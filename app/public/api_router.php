<?php

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, Idempotency-Key");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/rbac.php';
require_once __DIR__ . '/../Core/Database.php';
require_once __DIR__ . '/../Controllers/AdminCatalogController.php';
require_once __DIR__ . '/../Controllers/ClientOrderController.php';
require_once __DIR__ . '/../Controllers/ClientCancellationController.php';
require_once __DIR__ . '/../Controllers/KdsPosController.php';
require_once __DIR__ . '/../Controllers/DataPipelineController.php';
require_once __DIR__ . '/../Controllers/ServiceRequestController.php';
require_once __DIR__ . '/../Services/OrderToppingSnapshotService.php';
require_once __DIR__ . '/../Services/SharedCartService.php';
require_once __DIR__ . '/../Services/AprioriService.php';
require_once __DIR__ . '/../Services/EtlService.php';
require_once __DIR__ . '/../Services/RateLimiterService.php';
require_once __DIR__ . '/../Services/AuditLogService.php';

use App\Controllers\AdminCatalogController;
use App\Controllers\ClientOrderController;
use App\Controllers\ClientCancellationController;
use App\Controllers\KdsPosController;
use App\Controllers\DataPipelineController;
use App\Controllers\ServiceRequestController;
use App\Services\RateLimiterService;

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

// Chuẩn hóa path: loại bỏ prefix nếu có
$path = preg_replace('#^/api/v1#', '', $uri);

$admin = new AdminCatalogController();
$client = new ClientOrderController();
$clientCancellation = new ClientCancellationController();
$kdsPos = new KdsPosController();
$data = new DataPipelineController();
$serviceRequests = new ServiceRequestController();

// 0. Health check (kiểm tra cả kết nối DB cho Render/Docker healthcheck)
if (($path === '' || $path === '/' || $path === '/health') && $method === 'GET') {
    $dbOk = true;
    try {
        \App\Core\Database::getConnection()->query('SELECT 1');
    } catch (\Throwable $e) {
        $dbOk = false;
    }
    if (!$dbOk) {
        http_response_code(503);
    }
    echo json_encode([
        'status' => $dbOk ? 'OK' : 'DEGRADED',
        'db' => $dbOk ? 'ok' : 'error',
        'runtime' => 'PHP ' . PHP_VERSION,
        'app' => 'DineFlow',
        'timestamp' => gmdate('c'),
    ]);
    exit;
}

// 1. Admin Tables & Catalog
if ($path === '/admin/audit-logs' && $method === 'GET') {
    requireApiPermission('data.manage');
    $admin->getAuditLogs();
    exit;
}
if ($path === '/admin/qr-public-url' && $method === 'GET') {
    requireApiPermission('tables.view');
    $admin->getQrPublicUrl();
    exit;
}
if ($path === '/admin/tables' && $method === 'GET') {
    requireApiPermission('tables.view');
    $admin->getTables();
    exit;
}
if ($path === '/admin/tables' && $method === 'POST') {
    requireApiPermission('tables.manage');
    $admin->createTable();
    exit;
}
if (preg_match('#^/admin/tables/(\d+)/open-session$#', $path, $m) && $method === 'POST') {
    requireApiPermission('tables.open');
    $admin->openSession((int)$m[1]);
    exit;
}
if ($path === '/admin/categories' && $method === 'GET') {
    requireApiPermission('products.view');
    $admin->getCategories();
    exit;
}
if (($path === '/admin/menu-items' || $path === '/admin/menu') && $method === 'GET') {
    requireApiPermission('products.view');
    if (isset($_GET['grouped']) && $_GET['grouped'] === '1') {
        $admin->getMenuCatalog();
    } else {
        $admin->getMenuItems();
    }
    exit;
}
if ($path === '/admin/menu-catalog' && $method === 'GET') {
    $admin->getMenuCatalog();
    exit;
}
if ($path === '/admin/menu-items' && $method === 'POST') {
    requireApiPermission('products.manage');
    $admin->createMenuItem();
    exit;
}
if (preg_match('#^/admin/menu-items/(\d+)$#', $path, $m) && ($method === 'PATCH' || $method === 'PUT')) {
    requireApiPermission('products.manage');
    $admin->updateMenuItem((int)$m[1]);
    exit;
}
if (preg_match('#^/admin/menu-items/(\d+)/toggle-availability$#', $path, $m) && ($method === 'PATCH' || $method === 'POST')) {
    requireApiPermission('products.manage');
    $admin->toggleMenuItemAvailability((int)$m[1]);
    exit;
}
if (preg_match('#^/admin/menu-items/(\d+)/toppings$#', $path, $m) && ($method === 'PATCH' || $method === 'PUT')) {
    requireApiPermission('products.manage');
    $admin->updateMenuItemToppings((int)$m[1]);
    exit;
}
if ($path === '/admin/toppings' && $method === 'POST') {
    requireApiPermission('products.manage');
    $admin->createTopping();
    exit;
}
if (preg_match('#^/admin/toppings/(\d+)$#', $path, $m) && ($method === 'PATCH' || $method === 'PUT')) {
    requireApiPermission('products.manage');
    $admin->updateTopping((int)$m[1]);
    exit;
}
if (preg_match('#^/admin/toppings/(\d+)/toggle-availability$#', $path, $m) && ($method === 'PATCH' || $method === 'POST')) {
    requireApiPermission('products.manage');
    $admin->toggleToppingAvailability((int)$m[1]);
    exit;
}

