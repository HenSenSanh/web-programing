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

        $connection = getenv('DB_CONNECTION') ?: 'sqlite';
        $dbPath = getenv('DINEFLOW_DB_PATH') ?: (__DIR__ . '/../../database/dineflow.sqlite');

        if ($connection === 'pgsql' && getenv('DATABASE_URL')) {
            $dbUrl = parse_url(getenv('DATABASE_URL'));
            $dsn = sprintf(
                "pgsql:host=%s;port=%d;dbname=%s",
                $dbUrl['host'] ?? 'localhost',
                $dbUrl['port'] ?? 5432,
                ltrim($dbUrl['path'] ?? 'dineflow_db', '/')
            );
            $user = $dbUrl['user'] ?? 'dineflow_user';
            $pass = $dbUrl['pass'] ?? 'dineflow_password';
            try {
                self::$pdo = new PDO($dsn, $user, $pass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]);
                return self::$pdo;
            } catch (PDOException $e) {
                // Fallback to SQLite if Docker/Postgres is not running
            }
        }

        // Default SQLite
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

    public static function initSchema(): void
    {
        $db = self::getConnection();
        $schemaFile = __DIR__ . '/../../database/schema.sql';
        if (file_exists($schemaFile)) {
            $sql = file_get_contents($schemaFile);
            $db->exec($sql);
        }
    }
}
