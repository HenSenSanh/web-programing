<?php
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/rbac.php';

if (isset($_SESSION['user_id'])) {
    header("Location: " . getStaffLandingPath((int)$_SESSION['user_id']));
    exit;
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>DineFlow (QRDine) - Hệ thống Gọi món QR & Điều hành Nhà hàng</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="auth-wrapper">
        <div class="form-container" style="text-align: center;">
            <h1>DineFlow (QRDine) - Quản lý Nhà hàng</h1>
            <p class="subtitle">Gọi món QR tại bàn • Bếp KDS • Thu ngân POS • Data Pipeline</p>
            <div style="display: flex; gap: 12px; justify-content: center; margin-top: 24px;">
                <a href="register.php" class="btn">Đăng ký</a>
                <a href="login.php" class="btn btn-secondary">Đăng nhập</a>
            </div>
        </div>
    </div>
</body>
</html>
