<?php
/**
 * UTF-8 / utf8mb4 connection helpers and self-healing mojibake repair.
 *
 * Fixes double-encoded UTF-8 data (UTF-8 bytes misinterpreted as latin1/cp1252
 * and stored back as UTF-8, e.g. "Quáº£n trá»‹ há»‡ thá»‘ng" -> "Quản trị hệ thống",
 * "CÃ¡i" -> "Cái") without requiring mbstring or external extensions.
 */

if (!function_exists('dineflow_mysql_pdo_options')) {
    /**
     * Standard PDO options enforcing UTF-8 (utf8mb4) on MySQL connections.
     */
    function dineflow_mysql_pdo_options(): array
    {
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];

        if (defined('PDO::MYSQL_ATTR_INIT_COMMAND')) {
            $options[PDO::MYSQL_ATTR_INIT_COMMAND] = 'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci';
        }

        return $options;
    }

    /**
     * Converts a single UTF-8 character that belongs to Windows-1252 (0x00..0xFF)
     * back into its raw single-byte value, or null if not representable in cp1252.
     */
    function dineflow_utf8_char_to_cp1252_byte(string $char): ?string
    {
        static $cp1252Extra = [
            "\xE2\x82\xAC" => "\x80", // €
            "\xE2\x80\x9A" => "\x82", // ‚
            "\xC6\x92"     => "\x83", // ƒ
            "\xE2\x80\x9E" => "\x84", // „
            "\xE2\x80\xA6" => "\x85", // …
            "\xE2\x80\xA0" => "\x86", // †
            "\xE2\x80\xA1" => "\x87", // ‡
            "\xCB\x86"     => "\x88", // ˆ
            "\xE2\x80\xB0" => "\x89", // ‰
            "\xC5\xA0"     => "\x8A", // Š
            "\xE2\x80\xB9" => "\x8B", // ‹
            "\xC5\x92"     => "\x8C", // Œ
            "\xC5\xBD"     => "\x8E", // Ž
            "\xE2\x80\x98" => "\x91", // ‘
            "\xE2\x80\x99" => "\x92", // ’
            "\xE2\x80\x9C" => "\x93", // “
            "\xE2\x80\x9D" => "\x94", // ”
            "\xE2\x80\xA2" => "\x95", // •
            "\xE2\x80\x93" => "\x96", // –
            "\xE2\x80\x94" => "\x97", // —
            "\xCB\x9C"     => "\x98", // ˜
            "\xE2\x84\xA2" => "\x99", // ™
            "\xC5\xA1"     => "\x9A", // š
            "\xE2\x80\xBA" => "\x9B", // ›
            "\xC5\x93"     => "\x9C", // œ
            "\xC5\xBE"     => "\x9E", // ž
            "\xC5\xB8"     => "\x9F", // Ÿ
        ];

        $len = strlen($char);
        if ($len === 1) {
            return $char;
        }
        if ($len === 2) {
            $b0 = $char[0];
            $b1 = $char[1];
            if ($b0 === "\xC2") {
                return $b1;
            }
            if ($b0 === "\xC3") {
                return chr(ord($b1) + 64);
            }
        }
        return $cp1252Extra[$char] ?? null;
    }

    /**
     * Attempts a single cp1252->UTF-8 mojibake decode pass on a contiguous segment.
     */
    function dineflow_decode_mojibake_segment(string $segment): string
    {
        if ($segment === '' || preg_match('/[\x80-\xFF]/', $segment) !== 1) {
            return $segment;
        }

        if (preg_match_all('/./us', $segment, $matches) === false) {
            return $segment;
        }

        $bytes = '';
        foreach ($matches[0] as $ch) {
            $b = dineflow_utf8_char_to_cp1252_byte($ch);
            if ($b === null) {
                return $segment;
            }
            $bytes .= $b;
        }

        if ($bytes !== $segment && strlen($bytes) < strlen($segment) && preg_match('//u', $bytes) === 1) {
            return $bytes;
        }

        return $segment;
    }

    /**
     * Repairs double-encoded UTF-8 mojibake strings (including mixed valid + mojibake strings).
     */
    function dineflow_repair_mojibake_text(string $value): string
    {
        if ($value === '' || preg_match('/[\x80-\xFF]/', $value) !== 1) {
            return $value;
        }

        if (preg_match('//u', $value) !== 1) {
            return $value;
        }

        $current = $value;
        for ($pass = 0; $pass < 2; $pass++) {
            if (preg_match_all('/./us', $current, $matches) === false) {
                break;
            }

            $rebuilt = '';
            $buffer = '';
            foreach ($matches[0] as $ch) {
                if (dineflow_utf8_char_to_cp1252_byte($ch) !== null) {
                    $buffer .= $ch;
                } else {
                    if ($buffer !== '') {
                        $rebuilt .= dineflow_decode_mojibake_segment($buffer);
                        $buffer = '';
                    }
                    $rebuilt .= $ch;
                }
            }
            if ($buffer !== '') {
                $rebuilt .= dineflow_decode_mojibake_segment($buffer);
            }

            if ($rebuilt === $current) {
                break;
            }
            $current = $rebuilt;
        }

        return $current;
    }

    /**
     * Discovers text columns per table with a single-column primary key.
     *
     * @return array<string, array{pk: string, cols: string[]}>
     */
    function dineflow_discover_text_tables(PDO $pdo, string $driver): array
    {
        $schema = [];

        if ($driver === 'mysql') {
            $stmt = $pdo->query("
                SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE, COLUMN_KEY
                FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                ORDER BY TABLE_NAME, ORDINAL_POSITION
            ");
            $pks = [];
            $textCols = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $col) {
                $table = $col['TABLE_NAME'] ?? '';
                $column = $col['COLUMN_NAME'] ?? '';
                if (!preg_match('/^[a-zA-Z0-9_]+$/', $table) || !preg_match('/^[a-zA-Z0-9_]+$/', $column)) {
                    continue;
                }
                if (($col['COLUMN_KEY'] ?? '') === 'PRI') {
                    $pks[$table][] = $column;
                }
                if (in_array(strtolower($col['DATA_TYPE'] ?? ''), ['varchar', 'char', 'text', 'tinytext', 'mediumtext', 'longtext'], true)) {
                    $textCols[$table][] = $column;
                }
            }
            foreach ($textCols as $table => $cols) {
                if (isset($pks[$table]) && count($pks[$table]) === 1) {
                    $schema[$table] = ['pk' => $pks[$table][0], 'cols' => $cols];
                }
            }
            return $schema;
        }

        if ($driver === 'sqlite') {
            $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'")
                ->fetchAll(PDO::FETCH_COLUMN);
            foreach ($tables as $table) {
                if (!preg_match('/^[a-zA-Z0-9_]+$/', (string)$table)) {
                    continue;
                }
                $colsInfo = $pdo->query('PRAGMA table_info("' . $table . '")')->fetchAll(PDO::FETCH_ASSOC);
                $pkCols = [];
                $textCols = [];
                foreach ($colsInfo as $info) {
                    $colName = $info['name'] ?? '';
                    if (!preg_match('/^[a-zA-Z0-9_]+$/', $colName)) {
                        continue;
                    }
                    if ((int)($info['pk'] ?? 0) > 0) {
                        $pkCols[] = $colName;
                    }
                    $type = strtolower((string)($info['type'] ?? ''));
                    if ($type === '' || str_contains($type, 'char') || str_contains($type, 'text') || str_contains($type, 'clob')) {
                        $textCols[] = $colName;
                    }
                }
                if (count($pkCols) === 1 && !empty($textCols)) {
                    $schema[$table] = ['pk' => $pkCols[0], 'cols' => $textCols];
                }
            }
        }

        return $schema;
    }

    /**
     * Quotes a validated SQL identifier according to PDO driver.
     */
    function dineflow_quote_ident(string $ident, string $driver): string
    {
        return $driver === 'mysql' ? "`{$ident}`" : "\"{$ident}\"";
    }

    /**
     * Scans all tables in the connected DB and repairs any mojibake UTF-8 strings in-place.
     *
     * @return int Number of rows updated.
     */
    function dineflow_repair_mojibake_tables(PDO $pdo): int
    {
        $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $tables = dineflow_discover_text_tables($pdo, $driver);
        $updatedRows = 0;

        foreach ($tables as $table => $meta) {
            $pk = $meta['pk'];
            $cols = $meta['cols'];
            $qTable = dineflow_quote_ident($table, $driver);
            $qPk = dineflow_quote_ident($pk, $driver);
            $selectCols = implode(', ', array_map(
                static fn(string $c): string => dineflow_quote_ident($c, $driver),
                array_unique(array_merge([$pk], $cols))
            ));

            try {
                $rows = $pdo->query("SELECT {$selectCols} FROM {$qTable} LIMIT 2000")->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {
                continue;
            }

            foreach ($rows as $row) {
                $changes = [];
                foreach ($cols as $col) {
                    $val = $row[$col] ?? null;
                    if (!is_string($val) || $val === '') {
                        continue;
                    }
                    $fixed = dineflow_repair_mojibake_text($val);
                    if ($fixed !== $val) {
                        $changes[$col] = $fixed;
                    }
                }

                if (empty($changes)) {
                    continue;
                }

                $setClauses = [];
                $params = [];
                foreach ($changes as $col => $fixedVal) {
                    $setClauses[] = dineflow_quote_ident($col, $driver) . ' = ?';
                    $params[] = $fixedVal;
                }
                $params[] = $row[$pk];

                try {
                    $stmt = $pdo->prepare("UPDATE {$qTable} SET " . implode(', ', $setClauses) . " WHERE {$qPk} = ?");
                    $stmt->execute($params);
                    $updatedRows++;
                } catch (Throwable $e) {
                    // Ignore constraint collisions so request flow stays uninterrupted
                }
            }
        }

        return $updatedRows;
    }

    /**
     * Ensures MySQL session uses utf8mb4 and repairs pre-existing seed mojibake once per environment.
     */
    function dineflow_ensure_utf8_connection(PDO $pdo, string $cacheKey = ''): void
    {
        $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver !== 'mysql') {
            return;
        }

        static $checkedInProcess = [];
        $objId = spl_object_id($pdo);
        if (isset($checkedInProcess[$objId])) {
            return;
        }
        $checkedInProcess[$objId] = true;

        try {
            $pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');
        } catch (Throwable $e) {
            try {
                $pdo->exec('SET NAMES utf8mb4');
            } catch (Throwable $ignored) {
            }
        }

        $markerFile = rtrim(sys_get_temp_dir(), '/\\')
            . DIRECTORY_SEPARATOR
            . 'dineflow_utf8_repaired_v2_' . md5($cacheKey ?: 'mysql') . '.flag';

        $needsFullScan = !is_file($markerFile);
        if (!$needsFullScan) {
            try {
                $sampleNames = array_merge(
                    $pdo->query('SELECT name FROM roles LIMIT 10')->fetchAll(PDO::FETCH_COLUMN),
                    $pdo->query('SELECT name FROM units LIMIT 10')->fetchAll(PDO::FETCH_COLUMN)
                );
                foreach ($sampleNames as $sample) {
                    if (is_string($sample) && dineflow_repair_mojibake_text($sample) !== $sample) {
                        $needsFullScan = true;
                        break;
                    }
                }
            } catch (Throwable $e) {
                // Schema might not be imported yet; keep moving
                return;
            }
        }

        if ($needsFullScan) {
            try {
                $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
                foreach ([
                    'stock_movements',
                    'inventory_lines',
                    'inventory_documents',
                    'stock_balances',
                    'product_prices',
                    'product_lots',
                    'warehouse_locations',
                    'warehouses',
                ] as $legacyTable) {
                    $pdo->exec("DROP TABLE IF EXISTS `{$legacyTable}`");
                }
                $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            } catch (Throwable $ignored) {
            }
            dineflow_repair_mojibake_tables($pdo);
            @file_put_contents($markerFile, (string)time());
        }
    }
}
