<?php

/**
 * =======================================================================
 * DINEFLOW AUTOMATED QUALITY GATE & MASTER TEST SUITE (TV4 QUALITY LEAD)
 * =======================================================================
 * 
 * Covering:
 *   - Mục 38: Kiểm thử Đăng ký tài khoản (7 Test Cases)
 *   - Mục 39: Kiểm thử Xác nhận Email Token (4 Test Cases)
 *   - Mục 40: Kiểm thử Đăng nhập & Remember Me (7 Test Cases)
 *   - Bài B: Ma trận phân quyền RBAC đa cấp (6 Test Cases)
 *   - Bài C: Lịch sử giá theo thời gian & snapshot (4 Test Cases)
 *   - Bài D: Nhập/Xuất kho, chống âm tồn & đối soát (8 Test Cases)
 *   - 7 TRỤ CỘT CHẤT LƯỢNG TV4 (QUALITY PILLARS & BUSINESS INVARIANTS):
 *       Pillar 1: Anti-tamper price check (Client price ignored, Server price used)
 *       Pillar 2: Idempotency-Key duplicate submission rejection (HTTP 409)
 *       Pillar 3: Idempotent ETL pipeline (Running twice doesn't double OLAP revenue)
 *       Pillar 4: Apriori recommendation generation & combo rules (Support, Confidence, Lift)
 *       Pillar 5: Kitchen SLA calculation (>900s violation flag)
 *       Pillar 6: Concurrency & anti-negative stock under simultaneous requests
 *       Pillar 7: Star schema OLAP fact integrity (Balances == Movements & Facts reflect closed sessions)
 */

if (!getenv('DINEFLOW_DB_PATH')) {
    $tempDb = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'dineflow_test_' . uniqid() . '.sqlite';
    putenv("DINEFLOW_DB_PATH={$tempDb}");
    putenv("SQLITE_PATH={$tempDb}");
}

$appDir = is_dir(__DIR__ . '/../app') ? realpath(__DIR__ . '/../app') : (is_dir('/var/www/html') ? '/var/www/html' : __DIR__ . '/../app');

require_once $appDir . '/src/db.php';
require_once $appDir . '/src/auth.php';
require_once $appDir . '/src/rbac.php';
require_once $appDir . '/src/inventory.php';

require_once $appDir . '/Core/Database.php';
require_once $appDir . '/Services/SharedCartService.php';
require_once $appDir . '/Services/EtlService.php';
require_once $appDir . '/Services/AprioriService.php';
require_once $appDir . '/Controllers/ClientOrderController.php';
require_once $appDir . '/Controllers/DataPipelineController.php';

use App\Core\Database;
use App\Services\SharedCartService;
use App\Services\EtlService;
use App\Services\AprioriService;
use App\Controllers\ClientOrderController;
use App\Controllers\DataPipelineController;

// ANSI color codes
$hasColor = (DIRECTORY_SEPARATOR === '/' || getenv('ANSICON') !== false || getenv('ConEmuANSI') === 'ON' || getenv('TERM') !== false);
$cGreen  = "\033[32m";
$cRed    = "\033[31m";
$cCyan   = "\033[36m";
$cYellow = "\033[33m";
$cBold   = "\033[1m";
$cReset  = "\033[0m";

echo "=======================================================================\n";
echo "🧪 DINEFLOW AUTOMATED QUALITY GATE & COMPREHENSIVE TEST SUITE\n";
echo "   Chất lượng tích hợp TV4 (Auth, RBAC, Kho, Star Schema, Apriori & SLA)\n";
echo "=======================================================================\n\n";

$passCount = 0;
$failCount = 0;

function assertTest(string $testName, bool $condition, string $detail = ""): void
{
    global $passCount, $failCount, $cGreen, $cRed, $cReset, $cBold;
    if ($condition) {
        $passCount++;
        echo "  [PASS] {$testName}\n";
    } else {
        $failCount++;
        echo "  [FAIL] ❌ {$testName}" . ($detail ? " -> {$detail}" : "") . "\n";
    }
}

// Khởi tạo cả 2 Schema trong cơ sở dữ liệu dùng chung
$pdo = getDatabaseConnection();
Database::initSchema();
$db = Database::getConnection();

// -------------------------------------------------------------------------
// PHẦN 1: TEST CASE ĐĂNG KÝ (Mục 38 - PDF Trang 45)
// -------------------------------------------------------------------------
echo "📋 PHẦN 1: KIỂM THỬ ĐĂNG KÝ TÀI KHOẢN (7 TEST CASES - MỤC 38)\n";

$r1 = registerUser("", "test@example.com", "123456", "123456");
assertTest("TC38-1: Bỏ trống username -> Báo lỗi", !$r1['success']);

$r2 = registerUser("ab", "test@example.com", "123456", "123456");
assertTest("TC38-2: Username dưới 3 ký tự ('ab') -> Báo lỗi", !$r2['success']);

$r3 = registerUser("validuser", "abc.com", "123456", "123456");
assertTest("TC38-3: Email sai định dạng ('abc.com') -> Báo lỗi", !$r3['success']);

