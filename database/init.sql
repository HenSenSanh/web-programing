-- =========================================================================
-- DATABASE INITIALIZATION: HỆ THỐNG NHÀ HÀNG QRDINE - DINEFLOW (PHP + MYSQL)
-- Chuẩn utf8mb4_unicode_ci: Gọi món QR tại bàn, Giỏ hàng chung, Bếp KDS,
-- Thu ngân POS, Phân quyền RBAC & Kho dữ liệu phân tích OLAP Star Schema
-- =========================================================================

SET NAMES utf8mb4;
SET CHARACTER SET utf8mb4;

CREATE DATABASE IF NOT EXISTS dineflow_db
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;

USE dineflow_db;

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

-- Toàn bộ 35 món ăn trong Star Schema OLAP
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
(10, 10, 'Coca-Cola Lon Lạnh', 'Đồ Uống & Tráng Miệng', 18000.00),
(11, 11, 'Cơm Bò Xào Nấm Kim Châm', 'Món Chính', 62000.00),
(12, 12, 'Lẩu Kim Chi Hải Sản Hàn Quốc', 'Món Chính', 179000.00),
(13, 13, 'Nem Chua Rán Hà Nội (8 cái)', 'Món Ăn Kèm', 45000.00),
(14, 14, 'Phô Mai Que Kéo Sợi (5 cái)', 'Món Ăn Kèm', 42000.00),
(15, 15, 'Nước Ép Dưa Hấu Tươi', 'Đồ Uống & Tráng Miệng', 30000.00),
(16, 16, 'Trà Vải Hoa Hồng Nhiệt Đới', 'Đồ Uống & Tráng Miệng', 35000.00),
(17, 17, 'Mì Udon Xào Bò Nhật Bản', 'Món Chính', 68000.00),
(18, 18, 'Cơm Rang Dưa Bò Truyền Thống', 'Món Chính', 55000.00),
(19, 19, 'Bánh Bạch Tuộc Takoyaki (6 viên)', 'Món Ăn Kèm', 40000.00),
(20, 20, 'Xúc Xích Đức Nướng Than Hoa', 'Món Ăn Kèm', 38000.00),
(21, 21, 'Trà Chanh Giã Tay Quảng Đông', 'Đồ Uống & Tráng Miệng', 28000.00),
(22, 22, 'Sinh Tố Bơ Sáp Dừa Tươi', 'Đồ Uống & Tráng Miệng', 42000.00),
(23, 23, 'Bún Đậu Mắm Tôm Thập Cẩm', 'Món Chính', 65000.00),
(24, 24, 'Bò Bít Tết Sốt Tiêu Đen Kèm Bánh Mì', 'Món Chính', 89000.00),
(25, 25, 'Bánh Mì Bơ Tỏi Nướng Giòn', 'Món Ăn Kèm', 25000.00),
(26, 26, 'Salad Cà Chua Dưa Leo Dầu Giấm', 'Món Ăn Kèm', 30000.00),
(27, 27, 'Cà Phê Sữa Đá Phin Truyền Thống', 'Đồ Uống & Tráng Miệng', 25000.00),
(28, 28, 'Trà Xoài Macchiato Kem Muối', 'Đồ Uống & Tráng Miệng', 38000.00),
(29, 29, 'Súp Gà Ngô Non Trứng Cút', 'Món Chính', 35000.00),
(30, 30, 'Cháo Ếch Singapore Nồi Đất', 'Món Chính', 75000.00),
(31, 31, 'Ngô Ngọt Chiên Bơ Giòn', 'Món Ăn Kèm', 35000.00),
(32, 32, 'Bánh Gối Nhân Thịt Miến (3 cái)', 'Món Ăn Kèm', 30000.00),
(33, 33, 'Chè Dừa Dầm Hải Phòng', 'Đồ Uống & Tráng Miệng', 32000.00),
(34, 34, 'Tàu Hũ Trân Châu Đường Đen', 'Đồ Uống & Tráng Miệng', 28000.00),
(35, 35, 'Combo Lẩu Nướng Thần Thánh 4 Người', 'Combo Tiết Kiệm', 399000.00)
ON DUPLICATE KEY UPDATE item_name = VALUES(item_name), category_name = VALUES(category_name), current_price = VALUES(current_price);

-- Chiều thời gian 7 ngày (trưa & tối cao điểm)
INSERT INTO dim_time (time_key, full_date, hour, day_of_week, is_weekend) VALUES
(2026111201, '2026-11-12', 1, 4, 0),
(2026111202, '2026-11-12', 2, 4, 0),
(2026111211, '2026-11-12', 11, 4, 0),
(2026111212, '2026-11-12', 12, 4, 0),
(2026111218, '2026-11-12', 18, 4, 0),
(2026111219, '2026-11-12', 19, 4, 0),
(2026111312, '2026-11-13', 12, 5, 0),
(2026111319, '2026-11-13', 19, 5, 0),
(2026111412, '2026-11-14', 12, 6, 1),
(2026111419, '2026-11-14', 19, 6, 1),
(2026111513, '2026-11-15', 13, 0, 1),
(2026111520, '2026-11-15', 20, 0, 1),
(2026111611, '2026-11-16', 11, 1, 0),
(2026111619, '2026-11-16', 19, 1, 0),
(2026111712, '2026-11-17', 12, 2, 0),
(2026111719, '2026-11-17', 19, 2, 0),
(2026111812, '2026-11-18', 12, 3, 0),
(2026111818, '2026-11-18', 18, 3, 0)
ON DUPLICATE KEY UPDATE hour = VALUES(hour);

