<?php

if (!getenv('DINEFLOW_DB_PATH')) {
    $tempDb = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'dineflow_test_' . uniqid() . '.sqlite';
    putenv("DINEFLOW_DB_PATH={$tempDb}");
    putenv("SQLITE_PATH={$tempDb}");
}

require_once __DIR__ . '/../app/Core/Database.php';
require_once __DIR__ . '/../app/Services/SharedCartService.php';
require_once __DIR__ . '/../app/Services/EtlService.php';
require_once __DIR__ . '/../app/Services/AprioriService.php';
require_once __DIR__ . '/../app/Controllers/DataPipelineController.php';

use App\Core\Database;
use App\Services\SharedCartService;
use App\Services\EtlService;
use App\Services\AprioriService;
use App\Controllers\DataPipelineController;

echo "=======================================================================\n";
echo "🧪 TV4 CORE TEST SUITE: DATA PIPELINE, ETL, APRIORI & SHARED CART\n";
echo "=======================================================================\n\n";

$passCount = 0;
$failCount = 0;

function assertTv4(string $testName, bool $condition, string $detail = ""): void
{
    global $passCount, $failCount;
    if ($condition) {
        $passCount++;
        echo "  [PASS] {$testName}\n";
    } else {
        $failCount++;
        echo "  [FAIL] ❌ {$testName}" . ($detail ? " -> {$detail}" : "") . "\n";
    }
}

// 1. Initialize Schema & Test Database
Database::initSchema();
$db = Database::getConnection();

// -------------------------------------------------------------------------
// SECTION 1: SHARED CART SERVICE (RELIABILITY & CONCURRENCY)
// -------------------------------------------------------------------------
echo "📋 PHẦN 1: KIỂM THỬ SHARED CART SERVICE (QUẢN LÝ GIỎ HÀNG CHUNG BÀN)\n";

$token = 'test_token_' . uniqid();
SharedCartService::clearCart($token);
$emptyCart = SharedCartService::getCart($token);
assertTv4("TC-CART-1: Giỏ hàng ban đầu rỗng", count($emptyCart['items']) === 0 && $emptyCart['total_quantity'] === 0);

// Thêm món 1 vào giỏ
$cart1 = SharedCartService::addItem($token, [
    'menu_item_id' => 1,
    'name' => 'Mì Cay Hải Sản',
    'price' => 65000,
    'quantity' => 2,
    'toppings' => ['Thêm Bò'],
    'notes' => 'Ít cay'
]);
assertTv4("TC-CART-2: Thêm món vào giỏ -> Số lượng và tổng tiền chuẩn", count($cart1['items']) === 1 && $cart1['total_quantity'] === 2 && (float)$cart1['total_amount'] === 130000.0);

// Thêm tiếp cùng món đó -> cộng dồn số lượng
$cart2 = SharedCartService::addItem($token, [
    'menu_item_id' => 1,
    'name' => 'Mì Cay Hải Sản',
    'price' => 65000,
    'quantity' => 1,
    'toppings' => ['Thêm Bò']
]);
assertTv4("TC-CART-3: Thêm cùng món & topping -> Tự động cộng dồn số lượng (3 phần)", $cart2['items'][0]['quantity'] === 3 && $cart2['total_quantity'] === 3);

// Giảm số lượng qua updateItem
$cart3 = SharedCartService::updateItem($token, 1, -1, ['Thêm Bò']);
assertTv4("TC-CART-4: Giảm 1 phần -> Còn 2 phần", $cart3['items'][0]['quantity'] === 2);

// Thêm món 2
SharedCartService::addItem($token, [
    'menu_item_id' => 8,
    'name' => 'Trà Đào Cam Sả',
    'price' => 32000,
    'quantity' => 2
]);
$cartMulti = SharedCartService::getCart($token);
assertTv4("TC-CART-5: Giỏ hàng nhiều món -> Tính đúng tổng số lượng (4) và tổng tiền", $cartMulti['total_quantity'] === 4 && (float)$cartMulti['total_amount'] === (2 * 65000 + 2 * 32000));

// Xóa giỏ hàng
SharedCartService::clearCart($token);
$cartCleared = SharedCartService::getCart($token);
assertTv4("TC-CART-6: Xóa giỏ hàng (Checkout) -> Giỏ sạch hoàn toàn", count($cartCleared['items']) === 0);

echo "\n";

// -------------------------------------------------------------------------
// SECTION 2: ETL SERVICE (IDEMPOTENCY & STAR SCHEMA LOADING)
// -------------------------------------------------------------------------
echo "📋 PHẦN 2: KIỂM THỬ ETL SERVICE (TÍNH BẤT BIẾN IDEMPOTENT & KHO OLAP)\n";

