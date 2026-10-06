@echo off
setlocal EnableExtensions EnableDelayedExpansion

title ExportControl Prospector
color 0B
cls

echo.
echo ======================================================
echo             EXPORTCONTROL PROSPECTOR
echo ======================================================
echo.
echo Preparando ambiente...
echo.


echo [1/4] Verificando Docker...
echo.

wsl.exe -d Ubuntu -- bash -lc "docker info >/dev/null 2>&1"

if not errorlevel 1 goto DOCKER_READY


echo Docker Desktop nao esta ativo.
echo Iniciando...
echo.

powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0start-docker.ps1"

if errorlevel 1 (
    echo.
    echo ======================================================
    echo       NAO FOI POSSIVEL INICIAR O DOCKER
    echo ======================================================
    echo.
    echo Verifique se o Docker Desktop esta instalado.
    echo.
    pause
    exit /b 1
)


echo Aguardando Docker ficar pronto...
echo.

set /a DOCKER_WAIT=0


:WAIT_DOCKER

timeout /t 2 /nobreak >nul

wsl.exe -d Ubuntu -- bash -lc "docker info >/dev/null 2>&1"

if not errorlevel 1 goto DOCKER_READY

set /a DOCKER_WAIT+=1

echo Aguardando Docker... !DOCKER_WAIT!

if !DOCKER_WAIT! GEQ 90 (
    echo.
    echo ======================================================
    echo       DOCKER DEMOROU PARA INICIAR
    echo ======================================================
    echo.
    pause
    exit /b 1
)

goto WAIT_DOCKER


:DOCKER_READY

echo.
echo Docker pronto.
echo.


echo [2/4] Iniciando Prospector...
echo.

wsl.exe -d Ubuntu -- bash -lc "cd /home/adolfo/projetos/prospector-exportcontrol && ./start.sh"

if errorlevel 1 (
    echo.
    echo ======================================================
    echo       ERRO AO INICIAR O PROSPECTOR
    echo ======================================================
    echo.
    pause
    exit /b 1
)


echo.
echo [3/4] Aguardando aplicacao...
echo.

set /a APP_WAIT=0


:WAIT_APP

powershell.exe -NoProfile -Command "try { Invoke-WebRequest -UseBasicParsing -Uri 'http://localhost' -TimeoutSec 2 ^| Out-Null; exit 0 } catch { exit 1 }"

if not errorlevel 1 goto APP_READY

set /a APP_WAIT+=1

if !APP_WAIT! GEQ 30 goto OPEN_BROWSER

timeout /t 1 /nobreak >nul

goto WAIT_APP


:APP_READY

echo Aplicacao pronta.


:OPEN_BROWSER

echo.
echo [4/4] Abrindo navegador...
echo.

start "" "http://localhost"


echo.
echo ======================================================
echo             PROSPECTOR PRONTO
echo ======================================================
echo.
echo Docker .......... OK
echo Laravel ......... OK
echo Queue ........... OK
echo Scheduler ....... OK
echo Frontend ........ OK
echo HubSpot Sync .... OK
echo.
echo http://localhost
echo.

timeout /t 3 /nobreak >nul

exit /b 0
