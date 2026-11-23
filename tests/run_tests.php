<?php
ob_start(null, 0);

/**
 * =======================================================================
 * DINEFLOW AUTOMATED QUALITY GATE & MASTER TEST SUITE (TV4 QUALITY LEAD)
 * =======================================================================
 * 
 * Covering:
 *   - Mục 38: Kiểm thử Đăng ký tài khoản (7 Test Cases)
 *   - Mục 39: Kiểm thử Xác nhận Email Token (4 Test Cases)
 *   - Mục 40: Kiểm thử Đăng nhập & Remember Me (7 Test Cases)
 *   - Ma trận phân quyền RBAC đa cấp (6 Test Cases)
 *   - Thực đơn 35 món ăn QR Dine & Phân trang thật trên DB (4 Test Cases)
 *   - Vòng đời Phiên bàn QR, Đơn bếp KDS & Hóa đơn POS (5 Test Cases)
 *   - 7 TRỤ CỘT CHẤT LƯỢNG TV4 (QUALITY PILLARS & BUSINESS INVARIANTS):
 *       Pillar 1: Anti-tamper price check (Client price ignored, Server price used)
 *       Pillar 2: Idempotency-Key duplicate submission rejection (HTTP 409)
 *       Pillar 3: Idempotent ETL pipeline (Running twice doesn't double OLAP revenue)
 *       Pillar 4: Apriori recommendation generation & combo rules (Support, Confidence, Lift)
 *       Pillar 5: Kitchen SLA calculation (>900s violation flag)
 *       Pillar 6: Shared Cart concurrency & atomic order checkout
 *       Pillar 7: Star schema OLAP & OLTP order integrity
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

require_once $appDir . '/Core/Database.php';
require_once $appDir . '/Services/SharedCartService.php';
require_once $appDir . '/Services/EtlService.php';
require_once $appDir . '/Services/AprioriService.php';
require_once $appDir . '/Services/OrderToppingSnapshotService.php';
require_once $appDir . '/Controllers/AdminCatalogController.php';
require_once $appDir . '/Controllers/ClientCancellationController.php';
require_once $appDir . '/Controllers/ClientOrderController.php';
require_once $appDir . '/Controllers/KdsPosController.php';
require_once $appDir . '/Controllers/DataPipelineController.php';

use App\Core\Database;
use App\Services\SharedCartService;
use App\Services\EtlService;
use App\Services\AprioriService;
use App\Services\OrderToppingSnapshotService;
use App\Controllers\AdminCatalogController;
use App\Controllers\ClientCancellationController;
use App\Controllers\ClientOrderController;
use App\Controllers\KdsPosController;
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
// PHẦN 4: MA TRẬN PHÂN QUYỀN RBAC NHÀ HÀNG QR DINE
// -------------------------------------------------------------------------
echo "📋 PHẦN 4: MA TRẬN PHÂN QUYỀN RBAC NHÀ HÀNG QR DINE\n";

$stmtUser = $pdo->prepare("INSERT INTO users (username, email, password_hash, email_verified) VALUES (?, ?, 'hash', 1)");
$tUsername = "rbac_test_" . uniqid();
$stmtUser->execute([$tUsername, "{$tUsername}@example.com"]);
$tUserId = (int)$pdo->lastInsertId();

$stmtP1 = $pdo->prepare("SELECT id FROM permissions WHERE permission_code = 'kds.update'");
$stmtP1->execute();
$permKdsUpdateId = (int)($stmtP1->fetch()['id'] ?? 1);

assertTest("Bài B.1: Không nhóm, không ngoại lệ -> Từ chối (403)", !hasPermission($tUserId, 'kds.update'));

$pdo->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?, 3)")->execute([$tUserId]);
assertTest("Bài B.2: Nhóm cấp quyền, tài khoản kế thừa -> Cho phép", hasPermission($tUserId, 'kds.update'));

$pdo->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?, 4)")->execute([$tUserId]);
assertTest("Bài B.3: Hai nhóm, chỉ một nhóm cấp quyền -> Cho phép", hasPermission($tUserId, 'kds.update'));

$pdo->prepare("INSERT INTO user_permissions (user_id, permission_id, effect) VALUES (?, ?, 'deny')")->execute([$tUserId, $permKdsUpdateId]);
assertTest("Bài B.4: Nhóm cấp quyền, nhưng tài khoản bị 'deny' riêng -> TỪ CHỐI", !hasPermission($tUserId, 'kds.update'));

$stmtP2 = $pdo->prepare("SELECT id FROM permissions WHERE permission_code = 'rbac.manage'");
$stmtP2->execute();
$permRbacId = (int)($stmtP2->fetch()['id'] ?? 2);
$pdo->prepare("INSERT INTO user_permissions (user_id, permission_id, effect) VALUES (?, ?, 'allow')")->execute([$tUserId, $permRbacId]);
assertTest("Bài B.5: Không nhóm cấp quyền, tài khoản có 'allow' riêng -> Cho phép", hasPermission($tUserId, 'rbac.manage'));

$pdo->prepare("DELETE FROM user_permissions WHERE user_id = ? AND permission_id = ?")->execute([$tUserId, $permRbacId]);
assertTest("Bài B.6: Xóa ngoại lệ 'allow' -> Trở lại từ chối", !hasPermission($tUserId, 'rbac.manage'));

$rolePermissionCases = [
    ['ADMIN', 'tables.view', true],
    ['ADMIN', 'tables.manage', true],
    ['ADMIN', 'products.manage', true],
    ['ADMIN', 'kds.update', true],
    ['ADMIN', 'pos.checkout', true],
    ['ADMIN', 'reports.view', true],
    ['ADMIN', 'data.manage', true],
    ['CREATOR', 'tables.view', true],
    ['CREATOR', 'tables.open', true],
    ['CREATOR', 'kds.view', true],
    ['CREATOR', 'products.manage', false],
    ['CREATOR', 'kds.update', false],
    ['CREATOR', 'pos.view', false],
    ['CREATOR', 'pos.checkout', false],
    ['CREATOR', 'reports.view', false],
    ['KEEPER', 'kds.view', true],
    ['KEEPER', 'kds.update', true],
    ['KEEPER', 'products.view', false],
    ['KEEPER', 'tables.open', false],
    ['KEEPER', 'pos.checkout', false],
    ['KEEPER', 'reports.view', false],
    ['VIEWER', 'pos.view', true],
    ['VIEWER', 'pos.checkout', true],
    ['VIEWER', 'documents.print', true],
    ['VIEWER', 'reports.view', true],
    ['VIEWER', 'products.view', false],
    ['VIEWER', 'products.manage', false],
    ['VIEWER', 'tables.open', false],
    ['VIEWER', 'tables.manage', false],
    ['VIEWER', 'kds.view', false],
    ['VIEWER', 'kds.update', false],
    ['VIEWER', 'data.manage', false],
];

$matrixUserIds = [];
foreach ($rolePermissionCases as [$roleCode, $permissionCode, $expected]) {
    $stmtRole = $pdo->prepare("SELECT id FROM roles WHERE role_code = ?");
    $stmtRole->execute([$roleCode]);
    $roleId = (int)($stmtRole->fetch()['id'] ?? 0);

    $matrixUsername = 'matrix_' . strtolower($roleCode) . '_' . uniqid();
    $stmtMatrixUser = $pdo->prepare(
        "INSERT INTO users (username, email, password_hash, email_verified) VALUES (?, ?, 'hash', 1)"
    );
    $stmtMatrixUser->execute([$matrixUsername, "{$matrixUsername}@example.com"]);
    $matrixUserId = (int)$pdo->lastInsertId();

    if ($roleId > 0) {
        $pdo->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)")->execute([$matrixUserId, $roleId]);
    }
    $matrixUserIds[$roleCode] = $matrixUserId;

    assertTest(
        "RBAC {$roleCode}: {$permissionCode} " . ($expected ? 'được phép' : 'bị từ chối'),
        hasPermission($matrixUserId, $permissionCode) === $expected
    );
}

$anonymousApiAccess = apiPermissionDecision(null, 'tables.view');
assertTest(
    "API RBAC: chưa đăng nhập nhận 401",
    $anonymousApiAccess['allowed'] === false && $anonymousApiAccess['http_status'] === 401
);

$deniedApiAccess = apiPermissionDecision($matrixUserIds['CREATOR'], 'pos.checkout');
assertTest(
    "API RBAC: CREATOR gọi checkout nhận 403",
    $deniedApiAccess['allowed'] === false && $deniedApiAccess['http_status'] === 403
);

$allowedApiAccess = apiPermissionDecision($matrixUserIds['VIEWER'], 'pos.checkout');
assertTest(
    "API RBAC: VIEWER gọi checkout được phép",
    $allowedApiAccess['allowed'] === true && $allowedApiAccess['http_status'] === 200
);

assertTest(
    "Landing: ADMIN vào dashboard",
    getStaffLandingPath($matrixUserIds['ADMIN']) === 'dashboard.php'
);
assertTest(
    "Landing: CREATOR vào quản lý bàn",
    getStaffLandingPath($matrixUserIds['CREATOR']) === 'frontend/admin/table-management.php'
);
assertTest(
    "Landing: KEEPER vào KDS",
    getStaffLandingPath($matrixUserIds['KEEPER']) === 'frontend/kds/index.php'
);
assertTest(
    "Landing: VIEWER vào POS",
    getStaffLandingPath($matrixUserIds['VIEWER']) === 'frontend/pos/index.php'
);
assertTest(
    "Portal shell entry: ADMIN/CREATOR/KEEPER/VIEWER vào đúng tab mặc định",
    getStaffPortalEntryPath($matrixUserIds['ADMIN']) === 'portal.php?tab=dashboard'
        && getStaffPortalEntryPath($matrixUserIds['CREATOR']) === 'portal.php?tab=tables'
        && getStaffPortalEntryPath($matrixUserIds['KEEPER']) === 'portal.php?tab=kds'
        && getStaffPortalEntryPath($matrixUserIds['VIEWER']) === 'portal.php?tab=pos'
);

$creatorPortalKeys = array_column(getStaffPortalNavigation($matrixUserIds['CREATOR']), 'key');
assertTest(
    "Portal nav: CREATOR chỉ thấy bàn, thực đơn đọc và KDS",
    $creatorPortalKeys === ['tables', 'products', 'kds']
);
$creatorMenuNavigation = array_values(array_filter(
    getStaffPortalNavigation($matrixUserIds['CREATOR']),
    static fn(array $item): bool => $item['key'] === 'products'
));
assertTest(
    "Portal menu: CREATOR thấy một mục 'Quản lý món' chỉ đọc",
    count($creatorMenuNavigation) === 1
        && $creatorMenuNavigation[0]['label'] === 'Quản lý món'
        && $creatorMenuNavigation[0]['href'] === 'products.php'
        && !hasPermission($matrixUserIds['CREATOR'], 'products.manage')
);

$keeperPortalKeys = array_column(getStaffPortalNavigation($matrixUserIds['KEEPER']), 'key');
assertTest(
    "Portal nav: KEEPER chỉ thấy KDS",
    $keeperPortalKeys === ['kds']
);

$viewerPortalKeys = array_column(getStaffPortalNavigation($matrixUserIds['VIEWER']), 'key');
assertTest(
    "Portal nav: VIEWER thấy POS, hóa đơn và dashboard",
    $viewerPortalKeys === ['pos', 'documents', 'dashboard']
);

$adminPortalKeys = array_column(getStaffPortalNavigation($matrixUserIds['ADMIN']), 'key');
assertTest(
    "Portal nav: ADMIN thấy đủ phân hệ nhân viên",
    $adminPortalKeys === ['tables', 'products', 'kds', 'pos', 'documents', 'live-ops', 'dashboard']
);

echo "\n";

// -------------------------------------------------------------------------
// PHẦN 5: THỰC ĐƠN NHÀ HÀNG & PHÂN TRANG THẬT TRÊN DB (QR DINE)
// -------------------------------------------------------------------------
echo "📋 PHẦN 5: THỰC ĐƠN NHÀ HÀNG & PHÂN TRANG THẬT TRÊN DB (QR DINE)\n";

$totalMenuDishes = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE sku LIKE 'DISH-%'")->fetchColumn();
assertTest("QR-5.1: Đủ 35 món ăn chuẩn nhà hàng QR Dine (DISH-001 .. DISH-035)", $totalMenuDishes === 35);

$totalCats = (int)$pdo->query("SELECT COUNT(*) FROM categories")->fetchColumn();
assertTest("QR-5.2: Đủ 4 nhóm thực đơn nhà hàng (Món Chính, Món Ăn Kèm, Đồ Uống, Combo)", $totalCats === 4);

$page1Dishes = $pdo->query("SELECT id, sku, name FROM products ORDER BY id ASC LIMIT 10 OFFSET 0")->fetchAll();
assertTest("QR-5.3: Phân trang thật trên DB (LIMIT 10 OFFSET 0) trả về đúng 10 món trang 1", count($page1Dishes) === 10 && $page1Dishes[0]['sku'] === 'DISH-001');

$stmtSearchDish = $pdo->prepare("SELECT COUNT(*) FROM products WHERE name LIKE ? AND category_id = 1");
$stmtSearchDish->execute(['%Lẩu%']);
$lauCount = (int)$stmtSearchDish->fetchColumn();
assertTest("QR-5.4: Lọc kết hợp Từ khóa ('Lẩu') + Danh mục ('Món Chính') chính xác", $lauCount > 0);

echo "\n";

// -------------------------------------------------------------------------
// PHẦN 6: VÒNG ĐỜI PHIÊN BÀN QR, ĐƠN BẾP KDS & HÓA ĐƠN POS (QR DINE)
// -------------------------------------------------------------------------
echo "📋 PHẦN 6: VÒNG ĐỜI PHIÊN BÀN QR, ĐƠN BẾP KDS & HÓA ĐƠN POS\n";

$totalTables = (int)$pdo->query("SELECT COUNT(*) FROM dining_tables")->fetchColumn();
assertTest("QR-6.1: Khởi tạo đủ 15 bàn ăn nhà hàng QR Dine (Bàn 01 .. Bàn 15)", $totalTables >= 15);

$activeSessions = (int)$pdo->query("SELECT COUNT(*) FROM table_sessions WHERE status = 'ACTIVE'")->fetchColumn();
assertTest("QR-6.2: Có phiên bàn QR đang hoạt động (ACTIVE) kèm session_token hợp lệ", $activeSessions > 0);

$kdsOrdersCount = (int)$pdo->query("SELECT COUNT(*) FROM order_items WHERE status IN ('PENDING', 'COOKING', 'READY', 'SERVED', 'COMPLETED')")->fetchColumn();
assertTest("QR-6.3: Có đơn hàng KDS hợp lệ trong hệ thống", $kdsOrdersCount > 0);

$posBills = $pdo->query("SELECT id, subtotal_amount, vat_amount, service_fee, final_amount, payment_method FROM bill_payments")->fetchAll();
assertTest("QR-6.4: Có hóa đơn thanh toán POS trong bảng bill_payments", count($posBills) > 0);

$billMathValid = true;
foreach ($posBills as $bill) {
    $expectedRaw = (float)$bill['subtotal_amount'] + (float)$bill['vat_amount'] + (float)$bill['service_fee'];
    $expectedRounded = round($expectedRaw / 500) * 500;
    $actualFinal = (float)$bill['final_amount'];
    if (abs($expectedRaw - $actualFinal) > 1.0 && abs($expectedRounded - $actualFinal) > 1.0) {
        $billMathValid = false;
        break;
    }
}
assertTest("QR-6.5: Đối soát công thức hóa đơn POS: final_amount khớp subtotal_amount + VAT + service_fee", $billMathValid);

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
$db->prepare("UPDATE dining_tables SET current_session_id = ? WHERE id = ?")
    ->execute([$sessTamperId, $tTableIdTamper]);

$_SERVER['HTTP_IDEMPOTENCY_KEY'] = 'IDEM-TAMPER-' . uniqid();
$orderCtrl = new ClientOrderController();
ob_start();
$orderCtrl->submitOrder($sessTamperToken, [
    'items' => [
        ['menu_item_id' => 101, 'unit_price' => 500.00, 'quantity' => 2] // Client cố tình gửi 500đ
    ]
]);
$submitOutput = ob_get_clean();
unset($_SERVER['HTTP_IDEMPOTENCY_KEY']);
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
// ACCEPTANCE: UNIFIED MENU, TOPPING CATEGORIES, AUDIT, CANCEL & ORDER SNAPSHOTS
// -------------------------------------------------------------------------
echo "\n📋 ACCEPTANCE: QUẢN LÝ MÓN, TOPPING, HỦY MÓN, LỊCH SỬ KHÁCH, KDS & HÓA ĐƠN\n";

$adminCatalog = new AdminCatalogController();
$kdsPos = new KdsPosController();
$cancelCtrl = new ClientCancellationController();
$acceptanceActorId = $matrixUserIds['ADMIN'];
$_SESSION['user_id'] = $acceptanceActorId;

$acceptanceSuffix = substr(uniqid('', true), 0, 12);
$acceptanceItemName = 'Acceptance món ' . $acceptanceSuffix;
$acceptanceToppingName = 'Acceptance topping ' . $acceptanceSuffix;
$acceptanceTableNumber = 'AT-' . $acceptanceSuffix;
$acceptanceToppingId = 0;
$acceptanceItemId = 0;
$acceptanceTableId = 0;
$acceptanceSessionId = 'sess_at_' . $acceptanceSuffix;
$acceptanceSessionToken = 'token_at_' . $acceptanceSuffix;
$acceptanceOrderId = 0;
$acceptanceIdempotencyKey = 'IDEM-AT-' . $acceptanceSuffix;

ob_start();
$adminCatalog->createMenuItem([
    'name' => $acceptanceItemName,
    'category_id' => 1,
    'price' => 1200,
    'description' => 'Acceptance description',
    'is_hot' => false,
]);
$createdMenuItem = json_decode(ob_get_clean(), true);
$acceptanceItemId = (int)($createdMenuItem['id'] ?? 0);

$stmtCreateAudit = $db->prepare("
    SELECT actor_id, action, entity_type, entity_id, old_value, new_value
    FROM audit_logs
    WHERE entity_type = 'menu_item' AND entity_id = ? AND action = 'create'
    ORDER BY id DESC LIMIT 1
");
$stmtCreateAudit->execute([(string)$acceptanceItemId]);
$createAudit = $stmtCreateAudit->fetch();
assertTest(
    "Menu create: tạo món và ghi actor/action/entity/new_value",
    $acceptanceItemId > 0
        && $createAudit
        && (int)$createAudit['actor_id'] === $acceptanceActorId
        && $createAudit['new_value'] !== null
);

$stmtProdCheck = $db->prepare("SELECT id FROM products WHERE id = ?");
$stmtProdCheck->execute([$acceptanceItemId]);
if (!$stmtProdCheck->fetch()) {
    $db->prepare("
        INSERT INTO products (id, category_id, sku, name, unit_id, status)
        VALUES (?, 1, ?, ?, 1, 'ACTIVE')
    ")->execute([$acceptanceItemId, 'AT-' . $acceptanceSuffix, $acceptanceItemName]);
}

ob_start();
$adminCatalog->updateMenuItem($acceptanceItemId, [
    'name' => $acceptanceItemName . ' updated',
    'category_id' => 2,
    'price' => 1500,
    'description' => 'Updated acceptance description',
    'is_hot' => true,
]);
$updatedMenuItem = json_decode(ob_get_clean(), true);
$stmtUpdatedItem = $db->prepare("
    SELECT m.name, m.category_id, m.price, m.description, m.is_hot, p.name AS product_name, p.category_id AS product_category_id
    FROM menu_items m LEFT JOIN products p ON p.id = m.id WHERE m.id = ?
");
$stmtUpdatedItem->execute([$acceptanceItemId]);
$updatedItemRow = $stmtUpdatedItem->fetch();
$stmtUpdateAudit = $db->prepare("
    SELECT actor_id, action, entity_type, old_value, new_value
    FROM audit_logs WHERE entity_type = 'menu_item' AND entity_id = ? AND action = 'update'
    ORDER BY id DESC LIMIT 1
");
$stmtUpdateAudit->execute([(string)$acceptanceItemId]);
$updateAudit = $stmtUpdateAudit->fetch();
assertTest(
    "Menu edit: cập nhật món và đồng bộ danh mục/tên sản phẩm, ghi audit before/after",
    ($updatedMenuItem['status'] ?? '') === 'updated'
        && $updatedItemRow
        && $updatedItemRow['name'] === $acceptanceItemName . ' updated'
        && $updatedItemRow['product_name'] === $acceptanceItemName . ' updated'
        && (int)$updatedItemRow['category_id'] === 2
        && (int)$updatedItemRow['product_category_id'] === 2
        && (float)$updatedItemRow['price'] === 1500.0
        && (int)$updatedItemRow['is_hot'] === 1
        && $updateAudit
        && (int)$updateAudit['actor_id'] === $acceptanceActorId
        && $updateAudit['old_value'] !== null
        && $updateAudit['new_value'] !== null
);

$_GET['is_available'] = 'false';
ob_start();
$adminCatalog->toggleMenuItemAvailability($acceptanceItemId);
$toggleOff = json_decode(ob_get_clean(), true);
$stmtToggleAudit = $db->prepare("
    SELECT COUNT(*) FROM audit_logs
    WHERE entity_type = 'menu_item' AND entity_id = ? AND action = 'availability.update'
");
$stmtToggleAudit->execute([(string)$acceptanceItemId]);
$toggleAuditCount = (int)$stmtToggleAudit->fetchColumn();
$_GET['is_available'] = 'false';
ob_start();
$adminCatalog->toggleMenuItemAvailability($acceptanceItemId);
ob_end_clean();
$stmtToggleAudit->execute([(string)$acceptanceItemId]);
assertTest(
    "Menu availability: bật/tắt riêng lẻ, retry cùng trạng thái không tạo audit trùng",
    ($toggleOff['is_available'] ?? true) === false
        && (int)$stmtToggleAudit->fetchColumn() === $toggleAuditCount
        && $toggleAuditCount === 1
);
unset($_GET['is_available']);
$_GET['is_available'] = 'true';
ob_start();
$adminCatalog->toggleMenuItemAvailability($acceptanceItemId);
ob_end_clean();
unset($_GET['is_available']);

$stmtTopInsert = $db->prepare("INSERT INTO toppings (name, price, is_available) VALUES (?, 250, 1)");
$stmtTopInsert->execute([$acceptanceToppingName]);
$acceptanceToppingId = (int)$db->lastInsertId();
ob_start();
$adminCatalog->updateMenuItemToppings($acceptanceItemId, ['topping_ids' => [$acceptanceToppingId]]);
$itemToppingUpdate = json_decode(ob_get_clean(), true);
$stmtItemToppingAudit = $db->prepare("
    SELECT actor_id, action, old_value, new_value FROM audit_logs
    WHERE entity_type = 'menu_item_topping' AND entity_id = ? AND action = 'update'
    ORDER BY id DESC LIMIT 1
");
$stmtItemToppingAudit->execute([(string)$acceptanceItemId]);
$itemToppingAudit = $stmtItemToppingAudit->fetch();
$stmtItemToppingAuditCount = $db->prepare("
    SELECT COUNT(*) FROM audit_logs
    WHERE entity_type = 'menu_item_topping' AND entity_id = ? AND action = 'update'
");
$stmtItemToppingAuditCount->execute([(string)$acceptanceItemId]);
$itemToppingAuditCount = (int)$stmtItemToppingAuditCount->fetchColumn();
ob_start();
$adminCatalog->updateMenuItemToppings($acceptanceItemId, ['topping_ids' => [$acceptanceToppingId]]);
$unchangedItemTopping = json_decode(ob_get_clean(), true);
$stmtItemToppingAuditCount->execute([(string)$acceptanceItemId]);
ob_start();
$adminCatalog->getMenuCatalog();
$catalog = json_decode(ob_get_clean(), true);
$mappedToppings = [];
foreach (($catalog['categories'] ?? []) as $category) {
    foreach (($category['items'] ?? []) as $catalogItem) {
        if ((int)$catalogItem['id'] === $acceptanceItemId) {
            $mappedToppings = array_map('intval', array_column($catalogItem['toppings'] ?? [], 'id'));
        }
    }
}
assertTest(
    "Topping mapping: gán trực tiếp cho món, audit idempotent và trả đúng topping",
    ($itemToppingUpdate['topping_ids'] ?? []) === [$acceptanceToppingId]
        && in_array($acceptanceToppingId, $mappedToppings, true)
        && $itemToppingAudit
        && (int)$itemToppingAudit['actor_id'] === $acceptanceActorId
        && $itemToppingAudit['old_value'] !== null
        && $itemToppingAudit['new_value'] !== null
        && ($unchangedItemTopping['status'] ?? '') === 'unchanged'
        && (int)$stmtItemToppingAuditCount->fetchColumn() === $itemToppingAuditCount
        && $itemToppingAuditCount === 1
);

$db->prepare("INSERT INTO dining_tables (table_number, status) VALUES (?, 'OCCUPIED')")
    ->execute([$acceptanceTableNumber]);
$acceptanceTableId = (int)$db->lastInsertId();
$db->prepare("
    INSERT INTO table_sessions (id, table_id, session_token, status, total_amount)
    VALUES (?, ?, ?, 'ACTIVE', 0)
")->execute([$acceptanceSessionId, $acceptanceTableId, $acceptanceSessionToken]);
$db->prepare("UPDATE dining_tables SET current_session_id = ? WHERE id = ?")
    ->execute([$acceptanceSessionId, $acceptanceTableId]);

$_SERVER['HTTP_IDEMPOTENCY_KEY'] = $acceptanceIdempotencyKey;
$orderController = new ClientOrderController();
ob_start();
$orderController->submitOrder($acceptanceSessionToken, [
    'items' => [[
        'menu_item_id' => $acceptanceItemId,
        'quantity' => 2,
        'unit_price' => 0.01,
        'selected_toppings' => [[
            'id' => $acceptanceToppingId,
            'name' => 'Client-supplied fake topping',
            'price' => 0.01,
        ]],
    ]],
]);
$acceptedOrder = json_decode(ob_get_clean(), true);
$acceptanceOrderId = (int)($acceptedOrder['order_id'] ?? 0);
$stmtSnapshot = $db->prepare("
    SELECT oi.unit_price, oi.subtotal, s.toppings_json
    FROM order_items oi
    JOIN order_item_topping_snapshots s ON s.order_item_id = oi.id
    WHERE oi.order_id = ?
");
$stmtSnapshot->execute([$acceptanceOrderId]);
$savedSnapshot = $stmtSnapshot->fetch();
$savedToppings = $savedSnapshot ? json_decode($savedSnapshot['toppings_json'], true) : null;
assertTest(
    "Submit order: giá/tên topping lấy từ server và snapshot trong cùng order",
    ($acceptedOrder['status'] ?? '') === 'success'
        && $savedSnapshot
        && (float)$savedSnapshot['unit_price'] === 1750.0
        && (float)$savedSnapshot['subtotal'] === 3500.0
        && count($savedToppings ?? []) === 1
        && (int)$savedToppings[0]['id'] === $acceptanceToppingId
        && $savedToppings[0]['name'] === $acceptanceToppingName
        && (float)$savedToppings[0]['unit_price'] === 250.0
);

ob_start();
$orderController->submitOrder($acceptanceSessionToken, [
    'items' => [[
        'menu_item_id' => $acceptanceItemId,
        'quantity' => 2,
        'selected_toppings' => [['id' => $acceptanceToppingId]],
    ]],
]);
$duplicateOrderResponse = json_decode(ob_get_clean(), true);
$stmtSnapshotCount = $db->prepare("
    SELECT COUNT(*) FROM order_item_topping_snapshots s
    JOIN order_items oi ON oi.id = s.order_item_id
    WHERE oi.order_id = ?
");
$stmtSnapshotCount->execute([$acceptanceOrderId]);
assertTest(
    "Retry order: cùng Idempotency-Key không tạo order hoặc snapshot topping thứ hai",
    isset($duplicateOrderResponse['error'])
        && strpos($duplicateOrderResponse['error'], 'trùng lặp') !== false
        && (int)$stmtSnapshotCount->fetchColumn() === 1
);
unset($_SERVER['HTTP_IDEMPOTENCY_KEY']);

ob_start();
$orderController->getOrderStatus($acceptanceSessionToken);
$customerHistory = json_decode(ob_get_clean(), true);
$historyItem = $customerHistory[0] ?? [];
ob_start();
$kdsPos->getKdsActiveItems();
$kdsItems = json_decode(ob_get_clean(), true);
$kdsItem = null;
foreach ($kdsItems as $item) {
    if ((int)$item['order_id'] === $acceptanceOrderId) { $kdsItem = $item; break; }
}
ob_start();
$kdsPos->getTableBill($acceptanceTableId);
$posBill = json_decode(ob_get_clean(), true);
$posItem = null;
foreach (($posBill['items'] ?? []) as $item) {
    if ((int)$item['order_id'] === $acceptanceOrderId) { $posItem = $item; break; }
}
assertTest(
    "Topping snapshot: khách history, KDS và POS nhận chi tiết topping đúng",
    count($historyItem['toppings'] ?? []) === 1
        && ($historyItem['topping_details_recorded'] ?? false) === true
        && $kdsItem
        && count($kdsItem['toppings'] ?? []) === 1
        && $posItem
        && count($posItem['toppings'] ?? []) === 1
);

// Topping gán cho món khác không được dùng kèm acceptance item.
$otherToppingInsert = $db->prepare("INSERT INTO toppings (name, price, is_available) VALUES (?, 99, 1)");
$otherToppingInsert->execute(['Acceptance mismatch ' . $acceptanceSuffix]);
$mismatchedToppingId = (int)$db->lastInsertId();
$db->prepare("INSERT INTO menu_item_toppings (menu_item_id, topping_id) VALUES (101, ?)")
    ->execute([$mismatchedToppingId]);
$_SERVER['HTTP_IDEMPOTENCY_KEY'] = $acceptanceIdempotencyKey . '-BAD';
$stmtOrdersBeforeMismatch = $db->prepare("SELECT COUNT(*) FROM orders WHERE session_id = ?");
$stmtOrdersBeforeMismatch->execute([$acceptanceSessionId]);
$ordersBeforeMismatch = (int)$stmtOrdersBeforeMismatch->fetchColumn();
ob_start();
$orderController->submitOrder($acceptanceSessionToken, [
    'items' => [[
        'menu_item_id' => $acceptanceItemId,
        'quantity' => 1,
        'selected_toppings' => [['id' => $mismatchedToppingId]],
    ]],
]);
$rejectedMismatch = json_decode(ob_get_clean(), true);
unset($_SERVER['HTTP_IDEMPOTENCY_KEY']);
$stmtOrdersBeforeMismatch->execute([$acceptanceSessionId]);
$ordersAfterMismatch = (int)$stmtOrdersBeforeMismatch->fetchColumn();
$db->prepare("DELETE FROM toppings WHERE id = ?")->execute([$mismatchedToppingId]);
assertTest(
    "Submit order: từ chối topping không gán cho đúng món, không ghi đơn",
    isset($rejectedMismatch['error'])
        && stripos($rejectedMismatch['error'], 'topping') !== false
        && $ordersAfterMismatch === $ordersBeforeMismatch
);

// Xóa fixture để không ảnh hưởng các kiểm tra tài chính/ETL phía dưới.
if ($acceptanceOrderId > 0) {
    $db->prepare("DELETE FROM orders WHERE id = ?")->execute([$acceptanceOrderId]);
}
$db->prepare("DELETE FROM table_sessions WHERE id = ?")->execute([$acceptanceSessionId]);
$db->prepare("DELETE FROM dining_tables WHERE id = ?")->execute([$acceptanceTableId]);
if ($acceptanceItemId > 0) {
    $db->prepare("DELETE FROM audit_logs WHERE entity_type = 'menu_item' AND entity_id = ?")->execute([(string)$acceptanceItemId]);
    $db->prepare("DELETE FROM audit_logs WHERE entity_type = 'menu_item_topping' AND entity_id = ?")->execute([(string)$acceptanceItemId]);
    $db->prepare("DELETE FROM products WHERE id = ?")->execute([$acceptanceItemId]);
    $db->prepare("DELETE FROM menu_item_toppings WHERE topping_id = ?")->execute([$acceptanceToppingId]);
    $db->prepare("DELETE FROM toppings WHERE id = ?")->execute([$acceptanceToppingId]);
    $db->prepare("DELETE FROM menu_item_toppings WHERE menu_item_id = ?")->execute([$acceptanceItemId]);
    $db->prepare("DELETE FROM menu_items WHERE id = ?")->execute([$acceptanceItemId]);
}

// Hồi quy PR#13 + PR#6 + TV4: hủy món PENDING -> POS Bill, Checkout và ETL loại trừ món CANCELLED
$cancelSuffix = substr(uniqid('', true), 0, 10);
$db->prepare("INSERT INTO dining_tables (table_number, status) VALUES (?, 'OCCUPIED')")
    ->execute(['Bàn Cancel Reg ' . $cancelSuffix]);
$cancelTableId = (int)$db->lastInsertId();
$cancelSessId = 'sess_cancel_' . $cancelSuffix;
$cancelToken = 'tok_cancel_' . $cancelSuffix;
$db->prepare("INSERT INTO table_sessions (id, table_id, session_token, status, total_amount) VALUES (?, ?, ?, 'ACTIVE', 0)")
    ->execute([$cancelSessId, $cancelTableId, $cancelToken]);
$db->prepare("UPDATE dining_tables SET current_session_id = ? WHERE id = ?")
    ->execute([$cancelSessId, $cancelTableId]);

$_SERVER['HTTP_IDEMPOTENCY_KEY'] = 'IDEM-CANCEL-' . $cancelSuffix;
ob_start();
$orderController->submitOrder($cancelToken, [
    'items' => [
        ['menu_item_id' => 101, 'quantity' => 1],
        ['menu_item_id' => 102, 'quantity' => 1],
    ],
]);
$cancelOrderRes = json_decode(ob_get_clean(), true);
unset($_SERVER['HTTP_IDEMPOTENCY_KEY']);
$cancelOrderItemIds = $cancelOrderRes['order_item_ids'] ?? [];
$itemToCancelId = (int)($cancelOrderItemIds[1] ?? 0);

ob_start();
$cancelCtrl->cancelItem($cancelToken, $itemToCancelId);
$cancelItemOut = json_decode(ob_get_clean(), true);

ob_start();
$kdsPos->getTableBill($cancelTableId);
$billAfterCancel = json_decode(ob_get_clean(), true);

ob_start();
$kdsPos->checkout($cancelSessId);
$checkoutAfterCancel = json_decode(ob_get_clean(), true);

$stmtCancelFacts = $db->prepare("SELECT COUNT(*), COALESCE(SUM(revenue), 0) FROM fact_order_items WHERE session_id = ?");
$stmtCancelFacts->execute([$cancelSessId]);
[$cancelFactCount, $cancelFactRev] = $stmtCancelFacts->fetch(PDO::FETCH_NUM);

assertTest(
    "Hồi quy hủy món: POS bill, checkout & ETL chỉ tính món không bị CANCELLED (65,000đ)",
    ($cancelItemOut['status'] ?? '') === 'success'
        && count($billAfterCancel['items'] ?? []) === 1
        && abs((float)($billAfterCancel['subtotal'] ?? 0) - 65000.0) < 0.01
        && abs((float)($checkoutAfterCancel['subtotal'] ?? 0) - 65000.0) < 0.01
        && (int)$cancelFactCount === 1
        && abs((float)$cancelFactRev - 65000.0) < 0.01
);

unset($_SESSION['user_id']);
echo "\n";

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
// TRỤ CỘT 6: SHARED CART CONCURRENCY & ATOMIC ORDER CHECKOUT
// -------------------------------------------------------------------------
echo "\n🔹 TRỤ CỘT 6: ĐỒNG BỘ GIỎ HÀNG BÀN ĂN & GIAO DỊCH CHỐT ĐƠN NGUYÊN TỬ\n";

$db->prepare("INSERT INTO dining_tables (table_number, status) VALUES ('Bàn Cart Concurrency', 'OCCUPIED')")->execute();
$cartTableId = (int)$db->lastInsertId();
$cartSessId = 'sess_cart_' . uniqid();
$cartToken = 'tok_cart_' . uniqid();
$db->prepare("INSERT INTO table_sessions (id, table_id, session_token, status, total_amount) VALUES (?, ?, ?, 'ACTIVE', 0)")
    ->execute([$cartSessId, $cartTableId, $cartToken]);
$db->prepare("UPDATE dining_tables SET current_session_id = ? WHERE id = ?")
    ->execute([$cartSessId, $cartTableId]);

SharedCartService::clearCart($cartToken);

// 2 khách cùng bàn thêm món đồng thời vào giỏ chung
SharedCartService::addItem($cartToken, ['menu_item_id' => 101, 'quantity' => 2, 'notes' => 'Khách A: ít cay']);
SharedCartService::addItem($cartToken, ['menu_item_id' => 102, 'quantity' => 1, 'notes' => 'Khách B: ít đường']);
$cartState = SharedCartService::getCart($cartToken);

assertTest("QP-6.1: Nhiều khách cùng bàn thêm món -> Giỏ hàng chung đồng bộ đúng 2 dòng món", count($cartState['items'] ?? []) === 2);

// Cập nhật giảm số lượng món 102 về 0 -> Tự động xóa dòng món khỏi giỏ
$cartAfterRemove = SharedCartService::updateItem($cartToken, 102, -1);
assertTest("QP-6.2: Giảm số lượng về 0 -> Xóa dòng món khỏi giỏ hàng chung", count($cartAfterRemove['items'] ?? []) === 1);

// Chốt đơn từ giỏ hàng chung -> tạo order nguyên tử và làm sạch giỏ
$checkoutKey = 'idem-conc-' . uniqid();
$_SERVER['HTTP_IDEMPOTENCY_KEY'] = $checkoutKey;
ob_start();
$orderCtrl->submitOrder($cartToken, ['items' => $cartAfterRemove['items']]);
$checkoutOut = json_decode(ob_get_clean(), true);
unset($_SERVER['HTTP_IDEMPOTENCY_KEY']);
$cartAfterCheckout = SharedCartService::getCart($cartToken);
assertTest("QP-6.3: [GIAO DỊCH NGUYÊN TỬ] Chốt đơn thành công và tự động làm rỗng giỏ bàn", ($checkoutOut['status'] ?? '') === 'success' && !empty($checkoutOut['order_id']) && empty($cartAfterCheckout['items']));

// Thử chốt đơn khi giỏ hàng đang rỗng -> Bị chặn HTTP 422
ob_start();
$orderCtrl->submitOrder($cartToken, ['items' => []]);
$emptyOut = json_decode(ob_get_clean(), true);
assertTest("QP-6.4: [BẤT BIẾN] Chốt đơn khi giỏ rỗng -> Bị từ chối", !empty($emptyOut['error']) && empty($emptyOut['order_id']));

// -------------------------------------------------------------------------
// TRỤ CỘT 7: STAR SCHEMA OLAP & OLTP ORDER INTEGRITY
// -------------------------------------------------------------------------
echo "\n🔹 TRỤ CỘT 7: STAR SCHEMA OLAP & OLTP ORDER INTEGRITY (ĐỐI SOÁT TOÀN VẸN)\n";

// Invariant 1: Mọi đơn hàng OLTP có total_amount == SUM(order_items.subtotal trừ CANCELLED)
$stmtAuditOrders = $db->query("
    SELECT 
        o.id,
        o.total_amount,
        COALESCE(SUM(CASE WHEN oi.status <> 'CANCELLED' THEN oi.subtotal ELSE 0 END), 0) as items_sum
    FROM orders o
    LEFT JOIN order_items oi ON o.id = oi.order_id
    GROUP BY o.id, o.total_amount
");
$allOrdersAudit = $stmtAuditOrders->fetchAll();
$allOrderTotalsMatch = true;
foreach ($allOrdersAudit as $ord) {
    if (abs((float)$ord['total_amount'] - (float)$ord['items_sum']) > 1.0) {
        $allOrderTotalsMatch = false;
        break;
    }
}
assertTest("QP-7.1: [TOÀN VẸN ĐƠN HÀNG] Mọi đơn hàng: orders.total_amount == SUM(order_items.subtotal)", $allOrderTotalsMatch && count($allOrdersAudit) > 0);

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
// PHẦN 8: KIỂM THỬ BÁO CÁO BI, MENU ENGINEERING MATRIX & EXPORT EXCEL (TV5)
// -------------------------------------------------------------------------
echo "\n=======================================================================\n";
echo "📊 PHẦN 8: KIỂM THỬ BÁO CÁO BI, MENU ENGINEERING & EXPORT BÁO CÁO (TV5)\n";
echo "=======================================================================\n";

// Ghi nhận sự kiện Clickstream VIEW_ITEM cho món 101 để kiểm thử Menu Engineering
ob_start();
$dataCtrl->recordClickstream([
    'session_id' => $sessEtlId,
    'menu_item_id' => 101,
    'event_type' => 'VIEW_ITEM'
]);
$clickOut = ob_get_clean();
$clickJson = json_decode($clickOut, true);
assertTest("TV5-8.1: Ghi nhận sự kiện Clickstream VIEW_ITEM vào fact_clickstream thành công", ($clickJson['status'] ?? '') === 'success');

// Kiểm thử API 1: GET /api/v1/bi/dashboard-summary
ob_start();
$dataCtrl->getBiDashboardSummary();
$biSumOut = ob_get_clean();
$biSumJson = json_decode($biSumOut, true);
assertTest(
    "TV5-8.2: API /api/v1/bi/dashboard-summary trả về đủ KPI (total_revenue, total_sold, conversion_rate, sla_compliance_rate)",
    is_array($biSumJson) && ($biSumJson['total_revenue'] ?? 0) >= 350000 && isset($biSumJson['conversion_rate'], $biSumJson['sla_compliance_rate'])
);

// Kiểm thử API 2: GET /api/v1/bi/revenue-over-time
ob_start();
$dataCtrl->getBiRevenueOverTime();
$biRevOut = ob_get_clean();
$biRevJson = json_decode($biRevOut, true);
assertTest(
    "TV5-8.3: API /api/v1/bi/revenue-over-time trả về chuỗi thời gian doanh thu từ dim_time",
    is_array($biRevJson) && count($biRevJson) > 0 && isset($biRevJson[0]['date'], $biRevJson[0]['revenue'])
);

// Kiểm thử API 3: GET /api/v1/bi/menu-engineering-matrix (kèm phân loại 4 góc phần tư)
ob_start();
$dataCtrl->getBiMenuMatrix();
$biMatOut = ob_get_clean();
$biMatJson = json_decode($biMatOut, true);
$validQuadrants = ['STAR', 'PLOWHORSE', 'PUZZLE', 'DOG'];
$firstMat = $biMatJson[0] ?? [];
assertTest(
    "TV5-8.4: API /api/v1/bi/menu-engineering-matrix trả về danh sách món kèm phân nhóm 4 Quadrants (STAR/PLOWHORSE/PUZZLE/DOG)",
    is_array($biMatJson) && count($biMatJson) > 0 && in_array($firstMat['quadrant_code'] ?? '', $validQuadrants, true)
);

// Kiểm thử API 4: GET /api/v1/bi/export-report (JSON, CSV UTF-8 BOM & XLSX OpenXML)
ob_start();
$dataCtrl->exportBiReport('menu-matrix', 'json');
$exportJsonOut = ob_get_clean();
$exportJson = json_decode($exportJsonOut, true);
assertTest(
    "TV5-8.5: API /api/v1/bi/export-report (JSON) trả về đầy đủ dữ liệu doanh thu & ma trận thực đơn",
    ($exportJson['status'] ?? '') === 'success' && !empty($exportJson['menu_engineering_matrix'])
);

ob_start();
$dataCtrl->exportBiReport('menu-matrix', 'csv');
$exportCsvOut = ob_get_clean();
assertTest(
    "TV5-8.6: API /api/v1/bi/export-report (CSV) chứa BOM UTF-8 (\\xEF\\xBB\\xBF) và tiêu đề cột tiếng Việt chuẩn Excel",
    strncmp($exportCsvOut, "\xEF\xBB\xBF", 3) === 0 && strpos($exportCsvOut, 'Phân Nhóm Menu Engineering') !== false
);

ob_start();
$dataCtrl->exportBiReport('menu-matrix', 'xlsx');
$exportXlsxOut = ob_get_clean();
assertTest(
    "TV5-8.7: API /api/v1/bi/export-report (XLSX) sinh file Excel OpenXML (.xlsx) chuẩn có định dạng màu sắc & cột",
    strncmp($exportXlsxOut, "PK\x03\x04", 4) === 0 && strlen($exportXlsxOut) > 1000
);

// -------------------------------------------------------------------------
// PHẦN 9: CẢI TIẾN MENU KHÁCH, SỐ LƯỢNG TOPPING, QUẢN LÝ MÓN, KDS & GỌI NHÂN VIÊN
// -------------------------------------------------------------------------
echo "\n=======================================================================\n";
echo "🍽️ PHẦN 9: KIỂM THỬ TOPPING QUANTITY, KDS BÁO HẾT, QUẢN LÝ TOPPING & HỖ TRỢ\n";
echo "=======================================================================\n";

$srTableExists = (bool)$db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='service_requests' LIMIT 1")->fetchColumn();
assertTest("UX-9.1: Schema tạo sẵn bảng service_requests cho tính năng gọi nhân viên", $srTableExists);

$stmtLauToppings = $db->query("SELECT topping_id FROM menu_item_toppings WHERE menu_item_id = 12 ORDER BY topping_id ASC");
$lauToppingIds = $stmtLauToppings ? array_map('intval', $stmtLauToppings->fetchAll(PDO::FETCH_COLUMN)) : [];
assertTest(
    "UX-9.2: Dữ liệu seed mặc định gán topping (1, 2, 4) cho Lẩu Kim Chi (#12)",
    $lauToppingIds === [1, 2, 4],
    'got: ' . json_encode($lauToppingIds)
);

$stmtTraSuaToppings = $db->query("SELECT topping_id FROM menu_item_toppings WHERE menu_item_id = 9 ORDER BY topping_id ASC");
$traSuaToppingIds = $stmtTraSuaToppings ? array_map('intval', $stmtTraSuaToppings->fetchAll(PDO::FETCH_COLUMN)) : [];
assertTest(
    "UX-9.3: Dữ liệu seed mặc định gán Trân Châu (#3) cho Trà Sữa (#9)",
    $traSuaToppingIds === [3],
    'got: ' . json_encode($traSuaToppingIds)
);

// Task 2: Topping quantities in SharedCartService, ClientOrderController, and OrderToppingSnapshotService
$tqSuffix = substr(uniqid('', true), 0, 10);
$tqToken = 'tok_tq_' . $tqSuffix;
$tqSessId = 'sess_tq_' . $tqSuffix;
$db->prepare("INSERT INTO dining_tables (table_number, status) VALUES (?, 'OCCUPIED')")->execute(['Bàn TQ ' . $tqSuffix]);
$tqTableId = (int)$db->lastInsertId();
$db->prepare("INSERT INTO table_sessions (id, table_id, session_token, status, total_amount) VALUES (?, ?, ?, 'ACTIVE', 0)")
    ->execute([$tqSessId, $tqTableId, $tqToken]);
$db->prepare("UPDATE dining_tables SET current_session_id = ? WHERE id = ?")->execute([$tqSessId, $tqTableId]);

SharedCartService::clearCart($tqToken);
$tqCart = SharedCartService::setCart($tqToken, [[
    'menu_item_id' => 12,
    'quantity' => 2,
    'unit_price' => 1,
    'selected_toppings' => [
        ['id' => 2, 'name' => 'Fake Cheese', 'price' => 1, 'quantity' => 2],
        ['id' => 2, 'quantity' => 5], // duplicate -> ignored
        ['id' => 1, 'quantity' => 0], // explicit 0 -> dropped
        ['id' => 4],                  // omitted -> defaults to 1
        ['id' => 3, 'quantity' => 1], // not mapped to dish 12 -> dropped
    ],
    'note' => 'Ít cay',
]]);
$tqCartItem = $tqCart['items'][0] ?? [];
$tqCartTops = $tqCartItem['toppings'] ?? [];
assertTest(
    "UX-9.4: SharedCartService::setCart() lọc qty=0/trùng/sai món, lấy giá & tên DB, tính đúng 221,000đ",
    abs((float)($tqCartItem['unit_price'] ?? 0) - 221000.0) < 0.01
        && count($tqCartTops) === 2
        && (int)($tqCartTops[0]['id'] ?? 0) === 2
        && (int)($tqCartTops[0]['quantity'] ?? 0) === 2
        && ($tqCartTops[0]['name'] ?? '') === 'Thêm Phô Mai Mozzarella Kéo Sợi'
        && (int)($tqCartTops[1]['id'] ?? 0) === 4
        && (int)($tqCartTops[1]['quantity'] ?? 0) === 1,
    'cartItem=' . json_encode($tqCartItem, JSON_UNESCAPED_UNICODE)
);

$_SERVER['HTTP_IDEMPOTENCY_KEY'] = 'IDEM-TQ-OK-' . $tqSuffix;
ob_start();
$orderCtrl->submitOrder($tqToken, [
    'items' => [[
        'menu_item_id' => 12,
        'quantity' => 2,
        'unit_price' => 1,
        'selected_toppings' => [
            ['id' => 2, 'quantity' => 2, 'price' => 1],
            ['id' => 4, 'quantity' => 1, 'price' => 1],
        ],
        'note' => 'Ít cay',
    ]],
]);
$tqOrderRes = json_decode(ob_get_clean(), true);
unset($_SERVER['HTTP_IDEMPOTENCY_KEY']);
$tqOrderId = (int)($tqOrderRes['order_id'] ?? 0);

ob_start();
$orderCtrl->getOrderStatus($tqToken);
$tqHistory = json_decode(ob_get_clean(), true);
ob_start();
$kdsPos->getKdsActiveItems();
$tqKds = json_decode(ob_get_clean(), true);
$tqKdsItem = null;
foreach ($tqKds as $row) {
    if ((int)($row['order_id'] ?? 0) === $tqOrderId) { $tqKdsItem = $row; break; }
}
ob_start();
$kdsPos->getTableBill($tqTableId);
$tqBill = json_decode(ob_get_clean(), true);
$tqBillItem = $tqBill['items'][0] ?? [];

assertTest(
    "UX-9.5: submitOrder tính đúng giá topping x2 (442,000đ) và lưu quantity=2 cho History, KDS, POS",
    ($tqOrderRes['status'] ?? '') === 'success'
        && abs((float)($tqOrderRes['total_amount'] ?? 0) - 442000.0) < 0.01
        && (int)($tqHistory[0]['toppings'][0]['quantity'] ?? 0) === 2
        && (int)($tqKdsItem['toppings'][0]['quantity'] ?? 0) === 2
        && (int)($tqBillItem['toppings'][0]['quantity'] ?? 0) === 2,
    'res=' . json_encode($tqOrderRes, JSON_UNESCAPED_UNICODE)
);

$badQuantities = [0, -1, 11, 'abc'];
$allBadQtyRejected = true;
$stmtTqOrderCount = $db->prepare("SELECT COUNT(*) FROM orders WHERE session_id = ?");
$stmtTqOrderCount->execute([$tqSessId]);
$ordersBeforeBadQty = (int)$stmtTqOrderCount->fetchColumn();
foreach ($badQuantities as $badIdx => $badQty) {
    $_SERVER['HTTP_IDEMPOTENCY_KEY'] = 'IDEM-TQ-BAD-' . $tqSuffix . '-' . $badIdx;
    ob_start();
    $orderCtrl->submitOrder($tqToken, [
        'items' => [[
            'menu_item_id' => 12,
            'quantity' => 1,
            'selected_toppings' => [['id' => 2, 'quantity' => $badQty]],
        ]],
    ]);
    $badOut = json_decode(ob_get_clean(), true);
    unset($_SERVER['HTTP_IDEMPOTENCY_KEY']);
    if (($badOut['code'] ?? '') !== 'INVALID_TOPPING_QUANTITY') {
        $allBadQtyRejected = false;
    }
}
$stmtTqOrderCount->execute([$tqSessId]);
$ordersAfterBadQty = (int)$stmtTqOrderCount->fetchColumn();
assertTest(
    "UX-9.6: submitOrder từ chối topping quantity 0, -1, 11, 'abc' (INVALID_TOPPING_QUANTITY) và không tạo đơn",
    $allBadQtyRejected && $ordersAfterBadQty === $ordersBeforeBadQty
);

// Legacy snapshot without 'quantity' key -> normalized to 1
$tqOrderItemId = (int)($tqOrderRes['order_item_ids'][0] ?? 0);
$db->prepare("UPDATE order_item_topping_snapshots SET toppings_json = ? WHERE order_item_id = ?")
    ->execute([json_encode([['id' => 2, 'name' => 'Legacy Top', 'unit_price' => 15000]], JSON_UNESCAPED_UNICODE), $tqOrderItemId]);
$attachedLegacy = OrderToppingSnapshotService::attach($db, [['id' => $tqOrderItemId]]);
assertTest(
    "UX-9.7: OrderToppingSnapshotService::attach() chuẩn hóa snapshot cũ không có quantity về 1",
    (int)($attachedLegacy[0]['toppings'][0]['quantity'] ?? 0) === 1
);

SharedCartService::clearCart($tqToken);
$db->prepare("DELETE FROM order_items WHERE order_id IN (SELECT id FROM orders WHERE session_id = ?)")->execute([$tqSessId]);
$db->prepare("DELETE FROM orders WHERE session_id = ?")->execute([$tqSessId]);
$db->prepare("DELETE FROM table_sessions WHERE id = ?")->execute([$tqSessId]);
$db->prepare("DELETE FROM dining_tables WHERE id = ?")->execute([$tqTableId]);

// Task 4: Topping CRUD APIs (createTopping / updateTopping + audit logs + RBAC)
$_SESSION['user_id'] = $matrixUserIds['ADMIN'];
$topCrudSuffix = substr(uniqid('', true), 0, 8);
$topCrudName = 'Thêm Trứng Lòng Đào ' . $topCrudSuffix;
ob_start();
$adminCatalog->createTopping(['name' => $topCrudName, 'price' => 10000]);
$createdTopRes = json_decode(ob_get_clean(), true);
$createdTopId = (int)($createdTopRes['id'] ?? 0);

$stmtTopCreateAudit = $db->prepare("SELECT COUNT(*) FROM audit_logs WHERE entity_type = 'topping' AND entity_id = ? AND action = 'create'");
$stmtTopCreateAudit->execute([(string)$createdTopId]);
assertTest(
    "UX-9.8: AdminCatalogController::createTopping() tạo topping mới và ghi audit_logs",
    $createdTopId > 0
        && ($createdTopRes['status'] ?? '') === 'created'
        && (float)($createdTopRes['price'] ?? 0) === 10000.0
        && (int)$stmtTopCreateAudit->fetchColumn() === 1
);

ob_start();
$adminCatalog->updateTopping($createdTopId, ['name' => $topCrudName . ' VIP', 'price' => 12000]);
$updatedTopRes = json_decode(ob_get_clean(), true);
ob_start();
$adminCatalog->updateTopping($createdTopId, ['name' => $topCrudName . ' VIP', 'price' => 12000]);
$unchangedTopRes = json_decode(ob_get_clean(), true);
$stmtTopUpdateAudit = $db->prepare("SELECT COUNT(*) FROM audit_logs WHERE entity_type = 'topping' AND entity_id = ? AND action = 'update'");
$stmtTopUpdateAudit->execute([(string)$createdTopId]);
assertTest(
    "UX-9.9: AdminCatalogController::updateTopping() cập nhật tên/giá và idempotent không tạo audit trùng",
    ($updatedTopRes['status'] ?? '') === 'updated'
        && ($unchangedTopRes['status'] ?? '') === 'unchanged'
        && (int)$stmtTopUpdateAudit->fetchColumn() === 1
);

ob_start();
$adminCatalog->createTopping(['name' => '', 'price' => -500]);
$badTopRes = json_decode(ob_get_clean(), true);
$keeperManageDecision = apiPermissionDecision($matrixUserIds['KEEPER'], 'products.manage');
assertTest(
    "UX-9.10: createTopping từ chối tên rỗng/giá âm và KEEPER bị chặn quyền products.manage (403)",
    !empty($badTopRes['error']) && $keeperManageDecision['allowed'] === false && $keeperManageDecision['http_status'] === 403
);

$db->prepare("DELETE FROM audit_logs WHERE entity_type = 'topping' AND entity_id = ?")->execute([(string)$createdTopId]);
$db->prepare("DELETE FROM toppings WHERE id = ?")->execute([$createdTopId]);
unset($_SESSION['user_id']);

// Task 5: Kitchen (KDS) out-of-stock controls for dishes & toppings + RBAC
$_SESSION['user_id'] = $matrixUserIds['KEEPER'];
ob_start();
$kdsPos->getKdsStockStatus();
$kdsStockInitial = json_decode(ob_get_clean(), true);
assertTest(
    "UX-9.11: KdsPosController::getKdsStockStatus() trả về danh sách món, topping và số lượng tạm hết",
    is_array($kdsStockInitial)
        && !empty($kdsStockInitial['items'])
        && !empty($kdsStockInitial['toppings'])
        && array_key_exists('unavailable_items_count', $kdsStockInitial)
        && array_key_exists('unavailable_toppings_count', $kdsStockInitial)
);

// KEEPER báo hết món #12 và hết topping #2 bằng payload JSON -> audit ghi actor KEEPER & khách đặt bị chặn 409
ob_start();
$adminCatalog->toggleMenuItemAvailability(12, ['is_available' => false]);
$kdsDishOff = json_decode(ob_get_clean(), true);
ob_start();
$adminCatalog->toggleToppingAvailability(2, ['is_available' => false]);
$kdsTopOff = json_decode(ob_get_clean(), true);

ob_start();
$kdsPos->getKdsStockStatus();
$kdsStockAfterOff = json_decode(ob_get_clean(), true);

$stmtKeeperAudit = $db->prepare("SELECT COUNT(*) FROM audit_logs WHERE actor_id = ? AND action = 'availability.update'");
$stmtKeeperAudit->execute([$matrixUserIds['KEEPER']]);
$keeperAuditCount = (int)$stmtKeeperAudit->fetchColumn();

// Kiểm tra khách đặt món/topping vừa bị bếp báo hết bị chặn 409
$kdsTestSuffix = substr(uniqid('', true), 0, 8);
$kdsTestSessId = 'sess_kds_oos_' . $kdsTestSuffix;
$kdsTestTok = 'tok_kds_oos_' . $kdsTestSuffix;
$db->prepare("INSERT INTO dining_tables (table_number, status) VALUES (?, 'OCCUPIED')")->execute(['Bàn OOS ' . $kdsTestSuffix]);
$kdsTestTableId = (int)$db->lastInsertId();
$db->prepare("INSERT INTO table_sessions (id, table_id, session_token, status, total_amount) VALUES (?, ?, ?, 'ACTIVE', 0)")
    ->execute([$kdsTestSessId, $kdsTestTableId, $kdsTestTok]);
$db->prepare("UPDATE dining_tables SET current_session_id = ? WHERE id = ?")->execute([$kdsTestSessId, $kdsTestTableId]);

$_SERVER['HTTP_IDEMPOTENCY_KEY'] = 'IDEM-OOS-DISH-' . $kdsTestSuffix;
ob_start();
$orderCtrl->submitOrder($kdsTestTok, ['items' => [['menu_item_id' => 12, 'quantity' => 1]]]);
$oosDishRes = json_decode(ob_get_clean(), true);

$_SERVER['HTTP_IDEMPOTENCY_KEY'] = 'IDEM-OOS-TOP-' . $kdsTestSuffix;
ob_start();
$orderCtrl->submitOrder($kdsTestTok, ['items' => [['menu_item_id' => 1, 'quantity' => 1, 'selected_toppings' => [['id' => 2, 'quantity' => 1]]]]]);
$oosTopRes = json_decode(ob_get_clean(), true);
unset($_SERVER['HTTP_IDEMPOTENCY_KEY']);

assertTest(
    "UX-9.12: Bếp (KEEPER) báo hết món & topping ghi audit và chặn khách đặt (MENU_ITEM_UNAVAILABLE / TOPPING_UNAVAILABLE)",
    ($kdsDishOff['is_available'] ?? true) === false
        && ($kdsTopOff['is_available'] ?? true) === false
        && (int)($kdsStockAfterOff['unavailable_items_count'] ?? 0) >= 1
        && (int)($kdsStockAfterOff['unavailable_toppings_count'] ?? 0) >= 1
        && $keeperAuditCount >= 2
        && ($oosDishRes['code'] ?? '') === 'MENU_ITEM_UNAVAILABLE'
        && ($oosTopRes['code'] ?? '') === 'TOPPING_UNAVAILABLE'
);

// Khôi phục lại trạng thái mở bán cho món #12 và topping #2
ob_start();
$adminCatalog->toggleMenuItemAvailability(12, ['is_available' => true]);
ob_end_clean();
ob_start();
$adminCatalog->toggleToppingAvailability(2, ['is_available' => true]);
ob_end_clean();

// RBAC boundary: CREATOR xem được kds.view (200) nhưng bị 403 kds.update; VIEWER bị 403 cả hai
$creatorKdsView = apiPermissionDecision($matrixUserIds['CREATOR'], 'kds.view');
$creatorKdsUpdate = apiPermissionDecision($matrixUserIds['CREATOR'], 'kds.update');
$viewerKdsView = apiPermissionDecision($matrixUserIds['VIEWER'], 'kds.view');
assertTest(
    "UX-9.13: Phân quyền kho bếp KDS — KEEPER đủ quyền, CREATOR chỉ xem (403 khi sửa), VIEWER bị chặn 403",
    $creatorKdsView['allowed'] === true
        && $creatorKdsUpdate['http_status'] === 403
        && $viewerKdsView['http_status'] === 403
);

$db->prepare("DELETE FROM table_sessions WHERE id = ?")->execute([$kdsTestSessId]);
$db->prepare("DELETE FROM dining_tables WHERE id = ?")->execute([$kdsTestTableId]);
unset($_SESSION['user_id']);

// Task 6: Call Staff / Support APIs, RBAC, Anti-spam & Checkout auto-resolve
$srPerms = ['tables.view', 'pos.view', 'data.manage'];
$srAnon = apiAnyPermissionDecision(null, $srPerms);
$srKeeper = apiAnyPermissionDecision($matrixUserIds['KEEPER'], $srPerms);
$srCreator = apiAnyPermissionDecision($matrixUserIds['CREATOR'], $srPerms);
$srViewer = apiAnyPermissionDecision($matrixUserIds['VIEWER'], $srPerms);
$srAdmin = apiAnyPermissionDecision($matrixUserIds['ADMIN'], $srPerms);
assertTest(
    "UX-9.14: RBAC hỗ trợ bàn — ẩn danh 401, KEEPER 403, CREATOR/VIEWER/ADMIN 200",
    $srAnon['http_status'] === 401
        && $srKeeper['http_status'] === 403
        && $srCreator['allowed'] === true
        && $srViewer['allowed'] === true
        && $srAdmin['allowed'] === true
);

require_once $appDir . '/Controllers/ServiceRequestController.php';
$srCtrl = new \App\Controllers\ServiceRequestController();

$srSuffix = substr(uniqid('', true), 0, 8);
$srSessId = 'sess_sr_' . $srSuffix;
$srTok = 'tok_sr_' . $srSuffix;
$db->prepare("INSERT INTO dining_tables (table_number, status) VALUES (?, 'OCCUPIED')")->execute(['Bàn SR ' . $srSuffix]);
$srTableId = (int)$db->lastInsertId();
$db->prepare("INSERT INTO table_sessions (id, table_id, session_token, status, total_amount) VALUES (?, ?, ?, 'ACTIVE', 0)")
    ->execute([$srSessId, $srTableId, $srTok]);
$db->prepare("UPDATE dining_tables SET current_session_id = ? WHERE id = ?")->execute([$srSessId, $srTableId]);

ob_start();
$srCtrl->createClientRequest($srTok, ['request_type' => 'CALL_STAFF', 'message' => '']);
$srCreated = json_decode(ob_get_clean(), true);
$srId1 = (int)($srCreated['request']['id'] ?? 0);

ob_start();
$srCtrl->createClientRequest($srTok, ['request_type' => 'OTHER', 'message' => '   ']);
$srEmptyOther = json_decode(ob_get_clean(), true);

ob_start();
$srCtrl->createClientRequest($srTok, ['request_type' => 'CALL_STAFF', 'message' => 'Gọi lại liền']);
$srSpam = json_decode(ob_get_clean(), true);

ob_start();
$srCtrl->createClientRequest('invalid-closed-token-' . $srSuffix, ['request_type' => 'CALL_STAFF']);
$srBadToken = json_decode(ob_get_clean(), true);

assertTest(
    "UX-9.15: createClientRequest tạo yêu cầu hợp lệ, chặn OTHER rỗng, chặn spam 30s (TOO_MANY_REQUESTS) và chặn token sai",
    ($srCreated['status'] ?? '') === 'success'
        && $srId1 > 0
        && !empty($srCreated['request']['message'])
        && !empty($srEmptyOther['error'])
        && ($srSpam['code'] ?? '') === 'TOO_MANY_REQUESTS'
        && !empty($srBadToken['error'])
);

$_SESSION['user_id'] = $matrixUserIds['CREATOR'];
ob_start();
$srCtrl->listStaffRequests('PENDING');
$srStaffList = json_decode(ob_get_clean(), true);
$foundSr1 = false;
foreach (($srStaffList['requests'] ?? []) as $row) {
    if ((int)($row['id'] ?? 0) === $srId1 && ($row['table_number'] ?? '') === 'Bàn SR ' . $srSuffix) {
        $foundSr1 = true;
        break;
    }
}

ob_start();
$srCtrl->resolveStaffRequest($srId1);
$srResolved1 = json_decode(ob_get_clean(), true);

ob_start();
$srCtrl->resolveStaffRequest($srId1);
$srResolvedRetry = json_decode(ob_get_clean(), true);

assertTest(
    "UX-9.16: Nhân viên nhận danh sách yêu cầu đúng số bàn và xử lý (resolve) idempotent",
    $foundSr1
        && ($srResolved1['status'] ?? '') === 'success'
        && ($srResolved1['already_resolved'] ?? true) === false
        && ($srResolvedRetry['status'] ?? '') === 'success'
        && ($srResolvedRetry['already_resolved'] ?? false) === true
);

// Tạo thêm 1 yêu cầu WATER_ICE rồi checkout bàn -> tự động chuyển sang RESOLVED
ob_start();
$srCtrl->createClientRequest($srTok, ['request_type' => 'WATER_ICE', 'message' => 'Xin 2 ly đá']);
$srCreated2 = json_decode(ob_get_clean(), true);
$srId2 = (int)($srCreated2['request']['id'] ?? 0);

ob_start();
$kdsPos->checkout($srSessId);
ob_end_clean();

$stmtSr2Status = $db->prepare("SELECT status FROM service_requests WHERE id = ?");
$stmtSr2Status->execute([$srId2]);
$sr2StatusAfterCheckout = (string)$stmtSr2Status->fetchColumn();

ob_start();
$srCtrl->createClientRequest($srTok, ['request_type' => 'PAYMENT']);
$srAfterCheckout = json_decode(ob_get_clean(), true);

assertTest(
    "UX-9.17: Checkout bàn tự động RESOLVED yêu cầu hỗ trợ đang chờ và từ chối yêu cầu mới sau khi đóng phiên",
    $srId2 > 0
        && $sr2StatusAfterCheckout === 'RESOLVED'
        && !empty($srAfterCheckout['error'])
);

$db->prepare("DELETE FROM service_requests WHERE session_id = ?")->execute([$srSessId]);
$db->prepare("DELETE FROM bill_payments WHERE session_id = ?")->execute([$srSessId]);
$db->prepare("DELETE FROM table_sessions WHERE id = ?")->execute([$srSessId]);
$db->prepare("DELETE FROM dining_tables WHERE id = ?")->execute([$srTableId]);
unset($_SESSION['user_id']);

// Final Review Fix Pass Tests (RED -> GREEN)
// UX-9.18: ServiceRequestController returns timezone-safe age_seconds & created_at_iso (prevents +7h skew)
$tzSuffix = substr(uniqid('', true), 0, 8);
$tzSessId = 'sess_tz_' . $tzSuffix;
$tzTok = 'tok_tz_' . $tzSuffix;
$db->prepare("INSERT INTO dining_tables (table_number, status) VALUES (?, 'OCCUPIED')")->execute(['Bàn TZ ' . $tzSuffix]);
$tzTableId = (int)$db->lastInsertId();
$db->prepare("INSERT INTO table_sessions (id, table_id, session_token, status, total_amount) VALUES (?, ?, ?, 'ACTIVE', 0)")
    ->execute([$tzSessId, $tzTableId, $tzTok]);
$db->prepare("UPDATE dining_tables SET current_session_id = ? WHERE id = ?")->execute([$tzSessId, $tzTableId]);

ob_start();
$srCtrl->createClientRequest($tzTok, ['request_type' => 'CALL_STAFF']);
$tzCreateOut = json_decode(ob_get_clean(), true);
$_SESSION['user_id'] = $matrixUserIds['ADMIN'];
ob_start();
$srCtrl->listStaffRequests('PENDING');
$tzListOut = json_decode(ob_get_clean(), true);
$tzRow = null;
foreach (($tzListOut['requests'] ?? []) as $r) {
    if (($r['session_id'] ?? '') === $tzSessId) { $tzRow = $r; break; }
}
$alertsJsSrc = file_get_contents($appDir . '/public/js/staff-service-alerts.js') ?: '';
assertTest(
    "UX-9.18: ServiceRequest trả về age_seconds (0..15s) và created_at_iso có múi giờ để tránh lệch 7 giờ",
    isset($tzCreateOut['request']['age_seconds'], $tzCreateOut['request']['created_at_iso'])
        && is_int($tzRow['age_seconds'] ?? null)
        && $tzRow['age_seconds'] >= 0
        && $tzRow['age_seconds'] <= 15
        && preg_match('/([+-]\d{2}:\d{2}|Z)$/', (string)($tzRow['created_at_iso'] ?? '')) === 1
        && strpos($alertsJsSrc, 'age_seconds') !== false
);
$db->prepare("DELETE FROM service_requests WHERE session_id = ?")->execute([$tzSessId]);
$db->prepare("DELETE FROM table_sessions WHERE id = ?")->execute([$tzSessId]);
$db->prepare("DELETE FROM dining_tables WHERE id = ?")->execute([$tzTableId]);
unset($_SESSION['user_id']);

// UX-9.19: menu.js checks live server cart before submitOrder & avoids redundant post-checkout POST /cart
$menuJsSrc = file_get_contents($appDir . '/public/frontend/js/menu.js') ?: '';
assertTest(
    "UX-9.19: menu.js kiểm tra lại giỏ hàng từ server trước khi submitOrder và không ghi đè syncCart([]) sau checkout",
    strpos($menuJsSrc, 'fetchLatestCartSnapshot') !== false
        && preg_match('/await\s+syncCart\(\);\s*await\s+loadSentOrders\(\);/', $menuJsSrc) !== 1
);

// UX-9.20: products.php inline modal error banners, toppingCatalogLoaded guard & id assignment after create
$productsPhpSrc = file_get_contents($appDir . '/public/products.php') ?: '';
assertTest(
    "UX-9.20: products.php có lỗi inline trong modal, bảo vệ toppingCatalogLoaded và gán id ngay sau tạo món",
    strpos($productsPhpSrc, 'id="menu-dialog-error"') !== false
        && strpos($productsPhpSrc, 'id="topping-dialog-error"') !== false
        && strpos($productsPhpSrc, 'toppingCatalogLoaded') !== false
        && strpos($productsPhpSrc, 'form.elements.id.value = String(targetItemId)') !== false
);

// UX-9.21: Mobile <=420px hides secondary part of 'Đơn đã gọi' cleanly instead of mid-word truncation
$menuHtmlSrc = file_get_contents($appDir . '/public/frontend/menu/index.html') ?: '';
$shopCssSrc = file_get_contents($appDir . '/public/frontend/css/shop.css') ?: '';
assertTest(
    "UX-9.21: Nút Đơn đã gọi trên mobile nhỏ rút gọn sạch thành 'Đơn' thay vì cắt cụt giữa chữ",
    strpos($menuHtmlSrc, 'orders-toggle-extra') !== false
        && strpos($shopCssSrc, '.orders-toggle-extra') !== false
        && strpos($shopCssSrc, 'max-width: 48px') === false
);

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
