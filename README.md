# 🍲 DINEFLOW - HỆ THỐNG GỌI MÓN QR TẠI BÀN, BẾP KDS & PHÂN TÍCH NHÀ HÀNG F&B

> **Đồ án Môn Lập trình Web**  
> **Kiến trúc:** Client - Server (RESTful API, Real-time WebSockets & Dual-Schema OLTP/OLAP)  
> **Công nghệ:** FastAPI (Python), PostgreSQL, Redis, HTML5 / CSS3 / JavaScript (Vanilla)

---

## 📌 THÔNG TIN DÀNH CHO CẢ NHÓM (BẮT BUỘC ĐỌC TRƯỚC KHI CODE)

Toàn bộ mã nguồn giao diện của cả 4 phân hệ hiện đã được gộp thống nhất về thư mục duy nhất: `frontend/`.  
Hệ thống chạy trên **1 cổng Server duy nhất (`8000`)** — máy chủ FastAPI vừa phục vụ REST API vừa tự động render toàn bộ giao diện HTML/CSS/JS. Các bạn **không cần chạy tách rời frontend cổng 3000 hay cài đặt thêm Node.js/Next.js rườm rà**.

---

## 🚀 HƯỚNG DẪN KHỞI CHẠY HỆ THỐNG (2 BƯỚC)

### ⚙️ Yêu cầu môi trường (Prerequisites):
1. **Docker Desktop** (bắt buộc đang bật sẵn để chạy Database & Redis).
2. **Python 3.10+** đã cài trên máy.

---

### 👉 Cách nhanh nhất trên Windows:

1. **Bước 1: Khởi động PostgreSQL & Redis**
   - Mở Terminal/CMD tại thư mục dự án và chạy:
     ```bash
     docker compose up -d
     ```
   - *Kiểm tra đảm bảo 2 container `dineflow_postgres` (Port 5432) và `dineflow_redis` (Port 6379) đang ở trạng thái `Running`.*

2. **Bước 2: Bật Server FastAPI**
   - Nhấp đúp chuột vào file: **`1_chay_server.bat`**
   - Khi cửa sổ hiện: `INFO: Uvicorn running on http://0.0.0.0:8000` là toàn bộ Backend và Frontend đã sẵn sàng hoạt động!


---

### 👉 Khởi chạy thủ công bằng Terminal:

```bash
# 1. Bật Docker database
docker compose up -d

# 2. Đi vào thư mục backend
cd backend

# 3. Tạo môi trường ảo và cài đặt thư viện (chỉ làm lần đầu)
python -m venv .venv
.venv\Scripts\activate       # Trên Windows (.venv/bin/activate trên Mac/Linux)
pip install -r requirements.txt

# 4. Khởi tạo dữ liệu nền (10 bàn ăn, danh mục món ăn & tài khoản mẫu)
python -m app.scripts.seed_data

# 5. Khởi động server (Không dùng cờ --reload trên Windows để tránh crash)
.venv\Scripts\uvicorn.exe app.main:app --host 0.0.0.0 --port 8000
```

---

## 🌐 DANH SÁCH ĐƯỜNG DẪN TRUY CẬP CÁC PHÂN HỆ

Khi Server đang chạy, mở trình duyệt và truy cập các liên kết tương ứng:

| Phân hệ / Vai trò | Đường dẫn trên trình duyệt | Mô tả chức năng |
| :--- | :--- | :--- |
| **API Docs (Swagger)** | `http://localhost:8000/docs` | Tài liệu API chuẩn OpenAPI, xem schema JSON và test API trực tiếp |
| **TV1 - Quản lý Bàn & QR** | `http://localhost:8000/frontend/tv1/table-management.html` | Xem trạng thái 10 bàn, bấm Mở bàn & Tạo mã QR cho khách |
| **TV1 - Quản lý Thực đơn** | `http://localhost:8000/frontend/tv1/menu-management.html` | Thêm, sửa, xóa món ăn, chỉnh sửa giá và bật/tắt còn món/hết món |
| **TV2 - Menu Khách QR** | `http://localhost:8000/frontend/menu/index.html` | Giao diện mobile khách chọn món, xem giỏ hàng chung, nhận gợi ý combo |
| **TV3 - Bếp KDS** | `http://localhost:8000/frontend/kds.html` | Màn hình bếp nhận món real-time, chuyển trạng thái Đang nấu / Nấu xong |
| **TV3 - Thu ngân POS** | `http://localhost:8000/frontend/pos.html` | Xem chi tiết đơn bàn, in hóa đơn tạm tính và thanh toán đóng bàn |
| **TV4 - Điều phối & Gợi ý** | `http://localhost:8000/tv4` | Màn hình live operations: xem giỏ hàng WebSocket, đo thời gian bếp nấu, cấu hình combo gợi ý |

