-- =========================================================================
-- DATABASE INITIALIZATION: HỆ THỐNG NHÀ HÀNG QRDINE - DINEFLOW (PHP + MYSQL)
-- Chuẩn utf8mb4_unicode_ci: Gọi món QR tại bàn, Giỏ hàng chung, Bếp KDS,
-- Thu ngân POS, Phân quyền RBAC & Kho dữ liệu phân tích OLAP Star Schema
-- =========================================================================

SET NAMES utf8mb4;
SET CHARACTER SET utf8mb4;

CREATE DATABASE IF NOT EXISTS login_system
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;

USE login_system;

-- 1. BẢNG USERS (Tài khoản nhân sự & quản trị nhà hàng DineFlow)
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

-- 2. HỆ THỐNG PHÂN QUYỀN RBAC NHÀ HÀNG DINEFLOW
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

CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    actor_id INT NULL,
    action VARCHAR(64) NOT NULL,
    entity_type VARCHAR(64) NOT NULL,
    entity_id VARCHAR(64) NOT NULL,
    old_value JSON NULL,
    new_value JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_actor_time (actor_id, created_at),
    INDEX idx_audit_entity (entity_type, entity_id),
    FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. ĐƠN VỊ TÍNH, DANH MỤC THỰC ĐƠN & MÓN ĂN NHÀ HÀNG
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

-- =========================================================================
-- DỮ LIỆU BAN ĐẦU CHO NHÂN SỰ, PHÂN QUYỀN & THỰC ĐƠN 35 MÓN QRDINE
-- =========================================================================

-- 1. Users mẫu (Mật khẩu 'Admin@123' / '123456')
INSERT INTO users (id, username, email, password_hash, full_name, role, email_verified) VALUES
(1, 'admin', 'admin@dineflow.vn', 'e86f78a8a3caf0b60d8e74e5942aa6d86dc150cd3c03338aef25b7d2d7e3acc7', 'Quản trị viên DineFlow', 'ADMIN', 1),
(2, 'creator', 'creator@dineflow.vn', '8d969eef6ecad3c29a3a629280e686cf0c3f5d5a86aff3ca12020c923adc6c92', 'Nhân viên Order Bàn', 'CREATOR', 1),
(3, 'keeper', 'keeper@dineflow.vn', '8d969eef6ecad3c29a3a629280e686cf0c3f5d5a86aff3ca12020c923adc6c92', 'Bếp trưởng KDS', 'KEEPER', 1),
(4, 'viewer', 'viewer@dineflow.vn', '8d969eef6ecad3c29a3a629280e686cf0c3f5d5a86aff3ca12020c923adc6c92', 'Thu ngân POS', 'VIEWER', 1),
(5, 'admin_example', 'admin@example.com', '8d969eef6ecad3c29a3a629280e686cf0c3f5d5a86aff3ca12020c923adc6c92', 'Quản lý Hệ thống', 'ADMIN', 1),
(6, 'manager', 'manager@dineflow.vn', 'e8392925a98c9c22795d1fc5d0dfee5b9a6943f6b768ec5a2a0c077e5ed119cf', 'Quản lý Ca trực', 'CREATOR', 1),
(7, 'staff', 'staff@dineflow.vn', 'dfd48f36338aa36228ebb9e204bba6b4e18db0b623e25c458901edc831fb18e9', 'Nhân viên Phục vụ', 'CREATOR', 1),
(8, 'chef', 'chef@dineflow.vn', 'd166a2682ff2efc04f40c0a0ce8f67a4d2dd76a70d24c45bd6537a5e1da19761', 'Đầu bếp Điều phối', 'KEEPER', 1),
(9, 'cashier', 'cashier@dineflow.vn', 'b827b2184e9e049bb3f349eb4337a25cb1339ef617ad4d1e9e7899f140ffa5e9', 'Thu ngân Quầy', 'VIEWER', 1)
ON DUPLICATE KEY UPDATE email = VALUES(email), password_hash = VALUES(password_hash), full_name = VALUES(full_name), email_verified = VALUES(email_verified);

-- 2. Nhóm quyền (Roles)
INSERT INTO roles (id, role_code, name, description) VALUES
(1, 'ADMIN', 'Quản trị hệ thống', 'Toàn quyền cấu hình nhà hàng, bàn ăn và thực đơn'),
(2, 'CREATOR', 'Nhân viên phục vụ / Order', 'Mở bàn QR và theo dõi trạng thái món'),
(3, 'KEEPER', 'Bếp trưởng / Điều phối KDS', 'Điều phối chế biến món trên KDS và xác nhận hoàn tất'),
(4, 'VIEWER', 'Thu ngân / Xem báo cáo', 'Xem doanh thu, tra cứu thực đơn và in hóa đơn thanh toán')
ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description);

