# Phần 2 – Giao diện khách hàng quét QR và đặt món

## File đã thêm

- `frontend_p2/menu/index.html`: trang menu tại đường dẫn `/menu`, tương thích URL QR đang được API Phần 1 sinh (`/menu?table=...&token=...`).
- `frontend_p2/css/menu.css`: giao diện mobile-first, co giãn cho điện thoại, tablet và máy tính.
- `frontend_p2/js/menu.js`: tải menu, tìm kiếm/lọc món, chọn topping/số lượng/ghi chú, giỏ hàng, gửi đơn và đồng bộ WebSocket.

Các file KDS/POS và mã backend hiện hữu không bị sửa.

## Kết nối với các phần khác

### Phần 1 – Admin và danh mục

- Đọc `GET /api/v1/admin/menu-items` để hiển thị danh mục, món, giá, ảnh, trạng thái còn món và topping.
- Đọc tham số `table` và `token` từ URL QR do `POST /api/v1/admin/tables/{table_id}/open-session` trả về.
- Khi API menu chưa chạy được, trang hiển thị menu minh họa và báo rõ chế độ xem thử. Giỏ hàng xem thử không gửi đơn.
- Backend hiện chưa có API tạo món mới; giao diện sẽ hiển thị món mới sau khi menu được lưu ở backend và trang tải lại.

### Phần 3 – KDS và POS

- Gửi đơn bằng `POST /api/v1/client/session/{session_token}/submit-order` theo payload `CartItemPayload`.
- Backend lưu đơn rồi phát `NEW_ORDER_RECEIVED` đến kết nối nhân viên; KDS Phần 3 lấy đơn đang chờ qua `GET /api/v1/staff/kds/active-items`.
- Phần 2 mở `WS /api/v1/client/ws/table/{session_token}` để đồng bộ giỏ hàng giữa các điện thoại cùng bàn và nhận thông báo trạng thái món.
- Thanh toán/đóng bàn thuộc POS Phần 3, không nằm trong trang khách Phần 2.

## Cách mở giao diện

Từ thư mục gốc dự án, chạy máy chủ tĩnh cho frontend:

```powershell
python -m http.server 3000 --directory frontend_p2
```

Trang menu: `http://localhost:3000/menu?table=1&token=<session_token>`.

Backend mặc định là `http://localhost:8000`. Có thể ghi đè bằng tham số `api`, ví dụ `?table=1&token=...&api=http://localhost:8000`. Khi mở menu không có token, trang chỉ hoạt động như bản xem thử và không gửi đơn.
