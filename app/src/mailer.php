<?php

function sendVerificationEmail(string $toEmail, string $username, string $token): bool
{
    $config = require __DIR__ . '/config.php';
    $appUrl = $config['app']['url'];
    $verifyUrl = $appUrl . "/verify_email.php?token=" . urlencode($token);

    $smtp = $config['smtp'];

    // Nếu có thư viện PHPMailer qua Composer
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (file_exists($autoload)) {
        require_once $autoload;
        if (class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            try {
                $mail->isSMTP();
                $mail->Host = $smtp['host'];
                $mail->SMTPAuth = !empty($smtp['username']);
                $mail->Username = $smtp['username'];
                $mail->Password = $smtp['password'];
                $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port = $smtp['port'];

                $mail->setFrom($smtp['from_email'] ?: 'no-reply@example.com', $smtp['from_name']);
                $mail->addAddress($toEmail, $username);

                $mail->isHTML(true);
                $mail->CharSet = 'UTF-8';
                $mail->Subject = "Xác nhận tài khoản của bạn";
                $mail->Body = "
                    <h2>Xin chào {$username}</h2>
                    <p>Bạn vừa đăng ký tài khoản tại hệ thống.</p>
                    <p>Vui lòng click vào liên kết dưới đây để xác nhận email:</p>
                    <p><a href='{$verifyUrl}'>Xác nhận email</a></p>
                    <p>Nếu bạn không đăng ký tài khoản này, vui lòng bỏ qua email.</p>
                ";
                $mail->AltBody = "Vui lòng mở link sau để xác nhận email: {$verifyUrl}";

                return $mail->send();
            } catch (\Exception $e) {
                error_log("Mailer Error: " . $mail->ErrorInfo);
                return false;
            }
        }
    }

    // Nếu chưa cài composer hoặc chạy trong môi trường demo/test: ghi log token để test
    error_log("[MOCK EMAIL] Gửi email xác nhận tới {$toEmail} với link: {$verifyUrl}");
    return true;
}
