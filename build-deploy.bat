@echo off
setlocal EnableDelayedExpansion
set "ROOT=%~dp0"
set "DIST=%ROOT%dist\janathan"

rem ================================================================
rem  Janathan - production build for shared hosting (Windows/Laragon)
rem  Builds assets, installs production PHP deps, assembles a ready
rem  deploy folder at dist\janathan\. The package ships WITHOUT a
rem  database - the web setup wizard creates it (schema, APP_KEY and
rem  first admin) on the first browser visit. APP_BASE_PATH is applied
rem  to the dist config/app.php during the build (source stays untouched).
rem ================================================================
rem  Options:
rem    /nopause           skip the final confirmation prompt
rem    /basepath <path>   set APP_BASE_PATH non-interactively (e.g. /basepath /janathan)
rem    set LARAGON=<path> if Laragon is not at C:\laragon
rem ================================================================

chcp 65001 >nul 2>&1

if defined LARAGON (set "LARAGON_ROOT=%LARAGON%") else (set "LARAGON_ROOT=C:\laragon")

set "NOPAUSE="

:parse_args
if "%~1"=="" goto :args_done
if /i "%~1"=="/nopause" ( set "NOPAUSE=1" & shift & goto :parse_args )
if /i "%~1"=="/basepath" goto :basepath_parse
shift
goto :parse_args
:basepath_parse
shift
if "%~1"=="" goto :args_done
set "APP_BASE_PATH_ARG=%~1"
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
rem 0. Locate the toolchain
rem ----------------------------------------------------------------
echo [0/3] Locating PHP, Node and Composer...

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
rem  APP_BASE_PATH - URL prefix the app is mounted under
rem ----------------------------------------------------------------
set "APP_BASE_PATH="
if defined APP_BASE_PATH_ARG (
    set "APP_BASE_PATH=%APP_BASE_PATH_ARG%"
) else if defined NOPAUSE (
    set "APP_BASE_PATH="
) else (
    echo  APP_BASE_PATH is the URL prefix the app is mounted under.
    echo  Leave empty when the document root points at public, or enter the
    echo  sub-folder name ^(e.g. janathan or /janathan^). Press Enter for empty.
    echo.
    set /p "APP_BASE_PATH=APP_BASE_PATH (default: empty): "
)

rem Normalize: '' or a leading-slash path with no trailing slash.
:bps_strip_tail
if not "!APP_BASE_PATH!"=="" if "!APP_BASE_PATH:~-1!"=="/" (
    set "APP_BASE_PATH=!APP_BASE_PATH:~0,-1!"
    if not "!APP_BASE_PATH!"=="" goto :bps_strip_tail
)
if "!APP_BASE_PATH!"=="" goto :bps_done
if "!APP_BASE_PATH:~0,1!"=="/" (
    set "APP_BASE_PATH=!APP_BASE_PATH:~1!"
    if not "!APP_BASE_PATH!"=="" if "!APP_BASE_PATH:~0,1!"=="/" goto :bps_strip_tail
)
if "!APP_BASE_PATH!"=="" goto :bps_done
set "APP_BASE_PATH=/!APP_BASE_PATH!"
:bps_done
if defined APP_BASE_PATH_ARG set "APP_BASE_PATH_ARG="
if defined APP_BASE_PATH (echo  APP_BASE_PATH  : !APP_BASE_PATH!) else (echo  APP_BASE_PATH  : ^(empty^))
echo.

rem ----------------------------------------------------------------
rem 1. Frontend assets (icons, CSS, JS)
rem ----------------------------------------------------------------
if not exist "%ROOT%node_modules\.bin\esbuild.cmd" goto :deps_missing
if not exist "%ROOT%node_modules\.bin\tailwindcss.cmd" goto :deps_missing
goto :deps_ok

:deps_missing
echo [1/3] Frontend dependencies missing for Windows - running npm ci...
call "%NPM_CMD%" ci
if errorlevel 1 goto :fail

:deps_ok
echo [1/3] Building frontend assets...
call "%NPM_CMD%" run build
if errorlevel 1 goto :fail

