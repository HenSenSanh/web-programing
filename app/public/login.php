<?php
require_once __DIR__ . '/../src/auth.php';

$message = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $usernameOrEmail = trim($_POST["username_or_email"] ?? "");
    $password = $_POST["password"] ?? "";
    $remember = isset($_POST["remember"]);

    $result = loginUser($usernameOrEmail, $password, $remember);

    if ($result["success"]) {
        header("Location: dashboard.php");
        exit;
    }

    $message = $result["message"];
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Đăng nhập</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="auth-wrapper">
        <div class="form-container">
            <div style="text-align: center; margin-bottom: 20px;">
                <div style="font-size: 36px; line-height: 1;">🍲</div>
                <h2 style="margin-top: 8px; margin-bottom: 4px; color: #1e293b;">DineFlow</h2>
                <p style="font-size: 13px; color: #64748b; margin: 0;">Đăng nhập hệ thống điều hành nhà hàng</p>
            </div>

            <?php if (!empty($message)): ?>
                <div class="message error-box">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <form id="loginForm" method="POST" action="login.php" novalidate>
                <div class="form-group">
                    <label for="username_or_email">User name hoặc email</label>
                    <input
                        type="text"
                        id="username_or_email"
                        name="username_or_email"
                        placeholder="Nhập user name hoặc email"
                        value="<?php echo htmlspecialchars($_POST['username_or_email'] ?? $_COOKIE['remember_user'] ?? ''); ?>"
                    >
                    <small class="error" id="loginUsernameError"></small>
                </div>

                <div class="form-group">
                    <label for="login_password">Mật khẩu</label>
                    <input type="password" id="login_password" name="password" placeholder="Nhập mật khẩu">
                    <small class="error" id="loginPasswordError"></small>
                </div>

                <div class="checkbox-group">
                    <input type="checkbox" id="remember" name="remember" <?php echo isset($_COOKIE['remember_user']) ? 'checked' : ''; ?>>
                    <label for="remember">Nhớ mật khẩu</label>
                </div>

                <button type="submit" class="btn btn-full">Đăng nhập</button>
            </form>

            <p class="link-text">
                Chưa có tài khoản? <a href="register.php">Đăng ký</a>
            </p>
        </div>
    </div>

    <script src="js/validate.js"></script>
</body>
</html>
