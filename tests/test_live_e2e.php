<?php

/**
 * DINEFLOW LIVE HTTP + MYSQL E2E VERIFICATION SUITE
 *
 * Verifies the live Apache + PHP 8.2 + MySQL 8.0 Docker stack over HTTP:
 *   1. Frontend routes, assets, and legacy 302 redirects (.htaccess)
 *   2. PHP authentication, RBAC session guard, and portal page links
 *   3. Full operational lifecycle (Open table -> QR Menu -> Cart -> Order -> KDS -> POS -> ETL -> BI)
 */

require_once (is_dir(__DIR__ . '/../app') ? realpath(__DIR__ . '/../app') : '/var/www/html') . '/Core/Database.php';
require_once (is_dir(__DIR__ . '/../app') ? realpath(__DIR__ . '/../app') : '/var/www/html') . '/Services/SharedCartService.php';

use App\Core\Database;
use App\Services\SharedCartService;

$baseUrl = rtrim(getenv('DINEFLOW_BASE_URL') ?: 'http://127.0.0.1', '/');
$passCount = 0;
$failCount = 0;

function assertE2e(string $name, bool $condition, string $detail = ''): void
{
    global $passCount, $failCount;
    if ($condition) {
        $passCount++;
        echo "  [PASS] {$name}\n";
    } else {
        $failCount++;
        echo "  [FAIL] {$name}" . ($detail !== '' ? " -> {$detail}" : '') . "\n";
    }
}

function httpRequest(
    string $method,
    string $url,
    $body = null,
    array $headers = [],
    ?string $cookieFile = null
): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));

    $requestHeaders = $headers;
    if ($body !== null) {
        if (is_array($body)) {
            $payload = json_encode($body);
            $requestHeaders[] = 'Content-Type: application/json';
        } else {
            $payload = (string)$body;
        }
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    }

    if (!empty($requestHeaders)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $requestHeaders);
    }
    if ($cookieFile !== null) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    }

    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        return ['status' => 0, 'headers' => [], 'body' => '', 'json' => null, 'error' => $err];
    }

    $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $rawHeaders = substr($raw, 0, $headerSize);
    $respBody = substr($raw, $headerSize);
    $parsedHeaders = [];
    foreach (preg_split('/\r?\n/', $rawHeaders) as $line) {
        $pos = strpos($line, ':');
        if ($pos !== false) {
            $k = strtolower(trim(substr($line, 0, $pos)));
            $v = trim(substr($line, $pos + 1));
            $parsedHeaders[$k] = $v;
        }
    }

    $decoded = json_decode($respBody, true);
    return [
        'status' => $status,
        'headers' => $parsedHeaders,
        'body' => $respBody,
        'json' => is_array($decoded) ? $decoded : null,
        'error' => null,
    ];
}

echo "=======================================================================\n";
echo "DINEFLOW LIVE HTTP + MYSQL E2E VERIFICATION ({$baseUrl})\n";
echo "=======================================================================\n\n";

// -------------------------------------------------------------------------
// 1. FRONTEND ROUTES, STATIC ASSETS & LEGACY 302 REDIRECTS
// -------------------------------------------------------------------------
echo "[1/3] Frontend Routes, Static Assets & .htaccess 302 Redirects\n";

$routes200 = [
    '/demo_tester.html',
    '/frontend/admin/table-management.html',
    '/frontend/admin/menu-management.html',
    '/frontend/menu/index.html?token=tbl-01-sess',
    '/frontend/css/shop.css',
    '/frontend/css/menu.css',
    '/frontend/js/menu.js',
    '/frontend/js/qrcode.js',
    '/frontend/kds/index.html',
    '/frontend/kds/kds.js',
    '/frontend/pos/index.html',
    '/frontend/pos/pos.js',
    '/frontend/live-ops/index.html',
    '/frontend/live-ops/live-ops.js',
    '/frontend/live-ops/style.css',
];

foreach ($routes200 as $route) {
    $res = httpRequest('GET', $baseUrl . $route);
    assertE2e(
        "E2E-HTTP-200: {$route}",
        $res['status'] === 200 && strlen($res['body']) > 50,
        "HTTP {$res['status']}"
    );
}