rem ----------------------------------------------------------------
rem 2. Backend dependencies (production only)
rem ----------------------------------------------------------------
echo [2/3] Installing production dependencies...
call %COMPOSER_RUN% install --no-dev --no-interaction --prefer-dist --optimize-autoloader --classmap-authoritative
if errorlevel 1 goto :fail

rem ----------------------------------------------------------------
rem 3. Assemble the deploy package at dist\janathan\
rem ----------------------------------------------------------------
echo [3/3] Assembling deploy package at %DIST%...
if exist "%DIST%" rmdir /s /q "%DIST%"
md "%DIST%" 2>nul

for %%S in (vendor config routes src templates resources) do (
    robocopy "%ROOT%%%S" "%DIST%\%%S" /E /NFL /NDL /NJH /NJS /NC /NS /NP
    if errorlevel 8 goto :fail
)
robocopy "%ROOT%public" "%DIST%\public" /E /NFL /NDL /NJH /NJS /NC /NS /NP
if errorlevel 8 goto :fail

rem Ship only the compiled CSS/JS, not the Tailwind/esbuild sources.
del /q "%DIST%\public\css\index.css" 2>nul
del /q "%DIST%\public\js\index.js" 2>nul

copy /y "%ROOT%composer.json"        "%DIST%\composer.json"        >nul
copy /y "%ROOT%composer.lock"        "%DIST%\composer.lock"        >nul
copy /y "%ROOT%.htaccess"            "%DIST%\.htaccess"            >nul
copy /y "%ROOT%Dockerfile"           "%DIST%\Dockerfile"           >nul
copy /y "%ROOT%docker-compose.yml"   "%DIST%\docker-compose.yml"   >nul
copy /y "%ROOT%docker-entrypoint.sh" "%DIST%\docker-entrypoint.sh" >nul
copy /y "%ROOT%.dockerignore"        "%DIST%\.dockerignore"        >nul
copy /y "%ROOT%scripts\README-DEPLOY.md" "%DIST%\README-DEPLOY.md" >nul

rem Writable dir where the web setup wizard will create the SQLite DB
rem on the first browser visit.
md "%DIST%\database" 2>nul

rem ----------------------------------------------------------------
rem Apply APP_BASE_PATH to the dist config/app.php (source stays untouched).
rem ----------------------------------------------------------------
if not "!APP_BASE_PATH!"=="" (
    set "ABS=!APP_BASE_PATH!"
    "%PHP_EXE%" -r "$q=chr(39); $f='%DIST%\config\app.php'; $c=file_get_contents($f); if ($c===false) exit(1); $c=str_replace($q.'APP_BASE_PATH'.$q.'           => '.$q.$q, $q.'APP_BASE_PATH'.$q.'           => '.$q.getenv('ABS').$q, $c); if (strpos($c,$q.'APP_BASE_PATH'.$q.'           => '.$q.getenv('ABS').$q)===false) exit(2); file_put_contents($f,$c) or exit(3); echo 'APP_BASE_PATH set to '.getenv('ABS').chr(10);"
    if errorlevel 1 goto :fail
    set "ABS="
)
echo.

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
call :check "%DIST%\Dockerfile"
call :check "%DIST%\docker-compose.yml"
call :check "%DIST%\docker-entrypoint.sh"
if "%MISSING%"=="1" goto :fail

echo.
echo Build finished successfully.
echo.
echo  Deploy package  : %DIST%
if defined APP_BASE_PATH (echo  APP_BASE_PATH   : !APP_BASE_PATH!) else (echo  APP_BASE_PATH   : ^(empty^))
echo  Database        : created on first visit by the web setup wizard
echo                    (admin account + APP_KEY are set up there)
echo  Next steps      : upload it, make sure "database" stays writable, open the site.
echo                    (full guide: README-DEPLOY.md in the package)
echo  Docker          : cd %DIST% ^&^& docker compose up -d --build
echo                    (binds the package as a volume; edit docker-compose.yml
echo                     for APP_BASE_PATH, DB_PATH, Mikrotik timeouts, port)
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
