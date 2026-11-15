# README_P2 — Cải tiến luồng đặt món và định hướng frontend


## 1. Các cải tiến đã thực hiện

### 1.1 Kiểm tra món và topping khi gửi đơn

**File:** `app/Controllers/ClientOrderController.php`

- Backend lấy giá món và topping từ database, không dùng giá do trình duyệt gửi để tính đơn.
- Từ chối toàn bộ đơn nếu món không tồn tại hoặc đã tắt bán.
- Từ chối nếu topping không tồn tại, đã tắt bán, ID không hợp lệ hoặc bị lặp.
- Kiểm tra và ghi đơn trong transaction; khi có lỗi thì rollback để không lưu một phần đơn.
- Trả mã lỗi cùng ngữ cảnh món/topping để frontend có thể thông báo phù hợp.

Các kiểm tra này dựa trên cờ `is_available`. Hệ thống hiện chưa tự trừ tồn kho món ăn theo số lượng nguyên liệu.

### 1.2 Chống gửi trùng bằng Idempotency-Key

**Files:**

- `app/Controllers/ClientOrderController.php`
- `frontend/js/menu.js`
- `database/schema.sql`
- `database/init.sql`
- `database/migrations/20261115_add_orders_idempotency_unique.sql`
- `tests/test_order_idempotency.php`

Đơn mới phải gửi `Idempotency-Key`. Khi cùng key được gửi lại trong cùng phiên bàn, backend trả thông tin đơn đã tạo thay vì tạo đơn thứ hai hoặc cộng lại tiền phiên. Frontend giữ key đang chờ trong `sessionStorage`, gửi kèm request và xóa key sau khi gửi thành công hoặc khi giỏ hàng thay đổi.

Schema khởi tạo mới đặt ràng buộc duy nhất cho `orders.idempotency_key`; cột vẫn cho phép `NULL` để giữ tương thích với đơn cũ. **Database đã tồn tại cần chạy migration SQL một lần.** Nếu migration báo trùng key, phải xử lý các key trùng trước.

Đã tạo test riêng bao gồm gửi cùng key, tổng tiền phiên, key thiếu/quá dài và key mới. File test **chưa được chạy**.

### 1.3 Lịch sử và trạng thái đơn

**File:** `frontend/js/menu.js`

- Frontend gọi trực tiếp endpoint PHP hiện có `/client/session/{token}/status`.
- Làm mới trạng thái mỗi 5 giây.
- Bỏ request thử `/orders` và kết nối WebSocket vì PHP hiện không khai báo các endpoint đó.

### 1.4 Token QR phải thuộc phiên bàn hiện tại

**Files:**

- `app/Controllers/ClientOrderController.php`
- `app/Controllers/ClientCancellationController.php`

Gửi đơn và hủy món chỉ được chấp nhận khi token thuộc phiên `ACTIVE` và `dining_tables.current_session_id` đang trỏ đúng tới phiên đó. Token cũ mất hiệu lực khi phiên đóng hoặc bàn được chuyển sang phiên mới.

Hiện không đặt hạn dùng theo số giờ cố định; chính trạng thái phiên và phiên hiện tại của bàn quyết định token còn hiệu lực hay không.

### 1.5 Hủy món

Route hủy món và controller đã có trong dự án. Luồng chỉ cho hủy món `PENDING` trong đúng phiên; trả `409` nếu trạng thái món đã đổi. KDS cập nhật trạng thái có điều kiện và POS/checkout bỏ món `CANCELLED` khỏi tổng tiền.

## 2. Các phần đang hoạt động nhưng chưa thay đổi

- API `/admin/menu-catalog` trả danh sách món, giá, trạng thái, topping và `image_url`.
- Menu khách khóa món hết bán và không hiển thị topping đã tắt.
- Một phiên bàn còn hoạt động có thể nhận nhiều đơn riêng, mỗi request mới dùng key mới.
- Ảnh món được tải từ đường dẫn `image_url`; frontend có biểu tượng dự phòng khi file không tải được. Dự án chưa có API upload ảnh.
- Các file ảnh đang có trong `frontend/img` là `mi_cay.jpg` và `lau_thai.jpg`; cần bổ sung những file khác mà dữ liệu mẫu tham chiếu nếu muốn chúng hiển thị ảnh thật.

## 3. Rà soát giao diện quản trị hiện tại

### Quản lý bàn và QR

**File:** `frontend/tv1/table-management.html`

Luồng mở phiên bàn và tạo QR **đã có**: bàn trống có nút “Mở bàn & Tạo QR”; sau khi mở, giao diện hiển thị QR và link vào menu khách.

Các phần còn thiếu hoặc nên cải thiện:

