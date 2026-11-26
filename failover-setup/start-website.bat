@echo off
rem VENUSeP: start the website. Two addresses, the same site and data:
rem   http://localhost:8000  on this laptop itself: "Continue with Google" works only here
rem                          (Google accepts localhost, never a hotspot address)
rem   port 8100              for phones and the other laptops on the hotspot
rem Start MySQL first (start-venusep.bat does). Keep this window open; closing it stops port 8100.
title VENUSeP website (keep open)
set "PHP=%~dp0..\..\..\php\php.exe"
if not exist "%PHP%" set "PHP=php"
cd /d "%~dp0..\laravel"
start "VENUSeP website for this laptop (localhost:8000, keep open)" /min "%PHP%" artisan serve --port=8000
"%PHP%" artisan serve --host=0.0.0.0 --port=8100
pause
