<?php

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, Idempotency-Key");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../Core/Database.php';
require_once __DIR__ . '/../Controllers/AdminCatalogController.php';
require_once __DIR__ . '/../Controllers/ClientOrderController.php';
require_once __DIR__ . '/../Controllers/KdsPosController.php';
require_once __DIR__ . '/../Controllers/DataPipelineController.php';
require_once __DIR__ . '/../Services/SharedCartService.php';
require_once __DIR__ . '/../Services/AprioriService.php';
require_once __DIR__ . '/../Services/EtlService.php';

use App\Controllers\AdminCatalogController;
use App\Controllers\ClientOrderController;
use App\Controllers\KdsPosController;
use App\Controllers\DataPipelineController;

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

// Chuẩn hóa path: loại bỏ prefix nếu có
$path = preg_replace('#^/api/v1#', '', $uri);

$admin = new AdminCatalogController();
$client = new ClientOrderController();
$kdsPos = new KdsPosController();
$data = new DataPipelineController();

// 1. TV1: Admin Tables & Catalog
if ($path === '/admin/tables' && $method === 'GET') {
    $admin->getTables();
    exit;
}
if ($path === '/admin/tables' && $method === 'POST') {
    $admin->createTable();
    exit;
}
if (preg_match('#^/admin/tables/(\d+)/open-session$#', $path, $m) && $method === 'POST') {
    $admin->openSession((int)$m[1]);
    exit;
}
if ($path === '/admin/categories' && $method === 'GET') {
    $admin->getCategories();
    exit;
}
if ($path === '/admin/menu-items' && $method === 'GET') {
    $admin->getMenuItems();
    exit;
}
if ($path === '/admin/menu-items' && $method === 'POST') {
    $admin->createMenuItem();
    exit;
}

// 2. TV2: Client QR Menu & Orders
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
    $client->submitOrder($m[1]);
    exit;
}
if (preg_match('#^/client/session/([a-zA-Z0-9_-]+)/status$#', $path, $m) && $method === 'GET') {
    $client->getOrderStatus($m[1]);
    exit;
}

// 3. TV3: Staff KDS & POS
if ($path === '/staff/kds/active-items' && $method === 'GET') {
    $kdsPos->getKdsActiveItems();
    exit;
}
if ($path === '/staff/kds/item-status' && $method === 'POST') {
    $kdsPos->updateItemStatus();
    exit;
}
if ($path === '/staff/pos/active-tables' && $method === 'GET') {
    $kdsPos->getActivePosTables();
    exit;
}
if (preg_match('#^/staff/pos/table-bill/(\d+)$#', $path, $m) && $method === 'GET') {
    $kdsPos->getTableBill((int)$m[1]);
    exit;
}
if (preg_match('#^/staff/pos/checkout/([a-zA-Z0-9_-]+)$#', $path, $m) && $method === 'POST') {
    $kdsPos->checkout($m[1]);
    exit;
}

// 4. TV4: Data Pipeline & Live Ops
if ($path === '/data/clickstream' && $method === 'POST') {
    $data->recordClickstream();
    exit;
}
if (preg_match('#^/data/recommendations/(\d+)$#', $path, $m) && $method === 'GET') {
    $data->getRecommendations((int)$m[1]);
    exit;
}
if ($path === '/data/trigger-batch-etl' && $method === 'POST') {
    $data->triggerBatchEtl();
    exit;
}
if (($path === '/data/live-ops' || $path === '/data/live-operations') && $method === 'GET') {
    $data->getLiveOps();
    exit;
}
if ($path === '/data/combo-rule' && $method === 'POST') {
    $data->createComboRule();
    exit;
}
if (($path === '/data/sla-metrics' || $path === '/data/sla-analytics') && $method === 'GET') {
    $data->getSlaMetrics();
    exit;
}
if ($path === '/data/pipeline-status' && $method === 'GET') {
    $data->getPipelineStatus();
    exit;
}
if ($path === '/data/association-rules' && $method === 'GET') {
    $limit = (int)($_GET['limit'] ?? 10);
    $data->getAssociationRules($limit);
    exit;
}
if ($path === '/data/peak-hours' && $method === 'GET') {
    $data->getPeakHours();
    exit;
}

// 404 Route not found
http_response_code(404);
echo json_encode([
    'error' => 'API endpoint not found',
    'path' => $uri,
    'method' => $method
]);
