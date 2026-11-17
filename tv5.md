# 📊 HỒ SƠ KỸ THUẬT, PHÂN TÍCH DỮ LIỆU BI & CẨM NANG BẢO VỆ ĐỒ ÁN THÀNH VIÊN 5 (TV5)
## DỰ ÁN DINEFLOW — HỆ THỐNG GỌI MÓN QR, BẾP KDS & PHÂN TÍCH NHÀ HÀNG F&B
### CHUẨN HÓA THEO ĐẶC TẢ KỸ THUẬT & KHUNG CHẤM ĐIỂM BÀI TẬP LỚN (PHP 8.2 THUẦN + MYSQL 8.0 + DOCKER)

> **Vai trò trong nhóm:** **BI Analytics, Menu Engineering & Financial Data Reconciliation Lead (TV5)**  
> **Công nghệ nền tảng:** PHP 8.2 thuần (Vanilla PHP + `ZipArchive` OpenXML), PDO MySQL 8.0 / SQLite fallback, Star Schema OLAP (`dim_time`, `dim_menu_item`, `dim_chef`, `fact_order_items`, `fact_clickstream`), HTML5, CSS3, JavaScript & HTML DOM  
> **Các tệp phụ trách chính:**
> - [`app/Controllers/DataPipelineController.php`](./app/Controllers/DataPipelineController.php) (Các phương thức BI, Menu Engineering Matrix 4 Quadrants & Export `.xlsx` / `.csv` / `.json`)
> - [`app/public/api_router.php`](./app/public/api_router.php) (Nhóm route `/api/v1/bi/*` bảo vệ bởi RBAC `reports.view`)
> - [`app/public/dashboard.php`](./app/public/dashboard.php) (Trung tâm Báo cáo BI, Biểu đồ Doanh thu, Khung giờ cao điểm, Ma trận Menu Engineering 4 Quadrants & Đối soát Tài chính)
> - [`app/public/demo_tester.html`](./app/public/demo_tester.html) (Cột 3: Bảng điều khiển BI thời gian thực & Xuất báo cáo `.xlsx`)
> - [`tests/run_tests.php`](./tests/run_tests.php) (Phần 8: Bộ kiểm thử `TV5-8.1` .. `TV5-8.7`) & [`tests/test_live_e2e.php`](./tests/test_live_e2e.php) (`E2E-BI-EXPORT-XLSX`)

---

## 1. VỊ TRÍ, VAI TRÒ & LUỒNG DỮ LIỆU CỦA TV5 TRONG KIẾN TRÚC DINEFLOW

### 1.1. Sơ đồ Luồng dữ liệu từ Nhà hàng (OLTP) sang Báo cáo Quản trị (OLAP BI)

```
┌────────────────────────────────────────────────────────────────────────────┐
│ 1. TẦNG VẬN HÀNH NHÀ HÀNG (OLTP - TV1, TV2, TV3)                           │
│  • Khách quét QR xem món ➔ Gửi sự kiện VIEW_ITEM                           │
│  • Khách đặt món ➔ Bếp KDS nấu (ghi nhận prep_time_seconds)                │
│  • Thu ngân POS chốt thanh toán (VAT 8%, làm tròn 500đ) ➔ Đóng phiên bàn   │
└───────────────────────────────┬────────────────────────────────────────────┘
                                │
                                ▼  (Tự động kích hoạt EtlService - TV4)
┌────────────────────────────────────────────────────────────────────────────┐
│ 2. TẦNG KHO DỮ LIỆU HÌNH SAO (STAR SCHEMA OLAP - TV4 & TV5)               │
│  • Bảng chiều (Dimensions): dim_time (YmdH), dim_menu_item, dim_chef       │
│  • Bảng sự kiện (Facts):                                                   │
│     - fact_order_items (revenue, quantity, prep_time_seconds, sla_flag)    │
│     - fact_clickstream (time_key, session_id, oltp_item_id, VIEW_ITEM)     │
└───────────────────────────────┬────────────────────────────────────────────┘
                                │
                                ▼  (Truy vấn phân tích & Trực quan hóa - TV5)
┌────────────────────────────────────────────────────────────────────────────┐
│ 3. TẦNG BÁO CÁO BI & MENU ENGINEERING (TV5 - Yêu cầu quyền reports.view)  │
│  • GET /api/v1/bi/dashboard-summary       (KPI Doanh thu, AOV, SLA, Conv%) │
│  • GET /api/v1/bi/revenue-over-time       (Biểu đồ doanh thu theo ngày)    │
│  • GET /api/v1/bi/menu-engineering-matrix (Phân nhóm 4 góc phần tư F&B)    │
│  • GET /api/v1/bi/export-report           (Xuất Excel .xlsx / .csv / .json)│
└────────────────────────────────────────────────────────────────────────────┘
```

