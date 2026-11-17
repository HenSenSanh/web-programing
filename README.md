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
# Lưu ý: Dockerfile đã tự cài PHPMailer qua Composer và mount volume php_vendor; file .env đã có sẵn cấu hình Docker mặc định.
docker compose up -d

# (Tùy chọn) Nếu có chỉnh sửa composer.json hoặc Dockerfile, build lại image:
docker compose up -d --build

# BƯỚC 2: Kiểm tra trạng thái 3 container đang chạy ổn định
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
  * **Trang Đăng nhập & Đăng ký:** Xây dựng giao diện `login.php` và `register.php`, tự chuyển hướng nhân viên tới đúng màn hình theo vai trò (`ADMIN` → Dashboard, `CREATOR` → Quản lý Bàn, `KEEPER` → Bếp KDS, `VIEWER` → Thu ngân POS), hỗ trợ `Remember Me` và kích hoạt email SMTP qua PHPMailer.
  * **Quản lý Bàn ăn & QR:** Xem trạng thái các bàn, thêm bàn mới (`tables.manage`), mở phiên cho bàn (`tables.open`) và tạo mã QR chứa `session_token` (tùy biến URL gốc cho điện thoại trên mạng LAN).
  * **Quản lý Thực đơn hợp nhất (`products.php`):** Thêm món mới qua modal (hỗ trợ `image_url` và đồng bộ tự động sang `products`), chỉnh sửa món, bật/tắt trạng thái còn món/hết món từng món, cấu hình topping trực tiếp cho từng món (`menu_item_toppings`), thống kê động toàn catalog và phân trang thật SQL (`COUNT(*)`, `LIMIT`, `OFFSET`) giữ bộ lọc URL.
* **Các file phụ trách:** `app/public/login.php`, `app/public/register.php`, `app/public/products.php`, `app/public/partials/staff_portal_nav.php`, `app/public/frontend/admin/table-management.php`, `app/Controllers/AdminCatalogController.php`.

---

### 👤 THÀNH VIÊN 2 (TV2) — Giao Diện Khách Quét QR & Giỏ Hàng Chung
* **Trách nhiệm chính:**
  * **Menu Khách trên Điện thoại (`/frontend/menu/index.html?token={session_token}`):** Hiển thị danh mục món ăn, hình ảnh, mô tả, nhãn "Món Hot", danh sách topping hợp lệ được cấu hình riêng cho từng món, và drawer giỏ hàng/lịch sử đơn.
  * **Giỏ hàng thời gian thực:** Thao tác trên cây HTML DOM mượt mà không tải lại trang, đồng bộ giỏ hàng chung giữa nhiều thiết bị cùng bàn qua REST API (`/api/v1/client/session/{token}/cart`).
  * **Gửi đơn & hủy món an toàn:** Gửi danh sách món xuống bếp kèm mã `Idempotency-Key` lưu `sessionStorage` (chặn gửi trùng khi bấm đúp hoặc retry), hỗ trợ gọi thêm nhiều đợt vào cùng phiên, cho phép hủy món đang `PENDING` (`ClientCancellationController`) và hiển thị snapshot topping đã đặt.
  * **Gợi ý món ăn kèm:** Hiển thị thanh "🔥 Món hay được gọi kèm" trên popup chọn món lấy từ API gợi ý của TV4.
* **Các file phụ trách:** `app/public/frontend/menu/index.html`, `app/public/frontend/js/menu.js`, `app/public/frontend/css/menu.css`, `app/public/frontend/css/shop.css`, `app/Controllers/ClientOrderController.php`, `app/Controllers/ClientCancellationController.php`.

---

