from typing import List
from datetime import datetime, timedelta

from fastapi import APIRouter, Depends, Header, HTTPException, WebSocket, WebSocketDisconnect
from pydantic import ValidationError
from sqlalchemy.exc import IntegrityError
from sqlalchemy.orm import Session, joinedload

from app.core.database import get_db
from app.core.ws_and_cart import SharedCartService, ws_manager
from app.models.oltp_models import (
    DiningTable,
    ClientOrderRequest,
    MenuItem,
    Order,
    OrderItem,
    OrderItemStatus,
    TableSession,
    Topping,
)
from app.schemas.contracts import CartItemPayload

router = APIRouter(prefix="/api/v1/client", tags=["P2 - Client QR Order (TV2)"])


def price_cart_items(db: Session, items: List[CartItemPayload], lock_menu_items: bool = False):
    """Calculate price for cart items on the server.
    - Validates availability of menu items and toppings.
    - Locks rows when `lock_menu_items` is True to prevent race conditions.
    - Returns a list of priced items and the total amount.
    """
    if not items:
        raise HTTPException(status_code=422, detail="Giỏ hàng đang trống")

    priced_items = []
    cart_total = 0.0
    for item in items:
        menu_query = db.query(MenuItem).filter(MenuItem.id == item.menu_item_id)
        if lock_menu_items:
            menu_query = menu_query.with_for_update()
        menu_item = menu_query.first()
        if not menu_item or not menu_item.is_available:
            raise HTTPException(status_code=409, detail=f"Món #{item.menu_item_id} không còn phục vụ")

        selected_toppings = []
        topping_total = 0.0
        topping_ids = [topping.id for topping in item.selected_toppings]
        if len(topping_ids) != len(set(topping_ids)):
            raise HTTPException(status_code=422, detail="Topping bị chọn trùng")
        for topping_id in topping_ids:
            topping = db.query(Topping).filter(
                Topping.id == topping_id,
                Topping.is_available.is_(True),
            ).first()
            if not topping:
                raise HTTPException(status_code=409, detail=f"Topping #{topping_id} không còn phục vụ")
            topping_total += topping.extra_price
            selected_toppings.append({
                "id": topping.id,
                "name": topping.name,
                "price": topping.extra_price,
            })

        unit_price = menu_item.price + topping_total
        line_total = unit_price * item.quantity
        priced_items.append({
            "menu_item_id": menu_item.id,
            "name": menu_item.name,
            "quantity": item.quantity,
            "unit_price": unit_price,
            "line_total": line_total,
            "selected_toppings": selected_toppings,
            "note": item.note,
        })
        cart_total += line_total

    return priced_items, cart_total


def get_active_session(db: Session, session_token: str) -> TableSession:
    session = (
        db.query(TableSession)
        .options(joinedload(TableSession.orders).joinedload(Order.items))
        .filter(TableSession.session_token == session_token)
        .first()
    )
    if not session or session.closed_at is not None:
        raise HTTPException(status_code=400, detail="Phiên bàn không hợp lệ hoặc đã đóng")

    table = db.query(DiningTable).filter(DiningTable.id == session.table_id).first()
    if not table or table.current_session_token != session_token:
        raise HTTPException(status_code=409, detail="Mã QR không còn thuộc phiên bàn hiện tại")
    if session.opened_at and datetime.utcnow() - session.opened_at > timedelta(minutes=180):
        raise HTTPException(status_code=410, detail="Mã QR đã hết hạn. Vui lòng nhờ nhân viên cấp mã mới")
    return session


@router.websocket("/ws/table/{session_token}")
async def table_shared_cart_ws(websocket: WebSocket, session_token: str):
    await ws_manager.connect_table(session_token, websocket)
    try:
        current_cart = [
            CartItemPayload.model_validate(item).model_dump()
            for item in SharedCartService.get_cart(session_token)
        ]
    except ValidationError:
        current_cart = []
        await websocket.send_json({
            "event": "CART_ERROR",
            "detail": "Giỏ hàng đã lưu không hợp lệ",
        })
    await websocket.send_json({"event": "CART_SYNC", "cart": current_cart})
    try:
        while True:
            data = await websocket.receive_json()
            if data.get("event") == "UPDATE_CART":
                try:
                    cart_items = [
                        CartItemPayload.model_validate(item).model_dump()
                        for item in data.get("cart", [])
                    ]
                except ValidationError:
                    await websocket.send_json({
                        "event": "CART_ERROR",
                        "detail": "Giỏ hàng gửi lên không hợp lệ",
                    })
                    continue
                SharedCartService.update_cart(session_token, cart_items)
                await ws_manager.broadcast_to_table(
                    session_token,
                    {"event": "CART_SYNC", "cart": cart_items},
                )
    except WebSocketDisconnect:
        ws_manager.disconnect_table(session_token, websocket)


