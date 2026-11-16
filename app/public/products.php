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
           p.sku, u.name AS unit_name
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
    return 'products.php?' . http_build_query($query);
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
        .menu-dialog { width: min(560px, calc(100vw - 24px)); border: 0; border-radius: 16px; padding: 0; }
        .menu-dialog::backdrop { background: rgb(15 23 42 / 45%); }
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
                    <h1 class="mt-1 text-xl font-bold text-slate-900">Quản lý món</h1>
                    <p class="mt-1 text-xs text-slate-500">Tìm món, cập nhật thông tin, trạng thái phục vụ và danh mục topping.</p>
                </div>
                <div class="rounded-lg bg-slate-50 px-4 py-3 text-right text-xs" aria-label="Thống kê trạng thái toàn bộ thực đơn">
                    <div class="font-semibold text-slate-800">Toàn bộ thực đơn: <?php echo $catalogTotal; ?> món</div>
                    <div class="mt-1 flex justify-end gap-3">
                        <span class="text-emerald-700">Đang bán: <?php echo $availableCount; ?></span>
                        <span class="text-rose-700">Hết món: <?php echo $unavailableCount; ?></span>
                    </div>
                </div>
            </div>

            <form method="GET" action="products.php" class="mb-5 flex flex-wrap items-end gap-3 rounded-xl border border-slate-200 bg-slate-50 p-4 text-xs">
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
                <?php if ($keyword !== '' || $categoryId > 0): ?>
                    <a href="products.php" class="rounded-lg bg-slate-200 px-4 py-2 font-semibold text-slate-700 hover:bg-slate-300">Xóa lọc</a>
                <?php endif; ?>
            </form>

            <div class="mb-3 flex flex-wrap items-center justify-between gap-2 text-xs">
                <h2 class="font-bold text-slate-900">Thực đơn món ăn</h2>
                <p class="text-slate-500">
                    Trang <strong><?php echo $page; ?></strong> / <strong><?php echo $totalPages; ?></strong>
                    (Tổng số: <strong><?php echo $catalogTotal; ?></strong> món
                    | Đang bán: <strong class="text-emerald-700"><?php echo $availableCount; ?></strong>
                    | Hết món: <strong class="text-rose-700"><?php echo $unavailableCount; ?></strong>)
                </p>
            </div>
            <p class="mb-3 text-[11px] text-slate-500">
                Phân trang theo kết quả lọc; số liệu đang bán/hết món được tính trên toàn catalog.
            </p>

            <div class="overflow-x-auto rounded-xl border border-slate-200">
                <table class="w-full min-w-[760px] text-left text-xs">
                    <thead class="bg-slate-50 text-slate-600">
                        <tr>
                            <th class="px-3 py-3">Mã</th>
                            <th class="px-3 py-3">Tên món</th>
                            <th class="px-3 py-3">Danh mục</th>
                            <th class="px-3 py-3">Đơn vị</th>
                            <th class="px-3 py-3 text-right">Giá</th>
                            <th class="px-3 py-3 text-center">Trạng thái</th>
                            <?php if ($canManageMenu): ?><th class="px-3 py-3 text-center">Thao tác</th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php if ($menuItems === []): ?>
                            <tr><td colspan="<?php echo $canManageMenu ? 7 : 6; ?>" class="px-4 py-8 text-center text-slate-500">Không tìm thấy món phù hợp.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($menuItems as $item): ?>
                            <?php
                            $editableItem = [
                                'id' => (int)$item['id'],
                                'name' => $item['name'],
                                'category_id' => (int)$item['category_id'],
                                'price' => (float)$item['price'],
                                'description' => $item['description'] ?? '',
                                'is_hot' => (bool)$item['is_hot'],
                                'is_available' => (bool)$item['is_available'],
                            ];
                            $itemJson = json_encode($editableItem, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP);
                            ?>
                            <tr class="hover:bg-slate-50" data-menu-row="<?php echo (int)$item['id']; ?>">
                                <td class="whitespace-nowrap px-3 py-3 font-mono text-indigo-700"><?php echo htmlspecialchars($item['sku'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="px-3 py-3">
                                    <div class="font-semibold text-slate-900"><?php echo htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8'); ?></div>
                                    <?php if ((bool)$item['is_hot']): ?><span class="mt-1 inline-block rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-semibold text-amber-700">Món nổi bật</span><?php endif; ?>
                                </td>
                                <td class="px-3 py-3"><?php echo htmlspecialchars($item['category_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="px-3 py-3 text-slate-500"><?php echo htmlspecialchars($item['unit_name'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="whitespace-nowrap px-3 py-3 text-right font-bold"><?php echo number_format((float)$item['price'], 0, ',', '.'); ?> đ</td>
                                <td class="px-3 py-3 text-center">
                                    <?php if ($canManageMenu): ?>
                                        <label class="inline-flex items-center gap-2" aria-label="Trạng thái món <?php echo htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8'); ?>">
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

            <div class="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 pt-4 text-xs">
                <div class="text-slate-500">
                    Hiển thị <?php echo $totalRecords > 0 ? $offset + 1 : 0; ?>–<?php echo min($offset + $perPage, $totalRecords); ?>
                    trên <?php echo $totalRecords; ?> kết quả lọc
                </div>
                <?php if ($totalPages > 1): ?>
                    <nav class="flex flex-wrap items-center gap-1" aria-label="Phân trang thực đơn">
                        <?php if ($page > 1): ?>
                            <a class="rounded border px-2 py-1.5 hover:bg-slate-100" href="<?php echo htmlspecialchars(buildPageUrl($page - 1, $keyword, $categoryId), ENT_QUOTES, 'UTF-8'); ?>">Trước</a>
                        <?php endif; ?>
                        <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                            <?php if ($i === $page): ?>
                                <span class="rounded bg-indigo-600 px-3 py-1.5 font-bold text-white" aria-current="page"><?php echo $i; ?></span>
                            <?php else: ?>
                                <a class="rounded border px-3 py-1.5 hover:bg-slate-100" href="<?php echo htmlspecialchars(buildPageUrl($i, $keyword, $categoryId), ENT_QUOTES, 'UTF-8'); ?>"><?php echo $i; ?></a>
                            <?php endif; ?>
                        <?php endfor; ?>
                        <?php if ($page < $totalPages): ?>
                            <a class="rounded border px-2 py-1.5 hover:bg-slate-100" href="<?php echo htmlspecialchars(buildPageUrl($page + 1, $keyword, $categoryId), ENT_QUOTES, 'UTF-8'); ?>">Sau</a>
                        <?php endif; ?>
                    </nav>
                <?php endif; ?>
            </div>
        </section>

        <section class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="mb-4">
                <h2 class="text-base font-bold text-slate-900">Topping theo từng món</h2>
                <p class="mt-1 text-xs text-slate-500">Chọn món rồi gán chính xác các topping áp dụng cho món đó. Khách chỉ thấy topping còn hàng đã được gán trực tiếp.</p>
            </div>
            <label for="topping-menu-item" class="mb-1 block text-xs font-semibold text-slate-700">Món cần cấu hình</label>
            <select id="topping-menu-item" class="mb-4 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs">
                <option value="">Đang tải danh sách món…</option>
            </select>
            <div id="topping-list" class="grid grid-cols-1 gap-3 md:grid-cols-2">
                <p class="text-xs text-slate-500">Đang tải danh sách topping…</p>
            </div>
        </section>
    </main>

    <?php if ($canManageMenu): ?>
        <dialog id="menu-edit-dialog" class="menu-dialog">
            <form id="menu-edit-form" class="menu-dialog-inner space-y-4">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wider text-indigo-600">Cập nhật món</p>
                        <h2 class="mt-1 text-lg font-bold text-slate-900">Sửa thông tin món ăn</h2>
                    </div>
                    <button type="button" class="close-menu-dialog rounded-lg px-2 py-1 text-xl text-slate-500" aria-label="Đóng">×</button>
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
                    <label for="edit-description" class="mb-1 block text-xs font-semibold">Mô tả</label>
                    <textarea id="edit-description" name="description" rows="3" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></textarea>
                </div>
                <label class="flex items-center gap-2 text-xs font-medium">
                    <input type="checkbox" name="is_hot" value="1" class="h-4 w-4 accent-indigo-600">
                    Đánh dấu món nổi bật
                </label>
                <div class="flex justify-end gap-2 border-t border-slate-100 pt-4">
                    <button type="button" class="close-menu-dialog rounded-lg bg-slate-100 px-4 py-2 text-xs font-semibold text-slate-700">Hủy</button>
                    <button type="submit" class="rounded-lg bg-indigo-600 px-4 py-2 text-xs font-semibold text-white hover:bg-indigo-700">Lưu thay đổi</button>
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
            const data = await response.json();
            if (!response.ok) throw new Error(data.error || `Lỗi HTTP ${response.status}`);
            return data;
        }

        function renderToppingsForSelectedItem() {
            const container = document.getElementById('topping-list');
            const menuItem = menuCatalogItems.find((item) => String(item.id) === toppingMenuItemSelect.value);
            if (!menuItem) {
                container.innerHTML = '<p class="text-xs text-slate-500">Chưa có món để cấu hình topping.</p>';
                return;
            }
            const assignedIds = (menuItem.toppings || []).map((topping) => Number(topping.id));
            const choices = toppingCatalog.map((topping) => `
                <div class="flex items-start gap-3 rounded-lg border border-slate-200 p-3 text-xs">
                    <label class="flex min-w-0 flex-1 items-start gap-2">
                        <input type="checkbox" class="topping-choice mt-0.5 h-4 w-4 accent-indigo-600" value="${Number(topping.id)}"
                            ${assignedIds.includes(Number(topping.id)) ? 'checked' : ''} ${canManageMenu ? '' : 'disabled'}>
                        <span class="min-w-0">
                            <span class="block font-semibold text-slate-800">${escapeHtml(topping.name)}</span>
                            <span class="mt-1 block text-slate-500">+${Number(topping.price || 0).toLocaleString('vi-VN')} đ</span>
                        </span>
                    </label>
                    ${canManageMenu ? `
                        <label class="flex items-center gap-1 whitespace-nowrap ${topping.is_available ? 'text-emerald-700' : 'text-rose-700'}">
                            <input type="checkbox" class="topping-availability h-4 w-4 accent-emerald-600" data-topping-id="${Number(topping.id)}"
                                aria-label="Trạng thái ${escapeHtml(topping.name)}" ${topping.is_available ? 'checked' : ''}>
                            ${topping.is_available ? 'Còn hàng' : 'Hết hàng'}
                        </label>
                    ` : `<span class="whitespace-nowrap ${topping.is_available ? 'text-emerald-700' : 'text-rose-700'}">${topping.is_available ? 'Còn hàng' : 'Hết hàng'}</span>`}
                </div>
            `).join('');
            container.innerHTML = `
                ${choices || '<p class="text-xs text-slate-500">Chưa có topping trong hệ thống.</p>'}
                ${canManageMenu ? `<div class="md:col-span-2"><button type="button" class="save-menu-item-toppings rounded-lg bg-indigo-600 px-4 py-2 text-xs font-semibold text-white hover:bg-indigo-700" data-menu-item-id="${Number(menuItem.id)}">Lưu topping cho món</button></div>` : ''}
            `;
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
                toppingMenuItemSelect.innerHTML = menuCatalogItems.map((item) => `
                    <option value="${Number(item.id)}">${escapeHtml(item.category_name)} · ${escapeHtml(item.name)}</option>
                `).join('');
                if (menuCatalogItems.some((item) => String(item.id) === currentId)) {
                    toppingMenuItemSelect.value = currentId;
                }
                renderToppingsForSelectedItem();
            } catch (error) {
                const container = document.getElementById('topping-list');
                container.innerHTML = `<p class="text-xs text-rose-700">Không tải được topping: ${escapeHtml(error.message)}</p>`;
            }
        }

        if (canManageMenu) {
            const dialog = document.getElementById('menu-edit-dialog');
            const form = document.getElementById('menu-edit-form');
            document.querySelectorAll('.edit-menu-item').forEach((button) => {
                button.addEventListener('click', () => {
                    const item = JSON.parse(button.dataset.menuItem || '{}');
                    form.elements.id.value = item.id;
                    form.elements.name.value = item.name || '';
                    form.elements.category_id.value = item.category_id;
                    form.elements.price.value = item.price;
                    form.elements.description.value = item.description || '';
                    form.elements.is_hot.checked = Boolean(item.is_hot);
                    dialog.showModal();
                });
            });
            document.querySelectorAll('.close-menu-dialog').forEach((button) => button.addEventListener('click', () => dialog.close()));
            form.addEventListener('submit', async (event) => {
                event.preventDefault();
                const payload = {
                    name: form.elements.name.value.trim(),
                    category_id: Number(form.elements.category_id.value),
                    price: Number(form.elements.price.value),
                    description: form.elements.description.value.trim(),
                    is_hot: form.elements.is_hot.checked,
                };
                const saveButton = form.querySelector('[type="submit"]');
                saveButton.disabled = true;
                try {
                    const response = await fetch(`${apiBase}/admin/menu-items/${encodeURIComponent(form.elements.id.value)}`, {
                        method: 'PATCH',
                        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                        body: JSON.stringify(payload),
                    });
                    await readResponse(response);
                    dialog.close();
                    showToast('Đã cập nhật món.');
                    location.reload();
                } catch (error) {
                    showToast(error.message, true);
                } finally {
                    saveButton.disabled = false;
                }
            });
        }

        document.querySelectorAll('.availability-toggle').forEach((checkbox) => {
            checkbox.addEventListener('change', async () => {
                const previousValue = !checkbox.checked;
                checkbox.disabled = true;
                try {
                    const response = await fetch(`${apiBase}/admin/menu-items/${checkbox.dataset.itemId}/toggle-availability?is_available=${checkbox.checked}`, {
                        method: 'PATCH',
                        headers: { Accept: 'application/json' },
                    });
                    const data = await readResponse(response);
                    checkbox.checked = Boolean(data.is_available);
                    location.reload();
                } catch (error) {
                    checkbox.checked = previousValue;
                    showToast(error.message, true);
                } finally {
                    checkbox.disabled = false;
                }
            });
        });

        document.addEventListener('change', async (event) => {
            const checkbox = event.target.closest('.topping-availability');
            if (!checkbox) return;
            const previousValue = !checkbox.checked;
            checkbox.disabled = true;
            try {
                const response = await fetch(`${apiBase}/admin/toppings/${checkbox.dataset.toppingId}/toggle-availability?is_available=${checkbox.checked}`, {
                    method: 'PATCH',
                    headers: { Accept: 'application/json' },
                });
                await readResponse(response);
                await loadToppings();
                showToast('Đã cập nhật trạng thái topping.');
            } catch (error) {
                checkbox.checked = previousValue;
                showToast(error.message, true);
                checkbox.disabled = false;
            }
        });

        document.addEventListener('click', async (event) => {
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
                await loadToppings();
                showToast('Đã cập nhật topping cho món.');
            } catch (error) {
                showToast(error.message, true);
                await loadToppings();
            } finally {
                button.disabled = false;
            }
        });

        toppingMenuItemSelect.addEventListener('change', renderToppingsForSelectedItem);
        loadToppings();
    </script>
</body>
</html>
