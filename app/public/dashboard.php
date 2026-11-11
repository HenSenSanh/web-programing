<?php
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/rbac.php';

requireLogin();

$pdo = getDatabaseConnection();
$userId = (int)$_SESSION['user_id'];

// Lấy danh sách nhóm quyền của user
$stmtRoles = $pdo->prepare("
    SELECT r.name, r.role_code
    FROM roles r
    JOIN user_roles ur ON r.id = ur.role_id
    WHERE ur.user_id = :user_id
");
$stmtRoles->execute(["user_id" => $userId]);
$userRoles = $stmtRoles->fetchAll();

// Thống kê kho
$stmtCountProd = $pdo->query("SELECT COUNT(*) as cnt FROM products WHERE status = 'ACTIVE'");
$totalProducts = (int)($stmtCountProd->fetch()['cnt'] ?? 0);

$stmtCountDocs = $pdo->query("SELECT COUNT(*) as cnt FROM inventory_documents");
$totalDocs = (int)($stmtCountDocs->fetch()['cnt'] ?? 0);

$stmtLowStock = $pdo->query("
    SELECT p.name, p.sku, COALESCE(SUM(sb.quantity), 0) as current_qty, p.min_stock
    FROM products p
    LEFT JOIN stock_balances sb ON p.id = sb.product_id
    GROUP BY p.id
    HAVING current_qty < p.min_stock
    LIMIT 5
");
$lowStockItems = $stmtLowStock->fetchAll();
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Dashboard - Quản lý Kho</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <nav class="navbar">
        <div style="font-weight: bold; font-size: 18px;">HỆ THỐNG QUẢN LÝ KHO</div>
        <div class="nav-links">
            <a href="dashboard.php" class="active">Dashboard</a>
            <a href="products.php">Sản phẩm & Phân trang</a>
            <a href="documents.php">Phiếu kho & In</a>
            <a href="logout.php" class="btn btn-danger" style="padding: 4px 10px;">Đăng xuất</a>
        </div>
    </nav>

    <div class="main-content">
        <div class="card">
            <h2>Xin chào, <strong><?php echo htmlspecialchars($_SESSION['username']); ?></strong>!</h2>
            <p style="color: #666; margin-top: 4px;">Email: <?php echo htmlspecialchars($_SESSION['email']); ?></p>
            <div style="margin-top: 10px;">
                <strong>Nhóm quyền của bạn:</strong>
                <?php if (empty($userRoles)): ?>
                    <span style="color: #888;">(Chưa được gán nhóm nào)</span>
                <?php else: ?>
                    <?php foreach ($userRoles as $r): ?>
                        <span style="background: #e3f2fd; color: #0d47a1; padding: 3px 8px; border-radius: 4px; font-size: 12px; margin-right: 6px;">
                            <?php echo htmlspecialchars($r['name']); ?> (<?php echo htmlspecialchars($r['role_code']); ?>)
                        </span>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; margin-bottom: 24px;">
            <div class="card" style="border-left: 5px solid #1976d2;">
                <h3>Tổng sản phẩm đang quản lý</h3>
                <p style="font-size: 32px; font-weight: bold; color: #1976d2; margin-top: 10px;"><?php echo $totalProducts; ?></p>
                <small style="color: #888;">Có hỗ trợ phân trang thật và tìm kiếm</small>
            </div>

            <div class="card" style="border-left: 5px solid #2e7d32;">
                <h3>Tổng chứng từ nhập / xuất</h3>
                <p style="font-size: 32px; font-weight: bold; color: #2e7d32; margin-top: 10px;"><?php echo $totalDocs; ?></p>
                <small style="color: #888;">Transaction & khóa dòng chống âm tồn</small>
            </div>

            <div class="card" style="border-left: 5px solid #d32f2f;">
                <h3>Cảnh báo tồn kho thấp</h3>
                <p style="font-size: 32px; font-weight: bold; color: #d32f2f; margin-top: 10px;"><?php echo count($lowStockItems); ?></p>
                <small style="color: #888;">Sản phẩm có tồn &lt; mức tối thiểu</small>
            </div>
        </div>

        <?php if (!empty($lowStockItems)): ?>
            <div class="card">
                <h3 style="color: #d32f2f; margin-bottom: 12px;">⚠️ Danh sách sản phẩm dưới mức tồn tối thiểu</h3>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>SKU</th>
                            <th>Tên sản phẩm</th>
                            <th>Tồn kho hiện tại</th>
                            <th>Mức tối thiểu</th>
                            <th>Tình trạng</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($lowStockItems as $item): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($item['sku']); ?></strong></td>
                                <td><?php echo htmlspecialchars($item['name']); ?></td>
                                <td style="color: #d32f2f; font-weight: bold;"><?php echo $item['current_qty']; ?></td>
                                <td><?php echo $item['min_stock']; ?></td>
                                <td><span style="color: #d32f2f; font-size: 12px;">Cần lập phiếu nhập</span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
