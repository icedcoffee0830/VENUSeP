@echo off
rem =====================================================================
rem  VENUSeP - run both testers on the Laravel app, then open the reports.
rem    Double-click it, or from cmd:  run-tests.bat --headed   (watch the browser)
rem    Anything after the name is passed to the flow tester, e.g. --only=venue-booking-to-receipt
rem  1. Page checker  (php artisan venusep:check) - every page as guest/customer/staff/admin
rem  2. Flow tester   (php artisan venusep:flows) - real browser through each process
rem  Both use their own database copies; the real "venusep" database is never touched.
rem =====================================================================
setlocal
cd /d "%~dp0laravel"
set "PHP=C:\xamp5\php\php.exe"

echo.
echo === VENUSeP tests ===
"C:\xamp5\mysql\bin\mysqladmin.exe" -u root ping >nul 2>&1
if errorlevel 1 (
  echo MySQL is not running. Start it in the XAMPP Control Panel, then run this again.
  goto :end
)

echo.
echo [1/2] Page checker - every page as guest, customer, staff and admin (about 20 seconds)
"%PHP%" artisan venusep:check --db=venusep_laravel_test
set "CHECK=%errorlevel%"

echo.
echo [2/2] Flow tester - a real browser walks through each process (a few minutes)
"%PHP%" artisan venusep:flows %*
set "FLOWS=%errorlevel%"

echo.
echo === Summary ===
if "%CHECK%"=="0" (echo   Page checker: PASS) else (echo   Page checker: problems found - see its report)
if "%FLOWS%"=="0" (echo   Flow tester:  PASS) else (echo   Flow tester:  problems found - see its report)

if defined VENUSEP_NOOPEN goto :end
for /f "delims=" %%f in ('dir /b /o-d "storage\app\venusep-check\*.html" 2^>nul') do (
  start "" "storage\app\venusep-check\%%f"
  goto :flowreport
)
:flowreport
for /f "delims=" %%d in ('dir /b /ad /o-d "storage\app\venusep-flows" 2^>nul') do (
  if exist "storage\app\venusep-flows\%%d\report.html" start "" "storage\app\venusep-flows\%%d\report.html"
  goto :end
)

:end
echo.
if not defined VENUSEP_NOPAUSE pause
endlocal
