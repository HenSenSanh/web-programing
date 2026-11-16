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
  * **Trang Đăng nhập & Đăng ký:** Xây dựng giao diện `login.php` và `register.php`, tích hợp mã hóa mật khẩu, ghi nhớ đăng nhập `Remember Me` và gửi email kích hoạt tài khoản qua SMTP.
  * **Quản lý Bàn ăn & QR:** Xem trạng thái các bàn (Trống / Đang ăn / Đang chờ thanh toán), mở bàn cho khách và tự động tạo mã QR chứa `session_token`.
  * **Quản lý Thực đơn (Bài A — Phân trang thật):** Thêm, sửa, xóa món ăn, chỉnh sửa đơn giá, bật/tắt trạng thái còn món/hết món. Áp dụng kỹ thuật phân trang thật bằng `COUNT(*)`, `LIMIT`, `OFFSET` trên MySQL, giữ nguyên bộ lọc tìm kiếm trên thanh URL.
* **Các file phụ trách:** `app/public/login.php`, `app/public/register.php`, `app/public/products.php`, `app/public/frontend/admin/table-management.html`, `app/public/frontend/admin/menu-management.html`.

---

### 👤 THÀNH VIÊN 2 (TV2) — Giao Diện Khách Quét QR & Giỏ Hàng Chung
* **Trách nhiệm chính:**
  * **Menu Khách trên Điện thoại (`/frontend/menu/index.html?token={session_token}`):** Hiển thị danh mục món ăn, hình ảnh, mô tả, nhãn "Món Hot", tùy chọn topping và ghi chú món.
  * **Giỏ hàng thời gian thực:** Thao tác trên cây HTML DOM mượt mà không tải lại trang, đồng bộ giỏ hàng chung giữa nhiều thiết bị cùng bàn qua REST API (`/api/v1/client/session/{token}/cart`).
  * **Gửi đơn đặt món an toàn:** Gửi danh sách món xuống bếp kèm mã `Idempotency-Key` (chặn gửi trùng khi bấm đúp chuột), hỗ trợ khách gọi thêm nhiều đợt vào cùng một phiên bàn.
  * **Gợi ý món ăn kèm:** Hiển thị thanh "🔥 Món hay được gọi kèm" trên popup chọn món lấy từ API gợi ý của TV4.
* **Các file phụ trách:** `app/public/frontend/menu/index.html`, `app/public/frontend/js/menu.js`, `app/public/frontend/css/menu.css`.

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
* **Các file phụ trách:** `tv4.md`, `docker-compose.yml`, `database/init.sql`, `app/src/`, `app/Services/`, `app/Controllers/`, `tests/run_tests.php`, `tests/test_tv4_core.php`.

---

### 👤 THÀNH VIÊN 5 (TV5) — Báo Cáo Doanh Thu BI & Ma Trận Thực Đơn
* **Trách nhiệm chính:**
  * **BI Dashboard (`dashboard.php` & `demo_tester.html`):** Hiển thị KPI tổng quan, doanh thu theo giờ/ngày từ API `/api/v1/bi/dashboard-summary` và `/api/v1/bi/revenue-over-time` (truy vấn từ kho Star Schema).
  * **Ma trận Menu Engineering (`/api/v1/bi/menu-engineering-matrix`):** Thống kê lượt xem (clickstream), số lượng bán, doanh thu và tỷ lệ chuyển đổi theo từng món ăn trên `demo_tester.html`.
  * **Báo cáo Hiệu suất & SLA:** Theo dõi chỉ số SLA bếp và báo cáo doanh thu tại `dashboard.php` (tính năng xuất file Excel `.xlsx` riêng là hạng mục mở rộng sau MVP).
