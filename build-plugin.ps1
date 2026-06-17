# AstraHub WordPress plugin packaging script (Windows PowerShell)
# Usage: run in plugin-wp-astrahub directory:  ./build-plugin.ps1
# Output: dist/wp-astrahub.zip (top-level folder wp-astrahub/, upload directly in WP admin)

$ErrorActionPreference = "Stop"
$root = $PSScriptRoot
$slug = "wp-astrahub"
$staging = Join-Path $root "dist\$slug"
$distDir = Join-Path $root "dist"
$zipPath = Join-Path $distDir "$slug.zip"

# 1) Build frontend console (produces assets/dist/*.js|css)
Write-Host "[1/4] Building frontend console..." -ForegroundColor Cyan
Push-Location (Join-Path $root "console")
try {
    npm run build
    if ($LASTEXITCODE -ne 0) { throw "frontend build failed" }
} finally {
    Pop-Location
}

# 2) Verify build artifacts exist
$adminJs = Join-Path $root "assets\dist\wp-astrahub-admin.js"
if (-not (Test-Path $adminJs)) { throw "missing artifact assets/dist/wp-astrahub-admin.js" }

# 3) Prepare staging dir (copy only runtime files)
Write-Host "[2/4] Preparing package contents..." -ForegroundColor Cyan
if (-not (Test-Path $distDir)) { New-Item -ItemType Directory -Path $distDir | Out-Null }
if (Test-Path $staging) { Remove-Item -Recurse -Force $staging }
New-Item -ItemType Directory -Path $staging | Out-Null

Copy-Item (Join-Path $root "wp-astrahub.php") $staging
Copy-Item (Join-Path $root "uninstall.php") $staging
Copy-Item (Join-Path $root "readme.txt") $staging
Copy-Item (Join-Path $root "LICENSE") $staging
Copy-Item (Join-Path $root "includes") (Join-Path $staging "includes") -Recurse
New-Item -ItemType Directory -Path (Join-Path $staging "assets\dist") | Out-Null
Copy-Item (Join-Path $root "assets\dist\*") (Join-Path $staging "assets\dist") -Recurse

# Frontend galaxy widget assets (live2d mascot + status bubble), ported 1:1 from plugin-astrahub.
Copy-Item (Join-Path $root "assets\widget") (Join-Path $staging "assets\widget") -Recurse
Copy-Item (Join-Path $root "assets\live2d") (Join-Path $staging "assets\live2d") -Recurse
Copy-Item (Join-Path $root "assets\live2d-widget") (Join-Path $staging "assets\live2d-widget") -Recurse

# 4) Zip it  —— 用 bsdtar（Windows 自带）打包，内部用正斜杠，Linux unzip 才能正确还原目录。
#    不能用 Compress-Archive：PowerShell 会用反斜杠当分隔符，导致 Linux 下解出
#    一堆名字带 \ 的扁平文件（wp-astrahub\wp-astrahub.php），WP 找不到主文件。
Write-Host "[3/4] Creating zip (via tar)..." -ForegroundColor Cyan
if (Test-Path $zipPath) { Remove-Item -Force $zipPath }
Push-Location $distDir
try {
    # 在 dist 目录下打包 wp-astrahub 子目录，zip 内顶层即 wp-astrahub/
    tar -a -c -f $zipPath $slug
    if ($LASTEXITCODE -ne 0) { throw "tar zip failed" }
} finally {
    Pop-Location
}
Remove-Item -Recurse -Force $staging

Write-Host "[4/4] Done -> $zipPath" -ForegroundColor Green
Write-Host "Upload this zip in WP admin: Plugins -> Add New -> Upload Plugin." -ForegroundColor Green
