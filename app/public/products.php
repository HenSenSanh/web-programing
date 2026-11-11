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
           COALESCE(m.price, 50000.00) as dish_price
    FROM products p
    JOIN categories c ON p.category_id = c.id
    JOIN units u ON p.unit_id = u.id
    LEFT JOIN menu_items m ON p.id = m.id
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DineFlow - Quản Lý Thực Đơn Món Ăn (Bài A: Phân Trang Thật)</title>
    <!-- Google Fonts Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }
    </style>
</head>
<body class="bg-slate-50 text-slate-800 min-h-screen antialiased">

    <!-- Navbar -->
    <header class="bg-white border-b border-slate-200 sticky top-0 z-40 shadow-xs">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 py-3.5 flex items-center justify-between gap-4">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-indigo-600 flex items-center justify-center text-white shadow-md shadow-indigo-200 font-black text-lg">
                    🍲
                </div>
                <div>
                    <div class="flex items-center gap-2">
                        <span class="text-sm font-bold text-slate-900 tracking-tight">DINEFLOW</span>
                        <span class="text-[11px] font-semibold px-2 py-0.5 rounded-full bg-indigo-50 text-indigo-700 border border-indigo-200">
                            Thực Đơn Nhà Hàng
                        </span>
                    </div>
                    <p class="text-xs text-slate-500">Quản Lý Danh Sách Món Ăn • Bài A: Phân Trang Thật Trên Database</p>
                </div>
            </div>

            <nav class="flex items-center gap-2 text-xs font-medium">
                <a href="dashboard.php" class="px-3 py-1.5 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-700 transition">
                    Dashboard
                </a>
                <a href="products.php" class="px-3 py-1.5 rounded-lg bg-indigo-50 text-indigo-700 border border-indigo-200 font-semibold">
                    🍲 Thực Đơn (Bài A)
                </a>
                <a href="documents.php" class="px-3 py-1.5 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-700 transition">
                    🧾 Hóa Đơn & In (Bài D)
                </a>
                <a href="/frontend/tv4_data_pipeline.html" class="px-3 py-1.5 rounded-lg bg-indigo-600 hover:bg-indigo-700 text-white font-semibold transition flex items-center gap-1">
                    <span>⚡ TV4 Data Pipeline</span>
                </a>
                <a href="logout.php" class="px-3 py-1.5 rounded-lg bg-rose-50 hover:bg-rose-100 text-rose-700 border border-rose-200 transition">
                    Đăng Xuất
                </a>
            </nav>
        </div>
    </header>

    <main class="max-w-7xl mx-auto px-4 sm:px-6 py-6 space-y-6">

        <div class="bg-white rounded-xl p-5 border border-slate-200 shadow-xs">
            <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
                <div>
                    <h2 class="text-base font-bold text-slate-900">Danh Mục Thực Đơn Món Ăn Nhà Hàng</h2>
                    <p class="text-xs text-slate-500 mt-0.5">
                        Thuật toán Bài A: Đếm tổng bản ghi bằng <code class="bg-slate-100 px-1 py-0.5 rounded text-indigo-700">COUNT(*)</code>, phân trang bằng <code class="bg-slate-100 px-1 py-0.5 rounded text-indigo-700">LIMIT 10 OFFSET <?php echo $offset; ?></code>, giữ nguyên tham số lọc qua Query String.
                    </p>
                </div>
                <div class="text-xs font-medium text-slate-500">
                    Trang <strong><?php echo $page; ?></strong> / <strong><?php echo $totalPages; ?></strong> (Tổng số: <strong><?php echo $totalRecords; ?></strong> món)
                </div>
            </div>

            <!-- Bộ lọc tìm kiếm & Danh mục -->
            <form method="GET" action="products.php" class="bg-slate-50/80 p-4 rounded-xl border border-slate-200 flex flex-wrap items-end gap-3 text-xs mb-5">
                <div class="flex-1 min-w-[220px]">
                    <label class="block font-semibold text-slate-700 mb-1">Tìm kiếm theo tên món hoặc mã:</label>
                    <input type="text" name="keyword" placeholder="Nhập tên món ăn hoặc mã DISH..." value="<?php echo htmlspecialchars($keyword); ?>" class="w-full bg-white border border-slate-300 rounded-lg px-3 py-2 text-slate-800 focus:ring-2 focus:ring-indigo-500 focus:outline-hidden transition">
                </div>

                <div class="min-w-[180px]">
                    <label class="block font-semibold text-slate-700 mb-1">Danh mục món:</label>
                    <select name="category_id" class="w-full bg-white border border-slate-300 rounded-lg px-3 py-2 text-slate-800 focus:ring-2 focus:ring-indigo-500 focus:outline-hidden transition">
                        <option value="0">-- Tất cả danh mục --</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo $cat['id']; ?>" <?php echo $categoryId === (int)$cat['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cat['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="flex items-center gap-2">
                    <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white font-semibold rounded-lg transition shadow-xs flex items-center gap-1.5">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                        <span>Tìm Kiếm</span>
                    </button>
                    <?php if ($keyword !== '' || $categoryId > 0): ?>
                        <a href="products.php" class="px-3 py-2 bg-slate-200 hover:bg-slate-300 text-slate-700 font-medium rounded-lg transition">
                            Đặt lại
                        </a>
                    <?php endif; ?>
                </div>
            </form>

            <!-- Table -->
            <div class="border border-slate-200 rounded-xl overflow-hidden">
                <table class="w-full text-xs text-left">
                    <thead class="bg-slate-50 text-slate-600 font-semibold border-b border-slate-200">
                        <tr>
                            <th class="px-4 py-3 w-16">ID</th>
                            <th class="px-4 py-3 w-28">Mã Món</th>
                            <th class="px-4 py-3">Tên Món Ăn</th>
                            <th class="px-4 py-3">Danh Mục</th>
                            <th class="px-4 py-3">Đơn Vị</th>
                            <th class="px-4 py-3 text-right">Đơn Giá Niêm Yết</th>
                            <th class="px-4 py-3 text-center">Trạng Thái</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 text-slate-700">
                        <?php if (empty($products)): ?>
                            <tr>
                                <td colspan="7" class="px-4 py-8 text-center text-slate-400 italic">
                                    Không tìm thấy món ăn nào phù hợp với bộ lọc tìm kiếm.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($products as $p): ?>
                                <tr class="hover:bg-slate-50 transition">
                                    <td class="px-4 py-3 font-mono text-slate-400"><?php echo $p['id']; ?></td>
                                    <td class="px-4 py-3 font-mono font-bold text-indigo-700"><?php echo htmlspecialchars($p['sku']); ?></td>
                                    <td class="px-4 py-3 font-semibold text-slate-900"><?php echo htmlspecialchars($p['name']); ?></td>
                                    <td class="px-4 py-3">
                                        <span class="px-2 py-0.5 rounded-full text-[11px] font-medium bg-slate-100 text-slate-600 border border-slate-200">
                                            <?php echo htmlspecialchars($p['category_name']); ?>
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-slate-500"><?php echo htmlspecialchars($p['unit_name']); ?></td>
                                    <td class="px-4 py-3 text-right font-bold text-slate-900">
                                        <?php echo number_format((float)$p['dish_price'], 0, ',', '.'); ?> đ
                                    </td>
                                    <td class="px-4 py-3 text-center">
                                        <span class="px-2 py-0.5 rounded-full text-[11px] font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                            Đang phục vụ
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Phân trang (Pagination) -->
            <?php if ($totalPages > 1): ?>
                <div class="flex items-center justify-between border-t border-slate-200 pt-4 mt-4 text-xs">
                    <div class="text-slate-500">
                        Hiển thị từ <strong><?php echo $offset + 1; ?></strong> đến <strong><?php echo min($offset + $perPage, $totalRecords); ?></strong> trên tổng số <strong><?php echo $totalRecords; ?></strong> món
                    </div>

                    <div class="flex items-center gap-1.5">
                        <?php if ($page > 1): ?>
                            <a href="<?php echo buildPageUrl(1, $keyword, $categoryId); ?>" class="px-2.5 py-1.5 rounded-lg border border-slate-200 hover:bg-slate-100 text-slate-600 transition">
                                &laquo; Đầu
                            </a>
                            <a href="<?php echo buildPageUrl($page - 1, $keyword, $categoryId); ?>" class="px-2.5 py-1.5 rounded-lg border border-slate-200 hover:bg-slate-100 text-slate-600 transition">
                                &lsaquo; Trước
                            </a>
                        <?php endif; ?>

                        <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                            <?php if ($i === $page): ?>
                                <span class="px-3 py-1.5 rounded-lg bg-indigo-600 text-white font-bold">
                                    <?php echo $i; ?>
                                </span>
                            <?php else: ?>
                                <a href="<?php echo buildPageUrl($i, $keyword, $categoryId); ?>" class="px-3 py-1.5 rounded-lg border border-slate-200 hover:bg-slate-100 text-slate-700 transition">
                                    <?php echo $i; ?>
                                </a>
                            <?php endif; ?>
                        <?php endfor; ?>

                        <?php if ($page < $totalPages): ?>
                            <a href="<?php echo buildPageUrl($page + 1, $keyword, $categoryId); ?>" class="px-2.5 py-1.5 rounded-lg border border-slate-200 hover:bg-slate-100 text-slate-600 transition">
                                Sau &rsaquo;
                            </a>
                            <a href="<?php echo buildPageUrl($totalPages, $keyword, $categoryId); ?>" class="px-2.5 py-1.5 rounded-lg border border-slate-200 hover:bg-slate-100 text-slate-600 transition">
                                Cuối &raquo;
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

        </div>

    </main>

</body>
</html>