$legacyRedirects = [
    '/tv4_data_pipeline.html' => '/frontend/live-ops/',
    '/frontend/tv4_data_pipeline.html' => '/frontend/live-ops/',
    '/frontend/tv1/table-management.html' => '/frontend/admin/table-management.html',
    '/frontend/kds.html' => '/frontend/kds/',
    '/frontend/pos.html' => '/frontend/pos/',
];

foreach ($legacyRedirects as $oldPath => $expectedSuffix) {
    $res = httpRequest('GET', $baseUrl . $oldPath);
    $loc = $res['headers']['location'] ?? '';
    assertE2e(
        "E2E-302: {$oldPath} -> {$expectedSuffix}",
        $res['status'] === 302 && str_ends_with($loc, $expectedSuffix),
        "HTTP {$res['status']}, Location='{$loc}'"
    );
}

echo "\n";

// -------------------------------------------------------------------------
// 2. PHP AUTHENTICATION, RBAC GUARD & PORTAL LINKS OVER HTTP SESSION
// -------------------------------------------------------------------------
echo "[2/3] PHP Auth Guard, Login Session & Staff Portal Links\n";

$cookieFile = tempnam(sys_get_temp_dir(), 'dineflow_e2e_cookie_');
$syntheticTableId = null;
$syntheticSessionId = null;
$syntheticSessionToken = null;

