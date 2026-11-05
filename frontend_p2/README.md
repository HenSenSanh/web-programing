# DineFlow — Phần 2: Khách quét QR và đặt món

## Phần 2

- Giao diện menu co giãn theo điện thoại, tablet và máy tính.
- Thanh tìm kiếm, lọc món theo danh mục và khu món nổi bật lấy từ dữ liệu menu.
- Xem món, chọn số lượng, topping, ghi chú; xem và chỉnh giỏ hàng.
- Đồng bộ giỏ hàng giữa các điện thoại cùng bàn qua WebSocket.
- Gửi đơn theo phiên QR và nhận thông báo trạng thái món.
- Ảnh món có đường dẫn tương đối ổn định khi chạy qua máy chủ local; lỗi ảnh có biểu tượng thay thế.

Giao diện lấy danh mục, món và topping từ API `GET /api/v1/admin/menu-items`; không còn danh sách món dự phòng hard-code. Trang dùng API đặt món `POST /api/v1/client/session/{session_token}/submit-order` và WebSocket `WS /api/v1/client/ws/table/{session_token}`.

## Chạy trên máy tính

Cần cài Docker Desktop và Python 3.10 trở lên. Mở PowerShell ở thư mục gốc dự án:

```powershell
docker compose up -d
```

Mở PowerShell thứ hai và cài/chạy backend. Chạy bước tạo dữ liệu mẫu một lần đầu:

```powershell
cd C:\Users\vinh\Desktop\web-programing-truong\backend
python -m venv .venv
.\.venv\Scripts\python.exe -m pip install -r requirements.txt
.\.venv\Scripts\python.exe -m app.scripts.seed_data
.\.venv\Scripts\python.exe -m uvicorn app.main:app --host 0.0.0.0 --port 8000 --reload
```

Mở PowerShell thứ ba tại thư mục gốc để chạy giao diện Phần 2:

```powershell
cd C:\Users\vinh\Desktop\web-programing-truong
python -m http.server 3000 --bind 0.0.0.0 --directory frontend_p2
```

Mở trang API tại `http://localhost:8000/docs`. Lấy `session_token` bằng API mở phiên bàn, sau đó mở menu theo dạng:

```text
http://localhost:3000/menu?table=<table_id>&token=<session_token>&api=http://localhost:8000
```

Không có `token`, trang chỉ hiển thị menu; không thể gửi đơn.

## Mở menu bằng điện thoại trong cùng Wi‑Fi

Lấy IPv4 Wi‑Fi của máy tính bằng `ipconfig`. Thay `localhost` bằng IP đó ở cả địa chỉ giao diện và API. Ví dụ máy tính có IP `192.168.1.20`:

```text
http://192.168.1.20:3000/menu?table=<table_id>&token=<session_token>&api=http://192.168.1.20:8000
```

Điện thoại và máy tính cần cùng mạng Wi‑Fi. Nếu được hỏi, cho phép Python truy cập mạng riêng trong Windows Firewall.

## Thêm ảnh món

Khi mở HTML trực tiếp, trình duyệt có thể đọc ảnh trên ổ đĩa. Khi chạy web qua HTTP, máy chủ chỉ phục vụ file nằm trong thư mục `frontend_p2`; đường dẫn `C:\...` hoặc `file:///...` không thể được trình duyệt trên điện thoại đọc trực tiếp.

1. Đặt ảnh trong `frontend_p2/assets/images/`.
2. Lưu `image_url` của món theo đường dẫn tương đối, ví dụ `assets/images/mi-cay.jpg`.
3. Tải lại trang menu.

Nếu `image_url` hiện là đường dẫn tuyệt đối trên Windows, frontend sẽ lấy tên file và thử tìm ảnh cùng tên trong `frontend_p2/assets/images/`. Hãy chép file ảnh vào thư mục đó. Ảnh từ URL `http` hoặc `https` được tải trực tiếp; URL `localhost` cũng được đổi theo máy đang mở trang để dùng được trên điện thoại.
