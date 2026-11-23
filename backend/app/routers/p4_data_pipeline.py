from datetime import datetime
import uuid
from pydantic import BaseModel
from fastapi import APIRouter, Depends, HTTPException
from sqlalchemy import text
from sqlalchemy.orm import Session
from app.core.database import SessionLocal, get_db
from app.core.ws_and_cart import ws_manager, SharedCartService
from app.models.oltp_models import (
    TableSession, MenuItem, Category, User, Order, OrderItem, DiningTable, TableStatus
)
from app.models.olap_models import DimTime, DimMenuItem, DimChef, FactOrderItem, FactClickstream
from app.schemas.contracts import ClickstreamEventPayload

router = APIRouter(prefix="/api/v1/data", tags=["P4 - Real-time Flow, ETL & Recommendation (TV4)"])


class ComboRulePayload(BaseModel):
    item_a_id: int
    item_b_id: int
    boost_weight: int = 3


def _get_or_create_time_key(db: Session, dt: datetime) -> int:
    """Đồng bộ chiều thời gian (DimTime) theo khung giờ YYYYMMDDHH"""
    time_key = int(dt.strftime("%Y%m%d%H"))
    exists = db.query(DimTime).filter_by(time_key=time_key).first()
    if not exists:
        db.add(DimTime(
            time_key=time_key,
            full_date=dt.date(),
            hour=dt.hour,
            day_of_week=dt.weekday(),
            is_weekend=(dt.weekday() >= 5)
        ))
        db.flush()
    return time_key


def _get_or_create_dim_item(db: Session, oltp_item_id: int, fallback_price: float = 0.0) -> DimMenuItem:
    """Đồng bộ món ăn từ OLTP sang bảng chiều DimMenuItem (OLAP)"""
    dim_item = db.query(DimMenuItem).filter_by(oltp_item_id=oltp_item_id).first()
    if not dim_item:
        oltp_item = db.query(MenuItem).filter_by(id=oltp_item_id).first()
        category_name = "Khác"
        current_price = fallback_price
        if oltp_item:
            current_price = oltp_item.price
            cat = db.query(Category).filter_by(id=oltp_item.category_id).first() if oltp_item.category_id else None
            if cat:
                category_name = cat.name
        dim_item = DimMenuItem(
            oltp_item_id=oltp_item_id,
            name=oltp_item.name if oltp_item else f"Món #{oltp_item_id}",
            category_name=category_name,
            current_price=current_price
        )
        db.add(dim_item)
        db.flush()
    return dim_item


@router.post("/clickstream")
def track_clickstream_event(payload: ClickstreamEventPayload, db: Session = Depends(get_db)):
    """[TV4] Ghi nhận sự kiện hành vi khách hàng thực tế trên Menu QR (VIEW_ITEM, ADD_TO_CART, REMOVE_FROM_CART)"""
    now = datetime.utcnow()
    time_key = _get_or_create_time_key(db, now)
    _get_or_create_dim_item(db, payload.menu_item_id)
    db.add(FactClickstream(
        time_key=time_key,
        session_id=payload.session_id,
        oltp_item_id=payload.menu_item_id,
        event_type=payload.event_type,
        created_at=now
    ))
    db.commit()
    return {"recorded": True, "time_key": time_key, "event_type": payload.event_type}


