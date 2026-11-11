<?php
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/rbac.php';

requireLogin();
requirePermission('products.view');

$pdo = getDatabaseConnection();

// 1. Nhận bộ lọc
$keyword = trim($_GET['keyword'] ?? '');
$categoryId = !empty($_GET['category_id']) ? (int)$_GET['category_id'] : 0;
$perPage = 10;
$page = max(1, (int)($_GET['page'] ?? 1));

// 2. Xây dựng điều kiện lọc
$whereClauses = ["p.status = 'ACTIVE'"];
$params = [];

if ($keyword !== '') {
    $whereClauses[] = "(p.name LIKE :kw OR p.sku LIKE :kw)";
    $params['kw'] = "%{$keyword}%";
}
if ($categoryId > 0) {
    $whereClauses[] = "p.category_id = :cat_id";
    $params['cat_id'] = $categoryId;
}
$whereSql = implode(" AND ", $whereClauses);

// 3. Đếm tổng số bản ghi (Bài A)
$stmtCount = $pdo->prepare("SELECT COUNT(*) as total FROM products p WHERE {$whereSql}");
$stmtCount->execute($params);
$totalRecords = (int)($stmtCount->fetch()['total'] ?? 0);
$totalPages = max(1, (int)ceil($totalRecords / $perPage));

if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;

// 4. Lấy dữ liệu trang hiện tại (Bài A)
$stmtData = $pdo->prepare("
    SELECT p.*, c.name as category_name, u.name as unit_name,
           COALESCE(SUM(sb.quantity), 0) as current_stock
    FROM products p
    JOIN categories c ON p.category_id = c.id
    JOIN units u ON p.unit_id = u.id
    LEFT JOIN stock_balances sb ON p.id = sb.product_id
    WHERE {$whereSql}
    GROUP BY p.id
    ORDER BY p.id ASC
    LIMIT {$perPage} OFFSET {$offset}
");
$stmtData->execute($params);
$products = $stmtData->fetchAll();

// Lấy danh mục phục vụ bộ lọc
$categories = $pdo->query("SELECT * FROM categories ORDER BY display_order ASC")->fetchAll();

// Tạo query string giữ nguyên các tham số khi phân trang
function buildPageUrl(int $targetPage, string $kw, int $catId): string {
    $q = ['page' => $targetPage];
    if ($kw !== '') $q['keyword'] = $kw;
    if ($catId > 0) $q['category_id'] = $catId;
    return 'products.php?' . http_build_query($q);
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Danh sách sản phẩm & Phân trang thật</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <nav class="navbar">
        <div style="font-weight: bold; font-size: 18px;">HỆ THỐNG QUẢN LÝ KHO</div>
        <div class="nav-links">
            <a href="dashboard.php">Dashboard</a>
            <a href="products.php" class="active">Sản phẩm & Phân trang</a>
            <a href="documents.php">Phiếu kho & In</a>
            <a href="logout.php" class="btn btn-danger" style="padding: 4px 10px;">Đăng xuất</a>
        </div>
    </nav>

    <div class="main-content">
        <div class="card">
            <h2>Quản lý danh sách sản phẩm (Bài A — Phân trang thật)</h2>
            <p class="subtitle" style="text-align: left; margin-bottom: 16px;">
                Đếm bằng COUNT(*), phân trang bằng LIMIT/OFFSET trên CSDL, giữ nguyên bộ lọc qua Query String.
            </p>

            <!-- Bộ lọc -->
            <form method="GET" action="products.php" class="filter-bar" style="display: flex; gap: 12px; align-items: flex-end; flex-wrap: wrap; margin-bottom: 20px;">
                <div class="form-group" style="margin-bottom: 0; flex: 1; min-width: 200px;">
                    <label>Tìm kiếm tên hoặc SKU:</label>
                    <input type="text" name="keyword" placeholder="Nhập từ khóa..." value="<?php echo htmlspecialchars($keyword); ?>">
                </div>

                <div class="form-group" style="margin-bottom: 0; min-width: 180px;">
                    <label>Danh mục:</label>
                    <select name="category_id">
                        <option value="0">-- Tất cả danh mục --</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo $cat['id']; ?>" <?php echo $categoryId === (int)$cat['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cat['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <button type="submit" class="btn">Tìm kiếm</button>
                    <?php if ($keyword !== '' || $categoryId > 0): ?>
                        <a href="products.php" class="btn btn-secondary">Đặt lại</a>
                    <?php endif; ?>
                </div>
            </form>

            <div style="font-size: 14px; margin-bottom: 8px; color: #555;">
                Tìm thấy <strong><?php echo $totalRecords; ?></strong> sản phẩm (Hiển thị trang <?php echo $page; ?> / <?php echo $totalPages; ?>)
            </div>

            <!-- Bảng dữ liệu -->
            <table class="data-table">
                <thead>
                    <tr>
                        <th style="width: 60px;">ID</th>
                        <th>Mã SKU</th>
                        <th>Tên sản phẩm</th>
                        <th>Danh mục</th>
                        <th>Đơn vị tính</th>
                        <th>Tồn kho hiện hành</th>
                        <th>Mức tối thiểu</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($products)): ?>
                        <tr>
                            <td colspan="7" style="text-align: center; color: #888; padding: 24px;">Không tìm thấy sản phẩm nào phù hợp.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($products as $p): ?>
                            <tr>
                                <td><?php echo $p['id']; ?></td>
                                <td><strong><?php echo htmlspecialchars($p['sku']); ?></strong></td>
                                <td><?php echo htmlspecialchars($p['name']); ?></td>
                                <td><?php echo htmlspecialchars($p['category_name']); ?></td>
                                <td><?php echo htmlspecialchars($p['unit_name']); ?></td>
                                <td style="font-weight: bold; color: <?php echo $p['current_stock'] < $p['min_stock'] ? '#d32f2f' : '#2e7d32'; ?>;">
                                    <?php echo $p['current_stock']; ?>
                                </td>
                                <td><?php echo $p['min_stock']; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <!-- Thanh phân trang (Bài A) -->
            <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="<?php echo buildPageUrl(1, $keyword, $categoryId); ?>">&laquo; Đầu</a>
                        <a href="<?php echo buildPageUrl($page - 1, $keyword, $categoryId); ?>">&lsaquo; Trước</a>
                    <?php endif; ?>

                    <?php
                    $startP = max(1, $page - 2);
                    $endP = min($totalPages, $page + 2);
                    for ($p = $startP; $p <= $endP; $p++):
                    ?>
                        <?php if ($p === $page): ?>
                            <span class="current"><?php echo $p; ?></span>
                        <?php else: ?>
                            <a href="<?php echo buildPageUrl($p, $keyword, $categoryId); ?>"><?php echo $p; ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>

                    <?php if ($page < $totalPages): ?>
                        <a href="<?php echo buildPageUrl($page + 1, $keyword, $categoryId); ?>">Sau &rsaquo;</a>
                        <a href="<?php echo buildPageUrl($totalPages, $keyword, $categoryId); ?>">Cuối &raquo;</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
