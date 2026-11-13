<?php

function getDatabaseConnection(): PDO
{
    static $pdoInstance = null;
    if ($pdoInstance !== null) {
        return $pdoInstance;
    }

    if (class_exists('App\Core\Database')) {
        $pdoInstance = \App\Core\Database::getConnection();
        if ($pdoInstance->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            initSqliteFallback($pdoInstance);
        }
        return $pdoInstance;
    }

    $config = require __DIR__ . '/config.php';
    $db = $config['db'];

    // Thử kết nối MySQL theo cấu hình
    try {
        $dsn = sprintf(
            "mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4",
            $db['host'],
            $db['port'],
            $db['database']
        );
        $pdoInstance = new PDO($dsn, $db['username'], $db['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        return $pdoInstance;
    } catch (PDOException $e) {
        // Fallback sang SQLite để có thể chạy kiểm thử độc lập mà không bắt buộc Docker MySQL bật
        $sqlitePath = getenv('DINEFLOW_DB_PATH') ?: (getenv('SQLITE_PATH') ?: (__DIR__ . '/../../database/app.sqlite'));
        $isNew = !file_exists($sqlitePath) || filesize($sqlitePath) === 0;
        $pdoInstance = new PDO("sqlite:" . $sqlitePath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdoInstance->exec("PRAGMA foreign_keys = ON;");
        $pdoInstance->exec("PRAGMA journal_mode = WAL;");
        $pdoInstance->exec("PRAGMA busy_timeout = 5000;");

        if ($isNew) {
            // Tự khởi tạo schema SQLite tương thích
            initSqliteFallback($pdoInstance);
        }
        return $pdoInstance;
    }
}

function initSqliteFallback(PDO $db): void
{
    if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
        return;
    }

    try {
        $chk = $db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='users' LIMIT 1");
        if ($chk && $chk->fetch()) {
            return;
        }
    } catch (\Throwable $e) {
        // Proceed with fallback initialization
    }

    $initSqlFile = __DIR__ . '/../../database/init.sql';
    if (!file_exists($initSqlFile)) return;

    $content = file_get_contents($initSqlFile);
    // Bỏ qua các lệnh MySQL đặc thù
    $lines = explode("\n", $content);
    $cleanSql = "";
    foreach ($lines as $line) {
        $trim = trim($line);
        if (stripos($trim, "CREATE DATABASE") === 0 || stripos($trim, "USE ") === 0) continue;
        $cleanSql .= $line . "\n";
    }

    // Thay thế kiểu dữ liệu sang SQLite tương thích
    $cleanSql = str_ireplace("INT AUTO_INCREMENT PRIMARY KEY", "INTEGER PRIMARY KEY AUTOINCREMENT", $cleanSql);
    $cleanSql = preg_replace("/ENGINE=[^;]+;/i", ";", $cleanSql);
    $cleanSql = preg_replace("/ENUM\([^)]+\)/i", "TEXT", $cleanSql);
    $cleanSql = preg_replace("/INDEX\s+[a-zA-Z0-9_]+\s*\([^)]+\),?/i", "", $cleanSql);
    $cleanSql = preg_replace("/UNIQUE KEY\s+[a-zA-Z0-9_]+\s*\(([^)]+)\),?/i", "UNIQUE ($1),", $cleanSql);
    $cleanSql = str_ireplace("ON UPDATE CURRENT_TIMESTAMP", "", $cleanSql);
    $cleanSql = preg_replace('/,\s*\)/', "\n)", $cleanSql);
    $cleanSql = preg_replace('/ON DUPLICATE KEY UPDATE[^;]+/i', '', $cleanSql);
    $cleanSql = str_ireplace("INSERT INTO", "INSERT OR IGNORE INTO", $cleanSql);

    // Thực thi từng câu
    $queries = explode(";", $cleanSql);
    foreach ($queries as $q) {
        $q = trim($q);
        if (!empty($q)) {
            try {
                $db->exec($q);
            } catch (\Throwable $t) {
                // Ignore minor syntax differences
            }
        }
    }
}
