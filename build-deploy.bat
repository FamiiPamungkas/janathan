@echo off
setlocal EnableDelayedExpansion
set "ROOT=%~dp0"
set "DIST=%ROOT%dist\janathan"
set "ZIP=%ROOT%dist\janathan.zip"

rem ================================================================
rem  Janathan - production build + optional deploy for shared hosting (Windows/Laragon)
rem  Builds assets, installs production PHP deps, assembles a ready
rem  package folder at dist\janathan\ plus a zip at dist\janathan.zip.
rem  The package ships WITHOUT a database - the web setup wizard
rem  creates it (schema, APP_KEY and first admin) on the first browser
rem  visit. APP_BASE_PATH is applied to the dist config/app.php during
rem  the build (source stays untouched). After the build, the zip can
rem  optionally be copied to a server via scp and unpacked there with
rem  a Docker container restart (/scp / /deploy).
rem ================================================================
rem  Options:
rem    /nopause           skip all interactive prompts (implies /noscp, /nodeploy)
rem    /basepath <path>   set APP_BASE_PATH non-interactively (e.g. /basepath /janathan)
rem    /scp               copy dist\janathan.zip to a server via scp
rem                       non-interactively (reads SSH_USER, SSH_HOST, SSH_DIR env vars)
rem    /noscp             never offer the SSH copy
rem    /deploy            after a successful scp, unzip the package on the server
rem                       and restart the Docker containers non-interactively
rem    /nodeploy          never offer the remote unzip + restart
rem    set LARAGON=<path> if Laragon is not at C:\laragon
rem ================================================================

chcp 65001 >nul 2>&1

if defined LARAGON (set "LARAGON_ROOT=%LARAGON%") else (set "LARAGON_ROOT=C:\laragon")

set "NOPAUSE="
set "DOSCP="
set "NOSCP="
set "DODEPLOY="
set "NODEPLOY="

:parse_args
if "%~1"=="" goto :args_done
if /i "%~1"=="/nopause" ( set "NOPAUSE=1" & shift & goto :parse_args )
if /i "%~1"=="/scp" ( set "DOSCP=1" & shift & goto :parse_args )
if /i "%~1"=="/noscp" ( set "NOSCP=1" & shift & goto :parse_args )
if /i "%~1"=="/deploy" ( set "DODEPLOY=1" & shift & goto :parse_args )
if /i "%~1"=="/nodeploy" ( set "NODEPLOY=1" & shift & goto :parse_args )
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

rem ----------------------------------------------------------------
rem 4. Create zip archive (PowerShell Compress-Archive)
rem ----------------------------------------------------------------
echo Creating %ZIP%...
if exist "%ZIP%" del /q "%ZIP%"
powershell -NoProfile -Command "Compress-Archive -Path '%DIST%' -DestinationPath '%ZIP%' -Force"
if errorlevel 1 (echo  [FAIL] Could not create zip archive & goto :fail)
if not exist "%ZIP%" (echo  [FAIL] Could not create zip archive & goto :fail)

rem ----------------------------------------------------------------
rem 5. Optional: copy the zip to a server via SSH (scp)
rem ----------------------------------------------------------------
set "DO_SCP="
if defined NOSCP goto :scp_done
if defined DOSCP goto :scp_noninteractive
if defined NOPAUSE goto :scp_done
set "SCP_ASK="
set /p "SCP_ASK=Copy janathan.zip to a server via SSH (scp)? [y/N]: "
if /i not "%SCP_ASK%"=="y" goto :scp_done
goto :scp_prompts

:scp_noninteractive
if not defined SSH_USER (echo  [FAIL] /scp needs SSH_USER env var. & goto :fail)
if not defined SSH_HOST (echo  [FAIL] /scp needs SSH_HOST env var. & goto :fail)
if not defined SSH_DIR (echo  [FAIL] /scp needs SSH_DIR env var. & goto :fail)
set "DO_SCP=1"
goto :scp_run

:scp_prompts
if defined SSH_USER (
    set "SCP_USER="
    set /p "SCP_USER=SSH username [%SSH_USER%]: "
    if defined SCP_USER set "SSH_USER=!SCP_USER!"
) else (
    set /p "SSH_USER=SSH username: "
)
if defined SSH_HOST (
    set "SCP_HOST="
    set /p "SCP_HOST=Server IP/host [%SSH_HOST%]: "
    if defined SCP_HOST set "SSH_HOST=!SCP_HOST!"
) else (
    set /p "SSH_HOST=Server IP/host: "
)
if defined SSH_DIR (
    set "SCP_DIR="
    set /p "SCP_DIR=Remote directory [%SSH_DIR%]: "
    if defined SCP_DIR set "SSH_DIR=!SCP_DIR!"
) else (
    set /p "SSH_DIR=Remote directory (e.g. /home/user or ~/docker): "
)
if not defined SSH_USER (echo  [FAIL] SSH upload needs a username, server IP/host and directory. & goto :fail)
if not defined SSH_HOST (echo  [FAIL] SSH upload needs a username, server IP/host and directory. & goto :fail)
if not defined SSH_DIR (echo  [FAIL] SSH upload needs a username, server IP/host and directory. & goto :fail)
set "DO_SCP=1"