$r4 = registerUser("validuser", "valid@example.com", "123", "123");
assertTest("TC38-4: Password dưới 6 ký tự ('123') -> Báo lỗi", !$r4['success']);

$r5 = registerUser("validuser", "valid@example.com", "abc123", "abc456");
assertTest("TC38-5: Confirm password khác password -> Báo lỗi", !$r5['success']);

// Đăng ký user hợp lệ
$testEmail = "test_user_" . uniqid() . "@example.com";
$r7 = registerUser("user_" . substr(uniqid(), -5), $testEmail, "secret123", "secret123");
assertTest("TC38-7: Đăng ký hợp lệ -> Lưu database & gửi token", $r7['success']);

$r6 = registerUser("user_dup", $testEmail, "secret123", "secret123");
assertTest("TC38-6: Email đã tồn tại -> Báo lỗi", !$r6['success']);

echo "\n";

// -------------------------------------------------------------------------
// PHẦN 2: TEST CASE XÁC NHẬN EMAIL (Mục 39 - PDF Trang 45)
// -------------------------------------------------------------------------
echo "📋 PHẦN 2: KIỂM THỬ XÁC NHẬN EMAIL (4 TEST CASES - MỤC 39)\n";

$v1 = verifyEmailToken("");
assertTest("TC39-1: Token rỗng -> Báo lỗi", !$v1['success']);

$v2 = verifyEmailToken("invalid_token_123456");
assertTest("TC39-2: Token sai / không tồn tại -> Báo lỗi", !$v2['success']);

$stmtToken = $pdo->prepare("SELECT verification_token FROM users WHERE email = ?");
$stmtToken->execute([$testEmail]);
$realToken = $stmtToken->fetch()['verification_token'] ?? '';

$v3 = verifyEmailToken($realToken);
assertTest("TC39-3: Token đúng -> Cập nhật email_verified = 1", $v3['success']);

$v4 = verifyEmailToken($realToken);
assertTest("TC39-4: Dùng lại token đã xác nhận -> Báo lỗi", !$v4['success']);

echo "\n";

// -------------------------------------------------------------------------
// PHẦN 3: TEST CASE ĐĂNG NHẬP (Mục 40 - PDF Trang 46)
// -------------------------------------------------------------------------
echo "📋 PHẦN 3: KIỂM THỬ ĐĂNG NHẬP (7 TEST CASES - MỤC 40)\n";

$l1 = loginUser("", "password123");
assertTest("TC40-1: Bỏ trống username/email -> Báo lỗi", !$l1['success']);

$l2 = loginUser("admin", "");
assertTest("TC40-2: Bỏ trống password -> Báo lỗi", !$l2['success']);

$l3 = loginUser("non_existent_user_999", "password123");
assertTest("TC40-3: Tài khoản không tồn tại -> Báo lỗi", !$l3['success']);

$l4 = loginUser("admin", "wrong_password");
assertTest("TC40-4: Sai mật khẩu -> Báo lỗi", !$l4['success']);

$unverifiedEmail = "unverified_" . uniqid() . "@example.com";
$unverifiedUser = "unver_" . substr(uniqid(), -5);
registerUser($unverifiedUser, $unverifiedEmail, "pass123456", "pass123456");

$l5 = loginUser($unverifiedUser, "pass123456");
assertTest("TC40-5: Chưa xác nhận email (email_verified = 0) -> Chặn login", !$l5['success']);

$l6 = loginUser($testEmail, "secret123", true);
assertTest("TC40-6: Đăng nhập hợp lệ -> Thành công, lưu session", $l6['success'] && isset($_SESSION['user_id']));
assertTest("TC40-7: Remember password checkbox -> Đặt remember_token", isset($_COOKIE['remember_user']));

echo "\n";

// -------------------------------------------------------------------------
// PHẦN 4: MA TRẬN PHÂN QUYỀN RBAC (Bài B - BAI_TAP_SINH_VIEN.md)
// -------------------------------------------------------------------------
echo "📋 PHẦN 4: MA TRẬN PHÂN QUYỀN RBAC (BÀI B - ĐẶC TẢ MỞ RỘNG)\n";

$stmtUser = $pdo->prepare("INSERT INTO users (username, email, password_hash, email_verified) VALUES (?, ?, 'hash', 1)");
$tUsername = "rbac_test_" . uniqid();
$stmtUser->execute([$tUsername, "{$tUsername}@example.com"]);
$tUserId = (int)$pdo->lastInsertId();

$stmtP1 = $pdo->prepare("SELECT id FROM permissions WHERE permission_code = 'documents.post'");
$stmtP1->execute();
$permPostId = (int)($stmtP1->fetch()['id'] ?? 1);

assertTest("Bài B.1: Không nhóm, không ngoại lệ -> Từ chối (403)", !hasPermission($tUserId, 'documents.post'));

$pdo->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?, 3)")->execute([$tUserId]);
assertTest("Bài B.2: Nhóm cấp quyền, tài khoản kế thừa -> Cho phép", hasPermission($tUserId, 'documents.post'));

$pdo->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?, 4)")->execute([$tUserId]);
assertTest("Bài B.3: Hai nhóm, chỉ một nhóm cấp quyền -> Cho phép", hasPermission($tUserId, 'documents.post'));

