<?php

namespace App\Controllers;

use App\Core\Database;
use App\Services\OrderToppingSnapshotService;
use App\Services\SharedCartService;
use PDO;

class ClientOrderController
{
    private const MAX_TOPPING_QUANTITY = 10;
    private bool $sqliteWriteTransaction = false;

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
        $data = $payload ?? (json_decode(file_get_contents('php://input') ?: '[]', true) ?: []);
        if (isset($data['items']) && is_array($data['items'])) {
            $cart = SharedCartService::setCart($token, $data['items']);
        } else {
            $cart = SharedCartService::addItem($token, $data);
        }
        echo json_encode($cart);
    }

    public function submitOrder(string $token, ?array $customPayload = null): void
    {
        $db = Database::getConnection();
        $payload = $customPayload ?? json_decode(file_get_contents('php://input') ?: '[]', true);
        $items = is_array($payload) && isset($payload['items']) ? $payload['items'] : $payload;

        if (empty($items) || !is_array($items)) {
            http_response_code(422);
            echo json_encode([
                'error' => 'Danh sách món đặt không được rỗng',
                'code' => 'EMPTY_ORDER',
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        try {
            $this->beginWriteTransaction($db);
            $lockSuffix = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';

            $stmt = $db->prepare("
                SELECT ts.*
                FROM table_sessions ts
                JOIN dining_tables dt
                  ON dt.id = ts.table_id
                 AND dt.current_session_id = ts.id
                WHERE ts.session_token = ? AND ts.status = 'ACTIVE'
                {$lockSuffix}
            ");
            $stmt->execute([$token]);
            $session = $stmt->fetch();
            if (!$session) {
                $this->rejectOrder($db, 400, 'INVALID_SESSION', 'Phiên bàn không tồn tại hoặc đã đóng');
                return;
            }

            $idempotencyKey = trim($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '');
            if ($idempotencyKey === '' || strlen($idempotencyKey) > 64) {
                $this->rejectOrder($db, 422, 'INVALID_IDEMPOTENCY_KEY', 'Thiếu hoặc sai Idempotency-Key');
                return;
            }

            $stmtCheck = $db->prepare("
                SELECT id, order_code, total_amount
                FROM orders
                WHERE session_id = ? AND idempotency_key = ?
            ");
            $stmtCheck->execute([$session['id'], $idempotencyKey]);
            $existing = $stmtCheck->fetch();

            if ($existing) {
                $stmtItems = $db->prepare("
                    SELECT id, menu_item_id, item_name, unit_price, quantity, subtotal, status, notes
                    FROM order_items
                    WHERE order_id = ?
                    ORDER BY id ASC
                ");
                $stmtItems->execute([$existing['id']]);
                $existingItems = OrderToppingSnapshotService::attach($db, $stmtItems->fetchAll());

                $this->rollbackWriteTransaction($db);
                http_response_code(409);
                echo json_encode([
                    'error' => 'Đơn hàng trùng lặp đã được tiếp nhận',
                    'code' => 'DUPLICATE_ORDER',
                    'order_id' => (int)$existing['id'],
                    'order_code' => $existing['order_code'],
                    'total_amount' => (float)$existing['total_amount'],
                    'items' => $existingItems,
                    'duplicate' => true,
                    'message' => 'Đơn này đã được tiếp nhận trước đó'
                ], JSON_UNESCAPED_UNICODE);
                return;
            }
            $orderCode = 'ORD-' . strtoupper(substr(md5(uniqid()), 0, 8));
            $orderTotal = 0.0;
            $orderItemsToInsert = [];

            $stmtItem = $db->prepare("SELECT id, name, price, is_available FROM menu_items WHERE id = ?{$lockSuffix}");
            $stmtTop = $db->prepare("SELECT id, name, price, is_available FROM toppings WHERE id = ?{$lockSuffix}");
            $stmtTopMap = $db->prepare("SELECT 1 FROM menu_item_toppings WHERE menu_item_id = ? AND topping_id = ? LIMIT 1");

            foreach ($items as $index => $item) {
                if (!is_array($item)) {
                    $this->rejectOrder($db, 422, 'INVALID_ITEM', 'Chi tiết món không hợp lệ', ['item_index' => $index]);
                    return;
                }

                $itemId = $this->parsePositiveInteger($item['menu_item_id'] ?? $item['id'] ?? null);
                if ($itemId === null) {
                    $this->rejectOrder($db, 422, 'INVALID_ITEM_ID', 'Mã món không hợp lệ', ['item_index' => $index]);
                    return;
                }

                $rawQuantity = $item['quantity'] ?? 1;
                $quantity = $this->parsePositiveInteger($rawQuantity);
                if ($quantity === null) {
                    $this->rejectOrder($db, 422, 'INVALID_QUANTITY', 'Số lượng món phải là số nguyên dương', [
                        'item_index' => $index,
                    ]);
                    return;
                }

                $stmtItem->execute([$itemId]);
                $realItem = $stmtItem->fetch();
                if (!$realItem) {
                    $this->rejectOrder($db, 404, 'MENU_ITEM_NOT_FOUND', 'Món ăn không tồn tại trên thực đơn', [
                        'item_index' => $index,
                        'menu_item_id' => $itemId,
                    ]);
                    return;
                }
                if ((int)$realItem['is_available'] !== 1) {
                    $this->rejectOrder($db, 409, 'MENU_ITEM_UNAVAILABLE', 'Món ăn hiện không còn phục vụ', [
                        'item_index' => $index,
                        'menu_item_id' => $itemId,
                    ]);
                    return;
                }

                // Luôn lấy giá từ Database, KHÔNG tin giá từ Client
                $unitPrice = (float)$realItem['price'];

                $toppingExtra = 0.0;
                $toppingSnapshots = [];
                $rawToppings = $item['selected_toppings'] ?? $item['toppings'] ?? [];
                if (!is_array($rawToppings)) {
                    $this->rejectOrder($db, 422, 'INVALID_TOPPINGS', 'Danh sách topping không hợp lệ', [
                        'item_index' => $index,
                    ]);
                    return;
                }
                $seenToppingIds = [];
                foreach ($rawToppings as $toppingIndex => $rawTopping) {
                    $topId = $this->parsePositiveInteger(
                        is_array($rawTopping) ? ($rawTopping['id'] ?? null) : $rawTopping
                    );
                    if ($topId === null || isset($seenToppingIds[$topId])) {
                        $this->rejectOrder($db, 422, 'INVALID_TOPPING_ID', 'Mã topping không hợp lệ hoặc bị lặp', [
                            'item_index' => $index,
                            'topping_index' => $toppingIndex,
                        ]);
                        return;
                    }
                    $seenToppingIds[$topId] = true;

                    $rawToppingQty = is_array($rawTopping) && array_key_exists('quantity', $rawTopping)
                        ? $rawTopping['quantity']
                        : 1;
                    $toppingQty = $this->parsePositiveInteger($rawToppingQty);
                    if ($toppingQty === null || $toppingQty > self::MAX_TOPPING_QUANTITY) {
                        $this->rejectOrder($db, 422, 'INVALID_TOPPING_QUANTITY', 'Số lượng topping phải là số nguyên từ 1 đến 10', [
                            'item_index' => $index,
                            'topping_index' => $toppingIndex,
                            'topping_id' => $topId,
                        ]);
                        return;
                    }

                    $stmtTop->execute([$topId]);
                    $topRow = $stmtTop->fetch();
                    if (!$topRow) {
                        $this->rejectOrder($db, 404, 'TOPPING_NOT_FOUND', 'Topping không tồn tại', [
                            'item_index' => $index,
                            'topping_id' => $topId,
                        ]);
                        return;
                    }
                    if ((int)$topRow['is_available'] !== 1) {
                        $this->rejectOrder($db, 409, 'TOPPING_UNAVAILABLE', 'Topping hiện không còn phục vụ', [
                            'item_index' => $index,
                            'topping_id' => $topId,
                        ]);
                        return;
                    }
                    $stmtTopMap->execute([$itemId, $topId]);
                    if (!$stmtTopMap->fetchColumn()) {
                        $this->rejectOrder($db, 422, 'TOPPING_NOT_ALLOWED_FOR_ITEM', 'Topping chưa được gán cho món ăn này', [
                            'item_index' => $index,
                            'menu_item_id' => $itemId,
                            'topping_id' => $topId,
                        ]);
                        return;
                    }
                    $toppingPrice = (float)$topRow['price'];
                    $toppingExtra += $toppingPrice * $toppingQty;
                    $toppingSnapshots[] = [
                        'id' => (int)$topRow['id'],
                        'name' => $topRow['name'],
                        'unit_price' => $toppingPrice,
                        'quantity' => $toppingQty,
                    ];
                }

                $unitPrice += $toppingExtra;
                $subtotal = $unitPrice * $quantity;
                $orderTotal += $subtotal;

                $orderItemsToInsert[] = [
                    'menu_item_id' => $realItem['id'],
                    'item_name' => $realItem['name'],
                    'unit_price' => $unitPrice,
                    'quantity' => $quantity,
                    'subtotal' => $subtotal,
                    'notes' => $item['notes'] ?? ($item['note'] ?? ''),
                    'toppings' => $toppingSnapshots,
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

            $insertedItemIds = [];
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
                $orderItemId = (int)$db->lastInsertId();
                $insertedItemIds[] = $orderItemId;
                OrderToppingSnapshotService::save($db, $orderItemId, $oi['toppings']);
            }

            // Cập nhật tổng tiền phiên bàn
            $stmtUpdateSession = $db->prepare("UPDATE table_sessions SET total_amount = total_amount + ? WHERE id = ? AND status = 'ACTIVE'");
            $stmtUpdateSession->execute([$orderTotal, $session['id']]);
            if ($stmtUpdateSession->rowCount() !== 1) {
                $this->rejectOrder($db, 409, 'INVALID_SESSION', 'Phiên bàn đã đóng, không thể gửi đơn');
                return;
            }

            $this->commitWriteTransaction($db);

            // Dọn giỏ hàng chỉ sau khi transaction được xác nhận.
            SharedCartService::clearCart($token);

            echo json_encode([
                'status' => 'success',
                'order_id' => $orderId,
                'order_item_ids' => $insertedItemIds,
                'order_code' => $orderCode,
                'total_amount' => $orderTotal,
                'items_count' => count($orderItemsToInsert),
                'message' => 'Đã gửi đơn xuống Bếp KDS thành công!'
            ], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            $this->rollbackWriteTransaction($db);
            http_response_code(500);
            echo json_encode([
                'error' => 'Không thể gửi đơn lúc này',
                'code' => 'ORDER_SUBMISSION_FAILED',
            ], JSON_UNESCAPED_UNICODE);
            error_log('Order submission failed: ' . $e->getMessage());
        }
    }

    private function beginWriteTransaction(PDO $db): void
    {
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $db->exec('BEGIN IMMEDIATE');
            $this->sqliteWriteTransaction = true;
            return;
        }

        $db->beginTransaction();
    }

    private function commitWriteTransaction(PDO $db): void
    {
        if ($this->sqliteWriteTransaction) {
            $db->exec('COMMIT');
            $this->sqliteWriteTransaction = false;
            return;
        }

        $db->commit();
    }

    private function rollbackWriteTransaction(PDO $db): void
    {
        if ($this->sqliteWriteTransaction) {
            $db->exec('ROLLBACK');
            $this->sqliteWriteTransaction = false;
            return;
        }

        if ($db->inTransaction()) {
            $db->rollBack();
        }
    }

    private function rejectOrder(PDO $db, int $statusCode, string $code, string $message, array $context = []): void
    {
        $this->rollbackWriteTransaction($db);
        http_response_code($statusCode);
        echo json_encode(array_merge([
            'error' => $message,
            'code' => $code,
        ], $context), JSON_UNESCAPED_UNICODE);
    }

    private function parsePositiveInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (!is_string($value) || !preg_match('/^[1-9][0-9]*$/D', $value)) {
            return null;
        }

        $integer = filter_var($value, FILTER_VALIDATE_INT);
        return $integer !== false && $integer > 0 ? $integer : null;
    }

    public function getOrderStatus(string $token): void
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT oi.*, o.order_code, o.created_at AS order_created_at
            FROM order_items oi
            JOIN orders o ON oi.order_id = o.id
            JOIN table_sessions ts ON o.session_id = ts.id
            JOIN dining_tables dt ON dt.id = ts.table_id AND dt.current_session_id = ts.id
            WHERE ts.session_token = ? AND ts.status = 'ACTIVE'
            ORDER BY oi.id DESC
        ");
        $stmt->execute([$token]);
        $items = OrderToppingSnapshotService::attach($db, $stmt->fetchAll());
        echo json_encode($items, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
