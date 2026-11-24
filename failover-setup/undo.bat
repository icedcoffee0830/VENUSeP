@echo off
rem VENUSeP server transfer: put this laptop back the way it was (it asks for administrator rights).
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0failover-setup.ps1" -Role Undo
