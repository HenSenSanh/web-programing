<?php

namespace App\Controllers;

use App\Core\Database;
use App\Services\EtlService;
use PDO;

class KdsPosController
{
    public function getKdsActiveItems(): void
    {
        $db = Database::getConnection();
        $stmt = $db->query("
            SELECT oi.*, dt.table_number, o.order_code, ts.session_token
            FROM order_items oi
            JOIN orders o ON oi.order_id = o.id
            JOIN table_sessions ts ON o.session_id = ts.id
            JOIN dining_tables dt ON ts.table_id = dt.id
            WHERE oi.status IN ('PENDING', 'COOKING', 'READY')
            ORDER BY oi.id ASC
        ");
        echo json_encode($stmt->fetchAll());
    }

    public function updateItemStatus(): void
    {
        $data = json_decode(file_get_contents('php://input'), true);
        $itemId = (int)($data['item_id'] ?? $data['order_item_id'] ?? 0);
        $newStatus = strtoupper(trim($data['status'] ?? ''));
        $chefId = !empty($data['chef_id']) ? (int)$data['chef_id'] : null;

        $validStatuses = ['PENDING', 'COOKING', 'READY', 'SERVED', 'CANCELLED'];
        if (!in_array($newStatus, $validStatuses)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid status']);
            return;
        }

        $db = Database::getConnection();
        $completedAt = in_array($newStatus, ['READY', 'SERVED']) ? date('Y-m-d H:i:s') : null;

        $stmt = $db->prepare("
            UPDATE order_items
            SET status = ?, chef_id = COALESCE(?, chef_id), completed_at = COALESCE(?, completed_at)
            WHERE id = ?
        ");
        $stmt->execute([$newStatus, $chefId, $completedAt, $itemId]);

        echo json_encode([
            'status' => 'success',
            'order_item_id' => $itemId,
            'new_status' => $newStatus,
            'completed_at' => $completedAt
        ]);
    }

    public function getActivePosTables(): void
    {
        $db = Database::getConnection();
        $stmt = $db->query("
            SELECT dt.id, dt.table_number, dt.status, ts.id as session_id, ts.session_token,
                   ts.total_amount, ts.created_at as opened_at,
                   COUNT(oi.id) as total_items,
                   SUM(CASE WHEN oi.status = 'PENDING' THEN 1 ELSE 0 END) as pending_items
            FROM dining_tables dt
            LEFT JOIN table_sessions ts ON dt.current_session_id = ts.id AND ts.status = 'ACTIVE'
            LEFT JOIN orders o ON ts.id = o.session_id
            LEFT JOIN order_items oi ON o.id = oi.order_id
            GROUP BY dt.id
            ORDER BY dt.id ASC
        ");
        echo json_encode($stmt->fetchAll());
    }

    public function getTableBill(int $tableId): void
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT dt.id as table_id, dt.table_number, ts.id as session_id, ts.session_token, ts.created_at as opened_at
            FROM dining_tables dt
            JOIN table_sessions ts ON dt.current_session_id = ts.id
            WHERE dt.id = ? AND ts.status = 'ACTIVE'
        ");
        $stmt->execute([$tableId]);
        $tableInfo = $stmt->fetch();

        if (!$tableInfo) {
            http_response_code(404);
            echo json_encode(['error' => 'Bàn chưa được mở hoặc không có phiên hoạt động']);
            return;
        }

        // Lấy chi tiết món
        $stmtItems = $db->prepare("
            SELECT oi.id, oi.item_name, oi.unit_price, oi.quantity, oi.subtotal, oi.status
            FROM order_items oi
            JOIN orders o ON oi.order_id = o.id
            WHERE o.session_id = ?
            ORDER BY oi.id ASC
        ");
        $stmtItems->execute([$tableInfo['session_id']]);
        $items = $stmtItems->fetchAll();

        $subtotal = 0.0;
        foreach ($items as $it) {
            $subtotal += (float)$it['subtotal'];
        }

        // VAT 8%
        $vat = $subtotal * 0.08;
        $rawFinal = $subtotal + $vat;
        // Làm tròn mốc 500đ
        $finalAmount = round($rawFinal / 500) * 500;

        echo json_encode([
            'table_id' => $tableInfo['table_id'],
            'table_number' => $tableInfo['table_number'],
            'session_id' => $tableInfo['session_id'],
            'opened_at' => $tableInfo['opened_at'],
            'items' => $items,
            'subtotal' => $subtotal,
            'vat_percent' => 8,
            'vat_amount' => $vat,
            'service_fee' => 0,
            'final_amount' => $finalAmount
        ]);
    }

    public function checkout(string $sessionId): void
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM table_sessions WHERE id = ? AND status = 'ACTIVE'");
        $stmt->execute([$sessionId]);
        $session = $stmt->fetch();

        if (!$session) {
            http_response_code(400);
            echo json_encode(['error' => 'Phiên bàn không tồn tại hoặc đã được thanh toán trước đó']);
            return;
        }

        $payload = json_decode(file_get_contents('php://input'), true) ?: [];
        $paymentMethod = $payload['payment_method'] ?? 'CASH';

        $db->beginTransaction();
        try {
            // Tính tổng tiền từ order_items
            $stmtSum = $db->prepare("
                SELECT COALESCE(SUM(oi.subtotal), 0) as subtotal
                FROM order_items oi
                JOIN orders o ON oi.order_id = o.id
                WHERE o.session_id = ?
            ");
            $stmtSum->execute([$sessionId]);
            $subtotal = (float)$stmtSum->fetch()['subtotal'];

            $vat = $subtotal * 0.08;
            $finalAmount = round(($subtotal + $vat) / 500) * 500;
            $invoiceCode = 'INV-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid()), 0, 6));

            // Tạo Bill Payment
            $stmtBill = $db->prepare("
                INSERT INTO bill_payments (session_id, table_id, subtotal_amount, vat_amount, service_fee, final_amount, payment_method, invoice_code)
                VALUES (?, ?, ?, ?, 0.00, ?, ?, ?)
            ");
            $stmtBill->execute([$sessionId, $session['table_id'], $subtotal, $vat, $finalAmount, $paymentMethod, $invoiceCode]);
            $billId = (int)$db->lastInsertId();

            // Đóng session
            $closedAt = date('Y-m-d H:i:s');
            $stmtClose = $db->prepare("UPDATE table_sessions SET status = 'CHECKOUT', closed_at = ? WHERE id = ?");
            $stmtClose->execute([$closedAt, $sessionId]);

            // Trả bàn về AVAILABLE
            $stmtTable = $db->prepare("UPDATE dining_tables SET status = 'AVAILABLE', current_session_id = NULL WHERE id = ?");
            $stmtTable->execute([$session['table_id']]);

            $db->commit();

            // Tự động kích hoạt luồng ETL chuyển dữ liệu sang kho OLAP
            EtlService::runEtlForClosedSession($sessionId);

            echo json_encode([
                'status' => 'success',
                'bill_id' => $billId,
                'invoice_code' => $invoiceCode,
                'subtotal' => $subtotal,
                'vat_amount' => $vat,
                'final_amount' => $finalAmount,
                'payment_method' => $paymentMethod,
                'closed_at' => $closedAt,
                'message' => 'Thanh toán thành công! Dữ liệu đã tự động đồng bộ sang kho OLAP.'
            ]);
        } catch (\Throwable $e) {
            $db->rollBack();
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }
}
