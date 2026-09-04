@echo off
setlocal EnableDelayedExpansion
set "ROOT=%~dp0"
set "DIST=%ROOT%dist\janathan"

rem ================================================================
rem  Janathan - production build for shared hosting (Windows/Laragon)
rem  Builds assets, installs production PHP deps, assembles a ready
rem  deploy folder at dist\janathan\ including an initialized SQLite
rem  database with an admin and APP_KEY. config/app.php ships as-is
rem  (it is a committed source file - edit it directly if needed).
rem ================================================================
rem  Options:
rem    /init <user> <pw>  admin credentials, prompts skipped (default janathan / 1234)
rem    /no-prompt         don't prompt for admin credentials, use defaults
rem    /nopause           skip the final confirmation prompt
rem    set LARAGON=<path> if Laragon is not at C:\laragon
rem ================================================================

chcp 65001 >nul 2>&1

if defined LARAGON (set "LARAGON_ROOT=%LARAGON%") else (set "LARAGON_ROOT=C:\laragon")

set "NOPAUSE="
set "NOPROMPT="
set "INIT_GIVEN="
set "ADMIN_USER=janathan"
set "ADMIN_PASS=1234"

:parse_args
if "%~1"=="" goto :args_done
if /i "%~1"=="/nopause" ( set "NOPAUSE=1" & shift & goto :parse_args )
if /i "%~1"=="/no-prompt" ( set "NOPROMPT=1" & shift & goto :parse_args )
if /i "%~1"=="/init"    (
    set "INIT_GIVEN=1"
    set "ADMIN_USER=%~2"
    if not "%~3"=="" set "ADMIN_PASS=%~3"
    shift & shift & shift
    goto :parse_args
)
shift
goto :parse_args
:args_done

echo.
echo ================================================================
echo  Janathan production build
echo  Project : %ROOT%
echo ================================================================
echo.

rem ----------------------------------------------------------------
rem Admin credentials (unless forced via /init or /no-prompt)
rem Empty input keeps the defaults.
rem ----------------------------------------------------------------
if not defined INIT_GIVEN if not defined NOPROMPT (
    echo  Admin credentials for the new database - Enter accepts defaults:
    set /p "ADMIN_USER=  Username [%ADMIN_USER%]: "
    set /p "ADMIN_PASS=  Password [%ADMIN_PASS%]: "
    if not defined ADMIN_USER set "ADMIN_USER=janathan"
    if not defined ADMIN_PASS set "ADMIN_PASS=1234"
)
if not defined INIT_GIVEN if not defined NOPROMPT echo  Admin user     : %ADMIN_USER%  - prompted above or default; change password after first login
echo.

rem ----------------------------------------------------------------
rem 0. Locate the toolchain
rem ----------------------------------------------------------------
echo [0/4] Locating PHP, Node and Composer...

set "PHP_EXE="
if exist "%LARAGON_ROOT%\bin\php" (
    for /f "delims=" %%D in ('dir /b /ad /o-n "%LARAGON_ROOT%\bin\php\php-*" 2^>nul') do (
        if not defined PHP_EXE if exist "%LARAGON_ROOT%\bin\php\%%D\php.exe" set "PHP_EXE=%LARAGON_ROOT%\bin\php\%%D\php.exe"
    )
)
if not defined PHP_EXE (
    for /f "delims=" %%P in ('where php 2^>nul') do if not defined PHP_EXE set "PHP_EXE=%%P"
)
if not defined PHP_EXE (echo  [FAIL] PHP not found. Set LARAGON or add php to PATH. & goto :fail)

"%PHP_EXE%" -r "exit(PHP_VERSION_ID < 80200 ? 1 : 0);" >nul 2>&1
if errorlevel 1 (echo  [FAIL] PHP 8.2 or newer is required. & goto :fail)

set "NPM_CMD="
if exist "%LARAGON_ROOT%\bin\nodejs" (
    for /f "delims=" %%N in ('dir /b /ad /o-n "%LARAGON_ROOT%\bin\nodejs\node-v*" 2^>nul') do (
        if not defined NPM_CMD if exist "%LARAGON_ROOT%\bin\nodejs\%%N\npm.cmd" set "NPM_CMD=%LARAGON_ROOT%\bin\nodejs\%%N\npm.cmd"
    )
)
if not defined NPM_CMD (
    for /f "delims=" %%N in ('where npm 2^>nul') do if not defined NPM_CMD set "NPM_CMD=%%N"
)
if not defined NPM_CMD (echo  [FAIL] npm not found. Set LARAGON or add node to PATH. & goto :fail)

