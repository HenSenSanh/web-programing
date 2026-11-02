import os
from dotenv import load_dotenv
from sqlalchemy import create_engine, text
from sqlalchemy.orm import sessionmaker, declarative_base
import redis

# Tải cấu hình từ .env nếu có
load_dotenv()

DATABASE_URL = os.getenv(
    "DATABASE_URL", 
    "postgresql://dineflow_user:dineflow_password@localhost:5432/dineflow_db"
)
REDIS_URL = os.getenv("REDIS_URL", "redis://localhost:6379/0")

engine = create_engine(DATABASE_URL, pool_pre_ping=True, pool_size=20, max_overflow=10)
SessionLocal = sessionmaker(autocommit=False, autoflush=False, bind=engine)
Base = declarative_base()

# Kết nối Redis dùng cho Giỏ hàng chung (Shared Cart), Queue Clickstream & Cache
redis_client = redis.Redis.from_url(REDIS_URL, decode_responses=True)

def init_schemas():
    """Tự động tạo 2 schema tách biệt: OLTP (Giao dịch) và OLAP (Phân tích)"""
    with engine.connect() as conn:
        conn.execute(text("CREATE SCHEMA IF NOT EXISTS oltp;"))
        conn.execute(text("CREATE SCHEMA IF NOT EXISTS olap;"))
        conn.commit()

def get_db():
    db = SessionLocal()
    try:
        yield db
    finally:
        db.close()