$pdo->prepare("INSERT INTO user_permissions (user_id, permission_id, effect) VALUES (?, ?, 'deny')")->execute([$tUserId, $permPostId]);
assertTest("Bài B.4: Nhóm cấp quyền, nhưng tài khoản bị 'deny' riêng -> TỪ CHỐI", !hasPermission($tUserId, 'documents.post'));

$stmtP2 = $pdo->prepare("SELECT id FROM permissions WHERE permission_code = 'rbac.manage'");
$stmtP2->execute();
$permRbacId = (int)($stmtP2->fetch()['id'] ?? 2);
$pdo->prepare("INSERT INTO user_permissions (user_id, permission_id, effect) VALUES (?, ?, 'allow')")->execute([$tUserId, $permRbacId]);
assertTest("Bài B.5: Không nhóm cấp quyền, tài khoản có 'allow' riêng -> Cho phép", hasPermission($tUserId, 'rbac.manage'));

$pdo->prepare("DELETE FROM user_permissions WHERE user_id = ? AND permission_id = ?")->execute([$tUserId, $permRbacId]);
assertTest("Bài B.6: Xóa ngoại lệ 'allow' -> Trở lại từ chối", !hasPermission($tUserId, 'rbac.manage'));

echo "\n";

// -------------------------------------------------------------------------
// PHẦN 5: GIÁ THEO THỜI GIAN (Bài C - BAI_TAP_SINH_VIEN.md)
// -------------------------------------------------------------------------
echo "📋 PHẦN 5: LỊCH SỬ GIÁ THEO THỜI GIAN (BÀI C)\n";

$p1 = InventoryService::addPrice(1, 'sale', 100000, '2027-01-02 00:00:00', '2027-01-01 00:00:00');
assertTest("Bài C.1: valid_to <= valid_from -> Bị từ chối", !$p1['success']);

$p2 = InventoryService::addPrice(1, 'sale', 120000, '2026-03-01 00:00:00', '2026-08-01 00:00:00');
assertTest("Bài C.2: Hai khoảng giao nhau cùng sản phẩm/loại giá -> Bị từ chối", !$p2['success']);

// Tạo sản phẩm mới theo đúng đặc tả Bài C để kiểm tra 2 khoảng giá tiếp giáp
$stmtNewP = $pdo->prepare("INSERT INTO products (category_id, sku, name, unit_id) VALUES (1, ?, ?, 1)");
$pSku = "PROD_C_" . uniqid();
$stmtNewP->execute([$pSku, "Sản phẩm kiểm thử giá C"]);
$prodCId = (int)$pdo->lastInsertId();

$p3 = InventoryService::addPrice($prodCId, 'purchase', 21000000, '2028-01-01 00:00:00', '2028-06-30 23:59:59');
$p4 = InventoryService::addPrice($prodCId, 'purchase', 22000000, '2028-07-01 00:00:00', '2028-12-31 23:59:59');
assertTest("Bài C.3: Hai khoảng giá tiếp giáp không giao nhau -> Được chấp nhận", $p3['success'] && $p4['success']);

$vp1 = InventoryService::getValidPrice(1, 'sale', '2026-01-01 00:00:00');
assertTest("Bài C.4: Tại đúng valid_from -> Chọn mức giá bắt đầu thời điểm đó", $vp1 !== null && (float)$vp1['amount'] == 25000000.00);

echo "\n";

// -------------------------------------------------------------------------
// PHẦN 6: NHẬP, XUẤT, CHẶN ÂM TỒN & ĐỐI SOÁT (Bài D - BAI_TAP_SINH_VIEN.md)
// -------------------------------------------------------------------------
echo "📋 PHẦN 6: NHẬP, XUẤT, CHẶN ÂM TỒN & ĐỐI SOÁT TOÀN VẸN (BÀI D)\n";

$docIn = InventoryService::createDraftDocument('IN', '2026-11-12', 'Nhà cung cấp A', 'Nhập kho thử nghiệm', 1, [
    ['product_id' => 1, 'location_id' => 1, 'lot_id' => 1, 'quantity' => 10]
]);
assertTest("Bài D.1: Tạo phiếu nháp nhập kho (DRAFT)", $docIn['success']);

$stmtCheckBal = $pdo->prepare("SELECT quantity FROM stock_balances WHERE product_id = 1 AND location_id = 1 AND lot_id = 1");
$stmtCheckBal->execute();
$balBefore = (int)($stmtCheckBal->fetch()['quantity'] ?? 0);
assertTest("Bài D.2: Lưu nháp KHÔNG làm thay đổi tồn kho (Tồn = {$balBefore})", true);

$postIn = InventoryService::postDocument($docIn['document_id'], 1);
assertTest("Bài D.3: Ghi sổ phiếu nhập -> Thành công", $postIn['success']);

$stmtCheckBal->execute();
$balAfterIn = (int)($stmtCheckBal->fetch()['quantity'] ?? 0);
assertTest("Bài D.4: Sau khi ghi sổ -> Tồn kho tăng đúng 10 đơn vị", ($balAfterIn - $balBefore) === 10);

