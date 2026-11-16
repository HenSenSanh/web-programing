<?php
require_once __DIR__ . '/../../../src/auth.php';
require_once __DIR__ . '/../../../src/rbac.php';
requireLogin();
requirePermission('pos.view');
$staffPortalActive = 'pos';
?>
<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="/css/staff-portal.css">
  <title>Màn hình Thu ngân POS - DineFlow</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
</head>
<body class="bg-light">
  <?php require __DIR__ . '/../../partials/staff_portal_nav.php'; ?>
  <div class="container py-5" style="max-width: 520px;">
    <div class="text-center mb-4">
      <h2 class="fw-bold text-dark"><i class="bi bi-cash-coin text-success"></i> Thu Ngân POS</h2>
      <p class="text-muted">Thanh toán hóa đơn & giải phóng bàn</p>
    </div>

    <div class="card p-4 shadow-sm border-0 rounded-3">
      <div class="mb-3">
        <label for="session-id-input" class="form-label fw-bold">Nhập Session Token hoặc Số bàn:</label>
        <div class="input-group">
          <span class="input-group-text bg-white"><i class="bi bi-upc-scan"></i></span>
          <input type="text" id="session-id-input" class="form-control form-control-lg" placeholder="Ví dụ: sess_... hoặc số 1">
        </div>
        <div class="form-text text-secondary mt-2">
          💡 Bạn có thể dán chuỗi <code>sess_...</code> từ bảng quản lý bàn, hoặc đơn giản gõ số bàn (ví dụ: <code>1</code>).
        </div>
      </div>
      <button class="btn btn-danger btn-lg w-100 fw-bold shadow-sm" onclick="handleCheckout()">
        <i class="bi bi-check2-circle"></i> Thanh Toán & Đóng Bàn
      </button>
    </div>
  </div>
  <script src="pos.js"></script>
</body>
</html>
