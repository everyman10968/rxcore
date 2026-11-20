@echo off
chcp 65001 >nul
if not exist "%~dp0..\logs" mkdir "%~dp0..\logs"
"c:\xampp\php\php.exe" "%~dp0moph_notify_runner.php" --daily-summary >> "%~dp0..\logs\moph_notify.log" 2>&1
exit /b %errorlevel%