-- 3. Quyền (Permissions)
INSERT INTO permissions (id, permission_code, name, module) VALUES
(1, 'products.view', 'Xem danh sách thực đơn món ăn', 'products'),
(2, 'products.manage', 'Thêm/sửa danh mục và món ăn', 'products'),
(3, 'prices.manage', 'Cấu hình giá niêm yết món ăn', 'prices'),
(4, 'documents.create', 'Lập hóa đơn thanh toán tạm tính', 'documents'),
(5, 'documents.post', 'Xác nhận thanh toán và chốt hóa đơn', 'documents'),
(6, 'documents.cancel', 'Hủy hóa đơn tạm tính', 'documents'),
(7, 'documents.print', 'Xem và in hóa đơn thanh toán (@media print)', 'documents'),
(8, 'rbac.manage', 'Quản lý phân quyền nhân sự', 'rbac'),
(9, 'tables.view', 'Xem trạng thái các bàn', 'tables'),
(10, 'tables.open', 'Mở phiên phục vụ cho bàn', 'tables'),
(11, 'tables.manage', 'Tạo và quản lý bàn ăn', 'tables'),
(12, 'kds.view', 'Xem trạng thái món trên KDS', 'kds'),
(13, 'kds.update', 'Cập nhật trạng thái món trên KDS', 'kds'),
(14, 'pos.view', 'Xem bàn và hóa đơn trên POS', 'pos'),
(15, 'pos.checkout', 'Xác nhận thanh toán và đóng phiên qua POS', 'pos'),
(16, 'reports.view', 'Xem dashboard và báo cáo', 'reports'),
(17, 'data.manage', 'Quản lý ETL, Live Ops và quy tắc gợi ý', 'data')
ON DUPLICATE KEY UPDATE name = VALUES(name), module = VALUES(module);

-- Đồng bộ ma trận quyền mặc định đã chốt cho bốn role hệ thống.
DELETE FROM role_permissions WHERE role_id IN (1, 2, 3, 4);

INSERT INTO role_permissions (role_id, permission_id) VALUES
(1, 1), (1, 2), (1, 3), (1, 4), (1, 5), (1, 6), (1, 7), (1, 8),
(1, 9), (1, 10), (1, 11), (1, 12), (1, 13), (1, 14), (1, 15), (1, 16), (1, 17),
(2, 1), (2, 9), (2, 10), (2, 12),
(3, 12), (3, 13),
(4, 7), (4, 14), (4, 15), (4, 16)
ON DUPLICATE KEY UPDATE permission_id = VALUES(permission_id);

-- Gán User vào nhóm
INSERT INTO user_roles (user_id, role_id) VALUES
(1, 1), (2, 2), (3, 3), (4, 4),
(5, 1), (6, 2), (7, 2), (8, 3), (9, 4)
ON DUPLICATE KEY UPDATE role_id = VALUES(role_id);

-- 4. Đơn vị món ăn & 4 Danh mục Thực đơn Nhà hàng DineFlow
INSERT INTO units (id, unit_code, name) VALUES
(1, 'CAI', 'Phần'),
(2, 'HOP', 'Tô / Nồi'),
(3, 'KG', 'Ly / Cốc')
ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT INTO categories (id, name, display_order) VALUES
(1, 'Món Chính', 1),
(2, 'Món Ăn Kèm', 2),
(3, 'Đồ Uống & Tráng Miệng', 3),
(4, 'Combo Tiết Kiệm', 4)
ON DUPLICATE KEY UPDATE name = VALUES(name), display_order = VALUES(display_order);

