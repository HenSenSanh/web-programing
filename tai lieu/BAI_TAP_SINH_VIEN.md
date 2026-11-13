# Bài thực hành phát triển tiếp hệ thống kho PHP

Đọc [đặc tả mở rộng](DE_XUAT_MO_RONG.md) và chạy khung mẫu trước khi sửa code. Mỗi nhóm phải giải thích được tồn kho được tạo bởi những phiếu nào, vì sao một người được phép ghi sổ và giá nào đã được dùng trên phiếu.

## 1. Mục tiêu đầu ra

Sinh viên cần thực hiện được:

1. Phân trang trên database, giữ bộ lọc và kiểm soát đầu vào.
2. Mô hình hóa quyền theo nhóm cùng ngoại lệ `allow/deny` trên tài khoản.
3. Tách dữ liệu mô tả sản phẩm khỏi giá theo thời gian và tồn theo kho/vị trí/lô.
4. Dùng transaction và khóa khi ghi sổ để tránh âm tồn hoặc ghi trùng.
5. In lại chứng từ từ dữ liệu lịch sử đã lưu.
6. Viết tiêu chí nghiệm thu và chứng minh quy tắc bằng dữ liệu thử.

## 2. Phần khung mẫu và phần phải phát triển

### 2.1. Luồng mẫu được cung cấp để đọc và chạy

| Mẫu | Nội dung cần đọc |
|---|---|
| Phân trang sản phẩm | `COUNT` và truy vấn dữ liệu cùng bộ lọc; `LIMIT/OFFSET`; liên kết giữ query string |
| Phân quyền | Bảng nhóm/quyền/ngoại lệ, hàm kiểm tra quyền phía server, giao diện gán quyền |
| Kho và danh mục | Khai báo đơn vị cơ sở, kho, vị trí khu/kệ/ô, lô sản phẩm |
| Giá lịch sử | Khoảng hiệu lực không giao nhau; loại giá nhập/xuất; không lấy từ sản phẩm |
| Phiếu | Tạo nháp nhiều dòng, ghi sổ nguyên tử, hủy nháp, xem và in phiếu đã ghi sổ |
| Dashboard | Số dư từ bảng tồn và cảnh báo thấp, không tính giá vốn từ giá bán |

Các màn hình mẫu là điểm xuất phát. Chức năng chỉ có trong mục bài tập dưới đây chưa được coi là đã có sẵn chỉ vì database hoặc giao diện có thể mở rộng được.

### 2.2. Giới hạn của khung mẫu

- Phân quyền theo chức năng toàn hệ thống; chưa giới hạn dữ liệu từng kho hoặc từng người lập.
- Chưa có quy tắc người lập khác người duyệt; một người có đủ quyền có thể lập và ghi sổ.
- Phiếu nháp không chỉnh sửa; hủy và lập lại. Phiếu đã ghi sổ không chỉnh/xóa trực tiếp.
- Lịch sử giá chỉ thêm mới; giá đang mở vô hạn chặn khoảng kế tiếp. Dùng khoảng hữu hạn khi tạo dữ liệu thử; chức năng đóng/mở lịch giá nguyên tử là bài phát triển tiếp.
- Đơn vị cơ sở và số lượng nguyên; chưa quy đổi thùng/hộp/cái hoặc nhập số lượng phân số.
- Chưa chuyển kho, trả hàng, đảo phiếu, kiểm kê, khóa kỳ, giữ hàng hoặc xuất tự chọn FEFO.
- Chưa tính giá vốn FIFO/bình quân, thuế, chiết khấu hoặc công nợ.
- Chưa có danh mục đối tác đầy đủ, nhật ký nghiệp vụ đầy đủ hoặc hệ thống thông báo.

## 3. Bài bắt buộc: hiểu và kiểm chứng khung mẫu

### Bài A — Phân trang thật

Chuẩn bị ít nhất 35 sản phẩm ở 3 danh mục. Thử trang 1, trang giữa, trang cuối, trang 0, trang rất lớn, từ khóa không có kết quả và kết hợp từ khóa + danh mục.

Nộp giải thích:

- Tổng bản ghi được đếm bằng truy vấn nào? Có cùng điều kiện với danh sách không?
- Vì sao đổi bộ lọc cần trở lại trang đầu?
- Vì sao phải có thứ tự ổn định thay vì chỉ `ORDER BY name` nếu nhiều sản phẩm trùng tên?
- Kích thước trang được giới hạn ở server như thế nào?

