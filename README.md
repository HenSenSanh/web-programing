# 🍲 DINEFLOW — HỆ THỐNG GỌI MÓN QR TẠI BÀN, BẾP KDS & PHÂN TÍCH NHÀ HÀNG F&B
### ĐỒ ÁN MÔN LẬP TRÌNH WEB — HỆ THỐNG FULL-STACK PHP 8.2 + MYSQL 8.0 + DOCKER COMPOSE

> **Tài liệu chính thức dành cho cả nhóm:** Hướng dẫn chuẩn hóa 100% bằng câu lệnh **Docker CLI**, phân công nhiệm vụ chi tiết cho 5 thành viên (TV1, TV2, TV3, TV4, TV5) và danh sách đường dẫn kiểm thử chức năng. **Tuyệt đối không dùng file script `.bat` hay runtime bên ngoài**, tuân thủ nghiêm ngặt yêu cầu của giảng viên.

---

## 🚀 1. HƯỚNG DẪN KHỞI CHẠY HỆ THỐNG BẰNG DOCKER CLI (CHÍNH THỨC)

Hệ thống được đóng gói hoàn chỉnh bằng Docker Compose, chạy đồng nhất trên mọi hệ điều hành (Windows / macOS / Linux).

### ⚙️ Yêu cầu môi trường duy nhất:
* Cài đặt **Docker Desktop** (bắt buộc bật Docker Desktop trước khi chạy lệnh).

### ⌨️ Các lệnh Terminal chuẩn (Chạy tại thư mục gốc dự án):

```bash
# BƯỚC 1: Khởi động 3 container dịch vụ ngầm (php-app, mysql, phpmyadmin)
docker compose up -d

# BƯỚC 2: Cài đặt thư viện PHPMailer qua Composer (chỉ cần chạy lần đầu tiên)
docker compose exec php-app composer install

# BƯỚC 3: Kiểm tra trạng thái 3 container đang chạy ổn định
docker compose ps
```

Khi muốn xem log hoặc dừng hệ thống:
```bash
# Xem log hệ thống thời gian thực:
docker compose logs -f

# Dừng toàn bộ hệ thống (giữ lại dữ liệu CSDL):
docker compose down

# Dừng và xóa toàn bộ dữ liệu CSDL cũ để nạp lại từ đầu:
docker compose down -v
```

---

## 👥 2. PHÂN CÔNG NHIỆM VỤ CHI TIẾT CHO 5 THÀNH VIÊN

Hệ thống được thiết kế theo mô hình **Modular Monolith** trên nền tảng **PHP 8.2 thuần + HTML5 + CSS3 + JavaScript**, phân định ranh giới trách nhiệm rõ ràng cho từng bạn:

```
                  ┌─────────────────────────────────────────────────────────┐
                  │                 DINEFLOW SYSTEM CORE                    │
                  │        (PHP 8.2 + MySQL 8.0 + Docker Compose)           │
                  └────────────────────────────┬────────────────────────────┘
                                               │
       ┌───────────────────┬───────────────────┼───────────────────┬───────────────────┐
       ▼                   ▼                   ▼                   ▼                   ▼
    ┌─────┐             ┌─────┐             ┌─────┐             ┌─────┐             ┌─────┐
    │ TV1 │             │ TV2 │             │ TV3 │             │ TV4 │             │ TV5 │
    └─────┘             └─────┘             └─────┘             └─────┘             └─────┘
    Admin &             Khách QR            Bếp KDS             Hạ tầng,            Báo cáo
   Menu/Bàn            Mobile Menu         & Thu Ngân          RBAC, Test &        Doanh thu
  (Phân trang)        (Giỏ hàng)          (In hóa đơn)        Chất lượng          & Ma trận
```

---

### 👤 THÀNH VIÊN 1 (TV1) — Quản Lý Bàn Ăn, Thực Đơn & Xác Thực
* **Trách nhiệm chính:**
  * **Trang Đăng nhập & Đăng ký:** Xây dựng giao diện `login.php` và `register.php`, tích hợp mã hóa mật khẩu, ghi nhớ đăng nhập `Remember Me` và gửi email kích hoạt tài khoản qua SMTP.
  * **Quản lý Bàn ăn & QR:** Xem trạng thái các bàn (Trống / Đang ăn / Đang chờ thanh toán), mở bàn cho khách và tự động tạo mã QR chứa `session_token`.
  * **Quản lý Thực đơn (Bài A — Phân trang thật):** Thêm, sửa, xóa món ăn, chỉnh sửa đơn giá, bật/tắt trạng thái còn món/hết món. Áp dụng kỹ thuật phân trang thật bằng `COUNT(*)`, `LIMIT`, `OFFSET` trên MySQL, giữ nguyên bộ lọc tìm kiếm trên thanh URL.