-- Sự kiện chi tiết hóa đơn (Fact Order Items) phủ đủ 7 ngày và 4 góc phần tư Menu Engineering
INSERT INTO fact_order_items (id, time_key, item_key, chef_key, session_id, quantity, revenue, prep_time_seconds, is_sla_violated) VALUES
(1, 2026111212, 1, 1, 'init_sess_001', 2, 130000.00, 480, 0),
(2, 2026111212, 8, 1, 'init_sess_001', 2, 64000.00, 240, 0),
(3, 2026111212, 5, 2, 'init_sess_001', 1, 35000.00, 360, 0),
(4, 2026111212, 2, 1, 'init_sess_002', 1, 189000.00, 960, 1),
(5, 2026111212, 6, 2, 'init_sess_002', 1, 69000.00, 520, 0),
(6, 2026111218, 1, 1, 'init_sess_003', 1, 65000.00, 420, 0),
(7, 2026111218, 8, 2, 'init_sess_003', 1, 32000.00, 180, 0),
(8, 2026111219, 3, 2, 'init_sess_004', 2, 110000.00, 1020, 1),
(9, 2026111219, 10, 1, 'init_sess_004', 2, 36000.00, 120, 0),
(10, 2026111312, 35, 1, 'init_sess_hist_01', 1, 399000.00, 780, 0),
(11, 2026111312, 8, 1, 'init_sess_hist_01', 4, 128000.00, 210, 0),
(12, 2026111312, 10, 2, 'init_sess_hist_01', 4, 72000.00, 120, 0),
(13, 2026111319, 2, 1, 'init_sess_hist_02', 2, 378000.00, 740, 0),
(14, 2026111319, 6, 2, 'init_sess_hist_02', 2, 138000.00, 490, 0),
(15, 2026111319, 7, 2, 'init_sess_hist_02', 3, 45000.00, 150, 0),
(16, 2026111412, 1, 1, 'init_sess_hist_03', 4, 260000.00, 510, 0),
(17, 2026111412, 9, 2, 'init_sess_hist_03', 5, 175000.00, 240, 0),
(18, 2026111412, 5, 2, 'init_sess_hist_03', 3, 105000.00, 320, 0),
(19, 2026111412, 13, 1, 'init_sess_hist_03', 2, 90000.00, 380, 0),
(20, 2026111419, 35, 1, 'init_sess_hist_04', 1, 399000.00, 860, 0),
(21, 2026111419, 12, 2, 'init_sess_hist_04', 2, 358000.00, 940, 1),
(22, 2026111419, 21, 1, 'init_sess_hist_04', 4, 112000.00, 190, 0),
(23, 2026111513, 24, 1, 'init_sess_hist_05', 3, 267000.00, 640, 0),
(24, 2026111513, 30, 2, 'init_sess_hist_05', 3, 225000.00, 610, 0),
(25, 2026111513, 16, 1, 'init_sess_hist_05', 4, 140000.00, 200, 0),
(26, 2026111520, 2, 1, 'init_sess_hist_06', 1, 189000.00, 820, 0),
(27, 2026111520, 1, 1, 'init_sess_hist_06', 3, 195000.00, 460, 0),
(28, 2026111520, 7, 2, 'init_sess_hist_06', 6, 90000.00, 120, 0),
(29, 2026111520, 27, 2, 'init_sess_hist_06', 5, 125000.00, 180, 0),
(30, 2026111611, 23, 1, 'init_sess_hist_07', 3, 195000.00, 420, 0),
(31, 2026111611, 11, 2, 'init_sess_hist_07', 3, 186000.00, 410, 0),
(32, 2026111611, 21, 2, 'init_sess_hist_07', 4, 112000.00, 160, 0),
(33, 2026111611, 25, 1, 'init_sess_hist_07', 1, 25000.00, 310, 0),
(34, 2026111619, 17, 1, 'init_sess_hist_08', 3, 204000.00, 500, 0),
(35, 2026111619, 19, 2, 'init_sess_hist_08', 4, 160000.00, 360, 0),
(36, 2026111619, 28, 2, 'init_sess_hist_08', 4, 152000.00, 220, 0),
(37, 2026111712, 4, 1, 'init_sess_hist_09', 4, 236000.00, 480, 0),
(38, 2026111712, 14, 2, 'init_sess_hist_09', 3, 126000.00, 340, 0),
(39, 2026111712, 22, 1, 'init_sess_hist_09', 2, 84000.00, 230, 0),
(40, 2026111712, 31, 2, 'init_sess_hist_09', 2, 70000.00, 290, 0),
(41, 2026111719, 12, 1, 'init_sess_hist_10', 2, 358000.00, 880, 0),
(42, 2026111719, 20, 2, 'init_sess_hist_10', 2, 76000.00, 310, 0),
(43, 2026111719, 15, 1, 'init_sess_hist_10', 4, 120000.00, 180, 0),
(44, 2026111719, 33, 2, 'init_sess_hist_10', 4, 128000.00, 200, 0),
(45, 2026111812, 1, 1, 'init_sess_hist_11', 4, 260000.00, 450, 0),
(46, 2026111812, 8, 2, 'init_sess_hist_11', 6, 192000.00, 190, 0),
(47, 2026111812, 18, 1, 'init_sess_hist_11', 3, 165000.00, 400, 0),
(48, 2026111812, 26, 2, 'init_sess_hist_11', 1, 30000.00, 210, 0),
(49, 2026111818, 35, 1, 'init_sess_hist_12', 2, 798000.00, 850, 0),
(50, 2026111818, 29, 2, 'init_sess_hist_12', 1, 35000.00, 260, 0),
(51, 2026111818, 32, 2, 'init_sess_hist_12', 1, 30000.00, 280, 0),
(52, 2026111818, 34, 1, 'init_sess_hist_12', 1, 28000.00, 170, 0)
ON DUPLICATE KEY UPDATE quantity = VALUES(quantity), revenue = VALUES(revenue);