@router.post("/session/{session_token}/price-cart")
def price_client_cart(
    session_token: str,
    items: List[CartItemPayload],
    db: Session = Depends(get_db),
):
    get_active_session(db, session_token)
    priced_items, cart_total = price_cart_items(db, items)
    return {"items": priced_items, "total_amount": cart_total}


@router.post("/session/{session_token}/submit-order")
async def submit_order_to_kitchen(
    session_token: str,
    items: List[CartItemPayload],
    idempotency_key: str = Header(..., alias="Idempotency-Key"),
    db: Session = Depends(get_db),
):
    session = get_active_session(db, session_token)
    if not idempotency_key.strip():
        raise HTTPException(status_code=400, detail="Thiếu Idempotency-Key")
    prior = db.query(ClientOrderRequest).filter_by(
        session_id=session.id,
        idempotency_key=idempotency_key,
    ).first()
    if prior:
        return prior.response_payload

    priced_items, order_total = price_cart_items(db, items, lock_menu_items=True)

    try:
        new_order = Order(session_id=session.id)
        db.add(new_order)
        db.flush()

        created_items = []
        response_items = []
        for item in priced_items:
            order_item = OrderItem(
                order_id=new_order.id,
                menu_item_id=item["menu_item_id"],
                quantity=item["quantity"],
                unit_price=item["unit_price"],
                selected_toppings=item["selected_toppings"],
                note=item["note"],
            )
            db.add(order_item)
            db.flush()
            created_items.append(order_item.id)
            response_items.append({
                "order_item_id": order_item.id,
                **item,
                "status": order_item.status.value,
            })

        session.total_amount += order_total
        response_payload = {
            "status": "success",
            "order_id": new_order.id,
            "order_item_ids": created_items,
            "total_amount": order_total,
            "session_total": session.total_amount,
            "items": response_items,
            "idempotency_key": idempotency_key,
        }
        db.add(ClientOrderRequest(
            session_id=session.id,
            idempotency_key=idempotency_key,
            order_id=new_order.id,
            response_payload=response_payload,
        ))
        db.commit()
    except IntegrityError:
        db.rollback()
        prior = db.query(ClientOrderRequest).filter_by(
            session_id=session.id,
            idempotency_key=idempotency_key,
        ).first()
        if prior:
            return prior.response_payload
        raise
    except Exception:
        db.rollback()
        raise

    SharedCartService.clear_cart(session_token)
    await ws_manager.broadcast_to_table(
        session_token,
        {"event": "ORDER_SUBMITTED", "order_id": new_order.id, "order_item_ids": created_items},
    )
    await ws_manager.broadcast_to_staff(
        {"event": "NEW_ORDER_RECEIVED", "order_id": new_order.id,
         "table_id": session.table_id, "order_item_ids": created_items},
    )
    return response_payload


@router.get("/session/{session_token}/orders")
def get_session_orders(session_token: str, db: Session = Depends(get_db)):
    session = get_active_session(db, session_token)
    orders = (
        db.query(Order)
        .options(joinedload(Order.items))
        .filter(Order.session_id == session.id)
        .order_by(Order.created_at.asc())
        .all()
    )
    return {
        "session_id": session.id,
        "total_amount": float(session.total_amount or 0),
        "orders": [
            {
                "order_id": order.id,
                "created_at": order.created_at.isoformat() if order.created_at else None,
                "items": [
                    {
                        "order_item_id": item.id,
                        "menu_item_id": item.menu_item_id,
                        "name": item.menu_item.name if item.menu_item else f"Món #{item.menu_item_id}",
                        "quantity": item.quantity,
                        "unit_price": item.unit_price,
                        "line_total": item.quantity * item.unit_price,
                        "selected_toppings": item.selected_toppings or [],
                        "note": item.note,
                        "status": item.status.value,
                    }
                    for item in order.items
                ],
            }
            for order in orders
        ],
    }


@router.post("/session/{session_token}/order-items/{order_item_id}/cancel")
async def cancel_pending_order_item(
    session_token: str,
    order_item_id: int,
    db: Session = Depends(get_db),
):
    session = get_active_session(db, session_token)
    item = (
        db.query(OrderItem)
        .join(Order)
        .filter(OrderItem.id == order_item_id, Order.session_id == session.id)
        .with_for_update()
        .first()
    )
    if not item:
        raise HTTPException(status_code=404, detail="Không tìm thấy món trong phiên bàn này")
    if item.status != OrderItemStatus.PENDING:
        raise HTTPException(status_code=409, detail="Chỉ được hủy món khi bếp chưa bắt đầu nấu")

    item.status = OrderItemStatus.CANCELLED
    session.total_amount -= item.quantity * item.unit_price
    db.commit()
    await ws_manager.broadcast_to_staff(
        {"event": "ORDER_ITEM_CANCELLED", "order_item_id": item.id, "session_id": session.id}
    )
    await ws_manager.broadcast_to_table(
        session_token,
        {"event": "ITEM_STATUS_UPDATED", "order_item_id": item.id, "new_status": "CANCELLED"},
    )
    return {"status": "cancelled", "order_item_id": item.id}
