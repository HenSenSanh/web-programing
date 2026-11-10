from typing import Optional
from fastapi import APIRouter, Depends, Query
from sqlalchemy import text
from sqlalchemy.orm import Session
from app.core.database import get_db

router = APIRouter(
    prefix="/api/v1/bi",
    tags=["P5 - BI Dashboard & Menu Matrix (TV5)"]
)


def _build_time_filter_clause(days: Optional[int], weekend_only: Optional[bool], table_alias: str = "t") -> tuple[str, dict]:
    """Hàm phụ trợ tạo mệnh đề lọc theo thời gian (DimTime) cho các truy vấn OLAP."""
    conditions = []
    params = {}

    if days is not None and days > 0:
        conditions.append(
            f"{table_alias}.full_date >= (SELECT MAX(full_date) - CAST(:days_offset AS INTEGER) FROM olap.dim_time)"
        )
        params["days_offset"] = days - 1

    if weekend_only is not None:
        conditions.append(f"{table_alias}.is_weekend = :weekend_only")
        params["weekend_only"] = weekend_only

    if not conditions:
        return "", params

    return " AND " + " AND ".join(conditions), params


# 1. DASHBOARD SUMMARY (KPI TỔNG HỢP + AOV + SLA BẾP)
@router.get("/dashboard-summary")
def get_dashboard_summary(
    days: Optional[int] = Query(None, description="Lọc theo N ngày gần nhất (VD: 7, 14)"),
    weekend_only: Optional[bool] = Query(None, description="True: chỉ cuối tuần, False: chỉ ngày thường"),
    db: Session = Depends(get_db)
):
    time_filter, params = _build_time_filter_clause(days, weekend_only, "t")

    sql = text(f"""
        WITH click_stats AS (
            SELECT
                COUNT(DISTINCT c.session_id) AS total_view_sessions,
                COUNT(*) AS total_views
            FROM olap.fact_clickstream c
            LEFT JOIN olap.dim_time t ON c.time_key = t.time_key
            WHERE c.event_type = 'VIEW_ITEM' {time_filter}
        ),
        purchase_stats AS (
            SELECT
                COUNT(DISTINCT f.session_id) AS purchase_sessions,
                COALESCE(SUM(f.quantity), 0) AS total_sold,
                COALESCE(SUM(f.revenue), 0) AS total_revenue,
                COALESCE(ROUND(AVG(f.prep_time_seconds), 0), 0) AS avg_prep_time_seconds,
                COUNT(*) AS total_order_lines,
                SUM(CASE WHEN f.is_sla_violated IS TRUE THEN 1 ELSE 0 END) AS sla_violated_count
            FROM olap.fact_order_items f
            LEFT JOIN olap.dim_time t ON f.time_key = t.time_key
            WHERE 1=1 {time_filter}
        ),
        conversion_stats AS (
            SELECT COUNT(DISTINCT c.session_id) AS converted_sessions
            FROM olap.fact_clickstream c
            LEFT JOIN olap.dim_time t ON c.time_key = t.time_key
            WHERE c.event_type = 'VIEW_ITEM' {time_filter}
              AND EXISTS (
                  SELECT 1
                  FROM olap.fact_order_items f
                  WHERE f.session_id = c.session_id
              )
        )
        SELECT
            p.total_revenue,
            p.total_sold,
            p.purchase_sessions,
            CASE
                WHEN p.purchase_sessions = 0 THEN 0
                ELSE ROUND(CAST(p.total_revenue AS NUMERIC) / p.purchase_sessions, 0)
            END AS avg_order_value,
            c.total_views,
            c.total_view_sessions,
            v.converted_sessions,
            CASE
                WHEN c.total_view_sessions = 0 THEN 0
                ELSE ROUND(
                    v.converted_sessions * 100.0
                    / c.total_view_sessions, 2
                )
            END AS conversion_rate,
            p.avg_prep_time_seconds,
            COALESCE(p.sla_violated_count, 0) AS sla_violated_count,
            CASE
                WHEN p.total_order_lines = 0 THEN 0
                ELSE ROUND(
                    COALESCE(p.sla_violated_count, 0) * 100.0
                    / p.total_order_lines, 2
                )
            END AS sla_violation_rate,
            CASE
                WHEN p.total_order_lines = 0 THEN 100
                ELSE ROUND(
                    100.0 - (COALESCE(p.sla_violated_count, 0) * 100.0 / p.total_order_lines), 2
                )
            END AS sla_compliance_rate
        FROM click_stats c
        CROSS JOIN purchase_stats p
        CROSS JOIN conversion_stats v;
    """)

    return db.execute(sql, params).mappings().one()


