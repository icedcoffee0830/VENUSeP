@echo off
rem VENUSeP: the team's fixed https address for phones (ngrok), Google sign-in included.
rem Needs internet on this laptop (mobile data on the hotspot) and the website on
rem http://localhost:8000 (start-venusep.bat starts it). Only one laptop runs this at a time.
rem The first time on a laptop it asks for the ngrok token (ngrok keeps it).
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0failover-setup.ps1" -Role Tunnel
