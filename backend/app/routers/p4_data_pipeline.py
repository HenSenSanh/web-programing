from datetime import datetime
from fastapi import APIRouter, Depends
from sqlalchemy import text
from sqlalchemy.orm import Session
from app.core.database import SessionLocal, get_db
from app.models.oltp_models import TableSession, MenuItem, Category, User
from app.models.olap_models import DimTime, DimMenuItem, DimChef, FactOrderItem, FactClickstream
from app.schemas.contracts import ClickstreamEventPayload

router = APIRouter(prefix="/api/v1/data", tags=["P4 - Data Engineering & AI (TV4 - Bạn)"])

def _get_or_create_time_key(db: Session, dt: datetime) -> int:
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

@router.post("/clickstream")
def track_clickstream_event(payload: ClickstreamEventPayload, db: Session = Depends(get_db)):
    """[TV4] Hứng sự kiện khách lướt Menu (VIEW_ITEM, ADD_TO_CART, REMOVE_FROM_CART)"""
    now = datetime.utcnow()
    time_key = _get_or_create_time_key(db, now)
    db.add(FactClickstream(
        time_key=time_key,
        session_id=payload.session_id,
        oltp_item_id=payload.menu_item_id,
        event_type=payload.event_type,
        created_at=now
    ))
    db.commit()
    return {"recorded": True}

def run_etl_for_closed_session(session_id: str):
    """[TV4] Background Task: Biến đổi đơn hàng từ OLTP sang Star Schema OLAP khi đóng bàn"""
    db = SessionLocal()
    try:
        session = db.query(TableSession).filter_by(id=session_id).first()
        if not session or session.is_etl_processed:
            return

        for order in session.orders:
            time_key = _get_or_create_time_key(db, order.created_at)
            for item in order.items:
                # 1. Đồng bộ DimMenuItem
                dim_item = db.query(DimMenuItem).filter_by(oltp_item_id=item.menu_item_id).first()
                if not dim_item:
                    oltp_item = db.query(MenuItem).filter_by(id=item.menu_item_id).first()
                    category_name = "Khác"
                    current_price = item.unit_price
                    if oltp_item:
                        current_price = oltp_item.price
                        cat = db.query(Category).filter_by(id=oltp_item.category_id).first() if oltp_item.category_id else None
                        if cat:
                            category_name = cat.name
                    dim_item = DimMenuItem(
                        oltp_item_id=item.menu_item_id,
                        name=oltp_item.name if oltp_item else f"Món #{item.menu_item_id}",
                        category_name=category_name,
                        current_price=current_price
                    )
                    db.add(dim_item)
                    db.flush()

                # 2. Đồng bộ DimChef
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

                # 3. Tính thời gian nấu & kiểm tra vi phạm SLA (> 15 phút = 900 giây)
                prep_seconds = None
                is_violated = False
                if item.completed_at and item.created_at:
                    prep_seconds = int((item.completed_at - item.created_at).total_seconds())
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
    finally:
        db.close()

@router.get("/recommendations/{menu_item_id}")
def get_frequently_bought_together(menu_item_id: int, db: Session = Depends(get_db)):
    """[TV4] API gợi ý 'Món hay gọi kèm' dựa trên ma trận đồng xuất hiện (Self-Join FactOrderItem)"""
    sql = text("""
        SELECT d2.oltp_item_id, d2.name, d2.current_price, COUNT(*) AS pair_count
        FROM olap.fact_order_items f1
        JOIN olap.dim_menu_item d1 ON f1.item_key = d1.item_key
        JOIN olap.fact_order_items f2 ON f1.session_id = f2.session_id AND f1.item_key != f2.item_key
        JOIN olap.dim_menu_item d2 ON f2.item_key = d2.item_key
        WHERE d1.oltp_item_id = :item_id
        GROUP BY d2.oltp_item_id, d2.name, d2.current_price
        ORDER BY pair_count DESC
        LIMIT 3;
    """)
    rows = db.execute(sql, {"item_id": menu_item_id}).mappings().all()
    return {"item_id": menu_item_id, "recommendations": list(rows)}
