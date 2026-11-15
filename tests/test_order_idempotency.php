<?php

use App\Controllers\ClientOrderController;
use App\Core\Database;

$databasePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'dineflow_order_idempotency_' . uniqid('', true) . '.sqlite';
putenv('DINEFLOW_DB_PATH=' . $databasePath);

require_once __DIR__ . '/../app/Core/Database.php';
require_once __DIR__ . '/../app/Services/SharedCartService.php';
require_once __DIR__ . '/../app/Controllers/ClientOrderController.php';

function assertOrderIdempotencyTest(string $name, bool $condition): void
{
    static $failures = 0;
    if ($condition) {
        echo "[PASS] {$name}\n";
        return;
    }

    $failures++;
    echo "[FAIL] {$name}\n";
    $GLOBALS['order_idempotency_test_failures'] = $failures;
}

function callIdempotentSubmit(ClientOrderController $controller, string $token, ?string $key, array $payload): array
{
    if ($key === null) {
        unset($_SERVER['HTTP_IDEMPOTENCY_KEY']);
    } else {
        $_SERVER['HTTP_IDEMPOTENCY_KEY'] = $key;
    }

    http_response_code(200);
    ob_start();
    $controller->submitOrder($token, $payload);
    $body = ob_get_clean();

    return [http_response_code(), json_decode($body, true)];
}

try {
    Database::initSchema();
    $db = Database::getConnection();
    $suffix = strtoupper(bin2hex(random_bytes(5)));
    $tableId = random_int(10000, 50000);
    $categoryId = random_int(10000, 50000);
    $itemId = random_int(10000, 50000);
    $sessionId = 'idem-session-' . strtolower($suffix);
    $token = 'idem-token-' . strtolower($suffix);

    $db->prepare("INSERT INTO dining_tables (id, table_number, status, current_session_id) VALUES (?, ?, 'OCCUPIED', ?)")
        ->execute([$tableId, 'IDEM-' . $suffix, $sessionId]);
    $db->prepare('INSERT INTO categories (id, name, display_order) VALUES (?, ?, 1)')
        ->execute([$categoryId, 'Idempotency test ' . $suffix]);
    $db->prepare('INSERT INTO menu_items (id, category_id, name, price, is_available, is_hot) VALUES (?, ?, ?, 1000, 1, 0)')
        ->execute([$itemId, $categoryId, 'Idempotency item ' . $suffix]);
    $db->prepare("INSERT INTO table_sessions (id, table_id, session_token, status, total_amount, is_etl_processed) VALUES (?, ?, ?, 'ACTIVE', 0, 1)")
        ->execute([$sessionId, $tableId, $token]);

    $controller = new ClientOrderController();
    $payload = ['items' => [['menu_item_id' => $itemId, 'quantity' => 1]]];
    $firstKey = 'test-' . strtolower($suffix) . '-first';

    [$firstStatus, $firstResponse] = callIdempotentSubmit($controller, $token, $firstKey, $payload);
    [$repeatStatus, $repeatResponse] = callIdempotentSubmit($controller, $token, $firstKey, $payload);

    $countStmt = $db->prepare('SELECT COUNT(*) FROM orders WHERE session_id = ? AND idempotency_key = ?');
    $countStmt->execute([$sessionId, $firstKey]);
    $orderCount = (int)$countStmt->fetchColumn();
    $sessionTotalStmt = $db->prepare('SELECT total_amount FROM table_sessions WHERE id = ?');
    $sessionTotalStmt->execute([$sessionId]);
    $sessionTotalAfterRepeat = (float)$sessionTotalStmt->fetchColumn();

    assertOrderIdempotencyTest(
        'Repeating a key returns the original order as success',
        $firstStatus === 200
            && $repeatStatus === 200
            && ($firstResponse['order_id'] ?? null) === ($repeatResponse['order_id'] ?? null)
            && ($repeatResponse['duplicate'] ?? false) === true
    );
    assertOrderIdempotencyTest(
        'Repeating a key creates only one order and adds the total once',
        $orderCount === 1 && $sessionTotalAfterRepeat === 1000.0
    );

    [$missingKeyStatus, $missingKeyResponse] = callIdempotentSubmit($controller, $token, null, $payload);
    [$longKeyStatus, $longKeyResponse] = callIdempotentSubmit($controller, $token, str_repeat('x', 65), $payload);
    assertOrderIdempotencyTest(
        'Rejects a missing or overlong idempotency key',
        $missingKeyStatus === 422
            && ($missingKeyResponse['code'] ?? null) === 'INVALID_IDEMPOTENCY_KEY'
            && $longKeyStatus === 422
            && ($longKeyResponse['code'] ?? null) === 'INVALID_IDEMPOTENCY_KEY'
    );

    $secondKey = 'test-' . strtolower($suffix) . '-second';
    [$secondStatus, $secondResponse] = callIdempotentSubmit($controller, $token, $secondKey, $payload);
    $allOrdersStmt = $db->prepare('SELECT COUNT(*) FROM orders WHERE session_id = ?');
    $allOrdersStmt->execute([$sessionId]);
    $allOrders = (int)$allOrdersStmt->fetchColumn();
    $sessionTotalStmt->execute([$sessionId]);
    $finalSessionTotal = (float)$sessionTotalStmt->fetchColumn();
    assertOrderIdempotencyTest(
        'A different key creates a separate order in the same session',
        $secondStatus === 200
            && ($secondResponse['order_id'] ?? null) !== ($firstResponse['order_id'] ?? null)
            && $allOrders === 2
            && $finalSessionTotal === 2000.0
    );

    $failures = (int)($GLOBALS['order_idempotency_test_failures'] ?? 0);
    echo $failures === 0 ? "All order idempotency tests passed.\n" : "{$failures} order idempotency test(s) failed.\n";
    $exitCode = $failures === 0 ? 0 : 1;
} finally {
    foreach ([$databasePath, $databasePath . '-wal', $databasePath . '-shm'] as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
}

exit($exitCode ?? 1);
