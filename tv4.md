# 📋 TỔNG HỢP CÔNG VIỆC & BÁO CÁO NHIỆM VỤ THÀNH VIÊN 4 (TV4)
## DỰ ÁN DINEFLOW - HỆ THỐNG GỌI MÓN QR, BẾP KDS & PHÂN TÍCH F&B

> **Họ và tên:** Thành viên 4 (TV4)  
> **Vai trò trong nhóm:** **Backend Lead & Real-time Data Engineer**  
> **Trọng trách chính:** Xây dựng hạ tầng máy chủ, Cơ sở dữ liệu Dual-Schema, Giỏ hàng chung WebSocket Redis, Pipeline đồng bộ dữ liệu tự động (ETL), Thuật toán gợi ý món ăn kèm (Apriori) và Màn hình Điều phối Hoạt động Real-time.

---

## 1. TỔNG QUAN: PHẦN CỦA TV4 CÓ VAI TRÒ GÌ TRONG DỰ ÁN?

Trong một hệ thống phần mềm nhà hàng hiện đại, nếu chỉ có giao diện Frontend bấm nút thì hệ thống không thể hoạt động được. **TV4 là người xây dựng toàn bộ "xương sống" và "bộ não" bên dưới cho cả 4 thành viên còn lại:**
* Nếu không có TV4 $\rightarrow$ **TV1** không có cơ sở dữ liệu để lưu bàn và món ăn.
* Nếu không có TV4 $\rightarrow$ **TV2** không thể đồng bộ giỏ hàng chung giữa nhiều người cùng bàn và không có tính năng gợi ý món.
* Nếu không có TV4 $\rightarrow$ **TV3** không có luồng chốt bàn tự động đóng dữ liệu.
* Nếu không có TV4 $\rightarrow$ **TV5** hoàn toàn không có kho dữ liệu sạch (OLAP) để vẽ biểu đồ báo cáo doanh thu.

---

## 2. CHI TIẾT CÁC CÔNG VIỆC TV4 ĐÃ HOÀN THÀNH 100%

