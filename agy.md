# HƯỚNG DẪN KIỂM TRA CODE, CÀI ĐẶT MÔI TRƯỜNG & TEST HỆ THỐNG DINEFLOW (DÀNH CHO AGENT AGY)

Bạn (Agy) đóng vai trò là **DevOps & QA Automation Agent** cho dự án **DineFlow** (Hệ thống Đặt món QR tại bàn, Điều phối Bếp KDS Real-time & Phân tích dữ liệu F&B OLTP/OLAP). 
Hãy thực hiện tuần tự **6 bước** dưới đây để kiểm tra toàn bộ source code hiện tại, tự động bổ sung các file khởi tạo còn thiếu, cài đặt môi trường, nạp dữ liệu mẫu và chạy kiểm thử toàn luồng (End-to-End).

---

## BƯỚC 1: KIỂM TRA & CHUẨN HÓA CẤU TRÚC THƯ MỤC

Hãy kiểm tra thư mục gốc của dự án và đảm bảo cấu trúc khớp chính xác như sau (nếu thiếu các file `__init__.py` hoặc `requirements.txt`, hãy tự động tạo bổ sung):

```text
dineflow/
├── docker-compose.yml
├── agy.md
└── backend/
    ├── requirements.txt
    └── app/
        ├── __init__.py
        ├── main.py
        ├── core/
        │   ├── __init__.py
        │   ├── database.py
        │   └── ws_and_cart.py
        ├── models/
        │   ├── __init__.py
        │   ├── oltp_models.py
        │   └── olap_models.py
        ├── schemas/
        │   ├── __init__.py
        │   └── contracts.py
        ├── routers/
        │   ├── __init__.py
        │   ├── p1_admin_catalog.py
        │   ├── p2_client_order.py
        │   ├── p3_kds_pos.py
        │   ├── p4_data_pipeline.py
        │   └── p5_bi_dashboard.py
        └── scripts/
            ├── __init__.py
            └── seed_data.py
```

### Checklist rà soát lỗi cú pháp & Import (Code Audit)
1. Đảm bảo tất cả các thư mục con trong `backend/app/` đều có file `__init__.py` rỗng để Python nhận diện đúng package.
2. Trong `backend/app/main.py`, đảm bảo `init_schemas()` được gọi **trước** `Base.metadata.create_all(bind=engine)` để PostgreSQL tạo sẵn 2 schema `oltp` và `olap` trước khi tạo bảng.
3. Trong `backend/app/routers/p3_kds_pos.py`, kiểm tra import hàm `run_etl_for_closed_session` từ `app.routers.p4_data_pipeline` hoạt động bình thường, không bị lỗi vòng lặp (Circular Import).

---

## BƯỚC 2: TẠO FILE `backend/requirements.txt`

Nếu chưa có file `backend/requirements.txt`, hãy tạo file với nội dung chuẩn dưới đây:

```txt
fastapi==0.115.0
uvicorn[standard]==0.30.6
sqlalchemy==2.0.35
psycopg2-binary==2.9.9
redis==5.0.8
pydantic==2.9.2
websockets==13.1
httpx==0.27.2
```

---

## BƯỚC 3: TẠO SCRIPT BƠM DỮ LIỆU MẪU (`backend/app/scripts/seed_data.py`)

Hãy tạo hoặc cập nhật file `backend/app/scripts/seed_data.py` với đoạn code dưới đây để bơm sẵn dữ liệu cho cả 2 vùng **OLTP** (Nhân sự, 10 Bàn, Danh mục, Thực đơn, Topping) và **OLAP** (Dữ liệu lịch sử phục vụ test Gợi ý món và BI Dashboard):

```python
import random
import uuid
from datetime import datetime, timedelta
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
            MenuItem(category_id=cat_main.id, name="Mì Cay Hải Sản Cấp Độ 7", price=65000, is_hot=True),
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
```

---

## BƯỚC 4: CÀI ĐẶT MÔI TRƯỜNG & KHỞI CHẠY HỆ THỐNG

Agy hãy thực thi lần lượt các lệnh dưới đây trong Terminal:

### 1. Bật PostgreSQL và Redis bằng Docker Compose
```bash
docker compose up -d
```
*Kiểm tra trạng thái container:*
```bash
docker ps
```
*(Đảm bảo cả `dineflow_postgres` ở port `5432` và `dineflow_redis` ở port `6379` đều đang ở trạng thái `Up`)*.

### 2. Tạo môi trường ảo Python & Cài đặt thư viện
```bash
cd backend
python -m venv .venv
# Trên Windows:
.venv\Scripts\activate
# Trên macOS/Linux:
source .venv/bin/activate

pip install -r requirements.txt
```

### 3. Chạy Script bơm dữ liệu mẫu (`seed_data.py`)
```bash
# Đứng tại thư mục backend/
python -m app.scripts.seed_data
```

### 4. Khởi động FastAPI Server
```bash
# Đứng tại thư mục backend/
uvicorn app.main:app --host 0.0.0.0 --port 8000 --reload
```

---

## BƯỚC 5: KỊCH BẢN KIỂM THỬ TỰ ĐỘNG TOÀN LUỒNG (E2E VERIFICATION SUITE)

