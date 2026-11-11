<?php
session_start();

if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Hệ thống Xác thực & Quản lý Kho</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="auth-wrapper">
        <div class="form-container" style="text-align: center;">
            <h1>Hệ thống Quản lý Kho & Phân quyền</h1>
            <p class="subtitle">PHP 8.2 thuần + PDO MySQL + Docker Compose</p>
            <div style="display: flex; gap: 12px; justify-content: center; margin-top: 24px;">
                <a href="register.php" class="btn">Đăng ký</a>
                <a href="login.php" class="btn btn-secondary">Đăng nhập</a>
            </div>
        </div>
    </div>
</body>
</html>