* **Các file phụ trách:** `app/public/dashboard.php`, `app/Controllers/DataPipelineController.php` (BI endpoints), phần biểu đồ BI trong `demo_tester.html`.

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
| **Hóa đơn & In phiếu thanh toán** | `http://localhost:8080/documents.php` | TV3 & TV4 |
| **TV1 - Quản lý Bàn & Sinh mã QR** | `http://localhost:8080/frontend/admin/table-management.php` | TV1 |
| **TV1 - Quản lý Danh mục Món ăn** | `http://localhost:8080/frontend/admin/menu-management.php` | TV1 |
| **TV2 - Menu Khách Quét QR** | `http://localhost:8080/frontend/menu/index.html?token=tbl-01-sess` | TV2 |
| **TV3 - Màn hình Bếp KDS** | `http://localhost:8080/frontend/kds/index.php` | TV3 |
| **TV3 - Màn hình Thu ngân POS** | `http://localhost:8080/frontend/pos/` | TV3 |
| **TV4 - Màn hình Điều phối Live Ops** | `http://localhost:8080/frontend/live-ops/` | TV4 |
| **Simulator Demo Toàn Luồng E2E** | `http://localhost:8080/demo_tester.html` | TV4 & TV5 |
| **Quản trị Cơ sở dữ liệu phpMyAdmin** | `http://localhost:8081/` | TV4 & Cả nhóm |

### 🔑 Tài khoản mẫu có sẵn trong MySQL Docker (`database/init.sql`):
* **Tài khoản Quản trị nhà hàng (`ADMIN`):** `admin` (`admin@dineflow.vn`) — Mật khẩu: `Admin@123` (hoặc `123456`)
* **Tài khoản Thu ngân & Phục vụ (`CREATOR`):** `creator` (`creator@dineflow.vn`) — Mật khẩu: `123456`
* **Tài khoản Điều phối Bếp KDS (`KEEPER`):** `keeper` (`keeper@dineflow.vn`) — Mật khẩu: `123456`
* **Tài khoản Giám sát & Báo cáo BI (`VIEWER`):** `viewer` (`viewer@dineflow.vn`) — Mật khẩu: `123456`
* *(Lưu ý: `database/seed.php` chỉ dùng riêng cho SQLite fallback với `admin`/`admin123`, `cashier1`/`cashier123`, `chef_hung`/`chef123`.)*

### Ma trận quyền theo role [MVP]

| Role | Quyền nghiệp vụ |
|---|---|
| `ADMIN` | Toàn quyền quản trị bàn, menu, KDS, POS, báo cáo và dữ liệu. |
| `CREATOR` | Xem/mở bàn, xem menu và theo dõi trạng thái món; không được cập nhật KDS hay xác nhận checkout/đóng phiên. Khách gọi món qua API QR với token phiên, không cần role nhân viên. |
| `KEEPER` | Xem và cập nhật trạng thái món trên KDS; không được mở bàn hoặc checkout. |
| `VIEWER` | Xem/cập nhật POS (xác nhận thanh toán và đóng phiên), xem/in hóa đơn và xem báo cáo. |

API nhân viên kiểm tra permission server-side; chưa đăng nhập nhận `401`, thiếu quyền nhận `403`. Các API menu khách, giỏ hàng và gửi đơn QR tiếp tục dùng xác thực theo session token. Ma trận này phân quyền theo role toàn nhà hàng; schema hiện tại chưa có trường sở hữu bàn theo nhân viên.
Sau đăng nhập, role được đưa tới màn hình hiện có phù hợp: `ADMIN` → dashboard, `CREATOR` → quản lý bàn, `KEEPER` → KDS, `VIEWER` → POS.

- **[CHỐT]** Staff Portal dùng shared partial trên stack PHP hiện tại; riêng phần portal/RBAC không đổi schema hay state machine. Các trang/API tiếp tục kiểm tra đăng nhập và permission ở server-side; ẩn menu chỉ là trải nghiệm người dùng.
- **[MVP]** Các trang nhân viên dùng chung thanh điều hướng theo quyền và chuyển trang cùng tab. URL `.html` cũ được giữ làm redirect alias để tương thích.
- **[NÂNG CAO]** Portal hiện là multi-page, chưa phải SPA hoặc một URL duy nhất; chỉ cân nhắc nếu nhóm duyệt và có yêu cầu riêng.

