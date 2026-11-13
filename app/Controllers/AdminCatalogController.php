<?php

namespace App\Controllers;

use App\Core\Database;
use PDO;

class AdminCatalogController
{
    public function getTables(): void
    {
        $db = Database::getConnection();
        $stmt = $db->query("
            SELECT dt.*, ts.session_token, ts.session_token as current_session_token, ts.total_amount, ts.created_at as session_started_at
            FROM dining_tables dt
            LEFT JOIN table_sessions ts ON dt.current_session_id = ts.id
            ORDER BY dt.id ASC
        ");
        $tables = $stmt->fetchAll();
        echo json_encode($tables);
    }

    public function createTable(): void
    {
        $data = json_decode(file_get_contents('php://input'), true);
        $tableNumber = trim($data['table_number'] ?? '');
        if (!$tableNumber) {
            http_response_code(400);
            echo json_encode(['error' => 'table_number is required']);
            return;
        }

        $db = Database::getConnection();
        $stmt = $db->prepare("INSERT INTO dining_tables (table_number, status) VALUES (?, 'AVAILABLE')");
        $stmt->execute([$tableNumber]);

        echo json_encode([
            'id' => (int)$db->lastInsertId(),
            'table_number' => $tableNumber,
            'status' => 'AVAILABLE'
        ]);
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

    public function createMenuItem(): void
    {
        $data = json_decode(file_get_contents('php://input'), true);
        $name = trim($data['name'] ?? '');
        $categoryId = (int)($data['category_id'] ?? 1);
        $price = (float)($data['price'] ?? 0);
        $desc = $data['description'] ?? '';
        $isHot = !empty($data['is_hot']) ? 1 : 0;

        $db = Database::getConnection();
        $stmt = $db->prepare("INSERT INTO menu_items (category_id, name, description, price, is_available, is_hot) VALUES (?, ?, ?, ?, 1, ?)");
        $stmt->execute([$categoryId, $name, $desc, $price, $isHot]);

        echo json_encode([
            'id' => (int)$db->lastInsertId(),
            'name' => $name,
            'price' => $price,
            'status' => 'created'
        ]);
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
        foreach ($rows as $r) {
            $catId = (int)$r['category_id'];
            if (!isset($grouped[$catId])) {
                $grouped[$catId] = [
                    'id' => $catId,
                    'name' => $r['category_name'],
                    'display_order' => (int)$r['display_order'],
                    'items' => []
                ];
            }
            $r['is_available'] = (bool)$r['is_available'];
            $r['is_hot'] = (bool)$r['is_hot'];
            $r['price'] = (float)$r['price'];
            $grouped[$catId]['items'][] = $r;
        }

        $toppings = [];
        try {
            $stmtTop = $db->query("SELECT id, name, price, price as extra_price, is_available FROM toppings ORDER BY id ASC");
            $toppings = $stmtTop ? $stmtTop->fetchAll() : [];
            foreach ($toppings as &$t) {
                $t['price'] = (float)$t['price'];
                $t['extra_price'] = (float)$t['extra_price'];
                $t['is_available'] = (bool)$t['is_available'];
            }
            unset($t);
        } catch (\Throwable $e) {
            $toppings = [];
        }

        echo json_encode([
            'categories' => array_values($grouped),
            'available_toppings' => $toppings
        ], JSON_UNESCAPED_UNICODE);
    }

    public function toggleMenuItemAvailability(int $itemId): void
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT id, is_available FROM menu_items WHERE id = ?");
        $stmt->execute([$itemId]);
        $item = $stmt->fetch();
        if (!$item) {
            http_response_code(404);
            echo json_encode(['error' => 'Menu item not found']);
            return;
        }

        $newVal = isset($_GET['is_available'])
            ? (filter_var($_GET['is_available'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0)
            : ((int)$item['is_available'] ? 0 : 1);

        $db->prepare("UPDATE menu_items SET is_available = ? WHERE id = ?")->execute([$newVal, $itemId]);
        echo json_encode([
            'id' => $itemId,
            'is_available' => (bool)$newVal,
            'status' => 'updated'
        ]);
    }

    public function toggleToppingAvailability(int $toppingId): void
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT id, is_available FROM toppings WHERE id = ?");
        $stmt->execute([$toppingId]);
        $top = $stmt->fetch();
        if (!$top) {
            http_response_code(404);
            echo json_encode(['error' => 'Topping not found']);
            return;
        }

        $newVal = isset($_GET['is_available'])
            ? (filter_var($_GET['is_available'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0)
            : ((int)$top['is_available'] ? 0 : 1);

        $db->prepare("UPDATE toppings SET is_available = ? WHERE id = ?")->execute([$newVal, $toppingId]);
        echo json_encode([
            'id' => $toppingId,
            'is_available' => (bool)$newVal,
            'status' => 'updated'
        ]);
    }
}