### 👤 THÀNH VIÊN 3 (TV3) — Điều Phối Bếp KDS, Thu Ngân POS & In Hóa Đơn
* **Trách nhiệm chính:**
  * **Màn hình Bếp KDS (`/frontend/kds/`):** Bếp nhận đơn hàng định kỳ qua API, hiển thị thời gian chờ món, đổi trạng thái món theo quy trình: `PENDING` (Chờ nấu) $\rightarrow$ `COOKING` (Đang nấu) $\rightarrow$ `COMPLETED` (Hoàn thành; API cũng hỗ trợ `READY` / `SERVED`).
  * **Thu ngân POS (`/frontend/pos/`):** Xem chi tiết các món bàn đã gọi, tạm tính tiền, áp dụng thuế VAT 8%, phí dịch vụ 0đ, làm tròn số tiền theo mốc 500đ chuẩn Việt Nam (`KdsPosController.php`).
  * **Thanh toán & In hóa đơn nhà hàng:** Xác nhận thanh toán theo phương thức (`CASH` / `TRANSFER`), chốt đóng bàn, kích hoạt ETL tự động và hỗ trợ **in hóa đơn thanh toán theo chuẩn `@media print`** tại `app/public/documents.php`.
* **Các file phụ trách:** `app/public/frontend/kds/`, `app/public/frontend/pos/`, `app/public/documents.php`.

---

### 👤 THÀNH VIÊN 4 (TV4) — Kiến Trúc Hệ Thống, Quản Trị Dữ Liệu & Kiểm Thử Chất Lượng
* **Trách nhiệm chính:**
  * **Hạ tầng Docker & CSDL Kép:** Xây dựng file `docker-compose.yml`, `Dockerfile`, thiết kế CSDL MySQL 8.0 gồm nhóm bảng vận hành nhà hàng (`oltp`) và kho dữ liệu hình sao (`olap`).
  * **Phân quyền RBAC nâng cao:** Xây dựng lõi kiểm tra quyền theo thứ tự ưu tiên: `deny riêng > allow riêng > nhóm > từ chối 403`.
  * **Bảo mật Đơn giá & Chống giả mạo (Anti-Tamper):** Chụp đơn giá chuẩn từ bảng `menu_items`/`toppings` phía Server vào `order_items` khi khách đặt món.
  * **Bảo vệ Giao dịch & Đồng bộ Giỏ hàng chung:** Bọc các thao tác chốt đơn/thanh toán trong Database Transaction, đảm bảo toàn vẹn tổng tiền hóa đơn và `Idempotency-Key` chống gửi trùng đơn.
  * **Data Pipeline (ETL) & Gợi ý Apriori:** Vận hành luồng ETL tự động đổ dữ liệu sang OLAP khi thanh toán, tính toán luật kết hợp Apriori (Support, Confidence, Lift) và giám sát vi phạm SLA bếp (> 15 phút).
  * **Cổng Kiểm thử Tự động:** Xây dựng và chạy bộ test `tests/run_tests.php` (**61 ca kiểm thử**) và `tests/test_tv4_core.php` kiểm chứng các module cốt lõi.
* **Các file phụ trách:** `docker-compose.yml`, `database/init.sql`, `app/src/`, `app/Services/`, `app/Controllers/`, `tests/run_tests.php`, `tests/test_tv4_core.php`, `tests/test_live_e2e.php`.

---

### 👤 THÀNH VIÊN 5 (TV5) — Báo Cáo Doanh Thu BI & Ma Trận Thực Đơn
* **Trách nhiệm chính:**
  * **BI Dashboard (`dashboard.php`):** Hiển thị KPI tổng quan, doanh thu theo ngày/khung giờ cao điểm và đối soát tài chính OLAP vs POS từ API `/api/v1/bi/dashboard-summary` và `/api/v1/bi/revenue-over-time` (truy vấn từ kho Star Schema).
  * **Ma trận Menu Engineering (`/api/v1/bi/menu-engineering-matrix`):** Thống kê lượt xem (clickstream), số lượng bán, doanh thu, tỷ lệ chuyển đổi và phân nhóm 4 góc phần tư (`⭐ Star`, `🐴 Plowhorse`, `🧩 Puzzle`, `🐕 Dog`) kèm khuyến nghị kinh doanh trên `dashboard.php`.
  * **Báo cáo Hiệu suất, SLA & Xuất Excel (`.xlsx` / `.csv`):** Theo dõi chỉ số SLA bếp và xuất báo cáo doanh thu / ma trận thực đơn ra file Excel `.xlsx` chuẩn OpenXML (`ZipArchive`) và `.csv` UTF-8 BOM (chống formula injection) qua API `/api/v1/bi/export-report`. Tất cả endpoint `/api/v1/bi/*` được bảo vệ bởi RBAC quyền `reports.view`.
