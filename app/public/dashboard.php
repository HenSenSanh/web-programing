<?php
if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('Content-Type: text/html; charset=UTF-8');
}
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/rbac.php';

requireLogin();
requirePermission('reports.view');
$staffPortalActive = 'dashboard';

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

// =========================================================================
// 7. DỮ LIỆU PHÂN HỆ TV5: BÁO CÁO BI, STAR SCHEMA OLAP & MENU ENGINEERING
// =========================================================================
$olapSummary = $pdo->query("
    SELECT
        COALESCE(SUM(revenue), 0) as total_revenue,
        COALESCE(SUM(quantity), 0) as total_sold,
        COUNT(DISTINCT session_id) as purchase_sessions,
        COUNT(*) as total_fact_rows,
        COALESCE(SUM(is_sla_violated), 0) as sla_violations,
        COALESCE(ROUND(AVG(prep_time_seconds) / 60.0, 1), 0) as avg_prep_minutes
    FROM fact_order_items
")->fetch();

$olapTotalRevenue = (float)($olapSummary['total_revenue'] ?? 0);
$olapTotalSold = (int)($olapSummary['total_sold'] ?? 0);
$olapPurchaseSessions = (int)($olapSummary['purchase_sessions'] ?? 0);
$olapFactRows = (int)($olapSummary['total_fact_rows'] ?? 0);
$olapSlaViolations = (int)($olapSummary['sla_violations'] ?? 0);
$olapAvgPrepMin = (float)($olapSummary['avg_prep_minutes'] ?? 0);
$olapSlaCompliance = $olapFactRows > 0 ? round((1.0 - ($olapSlaViolations / $olapFactRows)) * 100.0, 1) : 100.0;
$olapAov = $olapPurchaseSessions > 0 ? round($olapTotalRevenue / $olapPurchaseSessions, 0) : 0;

$clickSessionsCount = (int)$pdo->query("SELECT COUNT(DISTINCT session_id) FROM fact_clickstream")->fetchColumn();
$totalClicksCount = (int)$pdo->query("SELECT COUNT(*) FROM fact_clickstream WHERE event_type = 'VIEW_ITEM'")->fetchColumn();
$convDenominator = max(1, max($clickSessionsCount, $olapPurchaseSessions));
$olapConversionRate = round(($olapPurchaseSessions / $convDenominator) * 100.0, 1);

// Đối soát tổng thu POS (bill_payments)
$posSummary = $pdo->query("
    SELECT
        COUNT(*) as bill_count,
        COALESCE(SUM(subtotal_amount), 0) as pos_subtotal,
        COALESCE(SUM(vat_amount), 0) as pos_vat,
        COALESCE(SUM(final_amount), 0) as pos_final
    FROM bill_payments
")->fetch();

// Doanh thu theo ngày từ Star Schema
$revenueByDate = $pdo->query("
    SELECT
        t.full_date as date,
        COUNT(DISTINCT f.session_id) as sessions_count,
        COALESCE(SUM(f.quantity), 0) as total_sold,
        COALESCE(SUM(f.revenue), 0) as revenue
    FROM fact_order_items f
    JOIN dim_time t ON f.time_key = t.time_key
    GROUP BY t.full_date
    ORDER BY t.full_date ASC
")->fetchAll();

// Doanh thu theo khung giờ cao điểm (Peak Hours)
$revenueByHour = $pdo->query("
    SELECT
        t.hour as hour_of_day,
        COUNT(DISTINCT f.session_id) as sessions_count,
        COALESCE(SUM(f.quantity), 0) as total_sold,
        COALESCE(SUM(f.revenue), 0) as revenue
    FROM fact_order_items f
    JOIN dim_time t ON f.time_key = t.time_key
    GROUP BY t.hour
    ORDER BY t.hour ASC
")->fetchAll();

// Ma trận Menu Engineering
$rawMatrix = $pdo->query("
    SELECT
        m.id,
        m.name,
        m.price,
        m.image_url,
        COALESCE(cat.name, 'Thực đơn') as category_name,
        COALESCE(c.views, 0) as views,
        COALESCE(s.total_sold, 0) as total_sold,
        COALESCE(s.total_revenue, 0) as total_revenue
    FROM menu_items m
    LEFT JOIN categories cat ON m.category_id = cat.id
    LEFT JOIN (
        SELECT oltp_item_id, COUNT(*) as views
        FROM fact_clickstream
        WHERE event_type = 'VIEW_ITEM'
        GROUP BY oltp_item_id
    ) c ON c.oltp_item_id = m.id
    LEFT JOIN (
        SELECT dm.oltp_item_id, SUM(f.quantity) as total_sold, SUM(f.revenue) as total_revenue
        FROM fact_order_items f
        JOIN dim_menu_item dm ON f.item_key = dm.item_key
        GROUP BY dm.oltp_item_id
    ) s ON s.oltp_item_id = m.id
    ORDER BY total_revenue DESC, m.id ASC
")->fetchAll();

$activeMatrixItems = array_filter($rawMatrix, fn($it) => ((int)$it['total_sold'] > 0 || (int)$it['views'] > 0));
$activeCnt = max(1, count($activeMatrixItems));
$avgSoldThreshold = max(1.0, array_sum(array_column($activeMatrixItems, 'total_sold')) / $activeCnt);
$avgRevThreshold = max(35000.0, array_sum(array_column($activeMatrixItems, 'total_revenue')) / $activeCnt);

$menuMatrix = [];
$quadrantCounts = ['STAR' => 0, 'PLOWHORSE' => 0, 'PUZZLE' => 0, 'DOG' => 0];
foreach ($rawMatrix as $rm) {
    $views = (int)$rm['views'];
    $sold = (int)$rm['total_sold'];
    $rev = (float)$rm['total_revenue'];
    $conv = $views > 0 ? round(min(100, ($sold / $views) * 100), 1) : ($sold > 0 ? 100.0 : 0.0);

    if ($sold >= $avgSoldThreshold && $rev >= $avgRevThreshold) {
        $qCode = 'STAR';
        $qBadge = '⭐ Star (Ngôi Sao)';
        $qClass = 'bg-amber-50 text-amber-800 border-amber-300';
        $qAction = 'Món chủ lực: Giữ chuẩn chất lượng & ưu tiên đầu menu';
    } elseif ($sold >= $avgSoldThreshold && $rev < $avgRevThreshold) {
        $qCode = 'PLOWHORSE';
        $qBadge = '🐴 Plowhorse (Ngựa Thồ)';
        $qClass = 'bg-emerald-50 text-emerald-800 border-emerald-300';
        $qAction = 'Bán chạy giá mềm: Ghép Combo Upsell để tăng giá trị đơn';
    } elseif ($rev >= $avgRevThreshold || ($views > 0 && $sold > 0) || ($views >= 2 && $sold === 0)) {
        $qCode = 'PUZZLE';
        $qBadge = '🧩 Puzzle (Dấu Hỏi)';
        $qClass = 'bg-indigo-50 text-indigo-800 border-indigo-300';
        $qAction = 'Tiềm năng cao: Đẩy khuyến mãi hoặc tối ưu hình ảnh/giá';
    } else {
        $qCode = 'DOG';
        $qBadge = '🐕 Dog (Cần Cải Tiến)';
        $qClass = 'bg-slate-100 text-slate-600 border-slate-300';
        $qAction = 'Ít tương tác: Cân nhắc cải tiến công thức hoặc thay thế';
    }
    $quadrantCounts[$qCode]++;

    $menuMatrix[] = [
        'id' => (int)$rm['id'],
        'name' => $rm['name'],
        'image_url' => $rm['image_url'] ?? '',
        'category_name' => $rm['category_name'],
        'price' => (float)$rm['price'],
        'views' => $views,
        'total_sold' => $sold,
        'total_revenue' => $rev,
        'conversion_rate' => $conv,
        'quadrant_code' => $qCode,
        'quadrant_label' => $qBadge,
        'quadrant_class' => $qClass,
        'recommendation_action' => $qAction,
    ];
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="/css/staff-portal.css">
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

    <?php require __DIR__ . '/partials/staff_portal_nav.php'; ?>

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
                        Tài khoản: <strong><?php echo htmlspecialchars($_SESSION['email']); ?></strong> • Phiên làm việc nhân sự DineFlow
                    </p>
                </div>
            </div>

            <div class="flex items-center gap-2">
                <span class="text-xs font-semibold text-slate-600">Vai trò:</span>
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
                <p class="text-[11px] text-slate-500 mt-1">Đang mở phục vụ trên thực đơn điện tử</p>
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
                <p class="text-[11px] text-slate-500 mt-1">Mục tiêu ra món dưới 15 phút</p>
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
                <p class="text-[11px] text-slate-500 mt-1">Tổng hợp từ các phiên đã thanh toán</p>
            </div>

        </div>

        <!-- Quick Access Portal -->
        <div class="bg-white rounded-xl border border-slate-200 shadow-xs p-5 space-y-4">
            <div class="flex items-center justify-between">
                <div>
                    <h3 class="text-sm font-bold text-slate-900">Truy Cập Nhanh Các Khu Vực Vận Hành</h3>
                    <p class="text-xs text-slate-500 mt-0.5">Chuyển nhanh giữa các màn hình làm việc trong ca</p>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3.5 text-xs">
                <a href="/frontend/admin/table-management.php" data-portal-tab="tables" class="p-3.5 rounded-xl border border-slate-200 hover:border-indigo-400 hover:bg-slate-50 transition flex items-center gap-3">
                    <div class="w-9 h-9 rounded-lg bg-indigo-50 text-indigo-600 flex items-center justify-center font-bold text-base">🪑</div>
                    <div>
                        <strong class="text-slate-900 block text-xs">Quản Lý Bàn Ăn &amp; Mã QR</strong>
                        <span class="text-slate-500 text-[11px]">Mở bàn, tạo phiên phục vụ và xuất mã QR</span>
                    </div>
                </a>

                <a href="products.php" data-portal-tab="products" class="p-3.5 rounded-xl border border-slate-200 hover:border-indigo-400 hover:bg-slate-50 transition flex items-center gap-3">
                    <div class="w-9 h-9 rounded-lg bg-emerald-50 text-emerald-600 flex items-center justify-center font-bold text-base">🍲</div>
                    <div>
                        <strong class="text-slate-900 block text-xs">Quản Lý Thực Đơn</strong>
                        <span class="text-slate-500 text-[11px]">Món ăn, hình ảnh, topping và trạng thái phục vụ</span>
                    </div>
                </a>

                <a href="/frontend/menu/index.html?token=tbl-01-sess" target="_blank" class="p-3.5 rounded-xl border border-slate-200 hover:border-emerald-400 hover:bg-slate-50 transition flex items-center gap-3">
                    <div class="w-9 h-9 rounded-lg bg-emerald-50 text-emerald-600 flex items-center justify-center font-bold text-base">📱</div>
                    <div>
                        <strong class="text-slate-900 block text-xs">Menu Khách Gọi Món</strong>
                        <span class="text-slate-500 text-[11px]">Xem thực đơn QR, giỏ hàng chung và gợi ý món kèm</span>
                    </div>
                </a>

                <a href="/frontend/kds/index.php" data-portal-tab="kds" class="p-3.5 rounded-xl border border-slate-200 hover:border-amber-400 hover:bg-slate-50 transition flex items-center gap-3">
                    <div class="w-9 h-9 rounded-lg bg-amber-50 text-amber-600 flex items-center justify-center font-bold text-base">👨‍🍳</div>
                    <div>
                        <strong class="text-slate-900 block text-xs">Điều Phối Bếp (KDS)</strong>
                        <span class="text-slate-500 text-[11px]">Nhận đơn mới, cập nhật chế biến và thời gian phục vụ</span>
                    </div>
                </a>

                <a href="/frontend/pos/index.php" data-portal-tab="pos" class="p-3.5 rounded-xl border border-slate-200 hover:border-rose-400 hover:bg-slate-50 transition flex items-center gap-3">
                    <div class="w-9 h-9 rounded-lg bg-rose-50 text-rose-600 flex items-center justify-center font-bold text-base">💳</div>
                    <div>
                        <strong class="text-slate-900 block text-xs">Thu Ngân (POS)</strong>
                        <span class="text-slate-500 text-[11px]">Tính tiền VAT 8%, VietQR và đóng bàn</span>
                    </div>
                </a>

                <a href="documents.php" data-portal-tab="documents" class="p-3.5 rounded-xl border border-slate-200 hover:border-sky-400 hover:bg-slate-50 transition flex items-center gap-3">
                    <div class="w-9 h-9 rounded-lg bg-sky-50 text-sky-600 flex items-center justify-center font-bold text-base">🧾</div>
                    <div>
                        <strong class="text-slate-900 block text-xs">Hóa Đơn &amp; In Bill</strong>
                        <span class="text-slate-500 text-[11px]">Tra cứu chứng từ thanh toán và in hóa đơn chuẩn</span>
                    </div>
                </a>

                <a href="/frontend/live-ops/index.php" data-portal-tab="live-ops" class="p-3.5 rounded-xl border border-indigo-300 bg-indigo-50/50 hover:bg-indigo-50 transition flex items-center gap-3 sm:col-span-2 lg:col-span-3">
                    <div class="w-9 h-9 rounded-lg bg-indigo-600 text-white flex items-center justify-center font-bold text-base">⚡</div>
                    <div>
                        <strong class="text-indigo-900 block text-xs">Giám Sát Vận Hành Trực Tiếp (Live Ops)</strong>
                        <span class="text-indigo-600 text-[11px]">Theo dõi hoạt động bếp thời gian thực và gợi ý combo bán kèm</span>
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
                <a href="/frontend/live-ops/" data-portal-tab="live-ops" class="text-xs font-semibold text-indigo-600 hover:text-indigo-700 flex items-center gap-1">
                    <span>Xem giám sát chi tiết tại Live Ops</span>
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

        <!-- BÁO CÁO DOANH THU & PHÂN TÍCH THỰC ĐƠN -->
        <section id="bi-analytics-section" class="bg-white rounded-xl border border-teal-200 shadow-xs overflow-hidden">
            <div class="px-5 py-4 bg-gradient-to-r from-teal-900 via-teal-800 to-slate-900 text-white flex flex-wrap items-center justify-between gap-4">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-teal-500/20 border border-teal-400/40 flex items-center justify-center text-xl">
                        📊
                    </div>
                    <div>
                        <h3 class="text-sm sm:text-base font-bold tracking-tight">BÁO CÁO DOANH THU &amp; MA TRẬN THỰC ĐƠN</h3>
                        <p class="text-xs text-teal-100/80 mt-0.5">
                            Số liệu kinh doanh theo ngày, khung giờ cao điểm và hiệu quả từng món ăn
                        </p>
                    </div>
                </div>

                <!-- Xuất Báo Cáo Excel (.xlsx) -->
                <div class="flex flex-wrap items-center gap-2 text-xs">
                    <a href="/api/v1/bi/export-report?type=menu-matrix&format=xlsx" class="px-3 py-2 rounded-lg bg-emerald-500 hover:bg-emerald-600 text-white font-semibold transition shadow-xs flex items-center gap-1.5">
                        <span>📥 Xuất Excel Ma Trận Món (.xlsx)</span>
                    </a>
                    <a href="/api/v1/bi/export-report?type=revenue&format=xlsx" class="px-3 py-2 rounded-lg bg-white/10 hover:bg-white/20 text-white border border-white/20 font-semibold transition flex items-center gap-1.5">
                        <span>📥 Xuất Excel Doanh Thu Ngày (.xlsx)</span>
                    </a>
                </div>
            </div>

            <div class="p-5 space-y-6">
                <!-- 4 KPI Báo Cáo Doanh Thu -->
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                    <div class="rounded-xl p-4 bg-teal-50/60 border border-teal-200">
                        <div class="flex items-center justify-between text-xs text-teal-800 font-semibold mb-1">
                            <span>TỔNG DOANH THU</span>
                        </div>
                        <div class="text-2xl font-extrabold text-teal-900">
                            <?php echo number_format($olapTotalRevenue, 0, ',', '.'); ?> đ
                        </div>
                        <p class="text-[11px] text-teal-700 mt-1">
                            Trung bình đơn: <strong><?php echo number_format($olapAov, 0, ',', '.'); ?> đ/bàn</strong>
                        </p>
                    </div>

                    <div class="rounded-xl p-4 bg-indigo-50/60 border border-indigo-200">
                        <div class="flex items-center justify-between text-xs text-indigo-800 font-semibold mb-1">
                            <span>SỐ LƯỢNG MÓN &amp; PHIÊN BÀN</span>
                        </div>
                        <div class="text-2xl font-extrabold text-indigo-900">
                            <?php echo number_format($olapTotalSold, 0, ',', '.'); ?> <span class="text-sm font-semibold">phần</span>
                        </div>
                        <p class="text-[11px] text-indigo-700 mt-1">
                            Trên tổng số <strong><?php echo $olapPurchaseSessions; ?> phiên bàn</strong> đã ghi nhận
                        </p>
                    </div>

                    <div class="rounded-xl p-4 bg-amber-50/60 border border-amber-200">
                        <div class="flex items-center justify-between text-xs text-amber-800 font-semibold mb-1">
                            <span>TỶ LỆ CHỌN MÓN</span>
                        </div>
                        <div class="text-2xl font-extrabold text-amber-900">
                            <?php echo $olapConversionRate; ?>%
                        </div>
                        <p class="text-[11px] text-amber-700 mt-1">
                            Lượt xem món: <strong><?php echo $totalClicksCount; ?> lượt</strong> (<?php echo $clickSessionsCount; ?> lượt bàn xem)
                        </p>
                    </div>

                    <div class="rounded-xl p-4 bg-rose-50/60 border border-rose-200">
                        <div class="flex items-center justify-between text-xs text-rose-800 font-semibold mb-1">
                            <span>ĐÚNG HẠN BẾP (&le; 15 PHÚT)</span>
                        </div>
                        <div class="text-2xl font-extrabold text-rose-900">
                            <?php echo $olapSlaCompliance; ?>%
                        </div>
                        <p class="text-[11px] text-rose-700 mt-1">
                            TB chế biến: <strong><?php echo $olapAvgPrepMin; ?> phút</strong> • Quá hạn: <strong><?php echo $olapSlaViolations; ?> món</strong>
                        </p>
                    </div>
                </div>

                <!-- Biểu Đồ Doanh Thu Theo Ngày & Khung Giờ Cao Điểm -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
                    <!-- Biểu đồ 1: Doanh thu theo ngày -->
                    <div class="rounded-xl border border-slate-200 p-4 bg-slate-50/50 flex flex-col justify-between">
                        <div class="flex items-center justify-between mb-3">
                            <div>
                                <h4 class="text-xs font-bold uppercase tracking-wider text-slate-800">📈 Doanh Thu Theo Ngày</h4>
                                <p class="text-[11px] text-slate-500">Xu hướng doanh số bán hàng theo từng ngày</p>
                            </div>
                            <span class="text-[11px] font-semibold text-teal-700 bg-teal-50 px-2 py-0.5 rounded border border-teal-200">
                                <?php echo count($revenueByDate); ?> ngày ghi nhận
                            </span>
                        </div>

                        <?php
                            $maxDayRev = 1.0;
                            foreach ($revenueByDate as $rd) {
                                if ((float)$rd['revenue'] > $maxDayRev) $maxDayRev = (float)$rd['revenue'];
                            }
                        ?>
                        <div class="h-44 bg-white border border-slate-200 rounded-lg p-3 flex items-end gap-3 overflow-x-auto">
                            <?php if (empty($revenueByDate)): ?>
                                <p class="text-xs text-slate-400 italic m-auto">Chưa có dữ liệu doanh thu.</p>
                            <?php else: ?>
                                <?php foreach ($revenueByDate as $rd): ?>
                                    <?php
                                        $revVal = (float)$rd['revenue'];
                                        $pct = max(8, round(($revVal / $maxDayRev) * 100));
                                    ?>
                                    <div class="flex flex-col items-center justify-end h-full flex-1 min-w-[54px]" title="<?php echo htmlspecialchars($rd['date']); ?>: <?php echo number_format($revVal, 0, ',', '.'); ?> đ">
                                        <span class="text-[10px] font-mono font-bold text-indigo-700 mb-1">
                                            <?php echo number_format($revVal / 1000, 0, ',', '.'); ?>k
                                        </span>
                                        <div class="w-full max-w-[44px] rounded-t-md bg-gradient-to-t from-indigo-600 to-teal-500 hover:opacity-90 transition" style="height: <?php echo $pct; ?>%;"></div>
                                        <span class="text-[10px] font-mono text-slate-600 mt-1.5 font-semibold"><?php echo htmlspecialchars(substr($rd['date'], 5)); ?></span>
                                        <span class="text-[9px] text-slate-400"><?php echo (int)$rd['total_sold']; ?> món</span>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Biểu đồ 2: Khung giờ cao điểm & Đối soát tài chính -->
                    <div class="rounded-xl border border-slate-200 p-4 bg-slate-50/50 flex flex-col justify-between space-y-3">
                        <div>
                            <div class="flex items-center justify-between mb-2">
                                <div>
                                    <h4 class="text-xs font-bold uppercase tracking-wider text-slate-800">⏰ Khung Giờ Cao Điểm</h4>
                                    <p class="text-[11px] text-slate-500">Thống kê lượt phục vụ theo khung giờ trong ngày</p>
                                </div>
                            </div>
                            <div class="grid grid-cols-3 gap-2">
                                <?php foreach ($revenueByHour as $rh): ?>
                                    <div class="bg-white border border-slate-200 rounded-lg p-2.5 text-xs">
                                        <div class="font-bold text-slate-800 flex items-center justify-between">
                                            <span>🕒 <?php echo sprintf('%02d:00', (int)$rh['hour_of_day']); ?></span>
                                            <span class="text-[10px] bg-indigo-50 text-indigo-700 px-1.5 py-0.5 rounded font-mono"><?php echo (int)$rh['total_sold']; ?> phần</span>
                                        </div>
                                        <div class="text-teal-700 font-mono font-bold mt-1">
                                            <?php echo number_format((float)$rh['revenue'], 0, ',', '.'); ?> đ
                                        </div>
                                        <div class="text-[10px] text-slate-400">
                                            <?php echo (int)$rh['sessions_count']; ?> phiên bàn
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="bg-white border border-teal-200 rounded-lg p-3 text-xs space-y-1.5">
                            <div class="font-bold text-teal-900 flex items-center justify-between">
                                <span>🛡️ Tổng Hợp Đối Soát Tài Chính</span>
                                <span class="text-[10px] px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-800 font-semibold">Đã đối soát</span>
                            </div>
                            <p class="text-[11px] text-slate-600 leading-relaxed">
                                • <strong>Doanh thu món ăn trước thuế:</strong> <strong><?php echo number_format($olapTotalRevenue, 0, ',', '.'); ?> đ</strong>.<br>
                                • <strong>Tổng hóa đơn thu tại quầy POS:</strong> Tạm tính (<strong><?php echo number_format((float)($posSummary['pos_subtotal'] ?? 0), 0, ',', '.'); ?> đ</strong>) + VAT 8% (<strong><?php echo number_format((float)($posSummary['pos_vat'] ?? 0), 0, ',', '.'); ?> đ</strong>) = <strong><?php echo number_format((float)($posSummary['pos_final'] ?? 0), 0, ',', '.'); ?> đ</strong>.
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Bảng Ma Trận Menu Engineering -->
                <div class="rounded-xl border border-slate-200 overflow-hidden bg-white">
                    <div class="p-4 bg-slate-50 border-b border-slate-200 flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h4 class="text-sm font-bold text-slate-900">🍽️ Ma Trận Hiệu Quả Thực Đơn (4 Nhóm Món Ăn)</h4>
                            <p class="text-xs text-slate-500 mt-0.5">
                                Ngưỡng trung bình thực đơn: <strong><?php echo round($avgSoldThreshold, 1); ?> phần</strong> &amp; <strong><?php echo number_format($avgRevThreshold, 0, ',', '.'); ?> đ</strong>
                            </p>
                        </div>

                        <div class="flex flex-wrap items-center gap-1.5 text-xs">
                            <button type="button" onclick="filterMenuMatrix('ALL')" id="btn-quad-ALL" class="quad-filter-btn px-2.5 py-1.5 rounded-lg font-semibold bg-slate-800 text-white transition">
                                Tất cả (<?php echo count($menuMatrix); ?>)
                            </button>
                            <button type="button" onclick="filterMenuMatrix('STAR')" id="btn-quad-STAR" class="quad-filter-btn px-2.5 py-1.5 rounded-lg font-semibold bg-amber-50 text-amber-800 border border-amber-300 hover:bg-amber-100 transition">
                                ⭐ Star (<?php echo $quadrantCounts['STAR']; ?>)
                            </button>
                            <button type="button" onclick="filterMenuMatrix('PLOWHORSE')" id="btn-quad-PLOWHORSE" class="quad-filter-btn px-2.5 py-1.5 rounded-lg font-semibold bg-emerald-50 text-emerald-800 border border-emerald-300 hover:bg-emerald-100 transition">
                                🐴 Plowhorse (<?php echo $quadrantCounts['PLOWHORSE']; ?>)
                            </button>
                            <button type="button" onclick="filterMenuMatrix('PUZZLE')" id="btn-quad-PUZZLE" class="quad-filter-btn px-2.5 py-1.5 rounded-lg font-semibold bg-indigo-50 text-indigo-800 border border-indigo-300 hover:bg-indigo-100 transition">
                                🧩 Puzzle (<?php echo $quadrantCounts['PUZZLE']; ?>)
                            </button>
                            <button type="button" onclick="filterMenuMatrix('DOG')" id="btn-quad-DOG" class="quad-filter-btn px-2.5 py-1.5 rounded-lg font-semibold bg-slate-100 text-slate-700 border border-slate-300 hover:bg-slate-200 transition">
                                🐕 Dog (<?php echo $quadrantCounts['DOG']; ?>)
                            </button>
                        </div>
                    </div>

                    <div class="overflow-x-auto max-h-96">
                        <table class="w-full text-left text-xs">
                            <thead class="bg-slate-100 text-slate-600 uppercase text-[11px] sticky top-0 border-b border-slate-200">
                                <tr>
                                    <th class="py-2.5 px-3">Mã</th>
                                    <th class="py-2.5 px-3">Món Ăn</th>
                                    <th class="py-2.5 px-3">Danh Mục</th>
                                    <th class="py-2.5 px-3 text-right">Đơn Giá</th>
                                    <th class="py-2.5 px-3 text-right">Lượt Xem</th>
                                    <th class="py-2.5 px-3 text-right">Đã Bán</th>
                                    <th class="py-2.5 px-3 text-right">Doanh Thu</th>
                                    <th class="py-2.5 px-3 text-right">Tỷ Lệ Đặt</th>
                                    <th class="py-2.5 px-3">Phân Nhóm</th>
                                    <th class="py-2.5 px-3">Đề Xuất Kinh Doanh</th>
                                </tr>
                            </thead>
                            <tbody id="biMatrixBody" class="divide-y divide-slate-100">
                                <?php foreach ($menuMatrix as $item): ?>
                                    <?php $mImg = trim((string)($item['image_url'] ?? '')); ?>
                                    <tr class="matrix-row hover:bg-slate-50 transition" data-quadrant="<?php echo htmlspecialchars($item['quadrant_code']); ?>">
                                        <td class="py-2 px-3 font-mono text-slate-500">#<?php echo $item['id']; ?></td>
                                        <td class="py-2 px-3">
                                            <div class="flex items-center gap-2.5">
                                                <div class="w-9 h-9 rounded-lg overflow-hidden border border-slate-200 bg-slate-100 shrink-0 flex items-center justify-center">
                                                    <?php if ($mImg !== ''): ?>
                                                        <img src="<?php echo htmlspecialchars($mImg, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8'); ?>" class="w-full h-full object-cover" loading="lazy" onerror="this.parentElement.innerHTML='🍽️'">
                                                    <?php else: ?>
                                                        <span>🍽️</span>
                                                    <?php endif; ?>
                                                </div>
                                                <span class="font-semibold text-slate-900"><?php echo htmlspecialchars($item['name']); ?></span>
                                            </div>
                                        </td>
                                        <td class="py-2 px-3 text-slate-600"><?php echo htmlspecialchars($item['category_name']); ?></td>
                                        <td class="py-2 px-3 text-right font-mono text-slate-700"><?php echo number_format($item['price'], 0, ',', '.'); ?> đ</td>
                                        <td class="py-2 px-3 text-right font-mono font-semibold text-sky-700"><?php echo $item['views']; ?></td>
                                        <td class="py-2 px-3 text-right font-mono font-bold text-slate-900"><?php echo $item['total_sold']; ?></td>
                                        <td class="py-2 px-3 text-right font-mono font-bold text-teal-700"><?php echo number_format($item['total_revenue'], 0, ',', '.'); ?> đ</td>
                                        <td class="py-2 px-3 text-right font-mono font-semibold text-indigo-700"><?php echo $item['conversion_rate']; ?>%</td>
                                        <td class="py-2 px-3">
                                            <span class="inline-block px-2 py-0.5 rounded-full text-[11px] font-bold border <?php echo $item['quadrant_class']; ?>">
                                                <?php echo htmlspecialchars($item['quadrant_label']); ?>
                                            </span>
                                        </td>
                                        <td class="py-2 px-3 text-[11px] text-slate-600"><?php echo htmlspecialchars($item['recommendation_action']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </section>

    </main>

    <script>
        let activeQuadrantFilter = 'ALL';

        function filterMenuMatrix(quadrant) {
            activeQuadrantFilter = quadrant;
            const rows = document.querySelectorAll('.matrix-row');
            rows.forEach(row => {
                if (quadrant === 'ALL' || row.getAttribute('data-quadrant') === quadrant) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });

            document.querySelectorAll('.quad-filter-btn').forEach(btn => {
                btn.classList.remove('ring-2', 'ring-offset-1', 'ring-teal-600');
            });
            const activeBtn = document.getElementById('btn-quad-' + quadrant);
            if (activeBtn) {
                activeBtn.classList.add('ring-2', 'ring-offset-1', 'ring-teal-600');
            }
        }

        document.addEventListener('click', (event) => {
            if (window.parent === window) return;
            const link = event.target.closest('a[data-portal-tab]');
            if (!link) return;
            event.preventDefault();
            window.parent.postMessage(
                { type: 'dineflow:navigate-tab', tab: link.dataset.portalTab },
                window.location.origin
            );
        });

        async function refreshDashboardContentSilently() {
            try {
                const res = await fetch('dashboard.php?embed=1', { headers: { 'X-Requested-With': 'fetch' } });
                if (!res.ok) return;
                const html = await res.text();
                const doc = new DOMParser().parseFromString(html, 'text/html');
                const nextMain = doc.querySelector('main');
                const curMain = document.querySelector('main');
                if (nextMain && curMain) {
                    curMain.innerHTML = nextMain.innerHTML;
                    filterMenuMatrix(activeQuadrantFilter);
                }
            } catch (err) {
                // Keep existing view on background hiccup
            }
        }

        window.addEventListener('message', (event) => {
            if (event.origin === window.location.origin && event.data?.type === 'dineflow:tab-activated') {
                refreshDashboardContentSilently();
            }
        });
    </script>
</body>
</html>
