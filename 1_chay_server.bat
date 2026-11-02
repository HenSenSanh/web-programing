@echo off
chcp 65001 > nul
set PYTHONIOENCODING=utf-8
title DineFlow FastAPI Server
cd /d "D:\code\test Dl\backend"
echo =======================================================
echo  🚀 DineFlow FastAPI Server dang chay...
echo  Swagger UI:       http://localhost:8000/docs
echo  E2E Simulator UI: http://localhost:8000/demo
echo =======================================================
echo.
.venv\Scripts\uvicorn.exe app.main:app --host 0.0.0.0 --port 8000 --reload
pause