:scp_run
where scp >nul 2>&1
if errorlevel 1 (echo  [FAIL] scp not found. Install the Windows OpenSSH client to use the SSH copy. & goto :fail)
where ssh >nul 2>&1
if errorlevel 1 (echo  [FAIL] ssh not found. Install the Windows OpenSSH client to use the SSH copy. & goto :fail)
rem Build a tilde-safe quoted remote path (~/... -> $HOME/'...').
call :remote_quote SSH_DIR REMOTE_DIR_Q
echo Ensuring remote directory exists on %SSH_HOST%...
ssh "%SSH_USER%@%SSH_HOST%" "mkdir -p !REMOTE_DIR_Q!"
if errorlevel 1 (echo  [FAIL] Could not create remote directory ^(build itself succeeded^). & goto :fail)
echo Copying janathan.zip to %SSH_USER%@%SSH_HOST%:%SSH_DIR%/ ...
scp "%ZIP%" "%SSH_USER%@%SSH_HOST%:%SSH_DIR%/"
if errorlevel 1 (echo  [FAIL] scp upload failed ^(build itself succeeded^). & goto :fail)
set "SCP_STATUS=copied to %SSH_USER%@%SSH_HOST%:%SSH_DIR%/"
goto :deploy_step

:scp_done
set "SCP_STATUS=skipped"

:deploy_step
rem ----------------------------------------------------------------
rem 6. Optional: unzip on the server and restart Docker containers
rem    (only after a successful scp - there is nothing to unpack
rem    otherwise). unzip merges: files in the archive overwrite,
rem    everything else on the server (e.g. database/janathan.sqlite,
rem    which is NOT shipped in the zip) is left untouched.
rem ----------------------------------------------------------------
set "DO_DEPLOY="
if defined NODEPLOY goto :deploy_done
if not defined DO_SCP goto :deploy_no_scp
if defined DODEPLOY goto :deploy_run
if defined NOPAUSE goto :deploy_done
set "DEPLOY_ASK="
set /p "DEPLOY_ASK=Unzip on the server and restart containers? [y/N]: "
if /i not "%DEPLOY_ASK%"=="y" goto :deploy_done
goto :deploy_run

:deploy_no_scp
if defined DODEPLOY (echo  [FAIL] /deploy needs a successful SSH copy first ^(combine with /scp, or answer y at the copy prompt^). & goto :fail)
goto :deploy_done

:deploy_run
where ssh >nul 2>&1
if errorlevel 1 (echo  [FAIL] ssh not found. Install the Windows OpenSSH client to use the remote deploy. & goto :fail)
ssh "%SSH_USER%@%SSH_HOST%" "command -v unzip >/dev/null 2>&1"
if errorlevel 1 (echo  [FAIL] 'unzip' not found on %SSH_HOST%. Install it there first. & goto :fail)
if not defined REMOTE_DIR_Q call :remote_quote SSH_DIR REMOTE_DIR_Q
echo Unpacking janathan.zip on %SSH_HOST% and restarting containers...
ssh "%SSH_USER%@%SSH_HOST%" "cd !REMOTE_DIR_Q! && unzip -o janathan.zip && cd janathan && (docker compose down && docker compose up -d || docker-compose down && docker-compose up -d)"
if errorlevel 1 (echo  [FAIL] Remote deploy failed ^(build + scp succeeded^). & goto :fail)
set "DEPLOY_STATUS=unpacked + containers restarted"
goto :deploy_summary

:deploy_done
set "DEPLOY_STATUS=skipped"

:deploy_summary
echo.
echo Build finished successfully.
echo.
echo  Package folder  : %DIST%
echo  Zip archive     : %ZIP%
if defined APP_BASE_PATH (echo  APP_BASE_PATH   : !APP_BASE_PATH!) else (echo  APP_BASE_PATH   : ^(empty^))
echo  Database        : created on first visit by the web setup wizard
echo                    (admin account + APP_KEY are set up there)
if not "%SCP_STATUS%"=="skipped" echo  SSH copy        : %SCP_STATUS%
if not "%DEPLOY_STATUS%"=="skipped" (
    echo  SSH deploy      : %DEPLOY_STATUS%
) else (
    if not "%SCP_STATUS%"=="skipped" (
        echo                    On the server: unzip janathan.zip, make "database" writable, open the site.
    ) else (
        echo  Next steps      : upload the zip, make sure "database" stays writable, open the site.
        echo                    (full guide: README-DEPLOY.md in the package)
    )
)
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

:remote_quote
rem Builds a tilde-safe POSIX-quoted path for the server's sh.
rem %1 = input var name (e.g. SSH_DIR), %2 = output var name.
rem ~/... -> $HOME/'...', ~ -> $HOME, ~user/... -> ~user/'...', else '...'.
rem Plain '...'/ "..." would inhibit tilde expansion (cd '~/docker' fails),
rem so the leading ~/^~user prefix must stay unquoted.
set "RQ_IN=!%~1!"
if "!RQ_IN!"=="~" (
    set "%~2=$HOME"
    exit /b 0
)
if "!RQ_IN:~0,2!"=="~/" (
    set "RQ_REST=!RQ_IN:~2!"
    set "RQ_REST=!RQ_REST:'='\''!"
    set "%~2=$HOME/'!RQ_REST!'"
    exit /b 0
)
if "!RQ_IN:~0,1!"=="~" (
    for /f "tokens=1* delims=/" %%A in ("!RQ_IN!") do (
        set "RQ_PREFIX=%%A"
        set "RQ_REST=%%B"
    )
    if defined RQ_REST (
        set "RQ_REST=!RQ_REST:'='\''!"
        set "%~2=!RQ_PREFIX!/'!RQ_REST!'"
    ) else (
        set "%~2=!RQ_IN!"
    )
    exit /b 0
)
set "RQ_ESC=!RQ_IN:'='\''!"
set "%~2='!RQ_ESC!'"
exit /b 0