-- Sự kiện Clickstream mẫu (Fact Clickstream)
INSERT INTO fact_clickstream (id, time_key, session_id, oltp_item_id, event_type) VALUES
(1, 2026111212, 'init_sess_001', 1, 'VIEW_ITEM'),
(2, 2026111212, 'init_sess_001', 1, 'ADD_TO_CART'),
(3, 2026111212, 'init_sess_001', 8, 'VIEW_ITEM'),
(4, 2026111212, 'init_sess_001', 8, 'ADD_TO_CART'),
(5, 2026111218, 'init_sess_003', 1, 'VIEW_ITEM'),
(6, 2026111218, 'init_sess_003', 8, 'VIEW_ITEM'),
(7, 2026111312, 'init_sess_hist_01', 35, 'VIEW_ITEM'),
(8, 2026111312, 'init_sess_hist_01', 35, 'VIEW_ITEM'),
(9, 2026111312, 'init_sess_hist_01', 35, 'ADD_TO_CART'),
(10, 2026111312, 'init_sess_hist_01', 8, 'VIEW_ITEM'),
(11, 2026111312, 'init_sess_hist_01', 8, 'ADD_TO_CART'),
(12, 2026111319, 'init_sess_hist_02', 2, 'VIEW_ITEM'),
(13, 2026111319, 'init_sess_hist_02', 2, 'VIEW_ITEM'),
(14, 2026111319, 'init_sess_hist_02', 2, 'ADD_TO_CART'),
(15, 2026111319, 'init_sess_hist_02', 6, 'VIEW_ITEM'),
(16, 2026111319, 'init_sess_hist_02', 6, 'VIEW_ITEM'),
(17, 2026111412, 'init_sess_hist_03', 1, 'VIEW_ITEM'),
(18, 2026111412, 'init_sess_hist_03', 1, 'VIEW_ITEM'),
(19, 2026111412, 'init_sess_hist_03', 1, 'ADD_TO_CART'),
(20, 2026111412, 'init_sess_hist_03', 9, 'VIEW_ITEM'),
(21, 2026111412, 'init_sess_hist_03', 9, 'ADD_TO_CART'),
(22, 2026111412, 'init_sess_hist_03', 5, 'VIEW_ITEM'),
(23, 2026111419, 'init_sess_hist_04', 12, 'VIEW_ITEM'),
(24, 2026111419, 'init_sess_hist_04', 12, 'ADD_TO_CART'),
(25, 2026111419, 'init_sess_hist_04', 21, 'VIEW_ITEM'),
(26, 2026111419, 'init_sess_hist_04', 21, 'ADD_TO_CART'),
(27, 2026111513, 'init_sess_hist_05', 24, 'VIEW_ITEM'),
(28, 2026111513, 'init_sess_hist_05', 24, 'VIEW_ITEM'),
(29, 2026111513, 'init_sess_hist_05', 24, 'VIEW_ITEM'),
(30, 2026111513, 'init_sess_hist_05', 30, 'VIEW_ITEM'),
(31, 2026111513, 'init_sess_hist_05', 30, 'VIEW_ITEM'),
(32, 2026111513, 'init_sess_hist_05', 16, 'VIEW_ITEM'),
(33, 2026111520, 'init_sess_hist_06', 1, 'VIEW_ITEM'),
(34, 2026111520, 'init_sess_hist_06', 2, 'VIEW_ITEM'),
(35, 2026111520, 'init_sess_hist_06', 7, 'VIEW_ITEM'),
(36, 2026111520, 'init_sess_hist_06', 27, 'VIEW_ITEM'),
(37, 2026111611, 'init_sess_hist_07', 23, 'VIEW_ITEM'),
(38, 2026111611, 'init_sess_hist_07', 11, 'VIEW_ITEM'),
(39, 2026111611, 'init_sess_hist_07', 21, 'VIEW_ITEM'),
(40, 2026111619, 'init_sess_hist_08', 17, 'VIEW_ITEM'),
(41, 2026111619, 'init_sess_hist_08', 17, 'VIEW_ITEM'),
(42, 2026111619, 'init_sess_hist_08', 19, 'VIEW_ITEM'),
(43, 2026111619, 'init_sess_hist_08', 28, 'VIEW_ITEM'),
(44, 2026111712, 'init_sess_hist_09', 4, 'VIEW_ITEM'),
(45, 2026111712, 'init_sess_hist_09', 4, 'ADD_TO_CART'),
(46, 2026111712, 'init_sess_hist_09', 14, 'VIEW_ITEM'),
(47, 2026111719, 'init_sess_hist_10', 12, 'VIEW_ITEM'),
(48, 2026111719, 'init_sess_hist_10', 15, 'VIEW_ITEM'),
(49, 2026111719, 'init_sess_hist_10', 33, 'VIEW_ITEM'),
(50, 2026111812, 'init_sess_hist_11', 1, 'VIEW_ITEM'),
(51, 2026111812, 'init_sess_hist_11', 8, 'VIEW_ITEM'),
(52, 2026111812, 'init_sess_hist_11', 18, 'VIEW_ITEM'),
(53, 2026111818, 'init_sess_hist_12', 35, 'VIEW_ITEM'),
(54, 2026111818, 'init_sess_hist_12', 35, 'ADD_TO_CART'),
(55, 2026111818, 'init_sess_hist_12', 13, 'VIEW_ITEM'),
(56, 2026111818, 'init_sess_hist_12', 3, 'VIEW_ITEM')
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

