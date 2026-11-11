<?php

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php';

$config = require __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_name($config['app']['session_name']);
    session_start();
}

function hashPasswordSha256(string $password): string
{
    return hash('sha256', $password);
}

function registerUser(
    string $username,
    string $email,
    string $password,
    string $confirmPassword
): array {
    if ($username === "" || $email === "" || $password === "" || $confirmPassword === "") {
        return [
            "success" => false,
            "message" => "Vui lòng nhập đầy đủ thông tin."
        ];
    }

    if (strlen($username) < 3) {
        return [
            "success" => false,
            "message" => "User name phải có ít nhất 3 ký tự."
        ];
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return [
            "success" => false,
            "message" => "Email không hợp lệ."
        ];
    }

    if (strlen($password) < 6) {
        return [
            "success" => false,
            "message" => "Mật khẩu phải có ít nhất 6 ký tự."
        ];
    }

    if ($password !== $confirmPassword) {
        return [
            "success" => false,
            "message" => "Mật khẩu xác nhận không khớp."
        ];
    }

    $pdo = getDatabaseConnection();
    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = :username OR email = :email LIMIT 1");
    $stmt->execute([
        "username" => $username,
        "email" => $email
    ]);

    if ($stmt->fetch()) {
        return [
            "success" => false,
            "message" => "User name hoặc email đã tồn tại."
        ];
    }

    $passwordHash = hashPasswordSha256($password);
    $verificationToken = bin2hex(random_bytes(32));

    $stmt = $pdo->prepare("
        INSERT INTO users (username, email, password_hash, email_verified, verification_token)
        VALUES (:username, :email, :password_hash, 0, :verification_token)
    ");
    $stmt->execute([
        "username" => $username,
        "email" => $email,
        "password_hash" => $passwordHash,
        "verification_token" => $verificationToken
    ]);

    $mailSent = sendVerificationEmail($email, $username, $verificationToken);

    if (!$mailSent) {
        return [
            "success" => true,
            "message" => "Đăng ký thành công nhưng gửi email xác nhận thất bại. Vui lòng kiểm tra cấu hình SMTP."
        ];
    }

    return [
        "success" => true,
        "message" => "Đăng ký thành công. Vui lòng kiểm tra email để xác nhận tài khoản."
    ];
}

function loginUser(string $usernameOrEmail, string $password, bool $remember = false): array
{
    if ($usernameOrEmail === "" || $password === "") {
        return [
            "success" => false,
            "message" => "Vui lòng nhập user name/email và mật khẩu."
        ];
    }

    $pdo = getDatabaseConnection();
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = :input OR email = :input LIMIT 1");
    $stmt->execute(["input" => $usernameOrEmail]);
    $user = $stmt->fetch();

    if (!$user) {
        return [
            "success" => false,
            "message" => "Tài khoản không tồn tại."
        ];
    }

    $passwordHash = hashPasswordSha256($password);

    if (!hash_equals($user['password_hash'], $passwordHash)) {
        return [
            "success" => false,
            "message" => "Mật khẩu không chính xác."
        ];
    }

    if ((int)$user['email_verified'] !== 1) {
        return [
            "success" => false,
            "message" => "Tài khoản chưa xác nhận email. Vui lòng kiểm tra email."
        ];
    }

    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['email'] = $user['email'];

    if ($remember) {
        $rememberToken = bin2hex(random_bytes(32));
        $stmt = $pdo->prepare("UPDATE users SET remember_token = :remember_token WHERE id = :id");
        $stmt->execute([
            "remember_token" => $rememberToken,
            "id" => $user['id']
        ]);

        if (!headers_sent()) {
            setcookie("remember_user", $usernameOrEmail, time() + 86400 * 30, "/");
            setcookie("remember_token", $rememberToken, time() + 86400 * 30, "/", "", false, true);
        }
        $_COOKIE['remember_user'] = $usernameOrEmail;
        $_COOKIE['remember_token'] = $rememberToken;
    }

    return [
        "success" => true,
        "message" => "Đăng nhập thành công."
    ];
}

function verifyEmailToken(string $token): array
{
    if ($token === "") {
        return [
            "success" => false,
            "message" => "Token xác nhận không hợp lệ."
        ];
    }

    $pdo = getDatabaseConnection();
    $stmt = $pdo->prepare("SELECT id FROM users WHERE verification_token = :token LIMIT 1");
    $stmt->execute(["token" => $token]);
    $user = $stmt->fetch();

    if (!$user) {
        return [
            "success" => false,
            "message" => "Token xác nhận không tồn tại hoặc đã được sử dụng."
        ];
    }

    $stmt = $pdo->prepare("UPDATE users SET email_verified = 1, verification_token = NULL WHERE id = :id");
    $stmt->execute(["id" => $user['id']]);

    return [
        "success" => true,
        "message" => "Xác nhận email thành công. Bạn có thể đăng nhập."
    ];
}

function requireLogin(): void
{
    if (!isset($_SESSION['user_id'])) {
        header("Location: login.php");
        exit;
    }
}

function logoutUser(): void
{
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }
    session_destroy();

    setcookie("remember_user", "", time() - 3600, "/");
    setcookie("remember_token", "", time() - 3600, "/");

    header("Location: login.php");
    exit;
}
