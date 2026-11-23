<?php

if (!function_exists('envValue')) {
    function envValue(string $key, string $default = ""): string
    {
        $val = getenv($key);
        if ($val !== false && $val !== "") {
            return $val;
        }
        // Hỗ trợ đọc từ file .env nếu có
        static $envArray = null;
        if ($envArray === null) {
            $envArray = [];
            $envPath = __DIR__ . '/../../.env';
            if (file_exists($envPath)) {
                $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                foreach ($lines as $line) {
                    if (strpos(trim($line), '#') === 0) continue;
                    if (strpos($line, '=') !== false) {
                        [$k, $v] = explode('=', $line, 2);
                        $envArray[trim($k)] = trim($v, " \t\n\r\0\x0B\"'");
                    }
                }
            }
        }
        return $envArray[$key] ?? $default;
    }
}

return [
    "app" => [
        "name" => envValue("APP_NAME", "DineFlow QRDine"),
        "url" => envValue("APP_URL", "http://localhost:8080"),
        "qr_base_url" => rtrim(envValue("QR_BASE_URL", ""), '/'),
        "session_name" => envValue("SESSION_NAME", "DINEFLOW_SESSION"),
    ],
    "db" => [
        "host" => envValue("DB_HOST", "mysql"),
        "port" => envValue("DB_PORT", "3306"),
        "database" => envValue("DB_DATABASE", "dineflow_db"),
        "username" => envValue("DB_USERNAME", "dineflow"),
        "password" => envValue("DB_PASSWORD", "dineflow123"),
    ],
    "smtp" => [
        "host" => envValue("SMTP_HOST", "smtp.gmail.com"),
        "port" => (int) envValue("SMTP_PORT", "587"),
        "username" => envValue("SMTP_USERNAME", ""),
        "password" => envValue("SMTP_PASSWORD", ""),
        "from_email" => envValue("SMTP_FROM_EMAIL", ""),
        "from_name" => envValue("SMTP_FROM_NAME", "DineFlow QRDine"),
    ],
];
