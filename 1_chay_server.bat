@echo off
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
echo  - TV5 BI / E2E Simulator:   http://localhost:8000/demo
echo =====================================================================
echo.
.venv\Scripts\uvicorn.exe app.main:app --host 0.0.0.0 --port 8000
pause
