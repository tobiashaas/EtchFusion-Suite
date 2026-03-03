@echo off
setlocal enabledelayedexpansion

cd /d E:\Github\EtchFusion-Suite\etch-fusion-suite

set "passed=0"
set "failed=0"
set "failed_files="

for /r includes %%F in (*.php) do (
    php -l "%%F" > nul 2>&1
    if !errorlevel! equ 0 (
        set /a passed+=1
    ) else (
        set /a failed+=1
        set "failed_files=!failed_files!%%F "
    )
)

echo.
echo PHP Syntax Check Results:
echo =========================
echo Total files: 145
echo Passed: %passed%
echo Failed: %failed%

if %failed% gtr 0 (
    echo.
    echo Failed files:
    for %%f in (!failed_files!) do echo   - %%f
)
