@echo off
cd /d "%~dp0"
if exist "frontend_p2" rmdir /s /q "frontend_p2"
if exist "data" rmdir /s /q "data"
if exist "frontend\dashboard.html" del /f /q "frontend\dashboard.html"
if exist "3_chay_menu_khach.bat" del /f /q "3_chay_menu_khach.bat"
if exist "4_nap_real_dataset.bat" del /f /q "4_nap_real_dataset.bat"
if exist "demo_tester.html" del /f /q "demo_tester.html"
if exist "HUONG_DAN_TV4_DATA_ENGINEERING_VA_REAL_DATASET.md" del /f /q "HUONG_DAN_TV4_DATA_ENGINEERING_VA_REAL_DATASET.md"
if exist "HUONG_DAN_NHIEM_VU_4_THANH_VIEN.md" del /f /q "HUONG_DAN_NHIEM_VU_4_THANH_VIEN.md"
if exist "TAI_LIEU_DOC_HIEU_TOAN_BO_DU_AN_DINEFLOW.md" del /f /q "TAI_LIEU_DOC_HIEU_TOAN_BO_DU_AN_DINEFLOW.md"
if exist "backend\app\scripts\ingest_real_dataset.py" del /f /q "backend\app\scripts\ingest_real_dataset.py"

cd /d "%~dp0backend"
echo =====================================================================
echo  DineFlow FastAPI Server dang chay (Full Frontend + Backend)...
echo  - Tai lieu API (Swagger):   http://localhost:8000/docs
echo  - TV1 Quan ly Ban va QR:    http://localhost:8000/frontend/tv1/table-management.html
echo  - TV1 Quan ly Thuc don:     http://localhost:8000/frontend/tv1/menu-management.html
echo  - TV2 Menu Khach Quet QR:   http://localhost:8000/frontend/menu/index.html
echo  - TV3 Man hinh Bep (KDS):   http://localhost:8000/frontend/kds.html
echo  - TV3 Thu ngan (POS):       http://localhost:8000/frontend/pos.html
echo  - TV4 Dieu phoi va Goi y:   http://localhost:8000/tv4
echo =====================================================================
echo.
.venv\Scripts\uvicorn.exe app.main:app --host 0.0.0.0 --port 8000
pause
