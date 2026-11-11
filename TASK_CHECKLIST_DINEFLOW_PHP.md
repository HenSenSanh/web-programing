# 📋 BẢNG THEO DÕI CÔNG VIỆC TỔNG HỢP (MASTER TASK TRACKER) — DỰ ÁN DINEFLOW (PHP)

> **Kiến trúc chuẩn hóa:** PHP Framework (Laravel Full-stack Modular Monolith) + HTML5 + CSS3 + FORM + JavaScript + HTML DOM + PostgreSQL/MySQL + Redis  
> **Người điều phối & Kiểm soát chất lượng (Lead):** Thành viên 4 (TV4)  
> **Bộ 4 tài liệu chính thức của dự án:**
> 1. [`README.md`](file:///D:/code/test%20Dl/README.md) — Hướng dẫn cài đặt, chạy Server PHP & Phân công file cho cả 5 thành viên.
> 2. [`DINEFLOW_WEB_TEAM_SPEC_V1.md`](file:///D:/code/test%20Dl/DINEFLOW_WEB_TEAM_SPEC_V1.md) — Đặc tả kỹ thuật, luồng nghiệp vụ, phân quyền RBAC & 24 ca nghiệm thu cho cả nhóm.
> 3. [`tv4.md`](file:///D:/code/test%20Dl/tv4.md) — Hồ sơ kỹ thuật chuyên sâu, 7 ranh giới tích hợp, 6 bất biến, 10 lỗi backlog & kịch bản demo dành riêng cho TV4.
> 4. [`TASK_CHECKLIST_DINEFLOW_PHP.md`](file:///D:/code/test%20Dl/TASK_CHECKLIST_DINEFLOW_PHP.md) — Bảng theo dõi và đánh dấu (`[x]`) tiến độ công việc toàn dự án.

---

## 🟢 GIAI ĐOẠN 0: RÀ SOÁT, THU GỌN & CHUẨN HÓA TÀI LIỆU SANG PHP (HOÀN TẤT 100%)

- [x] **TASK-0.1:** Đọc và phân tích toàn bộ tài liệu dự án, rút gọn các file nháp/trùng lặp thành **4 tệp chuẩn duy nhất**.
- [x] **TASK-0.2:** Chốt kiến trúc chính thức: Sử dụng **PHP Framework (Laravel Full-stack)** kết hợp HTML5, CSS3, FORM, JS & HTML DOM.
- [x] **TASK-0.3:** Loại bỏ 100% các tham chiếu công nghệ cũ (FastAPI/Python) trên toàn bộ tài liệu.
- [x] **TASK-0.4:** Chốt 11 câu hỏi kỹ thuật & thông số vận hành (SLO: 15–20 bàn, 30 khách đồng thời, $p85 \le 1\text{s}$, VAT 8%, phí dịch vụ 0đ, làm tròn mốc 500đ) tại [`DINEFLOW_WEB_TEAM_SPEC_V1.md`](file:///D:/code/test%20Dl/DINEFLOW_WEB_TEAM_SPEC_V1.md).

---

## 🔨 GIAI ĐOẠN 1 (SPRINT S1): KHỞI TẠO NỀN TẢNG PHP LARAVEL & CƠ SỞ DỮ LIỆU KÉP
> **Chủ trì:** **TV4** | **Hỗ trợ:** TV1, TV5

- [ ] **TASK-1.1 [TV4]:** Khởi tạo project PHP Laravel (`composer create-project laravel/laravel`), cấu hình `.env` kết nối PostgreSQL/MySQL và Redis.
- [ ] **TASK-1.2 [TV4]:** Viết Laravel Migrations cho **Schema `oltp`** (8 bảng cốt lõi + 3 bảng mở rộng):
  - [ ] `users` (bổ sung `email`, `phone_number`, `avatar_url`, `role`, `last_login_at`)
  - [ ] `dining_tables` (15–20 bàn, trạng thái `AVAILABLE`, `OCCUPIED`, `CHECKOUT`)
  - [ ] `table_sessions` (tách bạch `session_token` của QR và vòng đời phiên bàn)
  - [ ] `categories`, `menu_items` (dùng kiểu `decimal(12,2)` cho giá tiền — sửa `DEF-10`), `toppings`
  - [ ] `orders`, `order_items` (lưu snapshot giá, số lượng, topping tại thời điểm đặt)
  - [ ] `audit_logs`, `uploaded_files`, `restaurant_settings`
- [ ] **TASK-1.3 [TV4]:** Viết Laravel Migrations cho **Schema `olap`** (Star Schema 5 bảng):
  - [ ] 3 bảng chiều: `dim_time`, `dim_menu_item`, `dim_chef`
  - [ ] 2 bảng sự kiện: `fact_order_items`, `fact_clickstream`
- [ ] **TASK-1.4 [TV4]:** Viết `DatabaseSeeder.php` tạo dữ liệu mẫu:
  - [ ] 3 tài khoản nhân viên (`ADMIN`, `CASHIER`, `CHEF`) băm mật khẩu chuẩn bằng `Hash::make()` (sửa lỗi mật khẩu giả).
  - [ ] 15–20 bàn ăn và danh mục món ăn, topping mẫu.
- [ ] **TASK-1.5 [TV4]:** Xây dựng Eloquent Models (`app/Models/Oltp/*.php` & `app/Models/Olap/*.php`) kèm đầy đủ quan hệ khóa ngoại.

---

## 🔐 GIAI ĐOẠN 2 (SPRINT S1–S2): XÁC THỰC, PHÂN QUYỀN & CỔNG QUẢN TRỊ MỘT CỬA (`/portal`)
> **Chủ trì:** **TV1** | **Kiểm duyệt tích hợp (Ranh giới B1, B7):** **TV4**

- [ ] **TASK-2.1 [TV1]:** Xây dựng `AuthController.php` và trang `/login` (`resources/views/auth/login.blade.php` sử dụng HTML Form & CSRF Token `@csrf`).
- [ ] **TASK-2.2 [TV1]:** Xây dựng Middleware `app/Http/Middleware/CheckRole.php` (`role:admin,cashier,chef`) kiểm soát quyền truy cập ở tầng Server (Sửa lỗi Blocker **`DEF-02`** / Đảm bảo **`INV-4`**).
- [ ] **TASK-2.3 [TV1 + Cả nhóm]:** Xây dựng Master Layout **Cổng Quản trị Một Cửa** (`resources/views/layouts/portal.blade.php`):
  - [ ] Thanh Sidebar điều hướng chung hiển thị theo quyền (`@can` / role check).
  - [ ] Header hiển thị thông tin ca trực nhân viên và nút Đăng xuất (`POST /logout`).
- [ ] **TASK-2.4 [TV1]:** Xây dựng `AdminCatalogController.php` & giao diện Quản lý Bàn (`/portal/tables`):
  - [ ] Mở bàn & sinh mã QR với token ngẫu nhiên `Str::random(32)` có TTL 3 tiếng.
  - [ ] Chặn mở đè bàn khi bàn đang `OCCUPIED` và còn đơn chưa thanh toán (Sửa lỗi Critical **`DEF-04`** / Đảm bảo **`INV-6`**).
  - [ ] Hỗ trợ nghiệp vụ **Chuyển bàn** (gắn tag `TRANSFERRED`) và **Gộp bàn** (mã ghép `01-02`).
- [ ] **TASK-2.5 [TV1]:** Xây dựng chức năng Quản lý Thực đơn (`/portal/menu`) & Upload ảnh món ăn:
  - [ ] HTML Form thêm/sửa món, bật/tắt trạng thái Còn món / Tạm hết.
  - [ ] Upload ảnh qua `Storage::disk('public')`, kiểm tra MIME-type (`.jpg`, `.png`, `.webp`) và kích thước `< 5MB` (`SEC-11`).

---

## 📱 GIAI ĐOẠN 3 (SPRINT S2): MENU KHÁCH QR, GIỎ HÀNG CHUNG REDIS & GỬI ĐƠN AN TOÀN
> **Chủ trì:** **TV2** | **Phối hợp Realtime & Kiểm duyệt (Ranh giới B1, B2, B5):** **TV4**

- [ ] **TASK-3.1 [TV2]:** Xây dựng giao diện Mobile Khách quét QR (`/m/{session_token}` — `resources/views/client/menu.blade.php`, `public/js/menu.js`):
  - [ ] Xác thực `session_token` hợp lệ; chặn truy cập chéo bàn (Đảm bảo **`INV-5`**).
  - [ ] Sử dụng JavaScript & **HTML DOM** để lọc món, chọn topping, cập nhật giỏ hàng mượt mà không cần tải lại trang.
- [ ] **TASK-3.2 [TV4 + TV2]:** Tích hợp `SharedCartService.php` (Redis key `shared_cart:{token}`) và kênh WebSocket đồng bộ giỏ hàng chung giữa nhiều điện thoại cùng bàn:
  - [ ] Xác thực `session_token` khi WebSocket handshake (Sửa lỗi **`DEF-06`**).
- [ ] **TASK-3.3 [TV2]:** Xây dựng API Gửi đơn xuống bếp (`POST /api/v1/client/session/{token}/submit-order` trong `ClientOrderController.php`):
  - [ ] **BẮT BUỘC (`INV-1` / Sửa `DEF-01`):** Client chỉ gửi `menu_item_id`, `quantity`, `topping_ids`. Server tự mở `DB::transaction()`, tra giá gốc từ bảng `menu_items` và `toppings` để tính tổng tiền.
  - [ ] **BẮT BUỘC (`INV-2` / Sửa `DEF-05`):** Kiểm tra header `X-Idempotency-Key` trên Redis và khóa nút bấm trên DOM để chống bấm đúp nhân đôi món ăn.
  - [ ] Cho phép khách **đặt thêm món nhiều đợt** đẩy (push & merge) vào cùng `table_session` đang mở.
- [ ] **TASK-3.4 [TV2 + TV4]:** Tích hợp hiển thị thanh **"🔥 Món hay được gọi kèm"** trên popup chi tiết món từ API gợi ý của TV4 và gửi sự kiện Clickstream (`VIEW_ITEM`, `ADD_TO_CART`).

---

## 👨‍🍳 GIAI ĐOẠN 4 (SPRINT S3): ĐIỀU PHỐI BẾP KDS, THU NGÂN POS & THANH TOÁN VIETQR
> **Chủ trì:** **TV3** | **Kiểm duyệt Transaction & SLA (Ranh giới B2, B3, B4):** **TV4**

- [ ] **TASK-4.1 [TV3]:** Xây dựng màn hình Bếp KDS (`/portal/kds` — `resources/views/staff/kds.blade.php`, `KdsPosController.php`):
  - [ ] Nhận đơn mới real-time qua WebSocket và cập nhật lên HTML DOM.
  - [ ] Chuyển trạng thái từng món: `PENDING` $\rightarrow$ `COOKING` $\rightarrow$ `COMPLETED`, phát sự kiện ngược về màn hình khách.
  - [ ] Áp dụng quy tắc **Hủy món**: Chỉ cho phép hủy khi `PENDING` (chưa nấu); khi đã `COOKING` bắt buộc tính tiền hoặc cần quyền Override có ghi `audit_logs`.
- [ ] **TASK-4.2 [TV3]:** Xây dựng màn hình Thu ngân POS (`/portal/pos` — `resources/views/staff/pos.blade.php`):
  - [ ] Bảo vệ API xem hóa đơn `/api/v1/staff/pos/bill/{session_id}` bằng Middleware `role:cashier,admin` (Sửa lỗi **`DEF-07`**).
  - [ ] Tính tổng hóa đơn chuẩn: Cộng **VAT 8%**, **Phí phục vụ 0%**, áp dụng Discount và **làm tròn mốc 500đ** ($\ge 500\text{đ}$ làm tròn lên, $< 500\text{đ}$ làm tròn xuống).
  - [ ] Tích hợp sinh mã **VietQR động chuẩn Napas 247** hiển thị trên hóa đơn tạm tính và mẫu in nhiệt 80mm.
- [ ] **TASK-4.3 [TV3]:** Xây dựng API Chốt Thanh toán & Đóng bàn (`POST /api/v1/staff/pos/checkout/{session_id}`):
  - [ ] **BẮT BUỘC (`INV-3` / Sửa `DEF-03` & `DEF-08`):** Bọc trong `DB::transaction()`, sử dụng `lockForUpdate()`, kiểm tra nếu `payment_status === 'PAID'` thì trả về `409 Conflict` ngay lập tức.
  - [ ] Đổi trạng thái phiên sang `PAID/CLOSED`, giải phóng bàn về `AVAILABLE`, xóa giỏ hàng Redis và kích hoạt `dispatch(new RunEtlClosedSessionJob($sessionId))`.

---

## 📊 GIAI ĐOẠN 5 (SPRINT S4): REAL-TIME DATA PIPELINE (ETL), GỢI Ý APRIORI & BÁO CÁO BI
> **Chủ trì:** **TV4** (ETL, Apriori, SLA, Live Ops) & **TV5** (BI Dashboard, Menu Matrix, Export Excel)

- [ ] **TASK-5.1 [TV4]:** Hoàn thiện `RunEtlClosedSessionJob.php` và API `POST /api/v1/data/trigger-batch-etl`:
  - [ ] Chuyển đổi đơn hàng đã thanh toán sang Star Schema (`dim_time`, `dim_menu_item`, `dim_chef`, `fact_order_items`).
  - [ ] Đảm bảo tính **Idempotent** (`is_etl_processed = true`) — chạy lại nhiều lần không bao giờ nhân đôi doanh thu.
- [ ] **TASK-5.2 [TV4]:** Hoàn thiện `AprioriRecommendationService.php` và các API trong `DataPipelineController.php`:
  - [ ] Tính toán chỉ số **Support, Confidence, Lift** gợi ý top 3 món ăn kèm.
  - [ ] API cấu hình luật Combo Upsell thủ công (`POST /api/v1/data/combo-rule`).
  - [ ] API thống kê thời gian nấu và cảnh báo vi phạm SLA bếp (> 15 phút / 900 giây).
- [ ] **TASK-5.3 [TV4]:** Hoàn thiện giao diện Điều phối Thời gian thực (`/portal/live-ops` — `tv4_live_ops.blade.php`):
  - [ ] Auto-refresh 5s bằng JS `fetch()` cập nhật DOM: xem số thiết bị kết nối, giỏ hàng Redis, trạng thái bếp và nhật ký đồng bộ OLAP.
- [ ] **TASK-5.4 [TV5]:** Xây dựng `BiDashboardController.php` và giao diện Báo cáo BI (`/portal/reports`):
  - [ ] Biểu đồ doanh thu theo thời gian, khung giờ cao điểm và KPIs tỷ lệ chuyển đổi bằng `Chart.js`.
  - [ ] Biểu đồ ma trận thực đơn **Menu Engineering Matrix** (Stars, Plowhorses, Puzzles, Dogs).
  - [ ] Tính năng xuất báo cáo doanh thu ra tệp **Excel (`.xlsx`)** phục vụ kế toán.

---

## 🧪 GIAI ĐOẠN 6 (SPRINT S5): KIỂM THỬ TÍCH HỢP, SỬA LỖI & NGHIỆM THU BẢO VỆ ĐỒ ÁN
> **Chủ trì:** **TV4 (Quality & Integration Lead)** | **Tham gia:** Toàn bộ 5 thành viên

- [ ] **TASK-6.1 [TV4]:** Xây dựng bộ kiểm thử tự động **PHPUnit** (`tests/Feature/InvariantsTest.php`) kiểm tra 6 Bất biến Nghiệp vụ:
  - [ ] `TC-INV-1.1 & 1.2`: Chặn client sửa giá `unit_price: 1.0` hoặc số âm.
  - [ ] `TC-INV-2.1`: Chặn gửi đơn trùng lặp trong 200ms với cùng `Idempotency-Key`.
  - [ ] `TC-INV-3.1 & 3.2`: Chặn thanh toán lặp 2 lần (`409 Conflict`).
  - [ ] `TC-INV-4.1 & 4.2`: Chặn khách vãng lai gọi API Admin (`401`) và chặn `CHEF` gọi API thanh toán POS (`403`).
  - [ ] `TC-INV-5.1 & 5.2`: Chặn dùng token Bàn 1 gửi đơn hoặc nghe WebSocket của Bàn 2.
  - [ ] `TC-INV-6.1`: Đảm bảo bàn có đơn chưa thanh toán không bị tự động xóa hay mở đè.
- [ ] **TASK-6.2 [TV4 + TV5]:** Hoàn thiện kịch bản E2E `tests/Feature/EndToEndOrderFlowTest.php` với các lệnh khẳng định `$response->assertStatus(...)` nghiêm ngặt (Sửa lỗi **`DEF-09`**).
- [ ] **TASK-6.3 [TV4]:** Duyệt nghiệm thu 24 kịch bản Acceptance Tests (`AT-01` $\rightarrow$ `AT-24` trong [`DINEFLOW_WEB_TEAM_SPEC_V1.md`](file:///D:/code/test%20Dl/DINEFLOW_WEB_TEAM_SPEC_V1.md)) và chạy cổng kiểm thử `php artisan test` đạt **100% PASS**.
- [ ] **TASK-6.4 [Cả nhóm]:** Chuẩn bị kịch bản Demo bảo vệ đồ án 6 bước từ mở bàn đến báo cáo BI và bảng phân vai trả lời phản biện trước Hội đồng.
