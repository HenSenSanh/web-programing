<?php
require_once __DIR__ . '/../../../src/auth.php';
require_once __DIR__ . '/../../../src/rbac.php';
requireLogin();
requirePermission('kds.view');
$staffPortalActive = 'kds';
$canUpdateKds = hasPermission((int)$_SESSION['user_id'], 'kds.update');
?>
<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="/css/staff-portal.css">
  <title>Màn Hình Điều Phối Bếp (KDS)</title>
  <!-- Bootstrap 5 CSS -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <!-- Bootstrap Icons -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
</head>
<body class="bg-light">
  <?php require __DIR__ . '/../../partials/staff_portal_nav.php'; ?>
  <div class="container py-4">
    <!-- Header & Chuyển chế độ KDS -->
    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 mb-4 pb-3 border-bottom">
      <div>
        <h2 class="fw-bold text-dark mb-1">
          👨‍🍳 Màn Hình Điều Phối Bếp (KDS)
        </h2>
        <div class="btn-group btn-group-sm mt-1" role="tablist" aria-label="Chế độ màn hình bếp">
          <button id="kds-mode-orders" type="button" class="btn btn-dark fw-semibold" onclick="setKdsMode('ORDERS')">
            <i class="bi bi-fire"></i> Đơn đang nấu
          </button>
          <button id="kds-mode-stock" type="button" class="btn btn-outline-danger fw-semibold" onclick="setKdsMode('STOCK')">
            <i class="bi bi-exclamation-triangle-fill"></i> Báo hết món &amp; Topping
            <span id="kds-out-of-stock-badge" class="badge rounded-pill bg-danger ms-1 d-none">0</span>
          </button>
        </div>
      </div>

      <!-- Thanh nút lọc trạng thái đơn -->
      <div id="kds-orders-filters" class="btn-group shadow-sm" role="group" aria-label="KDS Filter">
        <button id="filter-all" type="button" class="btn btn-outline-dark filter-btn active" onclick="setFilter('ALL')">
          <i class="bi bi-list-task"></i> Tất cả
        </button>
        <button id="filter-pending" type="button" class="btn btn-outline-warning filter-btn text-dark fw-semibold" onclick="setFilter('PENDING')">
          <i class="bi bi-hourglass-split"></i> Chờ nấu
        </button>
        <button id="filter-cooking" type="button" class="btn btn-outline-primary filter-btn fw-semibold" onclick="setFilter('COOKING')">
          <i class="bi bi-fire"></i> Đang nấu
        </button>
      </div>
    </div>

    <!-- Chế độ 1: Danh sách thẻ đơn đang nấu -->
    <div id="kds-orders-section">
      <div id="kds-container" class="row row-cols-1 row-cols-md-2 row-cols-lg-3 g-3">
        <!-- JS sẽ render các thẻ món ăn ở đây -->
      </div>
    </div>

    <!-- Chế độ 2: Bếp báo hết món & hết topping -->
    <div id="kds-stock-section" class="d-none">
      <div class="card shadow-sm border-0 mb-4">
        <div class="card-body">
          <div class="row g-2 align-items-center">
            <div class="col-md-7">
              <div class="input-group">
                <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                <input id="kds-stock-search" type="search" class="form-control" placeholder="Tìm nhanh tên món ăn hoặc topping..." oninput="renderKdsStockUI()">
              </div>
            </div>
            <div class="col-md-5 d-flex justify-content-md-end gap-2">
              <button id="stock-filter-all" type="button" class="btn btn-dark btn-sm fw-semibold" onclick="setStockFilter('ALL')">Tất cả</button>
              <button id="stock-filter-out" type="button" class="btn btn-outline-danger btn-sm fw-semibold" onclick="setStockFilter('OUT')">Đang tạm hết</button>
              <button type="button" class="btn btn-outline-secondary btn-sm" onclick="fetchKdsStockStatus()"><i class="bi bi-arrow-clockwise"></i></button>
            </div>
          </div>
          <?php if (!$canUpdateKds): ?>
            <div class="alert alert-info py-2 px-3 mt-3 mb-0 small">
              Bạn đang ở chế độ chỉ xem kho bếp. Chỉ tài khoản Bếp (KEEPER) hoặc Quản trị (ADMIN) mới có quyền báo hết món/topping.
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Khối Topping -->
      <div class="mb-4">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <h5 class="fw-bold text-dark mb-0"><i class="bi bi-plus-circle-dotted text-primary"></i> Kho Topping</h5>
          <span id="kds-toppings-summary" class="badge bg-secondary">0 topping</span>
        </div>
        <div id="kds-stock-toppings" class="row row-cols-1 row-cols-md-2 row-cols-lg-4 g-2"></div>
      </div>

      <!-- Khối Món ăn -->
      <div>
        <div class="d-flex justify-content-between align-items-center mb-2">
          <h5 class="fw-bold text-dark mb-0"><i class="bi bi-egg-fried text-warning"></i> Thực đơn Món ăn</h5>
          <span id="kds-dishes-summary" class="badge bg-secondary">0 món</span>
        </div>
        <div id="kds-stock-dishes" class="row row-cols-1 row-cols-md-2 row-cols-lg-3 g-3"></div>
      </div>
    </div>
  </div>

  <div id="kds-toast" class="position-fixed bottom-0 end-0 m-3 px-3 py-2 rounded-3 text-white fw-semibold small shadow d-none" style="z-index: 1200;"></div>

  <script>
    window.KDS_CAN_UPDATE = <?php echo json_encode($canUpdateKds); ?>;
  </script>
  <script src="kds.js"></script>
</body>
</html>
