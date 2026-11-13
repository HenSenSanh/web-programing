<?php
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/rbac.php';

requireLogin();

$pdo = getDatabaseConnection();
$userId = (int)$_SESSION['user_id'];

// Lấy danh sách nhóm quyền và quyền hạn thực tế của user (Bài B)
$stmtRoles = $pdo->prepare("
    SELECT r.name, r.role_code
    FROM roles r
    JOIN user_roles ur ON r.id = ur.role_id
    WHERE ur.user_id = :user_id
");
$stmtRoles->execute(["user_id" => $userId]);
$userRoles = $stmtRoles->fetchAll();

// Thống kê nghiệp vụ DineFlow (QRDine)
// 1. Số bàn ăn đang phục vụ
$stmtActiveTables = $pdo->query("SELECT COUNT(*) as cnt FROM dining_tables WHERE status = 'OCCUPIED'");
$activeTablesCount = (int)($stmtActiveTables->fetch()['cnt'] ?? 0);

// 2. Tổng số bàn ăn
$stmtTotalTables = $pdo->query("SELECT COUNT(*) as cnt FROM dining_tables");
$totalTablesCount = (int)($stmtTotalTables->fetch()['cnt'] ?? 15);

// 3. Tổng món ăn đang bán trong thực đơn (Bài A)
$stmtMenuItems = $pdo->query("SELECT COUNT(*) as cnt FROM menu_items WHERE is_available = 1");
$totalMenuItems = (int)($stmtMenuItems->fetch()['cnt'] ?? 0);

// 4. Món đang chờ nấu dưới bếp KDS
$stmtKdsPending = $pdo->query("SELECT COUNT(*) as cnt FROM order_items WHERE status IN ('PENDING', 'COOKING')");
$kdsPendingCount = (int)($stmtKdsPending->fetch()['cnt'] ?? 0);

// 5. Doanh thu hôm nay (tương thích MySQL & SQLite)
$stmtRevenue = $pdo->prepare("SELECT COALESCE(SUM(final_amount), 0) as rev FROM bill_payments WHERE DATE(payment_time) = ?");
$stmtRevenue->execute([date('Y-m-d')]);
$todayRevenue = (float)($stmtRevenue->fetch()['rev'] ?? 0.0);
if ($todayRevenue == 0) {
    // Tổng tiền từ các phiên đã thanh toán mẫu
    $stmtRev2 = $pdo->query("SELECT COALESCE(SUM(total_amount), 0) as rev FROM table_sessions WHERE status IN ('CLOSED', 'CHECKOUT', 'PAID')");
    $todayRevenue = (float)($stmtRev2->fetch()['rev'] ?? 730000.0);
}

// 6. Danh sách bàn ăn thực tế
$stmtTablesList = $pdo->query("
    SELECT t.*, s.session_token, s.guest_count, s.total_amount as current_bill
    FROM dining_tables t
    LEFT JOIN table_sessions s ON t.current_session_id = s.id
    ORDER BY t.id ASC
    LIMIT 12
");
$tablesList = $stmtTablesList->fetchAll();
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DineFlow - Trung Tâm Điều Hành Nhà Hàng (QRDine)</title>
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

    <!-- Top Navigation Bar -->
    <header class="bg-white border-b border-slate-200 sticky top-0 z-40 shadow-xs">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 py-3.5 flex items-center justify-between gap-4">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-indigo-600 flex items-center justify-center text-white shadow-md shadow-indigo-200 font-black text-lg">
                    🍲
                </div>
                <div>
                    <div class="flex items-center gap-2">
                        <span class="text-sm font-bold text-slate-900 tracking-tight">DINEFLOW</span>
                        <span class="text-[11px] font-semibold px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200">
                            Restaurant OS
                        </span>
                    </div>
                    <p class="text-xs text-slate-500">Hệ Thống Gọi Món QR Tại Bàn, Bếp KDS & Phân Tích Nhà Hàng F&B</p>
                </div>
            </div>

            <!-- Navigation Links -->
            <nav class="flex items-center gap-2 text-xs font-medium">
                <a href="dashboard.php" class="px-3 py-1.5 rounded-lg bg-indigo-50 text-indigo-700 border border-indigo-200 font-semibold">
                    Dashboard
                </a>
                <a href="products.php" class="px-3 py-1.5 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-700 transition" title="Bài A: Phân trang thực đơn món ăn">
                    🍲 Thực Đơn (Bài A)
                </a>
                <a href="documents.php" class="px-3 py-1.5 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-700 transition" title="Bài D: Hóa đơn & In ấn">
                    🧾 Hóa Đơn & In (Bài D)
                </a>
                <a href="/frontend/tv4_data_pipeline.html" class="px-3 py-1.5 rounded-lg bg-indigo-600 hover:bg-indigo-700 text-white font-semibold transition shadow-xs flex items-center gap-1">
                    <span>⚡ TV4 Data Pipeline</span>
                </a>
                <div class="h-4 w-px bg-slate-300 mx-1"></div>
                <a href="logout.php" class="px-3 py-1.5 rounded-lg bg-rose-50 hover:bg-rose-100 text-rose-700 border border-rose-200 transition">
                    Đăng Xuất
                </a>
            </nav>
        </div>
    </header>

    <main class="max-w-7xl mx-auto px-4 sm:px-6 py-6 space-y-6">

        <!-- User Role & RBAC Overview Card -->
        <div class="bg-white rounded-xl p-5 border border-slate-200 shadow-xs flex flex-wrap items-center justify-between gap-4">
            <div class="flex items-center gap-3.5">
                <div class="w-12 h-12 rounded-full bg-indigo-100 text-indigo-700 flex items-center justify-center font-bold text-lg">
                    <?php echo strtoupper(substr($_SESSION['username'], 0, 2)); ?>
                </div>
                <div>
                    <h2 class="text-base font-bold text-slate-900">
                        Xin chào, <span class="text-indigo-600"><?php echo htmlspecialchars($_SESSION['username']); ?></span>!
                    </h2>
                    <p class="text-xs text-slate-500 mt-0.5">
                        Tài khoản: <strong><?php echo htmlspecialchars($_SESSION['email']); ?></strong> • Đăng nhập an toàn qua hệ thống xác thực DineFlow (Mục 38-40 PDF)
                    </p>
                </div>
            </div>

            <!-- RBAC Roles Tags (Bài B) -->
            <div class="flex items-center gap-2">
                <span class="text-xs font-semibold text-slate-600">Nhóm quyền hiệu lực (Bài B):</span>
                <?php if (!empty($userRoles)): ?>
                    <?php foreach ($userRoles as $r): ?>
                        <span class="text-xs font-bold px-2.5 py-1 rounded-full bg-indigo-50 text-indigo-700 border border-indigo-200">
                            🛡️ <?php echo htmlspecialchars($r['name']); ?> (<?php echo htmlspecialchars($r['role_code']); ?>)
                        </span>
                    <?php endforeach; ?>
                <?php else: ?>
                    <span class="text-xs font-medium px-2.5 py-1 rounded-full bg-slate-100 text-slate-600">
                        Nhân viên cơ sở
                    </span>
                <?php endif; ?>
            </div>
        </div>

        <!-- 4 Restaurant KPIs Grid -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            
            <!-- Bàn ăn đang có khách -->
            <div class="bg-white rounded-xl p-4 border border-slate-200 shadow-xs">
                <div class="flex items-center justify-between text-slate-500 mb-2">
                    <span class="text-xs font-semibold uppercase tracking-wider">Bàn Đang Có Khách</span>
                    <span class="w-8 h-8 rounded-lg bg-indigo-50 text-indigo-600 flex items-center justify-center font-bold">🪑</span>
                </div>
                <div class="flex items-baseline gap-2">
                    <span class="text-2xl font-bold text-slate-900"><?php echo $activeTablesCount; ?></span>
                    <span class="text-xs text-slate-500">/ <?php echo $totalTablesCount; ?> bàn</span>
                </div>
                <p class="text-[11px] text-slate-500 mt-1">Khách đang ngồi gọi món qua mã QR</p>
            </div>

            <!-- Món ăn trong thực đơn -->
            <div class="bg-white rounded-xl p-4 border border-slate-200 shadow-xs">
                <div class="flex items-center justify-between text-slate-500 mb-2">
                    <span class="text-xs font-semibold uppercase tracking-wider">Thực Đơn Đang Bán</span>
                    <span class="w-8 h-8 rounded-lg bg-emerald-50 text-emerald-600 flex items-center justify-center font-bold">🍲</span>
                </div>
                <div class="flex items-baseline gap-2">
                    <span class="text-2xl font-bold text-emerald-600"><?php echo $totalMenuItems; ?></span>
                    <span class="text-xs text-slate-500">món ăn</span>
                </div>
                <p class="text-[11px] text-slate-500 mt-1">Phân trang DB thật: COUNT / LIMIT / OFFSET</p>
            </div>

            <!-- Chờ bếp KDS nấu -->
            <div class="bg-white rounded-xl p-4 border border-slate-200 shadow-xs">
                <div class="flex items-center justify-between text-slate-500 mb-2">
                    <span class="text-xs font-semibold uppercase tracking-wider">Món Chờ Bếp Nấu</span>
                    <span class="w-8 h-8 rounded-lg bg-amber-50 text-amber-600 flex items-center justify-center font-bold">👨‍🍳</span>
                </div>
                <div class="flex items-baseline gap-2">
                    <span class="text-2xl font-bold text-amber-600"><?php echo $kdsPendingCount; ?></span>
                    <span class="text-xs text-slate-500">món trên KDS</span>
                </div>
                <p class="text-[11px] text-slate-500 mt-1">Giám sát SLA chế biến (Ngưỡng 900s)</p>
            </div>

            <!-- Doanh thu hôm nay -->
            <div class="bg-white rounded-xl p-4 border border-slate-200 shadow-xs">
                <div class="flex items-center justify-between text-slate-500 mb-2">
                    <span class="text-xs font-semibold uppercase tracking-wider">Doanh Thu Nhà Hàng</span>
                    <span class="w-8 h-8 rounded-lg bg-sky-50 text-sky-600 flex items-center justify-center font-bold">💰</span>
                </div>
                <div class="flex items-baseline gap-2">
                    <span class="text-2xl font-bold text-sky-700"><?php echo number_format($todayRevenue, 0, ',', '.'); ?> đ</span>
                </div>
                <p class="text-[11px] text-slate-500 mt-1">Thanh toán POS & Kho Star Schema</p>
            </div>

        </div>

        <!-- Team Module Quick Access Portal -->
        <div class="bg-white rounded-xl border border-slate-200 shadow-xs p-5 space-y-4">
            <div class="flex items-center justify-between">
                <div>
                    <h3 class="text-sm font-bold text-slate-900">Liên Kết Trực Tiếp Sang Các Màn Hình Phân Hệ Cả Nhóm</h3>
                    <p class="text-xs text-slate-500 mt-0.5">Tích hợp đồng bộ kiến trúc Modular Monolith: TV1, TV2, TV3, TV4, TV5</p>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3.5 text-xs">
                <a href="/frontend/tv1/table-management.html" target="_blank" class="p-3.5 rounded-xl border border-slate-200 hover:border-indigo-400 hover:bg-slate-50 transition flex items-center gap-3">
                    <div class="w-9 h-9 rounded-lg bg-indigo-50 text-indigo-600 flex items-center justify-center font-bold text-base">🪑</div>
                    <div>
                        <strong class="text-slate-900 block text-xs">Quản Lý Bàn Ăn & Mã QR (TV1)</strong>
                        <span class="text-slate-500 text-[11px]">Mở bàn, gán khách và in mã QR theo phiên</span>
                    </div>
                </a>

                <a href="products.php" class="p-3.5 rounded-xl border border-slate-200 hover:border-indigo-400 hover:bg-slate-50 transition flex items-center gap-3">
                    <div class="w-9 h-9 rounded-lg bg-emerald-50 text-emerald-600 flex items-center justify-center font-bold text-base">🍲</div>
                    <div>
                        <strong class="text-slate-900 block text-xs">Quản Lý Thực Đơn (Bài A - TV1)</strong>
                        <span class="text-slate-500 text-[11px]">Phân trang thật COUNT/LIMIT/OFFSET, lọc danh mục</span>
                    </div>
                </a>

                <a href="/frontend/menu/index.html?token=tbl-01-sess" target="_blank" class="p-3.5 rounded-xl border border-slate-200 hover:border-emerald-400 hover:bg-slate-50 transition flex items-center gap-3">
                    <div class="w-9 h-9 rounded-lg bg-emerald-50 text-emerald-600 flex items-center justify-center font-bold text-base">📱</div>
                    <div>
                        <strong class="text-slate-900 block text-xs">Menu Khách Quét QR (TV2)</strong>
                        <span class="text-slate-500 text-[11px]">Giỏ hàng chung thời gian thực, gợi ý món kèm</span>
                    </div>
                </a>

                <a href="/frontend/kds.html" target="_blank" class="p-3.5 rounded-xl border border-slate-200 hover:border-amber-400 hover:bg-slate-50 transition flex items-center gap-3">
                    <div class="w-9 h-9 rounded-lg bg-amber-50 text-amber-600 flex items-center justify-center font-bold text-base">👨‍🍳</div>
                    <div>
                        <strong class="text-slate-900 block text-xs">Màn Hình Điều Phối Bếp KDS (TV3)</strong>
                        <span class="text-slate-500 text-[11px]">Tiếp nhận món, chuyển trạng thái nấu, đo SLA</span>
                    </div>
                </a>

                <a href="documents.php" class="p-3.5 rounded-xl border border-slate-200 hover:border-sky-400 hover:bg-slate-50 transition flex items-center gap-3">
                    <div class="w-9 h-9 rounded-lg bg-sky-50 text-sky-600 flex items-center justify-center font-bold text-base">🧾</div>
                    <div>
                        <strong class="text-slate-900 block text-xs">Hóa Đơn & In Bill Chuẩn (Bài D - TV3)</strong>
                        <span class="text-slate-500 text-[11px]">Transaction thanh toán, khóa dòng, in ấn @media print</span>
                    </div>
                </a>

                <a href="/frontend/tv4_data_pipeline.html" class="p-3.5 rounded-xl border border-indigo-300 bg-indigo-50/50 hover:bg-indigo-50 transition flex items-center gap-3">
                    <div class="w-9 h-9 rounded-lg bg-indigo-600 text-white flex items-center justify-center font-bold text-base">⚡</div>
                    <div>
                        <strong class="text-indigo-900 block text-xs">Data Pipeline & Live Ops (TV4)</strong>
                        <span class="text-indigo-600 text-[11px]">Khai phá Apriori, SLA bếp, đồng bộ Star Schema</span>
                    </div>
                </a>
            </div>
        </div>

        <!-- Live Floor Status (Bàn Ăn Nhà Hàng) -->
        <div class="bg-white rounded-xl border border-slate-200 shadow-xs overflow-hidden">
            <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
                <div>
                    <h3 class="text-sm font-bold text-slate-900">Sơ Đồ Bàn Ăn Hiện Tại Trong Nhà Hàng</h3>
                    <p class="text-xs text-slate-500 mt-0.5">Theo dõi trạng thái phục vụ và phiên bàn đang hoạt động</p>
                </div>
                <a href="/frontend/tv4_data_pipeline.html" class="text-xs font-semibold text-indigo-600 hover:text-indigo-700 flex items-center gap-1">
                    <span>Xem giám sát chi tiết tại TV4</span>
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                </a>
            </div>

            <div class="p-5 grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-3.5">
                <?php foreach ($tablesList as $tbl): ?>
                    <?php 
                        $isOcc = ($tbl['status'] === 'OCCUPIED');
                        $bg = $isOcc ? 'bg-white border-indigo-200 ring-1 ring-indigo-50' : 'bg-slate-50/80 border-slate-200';
                        $statusBadge = $isOcc 
                            ? '<span class="text-[10px] font-bold px-1.5 py-0.5 rounded bg-emerald-50 text-emerald-700 border border-emerald-200">Đang ăn</span>'
                            : '<span class="text-[10px] font-medium px-1.5 py-0.5 rounded bg-slate-100 text-slate-500">Trống</span>';
                    ?>
                    <div class="rounded-xl border <?php echo $bg; ?> p-3 flex flex-col justify-between text-xs transition hover:shadow-xs">
                        <div class="flex items-center justify-between mb-1.5">
                            <strong class="text-slate-900 font-bold"><?php echo htmlspecialchars($tbl['table_number']); ?></strong>
                            <?php echo $statusBadge; ?>
                        </div>
                        <?php if ($isOcc): ?>
                            <div class="text-[11px] text-slate-500">
                                Bill: <strong class="text-indigo-700 font-semibold"><?php echo number_format((float)($tbl['current_bill'] ?? 0), 0, ',', '.'); ?> đ</strong>
                            </div>
                            <div class="mt-2 pt-2 border-t border-slate-100">
                                <a href="/frontend/menu/index.html?token=<?php echo htmlspecialchars($tbl['session_token'] ?? ''); ?>" target="_blank" class="text-[10px] text-indigo-600 font-semibold hover:underline flex items-center gap-1">
                                    <span>Vào Menu QR</span>
                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                                </a>
                            </div>
                        <?php else: ?>
                            <div class="text-[11px] text-slate-400 mt-2">Sẵn sàng</div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

    </main>

</body>
</html>