Với database MySQL đã khởi tạo trước đó, Docker không tự chạy lại file init khi volume còn tồn tại. Nếu catalog cũ thiếu permission mới, chạy phần seed `permissions` trong [`database/init.sql`](database/init.sql) trước. Sau đó [`database/seed_role_permissions.sql`](database/seed_role_permissions.sql) chỉ làm mới `role_permissions`, không tạo permission/role hay đổi schema; script kiểm tra catalog và role cần thiết đã có, nếu thiếu thì dừng.

### Quản lý món, topping và lịch sử order

- **[MVP]** `products.php` là màn hình hợp nhất quản lý món: phân trang/lọc, thống kê trạng thái toàn catalog, bật/tắt từng món, sửa món và gán topping trực tiếp cho từng món. CREATOR chỉ xem; thao tác sửa được kiểm tra quyền ở API.
- **[MVP]** Topping khách chọn được kiểm tra theo liên kết trực tiếp với món và trạng thái còn hàng, chụp tên/giá vào order item, rồi hiển thị trong lịch sử QR, KDS và hóa đơn. `unit_price` đã gồm giá topping nên không cộng tiền lần hai. Order cũ không có snapshot được ghi rõ là chưa có dữ liệu lịch sử topping.
- **[CHỐT]** Trạng thái bán/hết được bật tắt riêng từng món; bộ đếm là số liệu động trên toàn catalog, không làm bulk-toggle hay hardcode số 31/4. Một topping có thể gán cho nhiều món qua `menu_item_toppings`, không gán theo danh mục.
- **[CHỐT]** Schema khởi tạo và migration bổ sung `audit_logs`, `menu_item_toppings`, `order_item_topping_snapshots`. Không tự gán topping cho món; quản trị viên cấu hình từng món để tránh gán sai nghiệp vụ.
- **[NÂNG CAO]** Đơn cũ không có snapshot không thể khôi phục topping từ tổng tiền; không diễn giải việc thiếu snapshot thành “không chọn topping”.

Với MySQL đã tồn tại, sau khi duyệt migration hãy chạy `database/migrations/20261117_menu_topping_audit.sql` một lần trên đúng database. Sau migration, quản trị viên cần cấu hình liên kết topping–món trong `products.php` trước khi khách có thể chọn các topping đó. Không chạy lại `database/init.sql` trên volume đang dùng.

---

## 🧪 4. HƯỚNG DẪN CHẠY BỘ KIỂM THỬ TỰ ĐỘNG BẰNG DOCKER

Mỗi khi bất kỳ thành viên nào chỉnh sửa code, cần chạy lại bộ kiểm thử bên trong container Docker:

```bash
docker compose exec php-app php /var/www/tests/run_tests.php
docker compose exec php-app php /var/www/tests/test_tv4_core.php
```

Màn hình console `run_tests.php` in tổng số ca thực thi. Bộ test bao phủ:
* 7 ca kiểm thử đăng ký tài khoản (Mục 38)
* 4 ca kiểm thử xác nhận email qua token (Mục 39)
* 7 ca kiểm thử đăng nhập và ghi nhớ cookie (Mục 40)
* Ma trận RBAC theo role, ưu tiên `deny/allow`, phản hồi API `401/403` và trang đích sau đăng nhập
* 4 ca kiểm thử Thực đơn 35 món ăn QR Dine & Phân trang thật trên DB
* 5 ca kiểm thử Vòng đời Phiên bàn QR, Đơn bếp KDS & Hóa đơn POS
* 28 ca kiểm thử 7 Trụ cột Chất lượng & Bất biến Nghiệp vụ TV4 (Phần 7: Anti-Tamper, Idempotency-Key, ETL Idempotent, Apriori, Kitchen SLA >900s, Shared Cart Transaction, Star Schema Integrity).

Chỉ ghi nhận PASS sau khi chạy hai lệnh kiểm thử trên môi trường Docker hiện tại.