set "COMPOSER_PHAR="
if exist "%LARAGON_ROOT%\bin\composer\composer.phar" set "COMPOSER_PHAR=%LARAGON_ROOT%\bin\composer\composer.phar"
if not defined COMPOSER_PHAR if exist "%ProgramData%\ComposerSetup\bin\composer.phar" set "COMPOSER_PHAR=%ProgramData%\ComposerSetup\bin\composer.phar"
if defined COMPOSER_PHAR (set "COMPOSER_RUN="%PHP_EXE%" "%COMPOSER_PHAR%"") else (set "COMPOSER_RUN=composer")

echo  PHP      : %PHP_EXE%
echo  npm      : %NPM_CMD%
echo  Composer : %COMPOSER_RUN%
echo.

rem ----------------------------------------------------------------
rem 1. Frontend assets (icons, CSS, JS)
rem ----------------------------------------------------------------
if not exist "%ROOT%node_modules\.bin\esbuild.cmd" goto :deps_missing
if not exist "%ROOT%node_modules\.bin\tailwindcss.cmd" goto :deps_missing
goto :deps_ok

:deps_missing
echo [1/4] Frontend dependencies missing for Windows - running npm ci...
call "%NPM_CMD%" ci
if errorlevel 1 goto :fail

:deps_ok
echo [1/4] Building frontend assets...
call "%NPM_CMD%" run build
if errorlevel 1 goto :fail

rem ----------------------------------------------------------------
rem 2. Backend dependencies (production only)
rem ----------------------------------------------------------------
echo [2/4] Installing production dependencies...
call %COMPOSER_RUN% install --no-dev --no-interaction --prefer-dist --optimize-autoloader --classmap-authoritative
if errorlevel 1 goto :fail

rem ----------------------------------------------------------------
rem 3. Assemble the deploy package at dist\janathan\
rem ----------------------------------------------------------------
echo [3/4] Assembling deploy package at %DIST%...
if exist "%DIST%" rmdir /s /q "%DIST%"
md "%DIST%" 2>nul

for %%S in (vendor config routes src templates resources bin) do (
    robocopy "%ROOT%%%S" "%DIST%\%%S" /E /NFL /NDL /NJH /NJS /NC /NS /NP
    if errorlevel 8 goto :fail
)
robocopy "%ROOT%public" "%DIST%\public" /E /NFL /NDL /NJH /NJS /NC /NS /NP
if errorlevel 8 goto :fail

rem Ship only the compiled CSS/JS, not the Tailwind/esbuild sources.
del /q "%DIST%\public\css\index.css" 2>nul
del /q "%DIST%\public\js\index.js" 2>nul

copy /y "%ROOT%composer.json"      "%DIST%\composer.json"      >nul
copy /y "%ROOT%composer.lock"      "%DIST%\composer.lock"      >nul
copy /y "%ROOT%.htaccess"          "%DIST%\.htaccess"          >nul
copy /y "%ROOT%scripts\README-DEPLOY.md" "%DIST%\README-DEPLOY.md" >nul

rem Writable dir where the SQLite DB will be created.
md "%DIST%\database" 2>nul

rem ----------------------------------------------------------------
rem 4. Initialize database + admin user
rem ----------------------------------------------------------------
echo [4/4] Initializing database and admin user '%ADMIN_USER%'...
call "%PHP_EXE%" "%DIST%\bin\init.php" "%ADMIN_USER%" "%ADMIN_PASS%"
if errorlevel 1 goto :fail

rem ----------------------------------------------------------------
rem Sanity check the assembled package
rem ----------------------------------------------------------------
set "MISSING=0"
call :check "%DIST%\vendor\autoload.php"
call :check "%DIST%\public\css\app.css"
call :check "%DIST%\public\js\app.js"
call :check "%DIST%\public\fonts\phosphor\style.css"
call :check "%DIST%\public\.htaccess"
call :check "%DIST%\.htaccess"
call :check "%DIST%\config\app.php"
call :check "%DIST%\database\janathan.sqlite"
if "%MISSING%"=="1" goto :fail

echo.
echo Build finished successfully.
echo.
echo  Deploy package  : %DIST%
echo  config/app.php  : shipped as-is (edit directly if you need APP_BASE_PATH)
echo  Admin user      : %ADMIN_USER%  (password hidden; change it after first login)
echo  Next steps      : upload it, make sure "database" stays writable, open the site.
echo                    (full guide: README-DEPLOY.md in the package)
echo.
if not defined NOPAUSE pause
exit /b 0

:fail
echo.
echo Build FAILED - see messages above.
if not defined NOPAUSE pause
exit /b 1

:check
if exist "%~1" exit /b 0
echo  [FAIL] Missing: %~1
set "MISSING=1"
exit /b 0
