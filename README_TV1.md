# DineFlow — Hướng dẫn thành viên TV1

> **Mục đích:** Tài liệu onboarding và bàn giao cho TV1 — phụ trách xác thực, quản lý bàn/QR và quản lý món. Nội dung mô tả triển khai hiện tại, không thay thế các quyết định trong spec hoặc checklist của nhóm.
>
> **Cập nhật:** 2026-11-17  
> **Stack đã chốt:** PHP 8.2 thuần + PDO/MySQL 8.0 + Docker Compose + HTML/CSS/JavaScript. Không tự đổi framework, schema, API contract hoặc state machine nếu chưa được nhóm duyệt.

## 1. Đọc trước khi bắt đầu

- [Đặc tả kỹ thuật nhóm](./DINEFLOW_WEB_TEAM_SPEC_V1.md)
- [Checklist task](./TASK_CHECKLIST_DINEFLOW_PHP.md)
- [README chung: khởi chạy, URL, role và kiểm thử](./README.md)
- [Tài liệu phối hợp TV4](./tv4.md)
- [Tài liệu phối hợp TV5](./tv5.md)

Khi tài liệu hoặc mã nguồn có mô tả khác nhau, không tự suy diễn thay đổi nghiệp vụ. Báo nhóm và thống nhất trước; TV4 là đầu mối về schema, API contract, RBAC lõi và tích hợp.

## 2. Phạm vi TV1 và ranh giới với các thành viên

TV1 phụ trách giao diện và luồng quản trị bàn/menu, đồng thời phối hợp tích hợp đăng nhập và quyền:

- **TV1:** xác thực người dùng, quản lý bàn và phát QR, quản lý món/topping, validation phía giao diện và luồng bàn giao.
- **TV2:** menu khách QR, trải nghiệm trên điện thoại, giỏ hàng, hủy món đang chờ (`PENDING`) và gửi order.
- **TV3:** KDS, POS (đa bàn/VietQR), thanh toán và hóa đơn.
- **TV4:** Docker, kết nối CSDL, RBAC lõi, transaction/API dùng chung và bộ test tích hợp.
- **TV5:** báo cáo, BI, Menu Engineering 4 Quadrants và đối soát dữ liệu.

Role đang dùng trong nghiệp vụ hiện tại:

| Role | Quyền liên quan tới phần TV1 |
|---|---|
| `ADMIN` | Quản trị bàn, menu, nhân viên, cấu hình và giám sát. |
| `CREATOR` | Xem menu/trạng thái bàn và mở phiên bàn; không sửa menu, checkout hay đóng phiên. |
| `KEEPER` | Không quản lý bàn/menu; thao tác nghiệp vụ bếp trên KDS. |
| `VIEWER` | Không quản lý bàn/menu; xem POS, xác nhận thanh toán, in hóa đơn và xem báo cáo BI. |

Sidebar chỉ hỗ trợ điều hướng. API và trang PHP vẫn phải kiểm tra quyền server-side. Quyền cụ thể lấy từ permission catalog và role mapping; không dùng tên tài khoản hoặc dữ liệu `localStorage` làm nguồn phân quyền.

## 3. Những gì đã được cập nhật

### [MVP] Bàn, phiên và QR

- Trang quản lý bàn hiển thị trạng thái và cho phép role có quyền mở bàn tạo phiên (`tables.open`), đồng thời role có `tables.manage` có thể thêm bàn mới từ form trên trang.
- Backend chặn mở phiên thứ hai khi bàn đã có phiên `ACTIVE` (`409 Conflict`); token QR gắn với phiên bàn.
- QR dẫn tới cùng trang menu khách, kèm `table` và `token`. Mở URL menu không có token chỉ là chế độ xem thử, không gắn giỏ hàng/order với phiên bàn.
- Có cấu hình **Địa chỉ gốc cho QR** tại trang Quản lý Bàn để điện thoại mở được server trên LAN.

### [MVP] Quản lý món và topping

- Màn hình chính hiện là [`app/public/products.php`](./app/public/products.php), gộp quản lý món (thêm món qua modal kèm `image_url` tùy chọn, sửa món, phân trang/tìm kiếm, thống kê trạng thái động toàn catalog, bật/tắt từng món và gán topping).
- Khi tạo/sửa món qua `AdminCatalogController`, backend tự đồng bộ sang bảng `products` để danh sách phân trang SQL và catalog luôn nhất quán.
- Topping được gán **trực tiếp cho từng món** qua quan hệ `menu_item_toppings`; không gán theo danh mục. Dữ liệu liên kết phải được quản trị viên cấu hình, không tự động gán topping cho món.
- Thao tác tạo/sửa/trạng thái/mapping cần permission `products.manage` ở API và có audit (`audit_logs`).
- TV2 dùng cùng catalog (`/api/v1/admin/menu-catalog`) để hiển thị menu khách. Khi gửi order, backend xác minh món/topping và giá; thông tin topping được snapshot để KDS/POS/hóa đơn không phụ thuộc dữ liệu cấu hình thay đổi về sau. Giá topping đã nằm trong `order_items.unit_price`, không cộng lần hai.