def run_etl_for_closed_session(session_id: str) -> bool:
    """[TV4] Background Task: Đồng bộ hóa đơn thực tế từ OLTP sang OLAP khi Thu ngân thanh toán đóng bàn"""
    db = SessionLocal()
    try:
        session = db.query(TableSession).filter_by(id=session_id).first()
        if not session or session.is_etl_processed:
            return False

        for order in session.orders:
            order_dt = order.created_at or datetime.utcnow()
            time_key = _get_or_create_time_key(db, order_dt)
            for item in order.items:
                dim_item = _get_or_create_dim_item(db, item.menu_item_id, item.unit_price)

                chef_key = None
                if item.chef_id:
                    dim_chef = db.query(DimChef).filter_by(oltp_user_id=item.chef_id).first()
                    if not dim_chef:
                        oltp_chef = db.query(User).filter_by(id=item.chef_id).first()
                        if oltp_chef:
                            dim_chef = DimChef(oltp_user_id=oltp_chef.id, full_name=oltp_chef.full_name)
                            db.add(dim_chef)
                            db.flush()
                    if dim_chef:
                        chef_key = dim_chef.chef_key

                prep_seconds = None
                is_violated = False
                if item.completed_at and item.created_at:
                    prep_seconds = max(0, int((item.completed_at - item.created_at).total_seconds()))
                    is_violated = prep_seconds > 900
                elif session.closed_at and item.created_at:
                    prep_seconds = max(0, int((session.closed_at - item.created_at).total_seconds()))
                    is_violated = prep_seconds > 900

                db.add(FactOrderItem(
                    time_key=time_key,
                    item_key=dim_item.item_key,
                    chef_key=chef_key,
                    session_id=session.id,
                    quantity=item.quantity,
                    revenue=item.quantity * item.unit_price,
                    prep_time_seconds=prep_seconds,
                    is_sla_violated=is_violated
                ))

        session.is_etl_processed = True
        db.commit()
        return True
    finally:
        db.close()


@router.get("/live-operations")
def get_live_restaurant_operations(db: Session = Depends(get_db)):
    """
    [TV4] Giám sát Trạng thái Bàn, Giỏ hàng chung (Redis WebSocket), Đơn bếp & Hóa đơn thật theo thời gian thực.
    """
    tables = db.query(DiningTable).order_by(DiningTable.id.asc()).all()
    menu_map = {m.id: m.name for m in db.query(MenuItem).all()}

    active_tables_data = []
    for t in tables:
        open_sess = db.query(TableSession).filter(
            TableSession.table_id == t.id,
            TableSession.closed_at.is_(None)
        ).order_by(TableSession.opened_at.desc()).first()

        token = t.current_session_token or (open_sess.session_token if open_sess else None)
        shared_cart = []
        ws_clients = 0
        if token:
            try:
                shared_cart = SharedCartService.get_cart(token)
            except Exception:
                shared_cart = []
            ws_clients = len(ws_manager.table_connections.get(token, []))

        submitted_items = []
        bill_estimate = 0.0
        if open_sess:
            for ord_obj in open_sess.orders:
                for it in ord_obj.items:
                    line_total = it.quantity * it.unit_price
                    bill_estimate += line_total
                    submitted_items.append({
                        "order_item_id": it.id,
                        "dish_name": menu_map.get(it.menu_item_id, f"Món #{it.menu_item_id}"),
                        "quantity": it.quantity,
                        "status": it.status.value if hasattr(it.status, "value") else str(it.status),
                        "unit_price": it.unit_price,
                        "line_total": line_total
                    })

        active_tables_data.append({
            "table_id": t.id,
            "table_number": t.table_number,
            "status": t.status.value if hasattr(t.status, "value") else str(t.status),
            "session_id": open_sess.id if open_sess else None,
            "session_token": token,
            "opened_at": open_sess.opened_at.isoformat() if (open_sess and open_sess.opened_at) else None,
            "ws_connected_devices": ws_clients,
            "shared_cart_items": shared_cart,
            "submitted_kitchen_items": submitted_items,
            "current_bill_total": bill_estimate
        })

    # Lấy 15 phiên bàn đã thanh toán gần nhất
    closed_sessions = db.query(TableSession).filter(
        TableSession.closed_at.isnot(None)
    ).order_by(TableSession.closed_at.desc()).limit(15).all()

    table_name_map = {t.id: t.table_number for t in tables}
    closed_history = []
    for cs in closed_sessions:
        items_summary = []
        for o in cs.orders:
            for it in o.items:
                items_summary.append(f"{menu_map.get(it.menu_item_id, 'Món')} (x{it.quantity})")
        closed_history.append({
            "session_id": cs.id,
            "session_token": cs.session_token,
            "table_number": table_name_map.get(cs.table_id, f"Bàn #{cs.table_id}"),
            "opened_at": cs.opened_at.strftime("%H:%M:%S %d/%m") if cs.opened_at else "",
            "closed_at": cs.closed_at.strftime("%H:%M:%S %d/%m") if cs.closed_at else "",
            "total_amount": cs.total_amount or 0,
            "is_etl_processed": cs.is_etl_processed,
            "dishes": ", ".join(items_summary) if items_summary else "Không có món"
        })

    return {
        "tables": active_tables_data,
        "recent_closed_bills": closed_history
    }


