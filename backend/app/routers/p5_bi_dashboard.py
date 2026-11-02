from fastapi import APIRouter, Depends
from sqlalchemy import text
from sqlalchemy.orm import Session
from app.core.database import get_db

router = APIRouter(prefix="/api/v1/bi", tags=["P5 - BI Dashboard & Menu Matrix (TV5)"])

@router.get("/menu-engineering-matrix")
def get_menu_engineering_matrix(db: Session = Depends(get_db)):
    """[TV5] Ma trận Phân tích Thực đơn: So sánh Lượt xem (Clickstream) vs Lượt mua thực tế"""
    sql = text("""
        WITH views AS (
            SELECT oltp_item_id, COUNT(*) AS total_views
            FROM olap.fact_clickstream
            WHERE event_type = 'VIEW_ITEM'
            GROUP BY oltp_item_id
        ),
        sales AS (
            SELECT d.oltp_item_id, d.name, SUM(f.quantity) AS total_sold, SUM(f.revenue) AS total_revenue
            FROM olap.fact_order_items f
            JOIN olap.dim_menu_item d ON f.item_key = d.item_key
            GROUP BY d.oltp_item_id, d.name
        )
        SELECT 
            s.oltp_item_id,
            s.name,
            COALESCE(v.total_views, 0) AS views,
            s.total_sold,
            s.total_revenue
        FROM sales s
        LEFT JOIN views v ON s.oltp_item_id = v.oltp_item_id
        ORDER BY s.total_revenue DESC;
    """)
    return db.execute(sql).mappings().all()