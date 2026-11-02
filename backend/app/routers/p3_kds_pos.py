from datetime import datetime
from fastapi import APIRouter, Depends, BackgroundTasks, HTTPException
from sqlalchemy.orm import Session
from app.core.database import get_db
from app.core.ws_and_cart import ws_manager
from app.models.oltp_models import (
    OrderItem, OrderItemStatus, TableSession, DiningTable, TableStatus, PaymentStatus, MenuItem
)
from app.schemas.contracts import KitchenStatusUpdate
from app.routers.p4_data_pipeline import run_etl_for_closed_session

router = APIRouter(prefix="/api/v1/staff", tags=["P3 - KDS & POS (TV3)"])

@router.get("/kds/active-items")
def get_kds_active_items(db: Session = Depends(get_db)):
    """[TV3] Lấy danh sách món đang chờ nấu hoặc đang nấu cho màn hình Bếp KDS"""
    items = (
        db.query(OrderItem)
        .join(OrderItem.order)
        .filter(OrderItem.status.in_([OrderItemStatus.PENDING, OrderItemStatus.COOKING]))
        .order_by(OrderItem.created_at.asc())
        .all()
    )
    result = []
    for item in items:
        menu_item = db.query(MenuItem).filter(MenuItem.id == item.menu_item_id).first()
        result.append({
            "order_item_id": item.id,
            "order_id": item.order_id,
            "table_id": item.order.session.table_id if item.order and item.order.session else None,
            "menu_item_id": item.menu_item_id,
            "name": menu_item.name if menu_item else f"Món #{item.menu_item_id}",
            "quantity": item.quantity,
            "status": item.status.value,
            "note": item.note,
            "selected_toppings": item.selected_toppings,
            "created_at": item.created_at.isoformat() if item.created_at else None,
            "cooking_started_at": item.cooking_started_at.isoformat() if item.cooking_started_at else None
        })
    return result

@router.patch("/kds/item-status")
async def update_kitchen_item_status(payload: KitchenStatusUpdate, db: Session = Depends(get_db)):
    """[TV3] Đầu bếp bấm đổi trạng thái món -> Cập nhật mốc thời gian SLA & Báo về điện thoại khách"""
    item = db.query(OrderItem).filter(OrderItem.id == payload.order_item_id).first()
    if not item:
        raise HTTPException(status_code=404, detail="Không tìm thấy món ăn")

    item.chef_id = payload.chef_id
    item.status = OrderItemStatus(payload.new_status)
    if item.status == OrderItemStatus.COOKING:
        item.cooking_started_at = datetime.utcnow()
    elif item.status == OrderItemStatus.COMPLETED:
        if not item.cooking_started_at:
            item.cooking_started_at = datetime.utcnow()
        item.completed_at = datetime.utcnow()

    db.commit()
    session_token = item.order.session.session_token if item.order and item.order.session else None
    if session_token:
        await ws_manager.broadcast_to_table(session_token, {
            "event": "ITEM_STATUS_UPDATED",
            "order_item_id": item.id,
            "new_status": item.status.value
        })
    return {"status": "updated", "order_item_id": item.id, "new_status": item.status.value}

@router.post("/pos/checkout/{session_id}")
def checkout_and_close_table(
    session_id: str,
    background_tasks: BackgroundTasks,
    db: Session = Depends(get_db)
):
    """[TV3 -> TV4] Thu ngân xác nhận thanh toán -> Đóng bàn & Kích hoạt ETL ngầm đẩy sang OLAP"""
    session = db.query(TableSession).filter(TableSession.id == session_id).first()
    if not session:
        raise HTTPException(status_code=404, detail="Không tìm thấy phiên bàn")

    session.payment_status = PaymentStatus.PAID
    session.closed_at = datetime.utcnow()

    table = db.query(DiningTable).filter(DiningTable.id == session.table_id).first()
    if table:
        table.status = TableStatus.AVAILABLE
        table.current_session_token = None

    db.commit()
    # KÍCH HOẠT PIPELINE CỦA BẠN (TV4) CHẠY NGẦM
    background_tasks.add_task(run_etl_for_closed_session, session.id)
    return {"status": "paid_and_closed", "total_amount": session.total_amount}