-- Dữ liệu phiên bàn mẫu (kèm lịch sử 7 ngày đã quyết toán cho BI Dashboard)
INSERT INTO table_sessions (id, table_id, session_token, status, guest_count, total_amount, created_at, closed_at, is_etl_processed) VALUES
('init_sess_001', 1, 'tbl-01-sess', 'ACTIVE', 3, 229000.00, '2026-11-12 12:10:00', NULL, 1),
('init_sess_002', 2, 'tbl-02-sess', 'ACTIVE', 2, 258000.00, '2026-11-12 12:35:00', NULL, 1),
('init_sess_003', 5, 'tbl-05-sess', 'ACTIVE', 4, 97000.00, '2026-11-12 18:15:00', NULL, 1),
('init_sess_004', 3, 'tbl-03-hist-00', 'CHECKOUT', 2, 146000.00, '2026-11-12 19:05:00', '2026-11-12 20:15:00', 1),
('init_sess_hist_01', 3, 'tbl-03-hist-01', 'CHECKOUT', 4, 599000.00, '2026-11-13 12:15:00', '2026-11-13 13:20:00', 1),
('init_sess_hist_02', 4, 'tbl-04-hist-02', 'CHECKOUT', 4, 561000.00, '2026-11-13 19:20:00', '2026-11-13 20:40:00', 1),
('init_sess_hist_03', 6, 'tbl-06-hist-03', 'CHECKOUT', 5, 630000.00, '2026-11-14 12:10:00', '2026-11-14 13:30:00', 1),
('init_sess_hist_04', 7, 'tbl-07-hist-04', 'CHECKOUT', 4, 869000.00, '2026-11-14 19:35:00', '2026-11-14 21:00:00', 1),
('init_sess_hist_05', 8, 'tbl-08-hist-05', 'CHECKOUT', 3, 632000.00, '2026-11-15 13:05:00', '2026-11-15 14:15:00', 1),
('init_sess_hist_06', 9, 'tbl-09-hist-06', 'CHECKOUT', 5, 599000.00, '2026-11-15 19:50:00', '2026-11-15 21:10:00', 1),
('init_sess_hist_07', 10, 'tbl-10-hist-07', 'CHECKOUT', 4, 518000.00, '2026-11-16 11:35:00', '2026-11-16 12:40:00', 1),
('init_sess_hist_08', 11, 'tbl-11-hist-08', 'CHECKOUT', 4, 516000.00, '2026-11-16 19:05:00', '2026-11-16 20:15:00', 1),
('init_sess_hist_09', 12, 'tbl-12-hist-09', 'CHECKOUT', 4, 516000.00, '2026-11-17 12:25:00', '2026-11-17 13:35:00', 1),
('init_sess_hist_10', 13, 'tbl-13-hist-10', 'CHECKOUT', 4, 682000.00, '2026-11-17 19:15:00', '2026-11-17 20:30:00', 1),
('init_sess_hist_11', 14, 'tbl-14-hist-11', 'CHECKOUT', 6, 647000.00, '2026-11-18 12:05:00', '2026-11-18 13:15:00', 1),
('init_sess_hist_12', 15, 'tbl-15-hist-12', 'CHECKOUT', 4, 891000.00, '2026-11-18 18:40:00', '2026-11-18 20:05:00', 1)
ON DUPLICATE KEY UPDATE status = VALUES(status), total_amount = VALUES(total_amount), is_etl_processed = VALUES(is_etl_processed);

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
(15, 3, 'Nước Ép Dưa Hấu Tươi', 'Nước ép dưa hấu nguyên chất không đường thanh mát', 30000.00, 1, 0, '/frontend/img/nuoc_ep_dua_hau.svg'),
(16, 3, 'Trà Vải Hoa Hồng Nhiệt Đới', 'Hương vị trà vải dịu ngọt kèm thạch hoa hồng', 35000.00, 1, 1, '/frontend/img/tra_vai.svg'),
(17, 1, 'Mì Udon Xào Bò Nhật Bản', 'Sợi mì Udon tươi xào sốt Teriyaki cùng bò Úc thái lát', 68000.00, 1, 0, '/frontend/img/mi_udon.svg'),
(18, 1, 'Cơm Rang Dưa Bò Truyền Thống', 'Cơm rang hạt săn giòn trộn dưa cải chua và bắp bò xào', 55000.00, 1, 0, '/frontend/img/com_rang_dua_bo.svg'),
(19, 2, 'Bánh Bạch Tuộc Takoyaki (6 viên)', 'Bánh nướng bạch tuộc sốt mayo và cá bào Nhật', 40000.00, 1, 1, '/frontend/img/takoyaki.svg'),
(20, 2, 'Xúc Xích Đức Nướng Than Hoa', 'Xúc xích Đức xông khói vỏ giòn rụm chấm mù tạt vàng', 38000.00, 1, 0, '/frontend/img/xuc_xich_duc.svg'),
(21, 3, 'Trà Chanh Giã Tay Quảng Đông', 'Trà xanh chanh tươi thơm nồng vị tinh dầu chanh giã tay', 28000.00, 1, 1, '/frontend/img/tra_chanh.svg'),
(22, 3, 'Sinh Tố Bơ Sáp Dừa Tươi', 'Bơ sáp Đắk Lắk xay nhuyễn cùng sữa dừa béo ngậy', 42000.00, 1, 0, '/frontend/img/sinh_to_bo.svg'),
(23, 1, 'Bún Đậu Mắm Tôm Thập Cẩm', 'Bún lá, chả cốm rán, dồi sụn, thịt luộc và mắm tôm pha chanh', 65000.00, 1, 1, '/frontend/img/bun_dau.svg'),
(24, 1, 'Bò Bít Tết Sốt Tiêu Đen Kèm Bánh Mì', 'Bò Mỹ nướng mềm ngọt ăn kèm sốt tiêu đen cay nồng', 89000.00, 1, 1, '/frontend/img/bo_bit_tet.svg'),
(25, 2, 'Bánh Mì Bơ Tỏi Nướng Giòn', 'Bánh mì nướng bơ tỏi thơm lừng giòn tan', 25000.00, 1, 0, '/frontend/img/banh_mi_bo_toi.svg'),
(26, 2, 'Salad Cà Chua Dưa Leo Dầu Giấm', 'Rau xanh tươi mát sốt dầu giấm chua ngọt khai vị', 30000.00, 1, 0, '/frontend/img/salad.svg'),
(27, 3, 'Cà Phê Sữa Đá Phin Truyền Thống', 'Cà phê Robusta pha phin đậm đà thơm sữa', 25000.00, 1, 0, '/frontend/img/ca_phe_sua.svg'),
(28, 3, 'Trà Xoài Macchiato Kem Muối', 'Trà thanh long đỏ xoài tươi phủ lớp kem cheese béo mặn', 38000.00, 1, 1, '/frontend/img/tra_xoai.svg'),
(29, 1, 'Súp Gà Ngô Non Trứng Cút', 'Súp gà thơm ngọt sánh mịn ăn kèm tiêu và rau mùi', 35000.00, 1, 0, '/frontend/img/sup_ga.svg'),
(30, 1, 'Cháo Ếch Singapore Nồi Đất', 'Cháo trắng lá dứa ăn kèm ếch kho sốt cay sánh đậm đà', 75000.00, 1, 1, '/frontend/img/chao_ech.svg'),
(31, 2, 'Ngô Ngọt Chiên Bơ Giòn', 'Ngô ngọt tách hạt tẩm bột bơ sữa chiên vàng ruộm', 35000.00, 1, 1, '/frontend/img/ngo_chien.svg'),
(32, 2, 'Bánh Gối Nhân Thịt Miến (3 cái)', 'Bánh gối vỏ giòn nhân thịt nấm mộc nhĩ trứng cút', 30000.00, 1, 0, '/frontend/img/banh_goi.svg'),
(33, 3, 'Chè Dừa Dầm Hải Phòng', 'Chè dừa non thạch rau câu cốt dừa ngọt mát', 32000.00, 1, 1, '/frontend/img/che_dua_dam.svg'),
(34, 3, 'Tàu Hũ Trân Châu Đường Đen', 'Đậu hũ non mềm mịn ăn kèm sốt đường đen trân châu dẻo', 28000.00, 1, 0, '/frontend/img/tau_hu.svg'),
(35, 4, 'Combo Lẩu Nướng Thần Thánh 4 Người', 'Gồm 1 nồi lẩu Thái, đĩa bò Mỹ, đĩa gà sốt cay và 4 cốc trà đào', 399000.00, 1, 1, '/frontend/img/combo_lau_nuong.svg')
ON DUPLICATE KEY UPDATE category_id = VALUES(category_id), name = VALUES(name), description = VALUES(description), price = VALUES(price), is_hot = VALUES(is_hot), image_url = VALUES(image_url);

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
    idempotency_key VARCHAR(64) NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_order_sess (session_id),
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

