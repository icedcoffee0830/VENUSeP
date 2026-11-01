@echo off
rem =====================================================================
rem  VENUSeP - run the testers (run-tests.bat) again and again until you stop it.
rem    Double-click it, then leave it. Stop: close this window or press Ctrl+C.
rem    Each run's result is added to test-loop-log.txt (next to this file).
rem    Only the newest 30 flow reports and page-checker reports are kept.
rem    Options are passed on, e.g.  run-tests-loop.bat --only=admin-settings
rem =====================================================================
setlocal
cd /d "%~dp0"
set "VENUSEP_NOOPEN=1"
set "VENUSEP_NOPAUSE=1"
set "LOG=%~dp0test-loop-log.txt"
set "OUT=%TEMP%\venusep-loop-run.txt"
set /a N=0
rem VENUSEP_LOOPS = stop after that many runs; 0 (the default) = run until the window is closed
if not defined VENUSEP_LOOPS set "VENUSEP_LOOPS=0"
echo ===== loop started %date% %time% >> "%LOG%"

:again
set /a N+=1
echo.
echo ===== Run %N% - %date% %time% (close this window to stop) =====
call "%~dp0run-tests.bat" %* > "%OUT%" 2>&1
findstr /C:"Page checker:" /C:"Flow tester:" /C:"MySQL is not running" "%OUT%"
for /f "delims=" %%l in ('findstr /C:"Page checker:" /C:"Flow tester:" /C:"MySQL is not running" "%OUT%"') do echo Run %N% %date% %time% %%l>> "%LOG%"

rem keep the newest 30 reports so the disk doesn't fill up overnight
for /f "skip=30 delims=" %%d in ('dir /b /ad /o-d "laravel\storage\app\venusep-flows" 2^>nul') do rd /s /q "laravel\storage\app\venusep-flows\%%d"
for /f "skip=30 delims=" %%f in ('dir /b /a-d /o-d "laravel\storage\app\venusep-check\*.html" 2^>nul') do del /q "laravel\storage\app\venusep-check\%%f"

if %VENUSEP_LOOPS% GTR 0 if %N% GEQ %VENUSEP_LOOPS% goto :done
timeout /t 30 /nobreak >nul
goto :again

:done
echo ===== loop stopped after %N% run(s) %date% %time% >> "%LOG%"
endlocal
