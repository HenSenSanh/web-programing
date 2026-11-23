<?php

namespace App\Controllers;

require_once __DIR__ . '/../Services/AuditLogService.php';

use App\Core\Database;
use App\Services\AuditLogService;
use App\Services\EtlService;
use App\Services\OrderToppingSnapshotService;
use App\Services\SharedCartService;
use PDO;

class KdsPosController
{
    public function getKdsActiveItems(): void
    {
        $db = Database::getConnection();
        $stmt = $db->query("
            SELECT oi.*, dt.table_number, o.order_code, m.image_url
            FROM order_items oi
            JOIN orders o ON oi.order_id = o.id
            JOIN table_sessions ts ON o.session_id = ts.id
            JOIN dining_tables dt ON ts.table_id = dt.id
            LEFT JOIN menu_items m ON m.id = oi.menu_item_id
            WHERE oi.status IN ('PENDING', 'COOKING', 'READY')
            ORDER BY oi.id ASC
        ");
        $items = OrderToppingSnapshotService::attach($db, $stmt->fetchAll());
        echo json_encode($items, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public function updateItemStatus(): void
    {
        $data = json_decode(file_get_contents('php://input'), true);
        $itemId = (int)($data['item_id'] ?? $data['order_item_id'] ?? 0);
        $newStatus = strtoupper(trim($data['status'] ?? $data['new_status'] ?? ''));
        $chefId = !empty($data['chef_id']) ? (int)$data['chef_id'] : null;

        $validStatuses = ['PENDING', 'COOKING', 'READY', 'SERVED', 'COMPLETED', 'CANCELLED'];
        if (!in_array($newStatus, $validStatuses)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid status']);
            return;
        }

        $db = Database::getConnection();
        $stmtOld = $db->prepare("SELECT status FROM order_items WHERE id = ? LIMIT 1");
        $stmtOld->execute([$itemId]);
        $oldStatus = $stmtOld->fetchColumn();

        $completedAt = in_array($newStatus, ['READY', 'SERVED', 'COMPLETED']) ? date('Y-m-d H:i:s') : null;

        $stmt = $db->prepare("
            UPDATE order_items
            SET status = ?, chef_id = COALESCE(?, chef_id), completed_at = COALESCE(?, completed_at)
            WHERE id = ?
        ");
        $stmt->execute([$newStatus, $chefId, $completedAt, $itemId]);

        if ($oldStatus !== false && $oldStatus !== $newStatus) {
            AuditLogService::record(
                $db,
                'kds_status_change',
                'order_item',
                $itemId,
                ['status' => (string)$oldStatus],
                ['status' => $newStatus, 'chef_id' => $chefId]
            );
        }

        echo json_encode([
            'status' => 'success',
            'order_item_id' => $itemId,
            'new_status' => $newStatus,
            'completed_at' => $completedAt
        ]);
    }

    public function getKdsStockStatus(): void
    {
        $db = Database::getConnection();
        $stmtItems = $db->query("
            SELECT m.id, m.name, m.category_id, m.price, m.is_available, m.image_url, c.name AS category_name
            FROM menu_items m
            JOIN categories c ON c.id = m.category_id
            ORDER BY m.is_available ASC, c.display_order ASC, m.id ASC
        ");
        $items = [];
        $unavailableItems = 0;
        foreach (($stmtItems ? $stmtItems->fetchAll() : []) as $row) {
            $avail = (bool)$row['is_available'];
            if (!$avail) {
                $unavailableItems++;
            }
            $items[] = [
                'id' => (int)$row['id'],
                'name' => (string)$row['name'],
                'category_id' => (int)$row['category_id'],
                'category_name' => (string)$row['category_name'],
                'price' => (float)$row['price'],
                'is_available' => $avail,
                'image_url' => (string)($row['image_url'] ?? ''),
            ];
        }

        $stmtToppings = $db->query("
            SELECT id, name, price, is_available
            FROM toppings
            ORDER BY is_available ASC, id ASC
        ");
        $toppings = [];
        $unavailableToppings = 0;
        foreach (($stmtToppings ? $stmtToppings->fetchAll() : []) as $row) {
            $avail = (bool)$row['is_available'];
            if (!$avail) {
                $unavailableToppings++;
            }
            $toppings[] = [
                'id' => (int)$row['id'],
                'name' => (string)$row['name'],
                'price' => (float)$row['price'],
                'is_available' => $avail,
            ];
        }

        echo json_encode([
            'items' => $items,
            'toppings' => $toppings,
            'unavailable_items_count' => $unavailableItems,
            'unavailable_toppings_count' => $unavailableToppings,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public function getActivePosTables(): void
    {
        $db = Database::getConnection();
        $stmt = $db->query("
            SELECT dt.id, dt.table_number, dt.status, ts.id as session_id,
                   ts.total_amount, ts.created_at as opened_at,
                   SUM(CASE WHEN oi.id IS NOT NULL AND oi.status <> 'CANCELLED' THEN 1 ELSE 0 END) as total_items,
                   SUM(CASE WHEN oi.status = 'PENDING' THEN 1 ELSE 0 END) as pending_items
            FROM dining_tables dt
            LEFT JOIN table_sessions ts ON dt.current_session_id = ts.id AND ts.status = 'ACTIVE'
            LEFT JOIN orders o ON ts.id = o.session_id
            LEFT JOIN order_items oi ON o.id = oi.order_id
            GROUP BY dt.id, dt.table_number, dt.status, ts.id, ts.total_amount, ts.created_at
            ORDER BY dt.id ASC
        ");
        echo json_encode($stmt->fetchAll(), JSON_UNESCAPED_UNICODE);
    }

    public function getTableBill(int $tableId): void
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT dt.id as table_id, dt.table_number, ts.id as session_id, ts.created_at as opened_at
            FROM dining_tables dt
            JOIN table_sessions ts ON dt.current_session_id = ts.id
            WHERE dt.id = ? AND ts.status = 'ACTIVE'
        ");
        $stmt->execute([$tableId]);
        $tableInfo = $stmt->fetch();

        if (!$tableInfo) {
            http_response_code(404);
            echo json_encode(['error' => 'Bàn chưa được mở hoặc không có phiên hoạt động'], JSON_UNESCAPED_UNICODE);
            return;
        }

        // Lấy chi tiết món (loại trừ món đã CANCELLED)
        $stmtItems = $db->prepare("
            SELECT oi.id, oi.order_id, oi.item_name, oi.unit_price, oi.quantity, oi.subtotal, oi.status, m.image_url
            FROM order_items oi
            JOIN orders o ON oi.order_id = o.id
            LEFT JOIN menu_items m ON m.id = oi.menu_item_id
            WHERE o.session_id = ? AND oi.status <> 'CANCELLED'
            ORDER BY oi.id ASC
        ");
        $stmtItems->execute([$tableInfo['session_id']]);
        $items = OrderToppingSnapshotService::attach($db, $stmtItems->fetchAll());

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
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public function checkout(string $sessionIdentifier): void
    {
        $db = Database::getConnection();
        $payload = json_decode(file_get_contents('php://input') ?: '[]', true) ?: [];
        $paymentMethod = $payload['payment_method'] ?? 'CASH';
        $lock = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';

        $db->beginTransaction();
        try {
            if (ctype_digit($sessionIdentifier)) {
                $stmt = $db->prepare("SELECT * FROM table_sessions WHERE (id = ? OR table_id = ?) AND status = 'ACTIVE' ORDER BY created_at DESC LIMIT 1{$lock}");
                $stmt->execute([$sessionIdentifier, (int)$sessionIdentifier]);
            } else {
                $stmt = $db->prepare("SELECT * FROM table_sessions WHERE (id = ? OR session_token = ?) AND status = 'ACTIVE' ORDER BY created_at DESC LIMIT 1{$lock}");
                $stmt->execute([$sessionIdentifier, $sessionIdentifier]);
            }
            $session = $stmt->fetch();

            if (!$session) {
                $db->rollBack();
                http_response_code(400);
                echo json_encode(['error' => 'Phiên bàn không tồn tại hoặc đã được thanh toán trước đó'], JSON_UNESCAPED_UNICODE);
                return;
            }

            $actualSessionId = $session['id'];

            // Tính tổng tiền từ order_items (loại trừ món đã CANCELLED)
            $stmtSum = $db->prepare("
                SELECT COALESCE(SUM(oi.subtotal), 0) as subtotal
                FROM order_items oi
                JOIN orders o ON oi.order_id = o.id
                WHERE o.session_id = ? AND oi.status <> 'CANCELLED'
            ");
            $stmtSum->execute([$actualSessionId]);
            $subtotal = (float)$stmtSum->fetch()['subtotal'];

            $vat = $subtotal * 0.08;
            $finalAmount = round(($subtotal + $vat) / 500) * 500;
            $invoiceCode = 'INV-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid()), 0, 6));

            // Đóng session trước (idempotent guard chống thanh toán trùng đồng thời)
            $closedAt = date('Y-m-d H:i:s');
            $stmtClose = $db->prepare("UPDATE table_sessions SET status = 'CHECKOUT', total_amount = ?, closed_at = ? WHERE id = ? AND status = 'ACTIVE'");
            $stmtClose->execute([$subtotal, $closedAt, $actualSessionId]);
            if ($stmtClose->rowCount() !== 1) {
                $db->rollBack();
                http_response_code(400);
                echo json_encode(['error' => 'Phiên bàn không tồn tại hoặc đã được thanh toán trước đó'], JSON_UNESCAPED_UNICODE);
                return;
            }

            // Tạo Bill Payment
            $stmtBill = $db->prepare("
                INSERT INTO bill_payments (session_id, table_id, subtotal_amount, vat_amount, service_fee, final_amount, payment_method, invoice_code)
                VALUES (?, ?, ?, ?, 0.00, ?, ?, ?)
            ");
            $stmtBill->execute([$actualSessionId, $session['table_id'], $subtotal, $vat, $finalAmount, $paymentMethod, $invoiceCode]);
            $billId = (int)$db->lastInsertId();

            // Trả bàn về AVAILABLE
            $stmtTable = $db->prepare("UPDATE dining_tables SET status = 'AVAILABLE', current_session_id = NULL WHERE id = ?");
            $stmtTable->execute([$session['table_id']]);

            // Tự động đóng các yêu cầu gọi nhân viên đang chờ của phiên bàn vừa thanh toán
            try {
                $actorId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
                $resolvedBy = (is_int($actorId) && $actorId > 0) ? $actorId : null;
                $stmtResolveSr = $db->prepare("
                    UPDATE service_requests
                    SET status = 'RESOLVED', resolved_at = ?, resolved_by = COALESCE(resolved_by, ?)
                    WHERE session_id = ? AND status = 'PENDING'
                ");
                $stmtResolveSr->execute([$closedAt, $resolvedBy, $actualSessionId]);
            } catch (\Throwable $e) {
                // Bỏ qua nếu bảng service_requests chưa khởi tạo trong môi trường tối giản
            }

            $db->commit();

            AuditLogService::record(
                $db,
                'pos_checkout',
                'bill_payment',
                $billId,
                ['session_status' => 'ACTIVE'],
                [
                    'session_id' => $actualSessionId,
                    'table_id' => (int)$session['table_id'],
                    'invoice_code' => $invoiceCode,
                    'final_amount' => $finalAmount,
                    'payment_method' => $paymentMethod,
                ]
            );

            // Dọn giỏ hàng & tự động kích hoạt luồng ETL chuyển dữ liệu sang kho OLAP
            if (!empty($session['session_token'])) {
                SharedCartService::clearCart($session['session_token']);
            }
            EtlService::runEtlForClosedSession($actualSessionId);

            echo json_encode([
                'status' => 'success',
                'bill_id' => $billId,
                'invoice_code' => $invoiceCode,
                'subtotal' => $subtotal,
                'vat_amount' => $vat,
                'final_amount' => $finalAmount,
                'total_amount' => $finalAmount,
                'payment_method' => $paymentMethod,
                'closed_at' => $closedAt,
                'message' => 'Thanh toán thành công! Dữ liệu đã tự động đồng bộ sang kho OLAP.'
            ], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }
}
