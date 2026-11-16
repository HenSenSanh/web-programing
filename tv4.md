# 📋 HỒ SƠ KỸ THUẬT, KIỂM THỬ TÍCH HỢP & BÁO CÁO NHIỆM VỤ THÀNH VIÊN 4 (TV4)
## DỰ ÁN DINEFLOW — HỆ THỐNG GỌI MÓN QR, BẾP KDS & PHÂN TÍCH NHÀ HÀNG F&B
### CHUẨN HÓA THEO ĐẶC TẢ KỸ THUẬT & KHUNG CHẤM ĐIỂM BÀI TẬP LỚN (PHP 8.2 THUẦN + MYSQL 8.0 + DOCKER)

> **Họ và tên:** Thành viên 4 (TV4)  
> **Vai trò trong nhóm:** **Quality & Integration Lead / Backend Architect & Real-time Data Engineer**  
> **Công nghệ nền tảng:** PHP 8.2 thuần (Vanilla PHP), PDO MySQL (`ERRMODE_EXCEPTION`, `FETCH_ASSOC`), Docker Compose, HTML5, CSS3 (`@media print`), JavaScript & HTML DOM  
> **Khung chấm điểm tham chiếu:** Đặc tả hệ thống QR Dine ([`DINEFLOW_WEB_TEAM_SPEC_V1.md`](file:///D:/code/test%20Dl/DINEFLOW_WEB_TEAM_SPEC_V1.md)) và tài liệu [`huong_dan_login_php_mysql_docker_code (2).pdf`](file:///D:/code/test%20Dl/tai%20lieu/huong_dan_login_php_mysql_docker_code%20(2).pdf).

---

## 1. VỊ TRÍ, VAI TRÒ & RANH GIỚI TRÁCH NHIỆM CỦA TV4 (RACI MATRIX)

### 1.1. TV4 là "Kiến trúc sư Trưởng" và "Cổng kiểm soát chất lượng" của cả nhóm:
* **Đối với TV1 (Auth & Quản trị Bàn/Menu):** TV4 cung cấp cấu trúc CSDL chuẩn MySQL 8.0, xây dựng lõi xác thực đa tầng (băm mật khẩu SHA256/bcrypt, kích hoạt email SMTP qua `PHPMailer`), phân quyền RBAC đa cấp (`deny riêng > allow riêng > nhóm > 403`), và thuật toán phân trang thật trên CSDL bằng `COUNT(*)`, `LIMIT`, `OFFSET`.
* **Đối với TV2 (Menu Khách QR & Giỏ hàng chung):** TV4 cung cấp `SharedCartService` đồng bộ giỏ hàng chung giữa nhiều điện thoại cùng bàn và API gợi ý món ăn kèm thông minh (`AprioriService`).
* **Đối với TV3 (Bếp KDS & Thu ngân POS):** TV4 kiểm duyệt Transaction thanh toán nguyên tử, khóa dòng chống thanh toán lặp, hỗ trợ in hóa đơn chuẩn `@media print` và tự động kích hoạt tiến trình ETL khi chốt bàn.
* **Đối với TV5 (Báo cáo BI & Doanh thu):** TV4 vận hành pipeline ETL làm sạch dữ liệu từ schema `oltp` nạp sang kho hình sao (`olap`) để TV5 truy vấn vẽ biểu đồ doanh thu và ma trận thực đơn.

### 1.2. Ranh giới phân định rõ ràng (TV4 KHÔNG làm thay phần của bạn khác):
* ❌ **TV1** tự code giao diện Quản lý Bàn, Menu và trang Đăng nhập.
* ❌ **TV2** tự code giao diện Mobile Khách chọn món, thao tác DOM giỏ hàng và gửi order.
* ❌ **TV3** tự code màn hình Bếp KDS chuyển trạng thái món, giao diện POS thu ngân và thanh toán/in hóa đơn.
* ❌ **TV5** tự code giao diện biểu đồ `Chart.js`, ma trận Menu Engineering (`/api/v1/bi/*`) trên `dashboard.php` / `demo_tester.html`.
* ✅ **TV4** nắm giữ hạ tầng Docker, kiến trúc CSDL, toàn bộ lõi bảo mật / transaction / RBAC / ETL / Apriori và **vận hành bộ kiểm thử tự động (`tests/run_tests.php` — 61 ca test và `tests/test_tv4_core.php`)**.

---

## 2. CHI TIẾT CÁC MODULE KỸ THUẬT DO TV4 TRỰC TIẾP XÂY DỰNG

### 🔹 Module 1: Hạ tầng Docker Compose 3 Dịch vụ ([`docker-compose.yml`](file:///D:/code/test%20Dl/docker-compose.yml), [`docker/php/Dockerfile`](file:///D:/code/test%20Dl/docker/php/Dockerfile))
* Đóng gói toàn bộ hệ thống chuẩn hóa theo đúng yêu cầu đề bài:
  1. `php-app`: Chạy image `php:8.2-apache` đã biên dịch extension `pdo_mysql`, `pdo_sqlite`, `zip`, cài Composer (PHPMailer được giữ trong named volume `php_vendor:/var/www/html/vendor` cùng bind mount `composer.json`), bật `a2enmod rewrite` và trỏ `APACHE_DOCUMENT_ROOT=/var/www/html/public`, cổng ánh xạ `8080:80`.
  2. `mysql`: MySQL 8.0 (`utf8mb4_unicode_ci`), ánh xạ cổng `3307:3306`, tự động khởi tạo dữ liệu mẫu từ [`database/init.sql`](file:///D:/code/test%20Dl/database/init.sql).
  3. `phpmyadmin`: Image phpMyAdmin cổng `8081:80` giúp thầy cô kiểm tra CSDL trực quan.

### 🔹 Module 2: Kiến trúc CSDL Kép OLTP & Star Schema OLAP ([`database/init.sql`](file:///D:/code/test%20Dl/database/init.sql))
* Áp dụng kiến trúc CSDL quan hệ kết hợp Kho dữ liệu phân tích:
  * **Nhóm bảng Quản trị & Xác thực:** `users` (đúng chuẩn PDF trang 18–19 với 3 Index), `roles`, `permissions`, `user_roles`, `role_permissions`, `user_permissions`.
  * **Nhóm bảng Nhà hàng OLTP:** `dining_tables` (15 bàn QR), `table_sessions`, `categories` (4 nhóm thực đơn), `units`, `products` & `menu_items` (35 món ăn nhà hàng `DISH-001`..`DISH-035`), `toppings`, `combo_rules`, `shared_carts`, `orders`, `order_items`, `bill_payments`.
  * **Nhóm bảng Phân tích OLAP (Star Schema):** `dim_time`, `dim_menu_item`, `dim_chef`, `fact_order_items`, `fact_clickstream` (phục vụ ETL, Apriori và 3 endpoint BI `/api/v1/bi/dashboard-summary`, `/api/v1/bi/revenue-over-time`, `/api/v1/bi/menu-engineering-matrix`).

### 🔹 Module 3: Hệ thống Xác thực Đa tầng & Gửi Email SMTP ([`app/src/auth.php`](file:///D:/code/test%20Dl/app/src/auth.php), [`app/src/mailer.php`](file:///D:/code/test%20Dl/app/src/mailer.php))
* Triển khai toàn bộ 47 mục theo giáo trình PDF:
  * Validate form nghiêm ngặt cả phía client (`validate.js`) và server (`auth.php`).
  * Mã hóa mật khẩu an toàn kết hợp SHA256 và `password_hash()` bcrypt.
  * Tích hợp thư viện `PHPMailer: ^6.9` gửi email xác thực kích hoạt tài khoản qua giao thức SMTP cổng 587.
  * Xử lý xác nhận email một lần qua token (`verify_email.php`), tự động hủy token sau khi kích hoạt thành công.
  * Cơ chế ghi nhớ đăng nhập `Remember Me` qua cookie an toàn thời hạn 30 ngày.

### 🔹 Module 4: Động cơ Phân quyền RBAC Đa cấp ([`app/src/rbac.php`](file:///D:/code/test%20Dl/app/src/rbac.php))
* Hiện thực hóa giải thuật kiểm tra quyền phân cấp:
  $$\text{Quyền Hiệu Lực} = \begin{cases} \text{TỪ CHỐI (403)} & \text{nếu có } user\_permissions.\text{effect} = \text{'deny'} \\ \text{CHO PHÉP} & \text{nếu có } user\_permissions.\text{effect} = \text{'allow'} \\ \text{CHO PHÉP} & \text{nếu nhóm } (role\_permissions) \text{ cấp quyền} \\ \text{TỪ CHỐI (403)} & \text{mặc định} \end{cases}$$
* Không dùng hardcode tên `admin`, tất cả tài khoản đều được quản lý động qua dữ liệu phân quyền. Kiểm tra nghiêm ngặt cả phương thức GET và POST.

### 🔹 Module 5: Bảo mật Đơn giá Snapshot & Chống Giả mạo (`ClientOrderController.php`)
* Khi khách gửi đơn từ điện thoại QR:
  * Server bỏ qua hoàn toàn đơn giá từ phía Client gửi lên, trực tiếp truy vấn đơn giá gốc từ `menu_items` và `toppings` trên CSDL.
  * Chụp đơn giá **snapshot** (`unit_price`, `subtotal`) vào `order_items` tại thời điểm đặt món; việc điều chỉnh giá thực đơn sau này không làm sai lệch hóa đơn lịch sử.

### 🔹 Module 6: Giao dịch Nguyên tử, Idempotency-Key & Đồng bộ Giỏ hàng Bàn (`SharedCartService.php`, `KdsPosController.php`)
* Hỗ trợ nhiều khách trên cùng bàn quét QR và thêm món vào giỏ hàng chung thời gian thực.
* Kiểm soát `Idempotency-Key` chống tạo đơn trùng lặp (HTTP `409 Conflict`) khi mạng di động chập chờn.
* Thao tác chốt đơn và thanh toán POS (`bill_payments`) được bọc trong `PDO Transaction` nguyên tử, tự động giải phóng bàn (`AVAILABLE`) và làm sạch giỏ hàng bàn sau khi hoàn tất.

### 🔹 Module 7: Pipeline Dữ liệu Tự động (ETL) & Gợi ý Món Apriori ([`app/Services/`](file:///D:/code/test%20Dl/app/Services/))
* `EtlService.php`: Tự động kích hoạt khi bàn chốt thanh toán. Làm sạch và chuyển đổi hóa đơn sang kho OLAP. Cờ `is_etl_processed` đảm bảo tính chất **Idempotent** (chạy lại nhiều lần không bao giờ nhân đôi doanh thu).
* `AprioriService.php`: Khai phá tập mục thường xuyên (Frequent Itemsets), tính toán Support, Confidence và Lift Ratio từ dữ liệu lịch sử gọi món để đưa ra gợi ý Top 3 món bán kèm (Combo Upsell).
* `SharedCartService.php`: Đồng bộ giỏ hàng nhiều thiết bị trên cùng 1 bàn ăn.

---

## 3. ĐÁP ỨNG TOÀN DIỆN BỘ TIÊU CHÍ CHẤM ĐIỂM (MỤC 38–40, BÀI A–D & 7 TRỤ CỘT CHẤT LƯỢNG TV4)

TV4 đã thiết kế và thực thi bộ kiểm thử tự động toàn diện tại [`tests/run_tests.php`](file:///D:/code/test%20Dl/tests/run_tests.php) bao phủ **64 test cases** kiểm chứng 100% các yêu cầu trong tài liệu và các bất biến nghiệp vụ cốt lõi:

```
=======================================================================
🧪 DINEFLOW AUTOMATED QUALITY GATE & COMPREHENSIVE TEST SUITE
   Chất lượng tích hợp TV4 (Auth, RBAC, Kho, Star Schema, Apriori & SLA)
=======================================================================

📋 PHẦN 1: KIỂM THỬ ĐĂNG KÝ TÀI KHOẢN (7 TEST CASES - MỤC 38)
  [PASS] TC38-1: Bỏ trống username -> Báo lỗi
  [PASS] TC38-2: Username dưới 3 ký tự ('ab') -> Báo lỗi
  [PASS] TC38-3: Email sai định dạng ('abc.com') -> Báo lỗi
  [PASS] TC38-4: Password dưới 6 ký tự ('123') -> Báo lỗi
  [PASS] TC38-5: Confirm password khác password -> Báo lỗi
  [PASS] TC38-7: Đăng ký hợp lệ -> Lưu database & gửi token
  [PASS] TC38-6: Email đã tồn tại -> Báo lỗi

📋 PHẦN 2: KIỂM THỬ XÁC NHẬN EMAIL (4 TEST CASES - MỤC 39)
  [PASS] TC39-1: Token rỗng -> Báo lỗi
  [PASS] TC39-2: Token sai / không tồn tại -> Báo lỗi
  [PASS] TC39-3: Token đúng -> Cập nhật email_verified = 1
  [PASS] TC39-4: Dùng lại token đã xác nhận -> Báo lỗi

📋 PHẦN 3: KIỂM THỬ ĐĂNG NHẬP (7 TEST CASES - MỤC 40)
  [PASS] TC40-1: Bỏ trống username/email -> Báo lỗi
  [PASS] TC40-2: Bỏ trống password -> Báo lỗi
  [PASS] TC40-3: Tài khoản không tồn tại -> Báo lỗi
  [PASS] TC40-4: Sai mật khẩu -> Báo lỗi
  [PASS] TC40-5: Chưa xác nhận email (email_verified = 0) -> Chặn login
  [PASS] TC40-6: Đăng nhập hợp lệ -> Thành công, lưu session
  [PASS] TC40-7: Remember password checkbox -> Đặt remember_token

📋 PHẦN 4: MA TRẬN PHÂN QUYỀN RBAC (BÀI B - ĐẶC TẢ MỞ RỘNG)
  [PASS] Bài B.1: Không nhóm, không ngoại lệ -> Từ chối (403)
  [PASS] Bài B.2: Nhóm cấp quyền, tài khoản kế thừa -> Cho phép
  [PASS] Bài B.3: Hai nhóm, chỉ một nhóm cấp quyền -> Cho phép
  [PASS] Bài B.4: Nhóm cấp quyền, nhưng tài khoản bị 'deny' riêng -> TỪ CHỐI
  [PASS] Bài B.5: Không nhóm cấp quyền, tài khoản có 'allow' riêng -> Cho phép
  [PASS] Bài B.6: Xóa ngoại lệ 'allow' -> Trở lại từ chối

📋 PHẦN 5: THỰC ĐƠN NHÀ HÀNG & PHÂN TRANG THẬT TRÊN DB (QR DINE)
  [PASS] QR-5.1: Đủ 35 món ăn chuẩn nhà hàng QR Dine (DISH-001 .. DISH-035)
  [PASS] QR-5.2: Đủ 4 nhóm thực đơn nhà hàng (Món Chính, Món Ăn Kèm, Đồ Uống, Combo)
  [PASS] QR-5.3: Phân trang thật trên DB (LIMIT 10 OFFSET 0) trả về đúng 10 món trang 1
  [PASS] QR-5.4: Lọc kết hợp Từ khóa ('Lẩu') + Danh mục ('Món Chính') chính xác

📋 PHẦN 6: VÒNG ĐỜI PHIÊN BÀN QR, ĐƠN BẾP KDS & HÓA ĐƠN POS
  [PASS] QR-6.1: Khởi tạo đủ 15 bàn ăn nhà hàng QR Dine (Bàn 01 .. Bàn 15)
  [PASS] QR-6.2: Có phiên bàn QR đang hoạt động (ACTIVE) kèm session_token hợp lệ
  [PASS] QR-6.3: Có đơn hàng KDS hợp lệ trong hệ thống
  [PASS] QR-6.4: Có hóa đơn thanh toán POS trong bảng bill_payments
  [PASS] QR-6.5: Đối soát công thức hóa đơn POS: final_amount == subtotal + VAT - discount

=======================================================================
🛡️ PHẦN 7: KIỂM THỬ 7 TRỤ CỘT CHẤT LƯỢNG & BẤT BIẾN NGHIỆP VỤ TV4
=======================================================================

🔹 TRỤ CỘT 1: ANTI-TAMPER PRICE CHECK (CHỐNG THAO TÚNG ĐƠN GIÁ PHÍA CLIENT)
  [PASS] QP-1.1: SharedCartService bỏ qua giá client (1đ) -> Ép dùng giá Server (65,000đ)
  [PASS] QP-1.2: Tổng tiền giỏ hàng tính đúng theo giá chuẩn server (130,000đ)
  [PASS] QP-1.3: Số lượng đặt âm/0 được tự động bảo vệ (chuẩn hóa về >= 1)
  [PASS] QP-1.4: Order submitted với giá client 500đ -> Server chốt hóa đơn đúng giá 130,000đ

🔹 TRỤ CỘT 2: IDEMPOTENCY-KEY REJECTION (CHỐNG GỬI ĐƠN HÀNG LẶP DO LAG MẠNG)
  [PASS] QP-2.1: Gửi đơn lần 1 với Idempotency-Key -> Tiếp nhận thành công (status = success)
  [PASS] QP-2.2: Gửi lại cùng Idempotency-Key -> BỊ TỪ CHỐI DUPLICATE (HTTP 409 Conflict)
  [PASS] QP-2.3: Số lượng order được tạo trong Database cho khóa này đúng duy nhất 1

🔹 TRỤ CỘT 3: IDEMPOTENT ETL PIPELINE (CHẠY LẠI ETL KHÔNG BAO GIỜ NHÂN ĐÔI DOANH THU)
  [PASS] QP-3.1: Chạy ETL lần 1 thành công cho phiên bàn
  [PASS] QP-3.2: Số bản ghi fact nạp đúng = 2 và tổng doanh thu = 350,000đ
  [PASS] QP-3.3: [BẤT BIẾN] Chạy ETL lần 2 vẫn thành công an toàn
  [PASS] QP-3.4: [BẤT BIẾN] Doanh thu OLAP không đổi (350000đ) & số fact không đổi (Chống nhân đôi)

🔹 TRỤ CỘT 4: APRIORI ALGORITHM & COMBO RULES (SUPPORT, CONFIDENCE, LIFT)
  [PASS] QP-4.1: Thuật toán Apriori trả về danh sách gợi ý mua kèm
  [PASS] QP-4.2: Gợi ý số 1 ưu tiên đúng món chỉ định (target = 102)
  [PASS] QP-4.3: Nguồn gốc gợi ý được đánh dấu RULE_OVERRIDE
  [PASS] QP-4.4: Độ tin cậy (Confidence) đạt chuẩn >= 0.90 (90%)
  [PASS] QP-4.5: Khai phá Association Rules toàn hệ thống thành công

🔹 TRỤ CỘT 5: KITCHEN SLA CALCULATION (>900S VIOLATION FLAG)
  [PASS] QP-5.1: Món nấu 400s (<= 900s) -> is_sla_violated = 0 (Đạt chuẩn)
  [PASS] QP-5.2: Món nấu 1100s (> 900s) -> is_sla_violated = 1 (Vi phạm SLA)
  [PASS] QP-5.3: API /api/v1/data/sla-metrics chứa cấu hình ngưỡng chuẩn 900 giây
  [PASS] QP-5.4: API SLA tính toán chính xác tỷ lệ tuân thủ sla_compliance_rate

🔹 TRỤ CỘT 6: ĐỒNG BỘ GIỎ HÀNG BÀN ĂN & GIAO DỊCH CHỐT ĐƠN NGUYÊN TỬ
  [PASS] QP-6.1: Nhiều khách cùng bàn thêm món -> Giỏ hàng chung đồng bộ đúng 2 dòng món
  [PASS] QP-6.2: Giảm số lượng về 0 -> Xóa dòng món khỏi giỏ hàng chung
  [PASS] QP-6.3: [GIAO DỊCH NGUYÊN TỬ] Chốt đơn thành công (HTTP 201) và tự động làm rỗng giỏ bàn
  [PASS] QP-6.4: [BẤT BIẾN] Chốt đơn khi giỏ rỗng -> Bị từ chối (HTTP 400)

🔹 TRỤ CỘT 7: STAR SCHEMA OLAP & OLTP ORDER INTEGRITY (ĐỐI SOÁT TOÀN VẸN)
  [PASS] QP-7.1: [TOÀN VẸN ĐƠN HÀNG] Mọi đơn hàng: orders.total_amount == SUM(order_items.subtotal)
  [PASS] QP-7.2: [TOÀN VẸN OLAP] 100% bản ghi fact_order_items liên kết chuẩn xác với Dimension
  [PASS] QP-7.3: [TOÀN VẸN OLAP] 100% bản ghi fact_clickstream liên kết chuẩn với dim_time
  [PASS] QP-7.4: [ĐỐI SOÁT TÀI CHÍNH] Doanh thu OLAP fact_order_items == Tổng tiền phiên bàn OLTP

=======================================================================
📊 KẾT QUẢ KIỂM THỬ HỆ THỐNG DINEFLOW (TV4 MASTER QUALITY GATE):
   Tổng số ca kiểm thử : 61
   Số ca đạt (PASS)    : 61
   Số ca lỗi (FAIL)    : 0
   Tỷ lệ hoàn thành    : 100%
=======================================================================
🎉 CHÚC MỪNG: TẤT CẢ 100% CÁC TEST CASES ĐÃ ĐẠT CHUẨN (0 FAILURES)!
   Hệ thống sẵn sàng bàn giao & bảo vệ đồ án trước Hội đồng chấm thi.
=======================================================================
```

---

## 4. KỊCH BẢN DEMO BẢO VỆ ĐỒ ÁN TRƯỚC HỘI ĐỒNG DÀNH RIÊNG CHO TV4

Khi đến phần báo cáo cá nhân của TV4, bạn tự tin trình diễn theo đúng 4 bước mạch lạc sau:

1. **Bước 1 — Trình diễn Kiến trúc Hạ tầng Docker & CSDL Kép OLTP/OLAP:**
   * Mở Terminal chạy lệnh `docker compose ps`: Chỉ cho thầy cô thấy 3 container `php-app`, `mysql`, `phpmyadmin` đang kết nối ổn định qua bridge network.
   * Mở phpMyAdmin (`http://localhost:8081`): Chứng minh cấu trúc bảng phân quyền đa cấp RBAC, nhóm bảng nhà hàng OLTP và kho dữ liệu hình sao Star Schema OLAP.
2. **Bước 2 — Trình diễn Phân quyền RBAC & Cơ chế Bảo mật:**
   * Đăng nhập với tài khoản `viewer` (chỉ có quyền xem): Thử thực hiện thao tác quản trị hoặc chốt hóa đơn $\rightarrow$ Hệ thống lập tức trả về lỗi **`403 Forbidden`**.
   * Chỉ cho thầy cô thấy nguyên tắc ưu tiên: Tài khoản dù thuộc nhóm quản trị nhưng nếu có bản ghi `deny` riêng thì hành động đó vẫn bị chặn triệt để.
3. **Bước 3 — Trình diễn Data Pipeline (ETL), Gợi ý Apriori & In Hóa đơn:**
   * Mở `/frontend/live-ops/`: Kích hoạt tiến trình Batch ETL đổ dữ liệu sang Star Schema, xem gợi ý món ăn kèm Apriori và giám sát SLA bếp (>900s).
   * Mở `/documents.php` và bấm In hóa đơn: Giao diện tự động áp dụng `@media print` ẩn hoàn toàn các thanh công cụ, tạo bản in hóa đơn thanh toán nhà hàng sạch đẹp.
4. **Bước 4 — Chạy Bộ Kiểm thử Tự động Trước Mặt Thầy Cô:**
   * Mở Terminal chạy lệnh chuẩn Docker:
     ```bash
     docker compose exec php-app php /var/www/tests/run_tests.php
     docker compose exec php-app php /var/www/tests/test_tv4_core.php
     ```
   * Toàn bộ **61 ca kiểm thử** trong `run_tests.php` cùng kiểm thử E2E/Apriori/ETL trong `test_tv4_core.php` chạy tự động và kiểm chứng toàn bộ bất biến hệ thống.
