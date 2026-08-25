@echo off
:: ============================================================
:: HR Vault 201 - PHPStan Static Code Analyzer Runner
:: Usage: Double-click this file, or run from cmd prompt
:: ============================================================

set "PHP=c:\xampp\php\php.exe"
set "PHAR=c:\xampp\php\phpstan.phar"
set "CONFIG=%~dp0phpstan.neon"
set "OUTFILE=%~dp0phpstan_report.txt"

echo.
echo ====================================================
echo   HR Vault 201 - PHPStan Code Quality Scan
echo ====================================================
echo.

IF NOT EXIST "%PHP%" (
    echo [ERROR] PHP executable not found at "%PHP%"
    echo Please install or configure XAMPP PHP before running analysis.
    pause
    exit /b 1
)

IF NOT EXIST "%PHAR%" (
    echo [ERROR] phpstan.phar not found at "%PHAR%"
    echo Please download it from: https://phpstan.org/
    pause
    exit /b 1
)

IF NOT EXIST "%CONFIG%" (
    echo [ERROR] PHPStan configuration not found at "%CONFIG%"
    echo Please ensure phpstan.neon is beside this batch file.
    pause
    exit /b 1
)

echo Running analysis... (this may take 30-60 seconds)
echo.

pushd "%~dp0"
"%PHP%" "%PHAR%" analyse --configuration="%CONFIG%" --no-progress --error-format=table > "%OUTFILE%" 2>&1
set "PHPSTAN_EXIT=%ERRORLEVEL%"
type "%OUTFILE%"
popd

echo.
echo ====================================================
echo   Full report saved to: "%OUTFILE%"
echo ====================================================
pause
exit /b %PHPSTAN_EXIT%