### [MVP] Portal và tương thích URL

- Staff portal dùng shared partial ([`app/public/partials/staff_portal_nav.php`](./app/public/partials/staff_portal_nav.php)) và điều hướng theo quyền.
- Các route PHP là trang thao tác thực tế (`/frontend/admin/table-management.php`, `/frontend/kds/index.php`, `/frontend/pos/index.php`, `/frontend/live-ops/index.php`); các URL `.html` cũ được giữ làm redirect alias.
- Thay đổi này không biến portal thành SPA và không thay state machine nghiệp vụ.

## 4. Luồng nghiệp vụ cần nắm

1. Nhân viên đăng nhập; server xác minh session và permission, tự chuyển hướng tới đúng màn hình landing theo role.
2. `ADMIN` hoặc `CREATOR` có quyền chọn bàn trống. Backend tạo một phiên `ACTIVE`, liên kết bàn (`current_session_id`) và sinh token QR. Không mở phiên mới nếu phiên cũ vẫn đang hoạt động.
3. Khách quét QR. Menu tải catalog, xác minh token/phiên, rồi đồng bộ giỏ hàng theo phiên. Token là thông tin truy cập phiên; không đăng token thật lên issue/chat công khai.
4. Khi khách gửi order, backend kiểm tra phiên, trạng thái món và topping, tự tính giá phía server và dùng `Idempotency-Key` để chống tạo order trùng khi retry.
5. `KEEPER` nhận order trên KDS và cập nhật trạng thái món. Topping trong snapshot đi kèm order để bếp chuẩn bị chính xác.
6. `VIEWER` kiểm tra hóa đơn, nhận thanh toán, in hóa đơn và đóng phiên theo quyền. `ADMIN` giám sát và cấu hình; không thay thế luồng KDS/POS bằng thao tác ngoài quy trình.

## 5. Bản đồ mã nguồn

| Đường dẫn | Trách nhiệm |
|---|---|
| [`app/public/login.php`](./app/public/login.php), `register.php`, `verify_email.php` | Đăng nhập, đăng ký và xác nhận email. |
| [`app/public/dashboard.php`](./app/public/dashboard.php) | Dashboard nhân viên & BI Analytics; điều hướng portal dùng quyền của server. |
| [`app/public/frontend/admin/table-management.php`](./app/public/frontend/admin/table-management.php) | Trạng thái bàn, mở phiên, tạo bàn mới, hiển thị/cấu hình QR. |
| [`app/public/products.php`](./app/public/products.php) | Màn hình hợp nhất quản lý món, trạng thái và topping. |
| [`app/public/partials/staff_portal_nav.php`](./app/public/partials/staff_portal_nav.php) | Shared navigation của staff portal. |
| [`app/public/api_router.php`](./app/public/api_router.php) | Định tuyến API và kiểm tra permission cho endpoint nhân viên. |
| [`app/Controllers/AdminCatalogController.php`](./app/Controllers/AdminCatalogController.php) | API bàn, menu catalog, CRUD món + đồng bộ `products`, cấu hình topping theo món. |
| [`app/Controllers/ClientOrderController.php`](./app/Controllers/ClientOrderController.php) | Phiên QR phía khách, giỏ hàng và gửi order an toàn. |
| [`app/Services/OrderToppingSnapshotService.php`](./app/Services/OrderToppingSnapshotService.php) | Lưu/đọc snapshot topping phục vụ history, KDS và POS. |
| [`app/src/auth.php`](./app/src/auth.php), [`app/src/rbac.php`](./app/src/rbac.php) | Xác thực session, kiểm quyền và trang đích theo role. |
| [`app/public/frontend/menu/index.html`](./app/public/frontend/menu/index.html), [`app/public/frontend/js/menu.js`](./app/public/frontend/js/menu.js) | Menu khách QR, giỏ hàng, hủy món đang chờ, gửi order và lịch sử order. |
| [`database/init.sql`](./database/init.sql), [`database/schema.sql`](./database/schema.sql) | Schema cho cài mới/test. |
| [`database/migrations/20261117_rbac_permissions_catalog.sql`](./database/migrations/20261117_rbac_permissions_catalog.sql) | Bổ sung 17 permission vào bảng `permissions` trên DB cũ trước khi seed mapping. |
| [`database/migrations/20261117_menu_topping_audit.sql`](./database/migrations/20261117_menu_topping_audit.sql) | Bổ sung `audit_logs`, `menu_item_toppings` và `order_item_topping_snapshots`. |
| [`database/seed_role_permissions.sql`](./database/seed_role_permissions.sql) | Làm mới mapping `role_permissions` khi permission catalog đã tồn tại. |
| [`tests/run_tests.php`](./tests/run_tests.php), [`tests/test_live_e2e.php`](./tests/test_live_e2e.php) | Suite kiểm thử SQLite và suite E2E HTTP/MySQL thực tế. |

## 6. API liên quan trực tiếp tới TV1

