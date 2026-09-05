# Set console output encoding to UTF-8
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8

Write-Host ""
Write-Host "==============================================" -ForegroundColor Cyan
Write-Host "  Asistente de Subida y Actualizacion a GitHub" -ForegroundColor Cyan
Write-Host "  Plugin: WP autocontent (19webs)" -ForegroundColor Cyan
Write-Host "==============================================" -ForegroundColor Cyan
Write-Host ""

# 1. Verificar Git
if (-not (Test-Path ".git")) {
    Write-Host "[INFO] El repositorio local no estaba inicializado. Configurando Git..." -ForegroundColor Yellow
    git init
    git branch -M main
    git remote add origin https://github.com/19webs/wp-autocontent.git
    Write-Host "[OK] Repositorio Git local conectado a GitHub." -ForegroundColor Green
    Write-Host ""
} else {
    git remote set-url origin https://github.com/19webs/wp-autocontent.git 2>$null
}

# 2. Leer versión actual desde wp-autocontent.php en UTF-8 puro
$phpFile = "wp-autocontent.php"
$currentVer = "1.0.0"
$suggestedVer = "1.0.1"

if (Test-Path $phpFile) {
    $content = Get-Content $phpFile -Encoding UTF8 -Raw
    if ($content -match 'Version:\s+([0-9.]+)') {
        $currentVer = $Matches[1]
        $parts = $currentVer.Split('.')
        if ($parts.Count -ge 3) {
            $parts[2] = [int]$parts[2] + 1
            $suggestedVer = $parts -join '.'
        }
    }
}

Write-Host "[INFO] Version actual detectada en archivo: " -NoNewline
Write-Host "$currentVer" -ForegroundColor Yellow
Write-Host "[INFO] Siguiente version sugerida:        " -NoNewline
Write-Host "$suggestedVer" -ForegroundColor Green
Write-Host ""

# 3. Solicitar versión al usuario
$inputVer = Read-Host "Introduce la nueva version [ENTER para usar $suggestedVer]"
if ([string]::IsNullOrWhiteSpace($inputVer)) {
    $version = $suggestedVer
} else {
    $version = $inputVer.Trim()
}

Write-Host ""
Write-Host "[INFO] Actualizando version a v$version en wp-autocontent.php (UTF-8 puro)..." -ForegroundColor Yellow

# Update version in wp-autocontent.php safely
$raw = Get-Content $phpFile -Encoding UTF8 -Raw
$raw = $raw -replace 'Version:\s+[0-9.]+', "Version:     $version"
$raw = $raw -replace "define\(\s*'WP_AUTOCONTENT_VERSION'\s*,\s*'[0-9.]+'\s*\)", "define( 'WP_AUTOCONTENT_VERSION', '$version' )"
[System.IO.File]::WriteAllText((Get-Item $phpFile).FullName, $raw, (New-Object System.Text.UTF8Encoding $false))

Write-Host "[OK] Archivo wp-autocontent.php modificado a v$version." -ForegroundColor Green

$commitMsg = "Version v$version"

# 4. Commit y Push
git add .
git commit -m "$commitMsg"

Write-Host ""
Write-Host "[INFO] Subiendo archivos a GitHub..." -ForegroundColor Yellow
git push -f origin main

# 5. Crear y subir etiqueta Git
Write-Host ""
Write-Host "[INFO] Creando y subiendo etiqueta de version v$version..." -ForegroundColor Yellow
git tag -d "v$version" 2>$null
git push origin ":refs/tags/v$version" 2>$null

git tag "v$version"
git push origin "v$version"
Write-Host "[OK] Etiqueta v$version subida correctamente a GitHub." -ForegroundColor Green

Write-Host ""
Write-Host "==============================================" -ForegroundColor Cyan
Write-Host "  Proceso completado con exito (Version $version)." -ForegroundColor Cyan
Write-Host "==============================================" -ForegroundColor Cyan
Write-Host ""

Read-Host "Presiona ENTER para salir"