// 2. Client QR Menu & Orders
if (preg_match('#^/client/session/([a-zA-Z0-9_-]+)$#', $path, $m) && $method === 'GET') {
    $client->getSessionInfo($m[1]);
    exit;
}
if (preg_match('#^/client/session/([a-zA-Z0-9_-]+)/cart$#', $path, $m)) {
    if ($method === 'GET') $client->getCart($m[1]);
    else if ($method === 'POST') $client->updateCart($m[1]);
    exit;
}
if (preg_match('#^/client/session/([a-zA-Z0-9_-]+)/submit-order$#', $path, $m) && $method === 'POST') {
    if (!RateLimiterService::enforce('client_submit_order', RateLimiterService::resolveClientIp() . ':' . $m[1], 15, 60)) {
        exit;
    }
    $client->submitOrder($m[1]);
    exit;
}
if (preg_match('#^/client/session/([a-zA-Z0-9_-]+)/status$#', $path, $m) && $method === 'GET') {
    $client->getOrderStatus($m[1]);
    exit;
}
if (preg_match('#^/client/session/([a-zA-Z0-9_-]+)/order-items/(\d+)/cancel$#', $path, $m) && $method === 'POST') {
    if (!RateLimiterService::enforce('client_cancel_item', RateLimiterService::resolveClientIp() . ':' . $m[1], 15, 60)) {
        exit;
    }
    $clientCancellation->cancelItem($m[1], (int)$m[2]);
    exit;
}
if (preg_match('#^/client/session/([a-zA-Z0-9_-]+)/service-requests$#', $path, $m)) {
    if ($method === 'GET') {
        $serviceRequests->listClientRequests($m[1]);
        exit;
    }
    if ($method === 'POST') {
        if (!RateLimiterService::enforce('client_service_request', RateLimiterService::resolveClientIp() . ':' . $m[1], 10, 60)) {
            exit;
        }
        $serviceRequests->createClientRequest($m[1]);
        exit;
    }
}

