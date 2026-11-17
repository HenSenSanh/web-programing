# 🍲 DINEFLOW (QRDine) — HỆ THỐNG GỌI MÓN QR, BẾP KDS, THU NGÂN POS & PHÂN TÍCH DOANH THU BI

> Hệ thống quản trị nhà hàng Full-Stack xây dựng trên **PHP 8.2 thuần + MySQL 8.0 + Apache + Cloudflare Tunnel**, đóng gói đồng bộ bằng **Docker Compose**.

---

## 🚀 1. KHỞI CHẠY NHANH BẰNG DOCKER CLI

Yêu cầu duy nhất: cài đặt và bật **Docker Desktop**.

Tại thư mục gốc dự án, chạy lần lượt:

```bash
# Khởi động toàn bộ 4 container (php-app, mysql, phpmyadmin, tunnel)
docker compose up -d

# Kiểm tra trạng thái container đang chạy
docker compose ps
```

Các lệnh quản lý thường dùng:

```bash
# Xem log hệ thống (hoặc lấy link Cloudflare Tunnel đang chạy)
docker compose logs -f tunnel

# Dừng hệ thống (giữ nguyên dữ liệu MySQL)
docker compose down

# Dừng, xóa volume cũ và nạp lại database sạch từ database/init.sql
docker compose down -v && docker compose up -d

# Build lại image PHP nếu chỉnh Dockerfile hoặc composer.json
docker compose up -d --build
```

> **Mã QR Public (4G/5G/Wi-Fi ngoài):** Container `dineflow_tunnel` tự động tạo link HTTPS `https://*.trycloudflare.com` và ghi vào `/var/log/cloudflared/tunnel.log`. Trang **Quản lý Bàn & Mã QR** (`/frontend/admin/table-management.php`) tự động gọi API `GET /api/v1/admin/qr-public-url` để sinh mã QR cho khách quét trực tiếp từ bất kỳ mạng nào (hoặc có thể cố định domain bằng biến `QR_BASE_URL` trong `.env`).

---

## 🗄️ 2. VỊ TRÍ CODE PHP, DATABASE & CÁCH TRUY CẬP