INSERT INTO orders (id, session_id, order_code, status, total_amount, idempotency_key, created_at) VALUES
(1, 'init_sess_001', 'ORD-INIT-001', 'CONFIRMED', 229000.00, 'idem_001_initial', '2026-11-12 12:15:00'),
(2, 'init_sess_002', 'ORD-INIT-002', 'CONFIRMED', 258000.00, 'idem_002_initial', '2026-11-12 12:40:00'),
(3, 'init_sess_003', 'ORD-INIT-003', 'CONFIRMED', 97000.00, 'idem_003_initial', '2026-11-12 18:20:00'),
(4, 'init_sess_004', 'ORD-INIT-004', 'CONFIRMED', 146000.00, 'idem_004_initial', '2026-11-12 19:10:00'),
(5, 'init_sess_hist_01', 'ORD-HIST-001', 'CONFIRMED', 599000.00, 'idem_hist_001', '2026-11-13 12:20:00'),
(6, 'init_sess_hist_02', 'ORD-HIST-002', 'CONFIRMED', 561000.00, 'idem_hist_002', '2026-11-13 19:30:00'),
(7, 'init_sess_hist_03', 'ORD-HIST-003', 'CONFIRMED', 630000.00, 'idem_hist_003', '2026-11-14 12:15:00'),
(8, 'init_sess_hist_04', 'ORD-HIST-004', 'CONFIRMED', 869000.00, 'idem_hist_004', '2026-11-14 19:45:00'),
(9, 'init_sess_hist_05', 'ORD-HIST-005', 'CONFIRMED', 632000.00, 'idem_hist_005', '2026-11-15 13:10:00'),
(10, 'init_sess_hist_06', 'ORD-HIST-006', 'CONFIRMED', 599000.00, 'idem_hist_006', '2026-11-15 20:00:00'),
(11, 'init_sess_hist_07', 'ORD-HIST-007', 'CONFIRMED', 518000.00, 'idem_hist_007', '2026-11-16 11:40:00'),
(12, 'init_sess_hist_08', 'ORD-HIST-008', 'CONFIRMED', 516000.00, 'idem_hist_008', '2026-11-16 19:10:00'),
(13, 'init_sess_hist_09', 'ORD-HIST-009', 'CONFIRMED', 516000.00, 'idem_hist_009', '2026-11-17 12:30:00'),
(14, 'init_sess_hist_10', 'ORD-HIST-010', 'CONFIRMED', 682000.00, 'idem_hist_010', '2026-11-17 19:20:00'),
(15, 'init_sess_hist_11', 'ORD-HIST-011', 'CONFIRMED', 647000.00, 'idem_hist_011', '2026-11-18 12:10:00'),
(16, 'init_sess_hist_12', 'ORD-HIST-012', 'CONFIRMED', 891000.00, 'idem_hist_012', '2026-11-18 18:45:00')
ON DUPLICATE KEY UPDATE total_amount = VALUES(total_amount);