### 1.2. Tại sao phải tách bảng OLAP (Star Schema) thay vì `SELECT SUM()` trực tiếp trên bảng đơn hàng OLTP?
* **Bảo vệ hiệu năng giao dịch (Workload Isolation):** Trong giờ cao điểm trưa/tối, các bảng `orders` và `order_items` liên tục chịu tải ghi/cập nhật khóa dòng (`PDO Transaction`) từ khách quét QR, bếp KDS và thu ngân POS. Truy vấn thống kê trên bảng OLAP (`fact_order_items`, `dim_time`) giúp báo cáo chạy nhanh mà không gây lock bảng đặt món.
* **Chuẩn hóa chiều phân tích (Dimensional Modeling):** Bảng `dim_time` tách sẵn `full_date`, `hour`, `day_of_week`, `is_weekend` với khóa nguyên `time_key` dạng `YYYYMMDDHH` (ví dụ `2026111218`), giúp gom nhóm doanh thu theo ngày hoặc khung giờ cao điểm nhanh qua Index.

---

## 2. CHI TIẾT 4 API & CÔNG THỨC TOÁN HỌC DO TV5 XÂY DỰNG

Tất cả 4 endpoint `/api/v1/bi/*` được kiểm tra quyền `reports.view` qua `requireApiPermission('reports.view')` trong [`app/public/api_router.php`](./app/public/api_router.php) (chưa đăng nhập trả `401`, thiếu quyền trả `403`).

### 🔹 API 1: Tổng hợp KPI Quản trị (`GET /api/v1/bi/dashboard-summary`)
* **File cài đặt:** [`DataPipelineController::getBiDashboardSummary()`](./app/Controllers/DataPipelineController.php)
* **Các chỉ số trả về & Công thức:**
  1. **Tổng doanh thu thuần OLAP (`total_revenue`):** $\sum \text{fact\_order\_items.revenue}$
  2. **Tổng số lượng phần ăn đã bán (`total_sold`):** $\sum \text{fact\_order\_items.quantity}$
  3. **Giá trị đơn trung bình mỗi phiên bàn (`avg_order_value` - AOV):** $\frac{\text{Total Revenue}}{\max(1, \text{purchase\_sessions})}$
  4. **Tỷ lệ chuyển đổi phiên bàn (`conversion_rate`):** $\text{round}\left(\frac{\text{purchase\_sessions}}{\max(1, \max(\text{click\_sessions}, \text{purchase\_sessions}))} \times 100, 1\right)$
  5. **Tỷ lệ tuân thủ SLA Bếp (`sla_compliance_rate` - Ngưỡng $\le 900\text{s}$ / 15 phút):** $\text{round}\left(\left(1 - \frac{\sum \text{is\_sla\_violated}}{\text{total\_fact\_rows}}\right) \times 100, 1\right)$

---

### 🔹 API 2: Chuỗi thời gian Doanh thu theo Ngày (`GET /api/v1/bi/revenue-over-time`)
* **File cài đặt:** [`DataPipelineController::getBiRevenueOverTime()`](./app/Controllers/DataPipelineController.php)
* **Truy vấn SQL cốt lõi:**
  ```sql
  SELECT
      t.full_date as date,
      COALESCE(SUM(f.revenue), 0) as revenue,
      COALESCE(SUM(f.quantity), 0) as total_sold,
      COUNT(DISTINCT f.session_id) as sessions_count
  FROM fact_order_items f
  JOIN dim_time t ON f.time_key = t.time_key
  GROUP BY t.full_date
  ORDER BY t.full_date ASC
  ```

