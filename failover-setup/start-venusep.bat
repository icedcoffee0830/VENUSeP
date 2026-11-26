@echo off
rem VENUSeP: start a test session on this laptop. One double-click: MySQL, the website, finding
rem the other laptops on the hotspot (new addresses are fine), and the Server page.
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0failover-setup.ps1" -Role Start