@router.get("/pipeline-status")
def get_pipeline_status(db: Session = Depends(get_db)):
    """[TV4] Giám sát trạng thái Data Pipeline giữa OLTP và OLAP"""
    oltp_tables = db.query(DiningTable).count()
    oltp_sessions_total = db.query(TableSession).count()
    oltp_sessions_closed = db.query(TableSession).filter(TableSession.closed_at.isnot(None)).count()
    oltp_sessions_pending_etl = db.query(TableSession).filter(
        TableSession.closed_at.isnot(None),
        TableSession.is_etl_processed == False
    ).count()
    oltp_orders = db.query(Order).count()
    oltp_order_items = db.query(OrderItem).count()

    return {
        "status": "HEALTHY",
        "oltp_stats": {
            "dining_tables": oltp_tables,
            "total_sessions": oltp_sessions_total,
            "closed_sessions": oltp_sessions_closed,
            "pending_etl_sessions": oltp_sessions_pending_etl,
            "total_orders": oltp_orders,
            "total_order_items": oltp_order_items
        },
        "olap_star_schema_stats": {
            "dim_time": db.query(DimTime).count(),
            "dim_menu_item": db.query(DimMenuItem).count(),
            "dim_chef": db.query(DimChef).count(),
            "fact_order_items": db.query(FactOrderItem).count(),
            "fact_clickstream": db.query(FactClickstream).count()
        }
    }


@router.post("/trigger-batch-etl")
def trigger_batch_etl(db: Session = Depends(get_db)):
    """[TV4] Kích hoạt đồng bộ ETL vét cạn toàn bộ các hóa đơn thật đã đóng bàn sang OLAP"""
    unprocessed = db.query(TableSession).filter(
        TableSession.closed_at.isnot(None),
        TableSession.is_etl_processed == False
    ).all()

    processed_count = 0
    for s in unprocessed:
        if run_etl_for_closed_session(s.id):
            processed_count += 1

    return {
        "status": "completed",
        "sessions_scanned": len(unprocessed),
        "sessions_synced_to_olap": processed_count
    }


@router.post("/combo-rule")
def create_combo_recommendation_rule(payload: ComboRulePayload, db: Session = Depends(get_db)):
    """
    [TV4] Thiết lập / tăng trọng số cặp món Gợi ý mua kèm (Combo Upsell) trực tiếp từ giao diện Quản lý của TV4.
    Khi khách bên Menu QR (TV2) bấm xem món A, hệ thống sẽ ưu tiên gợi ý món B.
    """
    if payload.item_a_id == payload.item_b_id:
        raise HTTPException(status_code=400, detail="Hai món trong một Combo phải khác nhau.")

    dim_a = _get_or_create_dim_item(db, payload.item_a_id)
    dim_b = _get_or_create_dim_item(db, payload.item_b_id)
    now = datetime.utcnow()
    time_key = _get_or_create_time_key(db, now)

    weight = max(1, min(payload.boost_weight, 10))
    for _ in range(weight):
        combo_sess_id = f"combo_{uuid.uuid4().hex[:12]}"
        db.add(FactOrderItem(
            time_key=time_key,
            item_key=dim_a.item_key,
            chef_key=None,
            session_id=combo_sess_id,
            quantity=1,
            revenue=dim_a.current_price,
            prep_time_seconds=360,
            is_sla_violated=False
        ))
        db.add(FactOrderItem(
            time_key=time_key,
            item_key=dim_b.item_key,
            chef_key=None,
            session_id=combo_sess_id,
            quantity=1,
            revenue=dim_b.current_price,
            prep_time_seconds=300,
            is_sla_violated=False
        ))

    db.commit()
    return {
        "status": "success",
        "message": f"Đã lưu quy luật gợi ý: Khi khách xem '{dim_a.name}' ➔ Gợi ý mua kèm '{dim_b.name}'!"
    }