Các URL dưới đây là đường dẫn sau prefix `/api/v1`:

| API | Mục đích / quyền |
|---|---|
| `GET /admin/tables` | Danh sách bàn ăn; cần `tables.view`. |
| `POST /admin/tables` | Tạo bàn mới; cần `tables.manage`. |
| `POST /admin/tables/{id}/open-session` | Mở phiên bàn; cần `tables.open`. |
| `GET /admin/menu-catalog` | Catalog cho menu khách và các màn hình cần catalog. |
| `POST /admin/menu-items` | Tạo món; cần `products.manage`. |
| `PATCH /admin/menu-items/{id}` | Sửa món; cần `products.manage`. |
| `PATCH /admin/menu-items/{id}/toggle-availability` | Đổi trạng thái bán từng món; cần `products.manage`. |
| `PATCH /admin/menu-items/{id}/toppings` | Gán topping trực tiếp cho món; cần `products.manage`. |
| `GET /client/session/{token}` | Lấy thông tin phiên QR. |
| `GET/POST /client/session/{token}/cart` | Đọc/cập nhật giỏ chung của phiên. |
| `POST /client/session/{token}/submit-order` | Kiểm tra và gửi order an toàn xuống bếp (yêu cầu `Idempotency-Key`). |
| `GET /client/session/{token}/status` | Lịch sử/trạng thái order của phiên kèm topping snapshot. |

## 7. Khởi chạy và vận hành local

Chạy tại thư mục gốc repo, sau khi Docker Desktop hoạt động:

```bash
docker compose up -d
docker compose ps
```

*(Nếu dùng bản Compose cũ: thay `docker compose` bằng `docker-compose`.)*

URL local thường dùng:

- Đăng nhập: `http://localhost:8080/login.php`
- Bàn & QR: `http://localhost:8080/frontend/admin/table-management.php`
- Quản lý món: `http://localhost:8080/products.php`
- Thực đơn khách: `http://localhost:8080/frontend/menu/index.html`
- phpMyAdmin: `http://localhost:8081/`

Không chạy `docker compose down -v` nếu không chủ ý xóa volume dữ liệu MySQL.

### Cấu hình QR cho điện thoại

1. Mở Quản lý Bàn trên máy host.
2. Nhập IPv4 của card Wi‑Fi/Ethernet đang dùng, ví dụ `http://192.168.1.x:8080`, tại **Địa chỉ gốc cho QR** rồi lưu.
3. Quét QR mới tạo; điện thoại và máy host phải ở cùng mạng, port `8080` không bị firewall chặn.
4. Nếu IP của máy host đổi, cập nhật URL QR và tạo/in lại QR.

## 8. Database, migration và dữ liệu topping

- Cài mới dùng schema trong `database/init.sql` / `database/schema.sql`.
- Volume MySQL đã tồn tại không tự chạy lại `init.sql`. Với DB cũ từ bản trước:
  1. Chạy `database/migrations/20261117_rbac_permissions_catalog.sql` (nạp đủ 17 permission).
  2. Chạy `database/migrations/20261117_menu_topping_audit.sql` (tạo `audit_logs`, `menu_item_toppings`, `order_item_topping_snapshots`).
  3. Chạy `database/seed_role_permissions.sql` (cập nhật lại mapping 4 role).
  4. Nếu DB nâng cấp từ trước PR #13, kiểm tra thêm `database/migrations/20261115_add_orders_idempotency_unique.sql`.
- Không có mapping topping–món mặc định. Sau khi migration được áp dụng, ADMIN cần cấu hình mapping tại Quản lý món (`products.php`).
- Order cũ chưa có snapshot không thể khôi phục chi tiết topping từ tổng tiền; giao diện giữ thông báo “Chưa ghi nhận chi tiết topping cho đơn cũ”, không suy diễn thành “không chọn topping”.

## 9. Kiểm thử và nghiệm thu

Chạy bộ suite trong container PHP:

```bash
docker compose exec php-app php /var/www/tests/run_tests.php
docker compose exec php-app php /var/www/tests/test_tv4_core.php
docker compose exec php-app php /var/www/tests/test_live_e2e.php
```

Các ca nghiệm thu TV1 cần giữ:

- Login/logout và request sau logout; không truy cập trang quản trị nếu thiếu quyền (`401`/`403` / redirect `login.php`).
- `ADMIN`/`CREATOR` mở bàn theo permission; bàn đang có phiên `ACTIVE` không sinh phiên thứ hai (`409`).
- QR mới dùng địa chỉ LAN hợp lệ; token hợp lệ hiển thị đúng bàn, token sai/hết hạn không cho thao tác phiên.
- Tạo/sửa món, lọc/phân trang, trạng thái còn/hết và mapping topping; role chỉ xem không thể gọi API quản lý.
- Kiểm tra mapping topping đúng món, giá server-side, snapshot và audit.
- Kiểm tra portal bằng các role thật trên trình duyệt và test QR trên điện thoại thật cùng Wi‑Fi.
