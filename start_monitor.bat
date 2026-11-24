@echo off
title Heizungsmonitor
cd /d "%~dp0"
:loop
"C:\xampp\php\php.exe" monitor.php
echo Monitor beendet - Neustart in 30 Sekunden (Fenster schliessen zum Beenden)
timeout /t 30 >nul
goto loop
