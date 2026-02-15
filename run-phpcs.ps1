# Run PHPCS for Etch Fusion Suite
# Aufruf vom Projekt-Root (E:\Github\EtchFusion-Suite)
# Oder aus etch-fusion-suite: .\scripts\run-phpcs.ps1

$ErrorActionPreference = "Stop"
$scriptDir = $PSScriptRoot
$suiteDir = Join-Path $scriptDir "etch-fusion-suite"

# Prefer vendor in etch-fusion-suite, fall back to project root
$vendorPhpcs = Join-Path $suiteDir "vendor\bin\phpcs"
if (-not (Test-Path $vendorPhpcs)) {
    $vendorPhpcs = Join-Path $scriptDir "vendor\bin\phpcs"
}

if (-not (Test-Path $vendorPhpcs)) {
    Write-Host "PHPCS nicht gefunden. Bitte führe 'composer install' aus:" -ForegroundColor Red
    Write-Host "  cd $suiteDir" -ForegroundColor Yellow
    Write-Host "  composer install" -ForegroundColor Yellow
    Write-Host ""
    Write-Host "Oder im Projekt-Root:" -ForegroundColor Red
    Write-Host "  cd $scriptDir" -ForegroundColor Yellow
    Write-Host "  composer install" -ForegroundColor Yellow
    exit 1
}

$php = Get-Command php -ErrorAction SilentlyContinue
if (-not $php) {
    Write-Host "PHP nicht im PATH gefunden. Bitte füge PHP zu deinem PATH hinzu." -ForegroundColor Red
    exit 1
}

$phpcsXml = Join-Path $suiteDir "phpcs.xml.dist"
# Run from suite dir so ruleset paths (./includes etc.) resolve correctly
Push-Location $suiteDir
try {
    & php $vendorPhpcs --standard=$phpcsXml @args
} finally {
    Pop-Location
}
