import enum
import uuid
from datetime import datetime
from sqlalchemy import (
    Column, Integer, String, Boolean, Float, ForeignKey, DateTime, Enum, JSON
)
from sqlalchemy.orm import relationship
from app.core.database import Base

# --- ENUMS CHUẨN HÓA TOÀN HỆ THỐNG ---
class UserRole(str, enum.Enum):
    ADMIN = "ADMIN"       # Quản lý quán
    CASHIER = "CASHIER"   # Thu ngân
    CHEF = "CHEF"         # Đầu bếp

class TableStatus(str, enum.Enum):
    AVAILABLE = "AVAILABLE"
    OCCUPIED = "OCCUPIED"

class OrderItemStatus(str, enum.Enum):
    PENDING = "PENDING"       # Đang chờ bếp
    COOKING = "COOKING"       # Bếp đang nấu
    COMPLETED = "COMPLETED"   # Bếp nấu xong (Đang lên món)
    SERVED = "SERVED"         # Đã mang ra bàn
    CANCELLED = "CANCELLED"

class PaymentStatus(str, enum.Enum):
    UNPAID = "UNPAID"
    PAID = "PAID"

# --- CÁC BẢNG TRONG SCHEMA OLTP ---
class User(Base):
    __tablename__ = "users"
    __table_args__ = {"schema": "oltp"}

    id = Column(Integer, primary_key=True, index=True)
    username = Column(String(50), unique=True, nullable=False, index=True)
    password_hash = Column(String(255), nullable=False)
    full_name = Column(String(100), nullable=False)
    role = Column(Enum(UserRole), default=UserRole.CHEF, nullable=False)
    is_active = Column(Boolean, default=True)

class DiningTable(Base):
    __tablename__ = "tables"
    __table_args__ = {"schema": "oltp"}

    id = Column(Integer, primary_key=True, index=True)
    table_number = Column(String(20), unique=True, nullable=False) # VD: "Bàn 05"
    status = Column(Enum(TableStatus), default=TableStatus.AVAILABLE, nullable=False)
    current_session_token = Column(String(100), nullable=True)     # Chống quét QR từ xa

class TableSession(Base):
    __tablename__ = "table_sessions"
    __table_args__ = {"schema": "oltp"}

    id = Column(String(36), primary_key=True, default=lambda: str(uuid.uuid4()))
    table_id = Column(Integer, ForeignKey("oltp.tables.id"), nullable=False)
    session_token = Column(String(100), unique=True, nullable=False, index=True)
    opened_at = Column(DateTime, default=datetime.utcnow, nullable=False)
    closed_at = Column(DateTime, nullable=True)
    total_amount = Column(Float, default=0.0)
    payment_status = Column(Enum(PaymentStatus), default=PaymentStatus.UNPAID)
    is_etl_processed = Column(Boolean, default=False) # Đánh dấu đã đẩy sang OLAP chưa

    orders = relationship("Order", back_populates="session")

class Category(Base):
    __tablename__ = "categories"
    __table_args__ = {"schema": "oltp"}

    id = Column(Integer, primary_key=True, index=True)
    name = Column(String(100), nullable=False)
    display_order = Column(Integer, default=0)

class MenuItem(Base):
    __tablename__ = "menu_items"
    __table_args__ = {"schema": "oltp"}

    id = Column(Integer, primary_key=True, index=True)
    category_id = Column(Integer, ForeignKey("oltp.categories.id"), nullable=False)
    name = Column(String(150), nullable=False)
    description = Column(String(500), nullable=True)
    price = Column(Float, nullable=False)
    image_url = Column(String(500), nullable=True)
    is_available = Column(Boolean, default=True, nullable=False) # Nút gạt Báo hết món
    is_hot = Column(Boolean, default=False)                      # Nhãn "Món đang Hot"

class Topping(Base):
    __tablename__ = "toppings"
    __table_args__ = {"schema": "oltp"}

    id = Column(Integer, primary_key=True, index=True)
    name = Column(String(100), nullable=False) # VD: "Thêm trân châu"
    extra_price = Column(Float, default=0.0, nullable=False)
    is_available = Column(Boolean, default=True)

class Order(Base):
    __tablename__ = "orders"
    __table_args__ = {"schema": "oltp"}

    id = Column(Integer, primary_key=True, index=True)
    session_id = Column(String(36), ForeignKey("oltp.table_sessions.id"), nullable=False)
    created_at = Column(DateTime, default=datetime.utcnow, nullable=False)

    session = relationship("TableSession", back_populates="orders")
    items = relationship("OrderItem", back_populates="order")

class OrderItem(Base):
    __tablename__ = "order_items"
    __table_args__ = {"schema": "oltp"}

    id = Column(Integer, primary_key=True, index=True)
    order_id = Column(Integer, ForeignKey("oltp.orders.id"), nullable=False)
    menu_item_id = Column(Integer, ForeignKey("oltp.menu_items.id"), nullable=False)
    chef_id = Column(Integer, ForeignKey("oltp.users.id"), nullable=True) # Đầu bếp nhận nấu
    quantity = Column(Integer, default=1, nullable=False)
    unit_price = Column(Float, nullable=False) # Giá gốc lúc đặt + tổng giá topping
    selected_toppings = Column(JSON, default=list) # VD: [{"id": 1, "name": "Trân châu", "price": 10000}]
    note = Column(String(255), nullable=True)      # VD: "Ít cay, không hành"
    status = Column(Enum(OrderItemStatus), default=OrderItemStatus.PENDING, nullable=False)

    # 3 mốc thời gian phục vụ SLA Timer (15 phút) và phân tích tốc độ Bếp
    created_at = Column(DateTime, default=datetime.utcnow, nullable=False)
    cooking_started_at = Column(DateTime, nullable=True)
    completed_at = Column(DateTime, nullable=True)

    order = relationship("Order", back_populates="items")