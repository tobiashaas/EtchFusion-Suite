# Run PHPCS for Etch Fusion Suite
# Works with vendor in etch-fusion-suite OR in project root (parent directory)

$ErrorActionPreference = "Stop"
$scriptDir = $PSScriptRoot
$suiteDir = Split-Path $scriptDir -Parent
$projectRoot = Split-Path $suiteDir -Parent

# Prefer vendor in etch-fusion-suite, fall back to project root
$vendorPhpcs = Join-Path $suiteDir "vendor\bin\phpcs"
if (-not (Test-Path $vendorPhpcs)) {
    $vendorPhpcs = Join-Path $projectRoot "vendor\bin\phpcs"
}

if (-not (Test-Path $vendorPhpcs)) {
    Write-Host "PHPCS nicht gefunden. Bitte führe 'composer install' aus:" -ForegroundColor Red
    Write-Host "  cd $suiteDir" -ForegroundColor Yellow
    Write-Host "  composer install" -ForegroundColor Yellow
    Write-Host ""
    Write-Host "Oder im Projekt-Root:" -ForegroundColor Red
    Write-Host "  cd $projectRoot" -ForegroundColor Yellow
    Write-Host "  composer install" -ForegroundColor Yellow
    exit 1
}

$phpcsXml = Join-Path $suiteDir "phpcs.xml.dist"
$php = Get-Command php -ErrorAction SilentlyContinue
if (-not $php) {
    Write-Host "PHP nicht im PATH gefunden. Bitte füge PHP zu deinem PATH hinzu." -ForegroundColor Red
    exit 1
}

# Run from suite dir so ruleset paths (./includes etc.) resolve correctly
Push-Location $suiteDir
try {
    & php $vendorPhpcs @args
} finally {
    Pop-Location
}
