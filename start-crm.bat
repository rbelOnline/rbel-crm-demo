@echo off
REM RBEL-CRM local launcher: builds the SPA if needed and serves on http://localhost:8020
cd /d "%~dp0"
if not exist "vendor" call composer install
if not exist "node_modules" call npm install
if not exist "public\build\manifest.json" call npm run build
start "" http://localhost:8020
php artisan serve --host=localhost --port=8020
