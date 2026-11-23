<?php
if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('Content-Type: text/html; charset=UTF-8');
}
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/rbac.php';
require_once __DIR__ . '/../Services/OrderToppingSnapshotService.php';

requireLogin();
requirePermission('documents.print');
$staffPortalActive = 'documents';

$pdo = getDatabaseConnection();
$userId = (int)$_SESSION['user_id'];
$message = "";
$isError = false;

// 1. Lấy danh sách hóa đơn thanh toán nhà hàng DineFlow (QRDine)
$stmtBills = $pdo->query("
    SELECT bp.*, t.table_number
    FROM bill_payments bp
    LEFT JOIN dining_tables t ON bp.table_id = t.id
    ORDER BY bp.id DESC
    LIMIT 20
");
$bills = $stmtBills ? $stmtBills->fetchAll() : [];

// 2. Nếu đang xem / in hóa đơn cụ thể
$viewBillId = !empty($_GET['bill_id']) ? (int)$_GET['bill_id'] : 0;
$viewBill = null;
$billItems = [];

if ($viewBillId > 0) {
    $stmtB = $pdo->prepare("
        SELECT bp.*, t.table_number
        FROM bill_payments bp
        LEFT JOIN dining_tables t ON bp.table_id = t.id
        WHERE bp.id = :id
    ");
    $stmtB->execute(["id" => $viewBillId]);
    $viewBill = $stmtB->fetch();

    if ($viewBill) {
        $stmtLines = $pdo->prepare("
            SELECT oi.*
            FROM order_items oi
            JOIN orders o ON oi.order_id = o.id
            WHERE o.session_id = :session_id AND oi.status <> 'CANCELLED'
            ORDER BY oi.id ASC
        ");
        $stmtLines->execute(["session_id" => $viewBill['session_id']]);
        $billItems = \App\Services\OrderToppingSnapshotService::attach($pdo, $stmtLines->fetchAll());
    }
}

$ajaxAction = (string)($_GET['ajax'] ?? '');
if ($ajaxAction === 'bills') {
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=UTF-8');
    }
    echo json_encode(['bills' => $bills], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}
if ($ajaxAction === 'bill') {
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=UTF-8');
    }
    if (!$viewBill) {
        http_response_code(404);
        echo json_encode(['error' => 'Không tìm thấy hóa đơn'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode([
        'bill' => $viewBill,
        'items' => $billItems,
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
    <title>DineFlow - Hóa Đơn & Chứng Từ Thanh Toán</title>
    <!-- Google Fonts Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }
        
        /* Chuẩn hóa in ấn */
        @media print {
            body { background: #ffffff !important; color: #000000 !important; }
            header, nav, .no-print, button, a { display: none !important; }
            .print-only-container {
                box-shadow: none !important;
                border: none !important;
                padding: 0 !important;
                margin: 0 !important;
                width: 100% !important;
                max-width: 100% !important;
            }
        }
    </style>
</head>
<body class="bg-slate-50 text-slate-800 min-h-screen antialiased">
    <?php require __DIR__ . '/partials/staff_portal_nav.php'; ?>

    <main class="max-w-7xl mx-auto px-4 sm:px-6 py-6 space-y-6">

        <?php if ($message !== ""): ?>
            <div class="p-4 rounded-xl text-xs font-semibold <?php echo $isError ? 'bg-rose-50 text-rose-700 border border-rose-200' : 'bg-emerald-50 text-emerald-700 border border-emerald-200'; ?> no-print">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <!-- Chi tiết hóa đơn cần in (Khi bấm Xem / In) -->
        <div id="bill-preview-section" class="<?php echo $viewBill ? '' : 'hidden '; ?>">
        <?php if ($viewBill): ?>
            <div class="bg-white rounded-xl p-6 border border-slate-200 shadow-sm print-only-container max-w-2xl mx-auto">
                <div class="flex items-start justify-between border-b border-slate-200 pb-4 mb-4">
                    <div>
                        <h2 class="text-xl font-black text-slate-900 tracking-tight">NHÀ HÀNG DINEFLOW</h2>
                        <p class="text-xs text-slate-500 mt-0.5">Địa chỉ: Khu Ẩm Thực Nhà Hàng DineFlow • Hotline: 1900 6868</p>
                        <p class="text-xs text-slate-600 font-semibold mt-1">
                            BÀN: <span class="text-indigo-700 font-bold"><?php echo htmlspecialchars($viewBill['table_number'] ?? 'Mang về'); ?></span> • Phiên: <?php echo htmlspecialchars($viewBill['session_id']); ?>
                        </p>
                    </div>
                    <div class="text-right">
                        <span class="text-xs uppercase font-bold text-slate-400 block tracking-wider">HÓA ĐƠN THANH TOÁN</span>
                        <strong class="text-sm font-mono text-slate-900"><?php echo htmlspecialchars($viewBill['invoice_code']); ?></strong>
                        <div class="text-[11px] text-slate-500 mt-1"><?php echo htmlspecialchars($viewBill['payment_time']); ?></div>
                    </div>
                </div>

                <div class="mb-4">
                    <table class="w-full text-xs text-left">
                        <thead class="border-b border-slate-200 text-slate-600 font-semibold">
                            <tr>
                                <th class="py-2">Món Ăn</th>
                                <th class="py-2 text-center">SL</th>
                                <th class="py-2 text-right">Đơn Giá</th>
                                <th class="py-2 text-right">Thành Tiền</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 text-slate-800">
                            <?php foreach ($billItems as $item): ?>
                                <tr>
                                    <td class="py-2 font-medium">
                                        <?php echo htmlspecialchars($item['item_name'], ENT_QUOTES, 'UTF-8'); ?>
                                        <div class="mt-1 text-[10px] font-normal text-slate-500">
                                            <?php if (!($item['topping_details_recorded'] ?? false)): ?>
                                                Chưa có dữ liệu lịch sử topping
                                            <?php elseif (empty($item['toppings'])): ?>
                                                Không chọn topping
                                            <?php else: ?>
                                                Topping:
                                                <?php
                                                $toppingLabels = array_map(
                                                    static fn(array $topping): string => htmlspecialchars(
                                                        $topping['name'] . ' (+' . number_format((float)$topping['unit_price'], 0, ',', '.') . ' đ)',
                                                        ENT_QUOTES,
                                                        'UTF-8'
                                                    ),
                                                    $item['toppings']
                                                );
                                                echo implode(', ', $toppingLabels);
                                                ?>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="py-2 text-center font-bold">x<?php echo $item['quantity']; ?></td>
                                    <td class="py-2 text-right"><?php echo number_format((float)$item['unit_price'], 0, ',', '.'); ?> đ</td>
                                    <td class="py-2 text-right font-semibold"><?php echo number_format((float)$item['subtotal'], 0, ',', '.'); ?> đ</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="border-t border-slate-200 pt-3 space-y-1 text-xs">
                    <div class="flex justify-between text-slate-600">
                        <span>Tiền món ăn:</span>
                        <span><?php echo number_format((float)$viewBill['subtotal_amount'], 0, ',', '.'); ?> đ</span>
                    </div>
                    <div class="flex justify-between text-slate-600">
                        <span>Thuế VAT (8%):</span>
                        <span><?php echo number_format((float)$viewBill['vat_amount'], 0, ',', '.'); ?> đ</span>
                    </div>
                    <div class="flex justify-between text-slate-900 font-bold text-sm pt-2 border-t border-dashed border-slate-200">
                        <span>TỔNG THANH TOÁN:</span>
                        <span class="text-indigo-700"><?php echo number_format((float)$viewBill['final_amount'], 0, ',', '.'); ?> đ</span>
                    </div>
                    <div class="flex justify-between text-slate-500 text-[11px] pt-1">
                        <span>Phương thức thanh toán:</span>
                        <span class="font-semibold text-slate-700"><?php echo htmlspecialchars($viewBill['payment_method']); ?></span>
                    </div>
                </div>

                <div class="mt-6 pt-4 border-t border-slate-100 text-center text-xs text-slate-500">
                    <p class="font-semibold">Cảm ơn quý khách đã thưởng thức tại DineFlow!</p>
                    <p class="text-[10px] text-slate-400 mt-0.5">Hẹn gặp lại quý khách vào lần sau</p>
                </div>

                <div class="mt-6 flex justify-end gap-2.5 no-print">
                    <a href="documents.php" class="close-bill-btn px-4 py-2 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-semibold transition">
                        Đóng
                    </a>
                    <button onclick="window.print()" class="px-4 py-2 rounded-lg bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold transition flex items-center gap-1.5 shadow-sm shadow-indigo-200">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                        <span>In Hóa Đơn (Ctrl + P)</span>
                    </button>
                </div>
            </div>
        <?php endif; ?>
        </div>

        <!-- Danh sách Hóa đơn thanh toán nhà hàng DineFlow -->
        <div class="bg-white rounded-xl p-5 border border-slate-200 shadow-xs no-print">
            <div class="flex items-center justify-between mb-4">
                <div>
                    <h2 class="text-base font-bold text-slate-900">Danh Sách Hóa Đơn Thanh Toán</h2>
                    <p class="text-xs text-slate-500 mt-0.5">Tra cứu lịch sử thu ngân chốt bàn và in lại hóa đơn.</p>
                </div>
            </div>

            <div class="border border-slate-200 rounded-xl overflow-hidden">
                <table class="w-full text-xs text-left">
                    <thead class="bg-slate-50 text-slate-600 font-semibold border-b border-slate-200">
                        <tr>
                            <th class="px-4 py-3">Mã Hóa Đơn</th>
                            <th class="px-4 py-3">Bàn Ăn</th>
                            <th class="px-4 py-3">Thời Gian</th>
                            <th class="px-4 py-3">Phương Thức</th>
                            <th class="px-4 py-3 text-right">Tổng Tiền</th>
                            <th class="px-4 py-3 text-center">Thao Tác</th>
                        </tr>
                    </thead>
                    <tbody id="bills-tbody" class="divide-y divide-slate-100 text-slate-700">
                        <?php if (empty($bills)): ?>
                            <tr>
                                <td colspan="6" class="px-4 py-6 text-center text-slate-400 italic">Chưa có hóa đơn nào được ghi nhận.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($bills as $b): ?>
                                <tr class="hover:bg-slate-50 transition">
                                    <td class="px-4 py-3 font-mono font-bold text-indigo-700"><?php echo htmlspecialchars($b['invoice_code']); ?></td>
                                    <td class="px-4 py-3 font-semibold text-slate-900"><?php echo htmlspecialchars($b['table_number'] ?? 'Bàn'); ?></td>
                                    <td class="px-4 py-3 text-slate-500"><?php echo htmlspecialchars($b['payment_time']); ?></td>
                                    <td class="px-4 py-3">
                                        <span class="px-2 py-0.5 rounded-full text-[11px] font-semibold bg-sky-50 text-sky-700 border border-sky-200">
                                            <?php echo htmlspecialchars($b['payment_method']); ?>
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-right font-bold text-slate-900">
                                        <?php echo number_format((float)$b['final_amount'], 0, ',', '.'); ?> đ
                                    </td>
                                    <td class="px-4 py-3 text-center">
                                        <a href="documents.php?bill_id=<?php echo $b['id']; ?>" data-bill-id="<?php echo (int)$b['id']; ?>" class="view-bill-btn px-3 py-1 rounded-lg bg-indigo-50 hover:bg-indigo-100 text-indigo-700 font-semibold transition border border-indigo-200">
                                            Xem & In Bill
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </main>

    <script>
        (function () {
            const billPreviewSection = document.getElementById('bill-preview-section');
            const billsTbody = document.getElementById('bills-tbody');
            const isEmbedded = new URLSearchParams(window.location.search).get('embed') === '1';

            function escapeHtml(val) {
                return String(val ?? '').replace(/[&<>"']/g, (ch) => ({
                    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
                })[ch]);
            }

            function formatVnd(amount) {
                return Number(amount || 0).toLocaleString('vi-VN') + ' đ';
            }

            function renderBillPreview(bill, items) {
                if (!billPreviewSection) return;
                const itemRows = (items || []).map((item) => {
                    let toppingText = 'Chưa có dữ liệu lịch sử topping';
                    if (item.topping_details_recorded) {
                        const toppings = Array.isArray(item.toppings) ? item.toppings : [];
                        toppingText = toppings.length === 0
                            ? 'Không chọn topping'
                            : 'Topping: ' + toppings.map((t) => `${escapeHtml(t.name)} (+${formatVnd(t.unit_price)})`).join(', ');
                    }
                    return `
                        <tr>
                            <td class="py-2 font-medium">
                                ${escapeHtml(item.item_name)}
                                <div class="mt-1 text-[10px] font-normal text-slate-500">${toppingText}</div>
                            </td>
                            <td class="py-2 text-center font-bold">x${Number(item.quantity || 0)}</td>
                            <td class="py-2 text-right">${formatVnd(item.unit_price)}</td>
                            <td class="py-2 text-right font-semibold">${formatVnd(item.subtotal)}</td>
                        </tr>
                    `;
                }).join('');

                billPreviewSection.innerHTML = `
                    <div class="bg-white rounded-xl p-6 border border-slate-200 shadow-sm print-only-container max-w-2xl mx-auto">
                        <div class="flex items-start justify-between border-b border-slate-200 pb-4 mb-4">
                            <div>
                                <h2 class="text-xl font-black text-slate-900 tracking-tight">NHÀ HÀNG DINEFLOW</h2>
                                <p class="text-xs text-slate-500 mt-0.5">Địa chỉ: Khu Ẩm Thực Nhà Hàng DineFlow • Hotline: 1900 6868</p>
                                <p class="text-xs text-slate-600 font-semibold mt-1">
                                    BÀN: <span class="text-indigo-700 font-bold">${escapeHtml(bill.table_number || 'Mang về')}</span> • Phiên: ${escapeHtml(bill.session_id)}
                                </p>
                            </div>
                            <div class="text-right">
                                <span class="text-xs uppercase font-bold text-slate-400 block tracking-wider">HÓA ĐƠN THANH TOÁN</span>
                                <strong class="text-sm font-mono text-slate-900">${escapeHtml(bill.invoice_code)}</strong>
                                <div class="text-[11px] text-slate-500 mt-1">${escapeHtml(bill.payment_time)}</div>
                            </div>
                        </div>
                        <div class="mb-4">
                            <table class="w-full text-xs text-left">
                                <thead class="border-b border-slate-200 text-slate-600 font-semibold">
                                    <tr>
                                        <th class="py-2">Món Ăn</th>
                                        <th class="py-2 text-center">SL</th>
                                        <th class="py-2 text-right">Đơn Giá</th>
                                        <th class="py-2 text-right">Thành Tiền</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 text-slate-800">${itemRows}</tbody>
                            </table>
                        </div>
                        <div class="border-t border-slate-200 pt-3 space-y-1 text-xs">
                            <div class="flex justify-between text-slate-600">
                                <span>Tiền món ăn:</span>
                                <span>${formatVnd(bill.subtotal_amount)}</span>
                            </div>
                            <div class="flex justify-between text-slate-600">
                                <span>Thuế VAT (8%):</span>
                                <span>${formatVnd(bill.vat_amount)}</span>
                            </div>
                            <div class="flex justify-between text-slate-900 font-bold text-sm pt-2 border-t border-dashed border-slate-200">
                                <span>TỔNG THANH TOÁN:</span>
                                <span class="text-indigo-700">${formatVnd(bill.final_amount)}</span>
                            </div>
                            <div class="flex justify-between text-slate-500 text-[11px] pt-1">
                                <span>Phương thức thanh toán:</span>
                                <span class="font-semibold text-slate-700">${escapeHtml(bill.payment_method)}</span>
                            </div>
                        </div>
                        <div class="mt-6 pt-4 border-t border-slate-100 text-center text-xs text-slate-500">
                            <p class="font-semibold">Cảm ơn quý khách đã thưởng thức tại DineFlow!</p>
                            <p class="text-[10px] text-slate-400 mt-0.5">Hẹn gặp lại quý khách vào lần sau</p>
                        </div>
                        <div class="mt-6 flex justify-end gap-2.5 no-print">
                            <a href="documents.php" class="close-bill-btn px-4 py-2 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-semibold transition">Đóng</a>
                            <button type="button" onclick="window.print()" class="px-4 py-2 rounded-lg bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold transition flex items-center gap-1.5 shadow-sm shadow-indigo-200">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                                <span>In Hóa Đơn (Ctrl + P)</span>
                            </button>
                        </div>
                    </div>
                `;
                billPreviewSection.classList.remove('hidden');
                billPreviewSection.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }

            async function openBillPreview(billId) {
                const res = await fetch(`documents.php?ajax=bill&bill_id=${encodeURIComponent(billId)}`, {
                    headers: { Accept: 'application/json' },
                });
                const data = await res.json();
                if (!res.ok) throw new Error(data.error || `HTTP ${res.status}`);
                renderBillPreview(data.bill, data.items);
                const params = new URLSearchParams();
                if (isEmbedded) params.set('embed', '1');
                params.set('bill_id', String(billId));
                history.replaceState(null, '', `documents.php?${params.toString()}`);
            }

            async function refreshBillsList() {
                if (!billsTbody) return;
                const res = await fetch('documents.php?ajax=bills', { headers: { Accept: 'application/json' } });
                if (!res.ok) return;
                const data = await res.json();
                const bills = Array.isArray(data.bills) ? data.bills : [];
                if (bills.length === 0) {
                    billsTbody.innerHTML = '<tr><td colspan="6" class="px-4 py-6 text-center text-slate-400 italic">Chưa có hóa đơn nào được ghi nhận.</td></tr>';
                    return;
                }
                billsTbody.innerHTML = bills.map((b) => `
                    <tr class="hover:bg-slate-50 transition">
                        <td class="px-4 py-3 font-mono font-bold text-indigo-700">${escapeHtml(b.invoice_code)}</td>
                        <td class="px-4 py-3 font-semibold text-slate-900">${escapeHtml(b.table_number || 'Bàn')}</td>
                        <td class="px-4 py-3 text-slate-500">${escapeHtml(b.payment_time)}</td>
                        <td class="px-4 py-3">
                            <span class="px-2 py-0.5 rounded-full text-[11px] font-semibold bg-sky-50 text-sky-700 border border-sky-200">${escapeHtml(b.payment_method)}</span>
                        </td>
                        <td class="px-4 py-3 text-right font-bold text-slate-900">${formatVnd(b.final_amount)}</td>
                        <td class="px-4 py-3 text-center">
                            <a href="documents.php?bill_id=${Number(b.id)}" data-bill-id="${Number(b.id)}" class="view-bill-btn px-3 py-1 rounded-lg bg-indigo-50 hover:bg-indigo-100 text-indigo-700 font-semibold transition border border-indigo-200">
                                Xem & In Bill
                            </a>
                        </td>
                    </tr>
                `).join('');
            }

            document.addEventListener('click', (event) => {
                const viewLink = event.target.closest('.view-bill-btn');
                if (viewLink) {
                    event.preventDefault();
                    openBillPreview(viewLink.dataset.billId).catch((err) => alert(err.message));
                    return;
                }
                const closeLink = event.target.closest('.close-bill-btn');
                if (closeLink && billPreviewSection) {
                    event.preventDefault();
                    billPreviewSection.classList.add('hidden');
                    billPreviewSection.innerHTML = '';
                    history.replaceState(null, '', isEmbedded ? 'documents.php?embed=1' : 'documents.php');
                }
            });

            window.addEventListener('message', (event) => {
                if (event.origin === window.location.origin && event.data?.type === 'dineflow:tab-activated') {
                    refreshBillsList().catch(() => {});
                }
            });
        })();
    </script>
</body>
</html>