try {
    $protectedPages = [
        '/portal.php',
        '/dashboard.php',
        '/products.php',
        '/documents.php',
        '/frontend/admin/table-management.php',
        '/frontend/kds/index.php',
        '/frontend/pos/index.php',
        '/frontend/live-ops/index.php',
    ];
    foreach ($protectedPages as $protected) {
        $unauth = httpRequest('GET', $baseUrl . $protected);
        $loc = $unauth['headers']['location'] ?? '';
        assertE2e(
            "E2E-AUTH-GUARD: Unauthenticated {$protected} redirects to login.php",
            $unauth['status'] === 302 && str_contains($loc, 'login.php'),
            "HTTP {$unauth['status']}, Location='{$loc}'"
        );
    }

    foreach (['/api/v1/admin/tables', '/api/v1/bi/export-report?type=menu-matrix&format=xlsx'] as $protectedApi) {
        $unauthApi = httpRequest('GET', $baseUrl . $protectedApi);
        assertE2e(
            "E2E-API-AUTH: Unauthenticated {$protectedApi} rejected with 401",
            $unauthApi['status'] === 401,
            "HTTP {$unauthApi['status']}"
        );
    }

    foreach (['/login.php', '/register.php'] as $publicPhp) {
        $pub = httpRequest('GET', $baseUrl . $publicPhp);
        $hasPhpErr = preg_match('/Fatal error|Warning:|Notice:|Parse error/i', $pub['body']) === 1;
        assertE2e(
            "E2E-PHP-PUBLIC: {$publicPhp} loads HTTP 200 without PHP errors",
            $pub['status'] === 200 && !$hasPhpErr,
            "HTTP {$pub['status']}"
        );
    }

    $loginRes = httpRequest(
        'POST',
        $baseUrl . '/login.php',
        http_build_query(['username_or_email' => 'admin', 'password' => 'Admin@123']),
        ['Content-Type: application/x-www-form-urlencoded'],
        $cookieFile
    );
    $loginLoc = $loginRes['headers']['location'] ?? '';
    assertE2e(
        'E2E-LOGIN: POST /login.php (admin) succeeds and redirects to portal.php?tab=dashboard',
        $loginRes['status'] === 302 && str_contains($loginLoc, 'portal.php') && str_contains($loginLoc, 'tab=dashboard'),
        "HTTP {$loginRes['status']}, Location='{$loginLoc}'"
    );

    $portalRes = httpRequest('GET', $baseUrl . '/portal.php', null, [], $cookieFile);
    $portalErr = preg_match('/Fatal error|Warning:|Notice:|Parse error/i', $portalRes['body']) === 1;
    assertE2e(
        'E2E-PORTAL-SHELL: Authenticated /portal.php renders smooth tabbed shell for all roles',
        $portalRes['status'] === 200
            && !$portalErr
            && str_contains($portalRes['body'], 'data-tab="tables"')
            && str_contains($portalRes['body'], 'data-tab="products"')
            && str_contains($portalRes['body'], 'data-tab="kds"')
            && str_contains($portalRes['body'], 'data-tab="pos"')
            && str_contains($portalRes['body'], 'data-tab="documents"')
            && str_contains($portalRes['body'], 'data-tab="live-ops"')
            && str_contains($portalRes['body'], 'data-tab="dashboard"')
            && str_contains($portalRes['body'], 'embed=1'),
        "HTTP {$portalRes['status']}"
    );

    $embedRes = httpRequest('GET', $baseUrl . '/products.php?embed=1', null, [], $cookieFile);
    assertE2e(
        'E2E-PORTAL-EMBED: Embedded child page hides duplicate nav header',
        $embedRes['status'] === 200 && !str_contains($embedRes['body'], '<header class="staff-portal-nav">'),
        "HTTP {$embedRes['status']}"
    );

    $prodAjaxRes = httpRequest('GET', $baseUrl . '/products.php?ajax=table&page=1', null, [], $cookieFile);
    assertE2e(
        'E2E-AJAX-PRODUCTS: GET /products.php?ajax=table returns paginated JSON cleanly',
        $prodAjaxRes['status'] === 200
            && is_array($prodAjaxRes['json'])
            && isset($prodAjaxRes['json']['items'], $prodAjaxRes['json']['pagination'], $prodAjaxRes['json']['counts']),
        "HTTP {$prodAjaxRes['status']}"
    );

    $docAjaxRes = httpRequest('GET', $baseUrl . '/documents.php?ajax=bills', null, [], $cookieFile);
    assertE2e(
        'E2E-AJAX-DOCUMENTS: GET /documents.php?ajax=bills returns bill list JSON cleanly',
        $docAjaxRes['status'] === 200 && is_array($docAjaxRes['json']['bills'] ?? null),
        "HTTP {$docAjaxRes['status']}"
    );

    $dashRes = httpRequest('GET', $baseUrl . '/dashboard.php', null, [], $cookieFile);
    $dashErr = preg_match('/Fatal error|Warning:|Notice:|Parse error/i', $dashRes['body']) === 1;
    assertE2e(
        'E2E-DASHBOARD: Authenticated /dashboard.php renders PR#14 + TV5 links cleanly',
        $dashRes['status'] === 200
            && !$dashErr
            && str_contains($dashRes['body'], '/frontend/live-ops/index.php')
            && str_contains($dashRes['body'], '/frontend/admin/table-management.php')
            && str_contains($dashRes['body'], '/frontend/kds/index.php')
            && str_contains($dashRes['body'], '/frontend/pos/index.php')
            && str_contains($dashRes['body'], '/api/v1/bi/export-report')
            && !str_contains($dashRes['body'], 'tv4_data_pipeline.html')
            && !str_contains($dashRes['body'], '/frontend/tv1/')
            && !str_contains($dashRes['body'], '/frontend/kds.html'),
        "HTTP {$dashRes['status']}"
    );

    $staffPortals = [
        '/products.php',
        '/documents.php',
        '/frontend/admin/table-management.php',
        '/frontend/kds/index.php',
        '/frontend/pos/index.php',
        '/frontend/live-ops/index.php',
    ];
    foreach ($staffPortals as $staffPage) {
        $pageRes = httpRequest('GET', $baseUrl . $staffPage, null, [], $cookieFile);
        $hasErr = preg_match('/Fatal error|Warning:|Notice:|Parse error/i', $pageRes['body']) === 1;
        assertE2e(
            "E2E-PORTAL: Authenticated {$staffPage} loads cleanly & links /frontend/live-ops/index.php",
            $pageRes['status'] === 200
                && !$hasErr
                && str_contains($pageRes['body'], '/frontend/live-ops/index.php')
                && !str_contains($pageRes['body'], 'tv4_data_pipeline.html'),
            "HTTP {$pageRes['status']}"
        );
    }

    echo "\n";

    // ---------------------------------------------------------------------
    // 3. LIVE MYSQL END-TO-END RESTAURANT FLOW OVER REST API
    // ---------------------------------------------------------------------
    echo "[3/3] Live MySQL Full Operational Lifecycle over REST API\n";

    $health = httpRequest('GET', $baseUrl . '/api/v1/health');
    assertE2e(
        'E2E-API-HEALTH: GET /api/v1/health returns OK',
        $health['status'] === 200 && ($health['json']['status'] ?? '') === 'OK'
    );

    $tmpTableName = 'E2E-' . substr(uniqid(), -6);
    $createTbl = httpRequest('POST', $baseUrl . '/api/v1/admin/tables', ['table_number' => $tmpTableName], [], $cookieFile);
    $syntheticTableId = (int)($createTbl['json']['id'] ?? 0);
    assertE2e(
        'E2E-API-TABLE: POST /api/v1/admin/tables creates test table',
        $createTbl['status'] === 200 && $syntheticTableId > 0,
        "HTTP {$createTbl['status']}"
    );

    $openRes = httpRequest('POST', $baseUrl . "/api/v1/admin/tables/{$syntheticTableId}/open-session", null, [], $cookieFile);
    $syntheticSessionId = $openRes['json']['session_id'] ?? null;
    $syntheticSessionToken = $openRes['json']['session_token'] ?? null;
    $qrUrl = $openRes['json']['qr_url'] ?? '';
    assertE2e(
        'E2E-API-OPEN: Open session returns token and /frontend/menu/index.html?token=...',
        $openRes['status'] === 200
            && !empty($syntheticSessionId)
            && !empty($syntheticSessionToken)
            && $qrUrl === "/frontend/menu/index.html?token={$syntheticSessionToken}",
        "HTTP {$openRes['status']}, qr_url='{$qrUrl}'"
    );

    $doubleOpen = httpRequest('POST', $baseUrl . "/api/v1/admin/tables/{$syntheticTableId}/open-session", null, [], $cookieFile);
    assertE2e(
        'E2E-INV6: Duplicate open-session on OCCUPIED table blocked with 409',
        $doubleOpen['status'] === 409,
        "HTTP {$doubleOpen['status']}"
    );

    $sessInfo = httpRequest('GET', $baseUrl . "/api/v1/client/session/{$syntheticSessionToken}");
    assertE2e(
        'E2E-CLIENT-SESSION: GET /api/v1/client/session/{token} returns ACTIVE session',
        $sessInfo['status'] === 200 && ($sessInfo['json']['status'] ?? '') === 'ACTIVE'
    );

    $catalog = httpRequest('GET', $baseUrl . '/api/v1/admin/menu-catalog', null, [], $cookieFile);
    $firstDish = $catalog['json']['categories'][0]['items'][0] ?? null;
    $dishId = (int)($firstDish['id'] ?? 1);
    $realPrice = (float)($firstDish['price'] ?? 65000);
    assertE2e(
        'E2E-CATALOG: GET /api/v1/admin/menu-catalog returns categories & priced items',
        $catalog['status'] === 200 && $dishId > 0 && $realPrice > 0
    );

    $clickRes = httpRequest('POST', $baseUrl . '/api/v1/data/clickstream', [
        'session_id' => $syntheticSessionId,
        'menu_item_id' => $dishId,
        'event_type' => 'VIEW_ITEM',
    ]);
    assertE2e(
        'E2E-CLICKSTREAM: POST /api/v1/data/clickstream records event to OLAP',
        $clickRes['status'] === 200 && ($clickRes['json']['recorded'] ?? false) === true
    );

    $recRes = httpRequest('GET', $baseUrl . "/api/v1/data/recommendations/{$dishId}");
    assertE2e(
        'E2E-RECOMMENDATIONS: GET /api/v1/data/recommendations/{id} responds cleanly',
        $recRes['status'] === 200 && isset($recRes['json']['recommendations']) && is_array($recRes['json']['recommendations'])
    );

    $cartPost = httpRequest('POST', $baseUrl . "/api/v1/client/session/{$syntheticSessionToken}/cart", [
        'items' => [['menu_item_id' => $dishId, 'price' => 1, 'quantity' => 2]],
    ]);
    $cartGet = httpRequest('GET', $baseUrl . "/api/v1/client/session/{$syntheticSessionToken}/cart");
    $expectedSubtotal = $realPrice * 2;
    assertE2e(
        'E2E-INV1-CART: Shared Cart ignores tampered price=1 & enforces DB price',
        $cartPost['status'] === 200
            && $cartGet['status'] === 200
            && abs((float)($cartGet['json']['total_amount'] ?? 0) - $expectedSubtotal) < 0.01,
        'total=' . ($cartGet['json']['total_amount'] ?? 'null')
    );

    $idemKey = 'E2E-IDEM-' . uniqid();
    $orderRes = httpRequest(
        'POST',
        $baseUrl . "/api/v1/client/session/{$syntheticSessionToken}/submit-order",
        ['items' => [['menu_item_id' => $dishId, 'unit_price' => 1, 'quantity' => 2]]],
        ["Idempotency-Key: {$idemKey}"]
    );
    $orderItemId = (int)($orderRes['json']['order_item_ids'][0] ?? 0);
    $cartAfterSubmit = httpRequest('GET', $baseUrl . "/api/v1/client/session/{$syntheticSessionToken}/cart");
    assertE2e(
        'E2E-INV1-ORDER: submit-order enforces server total & clears shared cart',
        $orderRes['status'] === 200
            && ($orderRes['json']['status'] ?? '') === 'success'
            && $orderItemId > 0
            && abs((float)($orderRes['json']['total_amount'] ?? 0) - $expectedSubtotal) < 0.01
            && empty($cartAfterSubmit['json']['items']),
        "HTTP {$orderRes['status']}"
    );

    $dupOrder = httpRequest(
        'POST',
        $baseUrl . "/api/v1/client/session/{$syntheticSessionToken}/submit-order",
        ['items' => [['menu_item_id' => $dishId, 'quantity' => 2]]],
        ["Idempotency-Key: {$idemKey}"]
    );
    assertE2e(
        'E2E-INV2-IDEMPOTENCY: Reused Idempotency-Key rejected with 409 Conflict',
        $dupOrder['status'] === 409,
        "HTTP {$dupOrder['status']}"
    );

    $kdsList = httpRequest('GET', $baseUrl . '/api/v1/staff/kds/active-items', null, [], $cookieFile);
    $foundKds = false;
    if (is_array($kdsList['json'])) {
        foreach ($kdsList['json'] as $ki) {
            if ((int)($ki['id'] ?? 0) === $orderItemId) {
                $foundKds = true;
                break;
            }
        }
    }
    assertE2e('E2E-KDS-LIST: Submitted item appears in /api/v1/staff/kds/active-items', $foundKds);

    $kdsCook = httpRequest('POST', $baseUrl . '/api/v1/staff/kds/item-status', [
        'order_item_id' => $orderItemId,
        'status' => 'COOKING',
        'chef_id' => 1,
    ], [], $cookieFile);
    $kdsDone = httpRequest('POST', $baseUrl . '/api/v1/staff/kds/item-status', [
        'order_item_id' => $orderItemId,
        'status' => 'COMPLETED',
        'chef_id' => 1,
    ], [], $cookieFile);
    assertE2e(
        'E2E-KDS-TRANSITION: PENDING -> COOKING -> COMPLETED sets completed_at',
        $kdsCook['status'] === 200
            && $kdsDone['status'] === 200
            && ($kdsDone['json']['new_status'] ?? '') === 'COMPLETED'
            && !empty($kdsDone['json']['completed_at'])
    );

    $billRes = httpRequest('GET', $baseUrl . "/api/v1/staff/pos/table-bill/{$syntheticTableId}", null, [], $cookieFile);
    $expectedFinal = round(($expectedSubtotal * 1.08) / 500) * 500;
    assertE2e(
        'E2E-POS-BILL: Table bill computes VAT 8% & 500 VND rounding accurately',
        $billRes['status'] === 200
            && abs((float)($billRes['json']['subtotal'] ?? 0) - $expectedSubtotal) < 0.01
            && (int)($billRes['json']['vat_percent'] ?? 0) === 8
            && abs((float)($billRes['json']['final_amount'] ?? 0) - $expectedFinal) < 0.01,
        "HTTP {$billRes['status']}"
    );

    $checkoutRes = httpRequest('POST', $baseUrl . "/api/v1/staff/pos/checkout/{$syntheticSessionId}", [
        'payment_method' => 'CASH',
    ], [], $cookieFile);
    assertE2e(
        'E2E-POS-CHECKOUT: Checkout closes session, creates invoice & runs ETL',
        $checkoutRes['status'] === 200
            && ($checkoutRes['json']['status'] ?? '') === 'success'
            && !empty($checkoutRes['json']['invoice_code'])
            && abs((float)($checkoutRes['json']['final_amount'] ?? 0) - $expectedFinal) < 0.01,
        "HTTP {$checkoutRes['status']}"
    );

    $dupCheckout = httpRequest('POST', $baseUrl . "/api/v1/staff/pos/checkout/{$syntheticSessionId}", [
        'payment_method' => 'CASH',
    ], [], $cookieFile);
    assertE2e(
        'E2E-POS-NO-DOUBLE: Second checkout on closed session blocked with 400',
        $dupCheckout['status'] === 400,
        "HTTP {$dupCheckout['status']}"
    );

    $db = Database::getConnection();
    $stmtCheckEtl = $db->prepare("SELECT is_etl_processed FROM table_sessions WHERE id = ?");
    $stmtCheckEtl->execute([$syntheticSessionId]);
    $isEtl = (int)$stmtCheckEtl->fetchColumn();
    $stmtFactCnt = $db->prepare("SELECT COUNT(*) FROM fact_order_items WHERE session_id = ?");
    $stmtFactCnt->execute([$syntheticSessionId]);
    $factCnt = (int)$stmtFactCnt->fetchColumn();
    assertE2e(
        'E2E-ETL-VERIFY: Closed session marked is_etl_processed=1 and loaded into fact_order_items',
        $isEtl === 1 && $factCnt === 1,
        "is_etl={$isEtl}, facts={$factCnt}"
    );

    $analyticsRoutes = [
        '/api/v1/data/live-ops',
        '/api/v1/data/sla-metrics',
        '/api/v1/data/pipeline-status',
        '/api/v1/data/association-rules',
        '/api/v1/bi/dashboard-summary',
        '/api/v1/bi/revenue-over-time',
        '/api/v1/bi/menu-engineering-matrix',
    ];
    foreach ($analyticsRoutes as $ar) {
        $arRes = httpRequest('GET', $baseUrl . $ar, null, [], $cookieFile);
        assertE2e(
            "E2E-ANALYTICS: GET {$ar} returns valid JSON (200)",
            $arRes['status'] === 200 && is_array($arRes['json']),
            "HTTP {$arRes['status']}"
        );
    }

    $xlsxRes = httpRequest('GET', $baseUrl . '/api/v1/bi/export-report?type=menu-matrix&format=xlsx', null, [], $cookieFile);
    assertE2e(
        'E2E-BI-EXPORT-XLSX: Authenticated export-report returns valid OpenXML .xlsx binary',
        $xlsxRes['status'] === 200 && str_starts_with($xlsxRes['body'], "PK\x03\x04") && strlen($xlsxRes['body']) > 1000,
        "HTTP {$xlsxRes['status']}, bytes=" . strlen($xlsxRes['body'])
    );
} finally {
    if ($cookieFile && file_exists($cookieFile)) {
        @unlink($cookieFile);
    }
    if (!empty($syntheticSessionToken)) {
        SharedCartService::clearCart($syntheticSessionToken);
    }
    try {
        $db = Database::getConnection();
        if (!empty($syntheticSessionId)) {
            $db->prepare("DELETE FROM fact_clickstream WHERE session_id = ?")->execute([$syntheticSessionId]);
            $db->prepare("DELETE FROM fact_order_items WHERE session_id = ?")->execute([$syntheticSessionId]);
            $db->prepare("DELETE FROM bill_payments WHERE session_id = ?")->execute([$syntheticSessionId]);
            $db->prepare("DELETE oi FROM order_items oi JOIN orders o ON oi.order_id = o.id WHERE o.session_id = ?")->execute([$syntheticSessionId]);
            $db->prepare("DELETE FROM orders WHERE session_id = ?")->execute([$syntheticSessionId]);
            $db->prepare("UPDATE dining_tables SET current_session_id = NULL WHERE current_session_id = ?")->execute([$syntheticSessionId]);
            $db->prepare("DELETE FROM table_sessions WHERE id = ?")->execute([$syntheticSessionId]);
        }
        if (!empty($syntheticTableId)) {
            $db->prepare("DELETE FROM dining_tables WHERE id = ?")->execute([$syntheticTableId]);
        }
    } catch (\Throwable $cleanupErr) {
        fwrite(STDERR, "Cleanup warning: {$cleanupErr->getMessage()}\n");
    }
}

$total = $passCount + $failCount;
echo "\n=======================================================================\n";
echo "E2E SUMMARY: {$passCount} PASSED / {$total} TOTAL ({$failCount} FAILED)\n";
echo "=======================================================================\n";
exit($failCount > 0 ? 1 : 0);