Phát triển thêm màn hình sổ biến động theo sản phẩm, kho và khoảng ngày, có phân trang. Không tải hết dữ liệu rồi dùng `array_slice()`; không thay đổi dữ liệu tồn khi xem báo cáo.

### Bài B — Phân quyền nhóm và tài khoản

Tạo cấu hình 4 người dùng/nhóm: Quản trị, Người lập phiếu, Thủ kho, Người xem. Có thể tạo tài khoản qua fixture/SQL phục vụ bài tập nếu chưa xây màn hình tạo tài khoản; mật khẩu phải theo cơ chế xác thực đang dùng.

| Tình huống | Kết quả bắt buộc |
|---|---|
| Không nhóm, không ngoại lệ | Từ chối |
| Nhóm cấp quyền, tài khoản kế thừa | Cho phép |
| Hai nhóm, chỉ một nhóm cấp quyền | Cho phép |
| Nhóm cấp quyền, tài khoản `deny` | Từ chối |
| Không nhóm cấp quyền, tài khoản `allow` | Cho phép |
| Xóa ngoại lệ `allow`, không nhóm cấp quyền | Từ chối |
| Bỏ nút bằng CSS nhưng tự gửi POST trái quyền | Server trả 403, không thay đổi dữ liệu |
| Thu hồi quyền trong khi người dùng còn đăng nhập | Request tiếp theo bị từ chối theo dữ liệu mới |

Nộp truy vấn chứng minh quyền hiệu lực và giải thích thứ tự `deny riêng > allow riêng > nhóm > từ chối mặc định`. Kiểm tra cả GET lẫn POST, đường dẫn xem và các đường dẫn ghi sổ/in.

### Bài C — Giá theo thời gian

Tạo sản phẩm mới và khai báo hai mức giá xuất hữu hạn tiếp giáp, một mức giá nhập cùng thời gian, một khoảng trống chưa có giá. Giá chuyển đổi của sản phẩm cũ là khoảng mở nên không thể chèn giá mới giao khoảng đó bằng màn hình mẫu.

| Tình huống | Kết quả bắt buộc |
|---|---|
| Tại đúng `valid_from` | Chọn mức giá bắt đầu ở thời điểm đó |
| Tại đúng `valid_to` | Không chọn mức giá đã kết thúc |
| Hai khoảng tiếp giáp | Được chấp nhận |
| Hai khoảng giao nhau cùng sản phẩm/loại | Bị từ chối |
| Hai loại giá khác nhau cùng thời gian | Được chấp nhận |
| `valid_to <= valid_from` | Bị từ chối |
| Không có giá tại ngày phiếu | Không lập được phiếu, không dùng giá 0 ngầm định |
| Có dòng giá bằng 0 được cấu hình rõ | Lập phiếu với giá 0 |
| Sửa HTML/request gửi đơn giá khác | Server vẫn dùng giá lịch sử hợp lệ |
| Tạo phiếu rồi thêm giá cho thời gian khác | Phiếu cũ và bản in giữ nguyên giá chụp |

Phải kiểm tra cả trường hợp hai request cùng thêm giá chồng khoảng. Nếu chỉ kiểm tra trước `INSERT` mà không khóa sản phẩm/khóa thích hợp trong transaction, hai request có thể cùng vượt qua.

### Bài D — Nhập, xuất, in và đối soát

Dữ liệu mẫu: hai kho, ít nhất hai vị trí/kho, hai sản phẩm, một sản phẩm có hai lô. Nhập 10 đơn vị, xuất 6 đơn vị từ cùng một bộ khóa tồn.

Tiêu chí:

- Lưu nháp nhập không thay đổi tồn; ghi sổ mới cộng 10.
- Ghi sổ xuất 6 còn 4; xuất 5 từ số dư 4 thất bại.
- Một phiếu có hai dòng cùng bộ khóa, mỗi dòng 3, không được xuất từ tồn 4.
- Phiếu có nhiều dòng, một dòng thiếu tồn: không dòng nào bị ghi sổ một phần.
- Ghi sổ lặp cùng phiếu không tạo biến động thứ hai.
- Hủy nháp không đổi tồn; phiếu đã hủy không ghi sổ lại được.
- Gửi request hủy/sửa phiếu đã ghi sổ bị từ chối.
- Lô của sản phẩm A không được dùng cho sản phẩm B.
- Phiếu xuất với lô đã hết hạn bị từ chối; lùi ngày phiếu không cho xuất hàng đã quá hạn ở thời điểm ghi sổ.
- Chỉ in phiếu đã ghi sổ; phiếu thiếu quyền in hoặc còn nháp không tạo bản in chính thức.
- Tổng tiền trên bản in bằng tổng số lượng × đơn giá chụp của từng dòng.

