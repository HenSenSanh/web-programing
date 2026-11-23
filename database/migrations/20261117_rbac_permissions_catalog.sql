-- Run once on an existing MySQL database before database/seed_role_permissions.sql.
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