# 2. REVENUE OVER TIME (DOANH THU & SẢN LƯỢNG THEO NGÀY)
@router.get("/revenue-over-time")
def get_revenue_over_time(
    days: Optional[int] = Query(None, description="Lọc theo N ngày gần nhất"),
    weekend_only: Optional[bool] = Query(None, description="True: chỉ cuối tuần, False: chỉ ngày thường"),
    db: Session = Depends(get_db)
):
    time_filter, params = _build_time_filter_clause(days, weekend_only, "t")

    sql = text(f"""
        SELECT
            t.full_date AS date,
            t.day_of_week,
            t.is_weekend,
            COALESCE(SUM(f.revenue), 0) AS revenue,
            COALESCE(SUM(f.quantity), 0) AS total_sold,
            COUNT(DISTINCT f.session_id) AS sessions
        FROM olap.fact_order_items f
        JOIN olap.dim_time t
            ON f.time_key = t.time_key
        WHERE 1=1 {time_filter}
        GROUP BY t.full_date, t.day_of_week, t.is_weekend
        ORDER BY t.full_date;
    """)

    return db.execute(sql, params).mappings().all()


# 3. MENU ENGINEERING MATRIX (PHÂN TÍCH MA TRẬN THỰC ĐƠN 4 GÓC PHẦN TƯ)
@router.get("/menu-engineering-matrix")
def get_menu_engineering_matrix(
    days: Optional[int] = Query(None, description="Lọc theo N ngày gần nhất"),
    weekend_only: Optional[bool] = Query(None, description="True: chỉ cuối tuần, False: chỉ ngày thường"),
    category: Optional[str] = Query(None, description="Lọc theo tên danh mục (VD: Món Chính)"),
    db: Session = Depends(get_db)
):
    time_filter, params = _build_time_filter_clause(days, weekend_only, "t")
    cat_filter = ""
    if category:
        cat_filter = "WHERE d.category_name = :category"
        params["category"] = category

    sql = text(f"""
        WITH click_stats AS (
            SELECT
                c.oltp_item_id,
                COUNT(*) AS total_views,
                COUNT(DISTINCT c.session_id) AS view_sessions
            FROM olap.fact_clickstream c
            LEFT JOIN olap.dim_time t ON c.time_key = t.time_key
            WHERE c.event_type = 'VIEW_ITEM' {time_filter}
            GROUP BY c.oltp_item_id
        ),
        sales AS (
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
            LEFT JOIN olap.dim_time t
                ON f.time_key = t.time_key
            WHERE 1=1 {time_filter}
            GROUP BY d.oltp_item_id
        ),
        conversion_stats AS (
            SELECT
                c.oltp_item_id,
                COUNT(DISTINCT c.session_id) AS converted_sessions
            FROM olap.fact_clickstream c
            JOIN olap.dim_menu_item d
                ON c.oltp_item_id = d.oltp_item_id
            JOIN olap.fact_order_items f
                ON f.item_key = d.item_key
                AND f.session_id = c.session_id
            LEFT JOIN olap.dim_time t
                ON c.time_key = t.time_key
            WHERE c.event_type = 'VIEW_ITEM' {time_filter}
            GROUP BY c.oltp_item_id
        )
        SELECT
            d.oltp_item_id,
            d.name,
            d.category_name,
            d.current_price,
            COALESCE(cs.total_views, 0) AS views,
            COALESCE(cs.view_sessions, 0) AS view_sessions,
            COALESCE(s.total_sold, 0) AS total_sold,
            COALESCE(s.total_revenue, 0) AS total_revenue,
            COALESCE(s.purchase_sessions, 0) AS purchase_sessions,
            COALESCE(cv.converted_sessions, 0) AS converted_sessions,
            CASE
                WHEN COALESCE(cs.view_sessions, 0) = 0 THEN 0
                ELSE ROUND(
                    COALESCE(cv.converted_sessions, 0) * 100.0
                    / cs.view_sessions, 2
                )
            END AS conversion_rate,
            COALESCE(s.avg_prep_seconds, 0) AS avg_prep_seconds,
            COALESCE(s.sla_violation_rate, 0) AS sla_violation_rate
        FROM olap.dim_menu_item d
        LEFT JOIN click_stats cs
            ON d.oltp_item_id = cs.oltp_item_id
        LEFT JOIN sales s
            ON d.oltp_item_id = s.oltp_item_id
        LEFT JOIN conversion_stats cv
            ON d.oltp_item_id = cv.oltp_item_id
        {cat_filter}
        ORDER BY total_revenue DESC;
    """)

    rows = [dict(r) for r in db.execute(sql, params).mappings().all()]
    if not rows:
        return []

    # Tính ngưỡng trung bình / trung vị toàn thực đơn để phân loại 4 góc phần tư cân đối
    avg_views = sum(float(r["views"] or 0) for r in rows) / len(rows)
    avg_revenue = sum(float(r["total_revenue"] or 0) for r in rows) / len(rows)
    avg_sold = sum(float(r["total_sold"] or 0) for r in rows) / len(rows)
    sorted_revs = sorted(float(r["total_revenue"] or 0) for r in rows)
    median_revenue = sorted_revs[len(sorted_revs) // 2]
    avg_conversion = sum(float(r["conversion_rate"] or 0) for r in rows) / len(rows)

    for r in rows:
        high_interest = float(r["views"] or 0) >= avg_views
        high_value = (float(r["total_revenue"] or 0) >= median_revenue) or (
            float(r["total_sold"] or 0) >= avg_sold
        )

        if high_interest and high_value:
            r["quadrant"] = "STAR"
            r["quadrant_label"] = "Ngôi Sao (Star)"
            r["recommendation"] = "Món chủ lực! Giữ vững chất lượng, ưu tiên hiển thị đầu trang và dùng làm món dẫn khách."
        elif not high_interest and high_value:
            r["quadrant"] = "PUZZLE"
            r["quadrant_label"] = "Tiềm Năng Ẩn (Puzzle)"
            r["recommendation"] = "Tỷ lệ chốt/doanh thu tốt nhưng ít lượt xem. Hãy gắn nhãn 'Hot', đẩy lên banner hoặc gợi ý mua kèm."
        elif high_interest and not high_value:
            r["quadrant"] = "PLOWHORSE"
            r["quadrant_label"] = "Bẫy Lướt Xem (Cash Trap)"
            r["recommendation"] = "Khách xem nhiều nhưng ít đặt hoặc doanh thu thấp. Cần tối ưu lại giá bán, hình ảnh hoặc tạo combo."
        else:
            r["quadrant"] = "DOG"
            r["quadrant_label"] = "Kém Hiệu Quả (Dog)"
            r["recommendation"] = "Lượt xem và doanh thu đều thấp. Cân nhắc cải tiến công thức, đổi tên món hoặc thay thế khỏi menu."

        r["benchmark_avg_views"] = round(avg_views, 1)
        r["benchmark_avg_revenue"] = round(avg_revenue, 0)
        r["benchmark_avg_conversion"] = round(avg_conversion, 2)

    return rows


# 4. CATEGORY BREAKDOWN (CƠ CẤU DOANH THU & SẢN LƯỢNG THEO DANH MỤC)
@router.get("/category-breakdown")
def get_category_breakdown(
    days: Optional[int] = Query(None, description="Lọc theo N ngày gần nhất"),
    weekend_only: Optional[bool] = Query(None, description="True: chỉ cuối tuần, False: chỉ ngày thường"),
    db: Session = Depends(get_db)
):
    time_filter, params = _build_time_filter_clause(days, weekend_only, "t")

    sql = text(f"""
        WITH cat_sales AS (
            SELECT
                d.category_name,
                COUNT(DISTINCT d.oltp_item_id) AS item_count,
                COALESCE(SUM(f.quantity), 0) AS total_sold,
                COALESCE(SUM(f.revenue), 0) AS total_revenue
            FROM olap.dim_menu_item d
            LEFT JOIN olap.fact_order_items f
                ON d.item_key = f.item_key
            LEFT JOIN olap.dim_time t
                ON f.time_key = t.time_key
            WHERE 1=1 {time_filter}
            GROUP BY d.category_name
        ),
        total_all AS (
            SELECT COALESCE(SUM(total_revenue), 0) AS grand_revenue
            FROM cat_sales
        )
        SELECT
            cs.category_name,
            cs.item_count,
            cs.total_sold,
            cs.total_revenue,
            CASE
                WHEN ta.grand_revenue = 0 THEN 0
                ELSE ROUND(CAST(cs.total_revenue * 100.0 / ta.grand_revenue AS NUMERIC), 2)
            END AS revenue_share
        FROM cat_sales cs
        CROSS JOIN total_all ta
        ORDER BY cs.total_revenue DESC;
    """)

    return db.execute(sql, params).mappings().all()


# 5. PEAK HOURS & DAY OF WEEK HEATMAP (PHÂN TÍCH KHUNG GIỜ CAO ĐIỂM)
@router.get("/peak-hours-heatmap")
def get_peak_hours_heatmap(
    days: Optional[int] = Query(None, description="Lọc theo N ngày gần nhất"),
    db: Session = Depends(get_db)
):
    time_filter, params = _build_time_filter_clause(days, None, "t")

    sql_by_hour = text(f"""
        SELECT
            t.hour,
            COALESCE(SUM(f.revenue), 0) AS revenue,
            COALESCE(SUM(f.quantity), 0) AS total_sold,
            COUNT(DISTINCT f.session_id) AS sessions
        FROM olap.fact_order_items f
        JOIN olap.dim_time t ON f.time_key = t.time_key
        WHERE 1=1 {time_filter}
        GROUP BY t.hour
        ORDER BY t.hour;
    """)

    sql_by_dow = text(f"""
        SELECT
            t.day_of_week,
            t.is_weekend,
            COALESCE(SUM(f.revenue), 0) AS revenue,
            COALESCE(SUM(f.quantity), 0) AS total_sold,
            COUNT(DISTINCT f.session_id) AS sessions
        FROM olap.fact_order_items f
        JOIN olap.dim_time t ON f.time_key = t.time_key
        WHERE 1=1 {time_filter}
        GROUP BY t.day_of_week, t.is_weekend
        ORDER BY t.day_of_week;
    """)

    sql_matrix = text(f"""
        SELECT
            t.day_of_week,
            t.hour,
            COALESCE(SUM(f.revenue), 0) AS revenue,
            COALESCE(SUM(f.quantity), 0) AS total_sold,
            COUNT(DISTINCT f.session_id) AS sessions
        FROM olap.fact_order_items f
        JOIN olap.dim_time t ON f.time_key = t.time_key
        WHERE 1=1 {time_filter}
        GROUP BY t.day_of_week, t.hour
        ORDER BY t.day_of_week, t.hour;
    """)

    dow_names = {
        0: "Thứ 2",
        1: "Thứ 3",
        2: "Thứ 4",
        3: "Thứ 5",
        4: "Thứ 6",
        5: "Thứ 7",
        6: "Chủ Nhật"
    }

    by_hour = [dict(r) for r in db.execute(sql_by_hour, params).mappings().all()]
    by_dow = []
    for r in db.execute(sql_by_dow, params).mappings().all():
        item = dict(r)
        item["day_name"] = dow_names.get(item["day_of_week"], f"Ngày {item['day_of_week']}")
        by_dow.append(item)

    heatmap = []
    for r in db.execute(sql_matrix, params).mappings().all():
        item = dict(r)
        item["day_name"] = dow_names.get(item["day_of_week"], f"Ngày {item['day_of_week']}")
        heatmap.append(item)

    return {
        "by_hour": by_hour,
        "by_day_of_week": by_dow,
        "heatmap": heatmap
    }


# 6. KITCHEN SLA & CHEF PERFORMANCE (HIỆU SUẤT ĐẦU BẾP & SLA 15 PHÚT)
@router.get("/kitchen-sla-performance")
def get_kitchen_sla_performance(
    days: Optional[int] = Query(None, description="Lọc theo N ngày gần nhất"),
    weekend_only: Optional[bool] = Query(None, description="True: chỉ cuối tuần, False: chỉ ngày thường"),
    db: Session = Depends(get_db)
):
    time_filter, params = _build_time_filter_clause(days, weekend_only, "t")

    sql_chefs = text(f"""
        SELECT
            c.chef_key,
            c.full_name,
            COUNT(f.id) AS total_order_lines,
            COALESCE(SUM(f.quantity), 0) AS total_items_cooked,
            COALESCE(SUM(f.revenue), 0) AS total_revenue_generated,
            COALESCE(ROUND(AVG(f.prep_time_seconds), 0), 0) AS avg_prep_seconds,
            SUM(CASE WHEN f.is_sla_violated IS TRUE THEN 1 ELSE 0 END) AS sla_violated_count,
            CASE
                WHEN COUNT(f.id) = 0 THEN 0
                ELSE ROUND(
                    SUM(CASE WHEN f.is_sla_violated IS TRUE THEN 1 ELSE 0 END) * 100.0
                    / COUNT(f.id), 2
                )
            END AS sla_violation_rate,
            CASE
                WHEN COUNT(f.id) = 0 THEN 100
                ELSE ROUND(
                    100.0 - (SUM(CASE WHEN f.is_sla_violated IS TRUE THEN 1 ELSE 0 END) * 100.0 / COUNT(f.id)), 2
                )
            END AS sla_compliance_rate
        FROM olap.dim_chef c
        LEFT JOIN olap.fact_order_items f
            ON c.chef_key = f.chef_key
        LEFT JOIN olap.dim_time t
            ON f.time_key = t.time_key
        WHERE 1=1 {time_filter}
        GROUP BY c.chef_key, c.full_name
        ORDER BY total_items_cooked DESC;
    """)

    sql_slowest_items = text(f"""
        SELECT
            d.oltp_item_id,
            d.name,
            d.category_name,
            COUNT(f.id) AS order_count,
            COALESCE(ROUND(AVG(f.prep_time_seconds), 0), 0) AS avg_prep_seconds,
            COALESCE(MAX(f.prep_time_seconds), 0) AS max_prep_seconds,
            SUM(CASE WHEN f.is_sla_violated IS TRUE THEN 1 ELSE 0 END) AS sla_violated_count,
            CASE
                WHEN COUNT(f.id) = 0 THEN 0
                ELSE ROUND(
                    SUM(CASE WHEN f.is_sla_violated IS TRUE THEN 1 ELSE 0 END) * 100.0
                    / COUNT(f.id), 2
                )
            END AS sla_violation_rate
        FROM olap.fact_order_items f
        JOIN olap.dim_menu_item d ON f.item_key = d.item_key
        LEFT JOIN olap.dim_time t ON f.time_key = t.time_key
        WHERE f.prep_time_seconds IS NOT NULL {time_filter}
        GROUP BY d.oltp_item_id, d.name, d.category_name
        ORDER BY avg_prep_seconds DESC;
    """)

    return {
        "sla_threshold_seconds": 900,
        "chefs": db.execute(sql_chefs, params).mappings().all(),
        "items_prep_analysis": db.execute(sql_slowest_items, params).mappings().all()
    }
