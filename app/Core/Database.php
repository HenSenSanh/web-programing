<?php

namespace App\Core;

use PDO;
use PDOException;

class Database
{
    private static ?PDO $pdo = null;

    public static function getConnection(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        // 1. If explicit isolated SQLite test path is requested
        if (getenv('DINEFLOW_DB_PATH')) {
            $dbPath = getenv('DINEFLOW_DB_PATH');
            $dir = dirname($dbPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            self::$pdo = new PDO("sqlite:" . $dbPath, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            self::$pdo->exec("PRAGMA foreign_keys = ON;");
            self::$pdo->exec("PRAGMA journal_mode = WAL;");
            self::$pdo->exec("PRAGMA busy_timeout = 5000;");
            return self::$pdo;
        }

        // 2. MySQL connection (Docker environment)
        $host = getenv('DB_HOST') ?: 'mysql';
        $port = getenv('DB_PORT') ?: 3306;
        $dbname = getenv('DB_DATABASE') ?: 'login_system';
        $user = getenv('DB_USERNAME') ?: 'login_user';
        $pass = getenv('DB_PASSWORD') ?: 'login_password';

        try {
            $dsn = sprintf("mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4", $host, $port, $dbname);
            self::$pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            return self::$pdo;
        } catch (PDOException $e) {
            // 3. Fallback to local SQLite if MySQL is unavailable
            $dbPath = __DIR__ . '/../../database/dineflow.sqlite';
            $dir = dirname($dbPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            self::$pdo = new PDO("sqlite:" . $dbPath, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            self::$pdo->exec("PRAGMA foreign_keys = ON;");
            return self::$pdo;
        }
    }

    public static function initSchema(): void
    {
        $db = self::getConnection();
        $candidates = [
            __DIR__ . '/../../database/schema.sql',
            '/var/www/database/schema.sql',
            dirname(__DIR__, 2) . '/database/schema.sql',
            dirname(__DIR__) . '/../database/schema.sql',
        ];
        $schemaFile = null;
        foreach ($candidates as $candidate) {
            if (file_exists($candidate)) {
                $schemaFile = $candidate;
                break;
            }
        }
        if ($schemaFile && file_exists($schemaFile)) {
            $sql = file_get_contents($schemaFile);
            $db->exec($sql);
        }
    }
}