@router.get("/recommendations/{menu_item_id}")
def get_frequently_bought_together(menu_item_id: int, db: Session = Depends(get_db)):
    """
    [TV4] Hệ thống Gợi ý món mua kèm (Market Basket Analysis - Apriori):
    Tính toán đồng thời Pair Count, Support (%), Confidence (%) và Lift Ratio.
    """
    sql = text("""
        WITH total_sess AS (
            SELECT NULLIF(COUNT(DISTINCT session_id), 0) AS total_sessions
            FROM olap.fact_order_items
        ),
        item_sessions AS (
            SELECT d.oltp_item_id, d.item_key, d.name, d.current_price,
                   COUNT(DISTINCT f.session_id) AS item_sess_count
            FROM olap.fact_order_items f
            JOIN olap.dim_menu_item d ON f.item_key = d.item_key
            GROUP BY d.oltp_item_id, d.item_key, d.name, d.current_price
        ),
        pairs AS (
            SELECT f1.item_key AS item_a,
                   f2.item_key AS item_b,
                   COUNT(DISTINCT f1.session_id) AS pair_count
            FROM olap.fact_order_items f1
            JOIN olap.fact_order_items f2
              ON f1.session_id = f2.session_id
             AND f1.item_key != f2.item_key
            GROUP BY f1.item_key, f2.item_key
        )
        SELECT
            ib.oltp_item_id,
            ib.name,
            ib.current_price,
            p.pair_count,
            ROUND((p.pair_count * 100.0 / ts.total_sessions)::numeric, 2) AS support_pct,
            ROUND((p.pair_count * 100.0 / ia.item_sess_count)::numeric, 2) AS confidence_pct,
            ROUND(((p.pair_count::float / ia.item_sess_count) / (ib.item_sess_count::float / ts.total_sessions))::numeric, 2) AS lift
        FROM pairs p
        JOIN item_sessions ia ON p.item_a = ia.item_key
        JOIN item_sessions ib ON p.item_b = ib.item_key
        CROSS JOIN total_sess ts
        WHERE ia.oltp_item_id = :item_id
        ORDER BY p.pair_count DESC, lift DESC
        LIMIT 3;
    """)
    rows = db.execute(sql, {"item_id": menu_item_id}).mappings().all()
    return {
        "item_id": menu_item_id,
        "algorithm": "Apriori Association Rule Mining (Support, Confidence, Lift)",
        "recommendations": list(rows)
    }


@router.get("/association-rules")
def get_top_association_rules(limit: int = 10, db: Session = Depends(get_db)):
    """[TV4] Khai phá Tập luật kết hợp (Association Rules A -> B) mạnh nhất toàn nhà hàng"""
    sql = text("""
        WITH total_sess AS (
            SELECT NULLIF(COUNT(DISTINCT session_id), 0) AS total_sessions
            FROM olap.fact_order_items
        ),
        item_sessions AS (
            SELECT d.item_key, d.oltp_item_id, d.name, d.category_name, d.current_price,
                   COUNT(DISTINCT f.session_id) AS sess_count
            FROM olap.fact_order_items f
            JOIN olap.dim_menu_item d ON f.item_key = d.item_key
            GROUP BY d.item_key, d.oltp_item_id, d.name, d.category_name, d.current_price
        ),
        pairs AS (
            SELECT f1.item_key AS item_a,
                   f2.item_key AS item_b,
                   COUNT(DISTINCT f1.session_id) AS pair_sessions
            FROM olap.fact_order_items f1
            JOIN olap.fact_order_items f2
              ON f1.session_id = f2.session_id
             AND f1.item_key < f2.item_key
            GROUP BY f1.item_key, f2.item_key
        )
        SELECT
            ia.name AS antecedent_item,
            ib.name AS consequent_item,
            p.pair_sessions AS co_occurrence_sessions,
            ROUND((p.pair_sessions * 100.0 / ts.total_sessions)::numeric, 2) AS support_pct,
            ROUND((p.pair_sessions * 100.0 / ia.sess_count)::numeric, 2) AS confidence_a_to_b_pct,
            ROUND(((p.pair_sessions::float / ia.sess_count) / (ib.sess_count::float / ts.total_sessions))::numeric, 2) AS lift_ratio
        FROM pairs p
        JOIN item_sessions ia ON p.item_a = ia.item_key
        JOIN item_sessions ib ON p.item_b = ib.item_key
        CROSS JOIN total_sess ts
        ORDER BY p.pair_sessions DESC, lift_ratio DESC
        LIMIT :limit;
    """)
    rows = db.execute(sql, {"limit": limit}).mappings().all()
    return list(rows)


