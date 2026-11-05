import random
import sys
import uuid
from datetime import datetime, timedelta

if hasattr(sys.stdout, "reconfigure"):
    try:
        sys.stdout.reconfigure(encoding="utf-8")
    except Exception:
        pass

from app.core.database import SessionLocal, engine, Base, init_schemas
from app.models.oltp_models import (
    User, UserRole, DiningTable, TableStatus, Category, MenuItem, Topping
)
from app.models.olap_models import (
    DimTime, DimMenuItem, DimChef, FactOrderItem, FactClickstream
)

def run_seeder():
    print("🚀 [1/4] Khởi tạo Schema (oltp, olap) và các bảng...")
    init_schemas()
    Base.metadata.create_all(bind=engine)

    db = SessionLocal()
    try:
        # Kiểm tra nếu đã seed rồi thì bỏ qua
        if db.query(DiningTable).count() > 0:
            print("✅ Dữ liệu mẫu đã tồn tại trong Database. Bỏ qua bước tạo mới.")
            return

        print("🚀 [2/4] Đang tạo dữ liệu nền nhánh OLTP (Nhân sự, 10 Bàn, Thực đơn, Topping)...")
        # 1. Tạo Users (Admin, Thu ngân, 2 Đầu bếp)
        users = [
            User(username="admin", password_hash="hashed_123", full_name="Quản Lý Quán", role=UserRole.ADMIN),
            User(username="cashier1", password_hash="hashed_123", full_name="Thu Ngân Ca Sáng", role=UserRole.CASHIER),
            User(username="chef_hung", password_hash="hashed_123", full_name="Đầu Bếp Hùng", role=UserRole.CHEF),
            User(username="chef_lan", password_hash="hashed_123", full_name="Đầu Bếp Lan", role=UserRole.CHEF),
        ]
        db.add_all(users)
        db.flush()

        # 2. Tạo 10 Bàn ăn
        tables = [
            DiningTable(table_number=f"Bàn {i:02d}", status=TableStatus.AVAILABLE)
            for i in range(1, 11)
        ]
        db.add_all(tables)

        # 3. Tạo Danh mục & Món ăn
        cat_main = Category(name="Món Chính", display_order=1)
        cat_side = Category(name="Món Ăn Kèm", display_order=2)
        cat_drink = Category(name="Đồ Uống", display_order=3)
        db.add_all([cat_main, cat_side, cat_drink])
        db.flush()

        menu_items = [
            MenuItem(category_id=cat_main.id, name="Mì Cay Hải Sản Cấp Độ 7", price=650000000, is_hot=True),
            MenuItem(category_id=cat_main.id, name="Lẩu Thái Tomyum Bò Mỹ", price=189000, is_hot=True),
            MenuItem(category_id=cat_main.id, name="Cơm Trộn Hàn Quốc Bibimbap", price=55000, is_hot=False),
            MenuItem(category_id=cat_side.id, name="Khoai Tây Chiên Phô Mai", price=35000, is_hot=True),
            MenuItem(category_id=cat_side.id, name="Gà Rán Sốt Cay Hàn Quốc", price=49000, is_hot=False),
            MenuItem(category_id=cat_side.id, name="Takoyaki Bạch Tuộc (6 viên)", price=40000, is_hot=False),
            MenuItem(category_id=cat_drink.id, name="Trà Đào Cam Sả", price=35000, is_hot=True),
            MenuItem(category_id=cat_drink.id, name="Trà Sữa Trân Châu Đường Đen", price=40000, is_hot=True),
            MenuItem(category_id=cat_drink.id, name="Coca Cola Tươi", price=20000, is_hot=False),
        ]
        db.add_all(menu_items)

        # 4. Tạo Toppings
        toppings = [
            Topping(name="Thêm Phô Mai Kéo Sợi", extra_price=15000),
            Topping(name="Thêm Trân Châu Hoàng Kim", extra_price=10000),
            Topping(name="Thêm Bò Mỹ Nhúng", extra_price=45000),
        ]
        db.add_all(toppings)
        db.flush()

        print("🚀 [3/4] Đang đồng bộ sang Dimension Tables (Nhánh OLAP)...")
        dim_items = []
        cat_map = {cat_main.id: cat_main.name, cat_side.id: cat_side.name, cat_drink.id: cat_drink.name}
        for item in menu_items:
            d_item = DimMenuItem(
                oltp_item_id=item.id,
                name=item.name,
                category_name=cat_map[item.category_id],
                current_price=item.price
            )
            db.add(d_item)
            dim_items.append(d_item)

        chefs = [u for u in users if u.role == UserRole.CHEF]
        dim_chefs = []
        for c in chefs:
            d_chef = DimChef(oltp_user_id=c.id, full_name=c.full_name)
            db.add(d_chef)
            dim_chefs.append(d_chef)
        db.flush()

        print("🚀 [4/4] Đang giả lập 1.000 phiên bàn lịch sử & Clickstream cho BI Dashboard (TV4 & TV5)...")
        now = datetime.utcnow()
        time_keys_created = set()
        fact_orders_batch = []
        fact_clicks_batch = []

        for _ in range(1000):
            random_days_ago = random.randint(0, 14)
            random_hour = random.choices([11, 12, 13, 18, 19, 20, 21], weights=[15, 25, 10, 15, 30, 25, 10])[0]
            dt = (now - timedelta(days=random_days_ago)).replace(hour=random_hour, minute=random.randint(0, 59))
            t_key = int(dt.strftime("%Y%m%d%H"))

            if t_key not in time_keys_created:
                if not db.query(DimTime).filter_by(time_key=t_key).first():
                    db.add(DimTime(
                        time_key=t_key,
                        full_date=dt.date(),
                        hour=dt.hour,
                        day_of_week=dt.weekday(),
                        is_weekend=(dt.weekday() >= 5)
                    ))
                    db.flush()
                time_keys_created.add(t_key)

            sess_id = str(uuid.uuid4())
            # Chọn ngẫu nhiên 2-3 món ăn trong cùng 1 phiên bàn để tạo cặp món mua kèm (Frequently Bought Together)
            chosen_dims = random.sample(dim_items, k=random.randint(2, 3))
            for d_item in chosen_dims:
                qty = random.randint(1, 3)
                prep_sec = random.randint(300, 1100) # Ngẫu nhiên từ 5 phút đến ~18 phút (có đơn vượt SLA 900s)
                fact_orders_batch.append(FactOrderItem(
                    time_key=t_key,
                    item_key=d_item.item_key,
                    chef_key=random.choice(dim_chefs).chef_key,
                    session_id=sess_id,
                    quantity=qty,
                    revenue=qty * d_item.current_price,
                    prep_time_seconds=prep_sec,
                    is_sla_violated=(prep_sec > 900)
                ))
                # Tạo 2-5 lượt VIEW_ITEM tương ứng trong Clickstream
                for _ in range(random.randint(2, 5)):
                    fact_clicks_batch.append(FactClickstream(
                        time_key=t_key,
                        session_id=sess_id,
                        oltp_item_id=d_item.oltp_item_id,
                        event_type="VIEW_ITEM",
                        created_at=dt
                    ))

        db.bulk_save_objects(fact_orders_batch)
        db.bulk_save_objects(fact_clicks_batch)
        db.commit()
        print("🎉 Hoàn tất bơm dữ liệu mẫu! Hệ thống sẵn sàng chạy kiểm thử.")
    finally:
        db.close()

if __name__ == "__main__":
    run_seeder()
