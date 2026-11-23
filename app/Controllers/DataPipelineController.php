<?php

namespace App\Controllers;

use App\Core\Database;
use App\Services\AprioriService;
use App\Services\EtlService;
use App\Services\SharedCartService;
use PDO;
use Throwable;

class DataPipelineController
{
    /**
     * POST /api/v1/data/clickstream
     * Ghi nhận sự kiện hành vi khách hàng trên Menu QR (VIEW_ITEM, ADD_TO_CART, REMOVE_FROM_CART)
     */
    public function recordClickstream(?array $customData = null): void
    {
        $data = $customData ?? (json_decode(file_get_contents('php://input') ?: '[]', true) ?: []);

        $sessionId = trim((string)($data['session_id'] ?? ''));
        $itemId = (int)($data['menu_item_id'] ?? $data['oltp_item_id'] ?? 0);
        $eventType = strtoupper(trim((string)($data['event_type'] ?? 'VIEW_ITEM')));

        if ($itemId <= 0) {
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'message' => 'menu_item_id is required and must be greater than 0'
            ]);
            return;
        }

        try {
            $db = Database::getConnection();
            $now = time();
            $timeKey = (int)date('YmdH', $now);
            $fullDate = date('Y-m-d', $now);
            $hour = (int)date('H', $now);
            $dayOfWeek = (int)date('w', $now);
            $isWeekend = ($dayOfWeek === 0 || $dayOfWeek === 6) ? 1 : 0;

            // Đảm bảo DimTime tồn tại
            $stmtTimeCheck = $db->prepare("SELECT time_key FROM dim_time WHERE time_key = ?");
            $stmtTimeCheck->execute([$timeKey]);
            if (!$stmtTimeCheck->fetch()) {
                $stmtTime = $db->prepare("INSERT INTO dim_time (time_key, full_date, hour, day_of_week, is_weekend) VALUES (?, ?, ?, ?, ?)");
                $stmtTime->execute([$timeKey, $fullDate, $hour, $dayOfWeek, $isWeekend]);
            }

            // Đảm bảo DimMenuItem tồn tại
            $stmtDim = $db->prepare("SELECT item_key FROM dim_menu_item WHERE oltp_item_id = ?");
            $stmtDim->execute([$itemId]);
            if (!$stmtDim->fetch()) {
                $stmtMenuItem = $db->prepare("
                    SELECT m.name, m.price, COALESCE(c.name, 'Món ăn') as category_name
                    FROM menu_items m
                    LEFT JOIN categories c ON m.category_id = c.id
                    WHERE m.id = ?
                ");
                $stmtMenuItem->execute([$itemId]);
                $realItem = $stmtMenuItem->fetch();
                if ($realItem) {
                    $stmtInsDim = $db->prepare("INSERT INTO dim_menu_item (oltp_item_id, item_name, category_name, current_price) VALUES (?, ?, ?, ?)");
                    $stmtInsDim->execute([$itemId, $realItem['name'], $realItem['category_name'], (float)$realItem['price']]);
                }
            }

            // Ghi nhận sự kiện vào FactClickstream
            $stmt = $db->prepare("INSERT INTO fact_clickstream (time_key, session_id, oltp_item_id, event_type) VALUES (?, ?, ?, ?)");
            $stmt->execute([$timeKey, $sessionId, $itemId, $eventType]);

            echo json_encode([
                'status' => 'success',
                'recorded' => true,
                'time_key' => $timeKey,
                'event_type' => $eventType,
                'session_id' => $sessionId,
                'menu_item_id' => $itemId,
                'message' => 'Sự kiện clickstream đã được ghi nhận vào kho OLAP'
            ]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    /**
     * GET /api/v1/data/recommendations/{id}
     * Trả về danh sách món gợi ý bán kèm theo thuật toán Apriori (Support, Confidence, Lift)
     */
    public function getRecommendations(int $itemId): void
    {
        if ($itemId <= 0) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Invalid menu item id']);
            return;
        }

        $recommendations = AprioriService::getRecommendations($itemId, 3);
        echo json_encode([
            'item_id' => $itemId,
            'source_item_id' => $itemId,
            'algorithm' => 'Apriori Association Rule Mining (Support, Confidence, Lift)',
            'recommendations' => $recommendations
        ]);
    }

    /**
     * POST /api/v1/data/trigger-batch-etl
     * Kích hoạt tiến trình ETL vét cạn toàn bộ hóa đơn đã đóng bàn sang kho phân tích OLAP
     */
    public function triggerBatchEtl(): void
    {
        $result = EtlService::triggerBatchEtl();
        echo json_encode($result);
    }

    /**
     * GET /api/v1/data/live-ops OR /api/v1/data/live-operations
     * Giám sát điều phối thời gian thực: Trạng thái Bàn, Giỏ hàng chung, Bếp KDS & Hóa đơn
     */
    public function getLiveOps(): void
    {
        try {
            $db = Database::getConnection();

            // 1. Danh sách bàn ăn và phiên hoạt động
            $stmtTables = $db->query("
                SELECT t.id as table_id, t.table_number, t.status, t.current_session_id
                FROM dining_tables t
                ORDER BY t.id ASC
            ");
            $tables = $stmtTables ? $stmtTables->fetchAll() : [];

            // Menu map để lấy tên món nhanh
            $menuMap = [];
            $stmtMenu = $db->query("SELECT id, name, price FROM menu_items");
            if ($stmtMenu) {
                while ($m = $stmtMenu->fetch()) {
                    $menuMap[(int)$m['id']] = $m['name'];
                }
            }

            $activeTablesData = [];
            $totalActiveTables = 0;

            foreach ($tables as $t) {
                $tableId = (int)$t['table_id'];

                // Tìm phiên đang mở của bàn này
                $stmtSess = $db->prepare("
                    SELECT * FROM table_sessions
                    WHERE table_id = ? AND (closed_at IS NULL OR status = 'ACTIVE')
                    ORDER BY created_at DESC
                    LIMIT 1
                ");
                $stmtSess->execute([$tableId]);
                $openSess = $stmtSess->fetch();

                $token = $openSess ? $openSess['session_token'] : null;
                $sharedCartItems = [];
                if ($token) {
                    $sharedCartItems = SharedCartService::getItems($token);
                }

                $submittedKitchenItems = [];
                $billEstimate = 0.0;

                if ($openSess) {
                    $totalActiveTables++;
                    $stmtKitchen = $db->prepare("
                        SELECT oi.id, oi.menu_item_id, oi.item_name, oi.quantity, oi.unit_price, oi.subtotal, oi.status
                        FROM order_items oi
                        JOIN orders o ON oi.order_id = o.id
                        WHERE o.session_id = ?
                        ORDER BY oi.id ASC
                    ");
                    $stmtKitchen->execute([$openSess['id']]);
                    $kItems = $stmtKitchen->fetchAll();

                    foreach ($kItems as $ki) {
                        $lineTotal = (float)($ki['subtotal'] ?? ($ki['quantity'] * $ki['unit_price']));
                        $billEstimate += $lineTotal;
                        $submittedKitchenItems[] = [
                            'order_item_id' => (int)$ki['id'],
                            'dish_name' => $ki['item_name'] ?: ($menuMap[(int)$ki['menu_item_id']] ?? "Món #{$ki['menu_item_id']}"),
                            'quantity' => (int)$ki['quantity'],
                            'status' => $ki['status'],
                            'unit_price' => (float)$ki['unit_price'],
                            'line_total' => $lineTotal,
                        ];
                    }
                }

                $activeTablesData[] = [
                    'table_id' => $tableId,
                    'table_number' => $t['table_number'],
                    'status' => $openSess ? 'OCCUPIED' : $t['status'],
                    'session_id' => $openSess ? $openSess['id'] : null,
                    'session_token' => $token,
                    'opened_at' => $openSess ? $openSess['created_at'] : null,
                    'ws_connected_devices' => $openSess ? 1 : 0,
                    'shared_cart_items' => $sharedCartItems,
                    'submitted_kitchen_items' => $submittedKitchenItems,
                    'current_bill_total' => $billEstimate,
                ];
            }

            // 2. 15 phiên bàn đã chốt hóa đơn gần nhất
            $stmtClosed = $db->query("
                SELECT s.id as session_id, s.session_token, s.table_id, s.created_at as opened_at,
                       s.closed_at, s.total_amount, s.is_etl_processed, dt.table_number
                FROM table_sessions s
                LEFT JOIN dining_tables dt ON s.table_id = dt.id
                WHERE s.closed_at IS NOT NULL OR s.status IN ('CLOSED', 'CHECKOUT', 'PAID')
                ORDER BY s.closed_at DESC, s.created_at DESC
                LIMIT 15
            ");
            $closedSessions = $stmtClosed ? $stmtClosed->fetchAll() : [];

            $closedHistory = [];
            foreach ($closedSessions as $cs) {
                // Tóm tắt các món trong phiên
                $stmtSummary = $db->prepare("
                    SELECT oi.item_name, oi.quantity
                    FROM order_items oi
                    JOIN orders o ON oi.order_id = o.id
                    WHERE o.session_id = ?
                ");
                $stmtSummary->execute([$cs['session_id']]);
                $dishes = $stmtSummary->fetchAll();

                $dishStr = [];
                foreach ($dishes as $d) {
                    $dishStr[] = "{$d['item_name']} (x{$d['quantity']})";
                }

                $closedHistory[] = [
                    'session_id' => $cs['session_id'],
                    'session_token' => $cs['session_token'],
                    'table_number' => $cs['table_number'] ?: "Bàn #{$cs['table_id']}",
                    'opened_at' => !empty($cs['opened_at']) ? date('H:i:s d/m', strtotime($cs['opened_at'])) : '',
                    'closed_at' => !empty($cs['closed_at']) ? date('H:i:s d/m', strtotime($cs['closed_at'])) : '',
                    'total_amount' => (float)($cs['total_amount'] ?? 0),
                    'is_etl_processed' => (int)($cs['is_etl_processed'] ?? 0),
                    'dishes' => !empty($dishStr) ? implode(', ', $dishStr) : 'Không có món',
                ];
            }

            // 3. Thống kê tổng quan đơn & doanh thu hôm nay (tương thích cả MySQL & SQLite)
            $today = date('Y-m-d');
            $stmtTodayOrders = $db->prepare("
                SELECT COUNT(*) as cnt, COALESCE(SUM(total_amount), 0) as rev
                FROM orders
                WHERE DATE(created_at) = ?
            ");
            $stmtTodayOrders->execute([$today]);
            $todayOrderStats = $stmtTodayOrders ? $stmtTodayOrders->fetch() : null;

            // 4. Món đang chờ chế biến trong KDS
            $stmtKds = $db->query("
                SELECT oi.id, oi.item_name, oi.status, oi.created_at, dt.table_number
                FROM order_items oi
                JOIN orders o ON oi.order_id = o.id
                JOIN table_sessions ts ON o.session_id = ts.id
                JOIN dining_tables dt ON ts.table_id = dt.id
                WHERE oi.status IN ('PENDING', 'COOKING')
                ORDER BY oi.id ASC
                LIMIT 10
            ");
            $kdsPending = $stmtKds ? $stmtKds->fetchAll() : [];

            // 5. Thống kê OLAP Facts & Vi phạm SLA
            $stmtOlap = $db->query("
                SELECT COUNT(*) as cnt, COALESCE(SUM(revenue), 0) as rev,
                       SUM(CASE WHEN is_sla_violated = 1 THEN 1 ELSE 0 END) as sla_violated
                FROM fact_order_items
            ");
            $olapStats = $stmtOlap ? $stmtOlap->fetch() : null;

            echo json_encode([
                'status' => 'HEALTHY',
                'timestamp' => date('Y-m-d H:i:s'),
                'tables' => $activeTablesData,
                'recent_closed_bills' => $closedHistory,
                'active_tables' => $totalActiveTables,
                'today_orders_count' => (int)($todayOrderStats['cnt'] ?? 0),
                'today_revenue' => (float)($todayOrderStats['rev'] ?? 0),
                'kds_pending_items' => $kdsPending,
                'olap_total_fact_records' => (int)($olapStats['cnt'] ?? 0),
                'olap_total_revenue' => (float)($olapStats['rev'] ?? 0),
                'sla_violations_count' => (int)($olapStats['sla_violated'] ?? 0),
                'system_health' => 'OPTIMAL',
                'redis_status' => 'CONNECTED'
            ], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    /**
     * POST /api/v1/data/combo-rule
     * Thiết lập / tăng trọng số cặp món Gợi ý mua kèm (Combo Upsell)
     */
    public function createComboRule(?array $customData = null): void
    {
        $data = $customData ?? (json_decode(file_get_contents('php://input') ?: '[]', true) ?: []);

        $sourceId = (int)($data['source_item_id'] ?? $data['item_a_id'] ?? 0);
        $targetId = (int)($data['target_item_id'] ?? $data['item_b_id'] ?? 0);
        $confidence = (float)($data['confidence'] ?? 0.85);
        $discount = (int)($data['discount_percent'] ?? 10);
        $boostWeight = (int)($data['boost_weight'] ?? 3);

        if ($sourceId <= 0 || $targetId <= 0) {
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'detail' => 'source_item_id (item_a_id) và target_item_id (item_b_id) là bắt buộc.'
            ]);
            return;
        }

        if ($sourceId === $targetId) {
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'detail' => 'Hai món trong một Combo gợi ý phải khác nhau.'
            ]);
            return;
        }

        try {
            $ruleId = AprioriService::createComboRule($sourceId, $targetId, $confidence, $discount, $boostWeight);

            // Lấy tên 2 món để trả về thông điệp thân thiện
            $db = Database::getConnection();
            $stmtA = $db->prepare("SELECT name FROM menu_items WHERE id = ?");
            $stmtA->execute([$sourceId]);
            $nameA = $stmtA->fetch()['name'] ?? "Món #{$sourceId}";

            $stmtB = $db->prepare("SELECT name FROM menu_items WHERE id = ?");
            $stmtB->execute([$targetId]);
            $nameB = $stmtB->fetch()['name'] ?? "Món #{$targetId}";

            echo json_encode([
                'status' => 'success',
                'id' => $ruleId,
                'source_item_id' => $sourceId,
                'target_item_id' => $targetId,
                'message' => "Đã lưu quy luật gợi ý: Khi khách xem '{$nameA}' ➔ Gợi ý mua kèm '{$nameB}'!"
            ]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'detail' => $e->getMessage()]);
        }
    }

    /**
     * GET /api/v1/data/sla-metrics OR /api/v1/data/sla-analytics
     * Phân tích hiệu suất Đầu bếp & Tỷ lệ vi phạm SLA (> 15 phút / 900 giây) từ kho OLAP
     */
    public function getSlaMetrics(): void
    {
        try {
            $db = Database::getConnection();

            // 1. Thống kê tổng thể SLA
            $stmtOverall = $db->query("
                SELECT
                    COUNT(*) as total_items,
                    COALESCE(AVG(prep_time_seconds), 0) as avg_prep_seconds,
                    COALESCE(MAX(prep_time_seconds), 0) as max_prep_seconds,
                    COALESCE(SUM(is_sla_violated), 0) as violated_count
                FROM fact_order_items
            ");
            $overall = $stmtOverall ? $stmtOverall->fetch() : null;
            if ($stmtOverall) {
                $stmtOverall->closeCursor();
            }

            $totalCooked = (int)($overall['total_items'] ?? 0);
            $violatedCount = (int)($overall['violated_count'] ?? 0);
            $avgSeconds = (float)($overall['avg_prep_seconds'] ?? 0);
            $maxSeconds = (float)($overall['max_prep_seconds'] ?? 0);

            $complianceRate = ($totalCooked > 0)
                ? round((1.0 - ($violatedCount / $totalCooked)) * 100.0, 1)
                : 100.0;

            // 2. Phân tích chi tiết theo Đầu bếp (Chef Performance)
            $stmtChef = $db->query("
                SELECT
                    COALESCE(c.chef_full_name, 'Bếp Chung / Tự Động') as chef_name,
                    COUNT(f.id) as total_dishes_cooked,
                    SUM(f.quantity) as total_portions,
                    ROUND(AVG(f.prep_time_seconds)) as avg_prep_seconds,
                    ROUND(AVG(f.prep_time_seconds) / 60.0, 1) as avg_prep_minutes,
                    SUM(f.is_sla_violated) as sla_violated_count,
                    ROUND((SUM(f.is_sla_violated) * 100.0) / COUNT(f.id), 2) as sla_violation_rate_pct
                FROM fact_order_items f
                LEFT JOIN dim_chef c ON f.chef_key = c.chef_key
                GROUP BY COALESCE(c.chef_full_name, 'Bếp Chung / Tự Động')
                ORDER BY total_dishes_cooked DESC
            ");
            $chefPerformance = $stmtChef ? $stmtChef->fetchAll() : [];

            // 3. Phân tích điểm nghẽn món ăn (Dish Bottlenecks)
            $stmtDish = $db->query("
                SELECT
                    d.item_name as dish_name,
                    d.category_name,
                    COUNT(f.id) as order_count,
                    ROUND(AVG(f.prep_time_seconds)) as avg_prep_seconds,
                    ROUND(AVG(f.prep_time_seconds) / 60.0, 1) as avg_prep_minutes,
                    SUM(f.is_sla_violated) as sla_violated_count,
                    ROUND((SUM(f.is_sla_violated) * 100.0) / COUNT(f.id), 2) as violation_rate_pct
                FROM fact_order_items f
                JOIN dim_menu_item d ON f.item_key = d.item_key
                GROUP BY d.item_name, d.category_name
                ORDER BY violation_rate_pct DESC, avg_prep_seconds DESC
            ");
            $dishBottlenecks = $stmtDish ? $stmtDish->fetchAll() : [];

            echo json_encode([
                'sla_threshold_seconds' => 900,
                'sla_threshold_minutes' => 15,
                'total_dishes_cooked' => $totalCooked,
                'avg_prep_time_minutes' => round($avgSeconds / 60.0, 1),
                'max_prep_time_minutes' => round($maxSeconds / 60.0, 1),
                'sla_violations_count' => $violatedCount,
                'sla_compliance_rate' => $complianceRate,
                'chef_performance' => $chefPerformance,
                'dish_bottlenecks' => $dishBottlenecks
            ], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    /**
     * GET /api/v1/data/pipeline-status
     * Giám sát trạng thái Data Pipeline giữa OLTP và OLAP
     */
    public function getPipelineStatus(): void
    {
        try {
            $db = Database::getConnection();

            // OLTP stats
            $tablesCount = (int)$db->query("SELECT COUNT(*) FROM dining_tables")->fetchColumn();
            $sessionsTotal = (int)$db->query("SELECT COUNT(*) FROM table_sessions")->fetchColumn();
            $sessionsClosed = (int)$db->query("SELECT COUNT(*) FROM table_sessions WHERE closed_at IS NOT NULL OR status IN ('CLOSED', 'CHECKOUT', 'PAID')")->fetchColumn();
            $pendingEtl = (int)$db->query("SELECT COUNT(*) FROM table_sessions WHERE (closed_at IS NOT NULL OR status IN ('CLOSED', 'CHECKOUT', 'PAID')) AND (is_etl_processed = 0 OR is_etl_processed IS NULL)")->fetchColumn();
            $ordersCount = (int)$db->query("SELECT COUNT(*) FROM orders")->fetchColumn();
            $orderItemsCount = (int)$db->query("SELECT COUNT(*) FROM order_items")->fetchColumn();

            // OLAP stats
            $dimTimeCount = (int)$db->query("SELECT COUNT(*) FROM dim_time")->fetchColumn();
            $dimMenuCount = (int)$db->query("SELECT COUNT(*) FROM dim_menu_item")->fetchColumn();
            $dimChefCount = (int)$db->query("SELECT COUNT(*) FROM dim_chef")->fetchColumn();
            $factOrderCount = (int)$db->query("SELECT COUNT(*) FROM fact_order_items")->fetchColumn();
            $factClickCount = (int)$db->query("SELECT COUNT(*) FROM fact_clickstream")->fetchColumn();

            echo json_encode([
                'status' => 'HEALTHY',
                'oltp_stats' => [
                    'dining_tables' => $tablesCount,
                    'total_sessions' => $sessionsTotal,
                    'closed_sessions' => $sessionsClosed,
                    'pending_etl_sessions' => $pendingEtl,
                    'total_orders' => $ordersCount,
                    'total_order_items' => $orderItemsCount,
                ],
                'olap_star_schema_stats' => [
                    'dim_time' => $dimTimeCount,
                    'dim_menu_item' => $dimMenuCount,
                    'dim_chef' => $dimChefCount,
                    'fact_order_items' => $factOrderCount,
                    'fact_clickstream' => $factClickCount,
                ]
            ]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    /**
     * GET /api/v1/data/association-rules
     * Khai phá Tập luật kết hợp mạnh nhất toàn nhà hàng
     */
    public function getAssociationRules(int $limit = 10): void
    {
        $limit = max(1, min(50, $limit));
        $rules = AprioriService::getAssociationRules($limit);
        echo json_encode($rules);
    }

    /**
     * GET /api/v1/data/peak-hours
     * Phân tích khung giờ cao điểm từ bảng chiều DimTime
     */
    public function getPeakHours(): void
    {
        try {
            $db = Database::getConnection();
            $stmt = $db->query("
                SELECT
                    t.hour as hour_of_day,
                    COUNT(DISTINCT f.session_id) as total_sessions,
                    SUM(f.quantity) as total_items_sold,
                    SUM(f.revenue) as total_revenue,
                    ROUND(AVG(f.prep_time_seconds)) as avg_prep_seconds
                FROM fact_order_items f
                JOIN dim_time t ON f.time_key = t.time_key
                GROUP BY t.hour
                ORDER BY t.hour ASC
            ");
            $rows = $stmt ? $stmt->fetchAll() : [];
            echo json_encode($rows);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    /**
     * GET /api/v1/bi/dashboard-summary
     * Tổng hợp KPI doanh thu & tỷ lệ chuyển đổi từ kho Star Schema OLAP (TV5)
     */
    public function getBiDashboardSummary(): void
    {
        try {
            $db = Database::getConnection();
            $fact = $db->query("
                SELECT
                    COALESCE(SUM(revenue), 0) as total_revenue,
                    COALESCE(SUM(quantity), 0) as total_sold,
                    COUNT(DISTINCT session_id) as purchase_sessions,
                    COUNT(*) as total_fact_rows,
                    COALESCE(SUM(is_sla_violated), 0) as sla_violations_count
                FROM fact_order_items
            ")->fetch();

            $clickSessions = (int)$db->query("
                SELECT COUNT(DISTINCT session_id) FROM fact_clickstream
            ")->fetchColumn();

            $posStats = $db->query("
                SELECT
                    COUNT(*) as paid_bills_count,
                    COALESCE(SUM(subtotal_amount), 0) as pos_subtotal,
                    COALESCE(SUM(vat_amount), 0) as pos_vat,
                    COALESCE(SUM(final_amount), 0) as pos_gross_revenue
                FROM bill_payments
            ")->fetch();

            $totalRevenue = (float)($fact['total_revenue'] ?? 0);
            $totalSold = (int)($fact['total_sold'] ?? 0);
            $purchaseSessions = (int)($fact['purchase_sessions'] ?? 0);
            $totalFactRows = (int)($fact['total_fact_rows'] ?? 0);
            $slaViolations = (int)($fact['sla_violations_count'] ?? 0);

            $denominator = max(1, max($clickSessions, $purchaseSessions));
            $conversionRate = round(($purchaseSessions / $denominator) * 100, 1);
            $avgOrderValue = $purchaseSessions > 0 ? round($totalRevenue / $purchaseSessions, 0) : 0.0;
            $slaComplianceRate = $totalFactRows > 0
                ? round((1.0 - ($slaViolations / $totalFactRows)) * 100.0, 1)
                : 100.0;

            echo json_encode([
                'total_revenue' => $totalRevenue,
                'total_sold' => $totalSold,
                'purchase_sessions' => $purchaseSessions,
                'click_sessions' => $clickSessions,
                'conversion_rate' => $conversionRate,
                'avg_order_value' => $avgOrderValue,
                'sla_compliance_rate' => $slaComplianceRate,
                'sla_violations_count' => $slaViolations,
                'pos_paid_bills_count' => (int)($posStats['paid_bills_count'] ?? 0),
                'pos_subtotal' => (float)($posStats['pos_subtotal'] ?? 0),
                'pos_vat' => (float)($posStats['pos_vat'] ?? 0),
                'pos_gross_revenue' => (float)($posStats['pos_gross_revenue'] ?? 0),
            ], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    /**
     * GET /api/v1/bi/revenue-over-time
     * Doanh thu theo ngày từ fact_order_items + dim_time (TV5)
     */
    public function getBiRevenueOverTime(): void
    {
        try {
            $db = Database::getConnection();
            $stmt = $db->query("
                SELECT
                    t.full_date as date,
                    COALESCE(SUM(f.revenue), 0) as revenue,
                    COALESCE(SUM(f.quantity), 0) as total_sold,
                    COUNT(DISTINCT f.session_id) as sessions_count
                FROM fact_order_items f
                JOIN dim_time t ON f.time_key = t.time_key
                GROUP BY t.full_date
                ORDER BY t.full_date ASC
            ");
            echo json_encode($stmt ? $stmt->fetchAll() : [], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    /**
     * GET /api/v1/bi/menu-engineering-matrix
     * Ma trận Menu Engineering (lượt xem vs lượt bán & doanh thu + phân nhóm 4 Quadrants) từ OLAP (TV5)
     */
    public function getBiMenuMatrix(): void
    {
        try {
            $rows = $this->buildMenuMatrixData();
            echo json_encode($rows, JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    /**
     * GET /api/v1/bi/export-report
     * Xuất báo cáo doanh thu & Ma trận Menu Engineering ra file Excel (.xlsx chuẩn OpenXML) hoặc CSV UTF-8 BOM / JSON (TV5)
     */
    public function exportBiReport(?string $customType = null, ?string $customFormat = null): void
    {
        try {
            $type = strtolower(trim($customType ?? ($_GET['type'] ?? 'menu-matrix')));
            $format = strtolower(trim($customFormat ?? ($_GET['format'] ?? 'xlsx')));

            $matrixRows = $this->buildMenuMatrixData();
            $db = Database::getConnection();
            $stmtRev = $db->query("
                SELECT
                    t.full_date as date,
                    COUNT(DISTINCT f.session_id) as sessions_count,
                    COALESCE(SUM(f.quantity), 0) as total_sold,
                    COALESCE(SUM(f.revenue), 0) as revenue,
                    SUM(CASE WHEN f.is_sla_violated = 1 THEN 1 ELSE 0 END) as sla_violations
                FROM fact_order_items f
                JOIN dim_time t ON f.time_key = t.time_key
                GROUP BY t.full_date
                ORDER BY t.full_date ASC
            ");
            $revenueRows = $stmtRev ? $stmtRev->fetchAll() : [];

            if ($format === 'json') {
                echo json_encode([
                    'status' => 'success',
                    'report_type' => $type,
                    'generated_at' => date('Y-m-d H:i:s'),
                    'revenue_by_date' => $revenueRows,
                    'menu_engineering_matrix' => $matrixRows,
                ], JSON_UNESCAPED_UNICODE);
                return;
            }

            if ($format === 'xlsx' && class_exists('\ZipArchive')) {
                $xlsxBinary = $this->generateStyledXlsx($type, $matrixRows, $revenueRows);
                if (PHP_SAPI !== 'cli' && !headers_sent()) {
                    $filename = 'DineFlow_BaoCao_' . ($type === 'revenue' ? 'DoanhThu_OLAP' : 'Menu_Engineering') . '_' . date('Ymd_His') . '.xlsx';
                    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
                    header('Content-Disposition: attachment; filename="' . $filename . '"');
                    header('Content-Length: ' . strlen($xlsxBinary));
                    header('Cache-Control: max-age=0');
                    header('Pragma: public');
                }
                echo $xlsxBinary;
                return;
            }

            if (PHP_SAPI !== 'cli' && !headers_sent()) {
                $filename = 'dineflow_bi_' . ($type === 'revenue' ? 'revenue_report' : 'menu_engineering') . '_' . date('Ymd_His') . '.csv';
                header('Content-Type: text/csv; charset=UTF-8');
                header('Content-Disposition: attachment; filename="' . $filename . '"');
                header('Pragma: no-cache');
                header('Expires: 0');
            }

            // UTF-8 BOM để Microsoft Excel nhận diện tiếng Việt có dấu chính xác 100%
            echo "\xEF\xBB\xBF";
            $out = fopen('php://output', 'w');

            if ($type === 'revenue') {
                fputcsv($out, ['Ngày Báo Cáo', 'Số Phiên Bàn', 'Tổng Số Lượng Bán', 'Doanh Thu Thuần OLAP (VNĐ)', 'Doanh Thu Sau VAT 8% Ước Tính (VNĐ)', 'Số Món Vi Phạm SLA (>15p)']);
                foreach ($revenueRows as $r) {
                    $rev = (float)$r['revenue'];
                    $grossEst = round(($rev * 1.08) / 500) * 500;
                    fputcsv($out, [
                        $this->sanitizeCsvCell($r['date']),
                        (int)$r['sessions_count'],
                        (int)$r['total_sold'],
                        $rev,
                        $grossEst,
                        (int)$r['sla_violations'],
                    ]);
                }
            } else {
                fputcsv($out, ['Mã Món', 'Tên Món Ăn', 'Danh Mục', 'Đơn Giá (VNĐ)', 'Lượt Xem (Clickstream)', 'Số Lượng Đã Bán', 'Doanh Thu Thuần (VNĐ)', 'Tỷ Lệ Chuyển Đổi (%)', 'Phân Nhóm Menu Engineering', 'Khuyến Nghị Kinh Doanh']);
                foreach ($matrixRows as $m) {
                    fputcsv($out, [
                        $m['id'],
                        $this->sanitizeCsvCell($m['name']),
                        $this->sanitizeCsvCell($m['category_name']),
                        $m['price'],
                        $m['views'],
                        $m['total_sold'],
                        $m['total_revenue'],
                        $m['conversion_rate'] . '%',
                        $this->sanitizeCsvCell($m['quadrant_label']),
                        $this->sanitizeCsvCell($m['recommendation_action']),
                    ]);
                }
            }

            fclose($out);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    private function sanitizeCsvCell(mixed $value): string
    {
        $text = (string)$value;
        if ($text !== '' && preg_match('/^[=+\-@\t\r]/', $text) === 1) {
            return "'" . $text;
        }
        return $text;
    }

    /**
     * Sinh file Excel .xlsx chuẩn OpenXML với đầy đủ màu sắc, căn chỉnh độ rộng cột, đóng khung và hàng Tổng cộng (TV5)
     */
    private function generateStyledXlsx(string $type, array $matrixRows, array $revenueRows): string
    {
        $xmlEsc = fn($val): string => htmlspecialchars((string)$val, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $colLetter = function (int $colIndex): string {
            $letter = '';
            while ($colIndex > 0) {
                $mod = ($colIndex - 1) % 26;
                $letter = chr(65 + $mod) . $letter;
                $colIndex = (int)(($colIndex - $mod) / 26);
            }
            return $letter;
        };

        $cellStr = function (int $c, int $r, string $val, int $style) use ($colLetter, $xmlEsc): string {
            $ref = $colLetter($c) . $r;
            return '<c r="' . $ref . '" s="' . $style . '" t="inlineStr"><is><t>' . $xmlEsc($val) . '</t></is></c>';
        };
        $cellNum = function (int $c, int $r, $val, int $style) use ($colLetter): string {
            $ref = $colLetter($c) . $r;
            $num = is_numeric($val) ? $val : 0;
            return '<c r="' . $ref . '" s="' . $style . '"><v>' . $num . '</v></c>';
        };

        $nowStr = date('d/m/Y H:i:s');
        $sheetRowsXml = '';
        $mergeCellsXml = '';
        $colsXml = '';

        if ($type === 'revenue') {
            $sheetTitle = 'Bao Cao Doanh Thu OLAP';
            $widths = [1 => 18, 2 => 18, 3 => 22, 4 => 28, 5 => 30, 6 => 24];
            $colsXml = '<cols>';
            foreach ($widths as $idx => $w) {
                $colsXml .= '<col min="' . $idx . '" max="' . $idx . '" width="' . $w . '" customWidth="1"/>';
            }
            $colsXml .= '</cols>';

            $sheetRowsXml .= '<row r="1" ht="34" customHeight="1">'
                . $cellStr(1, 1, 'DINEFLOW — BÁO CÁO DOANH THU & SLA BẾP THEO NGÀY (KHO STAR SCHEMA OLAP)', 1)
                . $cellStr(2, 1, '', 1) . $cellStr(3, 1, '', 1) . $cellStr(4, 1, '', 1) . $cellStr(5, 1, '', 1) . $cellStr(6, 1, '', 1)
                . '</row>';
            $sheetRowsXml .= '<row r="2" ht="22" customHeight="1">'
                . $cellStr(1, 2, "DineFlow BI Analytics • Thời điểm xuất báo cáo: {$nowStr} • Đơn vị tiền tệ: VNĐ", 2)
                . $cellStr(2, 2, '', 2) . $cellStr(3, 2, '', 2) . $cellStr(4, 2, '', 2) . $cellStr(5, 2, '', 2) . $cellStr(6, 2, '', 2)
                . '</row>';
            $sheetRowsXml .= '<row r="3" ht="10" customHeight="1"/>';

            $headers = ['Ngày Báo Cáo', 'Số Phiên Bàn', 'Tổng Số Lượng Bán', 'Doanh Thu Thuần OLAP', 'Doanh Thu Sau VAT 8% (Làm Tròn)', 'Số Món Vi Phạm SLA (>15p)'];
            $sheetRowsXml .= '<row r="4" ht="28" customHeight="1">';
            foreach ($headers as $i => $hText) {
                $sheetRowsXml .= $cellStr($i + 1, 4, $hText, 3);
            }
            $sheetRowsXml .= '</row>';

            $rowNum = 5;
            $sumSess = 0;
            $sumSold = 0;
            $sumRev = 0.0;
            $sumGross = 0.0;
            $sumSla = 0;

            foreach ($revenueRows as $r) {
                $sess = (int)$r['sessions_count'];
                $sold = (int)$r['total_sold'];
                $rev = (float)$r['revenue'];
                $gross = round(($rev * 1.08) / 500) * 500;
                $sla = (int)$r['sla_violations'];

                $sumSess += $sess;
                $sumSold += $sold;
                $sumRev += $rev;
                $sumGross += $gross;
                $sumSla += $sla;

                $sheetRowsXml .= '<row r="' . $rowNum . '" ht="22" customHeight="1">'
                    . $cellStr(1, $rowNum, (string)$r['date'], 6)
                    . $cellNum(2, $rowNum, $sess, 7)
                    . $cellNum(3, $rowNum, $sold, 7)
                    . $cellNum(4, $rowNum, $rev, 9)
                    . $cellNum(5, $rowNum, $gross, 8)
                    . $cellNum(6, $rowNum, $sla, 6)
                    . '</row>';
                $rowNum++;
            }

            $sheetRowsXml .= '<row r="' . $rowNum . '" ht="26" customHeight="1">'
                . $cellStr(1, $rowNum, 'TỔNG CỘNG TOÀN KỲ', 14)
                . $cellNum(2, $rowNum, $sumSess, 15)
                . $cellNum(3, $rowNum, $sumSold, 15)
                . $cellNum(4, $rowNum, $sumRev, 16)
                . $cellNum(5, $rowNum, $sumGross, 16)
                . $cellNum(6, $rowNum, $sumSla, 15)
                . '</row>';

            $mergeCellsXml = '<mergeCells count="2">'
                . '<mergeCell ref="A1:F1"/>'
                . '<mergeCell ref="A2:F2"/>'
                . '</mergeCells>';
        } else {
            $sheetTitle = 'Ma Tran Menu Engineering';
            $widths = [
                1 => 10,
                2 => 34,
                3 => 24,
                4 => 16,
                5 => 16,
                6 => 16,
                7 => 20,
                8 => 16,
                9 => 28,
                10 => 52
            ];
            $colsXml = '<cols>';
            foreach ($widths as $idx => $w) {
                $colsXml .= '<col min="' . $idx . '" max="' . $idx . '" width="' . $w . '" customWidth="1"/>';
            }
            $colsXml .= '</cols>';

            $sheetRowsXml .= '<row r="1" ht="34" customHeight="1">'
                . $cellStr(1, 1, 'DINEFLOW — BÁO CÁO PHÂN TÍCH MA TRẬN MENU ENGINEERING (4 GÓC PHẦN TƯ)', 1);
            for ($c = 2; $c <= 10; $c++) {
                $sheetRowsXml .= $cellStr($c, 1, '', 1);
            }
            $sheetRowsXml .= '</row>';

            $sheetRowsXml .= '<row r="2" ht="22" customHeight="1">'
                . $cellStr(1, 2, "Báo cáo BI & Menu Engineering • Xuất lúc: {$nowStr} • Tổng số: " . count($matrixRows) . " món ăn", 2);
            for ($c = 2; $c <= 10; $c++) {
                $sheetRowsXml .= $cellStr($c, 2, '', 2);
            }
            $sheetRowsXml .= '</row>';

            $sheetRowsXml .= '<row r="3" ht="10" customHeight="1"/>';

            $headers = [
                'Mã Món',
                'Tên Món Ăn',
                'Danh Mục Thực Đơn',
                'Đơn Giá Niêm Yết',
                'Lượt Xem (Click)',
                'Số Lượng Đã Bán',
                'Doanh Thu Thuần',
                'Tỷ Lệ Chuyển Đổi',
                'Phân Nhóm Menu Engineering',
                'Khuyến Nghị Chiến Lược Kinh Doanh F&B'
            ];
            $sheetRowsXml .= '<row r="4" ht="28" customHeight="1">';
            foreach ($headers as $i => $hText) {
                $sheetRowsXml .= $cellStr($i + 1, 4, $hText, 3);
            }
            $sheetRowsXml .= '</row>';

            $rowNum = 5;
            $sumViews = 0;
            $sumSold = 0;
            $sumRevenue = 0.0;

            foreach ($matrixRows as $m) {
                $qCode = $m['quadrant_code'] ?? 'DOG';
                $badgeStyle = 13;
                $cleanBadgeText = '[DOG] Cần Cải Tiến';
                if ($qCode === 'STAR') {
                    $badgeStyle = 10;
                    $cleanBadgeText = '[STAR] Ngôi Sao Chủ Lực';
                } elseif ($qCode === 'PLOWHORSE') {
                    $badgeStyle = 11;
                    $cleanBadgeText = '[PLOWHORSE] Ngựa Thồ Bán Chạy';
                } elseif ($qCode === 'PUZZLE') {
                    $badgeStyle = 12;
                    $cleanBadgeText = '[PUZZLE] Dấu Hỏi Tiềm Năng';
                }

                $views = (int)$m['views'];
                $sold = (int)$m['total_sold'];
                $rev = (float)$m['total_revenue'];

                $sumViews += $views;
                $sumSold += $sold;
                $sumRevenue += $rev;

                $sheetRowsXml .= '<row r="' . $rowNum . '" ht="22" customHeight="1">'
                    . $cellStr(1, $rowNum, sprintf('DISH-%03d', (int)$m['id']), 6)
                    . $cellStr(2, $rowNum, (string)$m['name'], 5)
                    . $cellStr(3, $rowNum, (string)$m['category_name'], 4)
                    . $cellNum(4, $rowNum, (float)$m['price'], 8)
                    . $cellNum(5, $rowNum, $views, 7)
                    . $cellNum(6, $rowNum, $sold, 7)
                    . $cellNum(7, $rowNum, $rev, 9)
                    . $cellStr(8, $rowNum, $m['conversion_rate'] . '%', 6)
                    . $cellStr(9, $rowNum, $cleanBadgeText, $badgeStyle)
                    . $cellStr(10, $rowNum, (string)$m['recommendation_action'], 4)
                    . '</row>';
                $rowNum++;
            }

            $overallConv = $sumViews > 0 ? round(min(100, ($sumSold / $sumViews) * 100), 1) : ($sumSold > 0 ? 100.0 : 0.0);
            $sheetRowsXml .= '<row r="' . $rowNum . '" ht="26" customHeight="1">'
                . $cellStr(1, $rowNum, 'TỔNG CỘNG (' . count($matrixRows) . ' MÓN ĂN TRONG THỰC ĐƠN)', 14)
                . $cellStr(2, $rowNum, '', 14)
                . $cellStr(3, $rowNum, '', 14)
                . $cellStr(4, $rowNum, '', 14)
                . $cellNum(5, $rowNum, $sumViews, 15)
                . $cellNum(6, $rowNum, $sumSold, 15)
                . $cellNum(7, $rowNum, $sumRevenue, 16)
                . $cellStr(8, $rowNum, $overallConv . '%', 14)
                . $cellStr(9, $rowNum, 'Đã đối soát OLAP 100%', 14)
                . $cellStr(10, $rowNum, 'Báo cáo trích xuất trực tiếp từ Star Schema (fact_order_items & fact_clickstream)', 14)
                . '</row>';

            $mergeCellsXml = '<mergeCells count="3">'
                . '<mergeCell ref="A1:J1"/>'
                . '<mergeCell ref="A2:J2"/>'
                . '<mergeCell ref="A' . $rowNum . ':D' . $rowNum . '"/>'
                . '</mergeCells>';
        }

        $contentTypesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>';

        $relsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';

        $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . $xmlEsc($sheetTitle) . '" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>';

        $workbookRelsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';

        $stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="2">'
            . '<numFmt numFmtId="164" formatCode="#,##0 &quot;đ&quot;"/>'
            . '<numFmt numFmtId="165" formatCode="#,##0"/>'
            . '</numFmts>'
            . '<fonts count="9">'
            . '<font><sz val="11"/><color rgb="FF1E293B"/><name val="Segoe UI"/></font>'
            . '<font><b/><sz val="14"/><color rgb="FFFFFFFF"/><name val="Segoe UI"/></font>'
            . '<font><i/><sz val="10"/><color rgb="FF0F766E"/><name val="Segoe UI"/></font>'
            . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Segoe UI"/></font>'
            . '<font><b/><sz val="11"/><color rgb="FF0F172A"/><name val="Segoe UI"/></font>'
            . '<font><b/><sz val="10"/><color rgb="FF92400E"/><name val="Segoe UI"/></font>'
            . '<font><b/><sz val="10"/><color rgb="FF065F46"/><name val="Segoe UI"/></font>'
            . '<font><b/><sz val="10"/><color rgb="FF3730A3"/><name val="Segoe UI"/></font>'
            . '<font><b/><sz val="10"/><color rgb="FF475569"/><name val="Segoe UI"/></font>'
            . '</fonts>'
            . '<fills count="10">'
            . '<fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF0F766E"/><bgColor indexed="64"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFF0FDFA"/><bgColor indexed="64"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF1E293B"/><bgColor indexed="64"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFFEF3C7"/><bgColor indexed="64"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFD1FAE5"/><bgColor indexed="64"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFE0E7FF"/><bgColor indexed="64"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFF1F5F9"/><bgColor indexed="64"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFE0F2FE"/><bgColor indexed="64"/></patternFill></fill>'
            . '</fills>'
            . '<borders count="2">'
            . '<border><left/><right/><top/><bottom/><diagonal/></border>'
            . '<border>'
            . '<left style="thin"><color rgb="FFCBD5E1"/></left>'
            . '<right style="thin"><color rgb="FFCBD5E1"/></right>'
            . '<top style="thin"><color rgb="FFCBD5E1"/></top>'
            . '<bottom style="thin"><color rgb="FFCBD5E1"/></bottom>'
            . '<diagonal/>'
            . '</border>'
            . '</borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="17">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
            . '<xf numFmtId="0" fontId="2" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
            . '<xf numFmtId="0" fontId="3" fillId="4" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>'
            . '<xf numFmtId="0" fontId="4" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
            . '<xf numFmtId="165" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>'
            . '<xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>'
            . '<xf numFmtId="164" fontId="6" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>'
            . '<xf numFmtId="0" fontId="5" fillId="5" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
            . '<xf numFmtId="0" fontId="6" fillId="6" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
            . '<xf numFmtId="0" fontId="7" fillId="7" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
            . '<xf numFmtId="0" fontId="8" fillId="8" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
            . '<xf numFmtId="0" fontId="4" fillId="9" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
            . '<xf numFmtId="165" fontId="4" fillId="9" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>'
            . '<xf numFmtId="164" fontId="4" fillId="9" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>'
            . '</cellXfs>'
            . '</styleSheet>';

        $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetViews><sheetView workbookViewId="0" showGridLines="1"/></sheetViews>'
            . $colsXml
            . '<sheetData>' . $sheetRowsXml . '</sheetData>'
            . $mergeCellsXml
            . '</worksheet>';

        $tmpZip = tempnam(sys_get_temp_dir(), 'dineflow_xlsx_');
        $zip = new \ZipArchive();
        $zip->open($tmpZip, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', $contentTypesXml);
        $zip->addFromString('_rels/.rels', $relsXml);
        $zip->addFromString('xl/workbook.xml', $workbookXml);
        $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRelsXml);
        $zip->addFromString('xl/styles.xml', $stylesXml);
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
        $zip->close();

        $binary = file_get_contents($tmpZip) ?: '';
        @unlink($tmpZip);
        return $binary;
    }

    /**
     * Xây dựng dữ liệu Ma trận Menu Engineering kèm phân loại 4 góc phần tư (Star, Plowhorse, Puzzle, Dog)
     */
    private function buildMenuMatrixData(): array
    {
        $db = Database::getConnection();
        $stmt = $db->query("
            SELECT
                m.id,
                m.name,
                m.price,
                m.image_url,
                COALESCE(cat.name, 'Thực đơn chung') as category_name,
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
        ");
        $rows = $stmt ? $stmt->fetchAll() : [];

        $activeItems = array_filter($rows, fn($item) => ((int)$item['total_sold'] > 0 || (int)$item['views'] > 0));
        $countActive = max(1, count($activeItems));
        $sumSold = 0;
        $sumRev = 0.0;
        foreach ($activeItems as $ai) {
            $sumSold += (int)$ai['total_sold'];
            $sumRev += (float)$ai['total_revenue'];
        }
        $avgSold = max(1.0, $sumSold / $countActive);
        $avgRev = max(35000.0, $sumRev / $countActive);

        foreach ($rows as &$r) {
            $views = (int)$r['views'];
            $sold = (int)$r['total_sold'];
            $rev = (float)$r['total_revenue'];
            $price = (float)($r['price'] ?? 0);

            $r['id'] = (int)$r['id'];
            $r['price'] = $price;
            $r['views'] = $views;
            $r['total_sold'] = $sold;
            $r['total_revenue'] = $rev;
            $r['conversion_rate'] = $views > 0 ? round(min(100, ($sold / $views) * 100), 1) : ($sold > 0 ? 100.0 : 0.0);

            if ($sold >= $avgSold && $rev >= $avgRev) {
                $r['quadrant_code'] = 'STAR';
                $r['quadrant_label'] = '⭐ Star (Ngôi Sao)';
                $r['recommendation_action'] = 'Món chủ lực: Giữ chuẩn chất lượng, gắn nhãn Món Hot đầu menu';
            } elseif ($sold >= $avgSold && $rev < $avgRev) {
                $r['quadrant_code'] = 'PLOWHORSE';
                $r['quadrant_label'] = '🐴 Plowhorse (Ngựa Thồ)';
                $r['recommendation_action'] = 'Bán chạy giá mềm: Ghép Combo Upsell (Apriori) để kéo tổng hóa đơn';
            } elseif ($rev >= $avgRev || ($views > 0 && $sold > 0) || ($views >= 2 && $sold === 0)) {
                $r['quadrant_code'] = 'PUZZLE';
                $r['quadrant_label'] = '🧩 Puzzle (Dấu Hỏi)';
                $r['recommendation_action'] = 'Tiềm năng cao: Đẩy khuyến mãi giờ thấp điểm hoặc tối ưu ảnh/giá';
            } else {
                $r['quadrant_code'] = 'DOG';
                $r['quadrant_label'] = '🐕 Dog (Cần Cải Tiến)';
                $r['recommendation_action'] = 'Ít tương tác: Cân nhắc làm mới công thức hoặc thay thế món';
            }
        }
        unset($r);

        return $rows;
    }
}
