@echo off
setlocal enabledelayedexpansion
chcp 65001 >nul

REM Check Portable PHP runtime or XAMPP PHP
if exist "%~dp0runtime\php\php.exe" (
    set "PHP_BIN=%~dp0runtime\php\php.exe"
) else if exist "c:\xampp\php\php.exe" (
    set "PHP_BIN=c:\xampp\php\php.exe"
) else (
    set "PHP_BIN=php.exe"
)

if not exist "%~dp0logs" mkdir "%~dp0logs"
"%PHP_BIN%" "%~dp0agent_cli.php" --sync --days=7 --reconcile >> "%~dp0logs\auto_sync_task.log" 2>&1
exit /b %errorlevel%
