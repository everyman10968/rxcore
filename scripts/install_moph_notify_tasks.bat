@echo off
setlocal enabledelayedexpansion
chcp 65001 >nul
title RxCore MOPH Notify — Install Daily Summary Task

echo ============================================================
echo   RxCore MOPH Notify — Server Daily Summary Setup
echo ============================================================
echo.
echo งานที่จะตั้งเวลาบนเครื่อง Server แม่ข่าย:
echo   RxCoreMophNotify_DailySummary : สรุปยอดประจำวันทั้งเครือข่าย เวลา 17:30 น.
echo   (ส่วนการแจ้งเตือนผลการ Sync รายหน่วยบริการจะส่งตรงจากเครื่อง รพ.สต.)
echo.

set "DAILY_BAT=%~dp0moph_notify_daily.bat"
set "DAILY_TIME=17:30"
if not "%~1"=="" set "DAILY_TIME=%~1"

REM ลบ Task SyncCheck เดิม (ถ้าเคยติดตั้งไว้) เพื่อไม่ให้แจ้งเตือนซ้ำซ้อนกับ รพ.สต.
schtasks /Delete /F /TN "RxCoreMophNotify_SyncCheck" >nul 2>&1

schtasks /Create /F /TN "RxCoreMophNotify_DailySummary" /TR "\"%DAILY_BAT%\"" /SC DAILY /ST %DAILY_TIME% /RL HIGHEST >nul 2>&1
if errorlevel 1 (
    schtasks /Create /F /TN "RxCoreMophNotify_DailySummary" /TR "\"%DAILY_BAT%\"" /SC DAILY /ST %DAILY_TIME%
    if errorlevel 1 (
        echo [ERROR] กรุณาคลิกขวาแล้วเลือก "Run as administrator"
        pause
        exit /b 1
    )
)

echo [SUCCESS] ตั้งเวลาส่งสรุปประจำวันสำเร็จแล้ว!
echo   Task Name : RxCoreMophNotify_DailySummary
echo   Schedule  : ทุกวัน เวลา %DAILY_TIME% น.
echo   Script    : %DAILY_BAT%
echo.
pause
