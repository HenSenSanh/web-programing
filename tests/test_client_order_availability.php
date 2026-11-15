<?php

use App\Controllers\ClientOrderController;
use App\Core\Database;
use App\Services\SharedCartService;

$databasePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'dineflow_order_availability_' . uniqid('', true) . '.sqlite';
putenv('DINEFLOW_DB_PATH=' . $databasePath);

require_once __DIR__ . '/../app/Core/Database.php';
require_once __DIR__ . '/../app/Services/SharedCartService.php';
require_once __DIR__ . '/../app/Controllers/ClientOrderController.php';

function assertOrderAvailabilityTest(string $name, bool $condition): void
{
    static $failures = 0;
    if ($condition) {
        echo "[PASS] {$name}\n";
        return;
    }

    $failures++;
    echo "[FAIL] {$name}\n";
    $GLOBALS['order_availability_test_failures'] = $failures;
}

function callSubmitOrder(ClientOrderController $controller, string $token, array $payload): array
{
    unset($_SERVER['HTTP_IDEMPOTENCY_KEY']);
    http_response_code(200);
    ob_start();
    $controller->submitOrder($token, $payload);
    $body = ob_get_clean();

    return [http_response_code(), json_decode($body, true)];
}

try {
    Database::initSchema();
    $db = Database::getConnection();
    $token = 'availability-' . bin2hex(random_bytes(12));
    $invalidToken = 'invalid-' . bin2hex(random_bytes(12));

    $db->exec("INSERT INTO dining_tables (id, table_number, status, current_session_id)
        VALUES (990, 'TEST-990', 'OCCUPIED', 'availability-session')");
    $db->exec("INSERT INTO categories (id, name, display_order) VALUES (990, 'Availability tests', 1)");
    $db->exec("INSERT INTO menu_items (id, category_id, name, price, is_available, is_hot) VALUES
        (990, 990, 'Available item', 1000, 1, 0),
        (991, 990, 'Unavailable item', 2000, 0, 0)");
    $db->exec("INSERT INTO toppings (id, name, price, is_available) VALUES
        (990, 'Available topping', 200, 1),
        (991, 'Unavailable topping', 300, 0)");
    $insertSession = $db->prepare("
        INSERT INTO table_sessions (id, table_id, session_token, status, total_amount, is_etl_processed)
        VALUES ('availability-session', 990, ?, 'ACTIVE', 5000, 1)
    ");
    $insertSession->execute([$token]);
    $db->exec("INSERT INTO orders (session_id, order_code, status, total_amount)
        VALUES ('availability-session', 'AVAIL-BASE', 'CONFIRMED', 5000)");

    $controller = new ClientOrderController();

    [$status] = callSubmitOrder($controller, $invalidToken, [
        'items' => [['menu_item_id' => 990, 'quantity' => 1]],
    ]);
    assertOrderAvailabilityTest('Rejects an invalid or inactive session token', $status === 400);

    $beforeOrderCount = (int)$db->query("SELECT COUNT(*) FROM orders")->fetchColumn();
    [$status, $response] = callSubmitOrder($controller, $token, [
        'items' => [
            ['menu_item_id' => 990, 'quantity' => 1],
            ['menu_item_id' => 991, 'quantity' => 1],
        ],
    ]);
    assertOrderAvailabilityTest(
        'Rejects an unavailable menu item without a partial order',
        $status === 409
            && ($response['code'] ?? null) === 'MENU_ITEM_UNAVAILABLE'
            && (int)$db->query("SELECT COUNT(*) FROM orders")->fetchColumn() === $beforeOrderCount
    );

    [$status, $response] = callSubmitOrder($controller, $token, [
        'items' => [['menu_item_id' => 999, 'quantity' => 1]],
    ]);
    assertOrderAvailabilityTest(
        'Rejects a nonexistent menu item',
        $status === 404 && ($response['code'] ?? null) === 'MENU_ITEM_NOT_FOUND'
    );

    [$status, $response] = callSubmitOrder($controller, $token, [
        'items' => [[
            'menu_item_id' => 990,
            'quantity' => 1,
            'selected_toppings' => [['id' => 991]],
        ]],
    ]);
    assertOrderAvailabilityTest(
        'Rejects an unavailable topping',
        $status === 409 && ($response['code'] ?? null) === 'TOPPING_UNAVAILABLE'
    );

    [$status, $response] = callSubmitOrder($controller, $token, [
        'items' => [[
            'menu_item_id' => 990,
            'quantity' => 1,
            'selected_toppings' => [['id' => 999]],
        ]],
    ]);
    assertOrderAvailabilityTest(
        'Rejects a nonexistent topping',
        $status === 404 && ($response['code'] ?? null) === 'TOPPING_NOT_FOUND'
    );

    foreach ([0, -1, 1.5, true, 'not-a-number', ['1']] as $quantity) {
        [$status, $response] = callSubmitOrder($controller, $token, [
            'items' => [['menu_item_id' => 990, 'quantity' => $quantity]],
        ]);
        assertOrderAvailabilityTest(
            "Rejects invalid quantity " . json_encode($quantity),
            $status === 422 && ($response['code'] ?? null) === 'INVALID_QUANTITY'
        );
    }

    [$status, $response] = callSubmitOrder($controller, $token, [
        'items' => [['menu_item_id' => ['990'], 'quantity' => 1]],
    ]);
    assertOrderAvailabilityTest(
        'Rejects a malformed menu item ID',
        $status === 422 && ($response['code'] ?? null) === 'INVALID_ITEM_ID'
    );

    SharedCartService::setCart($token, [
        ['menu_item_id' => 990, 'quantity' => 1],
    ]);
    [$status] = callSubmitOrder($controller, $token, [
        'items' => [['menu_item_id' => 991, 'quantity' => 1]],
    ]);
    $cartAfterRejectedOrder = SharedCartService::getCart($token);
    assertOrderAvailabilityTest(
        'Rejected order leaves the shared cart intact',
        $status === 409 && count($cartAfterRejectedOrder['items'] ?? []) === 1
    );
    SharedCartService::clearCart($token);

    [$status, $response] = callSubmitOrder($controller, $token, [
        'items' => [[
            'menu_item_id' => 990,
            'unit_price' => 1,
            'quantity' => 2,
            'selected_toppings' => [['id' => 990]],
            'note' => 'less spicy',
        ]],
    ]);
    $savedItem = $db->query("
        SELECT unit_price, quantity, subtotal, notes
        FROM order_items
        WHERE order_id = " . (int)($response['order_id'] ?? 0)
    )->fetch();
    $sessionTotal = (float)$db->query("
        SELECT total_amount FROM table_sessions WHERE id = 'availability-session'
    ")->fetchColumn();
    assertOrderAvailabilityTest(
        'Accepts available selections and computes price from database values',
        $status === 200
            && (float)$savedItem['unit_price'] === 1200.0
            && (int)$savedItem['quantity'] === 2
            && (float)$savedItem['subtotal'] === 2400.0
            && $savedItem['notes'] === 'less spicy'
            && (float)($response['total_amount'] ?? -1) === 2400.0
            && $sessionTotal === 7400.0
    );

    $failures = (int)($GLOBALS['order_availability_test_failures'] ?? 0);
    echo $failures === 0 ? "All order availability tests passed.\n" : "{$failures} order availability test(s) failed.\n";
    $exitCode = $failures === 0 ? 0 : 1;
} finally {
    foreach ([$databasePath, $databasePath . '-wal', $databasePath . '-shm'] as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
}

exit($exitCode ?? 1);