-- 5. Danh sách 35 Món ăn Thực đơn Nhà hàng DineFlow (DISH-001 .. DISH-035)
INSERT INTO products (id, category_id, sku, name, unit_id, min_stock, status) VALUES
(1, 1, 'DISH-001', 'Mì Cay Hải Sản Cấp Độ 7', 2, 10, 'ACTIVE'),
(2, 1, 'DISH-002', 'Lẩu Thái Tomyum Bò Mỹ', 2, 5, 'ACTIVE'),
(3, 1, 'DISH-003', 'Cơm Trộn Hàn Quốc Bibimbap', 1, 15, 'ACTIVE'),
(4, 1, 'DISH-004', 'Mì Ý Sốt Bò Bằm Bolognaise', 1, 20, 'ACTIVE'),
(5, 2, 'DISH-005', 'Khoai Tây Chiên Lắc Phô Mai', 1, 15, 'ACTIVE'),
(6, 2, 'DISH-006', 'Gà Rán Sốt Cay Hàn Quốc (4 miếng)', 1, 12, 'ACTIVE'),
(7, 2, 'DISH-007', 'Kim Chi Cải Thảo Truyền Thống', 1, 20, 'ACTIVE'),
(8, 3, 'DISH-008', 'Trà Đào Cam Sả Tươi', 3, 25, 'ACTIVE'),
(9, 3, 'DISH-009', 'Trà Sữa Trân Châu Hoàng Kim', 3, 25, 'ACTIVE'),
(10, 3, 'DISH-010', 'Coca-Cola Lon Lạnh', 3, 30, 'ACTIVE'),
(11, 1, 'DISH-011', 'Cơm Bò Xào Nấm Kim Châm', 1, 15, 'ACTIVE'),
(12, 1, 'DISH-012', 'Lẩu Kim Chi Hải Sản Hàn Quốc', 2, 8, 'ACTIVE'),
(13, 2, 'DISH-013', 'Nem Chua Rán Hà Nội (8 cái)', 1, 20, 'ACTIVE'),
(14, 2, 'DISH-014', 'Phô Mai Que Kéo Sợi (5 cái)', 1, 20, 'ACTIVE'),
(15, 3, 'DISH-015', 'Nước Ép Dưa Hấu Tươi', 3, 20, 'ACTIVE'),
(16, 3, 'DISH-016', 'Trà Vải Hoa Hồng Nhiệt Đới', 3, 20, 'ACTIVE'),
(17, 1, 'DISH-017', 'Mì Udon Xào Bò Nhật Bản', 1, 15, 'ACTIVE'),
(18, 1, 'DISH-018', 'Cơm Rang Dưa Bò Truyền Thống', 1, 15, 'ACTIVE'),
(19, 2, 'DISH-019', 'Bánh Bạch Tuộc Takoyaki (6 viên)', 1, 15, 'ACTIVE'),
(20, 2, 'DISH-020', 'Xúc Xích Đức Nướng Than Hoa', 1, 20, 'ACTIVE'),
(21, 3, 'DISH-021', 'Trà Chanh Giã Tay Quảng Đông', 3, 25, 'ACTIVE'),
(22, 3, 'DISH-022', 'Sinh Tố Bơ Sáp Dừa Tươi', 3, 15, 'ACTIVE'),
(23, 1, 'DISH-023', 'Bún Đậu Mắm Tôm Thập Cẩm', 1, 15, 'ACTIVE'),
(24, 1, 'DISH-024', 'Bò Bít Tết Sốt Tiêu Đen Kèm Bánh Mì', 1, 10, 'ACTIVE'),
(25, 2, 'DISH-025', 'Bánh Mì Bơ Tỏi Nướng Giòn', 1, 25, 'ACTIVE'),
(26, 2, 'DISH-026', 'Salad Cà Chua Dưa Leo Dầu Giấm', 1, 20, 'ACTIVE'),
(27, 3, 'DISH-027', 'Cà Phê Sữa Đá Phin Truyền Thống', 3, 30, 'ACTIVE'),
(28, 3, 'DISH-028', 'Trà Xoài Macchiato Kem Muối', 3, 20, 'ACTIVE'),
(29, 1, 'DISH-029', 'Súp Gà Ngô Non Trứng Cút', 2, 15, 'ACTIVE'),
(30, 1, 'DISH-030', 'Cháo Ếch Singapore Nồi Đất', 2, 12, 'ACTIVE'),
(31, 2, 'DISH-031', 'Ngô Ngọt Chiên Bơ Giòn', 1, 20, 'ACTIVE'),
(32, 2, 'DISH-032', 'Bánh Gối Nhân Thịt Miến (3 cái)', 1, 20, 'ACTIVE'),
(33, 3, 'DISH-033', 'Chè Dừa Dầm Hải Phòng', 3, 20, 'ACTIVE'),
(34, 3, 'DISH-034', 'Tàu Hũ Trân Châu Đường Đen', 3, 20, 'ACTIVE'),
(35, 4, 'DISH-035', 'Combo Lẩu Nướng Thần Thánh 4 Người', 2, 5, 'ACTIVE')
ON DUPLICATE KEY UPDATE category_id = VALUES(category_id), sku = VALUES(sku), name = VALUES(name), unit_id = VALUES(unit_id), status = VALUES(status);

-- =========================================================================
-- 6. NHÓM BẢNG PHÂN TÍCH OLAP (STAR SCHEMA - THÀNH VIÊN 4: DATA PIPELINE)
-- Phục vụ BI Dashboard, Phân tích SLA Bếp & Khai phá Luật kết hợp Apriori
-- =========================================================================