INSERT INTO order_items (id, order_id, menu_item_id, item_name, unit_price, quantity, subtotal, status, chef_id, completed_at) VALUES
(1, 1, 1, 'Mì Cay Hải Sản Cấp Độ 7', 65000.00, 2, 130000.00, 'COMPLETED', 1, '2026-11-12 12:23:00'),
(2, 1, 8, 'Trà Đào Cam Sả Tươi', 32000.00, 2, 64000.00, 'COMPLETED', 1, '2026-11-12 12:19:00'),
(3, 1, 5, 'Khoai Tây Chiên Lắc Phô Mai', 35000.00, 1, 35000.00, 'COOKING', 2, NULL),
(4, 2, 2, 'Lẩu Thái Tomyum Bò Mỹ', 189000.00, 1, 189000.00, 'COMPLETED', 1, '2026-11-12 12:56:00'),
(5, 2, 6, 'Gà Rán Sốt Cay Hàn Quốc (4 miếng)', 69000.00, 1, 69000.00, 'PENDING', NULL, NULL),
(6, 3, 1, 'Mì Cay Hải Sản Cấp Độ 7', 65000.00, 1, 65000.00, 'COMPLETED', 1, '2026-11-12 18:27:00'),
(7, 3, 8, 'Trà Đào Cam Sả Tươi', 32000.00, 1, 32000.00, 'COMPLETED', 2, '2026-11-12 18:23:00'),
(8, 4, 3, 'Cơm Trộn Hàn Quốc Bibimbap', 55000.00, 2, 110000.00, 'COMPLETED', 2, '2026-11-12 19:27:00'),
(9, 4, 10, 'Coca-Cola Lon Lạnh', 18000.00, 2, 36000.00, 'COMPLETED', 1, '2026-11-12 19:12:00'),
(10, 5, 35, 'Combo Lẩu Nướng Thần Thánh 4 Người', 399000.00, 1, 399000.00, 'COMPLETED', 1, '2026-11-13 12:33:00'),
(11, 5, 8, 'Trà Đào Cam Sả Tươi', 32000.00, 4, 128000.00, 'COMPLETED', 1, '2026-11-13 12:24:00'),
(12, 5, 10, 'Coca-Cola Lon Lạnh', 18000.00, 4, 72000.00, 'COMPLETED', 2, '2026-11-13 12:22:00'),
(13, 6, 2, 'Lẩu Thái Tomyum Bò Mỹ', 189000.00, 2, 378000.00, 'COMPLETED', 1, '2026-11-13 19:42:00'),
(14, 6, 6, 'Gà Rán Sốt Cay Hàn Quốc (4 miếng)', 69000.00, 2, 138000.00, 'COMPLETED', 2, '2026-11-13 19:38:00'),
(15, 6, 7, 'Kim Chi Cải Thảo Truyền Thống', 15000.00, 3, 45000.00, 'COMPLETED', 2, '2026-11-13 19:33:00'),
(16, 7, 1, 'Mì Cay Hải Sản Cấp Độ 7', 65000.00, 4, 260000.00, 'COMPLETED', 1, '2026-11-14 12:24:00'),
(17, 7, 9, 'Trà Sữa Trân Châu Hoàng Kim', 35000.00, 5, 175000.00, 'COMPLETED', 2, '2026-11-14 12:19:00'),
(18, 7, 5, 'Khoai Tây Chiên Lắc Phô Mai', 35000.00, 3, 105000.00, 'COMPLETED', 2, '2026-11-14 12:20:00'),
(19, 7, 13, 'Nem Chua Rán Hà Nội (8 cái)', 45000.00, 2, 90000.00, 'COMPLETED', 1, '2026-11-14 12:22:00'),
(20, 8, 35, 'Combo Lẩu Nướng Thần Thánh 4 Người', 399000.00, 1, 399000.00, 'COMPLETED', 1, '2026-11-14 19:59:00'),
(21, 8, 12, 'Lẩu Kim Chi Hải Sản Hàn Quốc', 179000.00, 2, 358000.00, 'COMPLETED', 2, '2026-11-14 20:01:00'),
(22, 8, 21, 'Trà Chanh Giã Tay Quảng Đông', 28000.00, 4, 112000.00, 'COMPLETED', 1, '2026-11-14 19:48:00'),
(23, 9, 24, 'Bò Bít Tết Sốt Tiêu Đen Kèm Bánh Mì', 89000.00, 3, 267000.00, 'COMPLETED', 1, '2026-11-15 13:21:00'),
(24, 9, 30, 'Cháo Ếch Singapore Nồi Đất', 75000.00, 3, 225000.00, 'COMPLETED', 2, '2026-11-15 13:20:00'),
(25, 9, 16, 'Trà Vải Hoa Hồng Nhiệt Đới', 35000.00, 4, 140000.00, 'COMPLETED', 1, '2026-11-15 13:13:00'),
(26, 10, 2, 'Lẩu Thái Tomyum Bò Mỹ', 189000.00, 1, 189000.00, 'COMPLETED', 1, '2026-11-15 20:14:00'),
(27, 10, 1, 'Mì Cay Hải Sản Cấp Độ 7', 65000.00, 3, 195000.00, 'COMPLETED', 1, '2026-11-15 20:08:00'),
(28, 10, 7, 'Kim Chi Cải Thảo Truyền Thống', 15000.00, 6, 90000.00, 'COMPLETED', 2, '2026-11-15 20:02:00'),
(29, 10, 27, 'Cà Phê Sữa Đá Phin Truyền Thống', 25000.00, 5, 125000.00, 'COMPLETED', 2, '2026-11-15 20:03:00'),
(30, 11, 23, 'Bún Đậu Mắm Tôm Thập Cẩm', 65000.00, 3, 195000.00, 'COMPLETED', 1, '2026-11-16 11:47:00'),
(31, 11, 11, 'Cơm Bò Xào Nấm Kim Châm', 62000.00, 3, 186000.00, 'COMPLETED', 2, '2026-11-16 11:47:00'),
(32, 11, 21, 'Trà Chanh Giã Tay Quảng Đông', 28000.00, 4, 112000.00, 'COMPLETED', 2, '2026-11-16 11:43:00'),
(33, 11, 25, 'Bánh Mì Bơ Tỏi Nướng Giòn', 25000.00, 1, 25000.00, 'COMPLETED', 1, '2026-11-16 11:45:00'),
(34, 12, 17, 'Mì Udon Xào Bò Nhật Bản', 68000.00, 3, 204000.00, 'COMPLETED', 1, '2026-11-16 19:18:00'),
(35, 12, 19, 'Bánh Bạch Tuộc Takoyaki (6 viên)', 40000.00, 4, 160000.00, 'COMPLETED', 2, '2026-11-16 19:16:00'),
(36, 12, 28, 'Trà Xoài Macchiato Kem Muối', 38000.00, 4, 152000.00, 'COMPLETED', 2, '2026-11-16 19:14:00'),
(37, 13, 4, 'Mì Ý Sốt Bò Bằm Bolognaise', 59000.00, 4, 236000.00, 'COMPLETED', 1, '2026-11-17 12:38:00'),
(38, 13, 14, 'Phô Mai Que Kéo Sợi (5 cái)', 42000.00, 3, 126000.00, 'COMPLETED', 2, '2026-11-17 12:36:00'),
(39, 13, 22, 'Sinh Tố Bơ Sáp Dừa Tươi', 42000.00, 2, 84000.00, 'COMPLETED', 1, '2026-11-17 12:34:00'),
(40, 13, 31, 'Ngô Ngọt Chiên Bơ Giòn', 35000.00, 2, 70000.00, 'COMPLETED', 2, '2026-11-17 12:35:00'),
(41, 14, 12, 'Lẩu Kim Chi Hải Sản Hàn Quốc', 179000.00, 2, 358000.00, 'COMPLETED', 1, '2026-11-17 19:35:00'),
(42, 14, 20, 'Xúc Xích Đức Nướng Than Hoa', 38000.00, 2, 76000.00, 'COMPLETED', 2, '2026-11-17 19:25:00'),
(43, 14, 15, 'Nước Ép Dưa Hấu Tươi', 30000.00, 4, 120000.00, 'COMPLETED', 1, '2026-11-17 19:23:00'),
(44, 14, 33, 'Chè Dừa Dầm Hải Phòng', 32000.00, 4, 128000.00, 'COMPLETED', 2, '2026-11-17 19:23:00'),
(45, 15, 1, 'Mì Cay Hải Sản Cấp Độ 7', 65000.00, 4, 260000.00, 'COMPLETED', 1, '2026-11-18 12:18:00'),
(46, 15, 8, 'Trà Đào Cam Sả Tươi', 32000.00, 6, 192000.00, 'COMPLETED', 2, '2026-11-18 12:13:00'),
(47, 15, 18, 'Cơm Rang Dưa Bò Truyền Thống', 55000.00, 3, 165000.00, 'COMPLETED', 1, '2026-11-18 12:17:00'),
(48, 15, 26, 'Salad Cà Chua Dưa Leo Dầu Giấm', 30000.00, 1, 30000.00, 'COMPLETED', 2, '2026-11-18 12:14:00'),
(49, 16, 35, 'Combo Lẩu Nướng Thần Thánh 4 Người', 399000.00, 2, 798000.00, 'COMPLETED', 1, '2026-11-18 18:59:00'),
(50, 16, 29, 'Súp Gà Ngô Non Trứng Cút', 35000.00, 1, 35000.00, 'COMPLETED', 2, '2026-11-18 18:49:00'),
(51, 16, 32, 'Bánh Gối Nhân Thịt Miến (3 cái)', 30000.00, 1, 30000.00, 'COMPLETED', 2, '2026-11-18 18:50:00'),
(52, 16, 34, 'Tàu Hũ Trân Châu Đường Đen', 28000.00, 1, 28000.00, 'COMPLETED', 1, '2026-11-18 18:48:00')
ON DUPLICATE KEY UPDATE subtotal = VALUES(subtotal);

