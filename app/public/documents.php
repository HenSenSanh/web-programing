<?php
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/rbac.php';
require_once __DIR__ . '/../src/inventory.php';

requireLogin();

$pdo = getDatabaseConnection();
$userId = (int)$_SESSION['user_id'];
$message = "";
$isError = false;

// 1. Xử lý ghi sổ phiếu (POST) theo logic Bài D
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['action']) && $_POST['action'] === 'post_document') {
    requirePermission('documents.post');
    $docId = (int)($_POST['document_id'] ?? 0);
    $res = InventoryService::postDocument($docId, $userId);
    $message = $res['message'];
    $isError = !$res['success'];
}

// 2. Lấy danh sách hóa đơn thanh toán nhà hàng DineFlow
$stmtBills = $pdo->query("
    SELECT bp.*, t.table_number
    FROM bill_payments bp
    LEFT JOIN dining_tables t ON bp.table_id = t.id
    ORDER BY bp.id DESC
    LIMIT 10
");
$bills = $stmtBills ? $stmtBills->fetchAll() : [];

// 3. Lấy danh sách chứng từ hệ thống (Bài D)
$stmtDocs = $pdo->query("
    SELECT d.*, u.username as creator_name, p.username as poster_name,
           COUNT(l.id) as total_lines,
           COALESCE(SUM(l.subtotal), 0) as total_amount
    FROM inventory_documents d
    JOIN users u ON d.created_by = u.id
    LEFT JOIN users p ON d.posted_by = p.id
    LEFT JOIN inventory_lines l ON d.id = l.document_id
    GROUP BY d.id
    ORDER BY d.id DESC
");
$documents = $stmtDocs ? $stmtDocs->fetchAll() : [];

// 4. Nếu đang xem / in hóa đơn cụ thể
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
            WHERE oi.session_id = :session_id
        ");
        $stmtLines->execute(["session_id" => $viewBill['session_id']]);
        $billItems = $stmtLines->fetchAll();
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DineFlow - Hóa Đơn & Chứng Từ Thanh Toán (Bài D: In Ấn & Transaction)</title>
    <!-- Google Fonts Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }
        
        /* Chuẩn hóa in ấn @media print (Bài D) */
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

    <!-- Navbar -->
    <header class="bg-white border-b border-slate-200 sticky top-0 z-40 shadow-xs no-print">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 py-3.5 flex items-center justify-between gap-4">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-indigo-600 flex items-center justify-center text-white shadow-md shadow-indigo-200 font-black text-lg">
                    🧾
                </div>
                <div>
                    <div class="flex items-center gap-2">
                        <span class="text-sm font-bold text-slate-900 tracking-tight">DINEFLOW</span>
                        <span class="text-[11px] font-semibold px-2 py-0.5 rounded-full bg-sky-50 text-sky-700 border border-sky-200">
                            Hóa Đơn & Thu Ngân
                        </span>
                    </div>
                    <p class="text-xs text-slate-500">Quản Lý Hóa Đơn Nhà Hàng • Bài D: Khóa Dòng, Giao Dịch & In Chuẩn @media print</p>
                </div>
            </div>

            <nav class="flex items-center gap-2 text-xs font-medium">
                <a href="dashboard.php" class="px-3 py-1.5 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-700 transition">
                    Dashboard
                </a>
                <a href="products.php" class="px-3 py-1.5 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-700 transition">
                    🍲 Thực Đơn (Bài A)
                </a>
                <a href="documents.php" class="px-3 py-1.5 rounded-lg bg-indigo-50 text-indigo-700 border border-indigo-200 font-semibold">
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

        <?php if ($message !== ""): ?>
            <div class="p-4 rounded-xl text-xs font-semibold <?php echo $isError ? 'bg-rose-50 text-rose-700 border border-rose-200' : 'bg-emerald-50 text-emerald-700 border border-emerald-200'; ?> no-print">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <!-- Chi tiết hóa đơn cần in (Khi bấm Xem / In) -->
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
                                    <td class="py-2 font-medium"><?php echo htmlspecialchars($item['item_name']); ?></td>
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
                    <p class="text-[10px] text-slate-400 mt-0.5">Chứng từ thanh toán áp dụng Transaction nguyên tử & hỗ trợ in chuẩn @media print</p>
                </div>

                <div class="mt-6 flex justify-end gap-2.5 no-print">
                    <a href="documents.php" class="px-4 py-2 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-semibold transition">
                        Đóng
                    </a>
                    <button onclick="window.print()" class="px-4 py-2 rounded-lg bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold transition flex items-center gap-1.5 shadow-sm shadow-indigo-200">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                        <span>In Hóa Đơn (Ctrl + P)</span>
                    </button>
                </div>
            </div>
        <?php endif; ?>

        <!-- Danh sách Hóa đơn thanh toán nhà hàng DineFlow -->
        <div class="bg-white rounded-xl p-5 border border-slate-200 shadow-xs no-print">
            <div class="flex items-center justify-between mb-4">
                <div>
                    <h2 class="text-base font-bold text-slate-900">Danh Sách Hóa Đơn Thanh Toán Nhà Hàng DineFlow</h2>
                    <p class="text-xs text-slate-500 mt-0.5">Lịch sử thu ngân chốt bàn, snapshot đơn giá và xuất hóa đơn.</p>
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
                    <tbody class="divide-y divide-slate-100 text-slate-700">
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
                                        <a href="documents.php?bill_id=<?php echo $b['id']; ?>" class="px-3 py-1 rounded-lg bg-indigo-50 hover:bg-indigo-100 text-indigo-700 font-semibold transition border border-indigo-200">
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

</body>
</html>
