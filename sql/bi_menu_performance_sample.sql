-- ============================================================================
-- DINEFLOW - BI & ANALYTICS SQL SUITE (OLAP STAR SCHEMA)
-- TV5: BI Dashboard, Menu Engineering Matrix, Peak Hours & Kitchen SLA
-- ============================================================================

-- 1. PHÂN TÍCH MA TRẬN THỰC ĐƠN (MENU ENGINEERING MATRIX - 4 GÓC PHẦN TƯ)
WITH click_stats AS (
    SELECT
        oltp_item_id,
        COUNT(*) AS total_views,
        COUNT(DISTINCT session_id) AS view_sessions
    FROM olap.fact_clickstream
    WHERE event_type = 'VIEW_ITEM'
    GROUP BY oltp_item_id
),
order_stats AS (
    SELECT
        d.oltp_item_id,
        SUM(f.quantity) AS total_sold,
        SUM(f.revenue) AS total_revenue,
        COUNT(DISTINCT f.session_id) AS purchase_sessions,
        ROUND(AVG(f.prep_time_seconds), 0) AS avg_prep_seconds,
        ROUND(
            SUM(CASE WHEN f.is_sla_violated IS TRUE THEN 1 ELSE 0 END) * 100.0
            / NULLIF(COUNT(*), 0), 2
        ) AS sla_violation_rate
    FROM olap.fact_order_items f
    JOIN olap.dim_menu_item d
        ON f.item_key = d.item_key
    GROUP BY d.oltp_item_id
),
conversion_stats AS (
    SELECT
        d.oltp_item_id,
        COUNT(DISTINCT c.session_id) AS converted_sessions
    FROM olap.fact_clickstream c
    JOIN olap.dim_menu_item d
        ON c.oltp_item_id = d.oltp_item_id
    JOIN olap.fact_order_items f
        ON f.item_key = d.item_key
       AND f.session_id = c.session_id
    WHERE c.event_type = 'VIEW_ITEM'
    GROUP BY d.oltp_item_id
),
item_metrics AS (
    SELECT
        m.oltp_item_id,
        m.name AS menu_item,
        m.category_name,
        m.current_price,
        COALESCE(cs.total_views, 0) AS total_views,
        COALESCE(cs.view_sessions, 0) AS view_sessions,
        COALESCE(os.total_sold, 0) AS total_sold,
        COALESCE(os.total_revenue, 0) AS total_revenue,
        COALESCE(os.purchase_sessions, 0) AS purchase_sessions,
        COALESCE(cv.converted_sessions, 0) AS converted_sessions,
        CASE
            WHEN COALESCE(cs.view_sessions, 0) = 0 THEN 0
            ELSE ROUND(
                COALESCE(cv.converted_sessions, 0) * 100.0
                / cs.view_sessions,
                2
            )
        END AS conversion_rate,
        COALESCE(os.avg_prep_seconds, 0) AS avg_prep_seconds,
        COALESCE(os.sla_violation_rate, 0) AS sla_violation_rate
    FROM olap.dim_menu_item m
    LEFT JOIN click_stats cs
        ON m.oltp_item_id = cs.oltp_item_id
    LEFT JOIN order_stats os
        ON m.oltp_item_id = os.oltp_item_id
    LEFT JOIN conversion_stats cv
        ON m.oltp_item_id = cv.oltp_item_id
),
benchmarks AS (
    SELECT
        AVG(total_views) AS avg_views,
        AVG(total_revenue) AS avg_revenue
    FROM item_metrics
)
SELECT
    im.*,
    CASE
        WHEN im.total_views >= b.avg_views AND im.total_revenue >= b.avg_revenue THEN 'STAR (Ngôi Sao)'
        WHEN im.total_views < b.avg_views AND im.total_revenue >= b.avg_revenue THEN 'PUZZLE (Tiềm Năng Ẩn)'
        WHEN im.total_views >= b.avg_views AND im.total_revenue < b.avg_revenue THEN 'PLOWHORSE (Bẫy Lướt Xem)'
        ELSE 'DOG (Kém Hiệu Quả)'
    END AS menu_quadrant
FROM item_metrics im
CROSS JOIN benchmarks b
ORDER BY im.total_revenue DESC;


-- 2. PHÂN TÍCH KHUNG GIỜ CAO ĐIỂM & THỨ TRONG TUẦN (PEAK HOURS HEATMAP)
SELECT
    t.day_of_week,
    t.is_weekend,
    t.hour,
    COUNT(DISTINCT f.session_id) AS total_sessions,
    SUM(f.quantity) AS total_sold,
    SUM(f.revenue) AS total_revenue
FROM olap.fact_order_items f
JOIN olap.dim_time t ON f.time_key = t.time_key
GROUP BY t.day_of_week, t.is_weekend, t.hour
ORDER BY t.day_of_week, t.hour;


-- 3. PHÂN TÍCH HIỆU SUẤT ĐẦU BẾP & VI PHẠM SLA (> 15 PHÚT = 900 GIÂY)
SELECT
    c.chef_key,
    c.full_name,
    COUNT(f.id) AS total_orders_handled,
    COALESCE(SUM(f.quantity), 0) AS total_items_cooked,
    COALESCE(ROUND(AVG(f.prep_time_seconds), 0), 0) AS avg_prep_seconds,
    SUM(CASE WHEN f.is_sla_violated IS TRUE THEN 1 ELSE 0 END) AS sla_violated_count,
    ROUND(
        100.0 - (
            SUM(CASE WHEN f.is_sla_violated IS TRUE THEN 1 ELSE 0 END) * 100.0
            / NULLIF(COUNT(f.id), 0)
        ),
        2
    ) AS sla_compliance_rate
FROM olap.dim_chef c
LEFT JOIN olap.fact_order_items f ON c.chef_key = f.chef_key
GROUP BY c.chef_key, c.full_name
ORDER BY total_items_cooked DESC;
