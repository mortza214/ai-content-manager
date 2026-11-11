# بسته‌سازی محلی با فهرست مجاز؛ هیچ شبکه، نصب یا استقراری اجرا نمی‌شود.
$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem
$projectRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).Path
$archivePath = Join-Path $projectRoot 'ai-content-manager.zip'
$temporaryPath = Join-Path $projectRoot ('acm-package-' + [guid]::NewGuid().ToString('N') + '.tmp')
$files = @((Get-Item -LiteralPath (Join-Path $projectRoot 'ai-content-manager.php')))
$files += @((Get-Item -LiteralPath (Join-Path $projectRoot 'README.md')))
$files += @(Get-ChildItem -LiteralPath $projectRoot -Filter 'RELEASE-*.md' -File)
foreach ($folder in @('includes', 'assets', 'tests')) {
    $files += @(Get-ChildItem -LiteralPath (Join-Path $projectRoot $folder) -File -Recurse)
}
$archive = [System.IO.Compression.ZipFile]::Open($temporaryPath, [System.IO.Compression.ZipArchiveMode]::Create)
try {
    foreach ($file in $files) {
        $relative = $file.FullName.Substring($projectRoot.Length + 1).Replace('\', '/')
        if ($relative -notmatch '\.(php|css|js|md)$') { throw 'نوع فایل بسته‌بندی مجاز نیست؛ فهرست فایل‌ها را بررسی کنید.' }
        [void][System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($archive, $file.FullName, ('ai-content-manager/' + $relative), [System.IO.Compression.CompressionLevel]::Optimal)
    }
} finally { $archive.Dispose() }
$archive = [System.IO.Compression.ZipFile]::OpenRead($temporaryPath)
try {
    if ($archive.Entries.Count -ne $files.Count) { throw 'تعداد فایل‌های بسته با سورس برابر نیست.' }
    $names = @{}
    foreach ($entry in $archive.Entries) {
        if ($names.ContainsKey($entry.FullName) -or $entry.FullName.Contains('\') -or $entry.FullName.Contains('..') -or -not $entry.FullName.StartsWith('ai-content-manager/')) { throw 'مسیر تکراری یا نامعتبر در بسته پیدا شد.' }
        $names[$entry.FullName] = $true
        $sourcePath = Join-Path $projectRoot $entry.FullName.Substring('ai-content-manager/'.Length)
        $expected = (Get-FileHash -LiteralPath $sourcePath -Algorithm SHA256).Hash
        $stream = $entry.Open()
        $sha = [System.Security.Cryptography.SHA256]::Create()
        try { $actual = [BitConverter]::ToString($sha.ComputeHash($stream)).Replace('-', '') } finally { $stream.Dispose(); $sha.Dispose() }
        if ($actual -ne $expected) { throw 'محتوای بسته با سورس یکسان نیست.' }
    }
} finally { $archive.Dispose() }
# هر دو مسیر فایل، از ریشه مشخص پروژه ساخته شده‌اند؛ فایل محلی قبلی جایگزین می‌شود.
Move-Item -LiteralPath $temporaryPath -Destination $archivePath -Force
Write-Output ('بسته محلی ساخته و بررسی شد؛ تعداد فایل‌ها: ' + $files.Count)
Write-Output ('SHA256: ' + (Get-FileHash -LiteralPath $archivePath -Algorithm SHA256).Hash)
