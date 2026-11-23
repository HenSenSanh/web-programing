<?php

namespace App\Controllers;

require_once __DIR__ . '/../Services/AuditLogService.php';

use App\Core\Database;
use PDO;

class AdminCatalogController
{
    public function getTables(): void
    {
        $db = Database::getConnection();
        $stmt = $db->query("
            SELECT dt.*, ts.session_token, ts.session_token as current_session_token, ts.status as session_status, ts.total_amount, ts.created_at as session_started_at
            FROM dining_tables dt
            LEFT JOIN table_sessions ts ON dt.current_session_id = ts.id AND ts.status = 'ACTIVE'
            ORDER BY dt.id ASC
        ");
        $tables = $stmt->fetchAll();
        echo json_encode($tables, JSON_UNESCAPED_UNICODE);
    }

    public function getQrPublicUrl(): void
    {
        $config = require __DIR__ . '/../src/config.php';
        $envQrUrl = rtrim((string)($config['app']['qr_base_url'] ?? ''), '/');
        $renderUrl = rtrim((string)($config['app']['render_external_url'] ?? ''), '/');

        $tunnelUrl = '';
        $logPaths = ['/var/log/cloudflared/tunnel.log'];
        foreach ($logPaths as $logPath) {
            if (!is_file($logPath) || !is_readable($logPath)) {
                continue;
            }
            $size = (int)@filesize($logPath);
            $fp = @fopen($logPath, 'rb');
            if (!$fp) {
                continue;
            }
            if ($size > 262144) {
                $head = fread($fp, 65536) ?: '';
                fseek($fp, -196608, SEEK_END);
                $tail = stream_get_contents($fp) ?: '';
                $chunk = $head . "\n" . $tail;
            } else {
                $chunk = stream_get_contents($fp) ?: '';
            }
            fclose($fp);
            if (preg_match_all('#https://[a-z0-9-]+\.trycloudflare\.com#i', $chunk, $matches) && !empty($matches[0])) {
                $tunnelUrl = rtrim((string)end($matches[0]), '/');
                break;
            }
        }

        $effectiveUrl = $envQrUrl !== ''
            ? $envQrUrl
            : ($renderUrl !== '' ? $renderUrl : $tunnelUrl);

        echo json_encode([
            'tunnel_url' => $tunnelUrl,
            'render_url' => $renderUrl,
            'env_qr_url' => $envQrUrl,
            'effective_url' => $effectiveUrl,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function getAuditLogs(): void
    {
        $db = Database::getConnection();
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
        $entityType = isset($_GET['entity_type']) ? trim((string)$_GET['entity_type']) : null;
        $logs = \App\Services\AuditLogService::listRecent($db, $limit, $entityType ?: null);
        echo json_encode([
            'items' => $logs,
            'count' => count($logs),
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public function createTable(): void
    {
        $data = json_decode(file_get_contents('php://input') ?: '[]', true);
        $tableNumber = trim((string)($data['table_number'] ?? ''));
        if ($tableNumber === '' || strlen($tableNumber) > 20) {
            http_response_code(400);
            echo json_encode(['error' => 'table_number is required']);
            return;
        }

        $db = Database::getConnection();
        $stmtCheck = $db->prepare("SELECT id FROM dining_tables WHERE table_number = ? LIMIT 1");
        $stmtCheck->execute([$tableNumber]);
        if ($stmtCheck->fetch()) {
            http_response_code(409);
            echo json_encode(['error' => 'Số bàn đã tồn tại']);
            return;
        }

        $stmt = $db->prepare("INSERT INTO dining_tables (table_number, status) VALUES (?, 'AVAILABLE')");
        $stmt->execute([$tableNumber]);

        echo json_encode([
            'id' => (int)$db->lastInsertId(),
            'table_number' => $tableNumber,
            'status' => 'AVAILABLE'
        ], JSON_UNESCAPED_UNICODE);
    }

    public function openSession(int $tableId): void
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM dining_tables WHERE id = ?");
        $stmt->execute([$tableId]);
        $table = $stmt->fetch();

        if (!$table) {
            http_response_code(404);
            echo json_encode(['error' => 'Table not found']);
            return;
        }

        // Bảo vệ phiên đang hoạt động (tránh vô tình xoay mất token QR của bàn đang có khách)
        if (!empty($table['current_session_id'])) {
            $stmtActive = $db->prepare("SELECT id, session_token FROM table_sessions WHERE id = ? AND status = 'ACTIVE' LIMIT 1");
            $stmtActive->execute([$table['current_session_id']]);
            $activeSess = $stmtActive->fetch();
            if ($activeSess) {
                http_response_code(409);
                echo json_encode([
                    'error' => 'Bàn này đang có phiên phục vụ hoạt động',
                    'session_id' => $activeSess['id'],
                    'session_token' => $activeSess['session_token'],
                ], JSON_UNESCAPED_UNICODE);
                return;
            }
        }

        // Tự động khôi phục nếu bàn OCCUPIED bị đứt liên kết current_session_id nhưng vẫn còn phiên ACTIVE
        $stmtOrphan = $db->prepare("SELECT id, session_token FROM table_sessions WHERE table_id = ? AND status = 'ACTIVE' ORDER BY created_at DESC LIMIT 1");
        $stmtOrphan->execute([$tableId]);
        $orphanSess = $stmtOrphan->fetch();
        if ($orphanSess) {
            $db->prepare("UPDATE dining_tables SET status = 'OCCUPIED', current_session_id = ? WHERE id = ?")
                ->execute([$orphanSess['id'], $tableId]);
            echo json_encode([
                'status' => 'success',
                'recovered' => true,
                'table_id' => $tableId,
                'session_id' => $orphanSess['id'],
                'session_token' => $orphanSess['session_token'],
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        // Tạo session mới
        $sessionId = 'sess_' . bin2hex(random_bytes(8));
        $sessionToken = bin2hex(random_bytes(16));

        $db->beginTransaction();
        try {
            $stmtSess = $db->prepare("INSERT INTO table_sessions (id, table_id, session_token, status, guest_count) VALUES (?, ?, ?, 'ACTIVE', 1)");
            $stmtSess->execute([$sessionId, $tableId, $sessionToken]);

            $stmtUpdateTable = $db->prepare("UPDATE dining_tables SET status = 'OCCUPIED', current_session_id = ? WHERE id = ?");
            $stmtUpdateTable->execute([$sessionId, $tableId]);

            $db->commit();

            echo json_encode([
                'session_id' => $sessionId,
                'session_token' => $sessionToken,
                'table_id' => $tableId,
                'table_number' => $table['table_number'],
                'qr_url' => "/frontend/menu/index.html?token={$sessionToken}",
                'status' => 'ACTIVE'
            ]);
        } catch (\Throwable $e) {
            $db->rollBack();
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    public function getCategories(): void
    {
        $db = Database::getConnection();
        $stmt = $db->query("SELECT * FROM categories ORDER BY display_order ASC");
        echo json_encode($stmt->fetchAll());
    }

    public function getMenuItems(): void
    {
        $db = Database::getConnection();
        $stmt = $db->query("
            SELECT m.*, c.name as category_name
            FROM menu_items m
            JOIN categories c ON m.category_id = c.id
            ORDER BY c.display_order ASC, m.id ASC
        ");
        echo json_encode($stmt->fetchAll());
    }

    public function createMenuItem(?array $payload = null): void
    {
        $data = $payload ?? (json_decode(file_get_contents('php://input') ?: '[]', true) ?: []);
        $name = trim((string)($data['name'] ?? ''));
        $categoryId = filter_var($data['category_id'] ?? null, FILTER_VALIDATE_INT);
        $priceValue = $data['price'] ?? null;
        $description = trim((string)($data['description'] ?? ''));
        $price = is_numeric($priceValue) ? (float)$priceValue : -1;
        $isHot = filter_var($data['is_hot'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        $imageUrl = $this->sanitizeImageUrl($data['image_url'] ?? '');

        if ($name === '' || strlen($name) > 150 || !is_int($categoryId) || $categoryId < 1 || $price < 0 || $imageUrl === null) {
            http_response_code(422);
            echo json_encode(['error' => 'Thông tin món ăn không hợp lệ'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $db = Database::getConnection();
        $db->beginTransaction();
        try {
            $stmtCategory = $db->prepare('SELECT id FROM categories WHERE id = ?');
            $stmtCategory->execute([$categoryId]);
            if (!$stmtCategory->fetch()) {
                $db->rollBack();
                http_response_code(422);
                echo json_encode(['error' => 'Danh mục món không tồn tại'], JSON_UNESCAPED_UNICODE);
                return;
            }

            $stmt = $db->prepare("
                INSERT INTO menu_items (category_id, name, description, price, is_available, is_hot, image_url)
                VALUES (?, ?, ?, ?, 1, ?, ?)
            ");
            $stmt->execute([$categoryId, $name, $description, $price, $isHot, $imageUrl !== '' ? $imageUrl : null]);
            $itemId = (int)$db->lastInsertId();

            $this->syncProductRecord($db, $itemId, $categoryId, $name);

            $newValue = [
                'name' => $name,
                'category_id' => $categoryId,
                'price' => $price,
                'description' => $description,
                'is_hot' => (bool)$isHot,
                'is_available' => true,
                'image_url' => $imageUrl !== '' ? $imageUrl : null,
            ];
            $this->writeAudit($db, 'create', 'menu_item', $itemId, null, $newValue);
            $db->commit();

            echo json_encode([
                'id' => $itemId,
                'name' => $name,
                'price' => $price,
                'status' => 'created'
            ], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            http_response_code(500);
            echo json_encode(['error' => 'Không thể tạo món: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    public function updateMenuItem(int $itemId, ?array $payload = null): void
    {
        $data = $payload ?? (json_decode(file_get_contents('php://input') ?: '[]', true) ?: []);
        $name = trim((string)($data['name'] ?? ''));
        $categoryId = filter_var($data['category_id'] ?? null, FILTER_VALIDATE_INT);
        $priceValue = $data['price'] ?? null;
        $description = trim((string)($data['description'] ?? ''));
        $price = is_numeric($priceValue) ? (float)$priceValue : -1;
        $isHot = filter_var($data['is_hot'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        $hasImageField = is_array($data) && array_key_exists('image_url', $data);
        $imageUrl = $hasImageField ? $this->sanitizeImageUrl($data['image_url'] ?? '') : '';

        if ($itemId < 1 || $name === '' || strlen($name) > 150 || !is_int($categoryId) || $categoryId < 1 || $price < 0 || $imageUrl === null) {
            http_response_code(422);
            echo json_encode(['error' => 'Thông tin món ăn không hợp lệ'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $db = Database::getConnection();
        $db->beginTransaction();
        try {
            $lock = $this->lockSuffix($db);
            $stmtItem = $db->prepare("SELECT * FROM menu_items WHERE id = ?{$lock}");
            $stmtItem->execute([$itemId]);
            $item = $stmtItem->fetch();
            if (!$item) {
                $db->rollBack();
                http_response_code(404);
                echo json_encode(['error' => 'Menu item not found']);
                return;
            }

            $stmtCategory = $db->prepare('SELECT id FROM categories WHERE id = ?');
            $stmtCategory->execute([$categoryId]);
            if (!$stmtCategory->fetch()) {
                $db->rollBack();
                http_response_code(422);
                echo json_encode(['error' => 'Danh mục món không tồn tại'], JSON_UNESCAPED_UNICODE);
                return;
            }

            $finalImageUrl = $hasImageField
                ? ($imageUrl !== '' ? $imageUrl : null)
                : ($item['image_url'] ?? null);

            $oldValue = [
                'name' => $item['name'],
                'category_id' => (int)$item['category_id'],
                'price' => (float)$item['price'],
                'description' => (string)($item['description'] ?? ''),
                'is_hot' => (bool)$item['is_hot'],
                'is_available' => (bool)$item['is_available'],
                'image_url' => $item['image_url'] ?? null,
            ];
            $newValue = [
                'name' => $name,
                'category_id' => $categoryId,
                'price' => $price,
                'description' => $description,
                'is_hot' => (bool)$isHot,
                'is_available' => (bool)$item['is_available'],
                'image_url' => $finalImageUrl,
            ];

            if ($oldValue !== $newValue) {
                $db->prepare("
                    UPDATE menu_items
                    SET category_id = ?, name = ?, description = ?, price = ?, is_hot = ?, image_url = ?
                    WHERE id = ?
                ")->execute([$categoryId, $name, $description, $price, $isHot, $finalImageUrl, $itemId]);
                $db->prepare("UPDATE products SET category_id = ?, name = ? WHERE id = ?")
                    ->execute([$categoryId, $name, $itemId]);
                $this->writeAudit($db, 'update', 'menu_item', $itemId, $oldValue, $newValue);
            }
            $db->commit();

            echo json_encode([
                'id' => $itemId,
                'status' => $oldValue === $newValue ? 'unchanged' : 'updated',
                'item' => $newValue,
            ], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            http_response_code(500);
            echo json_encode(['error' => 'Không thể cập nhật món: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    public function getMenuCatalog(): void
    {
        $db = Database::getConnection();
        $stmtItems = $db->query("
            SELECT m.*, c.name as category_name, c.display_order
            FROM menu_items m
            JOIN categories c ON m.category_id = c.id
            ORDER BY c.display_order ASC, m.id ASC
        ");
        $rows = $stmtItems ? $stmtItems->fetchAll() : [];

        $grouped = [];
        $stmtCategories = $db->query('SELECT id, name, display_order FROM categories ORDER BY display_order ASC, id ASC');
        foreach ($stmtCategories->fetchAll() as $category) {
            $categoryId = (int)$category['id'];
            $grouped[$categoryId] = [
                'id' => $categoryId,
                'name' => $category['name'],
                'display_order' => (int)$category['display_order'],
                'items' => [],
                'toppings' => [],
            ];
        }

        $itemsById = [];
        foreach ($rows as $r) {
            $r['is_available'] = (bool)$r['is_available'];
            $r['is_hot'] = (bool)$r['is_hot'];
            $r['price'] = (float)$r['price'];
            $r['toppings'] = [];
            $itemsById[(int)$r['id']] = $r;
        }

        $toppingsById = [];
        $stmtTop = $db->query('SELECT id, name, price, is_available FROM toppings ORDER BY id ASC');
        foreach ($stmtTop->fetchAll() as $row) {
            $toppingId = (int)$row['id'];
            $toppingsById[$toppingId] = [
                'id' => $toppingId,
                'name' => $row['name'],
                'price' => (float)$row['price'],
                'extra_price' => (float)$row['price'],
                'is_available' => (bool)$row['is_available'],
            ];
        }

        $stmtMappings = $db->query("
            SELECT mit.menu_item_id, mit.topping_id
            FROM menu_item_toppings mit
            ORDER BY mit.menu_item_id ASC, mit.topping_id ASC
        ");
        foreach ($stmtMappings->fetchAll() as $mapping) {
            $itemId = (int)$mapping['menu_item_id'];
            $toppingId = (int)$mapping['topping_id'];
            if (isset($itemsById[$itemId], $toppingsById[$toppingId])) {
                $itemsById[$itemId]['toppings'][] = $toppingsById[$toppingId];
            }
        }

        foreach ($itemsById as $item) {
            $categoryId = (int)$item['category_id'];
            if (isset($grouped[$categoryId])) {
                $grouped[$categoryId]['items'][] = $item;
            }
        }

        echo json_encode([
            'categories' => array_values($grouped),
            'available_toppings' => array_values($toppingsById)
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public function updateMenuItemToppings(int $itemId, ?array $payload = null): void
    {
        $data = $payload ?? (json_decode(file_get_contents('php://input') ?: '[]', true) ?: []);
        $rawToppingIds = is_array($data) ? ($data['topping_ids'] ?? null) : null;
        if ($itemId < 1 || !is_array($rawToppingIds)) {
            http_response_code(422);
            echo json_encode(['error' => 'topping_ids must be an array']);
            return;
        }

        $toppingIds = [];
        foreach ($rawToppingIds as $rawId) {
            $toppingId = filter_var($rawId, FILTER_VALIDATE_INT);
            if (!is_int($toppingId) || $toppingId < 1) {
                http_response_code(422);
                echo json_encode(['error' => 'Topping không hợp lệ'], JSON_UNESCAPED_UNICODE);
                return;
            }
            $toppingIds[] = $toppingId;
        }
        $toppingIds = array_values(array_unique($toppingIds));
        sort($toppingIds);

        $db = Database::getConnection();
        $db->beginTransaction();
        try {
            $stmtItem = $db->prepare("SELECT id FROM menu_items WHERE id = ?{$this->lockSuffix($db)}");
            $stmtItem->execute([$itemId]);
            if (!$stmtItem->fetch()) {
                $db->rollBack();
                http_response_code(404);
                echo json_encode(['error' => 'Menu item not found']);
                return;
            }

            if ($toppingIds !== []) {
                $placeholders = implode(',', array_fill(0, count($toppingIds), '?'));
                $stmtToppings = $db->prepare("SELECT id FROM toppings WHERE id IN ({$placeholders})");
                $stmtToppings->execute($toppingIds);
                $existingIds = array_map('intval', array_column($stmtToppings->fetchAll(), 'id'));
                sort($existingIds);
                if ($existingIds !== $toppingIds) {
                    $db->rollBack();
                    http_response_code(422);
                    echo json_encode(['error' => 'Một hoặc nhiều topping không tồn tại'], JSON_UNESCAPED_UNICODE);
                    return;
                }
            }

            $stmtCurrent = $db->prepare('SELECT topping_id FROM menu_item_toppings WHERE menu_item_id = ? ORDER BY topping_id ASC');
            $stmtCurrent->execute([$itemId]);
            $oldToppingIds = array_map('intval', array_column($stmtCurrent->fetchAll(), 'topping_id'));
            if ($oldToppingIds !== $toppingIds) {
                $db->prepare('DELETE FROM menu_item_toppings WHERE menu_item_id = ?')->execute([$itemId]);
                $stmtInsert = $db->prepare('INSERT INTO menu_item_toppings (menu_item_id, topping_id) VALUES (?, ?)');
                foreach ($toppingIds as $toppingId) {
                    $stmtInsert->execute([$itemId, $toppingId]);
                }
                $this->writeAudit(
                    $db,
                    'update',
                    'menu_item_topping',
                    $itemId,
                    ['topping_ids' => $oldToppingIds],
                    ['topping_ids' => $toppingIds]
                );
            }
            $db->commit();

            echo json_encode([
                'menu_item_id' => $itemId,
                'topping_ids' => $toppingIds,
                'status' => $oldToppingIds === $toppingIds ? 'unchanged' : 'updated',
            ], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            http_response_code(500);
            echo json_encode(['error' => 'Không thể cập nhật topping cho món: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    public function toggleMenuItemAvailability(int $itemId, ?array $payload = null): void
    {
        $db = Database::getConnection();
        $rawAvailability = $_GET['is_available'] ?? null;
        if ($rawAvailability === null) {
            $body = $payload ?? (json_decode(file_get_contents('php://input') ?: '[]', true) ?: []);
            if (is_array($body) && array_key_exists('is_available', $body)) {
                $rawAvailability = $body['is_available'];
            }
        }
        $requestedAvailability = $rawAvailability === null
            ? null
            : filter_var($rawAvailability, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($itemId < 1 || ($rawAvailability !== null && $requestedAvailability === null)) {
            http_response_code(422);
            echo json_encode(['error' => 'is_available must be true or false']);
            return;
        }

        $db->beginTransaction();
        try {
            $stmt = $db->prepare("SELECT id, is_available FROM menu_items WHERE id = ?{$this->lockSuffix($db)}");
            $stmt->execute([$itemId]);
            $item = $stmt->fetch();
            if (!$item) {
                $db->rollBack();
                http_response_code(404);
                echo json_encode(['error' => 'Menu item not found']);
                return;
            }

            $oldAvailability = (bool)$item['is_available'];
            $newAvailability = $requestedAvailability ?? !$oldAvailability;
            if ($oldAvailability !== $newAvailability) {
                $db->prepare('UPDATE menu_items SET is_available = ? WHERE id = ?')
                    ->execute([$newAvailability ? 1 : 0, $itemId]);
                $this->writeAudit(
                    $db,
                    'availability.update',
                    'menu_item',
                    $itemId,
                    ['is_available' => $oldAvailability],
                    ['is_available' => $newAvailability]
                );
            }
            $db->commit();

            echo json_encode([
                'id' => $itemId,
                'is_available' => $newAvailability,
                'status' => $oldAvailability === $newAvailability ? 'unchanged' : 'updated'
            ]);
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            http_response_code(500);
            echo json_encode(['error' => 'Không thể cập nhật trạng thái món: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    public function toggleToppingAvailability(int $toppingId, ?array $payload = null): void
    {
        $db = Database::getConnection();
        $rawAvailability = $_GET['is_available'] ?? null;
        if ($rawAvailability === null) {
            $body = $payload ?? (json_decode(file_get_contents('php://input') ?: '[]', true) ?: []);
            if (is_array($body) && array_key_exists('is_available', $body)) {
                $rawAvailability = $body['is_available'];
            }
        }
        $requestedAvailability = $rawAvailability === null
            ? null
            : filter_var($rawAvailability, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($toppingId < 1 || ($rawAvailability !== null && $requestedAvailability === null)) {
            http_response_code(422);
            echo json_encode(['error' => 'is_available must be true or false']);
            return;
        }

        $db->beginTransaction();
        try {
            $stmt = $db->prepare("SELECT id, is_available FROM toppings WHERE id = ?{$this->lockSuffix($db)}");
            $stmt->execute([$toppingId]);
            $top = $stmt->fetch();
            if (!$top) {
                $db->rollBack();
                http_response_code(404);
                echo json_encode(['error' => 'Topping not found']);
                return;
            }

            $oldAvailability = (bool)$top['is_available'];
            $newAvailability = $requestedAvailability ?? !$oldAvailability;
            if ($oldAvailability !== $newAvailability) {
                $db->prepare("UPDATE toppings SET is_available = ? WHERE id = ?")
                    ->execute([$newAvailability ? 1 : 0, $toppingId]);
                $this->writeAudit(
                    $db,
                    'availability.update',
                    'topping',
                    $toppingId,
                    ['is_available' => $oldAvailability],
                    ['is_available' => $newAvailability]
                );
            }
            $db->commit();

            echo json_encode([
                'id' => $toppingId,
                'is_available' => $newAvailability,
                'status' => $oldAvailability === $newAvailability ? 'unchanged' : 'updated'
            ]);
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            http_response_code(500);
            echo json_encode(['error' => 'Không thể cập nhật trạng thái topping: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    public function createTopping(?array $payload = null): void
    {
        $data = $payload ?? (json_decode(file_get_contents('php://input') ?: '[]', true) ?: []);
        $name = trim((string)($data['name'] ?? ''));
        $priceValue = $data['price'] ?? null;
        $price = is_numeric($priceValue) ? (float)$priceValue : -1.0;
        $isAvailable = array_key_exists('is_available', $data)
            ? (filter_var($data['is_available'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0)
            : 1;

        if ($name === '' || mb_strlen($name, 'UTF-8') > 100 || $price < 0) {
            http_response_code(422);
            echo json_encode(['error' => 'Tên topping (1-100 ký tự) và giá (>= 0) là bắt buộc'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $db = Database::getConnection();
        $db->beginTransaction();
        try {
            $stmt = $db->prepare('INSERT INTO toppings (name, price, is_available) VALUES (?, ?, ?)');
            $stmt->execute([$name, $price, $isAvailable]);
            $toppingId = (int)$db->lastInsertId();

            $newValue = [
                'name' => $name,
                'price' => $price,
                'is_available' => (bool)$isAvailable,
            ];
            $this->writeAudit($db, 'create', 'topping', $toppingId, null, $newValue);
            $db->commit();

            http_response_code(201);
            echo json_encode([
                'id' => $toppingId,
                'name' => $name,
                'price' => $price,
                'extra_price' => $price,
                'is_available' => (bool)$isAvailable,
                'status' => 'created',
            ], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            http_response_code(500);
            echo json_encode(['error' => 'Không thể tạo topping: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    public function updateTopping(int $toppingId, ?array $payload = null): void
    {
        $data = $payload ?? (json_decode(file_get_contents('php://input') ?: '[]', true) ?: []);
        $name = trim((string)($data['name'] ?? ''));
        $priceValue = $data['price'] ?? null;
        $price = is_numeric($priceValue) ? (float)$priceValue : -1.0;

        if ($toppingId < 1 || $name === '' || mb_strlen($name, 'UTF-8') > 100 || $price < 0) {
            http_response_code(422);
            echo json_encode(['error' => 'Thông tin topping không hợp lệ'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $db = Database::getConnection();
        $db->beginTransaction();
        try {
            $stmt = $db->prepare("SELECT id, name, price, is_available FROM toppings WHERE id = ?{$this->lockSuffix($db)}");
            $stmt->execute([$toppingId]);
            $existing = $stmt->fetch();
            if (!$existing) {
                $db->rollBack();
                http_response_code(404);
                echo json_encode(['error' => 'Topping not found']);
                return;
            }

            $oldValue = [
                'name' => (string)$existing['name'],
                'price' => (float)$existing['price'],
                'is_available' => (bool)$existing['is_available'],
            ];
            $newValue = [
                'name' => $name,
                'price' => $price,
                'is_available' => (bool)$existing['is_available'],
            ];

            if ($oldValue !== $newValue) {
                $db->prepare('UPDATE toppings SET name = ?, price = ? WHERE id = ?')
                    ->execute([$name, $price, $toppingId]);
                $this->writeAudit($db, 'update', 'topping', $toppingId, $oldValue, $newValue);
            }
            $db->commit();

            echo json_encode([
                'id' => $toppingId,
                'status' => $oldValue === $newValue ? 'unchanged' : 'updated',
                'topping' => $newValue,
            ], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            http_response_code(500);
            echo json_encode(['error' => 'Không thể cập nhật topping: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    private function sanitizeImageUrl(mixed $raw): ?string
    {
        $url = trim((string)$raw);
        if ($url === '') {
            return '';
        }
        if (strlen($url) > 255 || preg_match('/[\r\n\0]/', $url) || preg_match('/^\s*(javascript|vbscript|data:text\/html):/i', $url)) {
            return null;
        }
        return $url;
    }

    private function syncProductRecord(\PDO $db, int $itemId, int $categoryId, string $name): void
    {
        try {
            $stmtExists = $db->prepare('SELECT id FROM products WHERE id = ?');
            $stmtExists->execute([$itemId]);
            if ($stmtExists->fetch()) {
                $db->prepare('UPDATE products SET category_id = ?, name = ? WHERE id = ?')
                    ->execute([$categoryId, $name, $itemId]);
                return;
            }

            $unitId = (int)$db->query('SELECT id FROM units ORDER BY id ASC LIMIT 1')->fetchColumn();
            if ($unitId < 1) {
                return;
            }

            $sku = sprintf('DISH-%03d', $itemId);
            $stmtSku = $db->prepare('SELECT id FROM products WHERE sku = ?');
            $stmtSku->execute([$sku]);
            if ($stmtSku->fetch()) {
                $sku = 'DISH-' . $itemId . '-' . substr(bin2hex(random_bytes(2)), 0, 4);
            }

            $db->prepare("
                INSERT INTO products (id, category_id, sku, name, unit_id, min_stock, status)
                VALUES (?, ?, ?, ?, ?, 10, 'ACTIVE')
            ")->execute([$itemId, $categoryId, $sku, $name, $unitId]);
        } catch (\Throwable $e) {
            // Bỏ qua nếu bảng products/units chưa tồn tại trong schema tối giản
        }
    }

    private function lockSuffix(\PDO $db): string
    {
        return $db->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    }

    private function writeAudit(
        \PDO $db,
        string $action,
        string $entityType,
        int $entityId,
        ?array $oldValue,
        array $newValue
    ): void {
        $actorId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
        if (!is_int($actorId) || $actorId < 1) {
            throw new \RuntimeException('Authenticated actor is required for menu audit');
        }

        $stmt = $db->prepare("
            INSERT INTO audit_logs (actor_id, action, entity_type, entity_id, old_value, new_value)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $actorId,
            $action,
            $entityType,
            (string)$entityId,
            $oldValue === null ? null : json_encode($oldValue, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            json_encode($newValue, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ]);
    }
}
