@echo off
rem Stops Pharmaco starting with this computer. It removes nothing else:
rem C:\Pharmaco and the database in it - the books - are left where they are.

net session >nul 2>&1
if %errorlevel% neq 0 (
    powershell -NoProfile -Command "Start-Process -FilePath '%~f0' -Verb RunAs"
    exit /b
)

schtasks /End /TN "Pharmaco Server" >nul 2>&1
schtasks /Delete /TN "Pharmaco Server" /F >nul 2>&1
schtasks /Delete /TN "Pharmaco Scheduler" /F >nul 2>&1
netsh advfirewall firewall delete rule name="Pharmaco" >nul 2>&1
del "%PUBLIC%\Desktop\Pharmaco.url" >nul 2>&1
del "%PUBLIC%\Desktop\Pharmaco Till (prints directly).lnk" >nul 2>&1
rem The "pharmaco" name for this computer, out of Windows' hosts file.
powershell -NoProfile -Command "$h=\"$env:SystemRoot\System32\drivers\etc\hosts\"; (Get-Content $h) | Where-Object { $_ -notmatch '^\s*127\.0\.0\.1\s+pharmaco(\s|$)' } | Set-Content -Encoding ASCII $h" >nul 2>&1

echo Pharmaco no longer starts by itself.
echo The application and the books in C:\Pharmaco were not touched.
echo The database service PharmacoDB is still installed; it holds the books.
echo.
pause