* **Các file phụ trách:** `app/public/dashboard.php`, `app/Controllers/DataPipelineController.php` (BI endpoints).

---

## 🌐 3. DANH SÁCH ĐƯỜNG DẪN TRUY CẬP CHO CẢ NHÓM KIỂM THỬ

Khi Server Docker đang bật, các bạn mở trình duyệt và truy cập các liên kết tương ứng:

| Phân hệ / Màn hình | Đường dẫn trên trình duyệt | Người phụ trách chính |
| :--- | :--- | :--- |
| **Trang chủ hệ thống** | `http://localhost:8080/` | Cả nhóm |
| **Đăng ký tài khoản** | `http://localhost:8080/register.php` | TV1 |
| **Đăng nhập hệ thống** | `http://localhost:8080/login.php` | TV1 |
| **Staff Portal (Chuyển tab mượt)** | `http://localhost:8080/portal.php` *(mở mặc định sau khi đăng nhập)* | Cả nhóm |
| **Dashboard Tổng quan & BI** | `http://localhost:8080/dashboard.php` | TV1 & TV5 |
| **Quản lý Thực đơn (Hợp nhất)** | `http://localhost:8080/products.php` | TV1 |
| **Hóa đơn & In phiếu thanh toán** | `http://localhost:8080/documents.php` | TV3 & TV4 |
| **TV1 - Quản lý Bàn & Sinh mã QR** | `http://localhost:8080/frontend/admin/table-management.php` *(alias `.html`)* | TV1 |
| **TV1 - Quản lý Danh mục Món ăn** | `http://localhost:8080/frontend/admin/menu-management.php` *(chuyển hướng `/products.php`)* | TV1 |
| **TV2 - Menu Khách Quét QR** | `http://localhost:8080/frontend/menu/index.html?token=tbl-01-sess` | TV2 |
| **TV3 - Màn hình Bếp KDS** | `http://localhost:8080/frontend/kds/index.php` *(alias `/frontend/kds/`)* | TV3 |
| **TV3 - Màn hình Thu ngân POS** | `http://localhost:8080/frontend/pos/index.php` *(alias `/frontend/pos/`)* | TV3 |
| **TV4 - Màn hình Điều phối Live Ops** | `http://localhost:8080/frontend/live-ops/index.php` *(alias `/frontend/live-ops/`)* | TV4 |
| **Quản trị Cơ sở dữ liệu phpMyAdmin** | `http://localhost:8081/` | TV4 & Cả nhóm |

### 🔑 Tài khoản mẫu có sẵn trong MySQL Docker (`database/init.sql`):
* **Tài khoản Quản trị nhà hàng (`ADMIN`):** `admin` (`admin@dineflow.vn`) — Mật khẩu: `Admin@123` (hoặc `123456`)
* **Tài khoản Phục vụ & Mở bàn (`CREATOR`):** `creator` (`creator@dineflow.vn`) — Mật khẩu: `123456`
* **Tài khoản Điều phối Bếp KDS (`KEEPER`):** `keeper` (`keeper@dineflow.vn`) — Mật khẩu: `123456`
* **Tài khoản Thu ngân POS & Báo cáo BI (`VIEWER`):** `viewer` (`viewer@dineflow.vn`) — Mật khẩu: `123456`
* *(Lưu ý: `database/seed.php` chỉ dùng riêng cho SQLite fallback với `admin`/`admin123`, `cashier1`/`cashier123`, `chef_hung`/`chef123`.)*

### 🔐 Ma trận quyền theo role & Nâng cấp Database cũ [MVP]

