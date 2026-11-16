<?php

namespace App\Controllers;

use App\Core\Database;
use App\Services\OrderToppingSnapshotService;
use App\Services\SharedCartService;
use InvalidArgumentException;
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
        $idempotencyKey = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? null;
        if ($idempotencyKey !== null && ($idempotencyKey === '' || strlen($idempotencyKey) > 64)) {
            http_response_code(422);
            echo json_encode(['error' => 'Idempotency-Key không hợp lệ']);
            return;
        }

        $payload = $customPayload ?? json_decode(file_get_contents('php://input') ?: '[]', true);
        $items = is_array($payload) && array_key_exists('items', $payload) ? $payload['items'] : $payload;

        if (empty($items) || !is_array($items)) {
            http_response_code(422);
            echo json_encode(['error' => 'Danh sách món đặt không được rỗng']);
            return;
        }

        $lock = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $db->beginTransaction();
        try {
            $stmt = $db->prepare("SELECT * FROM table_sessions WHERE session_token = ? AND status = 'ACTIVE'{$lock}");
            $stmt->execute([$token]);
            $session = $stmt->fetch();
            if (!$session) {
                $db->rollBack();
                http_response_code(400);
                echo json_encode(['error' => 'Phiên bàn không tồn tại hoặc đã đóng']);
                return;
            }

            if ($idempotencyKey !== null) {
                $stmtCheck = $db->prepare('SELECT id FROM orders WHERE idempotency_key = ?');
                $stmtCheck->execute([$idempotencyKey]);
                $existing = $stmtCheck->fetch();
                if ($existing) {
                    $db->rollBack();
                    http_response_code(409);
                    echo json_encode([
                        'error' => 'Đơn hàng trùng lặp đã được tiếp nhận',
                        'order_id' => (int)$existing['id'],
                    ], JSON_UNESCAPED_UNICODE);
                    return;
                }
            }

            $orderCode = 'ORD-' . strtoupper(substr(md5(uniqid()), 0, 8));
            $orderTotal = 0.0;
            $orderItemsToInsert = [];
            $stmtItem = $db->prepare("SELECT id, name, category_id, price, is_available FROM menu_items WHERE id = ?{$lock}");

            foreach ($items as $item) {
                if (!is_array($item)) {
                    throw new InvalidArgumentException('Thông tin món đặt không hợp lệ');
                }
                $itemId = (int)($item['menu_item_id'] ?? $item['id'] ?? 0);
                $rawQuantity = filter_var($item['quantity'] ?? 1, FILTER_VALIDATE_INT);
                $qty = is_int($rawQuantity) ? $rawQuantity : 0;
                if ($itemId < 1 || $qty < 1) {
                    throw new InvalidArgumentException('Món ăn hoặc số lượng không hợp lệ');
                }

                $stmtItem->execute([$itemId]);
                $realItem = $stmtItem->fetch();
                if (!$realItem) {
                    throw new InvalidArgumentException("Món ăn ID #{$itemId} không tồn tại trên thực đơn");
                }
                if (!(bool)$realItem['is_available']) {
                    throw new InvalidArgumentException("Món {$realItem['name']} hiện đã hết");
                }

                $unitPrice = (float)$realItem['price'];
                $toppingExtra = 0.0;
                $toppingSnapshots = [];
                $rawToppings = $item['selected_toppings'] ?? $item['toppings'] ?? [];
                if (!is_array($rawToppings)) {
                    throw new InvalidArgumentException('Danh sách topping không hợp lệ');
                }
                $seenToppingIds = [];
                $stmtTop = $db->prepare("
                    SELECT t.id, t.name, t.price
                    FROM toppings t
                    JOIN menu_item_toppings mit ON mit.topping_id = t.id
                    WHERE t.id = ? AND t.is_available = 1 AND mit.menu_item_id = ?
                ");
                foreach ($rawToppings as $top) {
                    $topId = is_array($top) ? (int)($top['id'] ?? 0) : (int)$top;
                    if ($topId < 1 || isset($seenToppingIds[$topId])) {
                        throw new InvalidArgumentException('Topping không hợp lệ hoặc bị chọn trùng');
                    }
                    $seenToppingIds[$topId] = true;
                    $stmtTop->execute([$topId, $itemId]);
                    $topRow = $stmtTop->fetch();
                    if (!$topRow) {
                        throw new InvalidArgumentException('Topping không tồn tại, đã hết hoặc chưa được gán cho món');
                    }
                    $toppingPrice = (float)$topRow['price'];
                    $toppingExtra += $toppingPrice;
                    $toppingSnapshots[] = [
                        'id' => (int)$topRow['id'],
                        'name' => $topRow['name'],
                        'unit_price' => $toppingPrice,
                    ];
                }

                $unitPrice += $toppingExtra;
                $subtotal = $unitPrice * $qty;
                $orderTotal += $subtotal;

                $orderItemsToInsert[] = [
                    'menu_item_id' => $realItem['id'],
                    'item_name' => $realItem['name'],
                    'unit_price' => $unitPrice,
                    'quantity' => $qty,
                    'subtotal' => $subtotal,
                    'notes' => $item['notes'] ?? ($item['note'] ?? ''),
                    'toppings' => $toppingSnapshots,
                ];
            }

            $stmtOrder = $db->prepare("
                INSERT INTO orders (session_id, order_code, status, total_amount, idempotency_key)
                VALUES (?, ?, 'CONFIRMED', ?, ?)
            ");
            $stmtOrder->execute([$session['id'], $orderCode, $orderTotal, $idempotencyKey]);
            $orderId = (int)$db->lastInsertId();

            $stmtOrderItem = $db->prepare("
                INSERT INTO order_items (order_id, menu_item_id, item_name, unit_price, quantity, subtotal, status, notes)
                VALUES (?, ?, ?, ?, ?, ?, 'PENDING', ?)
            ");

            $insertedItemIds = [];
            foreach ($orderItemsToInsert as $orderItem) {
                $stmtOrderItem->execute([
                    $orderId,
                    $orderItem['menu_item_id'],
                    $orderItem['item_name'],
                    $orderItem['unit_price'],
                    $orderItem['quantity'],
                    $orderItem['subtotal'],
                    $orderItem['notes'],
                ]);
                $orderItemId = (int)$db->lastInsertId();
                $insertedItemIds[] = $orderItemId;
                OrderToppingSnapshotService::save($db, $orderItemId, $orderItem['toppings']);
            }

            $stmtUpdateSession = $db->prepare('UPDATE table_sessions SET total_amount = total_amount + ? WHERE id = ?');
            $stmtUpdateSession->execute([$orderTotal, $session['id']]);
            $db->commit();

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
        } catch (InvalidArgumentException $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            http_response_code(422);
            echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('Order submission failed: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Không thể gửi đơn lúc này'], JSON_UNESCAPED_UNICODE);
        }
    }

    public function getOrderStatus(string $token): void
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT oi.*, o.order_code, o.created_at AS order_created_at
            FROM order_items oi
            JOIN orders o ON oi.order_id = o.id
            JOIN table_sessions ts ON o.session_id = ts.id
            WHERE ts.session_token = ?
            ORDER BY oi.id DESC
        ");
        $stmt->execute([$token]);
        $items = OrderToppingSnapshotService::attach($db, $stmt->fetchAll());
        echo json_encode($items, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
