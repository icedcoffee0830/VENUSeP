@echo off
rem VENUSeP server transfer: set up laptop B (it asks for administrator rights). See SETUP.md, "Two or more laptops".
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0failover-setup.ps1" -Role B
