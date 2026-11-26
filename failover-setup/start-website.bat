@echo off
rem VENUSeP: start the website so phones and the other laptops can open it (port 8100).
rem Start MySQL in the XAMPP panel first. Keep this window open; closing it stops the website.
title VENUSeP website (keep open)
set "PHP=%~dp0..\..\..\php\php.exe"
if not exist "%PHP%" set "PHP=php"
cd /d "%~dp0..\laravel"
"%PHP%" artisan serve --host=0.0.0.0 --port=8100
pause