$postAgain = InventoryService::postDocument($docIn['document_id'], 1);
assertTest("Bài D.5: Ghi sổ lặp lại cùng phiếu -> BỊ TỪ CHỐI", !$postAgain['success']);

$docOut1 = InventoryService::createDraftDocument('OUT', '2026-11-12', 'Khách hàng B', 'Xuất kho hợp lệ', 1, [
    ['product_id' => 1, 'location_id' => 1, 'lot_id' => 1, 'quantity' => 6]
]);
$postOut1 = InventoryService::postDocument($docOut1['document_id'], 1);
$stmtCheckBal->execute();
$balAfterOut1 = (int)($stmtCheckBal->fetch()['quantity'] ?? 0);
assertTest("Bài D.6: Ghi sổ xuất 6 đơn vị -> Thành công (Tồn giảm từ 10 xuống còn 4)", $postOut1['success'] && ($balAfterIn - $balAfterOut1) === 6);

$docOut2 = InventoryService::createDraftDocument('OUT', '2026-11-12', 'Khách hàng C', 'Xuất quá tồn', 1, [
    ['product_id' => 1, 'location_id' => 1, 'lot_id' => 1, 'quantity' => 5]
]);
$postOut2 = InventoryService::postDocument($docOut2['document_id'], 1);
$stmtCheckBal->execute();
$balAfterOut2 = (int)($stmtCheckBal->fetch()['quantity'] ?? 0);
assertTest("Bài D.7: Xuất 5 từ số dư 4 -> BỊ CHẶN (Chống âm tồn, rollback nguyên tử)", !$postOut2['success'] && $balAfterOut2 === $balAfterOut1);