* **Các file phụ trách:** `app/public/login.php`, `app/public/register.php`, `app/public/products.php`, `frontend/tv1/table-management.html`, `frontend/tv1/menu-management.html`.

---

### 👤 THÀNH VIÊN 2 (TV2) — Giao Diện Khách Quét QR & Giỏ Hàng Chung
* **Trách nhiệm chính:**
  * **Menu Khách trên Điện thoại (`/m/{session_token}`):** Hiển thị danh mục món ăn, hình ảnh, mô tả, nhãn "Món Hot", tùy chọn topping và ghi chú món.
  * **Giỏ hàng thời gian thực:** Thao tác trên cây HTML DOM mượt mà không tải lại trang, đồng bộ giỏ hàng chung giữa nhiều khách ngồi cùng bàn.
  * **Gửi đơn đặt món an toàn:** Gửi danh sách món xuống bếp kèm mã `Idempotency-Key` (chặn gửi trùng khi bấm đúp chuột), hỗ trợ khách gọi thêm nhiều đợt vào cùng một phiên bàn.
  * **Gợi ý món ăn kèm:** Hiển thị thanh "🔥 Món hay được gọi kèm" trên popup chọn món lấy từ API gợi ý của TV4.
* **Các file phụ trách:** `frontend/menu/index.html`, `frontend/js/menu.js`, `frontend/css/menu.css`.

---

### 👤 THÀNH VIÊN 3 (TV3) — Điều Phối Bếp KDS, Thu Ngân POS & In Hóa Đơn
* **Trách nhiệm chính:**
  * **Màn hình Bếp KDS (`/portal/kds`):** Bếp nhận đơn hàng thời gian thực, hiển thị thời gian chờ món, đổi trạng thái món theo quy trình: `PENDING` (Chờ nấu) $\rightarrow$ `COOKING` (Đang nấu) $\rightarrow$ `READY` (Nấu xong) $\rightarrow$ `SERVED` (Đã phục vụ).
  * **Thu ngân POS (`/portal/pos`):** Xem chi tiết các món bàn đã gọi, tạm tính tiền, áp dụng thuế VAT 8%, làm tròn số tiền theo mốc 500đ chuẩn Việt Nam.
  * **Thanh toán & In hóa đơn (Bài D):** Tạo mã VietQR thanh toán chuyển khoản, xác nhận thu tiền mặt, chốt đóng bàn và hỗ trợ **in hóa đơn tính tiền theo chuẩn `@media print`** (tự động ẩn sidebar và nút bấm khi in).
* **Các file phụ trách:** `frontend/kds.html`, `frontend/pos.html`, `frontend/js/kds.js`, `frontend/js/pos.js`, `app/public/documents.php`.

---

### 👤 THÀNH VIÊN 4 (TV4) — Kiến Trúc Hệ Thống, Quản Trị Dữ Liệu & Kiểm Thử Chất Lượng
* **Trách nhiệm chính:**
  * **Hạ tầng Docker & CSDL Kép:** Xây dựng file `docker-compose.yml`, `Dockerfile`, thiết kế CSDL MySQL 8.0 gồm nhóm bảng vận hành (`oltp`) và kho dữ liệu hình sao (`olap`).
  * **Phân quyền RBAC nâng cao (Bài B):** Xây dựng lõi kiểm tra quyền theo thứ tự ưu tiên: `deny riêng > allow riêng > nhóm > từ chối 403`.
  * **Lịch sử giá theo thời gian (Bài C):** Quản lý khoảng giá `[valid_from, valid_to]`, chặn giao khoảng, chụp đơn giá snapshot vào chi tiết hóa đơn.
  * **Bảo vệ Giao dịch & Chống âm tồn (Bài D):** Bọc các thao tác chốt đơn/thanh toán trong Database Transaction với khóa dòng, đảm bảo toàn vẹn: $\text{stock\_balances} = \sum(\text{stock\_movements})$.
  * **Data Pipeline (ETL) & Gợi ý Apriori:** Vận hành luồng ETL tự động đổ dữ liệu sang OLAP khi thanh toán, tính toán luật kết hợp Apriori (Support, Confidence, Lift) và giám sát vi phạm SLA bếp (> 15 phút).
  * **Cổng Kiểm thử Tự động:** Xây dựng và chạy bộ test `tests/run_tests.php` với 36 ca kiểm thử đạt 100% PASS.
* **Các file phụ trách:** `tv4.md`, `docker-compose.yml`, `database/init.sql`, `app/src/`, `app/Services/`, `app/Controllers/`, `tests/run_tests.php`.

---