- Chưa có form/nút tạo bàn mới trên giao diện, dù backend có `POST /admin/tables`.
- QR lấy ảnh từ dịch vụ bên ngoài `api.qrserver.com`; trang cần Internet và phụ thuộc bên thứ ba.
- Chưa có nút tải QR, in QR, sao chép link hoặc hiển thị QR lớn để in/dán tại bàn.
- Giao diện đang hiện token phiên dạng chữ; nên tránh phơi token dài trên màn hình quản trị chung.
- Backend `openSession()` nên tự từ chối mở phiên cho bàn đã có phiên hoạt động; không nên chỉ dựa vào việc giao diện ẩn nút.

### Quản lý menu

**File:** `frontend/tv1/menu-management.html`

Hiện trang chỉ tải danh sách món/topping, hiển thị thống kê và bật/tắt trạng thái còn bán. **Chưa có form thêm món, nút sửa món hoặc chức năng quản lý thông tin món** như tên, mô tả, danh mục, giá, ảnh và đánh dấu món nổi bật.

Backend hiện có endpoint tạo món `POST /admin/menu-items`, nhưng giao diện chưa gọi endpoint này. Backend chưa có route/controller cập nhật món hiện có. Topping cũng mới có chức năng bật/tắt, chưa có giao diện thêm/sửa tên và giá.

Vì vậy, nhận xét “frontend admin thiếu luồng tạo phiên/QR” chưa chính xác hoàn toàn: luồng cơ bản đã tồn tại. Nhận xét “quản lý menu không có nút sửa” là đúng; ngoài ra hiện cũng chưa có giao diện thêm món.


## chuẩn bị làm tiếp
## 4. Định hướng nâng cấp frontend tiếp theo

### Ưu tiên 1 — Bảo vệ thao tác quản trị

Rà soát đăng nhập và phân quyền trên các API `/admin/*`. Router API hiện gọi controller trực tiếp; cần bảo đảm người chưa đăng nhập hoặc không có quyền không thể tạo bàn, mở phiên hay thay đổi menu chỉ bằng cách gọi API.

### Ưu tiên 2 — Hoàn thiện quản lý menu

- Thêm form/modal tạo món và nút sửa trên từng món.
- Cho phép cập nhật tên, mô tả, danh mục, giá, trạng thái bán, `is_hot` và `image_url`.
- Bổ sung API cập nhật món (ví dụ `PUT /admin/menu-items/{id}`) và kiểm tra dữ liệu ở backend.
- Thêm quản lý tạo/sửa topping và giá topping.
- Nếu cần tải ảnh từ giao diện, thiết kế API upload cùng quy tắc lưu file và giới hạn loại/kích thước ảnh; nếu không, cho nhập URL ảnh là đủ cho phiên bản hiện tại.

### Ưu tiên 3 — Hoàn thiện quản lý bàn và QR

- Thêm form tạo bàn và xử lý số bàn trùng.
- Chặn mở phiên đang hoạt động ở backend, kể cả khi request gửi trực tiếp.
- Thêm sao chép link, tải ảnh QR, in QR theo mẫu có tên/số bàn.
- Nếu muốn bỏ phụ thuộc dịch vụ QR bên ngoài, tạo QR tại server hoặc dùng thư viện QR chạy tại frontend.

### Ưu tiên 4 — Dễ tìm và dễ thao tác

- Tìm kiếm món theo tên; lọc theo danh mục và trạng thái còn/hết.
- Hiển thị trạng thái đang tải, lỗi API và thông báo lưu thành công rõ ràng.
- Khóa nút trong lúc lưu để tránh bấm lặp; yêu cầu xác nhận với thao tác có ảnh hưởng lớn.
- Bổ sung kiểm tra dữ liệu đầu vào và thông báo lỗi ngay cạnh trường nhập.
- Rà soát bố cục trên màn hình điện thoại, bàn phím và khả năng truy cập.

### Ưu tiên 5 — Tồn kho tự động, nếu yêu cầu bài cần

Hiện `is_available` là trạng thái do admin bật/tắt thủ công. Muốn tự động chuyển món sang hết bán theo số lượng còn lại thì cần liên kết món với nguyên liệu/sản phẩm kho, định nghĩa định lượng và cập nhật tồn theo giao dịch bán. Đây là mở rộng backend và dữ liệu, không chỉ là chỉnh frontend.

## 5. Việc cần làm trước khi kết luận hoàn tất

- Chạy migration unique index trên database đang sử dụng.
- Chạy `tests/test_order_idempotency.php` và các test liên quan tới gửi đơn, hủy món sau khi có môi trường test.
- Bổ sung các file ảnh còn thiếu hoặc điều chỉnh `image_url` cho đúng file thực tế.
- Chọn phạm vi Phần tiếp theo; khuyến nghị làm bảo vệ API quản trị trước, sau đó hoàn thiện CRUD menu và QR.