// Tạo dữ liệu OLTP giả lập một phiên hoàn chỉnh
$testSessionId = 'sess_test_etl_' . uniqid();
$db->prepare("INSERT INTO dining_tables (table_number, status) VALUES ('Bàn Test ETL', 'OCCUPIED')")->execute();
$tTableId = (int)$db->lastInsertId();

$db->prepare("
    INSERT INTO table_sessions (id, table_id, session_token, status, total_amount, created_at, closed_at, is_etl_processed)
    VALUES (?, ?, ?, 'CHECKOUT', 250000, datetime('now', '-30 minutes'), datetime('now'), 0)
")->execute([$testSessionId, $tTableId, 'tok_' . $testSessionId]);

// Tạo order và order_items
$db->prepare("INSERT INTO orders (session_id, order_code, status, total_amount) VALUES (?, 'ORD-ETL-01', 'CONFIRMED', 250000)")->execute([$testSessionId]);
$tOrderId = (int)$db->lastInsertId();

// Tạo món ăn mẫu nếu chưa có
$db->prepare("INSERT INTO categories (name) VALUES ('Món Test')")->execute();
$tCatId = (int)$db->lastInsertId();
$db->prepare("INSERT INTO menu_items (category_id, name, price) VALUES (?, 'Món A Nhanh', 50000)")->execute([$tCatId]);
$itemAId = (int)$db->lastInsertId();
$db->prepare("INSERT INTO menu_items (category_id, name, price) VALUES (?, 'Món B Lâu (SLA Violated)', 200000)")->execute([$tCatId]);
$itemBId = (int)$db->lastInsertId();

// Item 1: Nấu 500s (< 900s -> SLA Đạt)
$db->prepare("
    INSERT INTO order_items (order_id, menu_item_id, item_name, unit_price, quantity, subtotal, status, created_at, completed_at)
    VALUES (?, ?, 'Món A Nhanh', 50000, 1, 50000, 'COMPLETED', datetime('now', '-20 minutes'), datetime('now', '-11 minutes 40 seconds'))
")->execute([$tOrderId, $itemAId]);

// Item 2: Nấu 1000s (> 900s -> Vi phạm SLA)
$db->prepare("
    INSERT INTO order_items (order_id, menu_item_id, item_name, unit_price, quantity, subtotal, status, created_at, completed_at)
    VALUES (?, ?, 'Món B Lâu (SLA Violated)', 200000, 1, 200000, 'COMPLETED', datetime('now', '-25 minutes'), datetime('now', '-8 minutes 20 seconds'))
")->execute([$tOrderId, $itemBId]);

// Chạy ETL lần 1
$factsBefore = (int)$db->query("SELECT COUNT(*) FROM fact_order_items")->fetchColumn();
$etlResult1 = EtlService::runEtlForClosedSession($testSessionId);
$factsAfter1 = (int)$db->query("SELECT COUNT(*) FROM fact_order_items")->fetchColumn();

assertTv4("TC-ETL-1: Chạy ETL lần 1 thành công", $etlResult1 === true);
assertTv4("TC-ETL-2: Nạp đúng 2 dòng fact_order_items mới", ($factsAfter1 - $factsBefore) === 2);

// Kiểm tra cờ is_etl_processed trên table_sessions
$stmtSessCheck = $db->prepare("SELECT is_etl_processed FROM table_sessions WHERE id = ?");
$stmtSessCheck->execute([$testSessionId]);
$isEtlProc = (int)$stmtSessCheck->fetchColumn();
assertTv4("TC-ETL-3: Cờ is_etl_processed = 1 được bật", $isEtlProc === 1);

// Kiểm tra tính toán prep_time_seconds và is_sla_violated
$stmtFactItems = $db->prepare("
    SELECT f.*, d.item_name
    FROM fact_order_items f
    JOIN dim_menu_item d ON f.item_key = d.item_key
    WHERE f.session_id = ?
");
$stmtFactItems->execute([$testSessionId]);
$insertedFacts = $stmtFactItems->fetchAll();

$slaViolatedFound = false;
$slaPassedFound = false;
foreach ($insertedFacts as $f) {
    if ((int)$f['is_sla_violated'] === 1 && (int)$f['prep_time_seconds'] > 900) {
        $slaViolatedFound = true;
    }
    if ((int)$f['is_sla_violated'] === 0 && (int)$f['prep_time_seconds'] <= 900) {
        $slaPassedFound = true;
    }
}
assertTv4("TC-ETL-4: Món nấu >900s tự động bật cờ is_sla_violated = 1", $slaViolatedFound);
assertTv4("TC-ETL-5: Món nấu <=900s có cờ is_sla_violated = 0", $slaPassedFound);

// BẤT BIẾN QUAN TRỌNG: Chạy ETL lần 2 (Idempotency) -> KHÔNG NHÂN ĐÔI DOANH THU
$etlResult2 = EtlService::runEtlForClosedSession($testSessionId);
$factsAfter2 = (int)$db->query("SELECT COUNT(*) FROM fact_order_items")->fetchColumn();
assertTv4("TC-ETL-6: [BẤT BIẾN] Chạy ETL lần 2 vẫn trả về true", $etlResult2 === true);
assertTv4("TC-ETL-7: [BẤT BIẾN] Số lượng fact không thay đổi (Chống nhân đôi doanh thu)", $factsAfter2 === $factsAfter1);

echo "\n";

// -------------------------------------------------------------------------
// SECTION 3: APRIORI SERVICE (SUPPORT, CONFIDENCE, LIFT & COMBO RULES)
// -------------------------------------------------------------------------
echo "📋 PHẦN 3: KIỂM THỬ THUẬT TOÁN APRIORI & QUY LUẬT COMBO OVERRIDE\n";

// Tạo rule combo ưu tiên: Món A -> Món B với confidence = 0.9, discount = 15%
AprioriService::createComboRule($itemAId, $itemBId, 0.9, 15, 0);

$recsA = AprioriService::getRecommendations($itemAId, 3);
assertTv4("TC-APR-1: Gợi ý có kết quả", !empty($recsA));
$firstRec = $recsA[0];
assertTv4("TC-APR-2: Ưu tiên rule combo override (Source = RULE_OVERRIDE)", $firstRec['source'] === 'RULE_OVERRIDE');
assertTv4("TC-APR-3: Gợi ý đúng món target B", (int)$firstRec['target_item_id'] === $itemBId);
assertTv4("TC-APR-4: Confidence đạt 0.9 (90%)", (float)$firstRec['confidence'] === 0.9);

// Khai phá luật kết hợp toàn nhà hàng
$assocRules = AprioriService::getAssociationRules(5);
assertTv4("TC-APR-5: Khai phá Association Rules trả về mảng kết quả", is_array($assocRules));

echo "\n";

// -------------------------------------------------------------------------
// SECTION 4: DATA PIPELINE CONTROLLER ENDPOINTS
// -------------------------------------------------------------------------
echo "📋 PHẦN 4: KIỂM THỬ CÁC ENDPOINT DATA PIPELINE CONTROLLER\n";

$controller = new DataPipelineController();

// 1. Clickstream
ob_start();
// Simulate php://input via mock or call method
// Test pipeline status
$controller->getPipelineStatus();
$statusOutput = ob_get_clean();
$statusJson = json_decode($statusOutput, true);
assertTv4("TC-API-1: GET /api/v1/data/pipeline-status trả về JSON hợp lệ", is_array($statusJson) && isset($statusJson['oltp_stats'], $statusJson['olap_star_schema_stats']));
assertTv4("TC-API-2: Trạng thái pipeline HEALTHY", ($statusJson['status'] ?? '') === 'HEALTHY');

// 2. SLA Metrics
ob_start();
$controller->getSlaMetrics();
$slaOutput = ob_get_clean();
$slaJson = json_decode($slaOutput, true);
assertTv4("TC-API-3: GET /api/v1/data/sla-metrics chứa ngưỡng 900s & thống kê", isset($slaJson['sla_threshold_seconds']) && $slaJson['sla_threshold_seconds'] === 900);
assertTv4("TC-API-4: Có danh sách dish_bottlenecks & chef_performance", isset($slaJson['dish_bottlenecks'], $slaJson['chef_performance']));

// 3. Live Ops
ob_start();
$controller->getLiveOps();
$liveOutput = ob_get_clean();
$liveJson = json_decode($liveOutput, true);
assertTv4("TC-API-5: GET /api/v1/data/live-ops trả về danh sách tables & recent_closed_bills", isset($liveJson['tables'], $liveJson['recent_closed_bills']));

echo "\n=======================================================================\n";
echo "📊 TỔNG KẾT KIỂM THỬ TV4: {$passCount} PASSED / " . ($passCount + $failCount) . " TOTAL\n";
if ($failCount === 0) {
    echo "🎉 CHÚC MỪNG: 100% CÁC TEST CASES CỦA TV4 CORE ĐỀU ĐẠT CHUẨN!\n";
} else {
    echo "⚠️ CẢNH BÁO: CÓ {$failCount} TEST CASES THẤT BẠI.\n";
}
echo "=======================================================================\n";
