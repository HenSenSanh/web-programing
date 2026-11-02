# 🍲 DINEFLOW - HỆ THỐNG GỌI MÓN QR TẠI BÀN, BẾP KDS & PHÂN TÍCH F&B

> **Đồ án Môn Lập trình Web**  
> Kiến trúc: **Client - Server (RESTful API & Real-time WebSockets)**  
> Công nghệ: **FastAPI (Python), PostgreSQL (Dual Schema OLTP/OLAP), Redis, Docker**

---

## 📌 DÀNH CHO THÀNH VIÊN NHÓM (FRONTEND & TESTER)

Chào các bạn trong nhóm! Toàn bộ hạ tầng Backend, Cơ sở dữ liệu và API đã được xây dựng và kiểm thử hoàn chỉnh. 

Các bạn **không cần phải cấu hình lại Database từ đầu**, chỉ cần làm theo hướng dẫn dưới đây để bật hệ thống lên máy của mình và bắt đầu ghép nối giao diện Frontend.

---

## 🚀 HƯỚNG DẪN KHỞI CHẠY SERVER (2 BƯỚC NHANH NHẤT)

### ⚙️ Yêu cầu trước khi chạy (Prerequisites):
1. Đã cài **[Docker Desktop](https://www.docker.com/products/docker-desktop/)** (và đang bật sẵn).
2. Đã cài **Python 3.10+** trên máy.

---

### 👉 CÁCH 1: Dùng File Chạy Tự Động (Khuyên dùng trên Windows)

1. **Bước 1: Bật Database & Redis (Docker)**
   - Mở Terminal tại thư mục dự án và chạy lệnh:
     ```bash
     docker compose up -d
     ```
   - *Lưu ý: Đảm bảo 2 container `dineflow_postgres` (Port 5432) và `dineflow_redis` (Port 6379) đang ở trạng thái `Running`.*

2. **Bước 2: Bật Server FastAPI**
   - Nhấp đúp chuột vào file: **`1_chay_server.bat`**
   - Khi màn hình hiện: `Uvicorn running on http://0.0.0.0:8000` là Server đã sẵn sàng!

3. **Bước 3 (Tùy chọn): Chạy kiểm thử tự động toàn bộ luồng**
   - Nhấp đúp chuột vào file: **`2_chay_test.bat`** để xem kịch bản đặt món, nấu ăn và thanh toán tự động chạy từ A đến Z.

---

### 👉 CÁCH 2: Khởi chạy thủ công bằng Dòng lệnh Terminal

Nếu bạn dùng macOS/Linux hoặc muốn tự chạy bằng dòng lệnh:

```bash
# 1. Bật Docker
docker compose up -d

# 2. Đi vào thư mục backend
cd backend

# 3. Tạo môi trường ảo & cài đặt thư viện (chỉ cần làm lần đầu)
python -m venv .venv

# Kích hoạt môi trường ảo:
# Trên Windows:
.venv\Scripts\activate
# Trên macOS/Linux:
source .venv/bin/activate

# Cài đặt thư viện:
pip install -r requirements.txt

# 4. Bơm dữ liệu mẫu (10 bàn ăn, thực đơn, 1.000 đơn lịch sử phân tích BI)
python -m app.scripts.seed_data

# 5. Khởi động Server
uvicorn app.main:app --host 0.0.0.0 --port 8000 --reload
```

---

## 🌐 CÁC ĐỊA CHỈ TRUY CẬP QUAN TRỌNG

Mở trình duyệt web và truy cập các liên kết sau:

| Đường dẫn | Tên trang | Mục đích sử dụng cho Frontend |
| :--- | :--- | :--- |
| **`http://localhost:8000/docs`** | **Swagger UI** (Tài liệu API) | **Quan trọng nhất cho Frontend**: Xem toàn bộ danh sách API, định dạng JSON cần gửi, bấm nút `Try it out` để test trực tiếp xem kết quả trả về. |
| **`http://localhost:8000/demo`** | **E2E Simulator** (Trang Test Trực quan) | Giao diện mô phỏng toàn luồng: Khách quét QR ➔ Giỏ hàng chung WebSocket ➔ Bếp KDS ➔ Thu ngân POS ➔ Phân tích BI. |
| **`http://localhost:8000`** | Root | Tự động chuyển hướng về trang tài liệu `/docs`. |

---

## 📖 HƯỚNG DẪN DÙNG SWAGGER UI (`/docs`) ĐỂ CODE FRONTEND

Khi bạn làm giao diện (HTML/CSS/JS hoặc React/Next.js), bạn **không cần đoán mò API**:
1. Vào `http://localhost:8000/docs`.
2. Chọn API bạn cần (ví dụ: `POST /api/v1/client/session/{session_token}/submit-order`).
3. Bấm **"Try it out"**.
4. Nhập dữ liệu thử vào ô Request Body (Swagger đã có sẵn mẫu JSON).
5. Bấm **"Execute"**:
   - Mục **Request URL**: Đường dẫn bạn cần truyền vào hàm `fetch()` hoặc `axios`.
   - Mục **Server response**: Dữ liệu JSON thực tế trả về để bạn hiển thị lên giao diện.

---

## 👥 BẢNG PHÂN CÔNG NHIỆM VỤ 5 THÀNH VIÊN

Để tránh xung đột Git (Conflict), mỗi thành viên tập trung vào đúng phần việc và file của mình:

| Thành viên | Vai trò | API Backend phụ trách | Thư mục Frontend phụ trách |
| :--- | :--- | :--- | :--- |
| **TV1** | Admin & Danh mục Bàn/Món | Tag `P1 - Admin & Catalog` (`p1_admin_catalog.py`) | Giao diện Quản lý Bàn ăn, Bật/Tắt hết món |
| **TV2** | Khách hàng QR Web-App | Tag `P2 - Client QR Order` (`p2_client_order.py`) | Giao diện Menu món trên Mobile, Giỏ hàng chung |
| **TV3** | Màn hình Bếp (KDS) & POS Thu ngân | Tag `P3 - KDS & POS` (`p3_kds_pos.py`) | Màn hình Điều phối Bếp (nhận đơn/nấu xong) & Thu ngân Thanh toán |
| **TV4 (Lead)** | Backend, Real-time & Data Pipeline | Tag `P4 - Data Engineering & AI` (`p4_data_pipeline.py`, `core/*`, `models/*`) | WebSocket Giỏ hàng, Background ETL & Thuật toán gợi ý món |
| **TV5** | Báo cáo BI & Phân tích Doanh thu | Tag `P5 - BI Dashboard` (`p5_bi_dashboard.py`) | Biểu đồ doanh thu, Ma trận Menu Engineering |

---

## 📡 DANH SÁCH API CHÍNH DÙNG CHO FRONTEND

### 1. Phân hệ Admin & Quản lý bàn (TV1)
- `GET /api/v1/admin/tables`: Lấy danh sách 10 bàn ăn kèm trạng thái và Session Token.
- `POST /api/v1/admin/tables/{table_id}/open-session`: Mở bàn & tạo mã QR phiên mới.
- `GET /api/v1/admin/menu-items`: Lấy danh sách danh mục và tất cả món ăn + topping.
- `PATCH /api/v1/admin/menu-items/{item_id}/toggle-availability`: Bật/Tắt trạng thái còn món hay hết món.

### 2. Phân hệ Khách hàng quét QR đặt món (TV2)
- `WS /api/v1/client/ws/table/{session_token}`: WebSocket đồng bộ Giỏ hàng chung giữa các máy cùng bàn.
- `POST /api/v1/client/session/{session_token}/submit-order`: Khách bấm gửi đơn đặt món xuống bếp.

### 3. Phân hệ Bếp KDS & Thu ngân POS (TV3)
- `GET /api/v1/staff/kds/active-items`: Danh sách các món đang chờ nấu hoặc đang nấu trên màn hình bếp.
- `PATCH /api/v1/staff/kds/item-status`: Bếp đổi trạng thái món (`COOKING`, `COMPLETED`).
- `POST /api/v1/staff/pos/checkout/{session_id}`: Thu ngân thanh toán, giải phóng bàn và tự động kích hoạt ETL sang Star Schema.

### 4. Phân hệ Dữ liệu & AI Gợi ý món (TV4 Lead)
- `POST /api/v1/data/clickstream`: Ghi nhận sự kiện khách bấm xem món (`VIEW_ITEM`).
- `GET /api/v1/data/recommendations/{menu_item_id}`: Gợi ý top 3 món ăn hay được mua kèm nhiều nhất (Frequently Bought Together).

### 5. Phân hệ Báo cáo BI Dashboard (TV5)
- `GET /api/v1/bi/menu-engineering-matrix`: Ma trận phân tích thực đơn (So sánh Lượt xem Clickstream vs Lượt bán thực tế và Doanh thu).

---

## 🛠️ KHẮC PHỤC SỰ CỐ THƯỜNG GẶP (TROUBLESHOOTING)

1. **Lỗi `Cannot connect to PostgreSQL/Redis`:**
   - Kiểm tra xem Docker Desktop đã bật chưa.
   - Chạy lệnh `docker ps` để đảm bảo container `dineflow_postgres` và `dineflow_redis` đang `Up`.
2. **Lỗi cổng 8000 bị chiếm dụng (Port already in use):**
   - Tắt các tiến trình Python cũ đang chạy ngầm hoặc đổi cổng trong file bat: `--port 8001`.
3. **Mất dữ liệu mẫu:**
   - Chạy lại lệnh: `backend\.venv\Scripts\python -m app.scripts.seed_data`.

---
*Chúc nhóm hoàn thành xuất sắc đồ án! Nếu có thắc mắc về API, liên hệ ngay TV4 (Backend Lead).*
