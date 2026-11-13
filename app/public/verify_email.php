<?php
require_once __DIR__ . '/../src/auth.php';

$token = $_GET["token"] ?? "";
$result = verifyEmailToken($token);
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Xác nhận email</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="auth-wrapper">
        <div class="form-container">
            <h2>Xác nhận email</h2>

            <div class="message <?php echo $result["success"] ? '' : 'error-box'; ?>">
                <?php echo htmlspecialchars($result["message"]); ?>
            </div>

            <p class="link-text">
                <a href="login.php">Quay lại trang đăng nhập</a>
            </p>
        </div>
    </div>
</body>
</html>
