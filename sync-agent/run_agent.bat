@echo off
chcp 65001 >nul
title RxCore Sync Agent Launcher

echo ======================================================
echo   RxCore Sync Agent — Windows Launcher (Phase 3.1)
echo ======================================================
echo.
echo กำลังเปิดโปรแกรม RxCore Sync Agent บน Web Browser...
echo.

:: ตรวจสอบและเปิด URL บนเบราว์เซอร์
start http://localhost/rxcore/sync-agent/

echo เปิดโปรแกรมสำเร็จ!
echo URL: http://localhost/rxcore/sync-agent/
echo.
echo (หากเปิดหน้าเว็บไม่ได้ กรุณาตรวจสอบว่า Apache ใน XAMPP Control Panel กำลังทำงาน)
echo.
timeout /t 5 >nul
