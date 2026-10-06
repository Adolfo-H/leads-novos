$ErrorActionPreference = "Stop"

Write-Host ""
Write-Host "Verificando Docker..."


# ------------------------------------------------------------
# Docker já está disponível?
# ------------------------------------------------------------

& wsl.exe -d Ubuntu -- bash -lc "docker info >/dev/null 2>&1"

if ($LASTEXITCODE -eq 0) {
    Write-Host "Docker ja esta ativo."
    exit 0
}


Write-Host "Docker ainda nao esta ativo."
Write-Host "Procurando Docker Desktop..."
Write-Host ""


# ------------------------------------------------------------
# Caminhos mais comuns
# ------------------------------------------------------------

$candidates = @(
    "$env:ProgramFiles\Docker\Docker\Docker Desktop.exe",
    "$env:LOCALAPPDATA\Docker\Docker Desktop.exe",
    "$env:LOCALAPPDATA\Programs\Docker\Docker Desktop.exe",
    "$env:LOCALAPPDATA\Programs\Docker\Docker\Docker Desktop.exe"
)


foreach ($candidate in $candidates) {

    if (Test-Path -LiteralPath $candidate) {

        Write-Host "Docker encontrado:"
        Write-Host $candidate
        Write-Host ""

        Start-Process -FilePath $candidate

        exit 0
    }
}


# ------------------------------------------------------------
# Registro do Windows - App Paths
# ------------------------------------------------------------

$appPathKeys = @(
    "HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\App Paths\Docker Desktop.exe",
    "HKCU:\SOFTWARE\Microsoft\Windows\CurrentVersion\App Paths\Docker Desktop.exe"
)


foreach ($key in $appPathKeys) {

    if (-not (Test-Path -LiteralPath $key)) {
        continue
    }

    $registryKey =
        Get-Item -LiteralPath $key

    $candidate =
        $registryKey.GetValue("")

    if ([string]::IsNullOrWhiteSpace($candidate)) {
        continue
    }

    if (Test-Path -LiteralPath $candidate) {

        Write-Host "Docker encontrado pelo Registro:"
        Write-Host $candidate
        Write-Host ""

        Start-Process -FilePath $candidate

        exit 0
    }
}


# ------------------------------------------------------------
# Registro de programas instalados
# ------------------------------------------------------------

$uninstallRoots = @(
    "HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall",
    "HKLM:\SOFTWARE\WOW6432Node\Microsoft\Windows\CurrentVersion\Uninstall",
    "HKCU:\SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall"
)


foreach ($root in $uninstallRoots) {

    if (-not (Test-Path -LiteralPath $root)) {
        continue
    }

    $entries =
        Get-ChildItem `
            -LiteralPath $root `
            -ErrorAction SilentlyContinue

    foreach ($entry in $entries) {

        $item =
            Get-ItemProperty `
                -LiteralPath $entry.PSPath `
                -ErrorAction SilentlyContinue

        if ($null -eq $item) {
            continue
        }

        $displayName =
            [string] $item.DisplayName

        if ($displayName -notlike "*Docker Desktop*") {
            continue
        }


        $installLocation =
            [string] $item.InstallLocation

        if (-not [string]::IsNullOrWhiteSpace($installLocation)) {

            $candidate =
                Join-Path `
                    $installLocation `
                    "Docker Desktop.exe"

            if (Test-Path -LiteralPath $candidate) {

                Write-Host "Docker encontrado:"
                Write-Host $candidate
                Write-Host ""

                Start-Process -FilePath $candidate

                exit 0
            }


            $candidate =
                Join-Path `
                    $installLocation `
                    "Docker\Docker Desktop.exe"

            if (Test-Path -LiteralPath $candidate) {

                Write-Host "Docker encontrado:"
                Write-Host $candidate
                Write-Host ""

                Start-Process -FilePath $candidate

                exit 0
            }
        }


        $displayIcon =
            [string] $item.DisplayIcon

        if (-not [string]::IsNullOrWhiteSpace($displayIcon)) {

            $candidate =
                $displayIcon.Replace(
                    ",0",
                    ""
                ).Trim('"')

            if (Test-Path -LiteralPath $candidate) {

                Write-Host "Docker encontrado:"
                Write-Host $candidate
                Write-Host ""

                Start-Process -FilePath $candidate

                exit 0
            }
        }
    }
}


# ------------------------------------------------------------
# Menu Iniciar
# ------------------------------------------------------------

$app =
    Get-StartApps |
    Where-Object {
        $_.Name -like "*Docker Desktop*"
    } |
    Select-Object -First 1


if ($null -ne $app) {

    Write-Host "Docker encontrado no Menu Iniciar:"
    Write-Host $app.Name
    Write-Host ""

    $shellPath =
        "shell:AppsFolder\" + $app.AppID

    Start-Process `
        -FilePath "explorer.exe" `
        -ArgumentList $shellPath

    exit 0
}


# ------------------------------------------------------------
# Não localizado
# ------------------------------------------------------------

Write-Host ""
Write-Host "================================================"
Write-Host " DOCKER DESKTOP NAO FOI LOCALIZADO"
Write-Host "================================================"
Write-Host ""
Write-Host "Caminhos diretos testados:"
Write-Host ""

foreach ($candidate in $candidates) {
    Write-Host " - $candidate"
}

Write-Host ""

exit 1