@router.get("/sla-analytics")
def get_kitchen_sla_analytics(db: Session = Depends(get_db)):
    """[TV4] Phân tích hiệu suất Đầu bếp & Tỷ lệ vi phạm SLA (> 15 phút / 900 giây) từ kho OLAP"""
    chef_sql = text("""
        SELECT
            COALESCE(c.full_name, 'Bếp Chung / Tự động') AS chef_name,
            COUNT(f.id) AS total_dishes_cooked,
            SUM(f.quantity) AS total_portions,
            ROUND(AVG(f.prep_time_seconds)::numeric, 0) AS avg_prep_seconds,
            ROUND((AVG(f.prep_time_seconds) / 60.0)::numeric, 1) AS avg_prep_minutes,
            SUM(CASE WHEN f.is_sla_violated THEN 1 ELSE 0 END) AS sla_violated_count,
            ROUND((SUM(CASE WHEN f.is_sla_violated THEN 1 ELSE 0 END) * 100.0 / NULLIF(COUNT(f.id), 0))::numeric, 2) AS sla_violation_rate_pct
        FROM olap.fact_order_items f
        LEFT JOIN olap.dim_chef c ON f.chef_key = c.chef_key
        WHERE f.prep_time_seconds IS NOT NULL
        GROUP BY COALESCE(c.full_name, 'Bếp Chung / Tự động')
        ORDER BY total_dishes_cooked DESC;
    """)

    dish_sla_sql = text("""
        SELECT
            d.name AS dish_name,
            d.category_name,
            COUNT(f.id) AS order_count,
            ROUND(AVG(f.prep_time_seconds)::numeric, 0) AS avg_prep_seconds,
            ROUND((AVG(f.prep_time_seconds) / 60.0)::numeric, 1) AS avg_prep_minutes,
            SUM(CASE WHEN f.is_sla_violated THEN 1 ELSE 0 END) AS sla_violated_count,
            ROUND((SUM(CASE WHEN f.is_sla_violated THEN 1 ELSE 0 END) * 100.0 / NULLIF(COUNT(f.id), 0))::numeric, 2) AS violation_rate_pct
        FROM olap.fact_order_items f
        JOIN olap.dim_menu_item d ON f.item_key = d.item_key
        WHERE f.prep_time_seconds IS NOT NULL
        GROUP BY d.name, d.category_name
        ORDER BY violation_rate_pct DESC, avg_prep_seconds DESC;
    """)

    chefs = db.execute(chef_sql).mappings().all()
    dishes = db.execute(dish_sla_sql).mappings().all()
    return {
        "sla_threshold_seconds": 900,
        "sla_threshold_minutes": 15,
        "chef_performance": list(chefs),
        "dish_bottlenecks": list(dishes)
    }


@router.get("/peak-hours")
def get_peak_hours_analysis(db: Session = Depends(get_db)):
    """[TV4] Phân tích khung giờ cao điểm (Peak Hours Traffic) từ bảng chiều DimTime"""
    sql = text("""
        SELECT
            t.hour AS hour_of_day,
            COUNT(DISTINCT f.session_id) AS total_sessions,
            SUM(f.quantity) AS total_items_sold,
            SUM(f.revenue) AS total_revenue,
            ROUND(AVG(f.prep_time_seconds)::numeric, 0) AS avg_prep_seconds
        FROM olap.fact_order_items f
        JOIN olap.dim_time t ON f.time_key = t.time_key
        GROUP BY t.hour
        ORDER BY t.hour ASC;
    """)
    rows = db.execute(sql).mappings().all()
    return list(rows)
