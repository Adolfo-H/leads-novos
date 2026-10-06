$ErrorActionPreference = "Stop"

$desktop = [Environment]::GetFolderPath("Desktop")

$project = "\\wsl.localhost\Ubuntu\home\adolfo\projetos\prospector-exportcontrol"

$launcher = Join-Path $project "tools\windows\Iniciar Prospector.cmd"

$icon = Join-Path $project "public\favicon.ico"

$shortcutPath = Join-Path $desktop "Prospector ExportControl.lnk"

if (-not (Test-Path $launcher)) {
    throw "Iniciador nao encontrado: $launcher"
}

$shell = New-Object -ComObject WScript.Shell

$shortcut = $shell.CreateShortcut(
    $shortcutPath
)

$shortcut.TargetPath = $launcher

$shortcut.WorkingDirectory = $project

$shortcut.Description = "Iniciar ExportControl Prospector"

if (Test-Path $icon) {
    $shortcut.IconLocation = "$icon,0"
}

$shortcut.Save()

Write-Host ""
Write-Host "Atalho criado com sucesso:"
Write-Host $shortcutPath
Write-Host ""
