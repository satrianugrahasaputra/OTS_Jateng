@echo off
setlocal EnableExtensions EnableDelayedExpansion
REM =============================================
REM  OTS Jateng - Auto Sync Data BPS
REM  Jalankan via Windows Task Scheduler
REM =============================================

set "SYNC_SECRET="
set "SYNC_URL=http://localhost:8000/run-sync-all"

if exist ".env" (
    for /f "usebackq tokens=1,* delims==" %%A in (".env") do (
        set "ENV_KEY=%%A"
        set "ENV_VALUE=%%B"
        if /I "!ENV_KEY!"=="SYNC_SECRET" set "SYNC_SECRET=!ENV_VALUE!"
        if /I "!ENV_KEY!"=="SYNC_URL" set "SYNC_URL=!ENV_VALUE!"
    )
)

if not defined SYNC_SECRET set "SYNC_SECRET=OTS_SYNC_SECRET_2026"

echo [%date% %time%] Starting sync...

REM Sync data tahun sekarang dengan token keamanan
REM PENTING: Atur SYNC_URL dan SYNC_SECRET di file .env untuk production.
curl -s "%SYNC_URL%?year=%date:~-4%&token=%SYNC_SECRET%" > NUL

echo [%date% %time%] Sync completed.
