# 📋 BẢNG THEO DÕI CÔNG VIỆC TỔNG HỢP (MASTER TASK TRACKER) — DỰ ÁN DINEFLOW (PHP)

> **Kiến trúc chuẩn hóa:** PHP 8.2 thuần (Vanilla PHP Modular Monolith) + PDO (MySQL 8.0 chính / SQLite fallback) + Docker Compose + HTML5 + CSS3 + FORM + JavaScript + HTML DOM  
> **Người điều phối & Kiểm soát chất lượng (Lead):** Thành viên 4 (TV4)  
> **Bộ 4 tài liệu chính thức của dự án:**
> 1. [`README.md`](file:///D:/code/test%20Dl/README.md) — Hướng dẫn cài đặt, chạy Server PHP Docker & Phân công file cho cả 5 thành viên.
> 2. [`DINEFLOW_WEB_TEAM_SPEC_V1.md`](file:///D:/code/test%20Dl/DINEFLOW_WEB_TEAM_SPEC_V1.md) — Đặc tả kỹ thuật, luồng nghiệp vụ, phân quyền RBAC & 24 ca nghiệm thu cho cả nhóm.
> 3. [`tv4.md`](file:///D:/code/test%20Dl/tv4.md) — Hồ sơ kỹ thuật chuyên sâu, 7 trụ cột chất lượng & kịch bản demo dành riêng cho TV4.
> 4. [`TASK_CHECKLIST_DINEFLOW_PHP.md`](file:///D:/code/test%20Dl/TASK_CHECKLIST_DINEFLOW_PHP.md) — Bảng theo dõi và đánh dấu (`[x]`) tiến độ công việc toàn dự án.

---

## 🟢 GIAI ĐOẠN 0: RÀ SOÁT, THU GỌN & CHUẨN HÓA TÀI LIỆU SANG PHP (HOÀN TẤT 100%)

- [x] **TASK-0.1:** Đọc và phân tích toàn bộ tài liệu dự án, chuẩn hóa bộ tài liệu theo kiến trúc thực tế **PHP 8.2 thuần + PDO MySQL 8.0 + Docker**.
- [x] **TASK-0.2:** Chốt kiến trúc chính thức: Sử dụng **PHP 8.2 thuần (Modular Monolith, PDO Prepared Statements)** kết hợp HTML5, CSS3, FORM, JS & HTML DOM.
- [x] **TASK-0.3:** Loại bỏ 100% các tham chiếu công nghệ cũ (FastAPI/Python, Laravel/PostgreSQL giả định chưa triển khai) trên toàn bộ mã nguồn và tài liệu.
- [x] **TASK-0.4:** Chốt 11 câu hỏi kỹ thuật & thông số vận hành (SLO: 15–20 bàn, 30 khách đồng thời, $p85 \le 1\text{s}$, VAT 8%, phí dịch vụ 0đ, làm tròn mốc 500đ) tại [`DINEFLOW_WEB_TEAM_SPEC_V1.md`](file:///D:/code/test%20Dl/DINEFLOW_WEB_TEAM_SPEC_V1.md).

---

## 🔨 GIAI ĐOẠN 1 (SPRINT S1): KHỞI TẠO NỀN TẢNG PHP 8.2 DOCKER & CƠ SỞ DỮ LIỆU KÉP
> **Chủ trì:** **TV4** | **Hỗ trợ:** TV1, TV5

- [x] **TASK-1.1 [TV4]:** Cấu hình Docker Compose 3 dịch vụ (`php-app`, `mysql`, `phpmyadmin`), `docker/php/Dockerfile` cài Composer/PHPMailer (`php_vendor` volume), và cấu hình `.env` / `app/src/config.php` kết nối PDO MySQL (`ERRMODE_EXCEPTION`, `FETCH_ASSOC`) + SQLite fallback.
- [x] **TASK-1.2 [TV4]:** Hoàn thiện CSDL **`oltp` & RBAC/Kho** trong [`database/init.sql`](file:///D:/code/test%20Dl/database/init.sql) và [`database/schema.sql`](file:///D:/code/test%20Dl/database/schema.sql):
  - [x] `users`, `roles`, `permissions`, `user_roles`, `role_permissions`, `user_permissions` (RBAC Bài B)
  - [x] `dining_tables`, `table_sessions` (vòng đời phiên bàn & `session_token` QR)
  - [x] `categories`, `menu_items` (`DECIMAL(12,2)` / `DECIMAL(10,2)`), `toppings`, `combo_rules`, `shared_carts`
  - [x] `orders`, `order_items`, `bill_payments` (lưu snapshot giá, số lượng, topping, trạng thái bếp)
  - [x] `products`, `product_lots`, `product_prices`, `stock_balances`, `stock_movements`, `inventory_documents`, `inventory_lines` (Bài A, C, D)
- [x] **TASK-1.3 [TV4]:** Hoàn thiện Star Schema **`olap`** trong [`database/init.sql`](file:///D:/code/test%20Dl/database/init.sql) và `database/schema.sql`:
  - [x] 3 bảng chiều: `dim_time`, `dim_menu_item`, `dim_chef`
  - [x] 2 bảng sự kiện: `fact_order_items`, `fact_clickstream`
- [x] **TASK-1.4 [TV4]:** Nạp dữ liệu mẫu tự động qua `database/init.sql` (MySQL Docker) & [`database/seed.php`](file:///D:/code/test%20Dl/database/seed.php) (SQLite fallback): tài khoản mẫu, bàn ăn, thực đơn, toppings, combo rules và phiếu kho nháp.
- [x] **TASK-1.5 [TV4]:** Xây dựng lớp kết nối & truy vấn chuẩn PDO: [`app/Core/Database.php`](file:///D:/code/test%20Dl/app/Core/Database.php), [`app/src/db.php`](file:///D:/code/test%20Dl/app/src/db.php), và bộ định tuyến API [`app/public/api_router.php`](file:///D:/code/test%20Dl/app/public/api_router.php).

---

## 🔐 GIAI ĐOẠN 2 (SPRINT S1–S2): XÁC THỰC, PHÂN QUYỀN RBAC & QUẢN TRỊ TV1
> **Chủ trì:** **TV1** | **Kiểm duyệt tích hợp:** **TV4**

- [x] **TASK-2.1 [TV1]:** Xây dựng [`app/src/auth.php`](file:///D:/code/test%20Dl/app/src/auth.php), [`app/src/mailer.php`](file:///D:/code/test%20Dl/app/src/mailer.php) và các trang `login.php`, `register.php`, `verify_email.php` (validate form client/server, `Remember Me`, kích hoạt email SMTP qua PHPMailer).
- [x] **TASK-2.2 [TV1 + TV4]:** Xây dựng lõi RBAC [`app/src/rbac.php`](file:///D:/code/test%20Dl/app/src/rbac.php) (`deny riêng > allow riêng > nhóm > 403`), bảo vệ cả GET và POST trên `dashboard.php`, `products.php`, `documents.php`.
- [ ] **TASK-2.3 [TV1 + Cả nhóm]:** Hợp nhất giao diện Staff Portal dùng chung thanh điều hướng & `requirePermission` đồng nhất giữa các trang HTML frontend (`/frontend/tv1/*`, `kds.html`, `pos.html`) và trang PHP (`/dashboard.php`, `/products.php`, `/documents.php`).
- [x] **TASK-2.4 [TV1]:** Xây dựng [`app/Controllers/AdminCatalogController.php`](file:///D:/code/test%20Dl/app/Controllers/AdminCatalogController.php) & giao diện Quản lý Bàn ([`frontend/tv1/table-management.html`](file:///D:/code/test%20Dl/frontend/tv1/table-management.html)):
  - [x] Mở bàn & sinh mã QR với `session_token`
  - [x] Chặn mở đè bàn khi bàn đang `OCCUPIED` (**`INV-6`**)
  - [ ] Nghiệp vụ **Chuyển bàn** và **Gộp bàn** trên giao diện UI (mở rộng sau MVP)
- [x] **TASK-2.5 [TV1]:** Xây dựng Quản lý Thực đơn ([`frontend/tv1/menu-management.html`](file:///D:/code/test%20Dl/frontend/tv1/menu-management.html)) & Bài A Phân trang thật SQL (`LIMIT/OFFSET`, giữ bộ lọc URL) tại [`app/public/products.php`](file:///D:/code/test%20Dl/app/public/products.php):
  - [x] Thêm món mới, bật/tắt trạng thái Còn món / Tạm hết cho món ăn & topping.
  - [ ] Upload file ảnh món trực tiếp qua PHP storage an toàn (mở rộng).

---

## 📱 GIAI ĐOẠN 3 (SPRINT S2): MENU KHÁCH QR, GIỎ HÀNG CHUNG REST & GỬI ĐƠN AN TOÀN
> **Chủ trì:** **TV2** | **Phối hợp & Kiểm duyệt (INV-1, INV-2):** **TV4**

- [x] **TASK-3.1 [TV2]:** Xây dựng giao diện Mobile Khách quét QR ([`frontend/menu/index.html`](file:///D:/code/test%20Dl/frontend/menu/index.html), [`frontend/js/menu.js`](file:///D:/code/test%20Dl/frontend/js/menu.js)):
  - [x] Lấy thông tin phiên bàn theo `session_token` (`/api/v1/client/session/{token}`) và tải catalog (`/api/v1/admin/menu-catalog`).
  - [x] Dùng JavaScript & **HTML DOM** để lọc món, chọn topping, cập nhật giỏ hàng mượt mà không cần tải lại trang.
- [x] **TASK-3.2 [TV4 + TV2]:** Tích hợp [`app/Services/SharedCartService.php`](file:///D:/code/test%20Dl/app/Services/SharedCartService.php) lưu giỏ hàng chung (file lock + bảng `shared_carts`) và đồng bộ qua REST API `/api/v1/client/session/{token}/cart` (polling 4s).
- [x] **TASK-3.3 [TV2]:** Xây dựng API Gửi đơn xuống bếp (`POST /api/v1/client/session/{token}/submit-order` trong [`app/Controllers/ClientOrderController.php`](file:///D:/code/test%20Dl/app/Controllers/ClientOrderController.php)):
  - [x] **BẮT BUỘC (`INV-1`):** Client chỉ gửi ID món/topping/số lượng; Server tự mở `PDO Transaction`, tra giá gốc từ `menu_items` và `toppings` để tính tổng tiền.
  - [x] **BẮT BUỘC (`INV-2`):** Kiểm tra `Idempotency-Key` trong bảng `orders` (trả `409 Conflict` nếu trùng) và khóa nút bấm trên DOM khi đang gửi.
  - [x] Cho phép khách **đặt thêm món nhiều đợt** vào cùng `table_session` đang mở.
- [x] **TASK-3.4 [TV2 + TV4]:** Hiển thị thanh **"🔥 Món hay được gọi kèm"** trên popup chi tiết món từ API `/api/v1/data/recommendations/{id}` và ghi nhận Clickstream.

---

## 👨‍🍳 GIAI ĐOẠN 4 (SPRINT S3): ĐIỀU PHỐI BẾP KDS, THU NGÂN POS & KHO BÀI D
> **Chủ trì:** **TV3** | **Kiểm duyệt Transaction & SLA:** **TV4**

- [x] **TASK-4.1 [TV3]:** Xây dựng màn hình Bếp KDS ([`frontend/kds.html`](file:///D:/code/test%20Dl/frontend/kds.html), [`frontend/js/kds.js`](file:///D:/code/test%20Dl/frontend/js/kds.js), [`app/Controllers/KdsPosController.php`](file:///D:/code/test%20Dl/app/Controllers/KdsPosController.php)):
  - [x] Nhận đơn mới định kỳ từ `/api/v1/staff/kds/items` và cập nhật lên HTML DOM an toàn (`escapeHtml`).
  - [x] Chuyển trạng thái từng món: `PENDING` $\rightarrow$ `COOKING` $\rightarrow$ `COMPLETED` (hỗ trợ `READY`, `SERVED`), tính `completed_at` để phục vụ SLA.
  - [ ] Giao diện/chính sách **Hủy món** có lý do & audit log (mở rộng).
- [x] **TASK-4.2 [TV3]:** Xây dựng màn hình Thu ngân POS ([`frontend/pos.html`](file:///D:/code/test%20Dl/frontend/pos.html), [`frontend/js/pos.js`](file:///D:/code/test%20Dl/frontend/js/pos.js)):
  - [x] Xem hóa đơn `/api/v1/staff/pos/bill/{session_id}`: Cộng **VAT 8%**, **Phí phục vụ 0đ**, và **làm tròn mốc 500đ**.
  - [ ] Tích hợp sinh mã **VietQR động chuẩn Napas 247** hiển thị trên màn hình POS (mở rộng).
- [x] **TASK-4.3 [TV3 + TV4]:** Xây dựng API Chốt Thanh toán & Đóng bàn (`POST /api/v1/staff/pos/checkout/{session_id}`) & Kho Bài D ([`app/public/documents.php`](file:///D:/code/test%20Dl/app/public/documents.php), [`app/src/inventory.php`](file:///D:/code/test%20Dl/app/src/inventory.php)):
  - [x] Chốt thanh toán trong `PDO Transaction`, chặn thanh toán lặp lại trên phiên đã đóng, giải phóng bàn `AVAILABLE`, xóa Shared Cart và tự động gọi `EtlService::processClosedSession($sessionId)`.
  - [x] Quản lý phiếu nhập/xuất kho `DRAFT -> POSTED`, chống âm tồn nguyên tử và in chứng từ chuẩn `@media print`.

---

## 📊 GIAI ĐOẠN 5 (SPRINT S4): REAL-TIME DATA PIPELINE (ETL), GỢI Ý APRIORI & BÁO CÁO BI
> **Chủ trì:** **TV4** (ETL, Apriori, SLA, Live Ops) & **TV5** (BI Dashboard, Menu Matrix)

- [x] **TASK-5.1 [TV4]:** Hoàn thiện [`app/Services/EtlService.php`](file:///D:/code/test%20Dl/app/Services/EtlService.php) và API `POST /api/v1/data/trigger-batch-etl` đảm bảo tính **Idempotent** (`is_etl_processed = 1`).
- [x] **TASK-5.2 [TV4]:** Hoàn thiện [`app/Services/AprioriService.php`](file:///D:/code/test%20Dl/app/Services/AprioriService.php) và [`app/Controllers/DataPipelineController.php`](file:///D:/code/test%20Dl/app/Controllers/DataPipelineController.php) (Apriori Support/Confidence/Lift, Combo Rules, Kitchen SLA > 900s).
- [x] **TASK-5.3 [TV4]:** Hoàn thiện giao diện Điều phối Thời gian thực ([`frontend/tv4_data_pipeline.html`](file:///D:/code/test%20Dl/frontend/tv4_data_pipeline.html)) auto-refresh 5s qua REST API.
- [x] **TASK-5.4 [TV5]:** Xây dựng API BI (`/api/v1/bi/dashboard-summary`, `/api/v1/bi/revenue-over-time`, `/api/v1/bi/menu-engineering-matrix`), giao diện biểu đồ trên [`demo_tester.html`](file:///D:/code/test%20Dl/demo_tester.html) và [`app/public/dashboard.php`](file:///D:/code/test%20Dl/app/public/dashboard.php):
  - [ ] Bổ sung chức năng xuất báo cáo doanh thu ra tệp **Excel (`.xlsx`)** riêng (mở rộng).

---

## 🧪 GIAI ĐOẠN 6 (SPRINT S5): KIỂM THỬ TÍCH HỢP, SỬA LỖI & NGHIỆM THU BẢO VỆ ĐỒ ÁN
> **Chủ trì:** **TV4 (Quality & Integration Lead)** | **Tham gia:** Toàn bộ 5 thành viên

- [x] **TASK-6.1 [TV4]:** Xây dựng bộ kiểm thử tự động PHP CLI [`tests/run_tests.php`](file:///D:/code/test%20Dl/tests/run_tests.php) (**64 ca kiểm thử** bao phủ Mục 38–40, Bài B–D và 7 Trụ cột Chất lượng TV4).
- [x] **TASK-6.2 [TV4]:** Xây dựng bộ kiểm thử lõi E2E TV4 tại [`tests/test_tv4_core.php`](file:///D:/code/test%20Dl/tests/test_tv4_core.php) và trang kiểm thử luồng tích hợp [`demo_tester.html`](file:///D:/code/test%20Dl/demo_tester.html).
- [ ] **TASK-6.3 [TV4]:** Khởi động Docker Desktop và chạy xác nhận lại cả 2 bộ kiểm thử (`docker compose exec php-app php /var/www/tests/run_tests.php` và `test_tv4_core.php`) đạt **100% PASS** trên bản cập nhật mới nhất.
- [ ] **TASK-6.4 [Cả nhóm]:** Chuẩn bị kịch bản Demo bảo vệ đồ án và phân vai trả lời phản biện trước Hội đồng.
