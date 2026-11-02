@echo off
chcp 65001 > nul
set PYTHONIOENCODING=utf-8
title DineFlow E2E Test Suite
cd /d "%~dp0"
echo =======================================================
echo  ĐANG CHẠY KIỂM THỬ TOÀN LUỒNG DINEFLOW
echo =======================================================
echo.
backend\.venv\Scripts\python.exe test_e2e.py
echo.
pause
