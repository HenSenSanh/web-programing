<?php

use App\Controllers\ClientCancellationController;
use App\Controllers\KdsPosController;
use App\Core\Database;

$databasePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'dineflow_cancel_' . uniqid('', true) . '.sqlite';
putenv('DINEFLOW_DB_PATH=' . $databasePath);

require_once __DIR__ . '/../app/Core/Database.php';
require_once __DIR__ . '/../app/Services/SharedCartService.php';
require_once __DIR__ . '/../app/Services/EtlService.php';
require_once __DIR__ . '/../app/Controllers/ClientCancellationController.php';
require_once __DIR__ . '/../app/Controllers/KdsPosController.php';

function assertCancellationTest(string $name, bool $condition): void
{
    static $failures = 0;
    if ($condition) {
        echo "[PASS] {$name}\n";
        return;
    }

    $failures++;
    echo "[FAIL] {$name}\n";
    $GLOBALS['cancellation_test_failures'] = $failures;
}

function callController(callable $action): array
{
    http_response_code(200);
    ob_start();
    $action();
    $body = ob_get_clean();

    return [http_response_code(), json_decode($body, true)];
}

try {
    Database::initSchema();
    $db = Database::getConnection();
    $token1 = 'cancel-' . bin2hex(random_bytes(12)) . '-1';
    $token2 = 'cancel-' . bin2hex(random_bytes(12)) . '-2';

    $db->exec("INSERT INTO dining_tables (id, table_number, status, current_session_id) VALUES
        (901, 'TEST-901', 'OCCUPIED', 'cancel-session-1'),
        (902, 'TEST-902', 'OCCUPIED', 'cancel-session-2')");
    $db->exec("INSERT INTO categories (id, name, display_order) VALUES (901, 'Test', 1)");
    $db->exec("INSERT INTO menu_items (id, category_id, name, price, is_available, is_hot)
        VALUES (901, 901, 'Test item', 10000, 1, 0)");
    $db->exec("INSERT INTO table_sessions (id, table_id, session_token, status, total_amount, is_etl_processed) VALUES
        ('cancel-session-1', 901, '{$token1}', 'ACTIVE', 100000, 1),
        ('cancel-session-2', 902, '{$token2}', 'ACTIVE', 50000, 1)");
    $db->exec("INSERT INTO orders (id, session_id, order_code, status, total_amount) VALUES
        (901, 'cancel-session-1', 'CANCEL-ORDER-1', 'CONFIRMED', 100000),
        (902, 'cancel-session-2', 'CANCEL-ORDER-2', 'CONFIRMED', 50000)");
    $db->exec("INSERT INTO order_items (id, order_id, menu_item_id, item_name, unit_price, quantity, subtotal, status) VALUES
        (901, 901, 901, 'Pending item', 30000, 1, 30000, 'PENDING'),
        (902, 901, 901, 'Cooking item', 40000, 1, 40000, 'COOKING'),
        (903, 902, 901, 'Other session item', 50000, 1, 50000, 'PENDING'),
        (904, 901, 901, 'Race cancel item', 10000, 1, 10000, 'PENDING'),
        (905, 901, 901, 'Race cook item', 20000, 1, 20000, 'PENDING')");

    $cancellation = new ClientCancellationController();
    $kds = new KdsPosController();

    [$status, $response] = callController(
        static fn() => $cancellation->cancelItem('invalid-' . $token1, 901)
    );
    assertCancellationTest('Rejects an invalid session token', $status === 404);

    [$status, $response] = callController(
        static fn() => $cancellation->cancelItem($token1, 903)
    );
    assertCancellationTest('Rejects an item from another session', $status === 404);

    $db->exec("UPDATE table_sessions SET status = 'CHECKOUT' WHERE id = 'cancel-session-2'");
    [$status] = callController(
        static fn() => $cancellation->cancelItem($token2, 903)
    );
    assertCancellationTest('Rejects a token for a closed session', $status === 404);

    [$status, $response] = callController(
        static fn() => $cancellation->cancelItem($token1, 901)
    );
    $stmtCancelled = $db->query("SELECT COUNT(*) FROM order_items WHERE id = 901 AND status = 'CANCELLED'");
    $cancelledRows = (int)$stmtCancelled->fetchColumn();
    $stmtTotals = $db->query("SELECT o.total_amount AS order_total, ts.total_amount AS session_total
        FROM orders o JOIN table_sessions ts ON ts.id = o.session_id WHERE o.id = 901");
    $totals = $stmtTotals->fetch();
    assertCancellationTest(
        'Cancels a pending item and updates order/session totals',
        $status === 200
            && ($response['new_status'] ?? null) === 'CANCELLED'
            && $cancelledRows === 1
            && (float)$totals['order_total'] === 70000.0
            && (float)$totals['session_total'] === 70000.0
    );

    [$status, $response] = callController(
        static fn() => $cancellation->cancelItem($token1, 902)
    );
    assertCancellationTest(
        'Refuses to cancel a cooking item with HTTP 409 and its current status',
        $status === 409 && ($response['current_status'] ?? null) === 'COOKING'
    );

    [$status, $response] = callController(
        static fn() => $cancellation->cancelItem($token1, 904)
    );
    [$kdsStatus, $kdsResponse] = callController(
        static fn() => $kds->updateItemStatus(['item_id' => 904, 'status' => 'COOKING'])
    );
    assertCancellationTest(
        'Cancellation wins the pending-to-cooking race when it commits first',
        $status === 200 && $kdsStatus === 409 && ($kdsResponse['current_status'] ?? null) === 'CANCELLED'
    );

    [$kdsStatus, $kdsResponse] = callController(
        static fn() => $kds->updateItemStatus(['item_id' => 905, 'status' => 'COOKING'])
    );
    [$cancelStatus, $cancelResponse] = callController(
        static fn() => $cancellation->cancelItem($token1, 905)
    );
    assertCancellationTest(
        'Starting to cook wins the pending-to-cooking race when KDS updates first',
        $kdsStatus === 200
            && $cancelStatus === 409
            && ($cancelResponse['current_status'] ?? null) === 'COOKING'
    );

    [$status, $response] = callController(
        static fn() => $kds->updateItemStatus(['item_id' => 902, 'status' => 'COMPLETED'])
    );
    assertCancellationTest('Allows COOKING to COMPLETED', $status === 200);

    [$status] = callController(
        static fn() => $kds->updateItemStatus(['item_id' => 902, 'status' => 'COOKING'])
    );
    assertCancellationTest('Rejects an invalid reverse KDS transition', $status === 409);

    [$status] = callController(
        static fn() => $kds->updateItemStatus(['item_id' => 902, 'status' => 'CANCELLED'])
    );
    assertCancellationTest('KDS cannot set CANCELLED', $status === 400);

    [$status, $bill] = callController(static fn() => $kds->getTableBill(901));
    assertCancellationTest(
        'Bill detail and subtotal omit all cancelled items',
        $status === 200
            && (float)($bill['subtotal'] ?? -1) === 60000.0
            && count($bill['items'] ?? []) === 2
            && !in_array('CANCELLED', array_column($bill['items'], 'status'), true)
    );

    [$status, $checkout] = callController(
        static fn() => $kds->checkout($token1)
    );
    $stmtPayment = $db->prepare('SELECT subtotal_amount FROM bill_payments WHERE invoice_code = ?');
    $stmtPayment->execute([$checkout['invoice_code'] ?? '']);
    $paidSubtotal = $stmtPayment->fetchColumn();
    assertCancellationTest(
        'Checkout payment subtotal excludes cancelled items',
        $status === 200
            && (float)($checkout['subtotal'] ?? -1) === 60000.0
            && (float)$paidSubtotal === 60000.0
    );

    $failures = (int)($GLOBALS['cancellation_test_failures'] ?? 0);
    echo $failures === 0 ? "All cancellation tests passed.\n" : "{$failures} cancellation test(s) failed.\n";
    $exitCode = $failures === 0 ? 0 : 1;
} finally {
    foreach ([$databasePath, $databasePath . '-wal', $databasePath . '-shm'] as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
}

exit($exitCode ?? 1);
