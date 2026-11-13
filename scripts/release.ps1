# Builds the two GoDaddy upload archives into dist\<timestamp>\ (no Node or Composer needed on the server):
#   billing-api.zip  -> extract into ~/billing-api/   (src, config, migrations, vendor without dev packages)
#   public_html.zip  -> extract into ~/public_html/   (React build, api/index.php, .htaccess; uploads/ untouched)
# Then on the server: php ~/billing-api/yii migrate:up --no-interaction
# Usage: powershell -ExecutionPolicy Bypass -File scripts/release.ps1 [-SkipTests]
param([switch]$SkipTests)
$ErrorActionPreference = 'Stop'
Set-Location (Split-Path $PSScriptRoot -Parent)

function Step($name, [scriptblock]$block) {
    Write-Host "==> $name" -ForegroundColor Cyan
    & $block
    if ($LASTEXITCODE -ne 0) { throw "$name failed (exit $LASTEXITCODE)" }
}

docker compose up -d php82 | Out-Null

if (-not $SkipTests) {
    Step 'Backend tests (PHP 8.2)' { docker compose exec -T php82 vendor/bin/codecept run Unit --no-colors }
}

# The React source lives in rental-billing-frontend. public_html already holds that build.

$out = 'runtime/release'
Step 'Package backend (composer --no-dev)' { Get-Content -Raw scripts/package.php | docker compose exec -T php82 php -- backend /var/www/billing-api "/var/www/billing-api/$out/billing-api.zip" }
Step 'Package public_html' { Get-Content -Raw scripts/package.php | docker compose exec -T php82 php -- public /var/www/html "/var/www/billing-api/$out/public_html.zip" }

$dist = Join-Path 'dist' (Get-Date -Format 'yyyyMMdd-HHmm')
New-Item -ItemType Directory -Force $dist | Out-Null
Move-Item -Force "backend/$out/*.zip" $dist
Remove-Item -Recurse -Force "backend/$out"

# Keep the last three releases for rollback
Get-ChildItem dist -Directory | Sort-Object Name -Descending | Select-Object -Skip 3 | Remove-Item -Recurse -Force

Write-Host ''
Write-Host "Release ready in $dist" -ForegroundColor Green
Get-ChildItem $dist | ForEach-Object { '  {0,-18} {1,8:N1} MB' -f $_.Name, ($_.Length / 1MB) }
Write-Host @'

Upload steps (cPanel File Manager or SFTP):
  1. Extract billing-api.zip into ~/billing-api/  (keep the existing ~/billing-api/.env)
  2. Extract public_html.zip into ~/public_html/  (uploads/ is not in the archive)
  3. Terminal: php ~/billing-api/yii migrate:up --no-interaction
  4. Purge the Cloudflare cache for /index.html and /sw.js
'@