### 🔹 Module 1: Thiết Kế & Khởi Tạo Cơ Sở Dữ Liệu Kép (PostgreSQL Dual-Schema)
* **File phụ trách:** [`backend/app/core/database.py`](file:///backend/app/core/database.py), [`backend/app/models/oltp_models.py`](file:///backend/app/models/oltp_models.py), [`backend/app/models/olap_models.py`](file:///backend/app/models/olap_models.py).
* **Kết quả đạt được:**
  1. Tách biệt hoàn toàn 2 Schema riêng biệt trên cùng 1 PostgreSQL Database:
     * **Schema `oltp` (Vận hành giao dịch nhanh):** Gồm 8 bảng quan hệ chuẩn hóa 3NF (`users`, `dining_tables`, `table_sessions`, `categories`, `menu_items`, `toppings`, `orders`, `order_items`).
     * **Schema `olap` (Kho phân tích theo mô hình Star Schema):** Gồm 3 bảng chiều (`dim_time`, `dim_menu_item`, `dim_chef`) và 2 bảng sự kiện trung tâm (`fact_order_items`, `fact_clickstream`).
  2. Viết hàm `init_schemas()` tự động tạo Schema và migration bảng ngay khi server khởi động mà không cần chạy lệnh SQL thủ công.

---

### 🔹 Module 2: Giỏ Hàng Chung Thời Gian Thực (Redis & WebSocket Pub/Sub)
* **File phụ trách:** [`backend/app/core/ws_and_cart.py`](file:///backend/app/core/ws_and_cart.py), [`backend/app/routers/p2_client_order.py`](file:///backend/app/routers/p2_client_order.py).
* **Kết quả đạt được:**
  1. Xây dựng lớp `RealtimeManager` quản lý kết nối WebSocket theo nhóm bàn (`session_token`) và nhân viên (`staff_connections`).
  2. Xây dựng `SharedCartService` lưu trữ giỏ hàng tạm thời vào in-memory RAM của **Redis** với khóa `shared_cart:{token}` (TTL 4 tiếng).
  3. **Cơ chế hoạt động:** Khi 2-3 khách cùng ngồi Bàn 1 quét QR trên điện thoại riêng, người A thêm món thì điện thoại của người B tự động cập nhật giỏ hàng ngay lập tức qua WebSocket mà không cần reload trang.

---

### 🔹 Module 3: Luồng Đồng Bộ Dữ Liệu Tự Động (Real-time Background ETL Pipeline)
* **File phụ trách:** [`backend/app/routers/p4_data_pipeline.py`](file:///backend/app/routers/p4_data_pipeline.py), [`backend/app/routers/p3_kds_pos.py`](file:///backend/app/routers/p3_kds_pos.py).
* **Kết quả đạt được:**
  1. Xây dựng hàm lõi `run_etl_for_closed_session(session_id)`:
     * Tự động kích hoạt ngầm (Background Task) ngay thời khắc Thu ngân (TV3) bấm thanh toán đóng bàn tại POS.
     * Trích xuất toàn bộ đơn hàng từ bảng `orders` (OLTP) $\rightarrow$ Tính toán thời gian chế biến của bếp $\rightarrow$ Kiểm tra vi phạm SLA $\rightarrow$ Đồng bộ SCD Type 1 vào bảng chiều `dim_menu_item`, `dim_chef`, `dim_time` $\rightarrow$ Nạp thẳng vào `fact_order_items` (OLAP).
     * Đánh dấu cờ `is_etl_processed = True` để đảm bảo tính bất biến (Idempotent), không bao giờ bị nhân đôi số liệu.
  2. Cung cấp API `POST /api/v1/data/trigger-batch-etl` để quét vét cạn và đối soát toàn bộ các hóa đơn chưa đồng bộ.

---

### 🔹 Module 4: Bộ Thu Thập Hành Vi Khách Hàng (Clickstream Tracking)
* **File phụ trách:** [`backend/app/routers/p4_data_pipeline.py`](file:///backend/app/routers/p4_data_pipeline.py), tích hợp vào [`frontend/js/menu.js`](file:///frontend/js/menu.js).
* **Kết quả đạt được:**
  1. Xây dựng API `POST /api/v1/data/clickstream` nhận sự kiện người dùng (`VIEW_ITEM`, `ADD_TO_CART`, `REMOVE_FROM_CART`).
  2. Khi khách hàng bấm xem chi tiết món ăn trên điện thoại, sự kiện kèm mã phiên bàn được ghi nhận ngay vào bảng `fact_clickstream` để phục vụ đo lường tỷ lệ chuyển đổi cho TV5.

---

### 🔹 Module 5: Động Cơ Gợi Ý Món Ăn Kèm (Apriori Algorithm & Combo Upsell)
* **File phụ trách:** [`backend/app/routers/p4_data_pipeline.py`](file:///backend/app/routers/p4_data_pipeline.py).
* **Kết quả đạt được:**
  1. Triển khai thuật toán Khai phá Luật kết hợp (**Market Basket Analysis / Apriori**) bằng truy vấn tối ưu Common Table Expressions (CTE) trên PostgreSQL:
     * Tính toán đồng thời 3 chỉ số kinh tế: **Support (%)**, **Confidence (%)**, và **Lift Ratio**.
     * API `GET /api/v1/data/recommendations/{menu_item_id}`: Trả về top 3 món ăn thường xuyên được khách mua kèm nhiều nhất.
  2. Xây dựng API `POST /api/v1/data/combo-rule`: Cho phép chủ quán hoặc quản lý ghim cặp món khuyến mãi Combo (ví dụ: Gọi Mì Cay $\rightarrow$ Gợi ý Trà Đào) trực tiếp từ giao diện điều khiển.
  3. API `GET /api/v1/data/association-rules`: Liệt kê danh sách các cặp món mua chung mạnh nhất toàn quán.

---

### 🔹 Module 6: Giám Sát Hiệu Suất Bếp (Kitchen SLA) & Giờ Cao Điểm
* **File phụ trách:** [`backend/app/routers/p4_data_pipeline.py`](file:///backend/app/routers/p4_data_pipeline.py).
* **Kết quả đạt được:**
  1. API `GET /api/v1/data/sla-analytics`: Tự động tính thời gian từ lúc khách gửi đơn (`created_at`) đến khi đầu bếp bấm hoàn thành trên KDS (`completed_at`). Cảnh báo vi phạm SLA nếu vượt ngưỡng **15 phút (900 giây)** và xếp hạng các món thường xuyên gây nghẽn bếp.
  2. API `GET /api/v1/data/peak-hours`: Phân tích lượng khách và doanh thu theo 24 khung giờ trong ngày từ bảng `dim_time`.

---

### 🔹 Module 7: Màn Hình Quản Trị & Điều Phối Live Operations (Giao diện Web của TV4)
* **File phụ trách:** [`frontend/tv4_data_pipeline.html`](file:///frontend/tv4_data_pipeline.html) (Truy cập tại: `http://localhost:8000/tv4`).
* **Kết quả đạt được:**
  1. Tự động làm mới mỗi 5 giây (`Auto-refresh 5s` qua API `/live-operations`).
  2. Hiển thị danh sách bàn đang phục vụ thực tế: có bao nhiêu thiết bị đang kết nối WebSocket, giỏ hàng Redis đang có những món gì, món nào đã bắn xuống bếp (`PENDING`, `COOKING`, `COMPLETED`), tạm tính tiền bàn.
  3. Form cấu hình gợi ý Combo: Chọn Món A $\rightarrow$ Gợi ý Món B $\rightarrow$ Bấm lưu là có hiệu lực ngay trên menu điện thoại của khách.
  4. Bảng kiểm tra Nhật ký hóa đơn đã thanh toán và trạng thái đồng bộ sang OLAP.

---

## 3. BẢNG TỔNG HỢP CÁC FILE MÃ NGUỒN DO TV4 XÂY DỰNG

| Loại file | Đường dẫn file | Mô tả chức năng |
| :--- | :--- | :--- |
| **Database Core** | `backend/app/core/database.py` | Quản lý kết nối PostgreSQL, engine, session, khởi tạo 2 Schema `oltp` & `olap`. |
| **Realtime Core** | `backend/app/core/ws_and_cart.py` | Quản lý WebSocket Manager & Redis Shared Cart Service. |
| **Data Models** | `backend/app/models/oltp_models.py` | Định nghĩa 8 bảng nghiệp vụ vận hành bán hàng. |
| **Data Models** | `backend/app/models/olap_models.py` | Định nghĩa Star Schema (3 bảng Dim + 2 bảng Fact). |
| **API Router** | `backend/app/routers/p4_data_pipeline.py` | Toàn bộ 8 API xử lý luồng: Live Ops, ETL, Apriori, SLA, Peak Hours. |
| **Server Main** | `backend/app/main.py` | Cấu hình FastAPI, tích hợp Middleware CORS, gắn Static Files frontend. |
| **Frontend UI** | `frontend/tv4_data_pipeline.html` | Màn hình điều phối live bàn, giỏ hàng, đơn bếp và cấu hình gợi ý món. |
| **Automation** | `1_chay_server.bat` | Script 1-click khởi chạy toàn bộ server FastAPI chuẩn trên Windows. |

---

## 4. KỊCH BẢN DEMO BÁO CÁO DÀNH CHO TV4 (KHI THẦY CÔ CHẤM BÀI)

Khi đến lượt TV4 trình bày, bạn thực hiện đúng 3 bước thuyết phục này:

1. **Bước 1 - Giới thiệu kiến trúc Backend & Cơ sở dữ liệu:**
   * Mở file `database.py` và PostgreSQL: Chỉ cho thầy cô thấy hệ thống áp dụng kiến trúc chuẩn doanh nghiệp **Dual-Schema** (Schema `oltp` lưu đơn hàng tức thời, Schema `olap` lưu dữ liệu phân tích sao).
2. **Bước 2 - Chứng minh luồng dữ liệu thời gian thực (Live Operations & WebSocket):**
   * Mở trang `http://localhost:8000/tv4`.
   * Mở 1 tab điện thoại menu khách (`menu/index.html`): Cho món vào giỏ hàng.
   * Quay lại trang TV4: Chỉ cho thầy cô thấy giỏ hàng nhảy số trực tiếp từ RAM Redis lên màn hình mà không cần load lại trang.
3. **Bước 3 - Chứng minh Thuật toán Gợi ý món (Apriori / Combo Upsell):**
   * Trên trang TV4: Chọn món *Mì Cay* gợi ý kèm *Trà Đào Cam Sả* $\rightarrow$ Bấm **Lưu**.
   * Sang trang menu khách: Bấm vào món *Mì Cay* $\rightarrow$ Khung **"🔥 Món hay được gọi kèm cùng: Trà Đào Cam Sả"** hiện lên ngay lập tức!
4. **Bước 4 - Chứng minh Real-time ETL & Đo SLA Bếp:**
   * Sang trang POS của TV3 bấm thanh toán $\rightarrow$ Quay lại trang TV4 thấy hóa đơn vừa thanh toán tự động chảy vào kho OLAP và hiển thị thời gian chế biến của đầu bếp.

---
*Bản báo cáo được lập để minh chứng khối lượng công việc thực tế và vai trò kỹ thuật nòng cốt của TV4 trong dự án DineFlow.*
