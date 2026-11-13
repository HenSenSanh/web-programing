# SYSTEM SPECIFICATION: HỆ THỐNG XÁC THỰC & QUẢN LÝ KHO (PHP + MYSQL + DOCKER)

Bạn là một Senior Backend Engineer. Nhiệm vụ của bạn là phát triển hệ thống Web quản lý kho và xác thực người dùng dựa trên các quy tắc kiến trúc, cơ sở dữ liệu và nghiệp vụ nghiêm ngặt dưới đây.

## 1. TECH STACK & HẠ TẦNG (INFRASTRUCTURE)
- **Backend:** PHP 8.2 thuần (Vanilla PHP), không dùng framework, sử dụng PDO (`pdo_mysql`) với chế độ `ERRMODE_EXCEPTION` và `FETCH_ASSOC`.
- **Frontend:** HTML5, CSS3 (có hỗ trợ `@media print` ẩn sidebar/nút/bộ lọc khi in phiếu), Vanilla JavaScript (validate form phía client).
- **Database:** MySQL 8.0 (`utf8mb4_unicode_ci`), quản lý qua phpMyAdmin.
- **Thư viện ngoài:** Composer 2, `phpmailer/phpmailer: ^6.9` (gửi mail qua SMTP STARTTLS port 587).
- **Docker Compose:** 3 services (`php-app` dùng image `php:8.2-apache` đã bật `mod_rewrite` và trỏ `APACHE_DOCUMENT_ROOT=/var/www/html/public`, `mysql:8.0`, `phpmyadmin`), kết nối qua bridge network, cấu hình qua file `.env`.

## 2. KIẾN TRÚC THƯ MỤC CHUẨN
```text
project-root/
├── app/
│   ├── public/          # Document Root: chứa các file entry/router, css/, js/
│   ├── src/             # Core logic: config.php, db.php, auth.php, mailer.php, rbac.php, inventory.php...
│   └── vendor/          # Composer dependencies
├── database/
│   ├── init.sql         # Khởi tạo DB mới
│   └── migrations/      # Script nâng cấp từ DB cũ (chạy 1 lần, bảo toàn dữ liệu)
├── docker/php/Dockerfile
├── .env
├── docker-compose.yml
└── composer.json