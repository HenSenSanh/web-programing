import os
from fastapi import FastAPI
from fastapi.responses import RedirectResponse, HTMLResponse
from fastapi.middleware.cors import CORSMiddleware
from app.core.database import engine, Base, init_schemas
from app.models import oltp_models, olap_models
from app.routers import (
    p1_admin_catalog,
    p2_client_order,
    p3_kds_pos,
    p4_data_pipeline,
    p5_bi_dashboard,
)

# Tự động tạo 2 schema (oltp, olap) và toàn bộ bảng khi chạy server
init_schemas()
Base.metadata.create_all(bind=engine)

app = FastAPI(title="DineFlow API - QR Order, KDS & F&B Analytics", version="1.0.0")

app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

@app.get("/", include_in_schema=False)
def root():
    """Tự động chuyển hướng về trang tài liệu Swagger UI khi vào đường dẫn gốc"""
    return RedirectResponse(url="/docs")

@app.get("/demo", include_in_schema=False)
def demo_page():
    """Giao diện mô phỏng kiểm thử E2E tương tác trực quan"""
    base_dir = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
    demo_file = os.path.join(base_dir, "demo_tester.html")
    if os.path.exists(demo_file):
        with open(demo_file, "r", encoding="utf-8") as f:
            return HTMLResponse(content=f.read())
    return HTMLResponse("<h3>demo_tester.html không tìm thấy</h3>", status_code=404)

app.include_router(p1_admin_catalog.router)
app.include_router(p2_client_order.router)
app.include_router(p3_kds_pos.router)
app.include_router(p4_data_pipeline.router)
app.include_router(p5_bi_dashboard.router)
