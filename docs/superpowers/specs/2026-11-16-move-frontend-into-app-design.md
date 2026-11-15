# Short Design: Move & Reorganize Frontend into `app/public/`

## Intent & Why
Gom toàn bộ mã nguồn frontend (`frontend/`, `demo_tester.html`) vào trong `app/public/` để quản lý tập trung cùng backend PHP, bỏ bind-mount chồng chéo trong Docker, và tổ chức lại `app/public/frontend/` theo **nghiệp vụ/module thực tế** thay cho tên theo thành viên (`tv1/`, `tv4_data_pipeline.html`) hay để file `.html` lẻ bên ngoài.

## Goals
- Di chuyển `demo_tester.html` $\rightarrow$ `app/public/demo_tester.html` (thay thế placeholder 0-byte).
- Di chuyển toàn bộ 12 file từ `frontend/` vào `app/public/frontend/` theo cấu trúc module rõ ràng:
  - `admin/` (`table-management.html`, `menu-management.html` — đổi từ `tv1/`)
  - `menu/` (`index.html` — giữ nguyên đường dẫn QR của khách)
  - `kds/` (`index.html`, `kds.js` — gom từ `kds.html` & `js/kds.js`)
  - `pos/` (`index.html`, `pos.js` — gom từ `pos.html` & `js/pos.js`)
  - `live-ops/` (`index.html`, `live-ops.js`, `style.css` — tách gọn từ `tv4_data_pipeline.html`)
  - `css/` (`menu.css`, `shop.css`) và `js/` (`menu.js`, `qrcode.js`) cho asset của menu khách & thư viện QR dùng chung.
- Giữ redirect 302 trong `app/public/.htaccess` cho các URL cũ (`/tv4_data_pipeline.html`, `/frontend/tv4_data_pipeline.html`, `/frontend/tv1/*`, `/frontend/kds.html`, `/frontend/pos.html`) sang đường dẫn mới.
- Gỡ 2 bind mount thừa trong `docker-compose.yml` (`./frontend:...` và `./demo_tester.html:...`).
- Cập nhật link điều hướng trong PHP (`dashboard.php`, `products.php`, `documents.php`), `live-ops/index.html`, và tài liệu (`README.md`, `DINEFLOW_WEB_TEAM_SPEC_V1.md`, `TASK_CHECKLIST_DINEFLOW_PHP.md`, `tv4.md`).

## Non-Goals
- Không tách `database/init.sql` (giữ nguyên 1 file).
- Không di chuyển `database/`, `tests/`, hoặc `composer.json` vào trong `app/`.
- Không thay đổi logic nghiệp vụ PHP/JS, API `/api/v1/*`, hay giao diện các màn hình.
- Không đổi URL menu QR `/frontend/menu/index.html?token=...` (giữ nguyên `AdminCatalogController.php` và `menu.js`).

## Anti-Goals
- Không dùng tên thư mục kiểu `tv1/`, `tv2/`, `tv3/`, `tv4/` hay để file `.html` rải rác ở `frontend/`.
- Không làm gãy đường dẫn cũ (nhờ redirect 302 trong `.htaccess`) hoặc làm gãy `table-management.html` (`../js/qrcode.js`) và `menu/index.html` (`../css/shop.css`, `../js/menu.js`).

## Constraints
- Apache sử dụng `APACHE_DOCUMENT_ROOT=/var/www/html/public` và `mod_rewrite` đã bật trong `docker/php/Dockerfile`.
- `docker-compose.yml` mount `./app:/var/www/html`.
- `app/public/frontend` (thư mục rỗng) và `app/public/demo_tester.html` (0 byte) đang được theo dõi trong Git.

## Approaches Considered
1. **Tổ chức theo module nghiệp vụ (`admin/`, `menu/`, `kds/`, `pos/`, `live-ops/`) + tách `live-ops` thành HTML/JS/CSS (Đã chọn):**
   - *Ưu điểm:* Nhìn chuyên nghiệp như dự án thực tế, không còn tên `tv1`/`tv4` hay file `.html` lộn xộn ở tầng ngoài, tách riêng HTML/JS/CSS cho màn hình Data Pipeline & Live Ops. Link cũ vẫn sống qua `.htaccess`.
   - *Đánh đổi:* Cần cập nhật đường dẫn ở `dashboard.php`, `products.php`, `documents.php`, `.htaccess` và tài liệu.
2. **Giữ tên `tv1/` và tạo thêm `tv4/`:**
   - *Không chọn:* Tên thư mục gắn số thành viên thiếu tự nhiên khi quản lý sản phẩm và vẫn để `kds.html`, `pos.html` lẻ bên ngoài.
3. **Tách `live-ops` thành 2 folder con (`live-ops/` và `data-pipeline/`):**
   - *Không chọn:* Người dùng chọn giữ 1 màn hình tổng hợp (Cách 1) cho thuận tiện vận hành & demo.

## Left to Builder & Verification
- Kiểm tra toàn bộ đường dẫn tương đối (`../js/qrcode.js`, `../css/shop.css`, `../js/menu.js`, `kds.js`, `pos.js`, `live-ops.js`, `style.css`).
- Xác nhận không còn `frontend/` và `demo_tester.html` ở gốc repo, không còn tham chiếu file hỏng.
- Kiểm tra `git status` và cấu hình Docker / test liên quan.
