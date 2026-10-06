$ErrorActionPreference = "Stop"

$desktop = [Environment]::GetFolderPath("Desktop")

$project = "\\wsl.localhost\Ubuntu\home\adolfo\projetos\prospector-exportcontrol"

$local = Join-Path $env:LOCALAPPDATA "ExportControlProspector"

New-Item -ItemType Directory -Force -Path $local | Out-Null


$projectLauncher = Join-Path $project "tools\windows\Iniciar Prospector.cmd"

$projectDocker = Join-Path $project "tools\windows\start-docker.ps1"

$projectLogo = Join-Path $project "public\images\brand\pngexportcontrol.png"


$localLauncher = Join-Path $local "Iniciar Prospector.cmd"

$localDocker = Join-Path $local "start-docker.ps1"

$localLogo = Join-Path $local "exportcontrol.png"

$localIcon = Join-Path $local "exportcontrol.ico"


if (-not (Test-Path -LiteralPath $projectLauncher)) {
    throw "Launcher nao encontrado: $projectLauncher"
}

if (-not (Test-Path -LiteralPath $projectDocker)) {
    throw "Script Docker nao encontrado: $projectDocker"
}

if (-not (Test-Path -LiteralPath $projectLogo)) {
    throw "Logo ExportControl nao encontrada: $projectLogo"
}


Copy-Item -LiteralPath $projectLauncher -Destination $localLauncher -Force

Copy-Item -LiteralPath $projectDocker -Destination $localDocker -Force

Copy-Item -LiteralPath $projectLogo -Destination $localLogo -Force


Write-Host ""
Write-Host "Gerando icone ExportControl..."


Add-Type -AssemblyName System.Drawing

$image = [System.Drawing.Image]::FromFile($localLogo)

$size = 256

$bitmap = New-Object -TypeName System.Drawing.Bitmap -ArgumentList $size, $size

$graphics = [System.Drawing.Graphics]::FromImage($bitmap)

$graphics.Clear([System.Drawing.Color]::Transparent)

$graphics.InterpolationMode = [System.Drawing.Drawing2D.InterpolationMode]::HighQualityBicubic


$scaleX = $size / $image.Width

$scaleY = $size / $image.Height

$scale = [Math]::Min($scaleX, $scaleY)

$width = [int]($image.Width * $scale)

$height = [int]($image.Height * $scale)

$x = [int](($size - $width) / 2)

$y = [int](($size - $height) / 2)


$graphics.DrawImage(
    $image,
    $x,
    $y,
    $width,
    $height
)


$handle = $bitmap.GetHicon()

$icon = [System.Drawing.Icon]::FromHandle($handle)

$stream = [System.IO.File]::Create($localIcon)

$icon.Save($stream)

$stream.Close()

$graphics.Dispose()

$bitmap.Dispose()

$image.Dispose()


$oldLauncher = Join-Path $desktop "Iniciar Prospector.cmd"

if (Test-Path -LiteralPath $oldLauncher) {
    Remove-Item -LiteralPath $oldLauncher -Force
}


$shortcutPath = Join-Path $desktop "Prospector ExportControl.lnk"

if (Test-Path -LiteralPath $shortcutPath) {
    Remove-Item -LiteralPath $shortcutPath -Force
}


$shell = New-Object -ComObject WScript.Shell

$shortcut = $shell.CreateShortcut($shortcutPath)

$shortcut.TargetPath = $localLauncher

$shortcut.WorkingDirectory = $local

$shortcut.Description = "Iniciar ExportControl Prospector"

$shortcut.IconLocation = $localIcon + ",0"

$shortcut.Save()


Write-Host ""
Write-Host "=============================================="
Write-Host " ATALHO INSTALADO"
Write-Host "=============================================="
Write-Host ""
Write-Host $shortcutPath
Write-Host ""
Write-Host "Arquivos locais:"
Write-Host $local
Write-Host ""
