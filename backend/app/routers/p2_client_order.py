from typing import List
from fastapi import APIRouter, Depends, WebSocket, WebSocketDisconnect, HTTPException
from sqlalchemy.orm import Session
from app.core.database import get_db
from app.core.ws_and_cart import ws_manager, SharedCartService
from app.models.oltp_models import TableSession, Order, OrderItem
from app.schemas.contracts import CartItemPayload

router = APIRouter(prefix="/api/v1/client", tags=["P2 - Client QR Order (TV2)"])

@router.websocket("/ws/table/{session_token}")
async def table_shared_cart_ws(websocket: WebSocket, session_token: str):
    """[TV2 + TV4] WebSocket đồng bộ Giỏ hàng chung giữa các điện thoại cùng bàn"""
    await ws_manager.connect_table(session_token, websocket)
    # Gửi giỏ hàng hiện tại ngay khi khách vừa quét QR vào bàn
    await websocket.send_json({
        "event": "CART_SYNC",
        "cart": SharedCartService.get_cart(session_token)
    })
    try:
        while True:
            data = await websocket.receive_json()
            if data.get("event") == "UPDATE_CART":
                cart_items = data.get("cart", [])
                SharedCartService.update_cart(session_token, cart_items)
                await ws_manager.broadcast_to_table(session_token, {
                    "event": "CART_SYNC",
                    "cart": cart_items
                })
    except WebSocketDisconnect:
        ws_manager.disconnect_table(session_token, websocket)

@router.post("/session/{session_token}/submit-order")
async def submit_order_to_kitchen(
    session_token: str,
    items: List[CartItemPayload],
    db: Session = Depends(get_db)
):
    """[TV2] Khách bấm 'Đặt món' -> Lưu vào DB OLTP & Bắn chuông xuống màn hình Bếp"""
    session = db.query(TableSession).filter_by(session_token=session_token).first()
    if not session or session.closed_at is not None:
        raise HTTPException(status_code=400, detail="Phiên bàn không hợp lệ hoặc đã đóng")

    new_order = Order(session_id=session.id)
    db.add(new_order)
    db.flush()

    order_total = 0.0
    created_items = []
    for item in items:
        order_total += item.unit_price * item.quantity
        order_item = OrderItem(
            order_id=new_order.id,
            menu_item_id=item.menu_item_id,
            quantity=item.quantity,
            unit_price=item.unit_price,
            selected_toppings=[t.model_dump() for t in item.selected_toppings],
            note=item.note
        )
        db.add(order_item)
        db.flush()
        created_items.append(order_item.id)

    session.total_amount += order_total
    db.commit()
    SharedCartService.clear_cart(session_token)

    # Báo cho tất cả điện thoại trong bàn và bắn thông báo xuống Bếp (TV3)
    await ws_manager.broadcast_to_table(session_token, {
        "event": "ORDER_SUBMITTED", 
        "order_id": new_order.id,
        "order_item_ids": created_items
    })
    await ws_manager.broadcast_to_staff({
        "event": "NEW_ORDER_RECEIVED", 
        "order_id": new_order.id, 
        "table_id": session.table_id,
        "order_item_ids": created_items
    })
    return {
        "status": "success", 
        "order_id": new_order.id, 
        "order_item_ids": created_items
    }
