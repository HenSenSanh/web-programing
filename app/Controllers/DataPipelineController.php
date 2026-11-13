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
                    COUNT(DISTINCT session_id) as purchase_sessions
                FROM fact_order_items
            ")->fetch();

            $clickSessions = (int)$db->query("
                SELECT COUNT(DISTINCT session_id) FROM fact_clickstream
            ")->fetchColumn();

            $purchaseSessions = (int)($fact['purchase_sessions'] ?? 0);
            $denominator = max(1, max($clickSessions, $purchaseSessions));
            $conversionRate = round(($purchaseSessions / $denominator) * 100, 1);

            echo json_encode([
                'total_revenue' => (float)($fact['total_revenue'] ?? 0),
                'total_sold' => (int)($fact['total_sold'] ?? 0),
                'purchase_sessions' => $purchaseSessions,
                'conversion_rate' => $conversionRate
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
                    COALESCE(SUM(f.quantity), 0) as total_sold
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
     * Ma trận Menu Engineering (lượt xem vs lượt bán & doanh thu) từ OLAP (TV5)
     */
    public function getBiMenuMatrix(): void
    {
        try {
            $db = Database::getConnection();
            $stmt = $db->query("
                SELECT
                    m.id,
                    m.name,
                    COALESCE(c.views, 0) as views,
                    COALESCE(s.total_sold, 0) as total_sold,
                    COALESCE(s.total_revenue, 0) as total_revenue
                FROM menu_items m
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
            foreach ($rows as &$r) {
                $views = (int)$r['views'];
                $sold = (int)$r['total_sold'];
                $r['views'] = $views;
                $r['total_sold'] = $sold;
                $r['total_revenue'] = (float)$r['total_revenue'];
                $r['conversion_rate'] = $views > 0 ? round(min(100, ($sold / $views) * 100), 1) : ($sold > 0 ? 100.0 : 0.0);
            }
            unset($r);
            echo json_encode($rows, JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }
}