Sau khi Server đã chạy tại `http://localhost:8000`, Agy hãy chạy kịch bản test tự động theo đúng **5 Phần (P1 $\rightarrow$ P5)** dưới đây và báo cáo lại kết quả:

### Test Phần 1 (TV1 - Admin & Catalog): Mở phiên bàn & Lấy Session Token
```bash
curl -X POST "http://localhost:8000/api/v1/admin/tables/1/open-session"
```
* **Kết quả kỳ vọng:** Trả về HTTP `200 OK` kèm `session_id`, `session_token` (ví dụ: `sess_xxx`) và `qr_url`. Hãy lưu lại `session_token` và `session_id` này để dùng cho bước tiếp theo.

### Test Phần 4 (TV4 - Clickstream): Giả lập khách bấm xem món ăn số 1
```bash
curl -X POST "http://localhost:8000/api/v1/data/clickstream" \
  -H "Content-Type: application/json" \
  -d '{
    "session_id": "<SESSION_ID_TỪ_BƯỚC_1>",
    "menu_item_id": 1,
    "event_type": "VIEW_ITEM"
  }'
```
* **Kết quả kỳ vọng:** `{"recorded": true}`.

### Test Phần 2 (TV2 - Client QR Order): Khách bấm Đặt món xuống Bếp
```bash
curl -X POST "http://localhost:8000/api/v1/client/session/<SESSION_TOKEN_TỪ_BƯỚC_1>/submit-order" \
  -H "Content-Type: application/json" \
  -d '[
    {
      "menu_item_id": 1,
      "name": "Mì Cay Hải Sản Cấp Độ 7",
      "quantity": 2,
      "unit_price": 80000,
      "selected_toppings": [{"id": 1, "name": "Thêm Phô Mai Kéo Sợi", "price": 15000}],
      "note": "Ít cay"
    }
  ]'
```
* **Kết quả kỳ vọng:** `{"status": "success", "order_id": 1}`.

### Test Phần 3 (TV3 - KDS & POS): Đầu bếp nấu xong món & Thu ngân Đóng bàn
1. **Đầu bếp cập nhật trạng thái món sang `COMPLETED`:**
```bash
curl -X PATCH "http://localhost:8000/api/v1/staff/kds/item-status" \
  -H "Content-Type: application/json" \
  -d '{
    "order_item_id": 1,
    "chef_id": 3,
    "new_status": "COMPLETED"
  }'
```
2. **Thu ngân bấm Thanh toán & Đóng bàn (Tự động kích hoạt Background ETL của TV4):**
```bash
curl -X POST "http://localhost:8000/api/v1/staff/pos/checkout/<SESSION_ID_TỪ_BƯỚC_1>"
```
* **Kết quả kỳ vọng:** Trả về `{"status": "paid_and_closed", "total_amount": 160000.0}` và dữ liệu tự động được đẩy ngầm sang bảng `olap.fact_order_items`.

### Test Phần 4 & Phần 5 (TV4 & TV5 - Gợi ý món & BI Dashboard)
1. **Kiểm tra API Gợi ý món mua kèm (Frequently Bought Together):**
```bash
curl -X GET "http://localhost:8000/api/v1/data/recommendations/1"
```
* **Kết quả kỳ vọng:** Trả về danh sách 3 món ăn hay được gọi chung nhất với món số 1 kèm `pair_count`.
2. **Kiểm tra API Ma trận Phân tích Thực đơn (Menu Engineering Matrix):**
```bash
curl -X GET "http://localhost:8000/api/v1/bi/menu-engineering-matrix"
```
* **Kết quả kỳ vọng:** Trả về danh sách toàn bộ món ăn kèm cột `views` (từ Clickstream), `total_sold` và `total_revenue` (từ FactOrderItem).

---

## BƯỚC 6: BẢNG PHÂN CHIA RANH GIỚI FILE CHO 5 THÀNH VIÊN (QUY TẮC GIT)

Agy lưu ý bảo vệ cấu trúc file: Mỗi thành viên chỉ làm việc trên đúng file Router và thư mục Frontend của mình:

| Thành viên | Vai trò | File Backend được phép chỉnh sửa | Thư mục Frontend phụ trách |
| :--- | :--- | :--- | :--- |
| **TV1** | Admin & Catalog | `app/routers/p1_admin_catalog.py` | `frontend/src/app/(admin)/` |
| **TV2** | Client QR Web-App | `app/routers/p2_client_order.py` | `frontend/src/app/(client)/menu/` |
| **TV3** | KDS Bếp & POS Thu ngân | `app/routers/p3_kds_pos.py` | `frontend/src/app/(staff)/kds-pos/` |
| **TV4 (Lead)** | Backend & Data Engineering | `app/core/*`, `app/models/*`, `app/routers/p4_data_pipeline.py` | Hỗ trợ WebSocket & Data Pipeline |
| **TV5** | BI Dashboard & Seeder | `app/routers/p5_bi_dashboard.py`, `app/scripts/seed_data.py` | `frontend/src/app/(bi)/dashboard/` |