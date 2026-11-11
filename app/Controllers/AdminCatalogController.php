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
            SELECT dt.*, ts.session_token, ts.total_amount, ts.created_at as session_started_at
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
}
