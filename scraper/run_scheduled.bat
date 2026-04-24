@echo off
REM Mamboleo scraper — Task Scheduler wrapper
REM Usage:   run_scheduled.bat fast   OR   run_scheduled.bat slow
REM Loads env from scraper/.env (via config.py), logs to data/scheduler.log

setlocal
set CADENCE=%~1
if "%CADENCE%"=="" set CADENCE=fast

set SCRAPER_DIR=%~dp0
cd /d "%SCRAPER_DIR%"

set LOGDIR=%SCRAPER_DIR%data
if not exist "%LOGDIR%" mkdir "%LOGDIR%"
set LOG=%LOGDIR%\scheduler.log

echo. >> "%LOG%"
echo ==================================================================== >> "%LOG%"
echo [%DATE% %TIME%] START cadence=%CADENCE% >> "%LOG%"
echo ==================================================================== >> "%LOG%"

C:\Python313\python.exe run_all_scrapers.py --cadence %CADENCE% >> "%LOG%" 2>&1
set RC=%ERRORLEVEL%

echo [%DATE% %TIME%] END cadence=%CADENCE% exit=%RC% >> "%LOG%"
exit /b %RC%
