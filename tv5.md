# 📊 HỒ SƠ KỸ THUẬT, PHÂN TÍCH DỮ LIỆU BI & CẨM NANG BẢO VỆ ĐỒ ÁN THÀNH VIÊN 5 (TV5)
## DỰ ÁN DINEFLOW — HỆ THỐNG GỌI MÓN QR, BẾP KDS & PHÂN TÍCH NHÀ HÀNG F&B
### CHUẨN HÓA THEO ĐẶC TẢ KỸ THUẬT & KHUNG CHẤM ĐIỂM BÀI TẬP LỚN (PHP 8.2 THUẦN + MYSQL 8.0 + DOCKER)

> **Họ và tên:** Thành viên 5 (TV5)  
> **Vai trò trong nhóm:** **BI Analytics, Menu Engineering & Financial Data Reconciliation Lead**  
> **Công nghệ nền tảng:** PHP 8.2 thuần (Vanilla PHP), PDO MySQL 8.0 / SQLite, Star Schema OLAP (`dim_time`, `dim_menu_item`, `dim_chef`, `fact_order_items`, `fact_clickstream`), HTML5, CSS3, JavaScript & HTML DOM  
> **Các tệp phụ trách chính:**
> - [`app/Controllers/DataPipelineController.php`](file:///d:/TỔNG%20HỢP/LAP%20TRINH%20WEB/web-programing-main/app/Controllers/DataPipelineController.php) (Các phương thức BI & Export CSV dòng 532–740)
> - [`app/public/api_router.php`](file:///d:/TỔNG%20HỢP/LAP%20TRINH%20WEB/web-programing-main/app/public/api_router.php) (Nhóm route `/api/v1/bi/*`)
> - [`app/public/dashboard.php`](file:///d:/TỔNG%20HỢP/LAP%20TRINH%20WEB/web-programing-main/app/public/dashboard.php) (Trung tâm Báo cáo BI, Biểu đồ Doanh thu, Ma trận Menu Engineering 4 Quadrants & Đối soát Tài chính)
> - [`demo_tester.html`](file:///d:/TỔNG%20HỢP/LAP%20TRINH%20WEB/web-programing-main/demo_tester.html) (Cột 3: Bảng điều khiển BI thời gian thực & Xuất báo cáo Excel CSV)
> - [`tests/run_tests.php`](file:///d:/TỔNG%20HỢP/LAP%20TRINH%20WEB/web-programing-main/tests/run_tests.php) (Phần 8: Bộ kiểm thử tự động `TV5-8.1` .. `TV5-8.6`)

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
│ 3. TẦNG BÁO CÁO BI & MENU ENGINEERING (TV5 PHỤ TRÁCH)                      │
│  • GET /api/v1/bi/dashboard-summary       (KPI Doanh thu, AOV, SLA, Conv%) │
│  • GET /api/v1/bi/revenue-over-time       (Biểu đồ doanh thu theo ngày)    │
│  • GET /api/v1/bi/menu-engineering-matrix (Phân nhóm 4 góc phần tư F&B)    │
│  • GET /api/v1/bi/export-report           (Xuất Excel CSV chuẩn UTF-8 BOM) │
└────────────────────────────────────────────────────────────────────────────┘
```

### 1.2. Tại sao phải tách bảng OLAP (Star Schema) thay vì `SELECT SUM()` trực tiếp trên bảng đơn hàng OLTP?
* **Bảo vệ hiệu năng giao dịch (Workload Isolation):** Trong giờ cao điểm trưa/tối, các bảng `orders` và `order_items` liên tục chịu tải ghi/cập nhật khóa dòng (`PDO Transaction`) từ khách quét QR, bếp KDS và thu ngân POS. Việc truy vấn thống kê phức tạp trên bảng OLAP (`fact_order_items`, `dim_time`) giúp báo cáo chạy tức thì mà không gây lock bảng đặt món.
* **Chuẩn hóa chiều phân tích (Dimensional Modeling):** Bảng `dim_time` tách sẵn `full_date`, `hour`, `day_of_week`, `is_weekend` với khóa nguyên `time_key` dạng `YYYYMMDDHH` (ví dụ `2026111218`), giúp gom nhóm doanh thu theo ngày hoặc khung giờ cao điểm cực nhanh qua Index.

---

## 2. CHI TIẾT 4 API & CÔNG THỨC TOÁN HỌC DO TV5 XÂY DỰNG

### 🔹 API 1: Tổng hợp KPI Quản trị (`GET /api/v1/bi/dashboard-summary`)
* **File cài đặt:** [`DataPipelineController::getBiDashboardSummary()`](file:///d:/TỔNG%20HỢP/LAP%20TRINH%20WEB/web-programing-main/app/Controllers/DataPipelineController.php#L536-L598)
* **Các chỉ số trả về & Công thức:**
  1. **Tổng doanh thu thuần OLAP (`total_revenue`):**
     $$\text{Total Revenue} = \sum \text{fact\_order\_items.revenue}$$
  2. **Tổng số lượng phần ăn đã bán (`total_sold`):**
     $$\text{Total Sold} = \sum \text{fact\_order\_items.quantity}$$
  3. **Giá trị đơn trung bình mỗi phiên bàn (`avg_order_value` - AOV):**
     $$\text{AOV} = \frac{\text{Total Revenue}}{\max(1, \text{purchase\_sessions})}$$
  4. **Tỷ lệ chuyển đổi phiên bàn (`conversion_rate`):**
     $$\text{Conversion Rate (\%)} = \text{round}\left(\frac{\text{purchase\_sessions}}{\max(1, \max(\text{click\_sessions}, \text{purchase\_sessions}))} \times 100, 1\right)$$
  5. **Tỷ lệ tuân thủ SLA Bếp (`sla_compliance_rate` - Ngưỡng $\le 900\text{s}$ / 15 phút):**
     $$\text{SLA Compliance (\%)} = \text{round}\left(\left(1 - \frac{\sum \text{is\_sla\_violated}}{\text{total\_fact\_rows}}\right) \times 100, 1\right)$$

---

### 🔹 API 2: Chuỗi thời gian Doanh thu theo Ngày (`GET /api/v1/bi/revenue-over-time`)
* **File cài đặt:** [`DataPipelineController::getBiRevenueOverTime()`](file:///d:/TỔNG%20HỢP/LAP%20TRINH%20WEB/web-programing-main/app/Controllers/DataPipelineController.php#L604-L624)
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
* **File cài đặt:** [`DataPipelineController::getBiMenuMatrix()`](file:///d:/TỔNG%20HỢP/LAP%20TRINH%20WEB/web-programing-main/app/Controllers/DataPipelineController.php#L630-L639) và [`buildMenuMatrixData()`](file:///d:/TỔNG%20HỢP/LAP%20TRINH%20WEB/web-programing-main/app/Controllers/DataPipelineController.php#L720-L802)
* **Ý nghĩa nghiệp vụ F&B:** Kết hợp dữ liệu hành vi xem món (`fact_clickstream`) với dữ liệu bán hàng thực tế (`fact_order_items`), đối chiếu với ngưỡng trung bình của các món có phát sinh tương tác ($\overline{\text{Sold}}$ và $\overline{\text{Revenue}}$) để phân loại toàn bộ 35 món ăn vào **4 Góc Phần Tư (4 Quadrants)**:

| Mã Nhóm | Tên Phân Nhóm | Điều Kiện Phân Loại | Khuyến Nghị Chiến Lược Quản Trị Nhà Hàng |
| :--- | :--- | :--- | :--- |
| **`STAR`** | ⭐ **Star (Ngôi Sao)** | $\text{Sold} \ge \overline{\text{Sold}}$ **và** $\text{Revenue} \ge \overline{\text{Revenue}}$ | Món chủ lực sinh lời cao: Giữ vững chất lượng, đặt vị trí nổi bật đầu Menu QR. |
| **`PLOWHORSE`** | 🐴 **Plowhorse (Ngựa Thồ)** | $\text{Sold} \ge \overline{\text{Sold}}$ **và** $\text{Revenue} < \overline{\text{Revenue}}$ | Món mồi hút khách: Ghép vào luật gợi ý mua kèm (Apriori Combo Upsell) để kéo tổng bill. |
| **`PUZZLE`** | 🧩 **Puzzle (Dấu Hỏi)** | $\text{Sold} < \overline{\text{Sold}}$ nhưng $\text{Revenue} \ge \overline{\text{Revenue}}$ hoặc có lượt xem cao mà ít chốt | Món tiềm năng: Chạy giảm giá nhẹ giờ thấp điểm hoặc tối ưu lại mô tả/hình ảnh. |
| **`DOG`** | 🐕 **Dog (Cần Cải Tiến)** | $\text{Sold} < \overline{\text{Sold}}$, $\text{Revenue} < \overline{\text{Revenue}}$ và ít lượt xem | Món kém hiệu quả: Cân nhắc cải tiến công thức hoặc loại bỏ khỏi thực đơn để giảm tồn kho. |

---

### 🔹 API 4: Xuất Báo Cáo Ra File Excel CSV Chuẩn UTF-8 BOM (`GET /api/v1/bi/export-report`)
* **File cài đặt:** [`DataPipelineController::exportBiReport()`](file:///d:/TỔNG%20HỢP/LAP%20TRINH%20WEB/web-programing-main/app/Controllers/DataPipelineController.php#L645-L715)
* **Đặc điểm kỹ thuật:**
  * Hỗ trợ xuất 2 loại báo cáo: `?type=menu-matrix` (Ma trận Menu Engineering 35 món) và `?type=revenue` (Báo cáo doanh thu & SLA theo ngày).
  * Đính kèm chuỗi byte **UTF-8 BOM (`\xEF\xBB\xBF`)** ở đầu luồng xuất `php://output` giúp mở trực tiếp bằng **Microsoft Excel** trên Windows hiển thị tiếng Việt có dấu chuẩn 100% không bị lỗi font.
  * Hỗ trợ tham số `?format=json` để kiểm thử tự động trong [`tests/run_tests.php`](file:///d:/TỔNG%20HỢP/LAP%20TRINH%20WEB/web-programing-main/tests/run_tests.php#L664-L680).

---

## 3. CẨM NANG BẢO VỆ ĐỒ ÁN & TRẢ LỜI PHẢN BIỆN DÀNH RIÊNG CHO TV5

Dưới đây là các câu hỏi phản biện thực tế mà giảng viên thường hỏi thành viên phụ trách BI / Báo cáo và câu trả lời chuẩn xác theo code của dự án:

### ❓ Câu hỏi 1: *"Tại sao Tổng doanh thu trên báo cáo BI (`fact_order_items`) lại chênh lệch với Tổng tiền thanh toán trong bảng `bill_payments` của POS?"*
* **Trả lời chuẩn:**
  * Dạ thưa thầy/cô, đây là thiết kế có chủ đích về kế toán quản trị nhà hàng và em đã hiển thị bảng **Đối Soát Tài Chính (BI Integrity Check)** ngay trên [`dashboard.php`](file:///d:/TỔNG%20HỢP/LAP%20TRINH%20WEB/web-programing-main/app/public/dashboard.php#L562-L572):
  * Bảng OLAP `fact_order_items` lưu **Doanh thu thuần theo từng món ăn (Net Item Revenue trước thuế)** để phân tích chính xác sức mua và đóng góp của từng món (khớp 100% với `orders.total_amount` và `table_sessions.total_amount`, được kiểm chứng bởi test case `QP-7.4` trong `run_tests.php`).
  * Trong khi đó, bảng `bill_payments` ở quầy Thu ngân POS lưu **Tổng tiền thực thu của khách (Gross Payment)** sau khi đã cộng thêm **Thuế VAT 8%** và áp dụng **làm tròn mốc 500đ** theo quy định của nhà hàng (`round((subtotal * 1.08) / 500) * 500`).

### ❓ Câu hỏi 2: *"Nếu thu ngân hoặc quản trị viên bấm nút đồng bộ ETL (`POST /api/v1/data/trigger-batch-etl`) nhiều lần liên tiếp thì báo cáo doanh thu của em có bị nhân đôi không?"*
* **Trả lời chuẩn:**
  * Dạ hoàn toàn **không bị nhân đôi** (đáp ứng Acceptance Test `AT-20`).
  * Trong [`EtlService::runEtlForClosedSession()`](file:///d:/TỔNG%20HỢP/LAP%20TRINH%20WEB/web-programing-main/app/Services/EtlService.php#L27-L39), hệ thống áp dụng **2 chốt chặn Idempotency**:
    1. Kiểm tra cờ `table_sessions.is_etl_processed == 1` thì dừng ngay.
    2. Kiểm tra `SELECT COUNT(*) FROM fact_order_items WHERE session_id = ?` — nếu phiên bàn đó đã tồn tại trong bảng Fact thì chỉ bật cờ `is_etl_processed = 1` chứ tuyệt đối không `INSERT` lần hai.

### ❓ Câu hỏi 3: *"Làm sao em tính được Tỷ lệ chuyển đổi (Conversion Rate) của từng món ăn khi khách quét mã QR không cần đăng nhập?"*
* **Trả lời chuẩn:**
  * Dạ khi khách quét mã QR tại bàn, mỗi phiên bàn có một mã định danh phiên (`session_id` / `session_token`).
  * Mỗi khi khách bấm xem chi tiết một món ăn trên điện thoại, frontend gửi sự kiện `VIEW_ITEM` kèm `menu_item_id` và `session_id` về API `POST /api/v1/data/clickstream` lưu vào bảng `fact_clickstream`.
  * Trong [`DataPipelineController::buildMenuMatrixData()`](file:///d:/TỔNG%20HỢP/LAP%20TRINH%20WEB/web-programing-main/app/Controllers/DataPipelineController.php#L720-L802), em dùng `LEFT JOIN` giữa bảng `menu_items`, tổng lượt xem từ `fact_clickstream` và tổng số lượng bán từ `fact_order_items` để tính tỷ lệ chuyển đổi $\text{Conversion Rate} = \min\left(100\%, \frac{\text{Total Sold}}{\text{Views}} \times 100\%\right)$ và phân nhóm món ăn thành 4 góc phần tư (`Star`, `Plowhorse`, `Puzzle`, `Dog`).

### ❓ Câu hỏi 4: *"Tại sao khi xuất file CSV cho Excel em lại phải in ra chuỗi `\xEF\xBB\xBF` ở đầu file?"*
* **Trả lời chuẩn:**
  * Dạ `\xEF\xBB\xBF` là ký hiệu **UTF-8 Byte Order Mark (BOM)**. Nếu xuất file `.csv` bằng UTF-8 thuần mà không có 3 byte BOM này ở đầu file, phần mềm Microsoft Excel trên Windows sẽ đọc nhầm bảng mã ANSI/Windows-1252 làm vỡ toàn bộ dấu tiếng Việt (ví dụ *"Mì Cay Hải Sản"* bị lỗi ký tự). Thêm `\xEF\xBB\xBF` giúp file mở trực tiếp bằng Excel hiển thị tiếng Việt có dấu chuẩn 100%.