CREATE TABLE IF NOT EXISTS dim_time (
    time_key INT PRIMARY KEY,
    full_date DATE NOT NULL,
    hour INT NOT NULL,
    day_of_week INT NOT NULL,
    is_weekend TINYINT(1) NOT NULL DEFAULT 0,
    INDEX idx_dim_time_date (full_date),
    INDEX idx_dim_time_hour (hour)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dim_menu_item (
    item_key INT AUTO_INCREMENT PRIMARY KEY,
    oltp_item_id INT UNIQUE NOT NULL,
    item_name VARCHAR(150) NOT NULL,
    category_name VARCHAR(100) NOT NULL,
    current_price DECIMAL(12, 2) NOT NULL,
    INDEX idx_dim_menu_oltp (oltp_item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dim_chef (
    chef_key INT AUTO_INCREMENT PRIMARY KEY,
    chef_username VARCHAR(50) UNIQUE NOT NULL,
    chef_full_name VARCHAR(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

-- Đầu bếp mẫu trong Star Schema OLAP
INSERT INTO dim_chef (chef_key, chef_username, chef_full_name) VALUES
(1, 'chef_hung', 'Bếp Trưởng Hùng'),
(2, 'chef_lan', 'Đầu Bếp Lan')
ON DUPLICATE KEY UPDATE chef_full_name = VALUES(chef_full_name);

-- Món ăn mẫu trong Star Schema OLAP
INSERT INTO dim_menu_item (item_key, oltp_item_id, item_name, category_name, current_price) VALUES
(1, 1, 'Mì Cay Hải Sản Cấp Độ 7', 'Món Chính', 65000.00),
(2, 2, 'Lẩu Thái Tomyum Bò Mỹ', 'Món Chính', 189000.00),
(3, 3, 'Cơm Trộn Hàn Quốc Bibimbap', 'Món Chính', 55000.00),
(4, 4, 'Mì Ý Sốt Bò Bằm Bolognaise', 'Món Chính', 59000.00),
(5, 5, 'Khoai Tây Chiên Lắc Phô Mai', 'Món Ăn Kèm', 35000.00),
(6, 6, 'Gà Rán Sốt Cay Hàn Quốc (4 miếng)', 'Món Ăn Kèm', 69000.00),
(7, 7, 'Kim Chi Cải Thảo Truyền Thống', 'Món Ăn Kèm', 15000.00),
(8, 8, 'Trà Đào Cam Sả Tươi', 'Đồ Uống & Tráng Miệng', 32000.00),
(9, 9, 'Trà Sữa Trân Châu Hoàng Kim', 'Đồ Uống & Tráng Miệng', 35000.00),
(10, 10, 'Coca-Cola Lon Lạnh', 'Đồ Uống & Tráng Miệng', 18000.00)
ON DUPLICATE KEY UPDATE item_name = VALUES(item_name), category_name = VALUES(category_name), current_price = VALUES(current_price);

-- Chiều thời gian mẫu
INSERT INTO dim_time (time_key, full_date, hour, day_of_week, is_weekend) VALUES
(2026111201, '2026-11-12', 1, 4, 0),
(2026111202, '2026-11-12', 2, 4, 0),
(2026111211, '2026-11-12', 11, 4, 0),
(2026111212, '2026-11-12', 12, 4, 0),
(2026111218, '2026-11-12', 18, 4, 0),
(2026111219, '2026-11-12', 19, 4, 0)
ON DUPLICATE KEY UPDATE hour = VALUES(hour);

-- Sự kiện chi tiết hóa đơn mẫu (Fact Order Items) với SLA (>900s)
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

-- Sự kiện Clickstream mẫu (Fact Clickstream)
INSERT INTO fact_clickstream (id, time_key, session_id, oltp_item_id, event_type) VALUES
(1, 2026111212, 'init_sess_001', 1, 'VIEW_ITEM'),
(2, 2026111212, 'init_sess_001', 1, 'ADD_TO_CART'),
(3, 2026111212, 'init_sess_001', 8, 'VIEW_ITEM'),
(4, 2026111212, 'init_sess_001', 8, 'ADD_TO_CART'),
(5, 2026111218, 'init_sess_003', 1, 'VIEW_ITEM'),
(6, 2026111218, 'init_sess_003', 8, 'VIEW_ITEM')
ON DUPLICATE KEY UPDATE event_type = VALUES(event_type);

-- =========================================================================
-- 7. BẢNG VẬN HÀNH NHÀ HÀNG QRDINE - DINEFLOW (OLTP)
-- Phục vụ Quản lý Bàn QR, Menu Khách, Giỏ hàng chung, Bếp KDS và Thu ngân POS
-- =========================================================================

CREATE TABLE IF NOT EXISTS dining_tables (
    id INT AUTO_INCREMENT PRIMARY KEY,
    table_number VARCHAR(20) UNIQUE NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'AVAILABLE',
    current_session_id VARCHAR(64) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS table_sessions (
    id VARCHAR(64) PRIMARY KEY,
    table_id INT NOT NULL,
    session_token VARCHAR(64) UNIQUE NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
    guest_count INT DEFAULT 1,
    total_amount DECIMAL(12, 2) DEFAULT 0.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    closed_at TIMESTAMP NULL,
    is_etl_processed TINYINT(1) DEFAULT 0,
    INDEX idx_table_sess (table_id),
    INDEX idx_token_sess (session_token),
    FOREIGN KEY (table_id) REFERENCES dining_tables(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS menu_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_id INT NOT NULL,
    name VARCHAR(150) NOT NULL,
    description TEXT NULL,
    price DECIMAL(12, 2) NOT NULL,
    is_available TINYINT(1) DEFAULT 1,
    is_hot TINYINT(1) DEFAULT 0,
    image_url VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_menu_cat (category_id),
    FOREIGN KEY (category_id) REFERENCES categories(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS session_orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_id VARCHAR(64) NOT NULL,
    idempotency_key VARCHAR(64) UNIQUE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_order_sess (session_id),
    INDEX idx_order_idem (idempotency_key),
    FOREIGN KEY (session_id) REFERENCES table_sessions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_items_kitchen (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    session_id VARCHAR(64) NOT NULL,
    menu_item_id INT NOT NULL,
    quantity INT NOT NULL DEFAULT 1,
    item_price DECIMAL(12, 2) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'PENDING',
    notes TEXT NULL,
    chef_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL,
    prep_time_seconds INT NULL,
    is_sla_violated TINYINT(1) DEFAULT 0,
    INDEX idx_kds_sess (session_id),
    INDEX idx_kds_status (status),
    FOREIGN KEY (order_id) REFERENCES session_orders(id) ON DELETE CASCADE,
    FOREIGN KEY (session_id) REFERENCES table_sessions(id) ON DELETE CASCADE,
    FOREIGN KEY (menu_item_id) REFERENCES menu_items(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dữ liệu 15 bàn ăn mẫu
INSERT INTO dining_tables (id, table_number, status, current_session_id) VALUES
(1, 'Bàn 01', 'OCCUPIED', 'init_sess_001'),
(2, 'Bàn 02', 'OCCUPIED', 'init_sess_002'),
(3, 'Bàn 03', 'AVAILABLE', NULL),
(4, 'Bàn 04', 'AVAILABLE', NULL),
(5, 'Bàn 05', 'OCCUPIED', 'init_sess_003'),
(6, 'Bàn 06', 'AVAILABLE', NULL),
(7, 'Bàn 07', 'AVAILABLE', NULL),
(8, 'Bàn 08', 'AVAILABLE', NULL),
(9, 'Bàn 09', 'AVAILABLE', NULL),
(10, 'Bàn 10', 'AVAILABLE', NULL),
(11, 'Bàn 11', 'AVAILABLE', NULL),
(12, 'Bàn 12', 'AVAILABLE', NULL),
(13, 'Bàn 13', 'AVAILABLE', NULL),
(14, 'Bàn 14', 'AVAILABLE', NULL),
(15, 'Bàn 15', 'AVAILABLE', NULL)
ON DUPLICATE KEY UPDATE table_number = VALUES(table_number);

-- Dữ liệu phiên bàn mẫu
INSERT INTO table_sessions (id, table_id, session_token, status, guest_count, total_amount, is_etl_processed) VALUES
('init_sess_001', 1, 'tbl-01-sess', 'ACTIVE', 3, 229000.00, 1),
('init_sess_002', 2, 'tbl-02-sess', 'ACTIVE', 2, 258000.00, 1),
('init_sess_003', 5, 'tbl-05-sess', 'ACTIVE', 4, 97000.00, 1)
ON DUPLICATE KEY UPDATE status = VALUES(status);

-- Dữ liệu 35 món ăn thực đơn nhà hàng DineFlow
INSERT INTO menu_items (id, category_id, name, description, price, is_available, is_hot, image_url) VALUES
(1, 1, 'Mì Cay Hải Sản Cấp Độ 7', 'Mì cay hải sản tôm mực chua cay đậm đà chuẩn vị Hàn', 65000.00, 1, 1, '/frontend/img/mi_cay.jpg'),
(2, 1, 'Lẩu Thái Tomyum Bò Mỹ', 'Nồi lẩu chua cay Tomyum kèm ba chỉ bò Mỹ thượng hạng', 189000.00, 1, 1, '/frontend/img/lau_thai.jpg'),
(3, 1, 'Cơm Trộn Hàn Quốc Bibimbap', 'Cơm trộn thịt bò sốt Gochujang truyền thống', 55000.00, 1, 0, '/frontend/img/com_tron.jpg'),
(4, 1, 'Mì Ý Sốt Bò Bằm Bolognaise', 'Mì spaghetti sốt cà chua thịt bò bằm phô mai sợi', 59000.00, 1, 0, '/frontend/img/mi_y.jpg'),
(5, 2, 'Khoai Tây Chiên Lắc Phô Mai', 'Khoai tây giòn rụm phủ bột phô mai béo ngậy', 35000.00, 1, 1, '/frontend/img/khoai_tay.jpg'),
(6, 2, 'Gà Rán Sốt Cay Hàn Quốc (4 miếng)', 'Gà giòn tẩm sốt cay ngọt mật ong chuẩn vị Seoul', 69000.00, 1, 1, '/frontend/img/ga_ran.jpg'),
(7, 2, 'Kim Chi Cải Thảo Truyền Thống', 'Kim chi cải thảo muối giòn chua cay vừa vặn', 15000.00, 1, 0, '/frontend/img/kim_chi.jpg'),
(8, 3, 'Trà Đào Cam Sả Tươi', 'Trà đào thanh mát với miếng đào giòn và sả tươi', 32000.00, 1, 1, '/frontend/img/tra_dao.jpg'),
(9, 3, 'Trà Sữa Trân Châu Hoàng Kim', 'Trà sữa đậm vị hồng trà và trân châu hoàng kim dẻo ngon', 35000.00, 1, 1, '/frontend/img/tra_sua.jpg'),
(10, 3, 'Coca-Cola Lon Lạnh', 'Nước ngọt có gas ướp lạnh sảng khoái', 18000.00, 1, 0, '/frontend/img/coca.jpg'),
(11, 1, 'Cơm Bò Xào Nấm Kim Châm', 'Thịt bò xào nấm kim châm mềm ngọt ăn kèm cơm trắng', 62000.00, 1, 0, '/frontend/img/com_bo.jpg'),
(12, 1, 'Lẩu Kim Chi Hải Sản Hàn Quốc', 'Nồi lẩu kim chi nấu cay ấm nồng kèm hải sản tươi sống', 179000.00, 1, 1, '/frontend/img/lau_kim_chi.jpg'),
(13, 2, 'Nem Chua Rán Hà Nội (8 cái)', 'Nem chua lăn bột chiên vàng giòn chấm tương ớt', 45000.00, 1, 1, '/frontend/img/nem_chua.jpg'),
(14, 2, 'Phô Mai Que Kéo Sợi (5 cái)', 'Phô mai Mozzarella bọc bột xù kéo sợi dai béo', 42000.00, 1, 0, '/frontend/img/pho_mai_que.jpg'),
(15, 3, 'Nước Ép Dưa Hấu Tươi', 'Nước ép dưa hấu nguyên chất không đường thanh mát', 30000.00, 1, 0, '/frontend/img/nuoc_ep_dua_hau.jpg'),
(16, 3, 'Trà Vải Hoa Hồng Nhiệt Đới', 'Hương vị trà vải dịu ngọt kèm thạch hoa hồng', 35000.00, 1, 1, '/frontend/img/tra_vai.jpg'),
(17, 1, 'Mì Udon Xào Bò Nhật Bản', 'Sợi mì Udon tươi xào sốt Teriyaki cùng bò Úc thái lát', 68000.00, 1, 0, '/frontend/img/mi_udon.jpg'),
(18, 1, 'Cơm Rang Dưa Bò Truyền Thống', 'Cơm rang hạt săn giòn trộn dưa cải chua và bắp bò xào', 55000.00, 1, 0, '/frontend/img/com_rang_dua_bo.jpg'),
(19, 2, 'Bánh Bạch Tuộc Takoyaki (6 viên)', 'Bánh nướng bạch tuộc sốt mayo và cá bào Nhật', 40000.00, 1, 1, '/frontend/img/takoyaki.jpg'),
(20, 2, 'Xúc Xích Đức Nướng Than Hoa', 'Xúc xích Đức xông khói vỏ giòn rụm chấm mù tạt vàng', 38000.00, 1, 0, '/frontend/img/xuc_xich_duc.jpg'),
(21, 3, 'Trà Chanh Giã Tay Quảng Đông', 'Trà xanh chanh tươi thơm nồng vị tinh dầu chanh giã tay', 28000.00, 1, 1, '/frontend/img/tra_chanh.jpg'),
(22, 3, 'Sinh Tố Bơ Sáp Dừa Tươi', 'Bơ sáp Đắk Lắk xay nhuyễn cùng sữa dừa béo ngậy', 42000.00, 1, 0, '/frontend/img/sinh_to_bo.jpg'),
(23, 1, 'Bún Đậu Mắm Tôm Thập Cẩm', 'Bún lá, chả cốm rán, dồi sụn, thịt luộc và mắm tôm pha chanh', 65000.00, 1, 1, '/frontend/img/bun_dau.jpg'),
(24, 1, 'Bò Bít Tết Sốt Tiêu Đen Kèm Bánh Mì', 'Bò Mỹ nướng mềm ngọt ăn kèm sốt tiêu đen cay nồng', 89000.00, 1, 1, '/frontend/img/bo_bit_tet.jpg'),
(25, 2, 'Bánh Mì Bơ Tỏi Nướng Giòn', 'Bánh mì nướng bơ tỏi thơm lừng giòn tan', 25000.00, 1, 0, '/frontend/img/banh_mi_bo_toi.jpg'),
(26, 2, 'Salad Cà Chua Dưa Leo Dầu Giấm', 'Rau xanh tươi mát sốt dầu giấm chua ngọt khai vị', 30000.00, 1, 0, '/frontend/img/salad.jpg'),
(27, 3, 'Cà Phê Sữa Đá Phin Truyền Thống', 'Cà phê Robusta pha phin đậm đà thơm sữa', 25000.00, 1, 0, '/frontend/img/ca_phe_sua.jpg'),
(28, 3, 'Trà Xoài Macchiato Kem Muối', 'Trà thanh long đỏ xoài tươi phủ lớp kem cheese béo mặn', 38000.00, 1, 1, '/frontend/img/tra_xoai.jpg'),
(29, 1, 'Súp Gà Ngô Non Trứng Cút', 'Súp gà thơm ngọt sánh mịn ăn kèm tiêu và rau mùi', 35000.00, 1, 0, '/frontend/img/sup_ga.jpg'),
(30, 1, 'Cháo Ếch Singapore Nồi Đất', 'Cháo trắng lá dứa ăn kèm ếch kho sốt cay sánh đậm đà', 75000.00, 1, 1, '/frontend/img/chao_ech.jpg'),
(31, 2, 'Ngô Ngọt Chiên Bơ Giòn', 'Ngô ngọt tách hạt tẩm bột bơ sữa chiên vàng ruộm', 35000.00, 1, 1, '/frontend/img/ngo_chien.jpg'),
(32, 2, 'Bánh Gối Nhân Thịt Miến (3 cái)', 'Bánh gối vỏ giòn nhân thịt nấm mộc nhĩ trứng cút', 30000.00, 1, 0, '/frontend/img/banh_goi.jpg'),
(33, 3, 'Chè Dừa Dầm Hải Phòng', 'Chè dừa non thạch rau câu cốt dừa ngọt mát', 32000.00, 1, 1, '/frontend/img/che_dua_dam.jpg'),
(34, 3, 'Tàu Hũ Trân Châu Đường Đen', 'Đậu hũ non mềm mịn ăn kèm sốt đường đen trân châu dẻo', 28000.00, 1, 0, '/frontend/img/tau_hu.jpg'),
(35, 4, 'Combo Lẩu Nướng Thần Thánh 4 Người', 'Gồm 1 nồi lẩu Thái, đĩa bò Mỹ, đĩa gà sốt cay và 4 cốc trà đào', 399000.00, 1, 1, '/frontend/img/combo_lau_nuong.jpg')
ON DUPLICATE KEY UPDATE category_id = VALUES(category_id), name = VALUES(name), description = VALUES(description), price = VALUES(price), is_hot = VALUES(is_hot);

-- Đơn gửi mẫu
INSERT INTO session_orders (id, session_id, idempotency_key) VALUES
(1, 'init_sess_001', 'idem_001_initial'),
(2, 'init_sess_002', 'idem_002_initial')
ON DUPLICATE KEY UPDATE idempotency_key = VALUES(idempotency_key);

-- Món bếp KDS mẫu (Kèm món vi phạm SLA >900s để demo)
INSERT INTO order_items_kitchen (id, order_id, session_id, menu_item_id, quantity, item_price, status, prep_time_seconds, is_sla_violated) VALUES
(1, 1, 'init_sess_001', 1, 2, 65000.00, 'COMPLETED', 480, 0),
(2, 1, 'init_sess_001', 8, 2, 32000.00, 'COMPLETED', 240, 0),
(3, 1, 'init_sess_001', 5, 1, 35000.00, 'COOKING', NULL, 0),
(4, 2, 'init_sess_002', 2, 1, 189000.00, 'COMPLETED', 960, 1),
(5, 2, 'init_sess_002', 6, 1, 69000.00, 'PENDING', NULL, 0)
ON DUPLICATE KEY UPDATE status = VALUES(status);

-- Đơn hàng & Chi tiết món ăn (KDS / Thu ngân POS)
CREATE TABLE IF NOT EXISTS orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_id VARCHAR(64) NOT NULL,
    order_code VARCHAR(50) UNIQUE NOT NULL,
    status VARCHAR(20) DEFAULT 'CONFIRMED',
    total_amount DECIMAL(12, 2) NOT NULL,
    idempotency_key VARCHAR(64) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_order_sess (session_id),
    INDEX idx_order_idem (idempotency_key),
    FOREIGN KEY (session_id) REFERENCES table_sessions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    menu_item_id INT NOT NULL,
    item_name VARCHAR(150) NOT NULL,
    unit_price DECIMAL(12, 2) NOT NULL,
    quantity INT NOT NULL,
    subtotal DECIMAL(12, 2) NOT NULL,
    status VARCHAR(20) DEFAULT 'PENDING',
    chef_id INT NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL,
    INDEX idx_oi_order (order_id),
    INDEX idx_oi_menu (menu_item_id),
    INDEX idx_oi_status (status),
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (menu_item_id) REFERENCES menu_items(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bill_payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_id VARCHAR(64) NOT NULL,
    table_id INT NOT NULL,
    subtotal_amount DECIMAL(12, 2) NOT NULL,
    vat_amount DECIMAL(12, 2) NOT NULL,
    service_fee DECIMAL(12, 2) DEFAULT 0.00,
    final_amount DECIMAL(12, 2) NOT NULL,
    payment_method VARCHAR(30) DEFAULT 'CASH',
    cashier_id INT NULL,
    payment_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    invoice_code VARCHAR(50) UNIQUE NOT NULL,
    INDEX idx_bp_sess (session_id),
    INDEX idx_bp_time (payment_time),
    FOREIGN KEY (session_id) REFERENCES table_sessions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO orders (id, session_id, order_code, status, total_amount, idempotency_key) VALUES
(1, 'init_sess_001', 'ORD-INIT-001', 'CONFIRMED', 229000.00, 'idem_001_initial'),
(2, 'init_sess_002', 'ORD-INIT-002', 'CONFIRMED', 258000.00, 'idem_002_initial')
ON DUPLICATE KEY UPDATE total_amount = VALUES(total_amount);

INSERT INTO order_items (id, order_id, menu_item_id, item_name, unit_price, quantity, subtotal, status, chef_id, completed_at) VALUES
(1, 1, 1, 'Mì Cay Hải Sản Cấp Độ 7', 65000.00, 2, 130000.00, 'COMPLETED', 1, NOW()),
(2, 1, 8, 'Trà Đào Cam Sả Tươi', 32000.00, 2, 64000.00, 'COMPLETED', 1, NOW()),
(3, 1, 5, 'Khoai Tây Chiên Lắc Phô Mai', 35000.00, 1, 35000.00, 'COOKING', 2, NULL),
(4, 2, 2, 'Lẩu Thái Tomyum Bò Mỹ', 189000.00, 1, 189000.00, 'COMPLETED', 1, NOW()),
(5, 2, 6, 'Gà Rán Sốt Cay Hàn Quốc (4 miếng)', 69000.00, 1, 69000.00, 'PENDING', NULL, NULL)
ON DUPLICATE KEY UPDATE subtotal = VALUES(subtotal);

INSERT INTO bill_payments (id, session_id, table_id, subtotal_amount, vat_amount, service_fee, final_amount, payment_method, invoice_code) VALUES
(1, 'init_sess_001', 1, 229000.00, 18320.00, 0.00, 247320.00, 'VIETQR', 'INV-20261112-001')
ON DUPLICATE KEY UPDATE final_amount = VALUES(final_amount);

-- Topping, Luật gợi ý Combo (Apriori) & Giỏ hàng chung
CREATE TABLE IF NOT EXISTS toppings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    price DECIMAL(12, 2) NOT NULL,
    is_available TINYINT(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS menu_item_toppings (
    menu_item_id INT NOT NULL,
    topping_id INT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (menu_item_id, topping_id),
    INDEX idx_menu_item_toppings_topping (topping_id),
    FOREIGN KEY (menu_item_id) REFERENCES menu_items(id) ON DELETE CASCADE,
    FOREIGN KEY (topping_id) REFERENCES toppings(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_item_topping_snapshots (
    order_item_id INT PRIMARY KEY,
    toppings_json JSON NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_item_id) REFERENCES order_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS combo_rules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    source_item_id INT NOT NULL,
    target_item_id INT NOT NULL,
    confidence DECIMAL(5, 4) DEFAULT 0.7500,
    discount_percent INT DEFAULT 10,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_combo_src (source_item_id),
    FOREIGN KEY (source_item_id) REFERENCES menu_items(id) ON DELETE CASCADE,
    FOREIGN KEY (target_item_id) REFERENCES menu_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS shared_carts (
    session_token VARCHAR(64) PRIMARY KEY,
    cart_data TEXT NOT NULL,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO toppings (id, name, price, is_available) VALUES
(1, 'Thêm Bò Mỹ Thượng Hạng', 25000.00, 1),
(2, 'Thêm Phô Mai Mozzarella Kéo Sợi', 15000.00, 1),
(3, 'Thêm Trân Châu Hoàng Kim', 8000.00, 1),
(4, 'Thêm Xúc Xích Đức', 12000.00, 1)
ON DUPLICATE KEY UPDATE name = VALUES(name), price = VALUES(price);

INSERT INTO combo_rules (id, source_item_id, target_item_id, confidence, discount_percent) VALUES
(1, 1, 8, 0.8800, 10),
(2, 2, 7, 0.8500, 15)
ON DUPLICATE KEY UPDATE confidence = VALUES(confidence), discount_percent = VALUES(discount_percent);
