@echo off
rem Installs Pharmaco on this computer, or updates it. Double-click to run.
rem It asks Windows for administrator rights, then hands over to install.ps1.

net session >nul 2>&1
if %errorlevel% neq 0 (
    powershell -NoProfile -Command "Start-Process -FilePath '%~f0' -Verb RunAs"
    exit /b
)

powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0install.ps1"
echo.
pause
