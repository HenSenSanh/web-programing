import secrets
from fastapi import APIRouter, Depends, HTTPException
from sqlalchemy.orm import Session
from app.core.database import get_db
from app.models.oltp_models import DiningTable, TableSession, TableStatus, MenuItem, Category, Topping
from app.schemas.contracts import MenuItemCreate

router = APIRouter(prefix="/api/v1/admin", tags=["P1 - Admin & Catalog (TV1)"])

@router.get("/tables")
def get_tables(db: Session = Depends(get_db)):
    """[TV1] Danh sách tất cả bàn ăn kèm trạng thái và session hiện tại"""
    tables = db.query(DiningTable).order_by(DiningTable.id).all()
    return [
        {
            "id": t.id,
            "table_number": t.table_number,
            "status": t.status.value,
            "current_session_token": t.current_session_token
        }
        for t in tables
    ]

@router.post("/tables/{table_id}/open-session")
def open_table_session(table_id: int, db: Session = Depends(get_db)):
    """[TV1] Mở phiên bàn & sinh mã Session Token gắn vào QR chống đặt đơn ảo"""
    table = db.query(DiningTable).filter(DiningTable.id == table_id).first()
    if not table:
        raise HTTPException(status_code=404, detail="Không tìm thấy bàn")
    
    token = f"sess_{secrets.token_urlsafe(12)}"
    new_session = TableSession(table_id=table.id, session_token=token)
    table.status = TableStatus.OCCUPIED
    table.current_session_token = token
    
    db.add(new_session)
    db.commit()
    db.refresh(new_session)
    return {
        "session_id": new_session.id,
        "session_token": token,
        "qr_url": f"http://localhost:3000/menu?table={table.id}&token={token}"
    }

@router.get("/menu-items")
def get_menu_items(db: Session = Depends(get_db)):
    """[TV1] Lấy danh mục món ăn và danh sách món phục vụ Client/Admin"""
    categories = db.query(Category).order_by(Category.display_order).all()
    items = db.query(MenuItem).all()
    toppings = db.query(Topping).filter(Topping.is_available == True).all()

    items_by_cat = {}
    for item in items:
        items_by_cat.setdefault(item.category_id, []).append({
            "id": item.id,
            "name": item.name,
            "description": item.description,
            "price": item.price,
            "image_url": item.image_url,
            "is_available": item.is_available,
            "is_hot": item.is_hot
        })

    return {
        "categories": [
            {
                "id": c.id,
                "name": c.name,
                "display_order": c.display_order,
                "items": items_by_cat.get(c.id, [])
            }
            for c in categories
        ],
        "available_toppings": [
            {"id": top.id, "name": top.name, "extra_price": top.extra_price}
            for top in toppings
        ]
    }

@router.patch("/menu-items/{item_id}/toggle-availability")
def toggle_item_availability(item_id: int, is_available: bool, db: Session = Depends(get_db)):
    """[TV1] Nút gạt 'Báo hết món' tức thời"""
    item = db.query(MenuItem).filter(MenuItem.id == item_id).first()
    if not item:
        raise HTTPException(status_code=404, detail="Không tìm thấy món")
    item.is_available = is_available
    db.commit()
    return {"item_id": item.id, "is_available": item.is_available}
