@echo off
rem ==================================================================
rem  AstraTrack - one-click start
rem  Starts (only if not already running): MySQL, Apache, PHP 8.5,
rem  the queue worker, then opens http://127.0.0.1:8080
rem ==================================================================
setlocal
set "XAMPP=C:\xampp"
set "PHPDIR=C:\Users\braga\AppData\Local\Programs\PHP\8.5.12"
set "APPDIR=%~dp0"
set "URL=http://127.0.0.1:8080"

echo.
echo  Starting AstraTrack...
echo.

rem ---- 1. MySQL (port 3306) ----
netstat -ano | findstr /R /C:":3306 .*LISTENING" >nul
if errorlevel 1 (
    echo  [MySQL]  starting...
    start "XAMPP MySQL" /min "%XAMPP%\mysql\bin\mysqld.exe" --defaults-file="%XAMPP%\mysql\bin\my.ini" --standalone
) else (
    echo  [MySQL]  already running
)

rem ---- 2. Apache (port 80 + 8080) ----
netstat -ano | findstr /R /C:":8080 .*LISTENING" >nul
if errorlevel 1 (
    echo  [Apache] starting...
    start "XAMPP Apache" /min "%XAMPP%\apache\bin\httpd.exe"
) else (
    echo  [Apache] already running
)

rem ---- 3. PHP 8.5 FastCGI (port 9085) ----
netstat -ano | findstr /R /C:":9085 .*LISTENING" >nul
if errorlevel 1 (
    echo  [PHP]    starting...
    start "AstraTrack PHP 8.5" /min cmd /c "set PHP_FCGI_MAX_REQUESTS=0&& "%PHPDIR%\php-cgi.exe" -b 127.0.0.1:9085"
) else (
    echo  [PHP]    already running
)

rem ---- 4. Queue worker (ID checks, email, SMS) ----
powershell -NoProfile -Command "if (Get-CimInstance Win32_Process -Filter \"Name='php.exe'\" | Where-Object { $_.CommandLine -like '*queue:work*' }) { exit 0 } else { exit 1 }"
if errorlevel 1 (
    echo  [Queue]  starting...
    start "AstraTrack Queue Worker" /D "%APPDIR%" "%PHPDIR%\php.exe" artisan queue:work --tries=3 --timeout=210
) else (
    echo  [Queue]  already running
)

rem ---- 5. Wait for the site, then open it ----
echo.
echo  Waiting for %URL% ...
set /a TRIES=0
:wait
set /a TRIES+=1
curl -s -o nul --max-time 3 %URL% && goto ready
if %TRIES% GEQ 20 goto failed
timeout /t 1 /nobreak >nul
goto wait

:ready
echo  AstraTrack is running: %URL%
start "" %URL%
echo.
echo  Keep the "AstraTrack Queue Worker" window open while you use the app.
echo  To stop: run stop-app.bat
timeout /t 5 >nul
exit /b 0

:failed
echo.
echo  The site did not respond after 20 seconds.
echo  Check the minimized XAMPP Apache / MySQL / PHP windows for errors,
echo  and C:\xampp\apache\logs\astra-error.log
pause
exit /b 1
