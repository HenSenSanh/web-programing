from fastapi import APIRouter, Depends
from sqlalchemy import text
from sqlalchemy.orm import Session
from app.core.database import get_db

router = APIRouter(
    prefix="/api/v1/bi",
    tags=["P5 - BI Dashboard & Menu Matrix (TV5)"]
)


@router.get("/dashboard-summary")
def get_dashboard_summary(db: Session = Depends(get_db)):
    """[TV5] Tổng hợp chỉ số KPI toàn quán: Doanh thu, Lượt xem, Tỷ lệ chuyển đổi"""
    sql = text("""
        WITH click_stats AS (
            SELECT
                COUNT(DISTINCT session_id) AS total_view_sessions,
                COUNT(*) AS total_views
            FROM olap.fact_clickstream
            WHERE event_type = 'VIEW_ITEM'
        ),
        purchase_stats AS (
            SELECT
                COUNT(DISTINCT session_id) AS purchase_sessions,
                COALESCE(SUM(quantity), 0) AS total_sold,
                COALESCE(SUM(revenue), 0) AS total_revenue
            FROM olap.fact_order_items
        ),
        conversion_stats AS (
            SELECT COUNT(DISTINCT c.session_id) AS converted_sessions
            FROM olap.fact_clickstream c
            WHERE c.event_type = 'VIEW_ITEM'
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
            c.total_views,
            c.total_view_sessions,
            v.converted_sessions,
            CASE
                WHEN c.total_view_sessions = 0 THEN 0
                ELSE ROUND(
                    v.converted_sessions * 100.0
                    / c.total_view_sessions, 2
                )
            END AS conversion_rate
        FROM click_stats c
        CROSS JOIN purchase_stats p
        CROSS JOIN conversion_stats v;
    """)
    return db.execute(sql).mappings().one()


@router.get("/revenue-over-time")
def get_revenue_over_time(db: Session = Depends(get_db)):
    """[TV5] Chuỗi thời gian doanh thu & sản lượng bán theo từng ngày"""
    sql = text("""
        SELECT
            t.full_date AS date,
            COALESCE(SUM(f.revenue), 0) AS revenue,
            COALESCE(SUM(f.quantity), 0) AS total_sold
        FROM olap.fact_order_items f
        JOIN olap.dim_time t
            ON f.time_key = t.time_key
        GROUP BY t.full_date
        ORDER BY t.full_date;
    """)
    return db.execute(sql).mappings().all()


@router.get("/menu-engineering-matrix")
def get_menu_engineering_matrix(db: Session = Depends(get_db)):
    """[TV5] Ma trận Phân tích Thực đơn: So sánh Lượt xem (Clickstream) vs Lượt mua thực tế"""
    sql = text("""
        WITH click_stats AS (
            SELECT
                oltp_item_id,
                COUNT(*) AS total_views,
                COUNT(DISTINCT session_id) AS view_sessions
            FROM olap.fact_clickstream
            WHERE event_type = 'VIEW_ITEM'
            GROUP BY oltp_item_id
        ),
        sales AS (
            SELECT
                d.oltp_item_id,
                SUM(f.quantity) AS total_sold,
                SUM(f.revenue) AS total_revenue
            FROM olap.fact_order_items f
            JOIN olap.dim_menu_item d
                ON f.item_key = d.item_key
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
            WHERE c.event_type = 'VIEW_ITEM'
            GROUP BY c.oltp_item_id
        )
        SELECT
            d.oltp_item_id,
            d.name,
            COALESCE(cs.total_views, 0) AS views,
            COALESCE(cs.view_sessions, 0) AS view_sessions,
            COALESCE(s.total_sold, 0) AS total_sold,
            COALESCE(s.total_revenue, 0) AS total_revenue,
            COALESCE(cv.converted_sessions, 0) AS converted_sessions,
            CASE
                WHEN COALESCE(cs.view_sessions, 0) = 0 THEN 0
                ELSE ROUND(
                    COALESCE(cv.converted_sessions, 0) * 100.0
                    / cs.view_sessions, 2
                )
            END AS conversion_rate
        FROM olap.dim_menu_item d
        LEFT JOIN click_stats cs
            ON d.oltp_item_id = cs.oltp_item_id
        LEFT JOIN sales s
            ON d.oltp_item_id = s.oltp_item_id
        LEFT JOIN conversion_stats cv
            ON d.oltp_item_id = cv.oltp_item_id
        ORDER BY total_revenue DESC;
    """)
    return db.execute(sql).mappings().all()
