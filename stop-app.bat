@echo off
rem ==================================================================
rem  AstraTrack - stop
rem  Stops the queue worker and PHP 8.5. Apache and MySQL keep running
rem  (other XAMPP sites may use them); stop those in the XAMPP Control
rem  Panel if you want, or run:  stop-app.bat all
rem ==================================================================
setlocal

echo  [Queue]  stopping...
powershell -NoProfile -Command "Get-CimInstance Win32_Process -Filter \"Name='php.exe'\" | Where-Object { $_.CommandLine -like '*queue:work*' } | ForEach-Object { Stop-Process -Id $_.ProcessId -Force }"

echo  [PHP]    stopping...
taskkill /F /IM php-cgi.exe >nul 2>&1

if /I "%~1"=="all" (
    echo  [Apache] stopping...
    taskkill /F /IM httpd.exe >nul 2>&1
    echo  [MySQL]  stopping safely...
    "C:\xampp\mysql\bin\mysqladmin.exe" -u root shutdown >nul 2>&1
)

echo  Done.
timeout /t 3 >nul