Đối soát từng bộ khóa:

```text
stock_balances.quantity
    = SUM(stock_movements.quantity)
    = tổng nhập đã ghi sổ - tổng xuất đã ghi sổ
```

Đối soát phải bao gồm số dư đầu kỳ đã chuyển đổi từ dữ liệu cũ. Không sửa trực tiếp bảng số dư để làm cho kết quả “đúng”.

## 4. Bài phát triển bắt buộc cho mỗi nhóm

Chọn ít nhất một bài dưới đây. Trước khi code, nộp quy tắc nghiệp vụ, thay đổi schema, màn hình dự kiến và ít nhất 5 ca nghiệm thu bao gồm một ca trái quyền và một ca cạnh tranh/lặp request nếu có tác động tồn.

### E1 — Giới hạn quyền theo kho

Thêm quan hệ tài khoản/nhóm với kho. Người dùng chỉ xem tồn và xử lý phiếu thuộc kho được cấp. Quyền chức năng và phạm vi kho đều phải thỏa mãn; direct `allow` quyền chức năng không tự mở mọi kho.

Nghiệm thu: thay `warehouse_id`/`location_id`/`document_id` trong URL hoặc POST không vượt phạm vi. Phiếu nhiều kho phải kiểm tra tất cả kho hoặc bị cấm theo quy tắc nhóm tự định nghĩa.

### E2 — Tách người lập và người duyệt

Thêm trạng thái `submitted` và quyền trình/duyệt. Quy định người lập không tự duyệt phiếu của mình; khi trả lại để sửa phải có lý do. Việc duyệt/ghi sổ phải nguyên tử và chống duyệt hai lần.

Nghiệm thu: người dùng có đủ cả hai quyền vẫn không tự duyệt; cập nhật đồng thời không bỏ qua điều kiện trạng thái. Ma trận chuyển trạng thái và các nút phải trùng nhau.

### E3 — Chuyển kho

Một nghiệp vụ chuyển tạo xuất kho nguồn và nhập kho đích trong cùng transaction. Giữ đúng sản phẩm, lô, số lượng; không coi giá bán là giá vốn chuyển kho.

Nghiệm thu: nguồn thiếu tồn thì đích không được tăng; lặp request không nhân đôi; kiểm tra quyền ở cả kho nguồn và đích. Nếu có vận chuyển trung gian, mô hình hóa kho/trạng thái “đang đi đường” trước khi code.

### E4 — Đảo phiếu / trả hàng

Tạo chứng từ mới tham chiếu phiếu gốc và ghi biến động ngược; không xóa lịch sử gốc. Phân biệt trả một phần và đảo toàn phần; số lượng trả tích lũy không vượt số lượng gốc.

Nghiệm thu: phiếu trả dùng đơn giá gốc phù hợp quy tắc, không lấy giá hiện hành; hai request trả đồng thời không vượt phần còn được trả; thao tác đảo nhập có thể bị chặn nếu hàng đã xuất.

### E5 — Quy đổi đơn vị

Thêm đơn vị mua/bán, đơn vị cơ sở, hệ số quy đổi có thời điểm hiệu lực hoặc chụp hệ số vào dòng phiếu. Ví dụ 1 thùng = 12 hộp. Định nghĩa số chữ số thập phân và làm tròn.

Nghiệm thu: nhập 2 thùng cộng 24 hộp cơ sở; thay hệ số sau này không đổi phiếu cũ; báo cáo hiển thị đơn vị rõ; không dùng số thực nhị phân để cộng trừ số lượng chính xác.

### E6 — Báo cáo giá vốn và khóa kỳ

Chọn một phương pháp rõ ràng: bình quân hoặc FIFO. Định nghĩa số dư đầu, chi phí nhập, xuất trả, lùi ngày và thời điểm khóa kỳ. Bổ sung giá vốn vào dòng xuất độc lập với giá bán.

Nghiệm thu bằng bộ dữ liệu nhỏ có kết quả tính tay; chứng minh tổng giá vốn xuất + giá trị cuối bằng giá trị đầu + giá trị nhập có điều chỉnh hợp lệ. Không dùng `tồn × giá bán hiện tại`.

### E7 — Quản lý tài khoản, nhật ký và danh mục an toàn