INSERT INTO bill_payments (id, session_id, table_id, subtotal_amount, vat_amount, service_fee, final_amount, payment_method, payment_time, invoice_code) VALUES
(1, 'init_sess_001', 1, 229000.00, 18320.00, 0.00, 247320.00, 'VIETQR', '2026-11-12 12:50:00', 'INV-20261112-001'),
(2, 'init_sess_004', 3, 146000.00, 11680.00, 0.00, 157680.00, 'CASH', '2026-11-12 20:15:00', 'INV-20261112-002'),
(3, 'init_sess_hist_01', 3, 599000.00, 47920.00, 0.00, 646920.00, 'VIETQR', '2026-11-13 13:20:00', 'INV-20261113-001'),
(4, 'init_sess_hist_02', 4, 561000.00, 44880.00, 0.00, 605880.00, 'CASH', '2026-11-13 20:40:00', 'INV-20261113-002'),
(5, 'init_sess_hist_03', 6, 630000.00, 50400.00, 0.00, 680400.00, 'VIETQR', '2026-11-14 13:30:00', 'INV-20261114-001'),
(6, 'init_sess_hist_04', 7, 869000.00, 69520.00, 0.00, 938520.00, 'CARD', '2026-11-14 21:00:00', 'INV-20261114-002'),
(7, 'init_sess_hist_05', 8, 632000.00, 50560.00, 0.00, 682560.00, 'VIETQR', '2026-11-15 14:15:00', 'INV-20261115-001'),
(8, 'init_sess_hist_06', 9, 599000.00, 47920.00, 0.00, 646920.00, 'VIETQR', '2026-11-15 21:10:00', 'INV-20261115-002'),
(9, 'init_sess_hist_07', 10, 518000.00, 41440.00, 0.00, 559440.00, 'CASH', '2026-11-16 12:40:00', 'INV-20261116-001'),
(10, 'init_sess_hist_08', 11, 516000.00, 41280.00, 0.00, 557280.00, 'VIETQR', '2026-11-16 20:15:00', 'INV-20261116-002'),
(11, 'init_sess_hist_09', 12, 516000.00, 41280.00, 0.00, 557280.00, 'CARD', '2026-11-17 13:35:00', 'INV-20261117-001'),
(12, 'init_sess_hist_10', 13, 682000.00, 54560.00, 0.00, 736560.00, 'VIETQR', '2026-11-17 20:30:00', 'INV-20261117-002'),
(13, 'init_sess_hist_11', 14, 647000.00, 51760.00, 0.00, 698760.00, 'VIETQR', '2026-11-18 13:15:00', 'INV-20261118-001'),
(14, 'init_sess_hist_12', 15, 891000.00, 71280.00, 0.00, 962280.00, 'VIETQR', '2026-11-18 20:05:00', 'INV-20261118-002')
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