---

### 🔹 API 3: Ma trận Thực đơn — Menu Engineering Matrix (`GET /api/v1/bi/menu-engineering-matrix`)
* **File cài đặt:** [`DataPipelineController::getBiMenuMatrix()`](./app/Controllers/DataPipelineController.php) và `buildMenuMatrixData()`
* **Ý nghĩa nghiệp vụ F&B:** Kết hợp dữ liệu hành vi xem món (`fact_clickstream`) với dữ liệu bán hàng thực tế (`fact_order_items`), đối chiếu với ngưỡng trung bình của các món có phát sinh tương tác ($\overline{\text{Sold}}$ và $\overline{\text{Revenue}}$) để phân loại các món ăn vào **4 Góc Phần Tư (4 Quadrants)**:

| Mã Nhóm | Tên Phân Nhóm | Điều Kiện Phân Loại | Khuyến Nghị Chiến Lược Quản Trị Nhà Hàng |
| :--- | :--- | :--- | :--- |
| **`STAR`** | ⭐ **Star (Ngôi Sao)** | $\text{Sold} \ge \overline{\text{Sold}}$ **và** $\text{Revenue} \ge \overline{\text{Revenue}}$ | Món chủ lực sinh lời cao: Giữ vững chất lượng, đặt vị trí nổi bật đầu Menu QR. |
| **`PLOWHORSE`** | 🐴 **Plowhorse (Ngựa Thồ)** | $\text{Sold} \ge \overline{\text{Sold}}$ **và** $\text{Revenue} < \overline{\text{Revenue}}$ | Món mồi hút khách: Ghép vào luật gợi ý mua kèm (Apriori Combo Upsell) để kéo tổng bill. |
| **`PUZZLE`** | 🧩 **Puzzle (Dấu Hỏi)** | $\text{Sold} < \overline{\text{Sold}}$ nhưng $\text{Revenue} \ge \overline{\text{Revenue}}$ hoặc có lượt xem cao mà ít chốt | Món tiềm năng: Chạy giảm giá nhẹ giờ thấp điểm hoặc tối ưu lại mô tả/hình ảnh. |
| **`DOG`** | 🐕 **Dog (Cần Cải Tiến)** | $\text{Sold} < \overline{\text{Sold}}$, $\text{Revenue} < \overline{\text{Revenue}}$ và ít lượt xem | Món kém hiệu quả: Cân nhắc cải tiến công thức hoặc loại bỏ khỏi thực đơn để giảm tồn kho. |

---

### 🔹 API 4: Xuất Báo Cáo Excel (`.xlsx`), CSV UTF-8 BOM (`.csv`) & JSON (`GET /api/v1/bi/export-report`)
* **File cài đặt:** [`DataPipelineController::exportBiReport()`](./app/Controllers/DataPipelineController.php) và `generateStyledXlsx()`
* **Đặc điểm kỹ thuật:**
  * Hỗ trợ xuất 2 loại báo cáo: `?type=menu-matrix` (Ma trận Menu Engineering) và `?type=revenue` (Báo cáo doanh thu & SLA theo ngày).
  * `?format=xlsx` (mặc định): Sinh tệp Excel OpenXML (`.xlsx`) bằng PHP `ZipArchive` thuần (không cần Composer package nặng bên ngoài), có định dạng tiêu đề màu, tô màu theo 4 nhóm Quadrant, tự động giãn độ rộng cột, đóng khung và dòng tổng kết.
  * `?format=csv`: Đính kèm chuỗi byte **UTF-8 BOM (`\xEF\xBB\xBF`)** giúp mở trực tiếp bằng Microsoft Excel trên Windows hiển thị tiếng Việt có dấu chuẩn 100%, kèm `sanitizeCsvCell()` chống CSV Formula Injection (`=`, `+`, `-`, `@`).
  * `?format=json`: Hỗ trợ kiểm thử tự động trong [`tests/run_tests.php`](./tests/run_tests.php).

