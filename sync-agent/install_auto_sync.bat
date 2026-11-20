@echo off
setlocal enabledelayedexpansion
chcp 65001 >nul
title RxCore Sync Agent — Install Windows Task Scheduler

echo ============================================================
echo   RxCore Sync Agent — Auto-Sync Task Scheduler Setup
echo ============================================================
echo.
echo ระบบจะตั้งเวลาให้ดึงข้อมูลย้อนหลัง 7 วัน + กระทบยอดอัตโนมัติ
echo เวลาทำงานประจำวัน: 22:00 น. (ไม่ต้องเปิดหน้าเว็บทิ้งไว้)
echo.

set "TASK_NAME=RxCoreSyncAgent_Daily"
set "SYNC_BAT=%~dp0auto_sync.bat"
set "SYNC_TIME=22:00"
if not "%~1"=="" set "SYNC_TIME=%~1"

schtasks /Create /F /TN "%TASK_NAME%" /TR "\"%SYNC_BAT%\"" /SC DAILY /ST %SYNC_TIME% /RL HIGHEST >nul 2>&1
if errorlevel 1 (
    schtasks /Create /F /TN "%TASK_NAME%" /TR "\"%SYNC_BAT%\"" /SC DAILY /ST %SYNC_TIME%
    if errorlevel 1 (
        echo.
        echo [ERROR] ไม่สามารถสร้าง Task ได้ กรุณาคลิกขวาแล้วเลือก "Run as administrator"
        pause
        exit /b 1
    )
)

echo [SUCCESS] ตั้งเวลา Auto-Sync สำเร็จแล้ว!
echo   Task Name : %TASK_NAME%
echo   Schedule  : ทุกวัน เวลา %SYNC_TIME% น.
echo   Script    : %SYNC_BAT%
echo.
echo (สามารถแก้ไขเวลาใน Task Scheduler หรือส่งเวลาใหม่เป็นพารามิเตอร์ เช่น install_auto_sync.bat 17:30)
echo.
pause