CREATE TABLE IF NOT EXISTS service_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_id VARCHAR(64) NOT NULL,
    table_id INT NOT NULL,
    request_type VARCHAR(32) NOT NULL DEFAULT 'CALL_STAFF',
    message VARCHAR(255) NOT NULL DEFAULT '',
    status VARCHAR(20) NOT NULL DEFAULT 'PENDING',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_at TIMESTAMP NULL,
    resolved_by INT NULL,
    INDEX idx_sr_status_created (status, created_at),
    INDEX idx_sr_session (session_id),
    FOREIGN KEY (session_id) REFERENCES table_sessions(id) ON DELETE CASCADE,
    FOREIGN KEY (table_id) REFERENCES dining_tables(id) ON DELETE CASCADE,
    FOREIGN KEY (resolved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO toppings (id, name, price, is_available) VALUES
(1, 'Thêm Bò Mỹ Thượng Hạng', 25000.00, 1),
(2, 'Thêm Phô Mai Mozzarella Kéo Sợi', 15000.00, 1),
(3, 'Thêm Trân Châu Hoàng Kim', 8000.00, 1),
(4, 'Thêm Xúc Xích Đức', 12000.00, 1)
ON DUPLICATE KEY UPDATE name = VALUES(name), price = VALUES(price);

INSERT INTO menu_item_toppings (menu_item_id, topping_id) VALUES
(1, 1), (1, 2), (1, 4),
(2, 1), (2, 2), (2, 4),
(3, 1), (3, 2), (3, 4),
(4, 1), (4, 2), (4, 4),
(5, 2), (5, 4),
(6, 2), (6, 4),
(8, 3),
(9, 3),
(11, 1), (11, 2), (11, 4),
(12, 1), (12, 2), (12, 4),
(13, 2), (13, 4),
(14, 2), (14, 4),
(16, 3),
(17, 1), (17, 2), (17, 4),
(18, 1), (18, 2), (18, 4),
(19, 2), (19, 4),
(20, 2), (20, 4),
(21, 3),
(24, 1), (24, 2), (24, 4),
(25, 2), (25, 4),
(28, 3),
(31, 2), (31, 4),
(33, 3),
(34, 3),
(35, 1), (35, 2), (35, 4)
ON DUPLICATE KEY UPDATE topping_id = VALUES(topping_id);

INSERT INTO combo_rules (id, source_item_id, target_item_id, confidence, discount_percent) VALUES
(1, 1, 8, 0.8800, 10),
(2, 2, 7, 0.8500, 15),
(3, 12, 21, 0.8200, 10),
(4, 6, 5, 0.7900, 10),
(5, 24, 26, 0.7600, 12),
(6, 35, 8, 0.9100, 15)
ON DUPLICATE KEY UPDATE confidence = VALUES(confidence), discount_percent = VALUES(discount_percent);