---

## 3. CẨM NANG BẢO VỆ ĐỒ ÁN & TRẢ LỜI PHẢN BIỆN DÀNH RIÊNG CHO TV5

### ❓ Câu hỏi 1: *"Tại sao Tổng doanh thu trên báo cáo BI (`fact_order_items`) lại chênh lệch với Tổng tiền thanh toán trong bảng `bill_payments` của POS?"*
* **Trả lời chuẩn:**
  * Đây là thiết kế có chủ đích về kế toán quản trị nhà hàng và được hiển thị rõ trên bảng **Đối Soát Tài Chính (BI Integrity Check)** tại [`app/public/dashboard.php`](./app/public/dashboard.php):
  * Bảng OLAP `fact_order_items` lưu **Doanh thu thuần theo từng món ăn (Net Item Revenue trước thuế)** để phân tích chính xác sức mua và đóng góp của từng món (khớp với `orders.total_amount` và `table_sessions.total_amount`, được kiểm chứng bởi `QP-7.4` trong `tests/run_tests.php`; các món bị khách hủy `CANCELLED` được loại trừ đồng nhất ở cả OLTP bill, POS checkout và ETL).
  * Trong khi đó, bảng `bill_payments` ở quầy Thu ngân POS lưu **Tổng tiền thực thu của khách (Gross Payment)** sau khi cộng thêm **Thuế VAT 8%** và áp dụng **làm tròn mốc 500đ** (`round((subtotal * 1.08) / 500) * 500`).

### ❓ Câu hỏi 2: *"Nếu thu ngân hoặc quản trị viên bấm nút đồng bộ ETL (`POST /api/v1/data/trigger-batch-etl`) nhiều lần liên tiếp thì báo cáo doanh thu có bị nhân đôi không?"*
* **Trả lời chuẩn:**
  * Hoàn toàn **không bị nhân đôi**.
  * Trong [`app/Services/EtlService.php`](./app/Services/EtlService.php), phương thức `runEtlForClosedSession()` áp dụng 2 chốt chặn Idempotency: kiểm tra `table_sessions.is_etl_processed == 1`, và kiểm tra `SELECT COUNT(*) FROM fact_order_items WHERE session_id = ?` trước khi nạp Fact.

### ❓ Câu hỏi 3: *"Làm sao tính được Tỷ lệ chuyển đổi (Conversion Rate) của từng món ăn khi khách quét mã QR không cần đăng nhập?"*
* **Trả lời chuẩn:**
  * Mỗi phiên bàn có một mã định danh phiên (`session_id` / `session_token`). Khi khách bấm xem chi tiết món trên điện thoại, frontend gửi sự kiện `VIEW_ITEM` kèm `menu_item_id` và `session_id` về `POST /api/v1/data/clickstream` lưu vào `fact_clickstream`.
  * Trong `buildMenuMatrixData()`, hệ thống `LEFT JOIN` giữa `menu_items`, tổng lượt xem từ `fact_clickstream` và tổng số lượng bán từ `fact_order_items` để tính tỷ lệ chuyển đổi và phân loại 4 góc phần tư (`STAR`, `PLOWHORSE`, `PUZZLE`, `DOG`).

### ❓ Câu hỏi 4: *"Tại sao khi xuất file CSV cho Excel phải in ra chuỗi `\xEF\xBB\xBF` ở đầu file, và tại sao dự án còn hỗ trợ `.xlsx` trực tiếp?"*
* **Trả lời chuẩn:**
  * `\xEF\xBB\xBF` là **UTF-8 Byte Order Mark (BOM)** giúp Microsoft Excel trên Windows không đọc nhầm bảng mã ANSI làm lỗi dấu tiếng Việt. Ngoài `.csv`, hệ thống còn sinh file `.xlsx` OpenXML trực tiếp qua `ZipArchive` ở PHP để người dùng tải xuống file bảng tính định dạng chuẩn ngay lập tức.
