@echo off
chcp 65001 >nul
title RxCore Sync Agent CLI Diagnostics

echo ======================================================
echo   RxCore Sync Agent — CLI Diagnostics Runner
echo ======================================================
echo.

if exist "C:\xampp\php\php.exe" (
    "C:\xampp\php\php.exe" "%~dp0agent_cli.php" --test
) else (
    php "%~dp0agent_cli.php" --test
)

echo.
echo ======================================================
pause
