cd E:\Github\EtchFusion-Suite\etch-fusion-suite
$files = Get-ChildItem -Path "includes" -Name "*.php" -Recurse
$passed = 0
$failed = 0
$failures = @()

foreach($file in $files) {
    $result = & php -l $file.FullName 2>&1
    if($LASTEXITCODE -eq 0) {
        $passed++
    } else {
        $failed++
        $failures += $file.FullName
    }
}

Write-Host "PHP Syntax Check Results:"
Write-Host "========================="
Write-Host "Total files: $($files.Count)"
Write-Host "Passed: $passed"
Write-Host "Failed: $failed"

if($failed -gt 0) {
    Write-Host ""
    Write-Host "Failed files:"
    foreach($f in $failures) {
        Write-Host "  - $f"
    }
}
