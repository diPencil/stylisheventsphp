@echo off
setlocal
cd /d "%~dp0"

echo Starting Stylish Holidays backend on http://localhost:5000 ...
start "Stylish Holidays Backend" /D "%~dp0backend" cmd /k "php artisan serve --host=127.0.0.1 --port=5000"

echo Starting Stylish Holidays frontend on http://localhost:3002 ...
start "Stylish Holidays Frontend" /D "%~dp0frontend" cmd /k "npm.cmd run dev"

echo.
echo Open http://localhost:3002/admin/
echo Keep both opened terminal windows running while testing locally.
pause
endlocal
