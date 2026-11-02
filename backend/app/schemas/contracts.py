from typing import List, Optional
from pydantic import BaseModel

# --- HỢP ĐỒNG CHO TV1 (ADMIN & CATALOG) ---
class MenuItemCreate(BaseModel):
    category_id: int
    name: str
    price: float
    description: Optional[str] = None
    image_url: Optional[str] = None
    is_available: bool = True

# --- HỢP ĐỒNG CHO TV2 (CLIENT QR & GIỎ HÀNG CHUNG) ---
class ToppingSelection(BaseModel):
    id: int
    name: str
    price: float

class CartItemPayload(BaseModel):
    menu_item_id: int
    name: str
    quantity: int
    unit_price: float
    selected_toppings: List[ToppingSelection] = []
    note: Optional[str] = None

# --- HỢP ĐỒNG CHO TV3 (BẾP KDS & THU NGÂN POS) ---
class KitchenStatusUpdate(BaseModel):
    order_item_id: int
    chef_id: int
    new_status: str # COOKING, COMPLETED, SERVED

# --- HỢP ĐỒNG CHO TV4 (DATA PIPELINE & CLICKSTREAM) ---
class ClickstreamEventPayload(BaseModel):
    session_id: str
    menu_item_id: int
    event_type: str # VIEW_ITEM, ADD_TO_CART, REMOVE_FROM_CART