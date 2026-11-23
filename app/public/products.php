<?php
if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('Content-Type: text/html; charset=UTF-8');
}
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/rbac.php';

requireLogin();
requirePermission('products.view');
$staffPortalActive = 'products';
$canManageMenu = hasPermission((int)$_SESSION['user_id'], 'products.manage');

$pdo = getDatabaseConnection();
$keyword = trim($_GET['keyword'] ?? '');
$categoryId = !empty($_GET['category_id']) ? (int)$_GET['category_id'] : 0;
$perPage = 10;
$page = max(1, (int)($_GET['page'] ?? 1));

$whereClauses = ['1 = 1'];
$params = [];
if ($keyword !== '') {
    $whereClauses[] = '(m.name LIKE :name_kw OR p.sku LIKE :sku_kw)';
    $params['name_kw'] = "%{$keyword}%";
    $params['sku_kw'] = "%{$keyword}%";
}
if ($categoryId > 0) {
    $whereClauses[] = 'm.category_id = :category_id';
    $params['category_id'] = $categoryId;
}
$whereSql = implode(' AND ', $whereClauses);

$stmtCount = $pdo->prepare("
    SELECT COUNT(*)
    FROM menu_items m
    LEFT JOIN products p ON p.id = m.id
    WHERE {$whereSql}
");
$stmtCount->execute($params);
$totalRecords = (int)$stmtCount->fetchColumn();
$totalPages = max(1, (int)ceil($totalRecords / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$stmtData = $pdo->prepare("
    SELECT m.id, m.name, m.category_id, m.description, m.price, m.is_available, m.is_hot,
           m.image_url, c.name AS category_name, c.display_order,
           p.sku, u.name AS unit_name,
           (SELECT COUNT(*) FROM menu_item_toppings mit WHERE mit.menu_item_id = m.id) AS topping_count
    FROM menu_items m
    JOIN categories c ON c.id = m.category_id
    LEFT JOIN products p ON p.id = m.id
    LEFT JOIN units u ON u.id = p.unit_id
    WHERE {$whereSql}
    ORDER BY c.display_order ASC, m.id ASC
    LIMIT {$perPage} OFFSET {$offset}
");
$stmtData->execute($params);
$menuItems = $stmtData->fetchAll();

$categories = $pdo->query('SELECT id, name, display_order FROM categories ORDER BY display_order ASC, id ASC')->fetchAll();
$catalogCounts = $pdo->query("
    SELECT COUNT(*) AS total,
           COALESCE(SUM(CASE WHEN is_available = 1 THEN 1 ELSE 0 END), 0) AS available,
           COALESCE(SUM(CASE WHEN is_available = 0 THEN 1 ELSE 0 END), 0) AS unavailable
    FROM menu_items
")->fetch();
$catalogTotal = (int)($catalogCounts['total'] ?? 0);
$availableCount = (int)($catalogCounts['available'] ?? 0);
$unavailableCount = (int)($catalogCounts['unavailable'] ?? 0);

function buildPageUrl(int $targetPage, string $kw, int $catId): string
{
    $query = ['page' => $targetPage];
    if ($kw !== '') {
        $query['keyword'] = $kw;
    }
    if ($catId > 0) {
        $query['category_id'] = $catId;
    }
    if (($_GET['embed'] ?? '') === '1') {
        $query['embed'] = '1';
    }
    return 'products.php?' . http_build_query($query);
}

if (($_GET['ajax'] ?? '') === 'table') {
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=UTF-8');
    }
    echo json_encode([
        'items' => array_map(static function (array $item): array {
            return [
                'id' => (int)$item['id'],
                'sku' => $item['sku'] ?? null,
                'name' => $item['name'],
                'category_id' => (int)$item['category_id'],
                'category_name' => $item['category_name'],
                'unit_name' => $item['unit_name'] ?? null,
                'price' => (float)$item['price'],
                'description' => $item['description'] ?? '',
                'image_url' => $item['image_url'] ?? '',
                'is_hot' => (bool)$item['is_hot'],
                'is_available' => (bool)$item['is_available'],
                'topping_count' => (int)($item['topping_count'] ?? 0),
            ];
        }, $menuItems),
        'pagination' => [
            'page' => $page,
            'totalPages' => $totalPages,
            'totalRecords' => $totalRecords,
            'perPage' => $perPage,
            'from' => $totalRecords > 0 ? $offset + 1 : 0,
            'to' => min($offset + $perPage, $totalRecords),
        ],
        'counts' => [
            'catalogTotal' => $catalogTotal,
            'availableCount' => $availableCount,
            'unavailableCount' => $unavailableCount,
        ],
        'keyword' => $keyword,
        'categoryId' => $categoryId,
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="/css/staff-portal.css">
    <title>DineFlow - Quản lý món</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { font-family: Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
        .menu-dialog {
            margin: auto;
            width: min(600px, calc(100vw - 24px));
            max-height: calc(100vh - 32px);
            overflow-y: auto;
            border: 0;
            border-radius: 16px;
            padding: 0;
            box-shadow: 0 24px 60px rgb(15 23 42 / 28%);
        }
        .menu-dialog::backdrop { background: rgb(15 23 42 / 48%); }
        .menu-dialog-inner { padding: 24px; }
        @media print { .staff-portal-nav, .no-print { display: none !important; } }
    </style>
</head>
<body class="min-h-screen bg-slate-50 text-slate-800 antialiased">
    <?php require __DIR__ . '/partials/staff_portal_nav.php'; ?>

    <main class="mx-auto max-w-7xl space-y-6 px-4 py-6 sm:px-6">
        <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="mb-5 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wider text-indigo-600">Danh mục thực đơn</p>
                    <h1 class="mt-1 text-xl font-bold text-slate-900">Quản lý món & Topping</h1>
                    <p class="mt-1 text-xs text-slate-500">Tìm món, thêm/cập nhật thông tin, trạng thái phục vụ và quản lý danh mục topping.</p>
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    <?php if ($canManageMenu): ?>
                        <button type="button" id="open-create-menu-dialog"
                                class="rounded-lg bg-emerald-600 px-4 py-2.5 text-xs font-semibold text-white shadow-xs hover:bg-emerald-700">
                            + Thêm món mới
                        </button>
                    <?php endif; ?>
                    <div id="catalog-stats-summary" class="rounded-lg bg-slate-50 px-4 py-3 text-right text-xs" aria-label="Thống kê trạng thái toàn bộ thực đơn">
                        <div class="font-semibold text-slate-800">Toàn bộ thực đơn: <?php echo $catalogTotal; ?> món</div>
                        <div class="mt-1 flex justify-end gap-3">
                            <span class="text-emerald-700">Đang bán: <?php echo $availableCount; ?></span>
                            <span class="text-rose-700">Hết món: <?php echo $unavailableCount; ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <form id="products-filter-form" method="GET" action="products.php" class="mb-5 flex flex-wrap items-end gap-3 rounded-xl border border-slate-200 bg-slate-50 p-4 text-xs">
                <div class="min-w-[220px] flex-1">
                    <label for="keyword" class="mb-1 block font-semibold text-slate-700">Tìm tên món hoặc mã</label>
                    <input id="keyword" type="search" name="keyword" value="<?php echo htmlspecialchars($keyword, ENT_QUOTES, 'UTF-8'); ?>"
                           placeholder="Nhập tên món hoặc mã DISH..." class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2">
                </div>
                <div class="min-w-[190px]">
                    <label for="category_id" class="mb-1 block font-semibold text-slate-700">Danh mục món</label>
                    <select id="category_id" name="category_id" class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2">
                        <option value="0">Tất cả danh mục</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?php echo (int)$category['id']; ?>" <?php echo $categoryId === (int)$category['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($category['name'], ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="rounded-lg bg-indigo-600 px-4 py-2 font-semibold text-white hover:bg-indigo-700">Lọc món</button>
                <a id="clear-filter-btn" href="<?php echo htmlspecialchars(buildPageUrl(1, '', 0), ENT_QUOTES, 'UTF-8'); ?>"
                   class="rounded-lg bg-slate-200 px-4 py-2 font-semibold text-slate-700 hover:bg-slate-300<?php echo ($keyword === '' && $categoryId <= 0) ? ' hidden' : ''; ?>">Xóa lọc</a>
            </form>

            <div class="mb-3 flex flex-wrap items-center justify-between gap-2 text-xs">
                <h2 class="font-bold text-slate-900">Thực đơn món ăn</h2>
                <p id="catalog-page-summary" class="text-slate-500">
                    Trang <strong><?php echo $page; ?></strong> / <strong><?php echo $totalPages; ?></strong>
                    (Tổng số: <strong><?php echo $catalogTotal; ?></strong> món
                    | Đang bán: <strong class="text-emerald-700"><?php echo $availableCount; ?></strong>
                    | Hết món: <strong class="text-rose-700"><?php echo $unavailableCount; ?></strong>)
                </p>
            </div>

            <div class="overflow-x-auto rounded-xl border border-slate-200">
                <table class="w-full min-w-[820px] text-left text-xs">
                    <thead class="bg-slate-50 text-slate-600">
                        <tr>
                            <th class="px-3 py-3">Mã</th>
                            <th class="px-3 py-3">Tên món</th>
                            <th class="px-3 py-3">Danh mục</th>
                            <th class="px-3 py-3 text-center">Topping</th>
                            <th class="px-3 py-3 text-right">Giá</th>
                            <th class="px-3 py-3 text-center">Trạng thái</th>
                            <?php if ($canManageMenu): ?><th class="px-3 py-3 text-center">Thao tác</th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody id="products-tbody" class="divide-y divide-slate-100">
                        <?php if ($menuItems === []): ?>
                            <tr><td colspan="<?php echo $canManageMenu ? 7 : 6; ?>" class="px-4 py-8 text-center text-slate-500">Không tìm thấy món phù hợp.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($menuItems as $item): ?>
                            <?php
                            $toppingCount = (int)($item['topping_count'] ?? 0);
                            $editableItem = [
                                'id' => (int)$item['id'],
                                'name' => $item['name'],
                                'category_id' => (int)$item['category_id'],
                                'price' => (float)$item['price'],
                                'description' => $item['description'] ?? '',
                                'image_url' => $item['image_url'] ?? '',
                                'is_hot' => (bool)$item['is_hot'],
                                'is_available' => (bool)$item['is_available'],
                            ];
                            $itemJson = json_encode($editableItem, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP);
                            ?>
                            <tr class="hover:bg-slate-50" data-menu-row="<?php echo (int)$item['id']; ?>">
                                <td class="whitespace-nowrap px-3 py-3 font-mono text-indigo-700"><?php echo htmlspecialchars($item['sku'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="px-3 py-2.5">
                                    <div class="flex items-center gap-3">
                                        <?php $imgSrc = trim((string)($item['image_url'] ?? '')); ?>
                                        <div class="h-11 w-11 shrink-0 overflow-hidden rounded-lg border border-slate-200 bg-slate-100">
                                            <?php if ($imgSrc !== ''): ?>
                                                <img src="<?php echo htmlspecialchars($imgSrc, ENT_QUOTES, 'UTF-8'); ?>"
                                                     alt="<?php echo htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8'); ?>"
                                                     class="h-full w-full object-cover" loading="lazy"
                                                     onerror="this.parentElement.innerHTML='<div class=&quot;flex h-full w-full items-center justify-center text-base&quot;>🍽️</div>'">
                                            <?php else: ?>
                                                <div class="flex h-full w-full items-center justify-center text-base">🍽️</div>
                                            <?php endif; ?>
                                        </div>
                                        <div>
                                            <div class="font-semibold text-slate-900"><?php echo htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8'); ?></div>
                                            <?php if ((bool)$item['is_hot']): ?><span class="mt-0.5 inline-block rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-semibold text-amber-700">Món nổi bật</span><?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-3 py-3"><?php echo htmlspecialchars($item['category_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="px-3 py-3 text-center">
                                    <?php if ($toppingCount > 0): ?>
                                        <span class="inline-flex rounded-full bg-indigo-50 px-2.5 py-0.5 text-[11px] font-semibold text-indigo-700 border border-indigo-200">
                                            <?php echo $toppingCount; ?> topping
                                        </span>
                                    <?php else: ?>
                                        <span class="text-[11px] text-slate-400">Chưa gán</span>
                                    <?php endif; ?>
                                </td>
                                <td class="whitespace-nowrap px-3 py-3 text-right font-bold"><?php echo number_format((float)$item['price'], 0, ',', '.'); ?> đ</td>
                                <td class="px-3 py-3 text-center">
                                    <?php if ($canManageMenu): ?>
                                        <label class="inline-flex cursor-pointer items-center gap-2" aria-label="Trạng thái món <?php echo htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="checkbox" class="availability-toggle h-4 w-4 accent-emerald-600"
                                                   data-item-id="<?php echo (int)$item['id']; ?>" <?php echo (bool)$item['is_available'] ? 'checked' : ''; ?>>
                                            <span class="availability-label font-semibold <?php echo (bool)$item['is_available'] ? 'text-emerald-700' : 'text-rose-700'; ?>">
                                                <?php echo (bool)$item['is_available'] ? 'Đang bán' : 'Hết món'; ?>
                                            </span>
                                        </label>
                                    <?php else: ?>
                                        <span class="font-semibold <?php echo (bool)$item['is_available'] ? 'text-emerald-700' : 'text-rose-700'; ?>">
                                            <?php echo (bool)$item['is_available'] ? 'Đang bán' : 'Hết món'; ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <?php if ($canManageMenu): ?>
                                    <td class="px-3 py-3 text-center">
                                        <button type="button" class="edit-menu-item rounded-lg border border-indigo-200 bg-indigo-50 px-3 py-1.5 font-semibold text-indigo-700 hover:bg-indigo-100"
                                                data-menu-item="<?php echo htmlspecialchars($itemJson ?: '{}', ENT_QUOTES, 'UTF-8'); ?>">
                                            Sửa món
                                        </button>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div id="products-pagination-wrap" class="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 pt-4 text-xs">
                <div class="text-slate-500">
                    Hiển thị <?php echo $totalRecords > 0 ? $offset + 1 : 0; ?>–<?php echo min($offset + $perPage, $totalRecords); ?>
                    trên <?php echo $totalRecords; ?> kết quả lọc
                </div>
                <?php if ($totalPages > 1): ?>
                    <nav class="flex flex-wrap items-center gap-1" aria-label="Phân trang thực đơn">
                        <?php if ($page > 1): ?>
                            <a class="rounded border px-2 py-1.5 hover:bg-slate-100" data-page="<?php echo $page - 1; ?>" href="<?php echo htmlspecialchars(buildPageUrl($page - 1, $keyword, $categoryId), ENT_QUOTES, 'UTF-8'); ?>">Trước</a>
                        <?php endif; ?>
                        <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                            <?php if ($i === $page): ?>
                                <span class="rounded bg-indigo-600 px-3 py-1.5 font-bold text-white" aria-current="page"><?php echo $i; ?></span>
                            <?php else: ?>
                                <a class="rounded border px-3 py-1.5 hover:bg-slate-100" data-page="<?php echo $i; ?>" href="<?php echo htmlspecialchars(buildPageUrl($i, $keyword, $categoryId), ENT_QUOTES, 'UTF-8'); ?>"><?php echo $i; ?></a>
                            <?php endif; ?>
                        <?php endfor; ?>
                        <?php if ($page < $totalPages): ?>
                            <a class="rounded border px-2 py-1.5 hover:bg-slate-100" data-page="<?php echo $page + 1; ?>" href="<?php echo htmlspecialchars(buildPageUrl($page + 1, $keyword, $categoryId), ENT_QUOTES, 'UTF-8'); ?>">Sau</a>
                        <?php endif; ?>
                    </nav>
                <?php endif; ?>
            </div>
        </section>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <!-- Khối 1: Kho Topping (Thêm / Sửa / Báo hết hàng) -->
            <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="text-base font-bold text-slate-900">Kho Topping</h2>
                        <p class="mt-1 text-xs text-slate-500">Quản lý danh mục topping dùng chung, giá bán và trạng thái còn/hết hàng.</p>
                    </div>
                    <?php if ($canManageMenu): ?>
                        <button type="button" id="open-create-topping-dialog"
                                class="rounded-lg bg-emerald-600 px-3.5 py-2 text-xs font-semibold text-white hover:bg-emerald-700">
                            + Thêm topping
                        </button>
                    <?php endif; ?>
                </div>
                <div id="topping-catalog-list" class="space-y-2.5">
                    <p class="text-xs text-slate-500">Đang tải kho topping…</p>
                </div>
            </section>

            <!-- Khối 2: Gán nhanh topping theo từng món -->
            <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="mb-4">
                    <h2 class="text-base font-bold text-slate-900">Gán topping nhanh theo món</h2>
                    <p class="mt-1 text-xs text-slate-500">Chọn món rồi tích các topping được phép chọn kèm (hoặc chỉnh trực tiếp khi bấm "Sửa món").</p>
                </div>
                <label for="topping-menu-item" class="mb-1 block text-xs font-semibold text-slate-700">Món cần cấu hình</label>
                <select id="topping-menu-item" class="mb-4 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs">
                    <option value="">Đang tải danh sách món…</option>
                </select>
                <div id="topping-list" class="space-y-2.5">
                    <p class="text-xs text-slate-500">Đang tải danh sách topping…</p>
                </div>
            </section>
        </div>
    </main>

    <?php if ($canManageMenu): ?>
        <dialog id="menu-edit-dialog" class="menu-dialog">
            <form id="menu-edit-form" class="menu-dialog-inner space-y-4">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p id="menu-dialog-kicker" class="text-xs font-semibold uppercase tracking-wider text-indigo-600">Cập nhật món</p>
                        <h2 id="menu-dialog-title" class="mt-1 text-lg font-bold text-slate-900">Sửa thông tin món ăn</h2>
                    </div>
                    <button type="button" class="close-menu-dialog rounded-lg px-2 py-1 text-xl text-slate-500 hover:bg-slate-100" aria-label="Đóng">×</button>
                </div>
                <input type="hidden" name="id">
                <div>
                    <label for="edit-name" class="mb-1 block text-xs font-semibold">Tên món</label>
                    <input id="edit-name" name="name" maxlength="150" required class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                </div>
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div>
                        <label for="edit-category" class="mb-1 block text-xs font-semibold">Danh mục</label>
                        <select id="edit-category" name="category_id" required class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                            <?php foreach ($categories as $category): ?>
                                <option value="<?php echo (int)$category['id']; ?>"><?php echo htmlspecialchars($category['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="edit-price" class="mb-1 block text-xs font-semibold">Giá bán (đ)</label>
                        <input id="edit-price" name="price" type="number" min="0" step="1" required class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    </div>
                </div>
                <div>
                    <label for="edit-image-url" class="mb-1 block text-xs font-semibold">Đường dẫn ảnh (tuỳ chọn)</label>
                    <div class="flex items-center gap-3">
                        <div id="edit-image-preview" class="flex h-12 w-12 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-slate-200 bg-slate-100 text-lg">🍽️</div>
                        <input id="edit-image-url" name="image_url" maxlength="255" placeholder="VD: /frontend/img/mi_cay.jpg hoặc https://..." class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    </div>
                </div>
                <div>
                    <label for="edit-description" class="mb-1 block text-xs font-semibold">Mô tả</label>
                    <textarea id="edit-description" name="description" rows="2" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></textarea>
                </div>
                <label class="flex cursor-pointer items-center gap-2 text-xs font-medium">
                    <input type="checkbox" name="is_hot" value="1" class="h-4 w-4 accent-indigo-600">
                    Đánh dấu món nổi bật (hiển thị trên bảng cuộn Món Hot)
                </label>
                <div class="rounded-xl border border-slate-200 bg-slate-50 p-3.5">
                    <div class="mb-2 flex items-center justify-between">
                        <span class="text-xs font-bold text-slate-800">Topping áp dụng cho món này</span>
                        <span class="text-[11px] text-slate-500">Lưu đồng thời khi bấm Lưu</span>
                    </div>
                    <div id="modal-dish-toppings" class="grid grid-cols-1 gap-2 sm:grid-cols-2"></div>
                </div>
                <div class="flex justify-end gap-2 border-t border-slate-100 pt-4">
                    <button type="button" class="close-menu-dialog rounded-lg bg-slate-100 px-4 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-200">Hủy</button>
                    <button type="submit" id="menu-dialog-submit" class="rounded-lg bg-indigo-600 px-4 py-2 text-xs font-semibold text-white hover:bg-indigo-700">Lưu thay đổi</button>
                </div>
            </form>
        </dialog>

        <dialog id="topping-edit-dialog" class="menu-dialog" style="width: min(440px, calc(100vw - 24px));">
            <form id="topping-edit-form" class="menu-dialog-inner space-y-4">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p id="topping-dialog-kicker" class="text-xs font-semibold uppercase tracking-wider text-emerald-600">Kho Topping</p>
                        <h2 id="topping-dialog-title" class="mt-1 text-lg font-bold text-slate-900">Thêm topping mới</h2>
                    </div>
                    <button type="button" class="close-topping-dialog rounded-lg px-2 py-1 text-xl text-slate-500 hover:bg-slate-100" aria-label="Đóng">×</button>
                </div>
                <input type="hidden" name="id">
                <div>
                    <label for="topping-name-input" class="mb-1 block text-xs font-semibold">Tên topping</label>
                    <input id="topping-name-input" name="name" maxlength="100" required placeholder="VD: Thêm Trứng Lòng Đào" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                </div>
                <div>
                    <label for="topping-price-input" class="mb-1 block text-xs font-semibold">Giá thêm (đ)</label>
                    <input id="topping-price-input" name="price" type="number" min="0" step="500" required placeholder="VD: 10000" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                </div>
                <div class="flex justify-end gap-2 border-t border-slate-100 pt-4">
                    <button type="button" class="close-topping-dialog rounded-lg bg-slate-100 px-4 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-200">Hủy</button>
                    <button type="submit" id="topping-dialog-submit" class="rounded-lg bg-emerald-600 px-4 py-2 text-xs font-semibold text-white hover:bg-emerald-700">Lưu topping</button>
                </div>
            </form>
        </dialog>
    <?php endif; ?>

    <div id="menu-toast" class="fixed bottom-4 right-4 z-[1100] hidden max-w-sm rounded-lg px-4 py-3 text-xs font-semibold text-white shadow-lg" role="status" aria-live="polite"></div>
    <script>
        const apiBase = '/api/v1';
        const canManageMenu = <?php echo json_encode($canManageMenu); ?>;
        const menuCategories = <?php echo json_encode($categories, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
        const toastElement = document.getElementById('menu-toast');
        const toppingMenuItemSelect = document.getElementById('topping-menu-item');
        const productsTbody = document.getElementById('products-tbody');
        const statsSummaryEl = document.getElementById('catalog-stats-summary');
        const pageSummaryEl = document.getElementById('catalog-page-summary');
        const paginationWrapEl = document.getElementById('products-pagination-wrap');
        const filterFormEl = document.getElementById('products-filter-form');
        const keywordInputEl = document.getElementById('keyword');
        const categorySelectEl = document.getElementById('category_id');
        const clearFilterBtn = document.getElementById('clear-filter-btn');
        const isEmbeddedMode = new URLSearchParams(window.location.search).get('embed') === '1';
        let currentFilterState = {
            page: <?php echo (int)$page; ?>,
            keyword: <?php echo json_encode($keyword, JSON_UNESCAPED_UNICODE); ?>,
            categoryId: <?php echo (int)$categoryId; ?>,
        };
        let menuCatalogItems = [];
        let toppingCatalog = [];
        let toastTimer;

        function escapeHtml(value) {
            return String(value ?? '').replace(/[&<>"']/g, (char) => ({
                '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
            })[char]);
        }

        function showToast(message, isError = false) {
            toastElement.textContent = message;
            toastElement.className = `fixed bottom-4 right-4 z-[1100] max-w-sm rounded-lg px-4 py-3 text-xs font-semibold text-white shadow-lg ${isError ? 'bg-rose-600' : 'bg-emerald-700'}`;
            clearTimeout(toastTimer);
            toastTimer = setTimeout(() => toastElement.classList.add('hidden'), 3200);
        }

        async function readResponse(response) {
            const data = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(data.error || `Lỗi HTTP ${response.status}`);
            return data;
        }

        function buildProductsUrl(state, includeAjax = false) {
            const params = new URLSearchParams();
            params.set('page', String(Math.max(1, Number(state.page) || 1)));
            if (state.keyword) params.set('keyword', state.keyword);
            if (Number(state.categoryId) > 0) params.set('category_id', String(Number(state.categoryId)));
            if (isEmbeddedMode) params.set('embed', '1');
            if (includeAjax) params.set('ajax', 'table');
            return `products.php?${params.toString()}`;
        }

        function renderMenuTableRows(items) {
            if (!productsTbody) return;
            if (!Array.isArray(items) || items.length === 0) {
                productsTbody.innerHTML = `<tr><td colspan="${canManageMenu ? 7 : 6}" class="px-4 py-8 text-center text-slate-500">Không tìm thấy món phù hợp.</td></tr>`;
                return;
            }
            productsTbody.innerHTML = items.map((item) => {
                const editableItem = {
                    id: Number(item.id),
                    name: item.name || '',
                    category_id: Number(item.category_id),
                    price: Number(item.price || 0),
                    description: item.description || '',
                    image_url: item.image_url || '',
                    is_hot: Boolean(item.is_hot),
                    is_available: Boolean(item.is_available),
                };
                const toppingCount = Number(item.topping_count || 0);
                const toppingBadge = toppingCount > 0
                    ? `<span class="inline-flex rounded-full bg-indigo-50 px-2.5 py-0.5 text-[11px] font-semibold text-indigo-700 border border-indigo-200">${toppingCount} topping</span>`
                    : `<span class="text-[11px] text-slate-400">Chưa gán</span>`;
                const availChecked = editableItem.is_available ? 'checked' : '';
                const availClass = editableItem.is_available ? 'text-emerald-700' : 'text-rose-700';
                const availText = editableItem.is_available ? 'Đang bán' : 'Hết món';
                const statusCell = canManageMenu
                    ? `<label class="inline-flex cursor-pointer items-center gap-2" aria-label="Trạng thái món ${escapeHtml(editableItem.name)}">
                            <input type="checkbox" class="availability-toggle h-4 w-4 accent-emerald-600" data-item-id="${editableItem.id}" ${availChecked}>
                            <span class="availability-label font-semibold ${availClass}">${availText}</span>
                       </label>`
                    : `<span class="font-semibold ${availClass}">${availText}</span>`;
                const actionCell = canManageMenu
                    ? `<td class="px-3 py-3 text-center">
                            <button type="button" class="edit-menu-item rounded-lg border border-indigo-200 bg-indigo-50 px-3 py-1.5 font-semibold text-indigo-700 hover:bg-indigo-100"
                                    data-menu-item="${escapeHtml(JSON.stringify(editableItem))}">Sửa món</button>
                       </td>`
                    : '';
                const imgThumb = editableItem.image_url
                    ? `<img src="${escapeHtml(editableItem.image_url)}" alt="${escapeHtml(editableItem.name)}" class="h-full w-full object-cover" loading="lazy" onerror="this.parentElement.innerHTML='<div class=&quot;flex h-full w-full items-center justify-center text-base&quot;>🍽️</div>'">`
                    : `<div class="flex h-full w-full items-center justify-center text-base">🍽️</div>`;
                return `
                    <tr class="hover:bg-slate-50" data-menu-row="${editableItem.id}">
                        <td class="whitespace-nowrap px-3 py-3 font-mono text-indigo-700">${escapeHtml(item.sku || '—')}</td>
                        <td class="px-3 py-2.5">
                            <div class="flex items-center gap-3">
                                <div class="h-11 w-11 shrink-0 overflow-hidden rounded-lg border border-slate-200 bg-slate-100">${imgThumb}</div>
                                <div>
                                    <div class="font-semibold text-slate-900">${escapeHtml(editableItem.name)}</div>
                                    ${editableItem.is_hot ? '<span class="mt-0.5 inline-block rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-semibold text-amber-700">Món nổi bật</span>' : ''}
                                </div>
                            </div>
                        </td>
                        <td class="px-3 py-3">${escapeHtml(item.category_name || '')}</td>
                        <td class="px-3 py-3 text-center">${toppingBadge}</td>
                        <td class="whitespace-nowrap px-3 py-3 text-right font-bold">${editableItem.price.toLocaleString('vi-VN')} đ</td>
                        <td class="px-3 py-3 text-center">${statusCell}</td>
                        ${actionCell}
                    </tr>
                `;
            }).join('');
        }

        function renderPaginationAndSummaries(payload) {
            const p = payload.pagination || {};
            const c = payload.counts || {};
            const total = Number(c.catalogTotal || 0);
            const avail = Number(c.availableCount || 0);
            const unavail = Number(c.unavailableCount || 0);

            if (statsSummaryEl) {
                statsSummaryEl.innerHTML = `
                    <div class="font-semibold text-slate-800">Toàn bộ thực đơn: ${total} món</div>
                    <div class="mt-1 flex justify-end gap-3">
                        <span class="text-emerald-700">Đang bán: ${avail}</span>
                        <span class="text-rose-700">Hết món: ${unavail}</span>
                    </div>
                `;
            }
            if (pageSummaryEl) {
                pageSummaryEl.innerHTML = `
                    Trang <strong>${Number(p.page || 1)}</strong> / <strong>${Number(p.totalPages || 1)}</strong>
                    (Tổng số: <strong>${total}</strong> món
                    | Đang bán: <strong class="text-emerald-700">${avail}</strong>
                    | Hết món: <strong class="text-rose-700">${unavail}</strong>)
                `;
            }
            if (paginationWrapEl) {
                const pageNum = Number(p.page || 1);
                const totalPagesNum = Number(p.totalPages || 1);
                let navHtml = '';
                if (totalPagesNum > 1) {
                    const links = [];
                    if (pageNum > 1) {
                        const prevHref = buildProductsUrl({ ...currentFilterState, page: pageNum - 1 }, false);
                        links.push(`<a class="rounded border px-2 py-1.5 hover:bg-slate-100" data-page="${pageNum - 1}" href="${escapeHtml(prevHref)}">Trước</a>`);
                    }
                    const start = Math.max(1, pageNum - 2);
                    const end = Math.min(totalPagesNum, pageNum + 2);
                    for (let i = start; i <= end; i++) {
                        if (i === pageNum) {
                            links.push(`<span class="rounded bg-indigo-600 px-3 py-1.5 font-bold text-white" aria-current="page">${i}</span>`);
                        } else {
                            const href = buildProductsUrl({ ...currentFilterState, page: i }, false);
                            links.push(`<a class="rounded border px-3 py-1.5 hover:bg-slate-100" data-page="${i}" href="${escapeHtml(href)}">${i}</a>`);
                        }
                    }
                    if (pageNum < totalPagesNum) {
                        const nextHref = buildProductsUrl({ ...currentFilterState, page: pageNum + 1 }, false);
                        links.push(`<a class="rounded border px-2 py-1.5 hover:bg-slate-100" data-page="${pageNum + 1}" href="${escapeHtml(nextHref)}">Sau</a>`);
                    }
                    navHtml = `<nav class="flex flex-wrap items-center gap-1" aria-label="Phân trang thực đơn">${links.join('')}</nav>`;
                }
                paginationWrapEl.innerHTML = `
                    <div class="text-slate-500">Hiển thị ${Number(p.from || 0)}–${Number(p.to || 0)} trên ${Number(p.totalRecords || 0)} kết quả lọc</div>
                    ${navHtml}
                `;
            }
            if (clearFilterBtn) {
                const hasFilter = Boolean(currentFilterState.keyword || Number(currentFilterState.categoryId) > 0);
                clearFilterBtn.classList.toggle('hidden', !hasFilter);
            }
        }

        async function refreshProductsTable(nextState = currentFilterState, pushUrl = false) {
            const normalized = {
                page: Math.max(1, Number(nextState.page) || 1),
                keyword: String(nextState.keyword || '').trim(),
                categoryId: Number(nextState.categoryId) || 0,
            };
            const response = await fetch(buildProductsUrl(normalized, true), {
                headers: { Accept: 'application/json' },
            });
            const payload = await readResponse(response);
            currentFilterState = {
                page: Number(payload.pagination?.page || normalized.page),
                keyword: payload.keyword || '',
                categoryId: Number(payload.categoryId || 0),
            };
            renderMenuTableRows(payload.items || []);
            renderPaginationAndSummaries(payload);
            if (pushUrl) {
                history.replaceState(currentFilterState, '', buildProductsUrl(currentFilterState, false));
            }
        }

        function renderToppingCatalogList() {
            const container = document.getElementById('topping-catalog-list');
            if (!container) return;
            if (!toppingCatalog.length) {
                container.innerHTML = '<p class="text-xs text-slate-500">Chưa có topping nào trong hệ thống.</p>';
                return;
            }
            container.innerHTML = toppingCatalog.map((topping) => {
                const avail = topping.is_available !== false;
                const toggleBtn = canManageMenu
                    ? `<button type="button"
                               class="topping-availability-btn rounded-full px-3 py-1 text-[11px] font-semibold transition border ${avail ? 'bg-emerald-50 text-emerald-700 border-emerald-200 hover:bg-emerald-100' : 'bg-rose-50 text-rose-700 border-rose-200 hover:bg-rose-100'}"
                               data-topping-id="${Number(topping.id)}"
                               data-next-available="${avail ? 'false' : 'true'}">
                           ${avail ? '● Còn hàng' : '✕ Hết hàng'}
                       </button>
                       <button type="button"
                               class="edit-topping-btn rounded-lg border border-slate-200 bg-white px-2.5 py-1 text-[11px] font-semibold text-slate-700 hover:bg-slate-100"
                               data-topping="${escapeHtml(JSON.stringify({ id: Number(topping.id), name: topping.name, price: Number(topping.price || 0) }))}">
                           Sửa
                       </button>`
                    : `<span class="rounded-full px-2.5 py-0.5 text-[11px] font-semibold ${avail ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700'}">${avail ? 'Còn hàng' : 'Hết hàng'}</span>`;
                return `
                    <div class="flex items-center justify-between gap-3 rounded-lg border border-slate-200 p-3 text-xs ${avail ? 'bg-white' : 'bg-rose-50/30'}">
                        <div class="min-w-0">
                            <div class="font-semibold text-slate-900">${escapeHtml(topping.name)}</div>
                            <div class="mt-0.5 text-slate-500">+${Number(topping.price || 0).toLocaleString('vi-VN')} đ</div>
                        </div>
                        <div class="flex shrink-0 items-center gap-2">${toggleBtn}</div>
                    </div>
                `;
            }).join('');
        }

        function renderToppingsForSelectedItem() {
            const container = document.getElementById('topping-list');
            const menuItem = menuCatalogItems.find((item) => String(item.id) === toppingMenuItemSelect.value);
            if (!menuItem) {
                container.innerHTML = '<p class="text-xs text-slate-500">Chưa có món để cấu hình topping.</p>';
                return;
            }
            const assignedIds = (menuItem.toppings || []).map((topping) => Number(topping.id));
            const choices = toppingCatalog.map((topping) => {
                const checked = assignedIds.includes(Number(topping.id));
                const avail = topping.is_available !== false;
                return `
                    <label class="flex cursor-pointer items-center justify-between gap-3 rounded-lg border p-3 text-xs transition ${checked ? 'border-indigo-300 bg-indigo-50/50' : 'border-slate-200 bg-white hover:bg-slate-50'}">
                        <span class="flex min-w-0 items-center gap-2.5">
                            <input type="checkbox" class="topping-choice h-4 w-4 accent-indigo-600" value="${Number(topping.id)}"
                                ${checked ? 'checked' : ''} ${canManageMenu ? '' : 'disabled'}>
                            <span class="min-w-0">
                                <span class="block font-semibold text-slate-800">${escapeHtml(topping.name)}</span>
                                <span class="text-[11px] text-slate-500">+${Number(topping.price || 0).toLocaleString('vi-VN')} đ</span>
                            </span>
                        </span>
                        <span class="shrink-0 text-[11px] font-semibold ${avail ? 'text-emerald-700' : 'text-rose-600'}">
                            ${avail ? 'Đang mở' : 'Đang hết'}
                        </span>
                    </label>
                `;
            }).join('');
            container.innerHTML = `
                <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                    ${choices || '<p class="text-xs text-slate-500">Chưa có topping trong hệ thống.</p>'}
                </div>
                ${canManageMenu ? `<div class="pt-2"><button type="button" class="save-menu-item-toppings rounded-lg bg-indigo-600 px-4 py-2 text-xs font-semibold text-white hover:bg-indigo-700" data-menu-item-id="${Number(menuItem.id)}">Lưu gán topping cho món</button></div>` : ''}
            `;
        }

        function renderModalDishToppings(assignedIds = []) {
            const box = document.getElementById('modal-dish-toppings');
            if (!box) return;
            if (!toppingCatalog.length) {
                box.innerHTML = '<p class="text-xs text-slate-500 sm:col-span-2">Chưa có topping nào. Hãy thêm topping ở mục Kho Topping.</p>';
                return;
            }
            box.innerHTML = toppingCatalog.map((top) => {
                const checked = assignedIds.includes(Number(top.id));
                return `
                    <label class="flex cursor-pointer items-center gap-2 rounded-lg border border-slate-200 bg-white px-2.5 py-2 text-xs hover:border-indigo-300">
                        <input type="checkbox" class="modal-topping-checkbox h-4 w-4 accent-indigo-600" value="${Number(top.id)}" ${checked ? 'checked' : ''}>
                        <span class="min-w-0 flex-1 truncate font-medium text-slate-800">${escapeHtml(top.name)}</span>
                        <span class="shrink-0 text-[11px] text-slate-500">+${Number(top.price || 0).toLocaleString('vi-VN')}đ</span>
                    </label>
                `;
            }).join('');
        }

        function updateImagePreview(url) {
            const preview = document.getElementById('edit-image-preview');
            if (!preview) return;
            const trimmed = String(url || '').trim();
            if (!trimmed) {
                preview.innerHTML = '🍽️';
                return;
            }
            preview.innerHTML = `<img src="${escapeHtml(trimmed)}" alt="Xem trước" class="h-full w-full object-cover" onerror="this.parentElement.innerHTML='🍽️'">`;
        }

        async function loadToppings() {
            try {
                const response = await fetch(`${apiBase}/admin/menu-catalog`, { headers: { Accept: 'application/json' } });
                const data = await readResponse(response);
                toppingCatalog = data.available_toppings || [];
                menuCatalogItems = (data.categories || []).flatMap((category) =>
                    (category.items || []).map((item) => ({ ...item, category_name: category.name }))
                );
                const currentId = toppingMenuItemSelect.value;
                toppingMenuItemSelect.innerHTML = menuCatalogItems.map((item) => {
                    const count = Array.isArray(item.toppings) ? item.toppings.length : 0;
                    return `<option value="${Number(item.id)}">${escapeHtml(item.category_name)} · ${escapeHtml(item.name)} (${count} topping)</option>`;
                }).join('');
                if (menuCatalogItems.some((item) => String(item.id) === currentId)) {
                    toppingMenuItemSelect.value = currentId;
                }
                renderToppingCatalogList();
                renderToppingsForSelectedItem();
            } catch (error) {
                const container = document.getElementById('topping-list');
                if (container) container.innerHTML = `<p class="text-xs text-rose-700">Không tải được topping: ${escapeHtml(error.message)}</p>`;
            }
        }

        if (filterFormEl) {
            filterFormEl.addEventListener('submit', async (event) => {
                event.preventDefault();
                try {
                    await refreshProductsTable({
                        page: 1,
                        keyword: keywordInputEl ? keywordInputEl.value : '',
                        categoryId: categorySelectEl ? Number(categorySelectEl.value) : 0,
                    }, true);
                } catch (error) {
                    showToast(error.message, true);
                }
            });
        }

        if (clearFilterBtn) {
            clearFilterBtn.addEventListener('click', async (event) => {
                event.preventDefault();
                if (keywordInputEl) keywordInputEl.value = '';
                if (categorySelectEl) categorySelectEl.value = '0';
                try {
                    await refreshProductsTable({ page: 1, keyword: '', categoryId: 0 }, true);
                } catch (error) {
                    showToast(error.message, true);
                }
            });
        }

        if (paginationWrapEl) {
            paginationWrapEl.addEventListener('click', async (event) => {
                const link = event.target.closest('a[data-page]');
                if (!link) return;
                event.preventDefault();
                try {
                    await refreshProductsTable({
                        ...currentFilterState,
                        page: Number(link.dataset.page) || 1,
                    }, true);
                } catch (error) {
                    showToast(error.message, true);
                }
            });
        }

        if (canManageMenu) {
            const dialog = document.getElementById('menu-edit-dialog');
            const form = document.getElementById('menu-edit-form');
            const kicker = document.getElementById('menu-dialog-kicker');
            const title = document.getElementById('menu-dialog-title');
            const submitBtn = document.getElementById('menu-dialog-submit');
            const openCreateBtn = document.getElementById('open-create-menu-dialog');
            const imageInput = document.getElementById('edit-image-url');

            if (imageInput) {
                imageInput.addEventListener('input', () => updateImagePreview(imageInput.value));
            }

            if (openCreateBtn) {
                openCreateBtn.addEventListener('click', () => {
                    form.reset();
                    form.elements.id.value = '';
                    if (menuCategories.length > 0) {
                        form.elements.category_id.value = menuCategories[0].id;
                    }
                    updateImagePreview('');
                    renderModalDishToppings([]);
                    kicker.textContent = 'Thêm món mới';
                    title.textContent = 'Tạo món ăn mới trong thực đơn';
                    submitBtn.textContent = 'Tạo món';
                    dialog.showModal();
                });
            }

            document.addEventListener('click', (event) => {
                const button = event.target.closest('.edit-menu-item');
                if (!button) return;
                const item = JSON.parse(button.dataset.menuItem || '{}');
                form.elements.id.value = item.id;
                form.elements.name.value = item.name || '';
                form.elements.category_id.value = item.category_id;
                form.elements.price.value = item.price;
                form.elements.image_url.value = item.image_url || '';
                form.elements.description.value = item.description || '';
                form.elements.is_hot.checked = Boolean(item.is_hot);
                updateImagePreview(item.image_url || '');
                const catalogMatch = menuCatalogItems.find((m) => Number(m.id) === Number(item.id));
                const assignedIds = catalogMatch && Array.isArray(catalogMatch.toppings)
                    ? catalogMatch.toppings.map((t) => Number(t.id))
                    : [];
                renderModalDishToppings(assignedIds);
                kicker.textContent = 'Cập nhật món';
                title.textContent = 'Sửa thông tin món ăn';
                submitBtn.textContent = 'Lưu thay đổi';
                dialog.showModal();
            });
            document.querySelectorAll('.close-menu-dialog').forEach((button) => button.addEventListener('click', () => dialog.close()));

            form.addEventListener('submit', async (event) => {
                event.preventDefault();
                const itemId = form.elements.id.value.trim();
                const payload = {
                    name: form.elements.name.value.trim(),
                    category_id: Number(form.elements.category_id.value),
                    price: Number(form.elements.price.value),
                    image_url: form.elements.image_url.value.trim(),
                    description: form.elements.description.value.trim(),
                    is_hot: form.elements.is_hot.checked,
                };
                const selectedToppingIds = [...form.querySelectorAll('.modal-topping-checkbox:checked')]
                    .map((cb) => Number(cb.value));
                const saveButton = form.querySelector('[type="submit"]');
                saveButton.disabled = true;
                try {
                    const targetUrl = itemId ? `${apiBase}/admin/menu-items/${encodeURIComponent(itemId)}` : `${apiBase}/admin/menu-items`;
                    const method = itemId ? 'PATCH' : 'POST';
                    const response = await fetch(targetUrl, {
                        method,
                        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                        body: JSON.stringify(payload),
                    });
                    const saved = await readResponse(response);
                    const targetItemId = Number(itemId || saved.id || 0);
                    if (targetItemId > 0) {
                        const topRes = await fetch(`${apiBase}/admin/menu-items/${encodeURIComponent(targetItemId)}/toppings`, {
                            method: 'PATCH',
                            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                            body: JSON.stringify({ topping_ids: selectedToppingIds }),
                        });
                        await readResponse(topRes);
                    }
                    dialog.close();
                    await Promise.all([
                        refreshProductsTable(currentFilterState, false),
                        loadToppings(),
                    ]);
                    showToast(itemId ? 'Đã cập nhật món và topping.' : 'Đã thêm món mới.');
                } catch (error) {
                    showToast(error.message, true);
                } finally {
                    saveButton.disabled = false;
                }
            });

            // Dialog Thêm / Sửa Topping
            const toppingDialog = document.getElementById('topping-edit-dialog');
            const toppingForm = document.getElementById('topping-edit-form');
            const toppingDialogTitle = document.getElementById('topping-dialog-title');
            const toppingDialogSubmit = document.getElementById('topping-dialog-submit');
            const openCreateToppingBtn = document.getElementById('open-create-topping-dialog');

            if (openCreateToppingBtn && toppingDialog && toppingForm) {
                openCreateToppingBtn.addEventListener('click', () => {
                    toppingForm.reset();
                    toppingForm.elements.id.value = '';
                    toppingDialogTitle.textContent = 'Thêm topping mới';
                    toppingDialogSubmit.textContent = 'Tạo topping';
                    toppingDialog.showModal();
                });
            }

            document.addEventListener('click', (event) => {
                const btn = event.target.closest('.edit-topping-btn');
                if (!btn || !toppingDialog || !toppingForm) return;
                const top = JSON.parse(btn.dataset.topping || '{}');
                toppingForm.elements.id.value = top.id || '';
                toppingForm.elements.name.value = top.name || '';
                toppingForm.elements.price.value = top.price ?? 0;
                toppingDialogTitle.textContent = 'Cập nhật topping';
                toppingDialogSubmit.textContent = 'Lưu topping';
                toppingDialog.showModal();
            });

            document.querySelectorAll('.close-topping-dialog').forEach((btn) =>
                btn.addEventListener('click', () => toppingDialog && toppingDialog.close())
            );

            if (toppingForm) {
                toppingForm.addEventListener('submit', async (event) => {
                    event.preventDefault();
                    const topId = toppingForm.elements.id.value.trim();
                    const payload = {
                        name: toppingForm.elements.name.value.trim(),
                        price: Number(toppingForm.elements.price.value),
                    };
                    toppingDialogSubmit.disabled = true;
                    try {
                        const url = topId ? `${apiBase}/admin/toppings/${encodeURIComponent(topId)}` : `${apiBase}/admin/toppings`;
                        const method = topId ? 'PATCH' : 'POST';
                        const res = await fetch(url, {
                            method,
                            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                            body: JSON.stringify(payload),
                        });
                        await readResponse(res);
                        toppingDialog.close();
                        await loadToppings();
                        showToast(topId ? 'Đã cập nhật topping.' : 'Đã thêm topping mới.');
                    } catch (error) {
                        showToast(error.message, true);
                    } finally {
                        toppingDialogSubmit.disabled = false;
                    }
                });
            }
        }

        document.addEventListener('change', async (event) => {
            const checkbox = event.target.closest('.availability-toggle');
            if (!checkbox) return;
            const previousValue = !checkbox.checked;
            checkbox.disabled = true;
            try {
                const response = await fetch(`${apiBase}/admin/menu-items/${checkbox.dataset.itemId}/toggle-availability?is_available=${checkbox.checked}`, {
                    method: 'PATCH',
                    headers: { Accept: 'application/json' },
                });
                const data = await readResponse(response);
                const updatedAvail = Boolean(data.is_available);
                checkbox.checked = updatedAvail;
                await refreshProductsTable(currentFilterState, false);
                showToast(updatedAvail ? 'Đã mở bán lại món.' : 'Đã đánh dấu hết món.');
            } catch (error) {
                checkbox.checked = previousValue;
                showToast(error.message, true);
            } finally {
                checkbox.disabled = false;
            }
        });

        document.addEventListener('click', async (event) => {
            const toggleBtn = event.target.closest('.topping-availability-btn');
            if (toggleBtn) {
                const toppingId = toggleBtn.dataset.toppingId;
                const nextAvail = toggleBtn.dataset.nextAvailable;
                toggleBtn.disabled = true;
                try {
                    const response = await fetch(`${apiBase}/admin/toppings/${encodeURIComponent(toppingId)}/toggle-availability?is_available=${encodeURIComponent(nextAvail)}`, {
                        method: 'PATCH',
                        headers: { Accept: 'application/json' },
                    });
                    await readResponse(response);
                    await loadToppings();
                    showToast(nextAvail === 'true' ? 'Đã mở lại topping.' : 'Đã báo hết topping.');
                } catch (error) {
                    showToast(error.message, true);
                    toggleBtn.disabled = false;
                }
                return;
            }

            const button = event.target.closest('.save-menu-item-toppings');
            if (!button) return;
            const menuItemId = button.dataset.menuItemId;
            const toppingIds = [...document.querySelectorAll('.topping-choice:checked')].map((checkbox) => Number(checkbox.value));
            button.disabled = true;
            try {
                const response = await fetch(`${apiBase}/admin/menu-items/${encodeURIComponent(menuItemId)}/toppings`, {
                    method: 'PATCH',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                    body: JSON.stringify({ topping_ids: toppingIds }),
                });
                await readResponse(response);
                await Promise.all([loadToppings(), refreshProductsTable(currentFilterState, false)]);
                showToast('Đã cập nhật topping cho món.');
            } catch (error) {
                showToast(error.message, true);
                await loadToppings();
            } finally {
                button.disabled = false;
            }
        });

        toppingMenuItemSelect.addEventListener('change', renderToppingsForSelectedItem);
        window.addEventListener('message', (event) => {
            if (event.origin === window.location.origin && event.data?.type === 'dineflow:tab-activated') {
                refreshProductsTable(currentFilterState, false).catch(() => {});
                loadToppings();
            }
        });
        loadToppings();
    </script>
</body>
</html>
