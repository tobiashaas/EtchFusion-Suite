# migrate-post.ps1 — Einzelnen Bricks Post re-migrieren und in Etch speichern
# Usage: .\migrate-post.ps1 -BricksId 25195 -EtchId 929
param(
    [Parameter(Mandatory)][int]$BricksId,
    [Parameter(Mandatory)][int]$EtchId
)

$TmpFile = "C:\Users\thaas\AppData\Local\Temp\efs_migrated_${BricksId}.txt"
$PluginPath = "/var/www/html/wp-content/plugins/etch-fusion-suite"

Write-Host "Converting Bricks post $BricksId..." -ForegroundColor Cyan

# 1. Convert on Bricks side
$ConvertScript = @"
<?php
if (!defined('ABSPATH')) exit;
`$bricks_post_id = $BricksId;
`$container = etch_fusion_suite_container();
`$content_parser = `$container->get('content_parser');
`$gutenberg_generator = `$container->get('gutenberg_generator');
`$bricks_content = `$content_parser->parse_bricks_content(`$bricks_post_id);
if (!`$bricks_content || !isset(`$bricks_content['elements'])) { echo "NO BRICKS CONTENT\n"; exit; }
echo 'Elements: ' . count(`$bricks_content['elements']) . "\n";
`$gutenberg = `$gutenberg_generator->generate_gutenberg_blocks(`$bricks_content['elements']);
file_put_contents('/tmp/efs_migrated_post.txt', `$gutenberg);
echo 'Done. Length: ' . strlen(`$gutenberg) . "\n";
"@

$ConvertScript | Set-Content -Path $TmpFile -Encoding UTF8
docker cp $TmpFile "bricks-cli:/tmp/efs_convert.php"
docker exec bricks-cli wp --allow-root --path=/var/www/html eval-file /tmp/efs_convert.php

if ($LASTEXITCODE -ne 0) {
    Write-Host "Conversion failed!" -ForegroundColor Red
    exit 1
}

# 2. Copy converted content
docker cp "bricks-cli:/tmp/efs_migrated_post.txt" $TmpFile
docker cp $TmpFile "etch-cli:/tmp/efs_migrated_post.txt"

Write-Host "Updating Etch post $EtchId..." -ForegroundColor Cyan

# 3. Update on Etch side
$UpdateScript = @"
<?php
if (!defined('ABSPATH')) exit;
`$etch_post_id = $EtchId;
`$content = file_get_contents('/tmp/efs_migrated_post.txt');
if (!`$content) { echo "No content!\n"; exit; }
`$result = wp_update_post(array('ID' => `$etch_post_id, 'post_content' => `$content), true);
is_wp_error(`$result) ? print('Error: ' . `$result->get_error_message() . "\n") : print("Updated post `$etch_post_id\n");
"@

$UpdateScript | Set-Content -Path $TmpFile -Encoding UTF8
docker cp $TmpFile "etch-cli:/tmp/efs_update.php"
docker exec etch-cli wp --allow-root --path=/var/www/html eval-file /tmp/efs_update.php

Write-Host "Done! Check: http://localhost:8889/?p=$EtchId" -ForegroundColor Green