### 📁 Vị trí các file nguồn chính
* **Kết nối PHP ↔ Database:**
  * [`app/Core/Database.php`](app/Core/Database.php) (`App\Core\Database::getConnection()`): Singleton PDO kết nối MySQL 8.0 dùng chung cho toàn bộ `Controllers/`, `Services/` và REST API.
  * [`app/src/db.php`](app/src/db.php) (`getDatabaseConnection()`): Lớp kết nối dùng cho các trang PHP (`login.php`, `dashboard.php`, `products.php`, `documents.php`), gọi về `App\Core\Database::getConnection()`.
  * [`app/src/config.php`](app/src/config.php) & [`.env`](.env): Đọc thông số cấu hình `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `QR_BASE_URL`.
* **Schema & Dữ liệu mẫu SQL:**
  * [`database/init.sql`](database/init.sql): Toàn bộ cấu trúc bảng **OLTP** (tài khoản, RBAC, bàn ăn, 35 món ăn + ảnh `/frontend/img/*`, topping, phiên bàn, KDS, hóa đơn POS) và **Star Schema OLAP** (`dim_time`, `dim_menu_item`, `dim_chef`, `fact_order_items`, `fact_clickstream`, `combo_rules`), kèm dữ liệu mẫu 7 ngày cho Dashboard BI & Ma trận Menu Engineering. Tự động import vào MySQL (`dineflow_db`) khi volume `mysql_data` được khởi tạo mới.
  * [`database/migrations/`](database/migrations) & [`database/seed_role_permissions.sql`](database/seed_role_permissions.sql): Script nâng cấp schema/quyền cho database đang chạy mà không cần `down -v`.
  * [`database/schema.sql`](database/schema.sql) & [`database/seed.php`](database/seed.php): Dùng cho SQLite fallback khi chạy unit test tách biệt.

### 🔌 3 cách truy cập trực tiếp vào Database MySQL (`dineflow_db`)

1. **Qua trình duyệt với phpMyAdmin (Khuyên dùng):**
   * Mở: `http://localhost:8081/`
   * **Username:** `dineflow` | **Password:** `dineflow123` | **Database:** `dineflow_db`
   * *(Hoặc đăng nhập quyền root: Username `root` / Password `root123` — cấu hình trong [`.env`](.env) và [`docker-compose.yml`](docker-compose.yml)).*
2. **Qua dòng lệnh MySQL CLI trong Docker:**
   ```bash
   docker exec -it dineflow_mysql mysql -udineflow -pdineflow123 dineflow_db
   ```
3. **Qua phần mềm DB bên ngoài (DBeaver / Navicat / MySQL Workbench / DataGrip):**
   * **Host:** `127.0.0.1` (hoặc `localhost`)
   * **Port:** `3307` *(Docker ánh xạ `3307:3306`)*
   * **Database:** `dineflow_db`
   * **Username / Password:** `dineflow` / `dineflow123` (hoặc `root` / `root123`)

---

## 🌐 3. DANH SÁCH ĐƯỜNG DẪN & TÀI KHOẢN HỆ THỐNG

| Chức năng / Màn hình | Đường dẫn truy cập | Ghi chú |
| :--- | :--- | :--- |
| **Trang chủ / Đăng nhập** | `http://localhost:8080/login.php` | Tự chuyển vào đúng phân hệ theo role |
| **Đăng ký tài khoản** | `http://localhost:8080/register.php` | Hỗ trợ xác thực email SMTP (PHPMailer) |
| **Staff Portal (Chuyển tab mượt)** | `http://localhost:8080/portal.php` | Thanh điều hướng dùng chung cho toàn bộ nhân sự |
| **Dashboard Tổng quan & Báo cáo BI** | `http://localhost:8080/dashboard.php` | KPI doanh thu 7 ngày, giờ cao điểm, Ma trận Menu 4 nhóm & xuất `.xlsx` |
| **Quản lý Thực đơn & Topping** | `http://localhost:8080/products.php` | Thêm/sửa món, ảnh món, bật/tắt hết món, gán topping, phân trang AJAX |
| **Hóa đơn & In Bill chuẩn** | `http://localhost:8080/documents.php` | Tra cứu chứng từ thanh toán & in hóa đơn `@media print` |
| **Sơ đồ Bàn ăn & Sinh mã QR** | `http://localhost:8080/frontend/admin/table-management.php` | Mở bàn, tự động gắn link Cloudflare Tunnel vào mã QR |
| **Menu Khách Quét QR** | `http://localhost:8080/frontend/menu/index.html?token=tbl-01-sess` | Giỏ hàng chung đồng bộ thiết bị, gợi ý món kèm, theo dõi & hủy món `PENDING` |
| **Màn hình Điều phối Bếp (KDS)** | `http://localhost:8080/frontend/kds/index.php` | Nhận đơn mới, cảnh báo thời gian nấu > 15 phút, chuyển `PENDING -> COOKING -> COMPLETED` |
| **Màn hình Thu ngân (POS)** | `http://localhost:8080/frontend/pos/index.php` | Tính tạm tính, VAT 8%, làm tròn 500đ, VietQR & đóng phiên |
| **Giám sát Vận hành (Live Ops)** | `http://localhost:8080/frontend/live-ops/index.php` | Theo dõi bàn đang mở, cảnh báo chậm bếp, luật gợi ý Apriori & đồng bộ báo cáo |
| **Quản trị CSDL phpMyAdmin** | `http://localhost:8081/` | Xem/sửa trực tiếp bảng `dineflow_db` |

### 🔑 Tài khoản mẫu trong MySQL (`database/init.sql`)
* **Admin (`ADMIN`):** `admin` (`admin@dineflow.vn`) — Mật khẩu: `Admin@123` *(hoặc `123456`)* → Toàn quyền quản trị, BI Dashboard, cấu hình hệ thống.
* **Phục vụ (`CREATOR`):** `creator` (`creator@dineflow.vn`) — Mật khẩu: `123456` → Xem/mở bàn, tạo QR, xem thực đơn (chỉ đọc) & KDS.
* **Đầu bếp (`KEEPER`):** `keeper` (`keeper@dineflow.vn`) — Mật khẩu: `123456` → Xem và cập nhật trạng thái món trên KDS.
* **Thu ngân (`VIEWER`):** `viewer` (`viewer@dineflow.vn`) — Mật khẩu: `123456` → Thu ngân POS, thanh toán đóng phiên, in hóa đơn & xem báo cáo BI.

> API nhân viên bảo vệ bằng RBAC (`401` khi chưa đăng nhập, `403` khi thiếu quyền). Các API menu khách (`/api/v1/client/*`, `/api/v1/data/clickstream`, `/api/v1/data/recommendations/*`) xác thực bằng `session_token` của bàn.

**Nâng cấp database cũ từ volume có sẵn (không dùng `down -v`):**
```bash
mysql -h 127.0.0.1 -P 3307 -u root -proot123 dineflow_db < database/migrations/20261117_rbac_permissions_catalog.sql
mysql -h 127.0.0.1 -P 3307 -u root -proot123 dineflow_db < database/migrations/20261117_menu_topping_audit.sql
mysql -h 127.0.0.1 -P 3307 -u root -proot123 dineflow_db < database/seed_role_permissions.sql
```

---

## 🏗️ 4. KIẾN TRÚC THƯ MỤC CHÍNH

```text
├── app/
│   ├── Controllers/          # AdminCatalogController, ClientOrderController, ClientCancellationController, KdsPosController, DataPipelineController
│   ├── Core/Database.php     # PDO Singleton (MySQL / SQLite fallback)
│   ├── Services/             # AprioriService, EtlService, SharedCartService, OrderToppingSnapshotService, AuditLogService
│   ├── src/                  # auth.php, rbac.php, config.php, db.php, mailer.php, csrf.php, validator.php
│   └── public/               # Entry PHP pages (portal, dashboard, products, documents, login, register) + api_router.php
│       └── frontend/
│           ├── admin/        # table-management.php
│           ├── menu/         # Customer QR Menu (HTML/CSS/JS)
│           ├── kds/          # Kitchen Display System
│           ├── pos/          # Cashier POS
│           ├── live-ops/     # Real-time Ops & Apriori Monitor
│           └── img/          # 35 ảnh món ăn thực đơn (.jpg & .svg)
├── database/                 # init.sql, schema.sql, seed.php, migrations/
├── docker/php/               # Dockerfile (PHP 8.2 + Apache + PDO MySQL + ZipArchive)
├── tests/                    # run_tests.php, test_tv4_core.php, test_live_e2e.php
└── docker-compose.yml        # php-app (8080), mysql (3307), phpmyadmin (8081), tunnel (Cloudflare)
```

---

## 🧪 5. CHẠY BỘ KIỂM THỬ TỰ ĐỘNG TRÊN DOCKER

Chạy cả 3 bộ kiểm thử bất kỳ lúc nào sau khi chỉnh code:

```bash
docker compose exec php-app php /var/www/tests/run_tests.php
docker compose exec php-app php /var/www/tests/test_tv4_core.php
docker compose exec php-app php /var/www/tests/test_live_e2e.php
```

* **`tests/run_tests.php` (`122/122` PASS):** Kiểm thử Auth/Email/Login, Ma trận phân quyền RBAC/API 401-403/Portal navigation, 35 món ăn & phân trang SQL, vòng đời Bàn/KDS/POS, bất biến tài chính OLTP↔OLAP, ETL idempotency, Apriori và Export BI Excel (`.xlsx`/`.csv`/`.json`).
* **`tests/test_tv4_core.php` (`23/23` PASS):** `SharedCartService`, `EtlService`, `AprioriService` và `DataPipelineController`.
* **`tests/test_live_e2e.php` (`72/72` PASS):** E2E thực tế qua HTTP + Apache + MySQL 8.0 + Cloudflare Tunnel API + kiểm tra toàn bộ `35/35` ảnh món HTTP 200, dữ liệu BI $\ge 7$ ngày và đủ 4 nhóm Ma trận Menu Engineering.