### 👤 THÀNH VIÊN 5 (TV5) — Báo Cáo Doanh Thu BI & Ma Trận Thực Đơn
* **Trách nhiệm chính:**
  * **BI Dashboard (`/portal/reports`):** Biểu đồ doanh thu theo giờ, theo ngày, tỷ lệ hoàn thành đơn hàng và thời gian phục vụ trung bình (truy vấn trực tiếp từ kho Star Schema do TV4 ETL sang).
  * **Ma trận Menu Engineering:** Phân loại món ăn thành 4 nhóm chiến lược: Ngôi sao (Stars), Ngựa thồ (Plowhorses), Câu đố (Puzzles) và Chó mực (Dogs) dựa trên mức độ phổ biến và tỷ suất lợi nhuận.
  * **Báo cáo Hiệu suất Đầu bếp:** Đo lường số lượng món và thời gian chế biến của từng đầu bếp, cảnh báo các món bị trễ SLA.
  * **Xuất báo cáo:** Chức năng xuất dữ liệu doanh thu và danh sách đơn hàng ra file Excel.
* **Các file phụ trách:** `app/public/dashboard.php`, các endpoint phân tích BI và giao diện biểu đồ `Chart.js`.

---

## 🌐 3. DANH SÁCH ĐƯỜNG DẪN TRUY CẬP CHO CẢ NHÓM KIỂM THỬ

Khi Server Docker đang bật, các bạn mở trình duyệt và truy cập các liên kết tương ứng:

| Phân hệ / Màn hình | Đường dẫn trên trình duyệt | Người phụ trách chính |
| :--- | :--- | :--- |
| **Trang chủ hệ thống** | `http://localhost:8080/` | Cả nhóm |
| **Đăng ký tài khoản** | `http://localhost:8080/register.php` | TV1 |
| **Đăng nhập hệ thống** | `http://localhost:8080/login.php` | TV1 |
| **Dashboard Tổng quan** | `http://localhost:8080/dashboard.php` | TV1 & TV5 |
| **Quản lý Thực đơn (Phân trang)** | `http://localhost:8080/products.php` | TV1 |
| **Phiếu kho & In hóa đơn** | `http://localhost:8080/documents.php` | TV3 & TV4 |
| **TV1 - Quản lý Bàn & Sinh mã QR** | `http://localhost:8080/frontend/tv1/table-management.html` | TV1 |
| **TV1 - Quản lý Danh mục Món ăn** | `http://localhost:8080/frontend/tv1/menu-management.html` | TV1 |
| **TV2 - Menu Khách Quét QR** | `http://localhost:8080/frontend/menu/index.html` | TV2 |
| **TV3 - Màn hình Bếp KDS** | `http://localhost:8080/frontend/kds.html` | TV3 |
| **TV3 - Màn hình Thu ngân POS** | `http://localhost:8080/frontend/pos.html` | TV3 |
| **TV4 - Màn hình Điều phối Live Ops** | `http://localhost:8080/frontend/tv4_data_pipeline.html` | TV4 |
| **Simulator Demo Toàn Luồng E2E** | `http://localhost:8080/demo_tester.html` | TV4 & TV5 |
| **Quản trị Cơ sở dữ liệu phpMyAdmin** | `http://localhost:8081/` | TV4 & Cả nhóm |

### 🔑 Tài khoản mẫu có sẵn trong hệ thống:
* **Tài khoản Admin (Toàn quyền):** `admin` — Mật khẩu: `123456`
* **Tài khoản Thu ngân (Cashier):** `cashier1` — Mật khẩu: `123456`
* **Tài khoản Đầu bếp (Chef):** `chef_hung` — Mật khẩu: `123456`
* **Tài khoản Chỉ xem (Viewer):** `viewer` — Mật khẩu: `123456`

---

## 🧪 4. HƯỚNG DẪN CHẠY BỘ KIỂM THỬ TỰ ĐỘNG BẰNG DOCKER

Mỗi khi bất kỳ thành viên nào chỉnh sửa code, cần chạy lại bộ kiểm thử bên trong container Docker:

```bash
docker compose exec php-app php /var/www/tests/run_tests.php
```

Màn hình console sẽ tự động thực thi **36 ca kiểm thử** bao phủ toàn bộ:
* 7 ca kiểm thử đăng ký tài khoản (Mục 38)
* 4 ca kiểm thử xác nhận email qua token (Mục 39)
* 7 ca kiểm thử đăng nhập và ghi nhớ cookie (Mục 40)
* 6 ca kiểm thử ma trận phân quyền RBAC (Bài B)
* 4 ca kiểm thử lịch sử giá theo thời gian không giao khoảng (Bài C)
* 8 ca kiểm thử nhập/xuất kho, chặn âm tồn và đối soát dữ liệu (Bài D).

Kết quả mong đợi: **`36 PASSED / 36 TOTAL (100% PASS)`**.
