@echo off
rem VENUSeP: an https address for phones, through a free Cloudflare quick tunnel.
rem Needs internet on this laptop (mobile data on the hotspot) and the website on
rem http://localhost:8000 (start-venusep.bat starts it). The address changes every time.
rem Keep this window open: closing it stops the tunnel.
title VENUSeP tunnel for phones (keep open)
set "CF=cloudflared"
where cloudflared >nul 2>nul || set "CF=%ProgramFiles(x86)%\cloudflared\cloudflared.exe"
if not exist "%CF%" if not "%CF%"=="cloudflared" (
  echo cloudflared is not installed yet. Installing it now ^(about a minute^)...
  winget install --id Cloudflare.cloudflared -e --accept-source-agreements --accept-package-agreements
  echo.
  echo Installed. Close this window and double-click start-tunnel.bat again.
  pause
  exit /b
)
echo.
echo  Starting the tunnel to http://localhost:8000 ...
echo  In a few seconds a box shows an address like https://word-word-word.trycloudflare.com
echo  Phones open that address, for example .../customer/customer-login
echo.
"%CF%" tunnel --url http://localhost:8000
pause