Xây CRUD tài khoản, khóa/mở tài khoản, đổi mật khẩu, tạo/sửa nhóm, nhật ký ai thay quyền gì lúc nào. Bổ sung ngừng sử dụng kho/vị trí/lô/đơn vị thay vì xóa khi đã có chứng từ.

Nghiệm thu: không làm mất người quản trị quyền cuối cùng; tài khoản bị khóa không tiếp tục xử lý request bằng session cũ; sửa danh mục không làm đổi thông tin nghiệp vụ trên bản in lịch sử nếu yêu cầu lưu nguyên trạng.

### Lựa chọn thay thế — Đóng/mở lịch giá nguyên tử

Thêm thao tác lập mức giá kế tiếp từ một khoảng đang mở: khóa sản phẩm, xác định khoảng hiện tại, đóng khoảng tại thời điểm T rồi thêm khoảng mới bắt đầu đúng T trong cùng transaction. Quy định rõ quyền, thời điểm được sửa và cách xử lý phiếu nháp đã có giá chụp.

Nghiệm thu: không có thời điểm nhận hai giá cùng loại, không mất khoảng giá nếu bước thêm mới thất bại, hai request đồng thời không tạo hai mức kế tiếp, phiếu cũ không thay đổi đơn giá đã chụp.

## 5. Thử cạnh tranh và tính toàn vẹn

Không kiểm thử cạnh tranh chỉ bằng hai lần bấm tuần tự.

1. Chuẩn bị số dư đúng 4 ở cùng sản phẩm/vị trí/lô.
2. Tạo hai phiếu nháp xuất 4 bằng hai tài khoản hợp lệ.
3. Gửi hai POST ghi sổ gần như đồng thời, mỗi request có session và CSRF hợp lệ riêng.
4. Kiểm tra chỉ một phiếu `posted`, số dư bằng 0, phiếu còn lại `draft`, không có biến động mồ côi.
5. Gửi lại request của phiếu đã ghi sổ; số dư vẫn 0 và số biến động không tăng.

Hai tab dùng cùng session PHP có thể bị tuần tự hóa bởi khóa session, làm phép thử không chạm được cạnh tranh database. Dùng hai session/tài khoản độc lập hoặc test tích hợp gọi lớp nghiệp vụ trên hai kết nối riêng.

Thử tương tự với hai giá giao nhau và hai yêu cầu hủy/ghi sổ cùng phiếu. Nếu gặp deadlock, báo lỗi rõ hoặc retry có giới hạn ở toàn transaction; không retry từng câu SQL rời rạc.

## 6. Yêu cầu nộp bài

- Source PHP và script migration; không nộp mật khẩu thật hay database cá nhân.
- README cách chạy từ database mới và cách nâng cấp database cũ.
- Quy tắc nghiệp vụ bổ sung, sơ đồ quan hệ và ma trận quyền.
- Ảnh các màn hình chính và một bản PDF in phiếu mẫu từ trình duyệt.
- Bảng ca kiểm thử có dữ liệu đầu vào, kết quả mong đợi, kết quả thực tế.
- Bằng chứng đối soát tồn và một phép thử cạnh tranh có hai session độc lập.
- Ghi rõ giới hạn còn lại; chức năng chưa làm không được ghi là đã hoàn thành.

## 7. Thang điểm gợi ý

| Hạng mục | Điểm | Điều kiện đạt |
|---|---:|---|
| Mô hình dữ liệu và migration | 15 | Đúng quan hệ, bảo toàn dữ liệu cũ, có ràng buộc |
| Phân quyền | 20 | Đúng thứ tự ưu tiên, chặn request trực tiếp, kiểm tra server |
| Nghiệp vụ nhập xuất | 25 | Không âm tồn, không ghi trùng, rollback toàn phiếu, bất biến phiếu đã ghi sổ |
| Giá theo thời gian | 10 | Không giao khoảng, đúng thời điểm biên, giá chụp không đổi |
| Giao diện và phân trang | 10 | Giữ bộ lọc, trạng thái rõ, thông báo lỗi hữu ích, in đọc được |
| Bài mở rộng đã chọn | 10 | Đạt quy tắc và ca nghiệm thu đã nộp |
| Kiểm thử và giải thích | 10 | Có đối soát, cạnh tranh, trình bày được quyết định thiết kế |
| **Tổng** | **100** | |

Các lỗi cho phép ghi sổ trái quyền, âm tồn hoặc ghi trùng chứng từ là lỗi nghiệp vụ trọng yếu; cần sửa trước khi nghiệm thu phần mở rộng.