| Role | Quyền nghiệp vụ |
|---|---|
| `ADMIN` | Toàn quyền quản trị bàn, menu, KDS, POS, hóa đơn, báo cáo BI và dữ liệu. |
| `CREATOR` | Xem/mở bàn, xem thực đơn và theo dõi KDS; không sửa menu, không cập nhật trạng thái KDS hay checkout/đóng phiên. |
| `KEEPER` | Xem và cập nhật trạng thái món trên KDS; không mở bàn, không quản lý menu hay checkout. |
| `VIEWER` | Xem/xác nhận thanh toán POS và đóng phiên, xem/in hóa đơn và xem/xuất báo cáo BI. |

API nhân viên kiểm tra permission server-side (`401` khi chưa đăng nhập, `403` khi thiếu quyền). Các API menu khách (`/api/v1/client/*`, `/data/clickstream`, `/data/recommendations/*`) xác thực theo phiên QR, không yêu cầu tài khoản nhân viên.
Sau đăng nhập, nhân viên được chuyển vào giao diện chuyển tab mượt tại [`app/public/portal.php`](app/public/portal.php) trên đúng tab mặc định theo role: `ADMIN` → `?tab=dashboard`, `CREATOR` → `?tab=tables`, `KEEPER` → `?tab=kds`, `VIEWER` → `?tab=pos` (các URL `.php` riêng lẻ vẫn hoạt động bình thường).

**Nâng cấp volume MySQL đã có sẵn (không dùng `down -v`):**
```bash
# Thứ tự bắt buộc nếu nâng cấp từ database cũ trước ngày 2026-11-17:
# (Nếu từ trước PR #13 thì chạy thêm database/migrations/20261115_add_orders_idempotency_unique.sql)
mysql -u root -p login_system < database/migrations/20261117_rbac_permissions_catalog.sql
mysql -u root -p login_system < database/migrations/20261117_menu_topping_audit.sql
mysql -u root -p login_system < database/seed_role_permissions.sql
```
Sau khi migration xong, quản trị viên cấu hình liên kết topping cho từng món trong `products.php` (không tự động gán topping mặc định để tránh sai nghiệp vụ).

---

## 🧪 4. HƯỚNG DẪN CHẠY BỘ KIỂM THỬ TỰ ĐỘNG BẰNG DOCKER

Mỗi khi chỉnh sửa code, chạy cả 3 bộ kiểm thử bên trong container Docker (`docker compose exec` hoặc `docker-compose exec`):

```bash
docker compose exec php-app php /var/www/tests/run_tests.php
docker compose exec php-app php /var/www/tests/test_tv4_core.php
docker compose exec php-app php /var/www/tests/test_live_e2e.php
```

Phạm vi bao phủ của 3 bộ kiểm thử:
* `tests/run_tests.php` (**122 ca kiểm thử**): Mục 38–40 (Auth/Email/Login), Ma trận RBAC/Landing/Portal Shell/401-403, Thực đơn 35 món & phân trang SQL, Vòng đời Bàn/KDS/POS, Acceptance quản lý món/topping snapshot/audit/hồi quy hủy món, 7 Trụ cột Chất lượng TV4 và Phần 8 (BI Matrix 4 Quadrants & Export Excel `.xlsx` / `.csv` / `.json`).
* `tests/test_tv4_core.php` (**23 ca kiểm thử**): SharedCartService, Idempotent EtlService, AprioriService và DataPipelineController.
* `tests/test_live_e2e.php` (**72 ca kiểm thử**): Kiểm thử toàn luồng HTTP + Apache + MySQL 8.0 thực tế (static assets, `.htaccess` 302 aliases, PHP session/RBAC guards, Portal Tab Shell `?embed=1`, AJAX không reload ở `products.php` / `documents.php`, `& 401` API guards, Cloudflare Tunnel URL, 35 ảnh món, Open Table → QR Menu → Shared Cart → Order → KDS → POS → ETL → BI → `.xlsx`).
