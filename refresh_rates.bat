@echo off
REM ---------------------------------------------------------------------------
REM refresh_rates.bat: Windows/XAMPP replacement for the cron job.
REM
REM Edit the two paths below if your setup differs, then either:
REM   - double-click this file to refresh rates once, or
REM   - register it with Task Scheduler (see install_rates_task.bat).
REM
REM Writes to cache\rates.log so you can see what the scheduled run did.
REM ---------------------------------------------------------------------------

set "PHP_EXE=C:\xampp\php\php.exe"
set "UNIPAY_DIR=C:\xampp\htdocs\UniPay"

set "SCRIPT=%UNIPAY_DIR%\lib_rates.php"
set "LOGFILE=%UNIPAY_DIR%\cache\rates.log"

if not exist "%PHP_EXE%" (
    echo ERROR: PHP not found at %PHP_EXE%
    echo Edit PHP_EXE at the top of this file to match your XAMPP install.
    pause
    exit /b 1
)

if not exist "%SCRIPT%" (
    echo ERROR: lib_rates.php not found at %SCRIPT%
    echo Edit UNIPAY_DIR at the top of this file to match your repo location.
    pause
    exit /b 1
)

if not exist "%UNIPAY_DIR%\cache" mkdir "%UNIPAY_DIR%\cache"

"%PHP_EXE%" "%SCRIPT%" >> "%LOGFILE%" 2>&1
set "RESULT=%ERRORLEVEL%"

REM Echo the last line so a double-click shows the outcome without opening the log.
for /f "usebackq delims=" %%L in (`powershell -NoProfile -Command "Get-Content '%LOGFILE%' -Tail 2"`) do echo %%L

if not "%RESULT%"=="0" (
    echo.
    echo Refresh FAILED - see %LOGFILE%
    REM Pause only when double-clicked, not when run by Task Scheduler.
    if /i "%~1" NEQ "/quiet" pause
)

exit /b %RESULT%