// 3. Staff KDS, POS & Service Support Alerts
if ($path === '/staff/service-requests' && $method === 'GET') {
    requireAnyApiPermission(['tables.view', 'pos.view', 'data.manage']);
    $serviceRequests->listStaffRequests();
    exit;
}
if (preg_match('#^/staff/service-requests/(\d+)/resolve$#', $path, $m) && ($method === 'POST' || $method === 'PATCH')) {
    requireAnyApiPermission(['tables.view', 'pos.view', 'data.manage']);
    $serviceRequests->resolveStaffRequest((int)$m[1]);
    exit;
}
if ($path === '/staff/kds/active-items' && $method === 'GET') {
    requireApiPermission('kds.view');
    $kdsPos->getKdsActiveItems();
    exit;
}
if ($path === '/staff/kds/item-status' && ($method === 'POST' || $method === 'PATCH')) {
    requireApiPermission('kds.update');
    $kdsPos->updateItemStatus();
    exit;
}
if ($path === '/staff/kds/stock-status' && $method === 'GET') {
    requireApiPermission('kds.view');
    $kdsPos->getKdsStockStatus();
    exit;
}
if (preg_match('#^/staff/kds/menu-items/(\d+)/availability$#', $path, $m) && ($method === 'PATCH' || $method === 'POST')) {
    requireApiPermission('kds.update');
    $admin->toggleMenuItemAvailability((int)$m[1]);
    exit;
}
if (preg_match('#^/staff/kds/toppings/(\d+)/availability$#', $path, $m) && ($method === 'PATCH' || $method === 'POST')) {
    requireApiPermission('kds.update');
    $admin->toggleToppingAvailability((int)$m[1]);
    exit;
}
if ($path === '/staff/pos/active-tables' && $method === 'GET') {
    requireApiPermission('pos.view');
    $kdsPos->getActivePosTables();
    exit;
}
if (preg_match('#^/staff/pos/table-bill/(\d+)$#', $path, $m) && $method === 'GET') {
    requireApiPermission('pos.view');
    $kdsPos->getTableBill((int)$m[1]);
    exit;
}
if (preg_match('#^/staff/pos/checkout/([a-zA-Z0-9_-]+)$#', $path, $m) && $method === 'POST') {
    requireApiPermission('pos.checkout');
    $kdsPos->checkout($m[1]);
    exit;
}

// 4. Data Pipeline & Live Ops
if ($path === '/data/clickstream' && $method === 'POST') {
    if (!RateLimiterService::enforce('client_clickstream', RateLimiterService::resolveClientIp(), 120, 60)) {
        exit;
    }
    $data->recordClickstream();
    exit;
}
if (preg_match('#^/data/recommendations/(\d+)$#', $path, $m) && $method === 'GET') {
    $data->getRecommendations((int)$m[1]);
    exit;
}
if ($path === '/data/trigger-batch-etl' && $method === 'POST') {
    requireApiPermission('data.manage');
    $data->triggerBatchEtl();
    exit;
}
if (($path === '/data/live-ops' || $path === '/data/live-operations') && $method === 'GET') {
    requireApiPermission('data.manage');
    $data->getLiveOps();
    exit;
}
if ($path === '/data/combo-rule' && $method === 'POST') {
    requireApiPermission('data.manage');
    $data->createComboRule();
    exit;
}
if (($path === '/data/sla-metrics' || $path === '/data/sla-analytics') && $method === 'GET') {
    requireApiPermission('reports.view');
    $data->getSlaMetrics();
    exit;
}
if ($path === '/data/pipeline-status' && $method === 'GET') {
    requireApiPermission('data.manage');
    $data->getPipelineStatus();
    exit;
}
if ($path === '/data/association-rules' && $method === 'GET') {
    requireApiPermission('reports.view');
    $limit = (int)($_GET['limit'] ?? 10);
    $data->getAssociationRules($limit);
    exit;
}
if ($path === '/data/peak-hours' && $method === 'GET') {
    requireApiPermission('reports.view');
    $data->getPeakHours();
    exit;
}

// 5. BI Analytics
if ($path === '/bi/dashboard-summary' && $method === 'GET') {
    requireApiPermission('reports.view');
    $data->getBiDashboardSummary();
    exit;
}
if ($path === '/bi/revenue-over-time' && $method === 'GET') {
    requireApiPermission('reports.view');
    $data->getBiRevenueOverTime();
    exit;
}
if ($path === '/bi/menu-engineering-matrix' && $method === 'GET') {
    requireApiPermission('reports.view');
    $data->getBiMenuMatrix();
    exit;
}
if ($path === '/bi/export-report' && $method === 'GET') {
    requireApiPermission('reports.view');
    $data->exportBiReport();
    exit;
}

// 404 Route not found
http_response_code(404);
echo json_encode([
    'error' => 'API endpoint not found',
    'path' => $uri,
    'method' => $method
]);
