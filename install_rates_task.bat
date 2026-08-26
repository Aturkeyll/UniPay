@echo off
REM ---------------------------------------------------------------------------
REM install_rates_task.bat: registers the hourly rate refresh with Windows
REM Task Scheduler. This is the Windows equivalent of the cron line:
REM
REM   0 * * * * /usr/bin/php /path/to/lib_rates.php >> /var/log/unipay-rates.log
REM
REM Run this ONCE. Right-click -> "Run as administrator" is not required if the
REM task runs as you; it IS required for /ru SYSTEM (see the note at the bottom).
REM ---------------------------------------------------------------------------

set "UNIPAY_DIR=C:\xampp\htdocs\UniPay"
set "TASK_NAME=UniPay Rate Refresh"

if not exist "%UNIPAY_DIR%\refresh_rates.bat" (
    echo ERROR: refresh_rates.bat not found in %UNIPAY_DIR%
    echo Edit UNIPAY_DIR at the top of this file.
    pause
    exit /b 1
)

echo Registering scheduled task "%TASK_NAME%"...
echo   Runs: every hour
echo   Command: %UNIPAY_DIR%\refresh_rates.bat /quiet
echo.

schtasks /create ^
    /tn "%TASK_NAME%" ^
    /tr "\"%UNIPAY_DIR%\refresh_rates.bat\" /quiet" ^
    /sc hourly ^
    /st 00:05 ^
    /f

if errorlevel 1 (
    echo.
    echo Registration failed. If the error mentions access, re-run this file
    echo with "Run as administrator".
    pause
    exit /b 1
)

echo.
echo Registered. Running it once now to populate the cache...
call "%UNIPAY_DIR%\refresh_rates.bat"

echo.
echo Useful commands:
echo   schtasks /query /tn "%TASK_NAME%" /v /fo LIST    ^(check status + last result^)
echo   schtasks /run   /tn "%TASK_NAME%"                ^(trigger it now^)
echo   schtasks /delete /tn "%TASK_NAME%" /f            ^(remove it^)
echo.
echo NOTE: by default this task only runs while you are logged in. That is
echo usually fine for a demo laptop. To run regardless, re-create it with
echo /ru SYSTEM from an administrator prompt:
echo.
echo   schtasks /create /tn "%TASK_NAME%" /tr "\"%UNIPAY_DIR%\refresh_rates.bat\" /quiet" /sc hourly /ru SYSTEM /f
echo.
pause