---

## 👥 BẢNG PHÂN CÔNG NHIỆM VỤ 5 THÀNH VIÊN

Mỗi thành viên tập trung vào đúng khu vực file được phân công để tránh xung đột mã nguồn (Git Conflict):

### 1. TV1 (Trường) - Admin Danh mục Bàn & Thực đơn
* **Thư mục Frontend:** [`frontend/tv1/`](file:///frontend/tv1/) (`table-management.html`, `menu-management.html`).
* **Backend API phụ trách:** [`backend/app/routers/p1_admin_catalog.py`](file:///backend/app/routers/p1_admin_catalog.py).
* **Nhiệm vụ chính:**
  * Hoàn thiện UI/UX trang mở bàn: bấm nút mở bàn $\rightarrow$ gọi API sinh QR URL $\rightarrow$ hiển thị mã QR động để khách quét.
  * Hoàn thiện bảng quản trị món ăn: form thêm món, lọc theo danh mục, nút gạt chuyển trạng thái Còn món / Tạm hết.

### 2. TV2 (Vinh) - Menu Khách Quét QR & Giỏ Hàng Chung
* **Thư mục Frontend:** [`frontend/menu/`](file:///frontend/menu/) (`index.html`), [`frontend/js/menu.js`](file:///frontend/js/menu.js), [`frontend/css/`](file:///frontend/css/).
* **Backend API phụ trách:** [`backend/app/routers/p2_client_order.py`](file:///backend/app/routers/p2_client_order.py).
* **Nhiệm vụ chính:**
  * Nhận query param `table` và `token` từ mã QR do TV1 tạo.
  * Đồng bộ Giỏ hàng chung qua WebSocket (`/api/v1/client/ws/table/{token}`) giữa nhiều điện thoại cùng bàn.
  * Khi khách bấm xem chi tiết 1 món $\rightarrow$ hiển thị thanh **"🔥 Món hay được gọi kèm"** từ API gợi ý của TV4.
  * Báo giá giỏ hàng qua `POST /api/v1/client/session/{token}/price-cart`; frontend chỉ gửi mã món, số lượng và ID topping, backend tính giá.
  * Bấm gửi đơn (`POST /api/v1/client/session/{token}/submit-order`) để backend tính lại giá và đẩy đơn trực tiếp xuống bếp của TV3.

### 3. TV3 (Vũ) - Bếp Điều Phối (KDS) & Thu Ngân (POS)
* **Thư mục Frontend:** [`frontend/kds.html`](file:///frontend/kds.html), [`frontend/pos.html`](file:///frontend/pos.html), [`frontend/js/kds.js`](file:///frontend/js/kds.js), [`frontend/js/pos.js`](file:///frontend/js/pos.js).
* **Backend API phụ trách:** [`backend/app/routers/p3_kds_pos.py`](file:///backend/app/routers/p3_kds_pos.py).
* **Nhiệm vụ chính:**
  * **KDS:** Tự động lắng nghe đơn món gửi từ TV2, cho phép đầu bếp bấm nhận món (`COOKING`) và nấu xong (`COMPLETED`).
  * **POS:** Thu ngân chọn bàn (hỗ trợ nhập số bàn hoặc mã session) $\rightarrow$ xem tổng tiền $\rightarrow$ bấm **Thanh toán**.
  * Ngay khi thanh toán thành công, hệ thống tự động giải phóng bàn về `AVAILABLE` và kích hoạt ngầm luồng đồng bộ sang kho dữ liệu cho TV4/TV5.

### 4. TV4 (Bạn - Backend Lead) - Điều Phối Thời Gian Thực, ETL & Gợi Ý Món
* **Thư mục Frontend:** [`frontend/tv4_data_pipeline.html`](file:///frontend/tv4_data_pipeline.html) (`http://localhost:8000/tv4`).
* **Backend API phụ trách:** [`backend/app/routers/p4_data_pipeline.py`](file:///backend/app/routers/p4_data_pipeline.py), [`backend/app/core/`](file:///backend/app/core/), [`backend/app/models/`](file:///backend/app/models/).
* **Nhiệm vụ chính:**
  * Quản trị hạ tầng Cơ sở dữ liệu Dual-Schema (`oltp` cho vận hành bán hàng, `olap` cho phân tích sao Star Schema).
  * Điều phối WebSocket & Giỏ hàng chung trên Redis (`ws_and_cart.py`).
  * Xây dựng tiến trình Background ETL tự động biến đổi hóa đơn đã thanh toán sang bảng `fact_order_items`.
  * Thuật toán gợi ý món mua kèm (Apriori Market Basket Analysis) và API cấu hình cặp món khuyến mãi Combo Upsell.
  * Đo lường thời gian chế biến của bếp và phát hiện vi phạm SLA (> 15 phút).

### 5. TV5 (Thắng) - Báo Cáo BI & Phân Tích Doanh Thu
* **Thư mục Frontend:** Tự do tạo file giao diện báo cáo (VD: `frontend/dashboard.html`).
* **Backend API phụ trách:** [`backend/app/routers/p5_bi_dashboard.py`](file:///backend/app/routers/p5_bi_dashboard.py).
* **Nhiệm vụ chính:**
  * Sử dụng thư viện biểu đồ (`Chart.js` hoặc tương đương) vẽ Dashboard phân tích:
    * Doanh thu theo chuỗi thời gian ngày/tuần: `GET /api/v1/bi/revenue-over-time`.
    * Tổng hợp KPIs doanh thu & tỷ lệ chuyển đổi: `GET /api/v1/bi/dashboard-summary`.
    * Ma trận thực đơn Menu Engineering (So sánh Lượt xem QR vs Lượt bán thực tế): `GET /api/v1/bi/menu-engineering-matrix`.
    * Khung giờ cao điểm: `GET /api/v1/data/peak-hours`.

---

## 🔄 QUY TRÌNH KIỂM THỬ TOÀN DIỆN (E2E TEST TỪ A ĐẾN Z)

Để kiểm tra hệ thống hoạt động thông suốt qua cả 4 phân hệ:

1. **Bước 1 (TV1):** Mở `http://localhost:8000/frontend/tv1/table-management.html` $\rightarrow$ Chọn **Bàn 01** $\rightarrow$ Bấm **"Mở bàn & Tạo QR"**.
2. **Bước 2 (TV2):** Bấm trực tiếp vào ảnh QR hoặc nút **"👉 Mở Menu Đặt Món (TV2)"** $\rightarrow$ Trang menu khách mở ra với token của Bàn 01 $\rightarrow$ Chọn món, thêm topping, quan sát gợi ý món đi kèm $\rightarrow$ Bấm **"Gửi đơn đến bếp"**.
3. **Bước 3 (TV3 KDS):** Mở `http://localhost:8000/frontend/kds.html` $\rightarrow$ Thấy món của Bàn 01 xuất hiện $\rightarrow$ Bấm **Bắt đầu nấu** $\rightarrow$ Bấm **Nấu xong**.
4. **Bước 4 (TV3 POS):** Mở `http://localhost:8000/frontend/pos.html` $\rightarrow$ Nhập số bàn `1` $\rightarrow$ Bấm **Tra cứu** $\rightarrow$ Bấm **Thanh toán & Đóng bàn**.
5. **Bước 5 (TV4):** Mở `http://localhost:8000/tv4` $\rightarrow$ Thấy hóa đơn Bàn 01 xuất hiện trong bảng Nhật ký, đã tự động chuyển sang OLAP và ghi nhận thời gian nấu của Bếp.
6. **Bước 6 (TV5):** Các API BI Dashboard tự động cập nhật doanh thu thật vừa thu được từ Bàn 01.

---

## 🛠️ KHẮC PHỤC SỰ CỐ THƯỜNG GẶP (TROUBLESHOOTING)

1. **Lỗi `Cannot connect to server / localhost:8000`:**
   * Đảm bảo cửa sổ `1_chay_server.bat` đang chạy và không bị tắt.
   * Kiểm tra Docker Desktop đã `docker compose up -d` thành công chưa.
2. **Lỗi `Port 8000 already in use`:**
   * Mở Task Manager tắt các tiến trình `python.exe` hoặc `uvicorn.exe` chạy ngầm từ phiên làm việc trước.
3. **Khi cần tạo mới lại dữ liệu bàn & món ăn:**
   * Chạy lệnh: `.venv\Scripts\python.exe -m app.scripts.seed_data`.

---
*DineFlow - Đồ án Môn Lập trình Web. Mọi thắc mắc về API hoặc kết nối luồng vui lòng liên hệ TV4 (Backend Lead).*
