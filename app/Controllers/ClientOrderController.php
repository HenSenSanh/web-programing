<?php

namespace App\Controllers;

use App\Core\Database;
use App\Services\SharedCartService;
use PDO;

class ClientOrderController
{
    public function getSessionInfo(string $token): void
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT ts.*, dt.table_number
            FROM table_sessions ts
            JOIN dining_tables dt ON ts.table_id = dt.id
            WHERE ts.session_token = ?
        ");
        $stmt->execute([$token]);
        $session = $stmt->fetch();

        if (!$session) {
            http_response_code(404);
            echo json_encode(['error' => 'Invalid or expired session token']);
            return;
        }

        echo json_encode($session);
    }

    public function getCart(string $token): void
    {
        $cart = SharedCartService::getCart($token);
        echo json_encode($cart);
    }

    public function updateCart(string $token, ?array $payload = null): void
    {
        $data = $payload ?? json_decode(file_get_contents('php://input') ?: '[]', true);
        $cart = SharedCartService::addItem($token, $data);
        echo json_encode($cart);
    }

    public function submitOrder(string $token, ?array $customPayload = null): void
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM table_sessions WHERE session_token = ? AND status = 'ACTIVE'");
        $stmt->execute([$token]);
        $session = $stmt->fetch();

        if (!$session) {
            http_response_code(400);
            echo json_encode(['error' => 'Phiên bàn không tồn tại hoặc đã đóng']);
            return;
        }

        $idempotencyKey = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? null;
        if ($idempotencyKey) {
            $stmtCheck = $db->prepare("SELECT * FROM orders WHERE idempotency_key = ?");
            $stmtCheck->execute([$idempotencyKey]);
            $existing = $stmtCheck->fetch();
            if ($existing) {
                http_response_code(409);
                echo json_encode(['error' => 'Đơn hàng trùng lặp đã được tiếp nhận', 'order_id' => $existing['id']]);
                return;
            }
        }

        $payload = $customPayload ?? json_decode(file_get_contents('php://input') ?: '[]', true);
        $items = is_array($payload) && isset($payload['items']) ? $payload['items'] : $payload;

        if (empty($items) || !is_array($items)) {
            http_response_code(422);
            echo json_encode(['error' => 'Danh sách món đặt không được rỗng']);
            return;
        }

        $db->beginTransaction();
        try {
            $orderCode = 'ORD-' . strtoupper(substr(md5(uniqid()), 0, 8));
            $orderTotal = 0.0;
            $orderItemsToInsert = [];

            // Query items from DB to enforce Server-side price (INV-1: Anti-Tamper)
            $stmtItem = $db->prepare("SELECT id, name, price, is_available FROM menu_items WHERE id = ?");

            foreach ($items as $item) {
                $itemId = (int)($item['menu_item_id'] ?? $item['id'] ?? 0);
                $qty = max(1, (int)($item['quantity'] ?? 1));

                $stmtItem->execute([$itemId]);
                $realItem = $stmtItem->fetch();
                if (!$realItem) {
                    throw new \Exception("Món ăn ID #{$itemId} không tồn tại trên thực đơn");
                }

                // Luôn lấy giá từ Database, KHÔNG tin giá từ Client
                $unitPrice = (float)$realItem['price'];
                $subtotal = $unitPrice * $qty;
                $orderTotal += $subtotal;

                $orderItemsToInsert[] = [
                    'menu_item_id' => $realItem['id'],
                    'item_name' => $realItem['name'],
                    'unit_price' => $unitPrice,
                    'quantity' => $qty,
                    'subtotal' => $subtotal,
                    'notes' => $item['notes'] ?? ''
                ];
            }

            // Tạo Order
            $stmtOrder = $db->prepare("
                INSERT INTO orders (session_id, order_code, status, total_amount, idempotency_key)
                VALUES (?, ?, 'CONFIRMED', ?, ?)
            ");
            $stmtOrder->execute([$session['id'], $orderCode, $orderTotal, $idempotencyKey]);
            $orderId = (int)$db->lastInsertId();

            // Tạo Order Items
            $stmtOrderItem = $db->prepare("
                INSERT INTO order_items (order_id, menu_item_id, item_name, unit_price, quantity, subtotal, status, notes)
                VALUES (?, ?, ?, ?, ?, ?, 'PENDING', ?)
            ");

            foreach ($orderItemsToInsert as $oi) {
                $stmtOrderItem->execute([
                    $orderId,
                    $oi['menu_item_id'],
                    $oi['item_name'],
                    $oi['unit_price'],
                    $oi['quantity'],
                    $oi['subtotal'],
                    $oi['notes']
                ]);
            }

            // Cập nhật tổng tiền phiên bàn
            $stmtUpdateSession = $db->prepare("UPDATE table_sessions SET total_amount = total_amount + ? WHERE id = ?");
            $stmtUpdateSession->execute([$orderTotal, $session['id']]);

            $db->commit();

            // Dọn giỏ hàng
            SharedCartService::clearCart($token);

            echo json_encode([
                'status' => 'success',
                'order_id' => $orderId,
                'order_code' => $orderCode,
                'total_amount' => $orderTotal,
                'items_count' => count($orderItemsToInsert),
                'message' => 'Đã gửi đơn xuống Bếp KDS thành công!'
            ]);
        } catch (\Throwable $e) {
            $db->rollBack();
            http_response_code(400);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    public function getOrderStatus(string $token): void
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT oi.*, o.order_code
            FROM order_items oi
            JOIN orders o ON oi.order_id = o.id
            JOIN table_sessions ts ON o.session_id = ts.id
            WHERE ts.session_token = ?
            ORDER BY oi.id DESC
        ");
        $stmt->execute([$token]);
        echo json_encode($stmt->fetchAll());
    }
}
