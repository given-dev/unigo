@echo off
setlocal
cd /d "%~dp0"
set "UNIGO_PHP=C:\xampp\php\php.exe"
if not exist "%UNIGO_PHP%" (
  echo PHP was not found at C:\xampp\php\php.exe. Install XAMPP first.
  pause
  exit /b 1
)
if not exist "%~dp0scripts\router.php" (
  echo The UniGo checkout is incomplete. Update this folder from GitHub first.
  pause
  exit /b 1
)
echo Keep MySQL running in XAMPP. Open http://127.0.0.1:8000 in your browser.
echo Press Ctrl+C to stop UniGo.
"%UNIGO_PHP%" -S 127.0.0.1:8000 -t "%~dp0public" "%~dp0scripts\router.php"
if errorlevel 1 pause
