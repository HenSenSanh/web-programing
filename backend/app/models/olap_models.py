from datetime import datetime, date
from sqlalchemy import Column, Integer, String, Float, Boolean, Date, DateTime, ForeignKey
from app.core.database import Base

# --- DIMENSIONS (BẢNG CHIỀU) ---
class DimTime(Base):
    __tablename__ = "dim_time"
    __table_args__ = {"schema": "olap"}

    time_key = Column(Integer, primary_key=True) # Format: YYYYMMDDHH (VD: 2026110319)
    full_date = Column(Date, nullable=False, index=True)
    hour = Column(Integer, nullable=False, index=True) # 0 -> 23 (Dùng vẽ Heatmap Peak Hours)
    day_of_week = Column(Integer, nullable=False)      # 0 (Thứ 2) -> 6 (Chủ nhật)
    is_weekend = Column(Boolean, default=False)

class DimMenuItem(Base):
    __tablename__ = "dim_menu_item"
    __table_args__ = {"schema": "olap"}

    item_key = Column(Integer, primary_key=True, autoincrement=True)
    oltp_item_id = Column(Integer, unique=True, nullable=False, index=True)
    name = Column(String(150), nullable=False)
    category_name = Column(String(100), nullable=False)
    current_price = Column(Float, nullable=False)

class DimChef(Base):
    __tablename__ = "dim_chef"
    __table_args__ = {"schema": "olap"}

    chef_key = Column(Integer, primary_key=True, autoincrement=True)
    oltp_user_id = Column(Integer, unique=True, nullable=False, index=True)
    full_name = Column(String(100), nullable=False)

# --- FACTS (BẢNG SỰ KIỆN TRUNG TÂM) ---
class FactOrderItem(Base):
    __tablename__ = "fact_order_items"
    __table_args__ = {"schema": "olap"}

    id = Column(Integer, primary_key=True, autoincrement=True)
    time_key = Column(Integer, ForeignKey("olap.dim_time.time_key"), index=True)
    item_key = Column(Integer, ForeignKey("olap.dim_menu_item.item_key"), index=True)
    chef_key = Column(Integer, ForeignKey("olap.dim_chef.chef_key"), nullable=True, index=True)
    session_id = Column(String(36), index=True, nullable=False) # Dùng tính cặp món mua chung

    quantity = Column(Integer, nullable=False)
    revenue = Column(Float, nullable=False)              # quantity * unit_price
    prep_time_seconds = Column(Integer, nullable=True)  # (completed_at - created_at)
    is_sla_violated = Column(Boolean, default=False)    # True nếu prep_time_seconds > 900 (15 phút)

class FactClickstream(Base):
    __tablename__ = "fact_clickstream"
    __table_args__ = {"schema": "olap"}

    id = Column(Integer, primary_key=True, autoincrement=True)
    time_key = Column(Integer, index=True, nullable=False)
    session_id = Column(String(36), index=True, nullable=False)
    oltp_item_id = Column(Integer, index=True, nullable=False)
    # Event: VIEW_ITEM, ADD_TO_CART, REMOVE_FROM_CART
    event_type = Column(String(50), index=True, nullable=False)
    created_at = Column(DateTime, default=datetime.utcnow)