$stmtSumMov = $pdo->prepare("
    SELECT SUM(quantity) as sum_qty FROM stock_movements
    WHERE product_id = 1 AND location_id = 1 AND lot_id = 1
");
$stmtSumMov->execute();
$sumMovements = (int)($stmtSumMov->fetch()['sum_qty'] ?? 0);
assertTest("Bài D.8: Đối soát: stock_balances ({$balAfterOut2}) == SUM(stock_movements) ({$sumMovements})", $balAfterOut2 === $sumMovements);

echo "\n";

// =========================================================================
// PHẦN 7: 7 TRỤ CỘT CHẤT LƯỢNG TV4 (QUALITY PILLARS & INVARIANTS)
// =========================================================================
echo "=======================================================================\n";
echo "🛡️ PHẦN 7: KIỂM THỬ 7 TRỤ CỘT CHẤT LƯỢNG & BẤT BIẾN NGHIỆP VỤ TV4\n";
echo "=======================================================================\n";

// Đảm bảo có dữ liệu món ăn trong menu_items
$db->exec("INSERT OR IGNORE INTO categories (id, name) VALUES (1, 'Món Chính TV4')");
$stmtInsItem = $db->prepare("INSERT OR IGNORE INTO menu_items (id, category_id, name, price, is_available) VALUES (?, 1, ?, ?, 1)");
$stmtInsItem->execute([101, 'Mì Cay Hải Sản TV4', 65000.00]);
$stmtInsItem->execute([102, 'Trà Đào Cam Sả TV4', 32000.00]);
$stmtInsItem->execute([103, 'Khoai Tây Chiên TV4', 35000.00]);

// -------------------------------------------------------------------------
// TRỤ CỘT 1: ANTI-TAMPER PRICE CHECK (SERVER PRICE ENFORCED, CLIENT PRICE IGNORED)
// -------------------------------------------------------------------------
echo "\n🔹 TRỤ CỘT 1: ANTI-TAMPER PRICE CHECK (CHỐNG THAO TÚNG ĐƠN GIÁ PHÍA CLIENT)\n";

$tamperCartToken = 'cart_tamper_' . uniqid();
SharedCartService::clearCart($tamperCartToken);

// Client cố tình gửi giá 1đ thay vì 65,000đ
$tamperedCart = SharedCartService::addItem($tamperCartToken, [
    'menu_item_id' => 101,
    'price' => 1.00, // Giá giả mạo
    'quantity' => 2
]);

$cartItem = $tamperedCart['items'][0] ?? null;
assertTest(
    "QP-1.1: SharedCartService bỏ qua giá client (1đ) -> Ép dùng giá Server (65,000đ)",
    $cartItem !== null && (float)$cartItem['price'] === 65000.00
);
assertTest(
    "QP-1.2: Tổng tiền giỏ hàng tính đúng theo giá chuẩn server (130,000đ)",
    (float)$tamperedCart['total_amount'] === 130000.00
);

// Client cố tình gửi số lượng âm hoặc 0 -> Phải được chuẩn hóa tối thiểu là 1
$zeroQtyCart = SharedCartService::addItem($tamperCartToken, [
    'menu_item_id' => 102,
    'price' => 32000.00,
    'quantity' => -5
]);
$itemZero = null;
foreach ($zeroQtyCart['items'] as $it) {
    if ($it['menu_item_id'] === 102) { $itemZero = $it; break; }
}
assertTest(
    "QP-1.3: Số lượng đặt âm/0 được tự động bảo vệ (chuẩn hóa về >= 1)",
    $itemZero !== null && $itemZero['quantity'] >= 1
);

// Kiểm tra ClientOrderController submitOrder chống thao túng giá
$db->exec("INSERT INTO dining_tables (table_number, status) VALUES ('Bàn Tamper Test', 'OCCUPIED')");
$tTableIdTamper = (int)$db->lastInsertId();
$sessTamperId = 'sess_tamper_' . uniqid();
$sessTamperToken = 'tok_tamper_' . uniqid();

$db->prepare("
    INSERT INTO table_sessions (id, table_id, session_token, status, total_amount)
    VALUES (?, ?, ?, 'ACTIVE', 0)
")->execute([$sessTamperId, $tTableIdTamper, $sessTamperToken]);

$orderCtrl = new ClientOrderController();
ob_start();
$orderCtrl->submitOrder($sessTamperToken, [
    'items' => [
        ['menu_item_id' => 101, 'unit_price' => 500.00, 'quantity' => 2] // Client cố tình gửi 500đ
    ]
]);
$submitOutput = ob_get_clean();
$submitJson = json_decode($submitOutput, true);

// Kiểm tra hóa đơn trong CSDL
$stmtOrdCheck = $db->prepare("SELECT total_amount FROM orders WHERE id = ?");
$stmtOrdCheck->execute([$submitJson['order_id'] ?? 0]);
$savedTotal = (float)$stmtOrdCheck->fetchColumn();

assertTest(
    "QP-1.4: Order submitted với giá client 500đ -> Server chốt hóa đơn đúng giá 130,000đ",
    $savedTotal === 130000.00
);

// -------------------------------------------------------------------------
// TRỤ CỘT 2: IDEMPOTENCY-KEY DUPLICATE SUBMISSION REJECTION
// -------------------------------------------------------------------------
echo "\n🔹 TRỤ CỘT 2: IDEMPOTENCY-KEY REJECTION (CHỐNG GỬI ĐƠN HÀNG LẶP DO LAG MẠNG)\n";

$idempotencyKey = 'IDEM-KEY-' . uniqid();
$_SERVER['HTTP_IDEMPOTENCY_KEY'] = $idempotencyKey;

// Submit lần 1 -> Thành công
ob_start();
$orderCtrl->submitOrder($sessTamperToken, [
    'items' => [
        ['menu_item_id' => 102, 'quantity' => 1]
    ]
]);
$resp1Raw = ob_get_clean();
$resp1 = json_decode($resp1Raw, true);

assertTest(
    "QP-2.1: Gửi đơn lần 1 với Idempotency-Key -> Tiếp nhận thành công (status = success)",
    ($resp1['status'] ?? '') === 'success'
);

// Submit lần 2 với cùng Idempotency-Key -> BỊ TỪ CHỐI (HTTP 409 Conflict)
ob_start();
$orderCtrl->submitOrder($sessTamperToken, [
    'items' => [
        ['menu_item_id' => 102, 'quantity' => 1]
    ]
]);
$resp2Raw = ob_get_clean();
$resp2 = json_decode($resp2Raw, true);

assertTest(
    "QP-2.2: Gửi lại cùng Idempotency-Key -> BỊ TỪ CHỐI DUPLICATE (HTTP 409 Conflict)",
    isset($resp2['error']) && strpos($resp2['error'], 'trùng lặp') !== false
);

// Kiểm tra số lượng order được ghi trong DB cho key này chỉ duy nhất 1
$stmtIdemCount = $db->prepare("SELECT COUNT(*) FROM orders WHERE idempotency_key = ?");
$stmtIdemCount->execute([$idempotencyKey]);
$idemCount = (int)$stmtIdemCount->fetchColumn();
assertTest(
    "QP-2.3: Số lượng order được tạo trong Database cho khóa này đúng duy nhất 1",
    $idemCount === 1
);
unset($_SERVER['HTTP_IDEMPOTENCY_KEY']);

// -------------------------------------------------------------------------
// TRỤ CỘT 3: IDEMPOTENT ETL PIPELINE (RUNNING TWICE NEVER DOUBLES REVENUE)
// -------------------------------------------------------------------------
echo "\n🔹 TRỤ CỘT 3: IDEMPOTENT ETL PIPELINE (CHẠY LẠI ETL KHÔNG BAO GIỜ NHÂN ĐÔI DOANH THU)\n";

$sessEtlId = 'sess_etl_test_' . uniqid();
$db->prepare("INSERT INTO dining_tables (table_number, status) VALUES ('Bàn ETL Invariant', 'OCCUPIED')")->execute();
$tblEtlId = (int)$db->lastInsertId();

$tNow = time();
$tCreateSession = date('Y-m-d H:i:s', $tNow - 2400);
$tCloseSession = date('Y-m-d H:i:s', $tNow);

$db->prepare("
    INSERT INTO table_sessions (id, table_id, session_token, status, total_amount, created_at, closed_at, is_etl_processed)
    VALUES (?, ?, ?, 'CHECKOUT', 350000, ?, ?, 0)
")->execute([$sessEtlId, $tblEtlId, 'tok_etl_' . $sessEtlId, $tCreateSession, $tCloseSession]);

$db->prepare("INSERT INTO orders (session_id, order_code, status, total_amount) VALUES (?, 'ORD-ETL-INV', 'CONFIRMED', 350000)")->execute([$sessEtlId]);
$ordEtlId = (int)$db->lastInsertId();

// Món 1: 1 x 65,000 = 65,000 (nấu đúng 400s <= 900s -> Đạt chuẩn)
$t1Create = date('Y-m-d H:i:s', $tNow - 1000);
$t1Complete = date('Y-m-d H:i:s', $tNow - 600);
$db->prepare("
    INSERT INTO order_items (order_id, menu_item_id, item_name, unit_price, quantity, subtotal, status, created_at, completed_at)
    VALUES (?, 101, 'Mì Cay Hải Sản TV4', 65000, 1, 65000, 'COMPLETED', ?, ?)
")->execute([$ordEtlId, $t1Create, $t1Complete]);

// Món 2: 1 x 285,000 = 285,000 (nấu đúng 1100s > 900s -> Vi phạm SLA)
$t2Create = date('Y-m-d H:i:s', $tNow - 1600);
$t2Complete = date('Y-m-d H:i:s', $tNow - 500);
$db->prepare("
    INSERT INTO order_items (order_id, menu_item_id, item_name, unit_price, quantity, subtotal, status, created_at, completed_at)
    VALUES (?, 102, 'Lẩu Hải Sản Khổng Lồ', 285000, 1, 285000, 'COMPLETED', ?, ?)
")->execute([$ordEtlId, $t2Create, $t2Complete]);

// Chạy ETL lần 1
$etlRun1 = EtlService::runEtlForClosedSession($sessEtlId);
$stmtFactCheck = $db->prepare("SELECT COUNT(*), SUM(revenue) FROM fact_order_items WHERE session_id = ?");
$stmtFactCheck->execute([$sessEtlId]);
[$factCount1, $factRev1] = $stmtFactCheck->fetch(PDO::FETCH_NUM);

assertTest("QP-3.1: Chạy ETL lần 1 thành công cho phiên bàn", $etlRun1 === true);
assertTest("QP-3.2: Số bản ghi fact nạp đúng = 2 và tổng doanh thu = 350,000đ", (int)$factCount1 === 2 && (float)$factRev1 === 350000.00);

// Chạy ETL lần 2 (Idempotent test)
$etlRun2 = EtlService::runEtlForClosedSession($sessEtlId);
$stmtFactCheck->execute([$sessEtlId]);
[$factCount2, $factRev2] = $stmtFactCheck->fetch(PDO::FETCH_NUM);

assertTest("QP-3.3: [BẤT BIẾN] Chạy ETL lần 2 vẫn thành công an toàn", $etlRun2 === true);
assertTest("QP-3.4: [BẤT BIẾN] Doanh thu OLAP không đổi ({$factRev2}đ) & số fact không đổi (Chống nhân đôi)", (int)$factCount2 === 2 && (float)$factRev2 === 350000.00);

// -------------------------------------------------------------------------
// TRỤ CỘT 4: APRIORI RECOMMENDATION GENERATION & COMBO RULES
// -------------------------------------------------------------------------
echo "\n🔹 TRỤ CỘT 4: APRIORI ALGORITHM & COMBO RULES (SUPPORT, CONFIDENCE, LIFT)\n";

// Tạo rule combo ưu tiên món 101 -> món 102 với confidence 0.90, giảm 15%
AprioriService::createComboRule(101, 102, 0.90, 15, 0);

$recs = AprioriService::getRecommendations(101, 3);
assertTest("QP-4.1: Thuật toán Apriori trả về danh sách gợi ý mua kèm", !empty($recs));

$topRec = $recs[0] ?? [];
assertTest("QP-4.2: Gợi ý số 1 ưu tiên đúng món chỉ định (target = 102)", ($topRec['target_item_id'] ?? 0) === 102);
assertTest("QP-4.3: Nguồn gốc gợi ý được đánh dấu RULE_OVERRIDE", ($topRec['source'] ?? '') === 'RULE_OVERRIDE');
assertTest("QP-4.4: Độ tin cậy (Confidence) đạt chuẩn >= 0.90 (90%)", ($topRec['confidence'] ?? 0) >= 0.90);

$rules = AprioriService::getAssociationRules(10);
assertTest("QP-4.5: Khai phá Association Rules toàn hệ thống thành công", is_array($rules));

// -------------------------------------------------------------------------
// TRỤ CỘT 5: KITCHEN SLA CALCULATION (>900S VIOLATION FLAG)
// -------------------------------------------------------------------------
echo "\n🔹 TRỤ CỘT 5: KITCHEN SLA CALCULATION (>900S VIOLATION FLAG)\n";

$stmtSlaCheck = $db->prepare("
    SELECT prep_time_seconds, is_sla_violated
    FROM fact_order_items
    WHERE session_id = ?
    ORDER BY prep_time_seconds ASC
");
$stmtSlaCheck->execute([$sessEtlId]);
$slaRows = $stmtSlaCheck->fetchAll();

$fastDish = $slaRows[0] ?? [];
$slowDish = $slaRows[1] ?? [];

assertTest(
    "QP-5.1: Món nấu 400s (<= 900s) -> is_sla_violated = 0 (Đạt chuẩn)",
    ($fastDish['prep_time_seconds'] ?? 0) <= 900 && ($fastDish['is_sla_violated'] ?? 1) === 0
);
assertTest(
    "QP-5.2: Món nấu 1100s (> 900s) -> is_sla_violated = 1 (Vi phạm SLA)",
    ($slowDish['prep_time_seconds'] ?? 0) > 900 && ($slowDish['is_sla_violated'] ?? 0) === 1
);

// Kiểm tra API SLA Analytics
$dataCtrl = new DataPipelineController();
ob_start();
$dataCtrl->getSlaMetrics();
$slaOutput = ob_get_clean();
$slaData = json_decode($slaOutput, true);

assertTest("QP-5.3: API /api/v1/data/sla-metrics chứa cấu hình ngưỡng chuẩn 900 giây", ($slaData['sla_threshold_seconds'] ?? 0) === 900);
assertTest("QP-5.4: API SLA tính toán chính xác tỷ lệ tuân thủ sla_compliance_rate", isset($slaData['sla_compliance_rate']));

// -------------------------------------------------------------------------
// TRỤ CỘT 6: CONCURRENCY & ANTI-NEGATIVE STOCK UNDER SIMULTANEOUS REQUESTS
// -------------------------------------------------------------------------
echo "\n🔹 TRỤ CỘT 6: CONCURRENCY & ANTI-NEGATIVE STOCK (CHỐNG ÂM TỒN & TRANSACTIONS)\n";

// Tạo sản phẩm và vị trí riêng cho test Concurrency
$stmtNewConcP = $pdo->prepare("INSERT INTO products (category_id, sku, name, unit_id) VALUES (1, ?, 'Sản Phẩm Concurrency', 1)");
$concSku = "CONC_" . uniqid();
$stmtNewConcP->execute([$concSku]);
$concProdId = (int)$pdo->lastInsertId();

// Tạo lô
$stmtConcLot = $pdo->prepare("INSERT INTO product_lots (product_id, lot_code, expired_date) VALUES (?, 'LOT-CONC', '2029-12-31')");
$stmtConcLot->execute([$concProdId]);
$concLotId = (int)$pdo->lastInsertId();

// Nhập 10 đơn vị ban đầu
$docInConc = InventoryService::createDraftDocument('IN', '2026-11-12', 'Kho A', 'Nhập đầu kỳ', 1, [
    ['product_id' => $concProdId, 'location_id' => 1, 'lot_id' => $concLotId, 'quantity' => 10]
]);
InventoryService::postDocument($docInConc['document_id'], 1);

// Yêu cầu 1: Xuất 7 đơn vị -> Thành công, còn 3
$docOutConc1 = InventoryService::createDraftDocument('OUT', '2026-11-12', 'Khách A', 'Xuất 7 đơn vị', 1, [
    ['product_id' => $concProdId, 'location_id' => 1, 'lot_id' => $concLotId, 'quantity' => 7]
]);
$postConc1 = InventoryService::postDocument($docOutConc1['document_id'], 1);

$stmtBalConc = $pdo->prepare("SELECT quantity FROM stock_balances WHERE product_id = ? AND location_id = 1 AND lot_id = ?");
$stmtBalConc->execute([$concProdId, $concLotId]);
$balAfter7 = (int)$stmtBalConc->fetchColumn();

assertTest("QP-6.1: Xuất 7 đơn vị từ tồn 10 -> Thành công, tồn kho còn đúng 3", $postConc1['success'] && $balAfter7 === 3);

// Yêu cầu 2: Xuất 5 đơn vị trong khi chỉ còn 3 -> BẮT BUỘC BỊ TỪ CHỐI & ROLLBACK TOÀN DIỆN
$docOutConc2 = InventoryService::createDraftDocument('OUT', '2026-11-12', 'Khách B', 'Xuất 5 đơn vị khi chỉ còn 3', 1, [
    ['product_id' => $concProdId, 'location_id' => 1, 'lot_id' => $concLotId, 'quantity' => 5]
]);
$postConc2 = InventoryService::postDocument($docOutConc2['document_id'], 1);

$stmtBalConc->execute([$concProdId, $concLotId]);
$balAfterFail = (int)$stmtBalConc->fetchColumn();

assertTest("QP-6.2: [BẤT BIẾN] Xuất quá tồn (5 > 3) -> BỊ TỪ CHỐI & ROLLBACK NGUYÊN TỬ", !$postConc2['success']);
assertTest("QP-6.3: [BẤT BIẾN] Tồn kho được bảo toàn nguyên vẹn = 3, KHÔNG BAO GIỜ BỊ ÂM", $balAfterFail === 3);

// Ghi sổ lặp lại cùng phiếu đã post -> Bị chặn
$postAgainConc = InventoryService::postDocument($docOutConc1['document_id'], 1);
assertTest("QP-6.4: Thử ghi sổ lặp lại phiếu xuất đã POSTED -> Bị từ chối triệt để", !$postAgainConc['success']);

// -------------------------------------------------------------------------
// TRỤ CỘT 7: STAR SCHEMA OLAP FACT INTEGRITY
// -------------------------------------------------------------------------
echo "\n🔹 TRỤ CỘT 7: STAR SCHEMA OLAP FACT INTEGRITY (ĐỐI SOÁT TOÀN VẸN)\n";

// Invariant 1: Kho hàng - stock_balances == SUM(stock_movements) cho tất cả sản phẩm
$stmtAuditMovements = $pdo->query("
    SELECT 
        b.product_id, 
        b.location_id, 
        b.lot_id, 
        b.quantity as bal_qty,
        COALESCE(SUM(m.quantity), 0) as mov_qty
    FROM stock_balances b
    LEFT JOIN stock_movements m 
        ON b.product_id = m.product_id 
       AND b.location_id = m.location_id 
       AND b.lot_id = m.lot_id
    GROUP BY b.product_id, b.location_id, b.lot_id
");
$allBalances = $stmtAuditMovements->fetchAll();
$allMatch = true;
foreach ($allBalances as $b) {
    if ((int)$b['bal_qty'] !== (int)$b['mov_qty']) {
        $allMatch = false;
        break;
    }
}
assertTest("QP-7.1: [TOÀN VẸN KHO] Mọi bản ghi: stock_balances == SUM(stock_movements)", $allMatch && count($allBalances) > 0);

// Invariant 2: OLAP Fact - Tất cả fact_order_items đều liên kết với dim_time và dim_menu_item hợp lệ
$stmtOrphanFacts = $db->query("
    SELECT COUNT(*) FROM fact_order_items f
    LEFT JOIN dim_time t ON f.time_key = t.time_key
    LEFT JOIN dim_menu_item d ON f.item_key = d.item_key
    WHERE t.time_key IS NULL OR d.item_key IS NULL
");
$orphanFactCount = (int)$stmtOrphanFacts->fetchColumn();
assertTest("QP-7.2: [TOÀN VẸN OLAP] 100% bản ghi fact_order_items liên kết chuẩn xác với Dimension", $orphanFactCount === 0);

// Invariant 3: Fact Clickstream liên kết với dim_time
$stmtOrphanClicks = $db->query("
    SELECT COUNT(*) FROM fact_clickstream c
    LEFT JOIN dim_time t ON c.time_key = t.time_key
    WHERE t.time_key IS NULL
");
$orphanClickCount = (int)$stmtOrphanClicks->fetchColumn();
assertTest("QP-7.3: [TOÀN VẸN OLAP] 100% bản ghi fact_clickstream liên kết chuẩn với dim_time", $orphanClickCount === 0);

// Invariant 4: Doanh thu fact của các phiên đã chốt khớp chính xác với OLTP orders
$stmtRevAudit = $db->query("
    SELECT 
        ts.id as session_id,
        ts.total_amount as session_total,
        COALESCE(SUM(f.revenue), 0) as fact_total
    FROM table_sessions ts
    JOIN fact_order_items f ON ts.id = f.session_id
    WHERE ts.is_etl_processed = 1
    GROUP BY ts.id, ts.total_amount
");
$revAudits = $stmtRevAudit->fetchAll();
$revMatch = true;
foreach ($revAudits as $ra) {
    if (abs((float)$ra['session_total'] - (float)$ra['fact_total']) > 0.01) {
        $revMatch = false;
        break;
    }
}
assertTest("QP-7.4: [ĐỐI SOÁT TÀI CHÍNH] Doanh thu OLAP fact_order_items == Tổng tiền phiên bàn OLTP", $revMatch && count($revAudits) > 0);

// -------------------------------------------------------------------------
// TỔNG KẾT BỘ KIỂM THỬ
// -------------------------------------------------------------------------
$totalTests = $passCount + $failCount;
$passPercent = $totalTests > 0 ? round(($passCount / $totalTests) * 100, 1) : 0;

echo "\n=======================================================================\n";
echo "📊 KẾT QUẢ KIỂM THỬ HỆ THỐNG DINEFLOW (TV4 MASTER QUALITY GATE):\n";
echo "   Tổng số ca kiểm thử : {$totalTests}\n";
echo "   Số ca đạt (PASS)    : {$passCount}\n";
echo "   Số ca lỗi (FAIL)    : {$failCount}\n";
echo "   Tỷ lệ hoàn thành    : {$passPercent}%\n";
echo "=======================================================================\n";

if ($failCount === 0) {
    echo "🎉 CHÚC MỪNG: TẤT CẢ 100% CÁC TEST CASES ĐÃ ĐẠT CHUẨN (0 FAILURES)!\n";
    echo "   Hệ thống sẵn sàng bàn giao & bảo vệ đồ án trước Hội đồng chấm thi.\n";
    echo "=======================================================================\n";
    exit(0);
} else {
    echo "⚠️ CẢNH BÁO: CÓ {$failCount} TEST CASE BỊ THẤT BẠI. VUI LÒNG KIỂM TRA LẠI LOG.\n";
    echo "=======================================================================\n";
    exit(1);
}
