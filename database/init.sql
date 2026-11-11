-- =========================================================================
-- DATABASE INITIALIZATION: HỆ THỐNG XÁC THỰC & QUẢN LÝ KHO (PHP + MYSQL)
-- Chuẩn utf8mb4_unicode_ci, hỗ trợ đầy đủ RBAC, Phân trang, Lịch sử giá & Kho
-- =========================================================================

CREATE DATABASE IF NOT EXISTS login_system
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;

USE login_system;

-- 1. BẢNG USERS (Theo đúng tài liệu PDF trang 18 & 19)
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    email VARCHAR(150) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) DEFAULT NULL,
    role VARCHAR(50) DEFAULT 'CASHIER',
    email_verified TINYINT(1) NOT NULL DEFAULT 0,
    verification_token VARCHAR(255) DEFAULT NULL,
    remember_token VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_username (username),
    INDEX idx_email (email),
    INDEX idx_verification_token (verification_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. HỆ THỐNG PHÂN QUYỀN RBAC (Theo DE_XUAT_MO_RONG.md)
CREATE TABLE IF NOT EXISTS roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    role_code VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    description VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    permission_code VARCHAR(100) NOT NULL UNIQUE,
    name VARCHAR(150) NOT NULL,
    module VARCHAR(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_roles (
    user_id INT NOT NULL,
    role_id INT NOT NULL,
    PRIMARY KEY (user_id, role_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS role_permissions (
    role_id INT NOT NULL,
    permission_id INT NOT NULL,
    PRIMARY KEY (role_id, permission_id),
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
    FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_permissions (
    user_id INT NOT NULL,
    permission_id INT NOT NULL,
    effect ENUM('allow', 'deny') NOT NULL DEFAULT 'allow',
    PRIMARY KEY (user_id, permission_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. DANH MỤC, KHO VÀ ĐƠN VỊ CƠ SỞ
CREATE TABLE IF NOT EXISTS units (
    id INT AUTO_INCREMENT PRIMARY KEY,
    unit_code VARCHAR(20) NOT NULL UNIQUE,
    name VARCHAR(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    display_order INT DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS warehouses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    warehouse_code VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(150) NOT NULL,
    address VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS warehouse_locations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    warehouse_id INT NOT NULL,
    location_code VARCHAR(50) NOT NULL,
    zone VARCHAR(50) NULL,
    rack VARCHAR(50) NULL,
    bin VARCHAR(50) NULL,
    UNIQUE KEY uk_warehouse_loc (warehouse_id, location_code),
    FOREIGN KEY (warehouse_id) REFERENCES warehouses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. SẢN PHẨM, LÔ VÀ LỊCH SỬ GIÁ
CREATE TABLE IF NOT EXISTS products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_id INT NOT NULL,
    sku VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(200) NOT NULL,
    unit_id INT NOT NULL,
    min_stock INT DEFAULT 10,
    status ENUM('ACTIVE', 'INACTIVE') DEFAULT 'ACTIVE',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_product_cat (category_id),
    INDEX idx_product_sku (sku),
    FOREIGN KEY (category_id) REFERENCES categories(id),
    FOREIGN KEY (unit_id) REFERENCES units(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS product_lots (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    lot_code VARCHAR(50) NOT NULL,
    expired_date DATE NULL,
    UNIQUE KEY uk_product_lot (product_id, lot_code),
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS product_prices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    price_type ENUM('purchase', 'sale') NOT NULL,
    amount DECIMAL(15, 2) NOT NULL,
    valid_from DATETIME NOT NULL,
    valid_to DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_price_lookup (product_id, price_type, valid_from, valid_to),
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. TỒN KHO VÀ SỔ BIẾN ĐỘNG (STOCK BALANCES & MOVEMENTS)
CREATE TABLE IF NOT EXISTS stock_balances (
    product_id INT NOT NULL,
    location_id INT NOT NULL,
    lot_id INT NOT NULL,
    quantity INT NOT NULL DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (product_id, location_id, lot_id),
    FOREIGN KEY (product_id) REFERENCES products(id),
    FOREIGN KEY (location_id) REFERENCES warehouse_locations(id),
    FOREIGN KEY (lot_id) REFERENCES product_lots(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. CHỨNG TỪ KHO VÀ CHI TIẾT DÒNG (INVENTORY DOCUMENTS & LINES)
CREATE TABLE IF NOT EXISTS inventory_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    document_code VARCHAR(50) NOT NULL UNIQUE,
    doc_type ENUM('IN', 'OUT') NOT NULL,
    doc_date DATE NOT NULL,
    partner_name VARCHAR(150) NULL,
    note TEXT NULL,
    status ENUM('DRAFT', 'POSTED', 'CANCELLED') NOT NULL DEFAULT 'DRAFT',
    created_by INT NOT NULL,
    posted_by INT NULL,
    posted_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id),
    FOREIGN KEY (posted_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inventory_lines (
    id INT AUTO_INCREMENT PRIMARY KEY,
    document_id INT NOT NULL,
    product_id INT NOT NULL,
    location_id INT NOT NULL,
    lot_id INT NOT NULL,
    unit_id INT NOT NULL,
    price_id INT NULL,
    snapshot_unit_price DECIMAL(15, 2) NOT NULL DEFAULT 0.00,
    quantity INT NOT NULL,
    subtotal DECIMAL(15, 2) NOT NULL DEFAULT 0.00,
    snapshot_product_name VARCHAR(200) NOT NULL,
    snapshot_sku VARCHAR(50) NOT NULL,
    snapshot_unit_name VARCHAR(50) NOT NULL,
    snapshot_location_name VARCHAR(100) NOT NULL,
    snapshot_lot_code VARCHAR(50) NOT NULL,
    FOREIGN KEY (document_id) REFERENCES inventory_documents(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id),
    FOREIGN KEY (location_id) REFERENCES warehouse_locations(id),
    FOREIGN KEY (lot_id) REFERENCES product_lots(id),
    FOREIGN KEY (unit_id) REFERENCES units(id),
    FOREIGN KEY (price_id) REFERENCES product_prices(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_movements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    line_id INT NOT NULL UNIQUE,
    document_id INT NOT NULL,
    product_id INT NOT NULL,
    location_id INT NOT NULL,
    lot_id INT NOT NULL,
    movement_type ENUM('IN', 'OUT') NOT NULL,
    quantity INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (line_id) REFERENCES inventory_lines(id),
    FOREIGN KEY (document_id) REFERENCES inventory_documents(id),
    FOREIGN KEY (product_id) REFERENCES products(id),
    FOREIGN KEY (location_id) REFERENCES warehouse_locations(id),
    FOREIGN KEY (lot_id) REFERENCES product_lots(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================================
-- DỮ LIỆU BAN ĐẦU (SEED DATA MẪU)
-- =========================================================================

-- 1. Users mẫu (Mật khẩu '123456' mã hóa SHA256: 8d969eef6ecad3c29a3a629280e686cf0c3f5d5a86aff3ca12020c923adc6c92)
INSERT INTO users (id, username, email, password_hash, email_verified) VALUES
(1, 'admin', 'admin@example.com', '8d969eef6ecad3c29a3a629280e686cf0c3f5d5a86aff3ca12020c923adc6c92', 1),
(2, 'creator', 'creator@example.com', '8d969eef6ecad3c29a3a629280e686cf0c3f5d5a86aff3ca12020c923adc6c92', 1),
(3, 'keeper', 'keeper@example.com', '8d969eef6ecad3c29a3a629280e686cf0c3f5d5a86aff3ca12020c923adc6c92', 1),
(4, 'viewer', 'viewer@example.com', '8d969eef6ecad3c29a3a629280e686cf0c3f5d5a86aff3ca12020c923adc6c92', 1);

-- 2. Nhóm quyền (Roles)
INSERT INTO roles (id, role_code, name, description) VALUES
(1, 'ADMIN', 'Quản trị hệ thống', 'Toàn quyền cấu hình và xem'),
(2, 'CREATOR', 'Người lập phiếu', 'Được tạo phiếu nháp nhập/xuất'),
(3, 'KEEPER', 'Thủ kho', 'Được ghi sổ phiếu kho và quản lý vị trí'),
(4, 'VIEWER', 'Người xem báo cáo', 'Chỉ xem dữ liệu và in ấn');

-- 3. Quyền (Permissions)
INSERT INTO permissions (id, permission_code, name, module) VALUES
(1, 'products.view', 'Xem danh sách sản phẩm', 'products'),
(2, 'products.manage', 'Thêm/sửa danh mục và sản phẩm', 'products'),
(3, 'prices.manage', 'Cấu hình lịch sử giá', 'prices'),
(4, 'documents.create', 'Lập phiếu nháp nhập/xuất', 'documents'),
(5, 'documents.post', 'Ghi sổ phiếu vào kho (Cập nhật tồn)', 'documents'),
(6, 'documents.cancel', 'Hủy phiếu nháp', 'documents'),
(7, 'documents.print', 'In phiếu kho chính thức', 'documents'),
(8, 'rbac.manage', 'Quản lý phân quyền', 'rbac');

-- Gán quyền cho nhóm
INSERT INTO role_permissions (role_id, permission_id) VALUES
-- Admin
(1, 1), (1, 2), (1, 3), (1, 4), (1, 5), (1, 6), (1, 7), (1, 8),
-- Creator
(2, 1), (2, 4), (2, 6), (2, 7),
-- Keeper
(3, 1), (3, 5), (3, 7),
-- Viewer
(4, 1), (4, 7);

-- Gán User vào nhóm
INSERT INTO user_roles (user_id, role_id) VALUES
(1, 1), (2, 2), (3, 3), (4, 4);

-- 4. Đơn vị cơ sở & Danh mục
INSERT INTO units (id, unit_code, name) VALUES
(1, 'CAI', 'Cái'),
(2, 'HOP', 'Hộp'),
(3, 'KG', 'Kilogram');

INSERT INTO categories (id, name, display_order) VALUES
(1, 'Thiết bị điện tử', 1),
(2, 'Linh kiện máy tính', 2),
(3, 'Phụ kiện văn phòng', 3);

-- 5. Kho & Vị trí
INSERT INTO warehouses (id, warehouse_code, name, address) VALUES
(1, 'KHO-TONG', 'Kho Tổng Miền Bắc', 'Số 1 Đại Cồ Việt, Hà Nội'),
(2, 'KHO-PHU', 'Kho Phụ Tây Hồ', 'Số 99 Lạc Long Quân, Hà Nội');

INSERT INTO warehouse_locations (id, warehouse_id, location_code, zone, rack, bin) VALUES
(1, 1, 'KT-A1-01', 'Khu A', 'Kệ 1', 'Ô 01'),
(2, 1, 'KT-A1-02', 'Khu A', 'Kệ 1', 'Ô 02'),
(3, 2, 'KP-B1-01', 'Khu B', 'Kệ 1', 'Ô 01'),
(4, 2, 'KP-B1-02', 'Khu B', 'Kệ 1', 'Ô 02');

-- 6. Tạo 35 sản phẩm mẫu cho bài tập phân trang thật (Bài A)
INSERT INTO products (id, category_id, sku, name, unit_id, min_stock, status) VALUES
(1, 1, 'SKU-DT-01', 'Điện thoại thông minh Pro Max 256GB', 1, 10, 'ACTIVE'),
(2, 1, 'SKU-DT-02', 'Máy tính bảng Tablet Air 11 inch', 1, 5, 'ACTIVE'),
(3, 1, 'SKU-DT-03', 'Đồng hồ thông minh SmartWatch Sport', 1, 15, 'ACTIVE'),
(4, 1, 'SKU-DT-04', 'Tai nghe chống ồn không dây TWS', 1, 20, 'ACTIVE'),
(5, 1, 'SKU-DT-05', 'Loa Bluetooth siêu trầm công suất 40W', 1, 8, 'ACTIVE'),
(6, 1, 'SKU-DT-06', 'Camera an ninh Wifi 360 độ ban đêm', 1, 12, 'ACTIVE'),
(7, 1, 'SKU-DT-07', 'Tivi thông minh QLED 55 inch 4K', 1, 4, 'ACTIVE'),
(8, 1, 'SKU-DT-08', 'Máy hút bụi không dây pin sạc', 1, 6, 'ACTIVE'),
(9, 1, 'SKU-DT-09', 'Nồi chiên không dầu điện tử 6L', 1, 10, 'ACTIVE'),
(10, 1, 'SKU-DT-10', 'Máy lọc không khí phòng ngủ ion âm', 1, 5, 'ACTIVE'),
(11, 1, 'SKU-DT-11', 'Bàn phím cơ Bluetooth RGB Red Switch', 1, 25, 'ACTIVE'),
(12, 1, 'SKU-DT-12', 'Chuột không dây công thái học siêu êm', 1, 30, 'ACTIVE'),

(13, 2, 'SKU-LK-01', 'Bộ vi xử lý Core i7 14700K 20 Cores', 1, 10, 'ACTIVE'),
(14, 2, 'SKU-LK-02', 'Bo mạch chủ Gaming Z790 DDR5 Wifi', 1, 8, 'ACTIVE'),
(15, 2, 'SKU-LK-03', 'Bộ nhớ RAM 32GB (2x16GB) Bus 6000MHz', 2, 15, 'ACTIVE'),
(16, 2, 'SKU-LK-04', 'Ổ cứng SSD M.2 NVMe Gen4 1TB tốc độ cao', 1, 20, 'ACTIVE'),
(17, 2, 'SKU-LK-05', 'Card màn hình đồ họa RTX 4070 Super 12GB', 1, 5, 'ACTIVE'),
(18, 2, 'SKU-LK-06', 'Nguồn máy tính công suất thực 850W Gold', 1, 10, 'ACTIVE'),
(19, 2, 'SKU-LK-07', 'Tản nhiệt nước AIO 360mm màn hình LCD', 1, 7, 'ACTIVE'),
(20, 2, 'SKU-LK-08', 'Vỏ case máy tính mặt kính cường lực', 1, 12, 'ACTIVE'),
(21, 2, 'SKU-LK-09', 'Quạt tản nhiệt case 120mm PWM RGB', 1, 50, 'ACTIVE'),
(22, 2, 'SKU-LK-10', 'Keo tản nhiệt gốm dẫn nhiệt cao cấp 4g', 1, 40, 'ACTIVE'),
(23, 2, 'SKU-LK-11', 'Card mạng Wifi 6E PCI-E kèm Bluetooth 5.3', 1, 15, 'ACTIVE'),
(24, 2, 'SKU-LK-12', 'Dây cáp nguồn nối dài bọc lưới màu trắng', 2, 20, 'ACTIVE'),

(25, 3, 'SKU-VP-01', 'Giấy in văn phòng A4 Double A 70gsm', 2, 50, 'ACTIVE'),
(26, 3, 'SKU-VP-02', 'Bút bi gel nước ngòi 0.5mm xanh mực', 2, 100, 'ACTIVE'),
(27, 3, 'SKU-VP-03', 'Kẹp bướm giữ giấy tài liệu hộp 12 cái', 2, 60, 'ACTIVE'),
(28, 3, 'SKU-VP-04', 'File kẹp còng đựng hồ sơ khổ A4 7cm', 1, 30, 'ACTIVE'),
(29, 3, 'SKU-VP-05', 'Sổ tay bìa da cao cấp kẻ ngang 200 trang', 1, 25, 'ACTIVE'),
(30, 3, 'SKU-VP-06', 'Dập ghim đại trợ lực kèm hộp ghim số 10', 1, 20, 'ACTIVE'),
(31, 3, 'SKU-VP-07', 'Kéo cắt giấy bọc nhựa tay cầm chống đau', 1, 35, 'ACTIVE'),
(32, 3, 'SKU-VP-08', 'Băng dính dán thùng đóng hàng trong suốt', 1, 80, 'ACTIVE'),
(33, 3, 'SKU-VP-09', 'Máy tính bấm tay kế toán 12 chữ số', 1, 15, 'ACTIVE'),
(34, 3, 'SKU-VP-10', 'Bảng từ trắng treo tường kích thước 80x120', 1, 5, 'ACTIVE'),
(35, 3, 'SKU-VP-11', 'Bút dạ viết bảng trắng xoá sạch không lem', 2, 40, 'ACTIVE');

-- 7. Lô mặc định NOLOT cho 35 sản phẩm
INSERT INTO product_lots (id, product_id, lot_code, expired_date)
SELECT id, id, 'NOLOT', NULL FROM products;

-- Thêm 1 lô có hạn dùng cho sản phẩm 1 (để thử nghiệm Bài C & Bài D)
INSERT INTO product_lots (id, product_id, lot_code, expired_date) VALUES
(36, 1, 'LOT-2026-12', '2026-12-31');

-- 8. Lịch sử giá cho sản phẩm 1 & 2
INSERT INTO product_prices (product_id, price_type, amount, valid_from, valid_to) VALUES
(1, 'purchase', 20000000.00, '2026-01-01 00:00:00', NULL),
(1, 'sale', 25000000.00, '2026-01-01 00:00:00', '2026-06-30 23:59:59'),
(1, 'sale', 24000000.00, '2026-07-01 00:00:00', NULL),
(2, 'purchase', 12000000.00, '2026-01-01 00:00:00', NULL),
(2, 'sale', 15000000.00, '2026-01-01 00:00:00', NULL);

-- =========================================================================
-- 9. NHÓM BẢNG PHÂN TÍCH OLAP (STAR SCHEMA - THÀNH VIÊN 4: DATA PIPELINE)
-- Phục vụ Data Warehouse, BI Dashboard, Phân tích SLA Bếp & Khai phá Apriori
-- =========================================================================

-- Dim Time: Chiều thời gian phân tích theo giờ (YYYYMMDDHH)
CREATE TABLE IF NOT EXISTS dim_time (
    time_key INT PRIMARY KEY,
    full_date DATE NOT NULL,
    hour INT NOT NULL,
    day_of_week INT NOT NULL,
    is_weekend TINYINT(1) NOT NULL DEFAULT 0,
    INDEX idx_dim_time_date (full_date),
    INDEX idx_dim_time_hour (hour)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dim Menu Item: Chiều thực đơn đồng bộ từ OLTP
CREATE TABLE IF NOT EXISTS dim_menu_item (
    item_key INT AUTO_INCREMENT PRIMARY KEY,
    oltp_item_id INT UNIQUE NOT NULL,
    item_name VARCHAR(150) NOT NULL,
    category_name VARCHAR(100) NOT NULL,
    current_price DECIMAL(12, 2) NOT NULL,
    INDEX idx_dim_menu_oltp (oltp_item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dim Chef: Chiều đầu bếp xử lý món
CREATE TABLE IF NOT EXISTS dim_chef (
    chef_key INT AUTO_INCREMENT PRIMARY KEY,
    chef_username VARCHAR(50) UNIQUE NOT NULL,
    chef_full_name VARCHAR(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Fact Order Items: Bảng sự kiện chi tiết món ăn đã chốt đơn/chế biến
CREATE TABLE IF NOT EXISTS fact_order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    time_key INT NOT NULL,
    item_key INT NOT NULL,
    chef_key INT NULL,
    session_id VARCHAR(64) NOT NULL,
    quantity INT NOT NULL,
    revenue DECIMAL(12, 2) NOT NULL,
    prep_time_seconds INT NOT NULL DEFAULT 0,
    is_sla_violated TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_fact_sess (session_id),
    INDEX idx_fact_item (item_key),
    INDEX idx_fact_time (time_key),
    INDEX idx_fact_chef (chef_key),
    INDEX idx_fact_sla (is_sla_violated),
    FOREIGN KEY (time_key) REFERENCES dim_time(time_key) ON DELETE CASCADE,
    FOREIGN KEY (item_key) REFERENCES dim_menu_item(item_key) ON DELETE CASCADE,
    FOREIGN KEY (chef_key) REFERENCES dim_chef(chef_key) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Fact Clickstream: Bảng sự kiện hành vi khách hàng trên Menu QR
CREATE TABLE IF NOT EXISTS fact_clickstream (
    id INT AUTO_INCREMENT PRIMARY KEY,
    time_key INT NOT NULL,
    session_id VARCHAR(64) NOT NULL,
    oltp_item_id INT NOT NULL,
    event_type VARCHAR(50) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_click_sess (session_id),
    INDEX idx_click_time (time_key),
    INDEX idx_click_event (event_type),
    FOREIGN KEY (time_key) REFERENCES dim_time(time_key) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================================
-- DỮ LIỆU MẪU BAN ĐẦU CHO KHO OLAP STAR SCHEMA
-- =========================================================================

-- 10. Đầu bếp mẫu trong kho OLAP
INSERT INTO dim_chef (chef_key, chef_username, chef_full_name) VALUES
(1, 'chef_hung', 'Bếp Trưởng Hùng'),
(2, 'chef_lan', 'Đầu Bếp Lan')
ON DUPLICATE KEY UPDATE chef_full_name = VALUES(chef_full_name);

-- 11. Món ăn mẫu trong kho OLAP
INSERT INTO dim_menu_item (item_key, oltp_item_id, item_name, category_name, current_price) VALUES
(1, 1, 'Mì Cay Hải Sản Cấp Độ 7', 'Món Chính', 65000.00),
(2, 2, 'Lẩu Thái Tomyum Bò Mỹ', 'Món Chính', 189000.00),
(3, 3, 'Cơm Trộn Hàn Quốc Bibimbap', 'Món Chính', 55000.00),
(4, 4, 'Mì Ý Sốt Bò Bằm Bolognaise', 'Món Chính', 59000.00),
(5, 5, 'Khoai Tây Chiên Lắc Phô Mai', 'Món Ăn Kèm', 35000.00),
(6, 6, 'Gà Rán Sốt Cay Hàn Quốc (4 miếng)', 'Món Ăn Kèm', 69000.00),
(7, 7, 'Kim Chi Cải Thảo Truyền Thống', 'Món Ăn Kèm', 15000.00),
(8, 8, 'Trà Đào Cam Sả Tươi', 'Đồ Uống', 32000.00),
(9, 9, 'Trà Sữa Trân Châu Hoàng Kim', 'Đồ Uống', 35000.00),
(10, 10, 'Coca-Cola Lon Lạnh', 'Đồ Uống', 18000.00)
ON DUPLICATE KEY UPDATE item_name = VALUES(item_name), current_price = VALUES(current_price);

-- 12. Chiều thời gian mẫu (Khung giờ gần nhất)
INSERT INTO dim_time (time_key, full_date, hour, day_of_week, is_weekend) VALUES
(2026111201, '2026-11-12', 1, 4, 0),
(2026111202, '2026-11-12', 2, 4, 0),
(2026111211, '2026-11-12', 11, 4, 0),
(2026111212, '2026-11-12', 12, 4, 0),
(2026111218, '2026-11-12', 18, 4, 0),
(2026111219, '2026-11-12', 19, 4, 0)
ON DUPLICATE KEY UPDATE hour = VALUES(hour);

-- 13. Sự kiện chi tiết hóa đơn mẫu (Fact Order Items) với SLA (>900s)
INSERT INTO fact_order_items (id, time_key, item_key, chef_key, session_id, quantity, revenue, prep_time_seconds, is_sla_violated) VALUES
(1, 2026111212, 1, 1, 'init_sess_001', 2, 130000.00, 480, 0),
(2, 2026111212, 8, 1, 'init_sess_001', 2, 64000.00, 240, 0),
(3, 2026111212, 5, 2, 'init_sess_001', 1, 35000.00, 360, 0),
(4, 2026111212, 2, 1, 'init_sess_002', 1, 189000.00, 960, 1),
(5, 2026111212, 6, 2, 'init_sess_002', 1, 69000.00, 520, 0),
(6, 2026111218, 1, 1, 'init_sess_003', 1, 65000.00, 420, 0),
(7, 2026111218, 8, 2, 'init_sess_003', 1, 32000.00, 180, 0),
(8, 2026111219, 3, 2, 'init_sess_004', 2, 110000.00, 1020, 1),
(9, 2026111219, 10, 1, 'init_sess_004', 2, 36000.00, 120, 0)
ON DUPLICATE KEY UPDATE quantity = VALUES(quantity);

-- 14. Sự kiện Clickstream mẫu (Fact Clickstream)
INSERT INTO fact_clickstream (id, time_key, session_id, oltp_item_id, event_type) VALUES
(1, 2026111212, 'init_sess_001', 1, 'VIEW_ITEM'),
(2, 2026111212, 'init_sess_001', 1, 'ADD_TO_CART'),
(3, 2026111212, 'init_sess_001', 8, 'VIEW_ITEM'),
(4, 2026111212, 'init_sess_001', 8, 'ADD_TO_CART'),
(5, 2026111218, 'init_sess_003', 1, 'VIEW_ITEM'),
(6, 2026111218, 'init_sess_003', 8, 'VIEW_ITEM')
ON DUPLICATE KEY UPDATE event_type = VALUES(event_type);
