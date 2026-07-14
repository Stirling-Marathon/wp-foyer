param(
    [string]$OutputPath = "dist/wordpress-foyer-roku.zip",
    [string]$ConfigPath = "",
    [string]$ApiBaseUrl = ""
)

$ErrorActionPreference = "Stop"
$root = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path
$manifest = Join-Path $root "manifest"
$localConfig = Join-Path $root "config.local.json"

function Get-ConfiguredApiBaseUrl {
    if ($ApiBaseUrl -ne "") {
        return $ApiBaseUrl
    }
    if ($env:WORDPRESS_FOYER_API_BASE_URL -and $env:WORDPRESS_FOYER_API_BASE_URL.Trim() -ne "") {
        return $env:WORDPRESS_FOYER_API_BASE_URL
    }

    $path = $localConfig
    if ($ConfigPath -ne "") {
        $path = (Resolve-Path -LiteralPath $ConfigPath).Path
    }
    if (-not (Test-Path -LiteralPath $path)) {
        throw "Missing Roku API configuration. Create roku-app/config.local.json or pass -ConfigPath / -ApiBaseUrl."
    }

    try {
        $config = Get-Content -Raw -LiteralPath $path | ConvertFrom-Json
    }
    catch {
        throw "Invalid JSON in Roku API configuration: $path"
    }

    if (-not ($config.PSObject.Properties.Name -contains "apiBaseUrl")) {
        throw "Roku API configuration must contain apiBaseUrl."
    }
    return $config.apiBaseUrl
}

function Normalize-ApiBaseUrl([object]$value) {
    if ($value -isnot [string]) {
        throw "apiBaseUrl must be a string."
    }

    $url = $value.Trim().TrimEnd("/")
    if ($url -eq "") {
        throw "apiBaseUrl must not be empty."
    }
    if ($url -notmatch "^https?://") {
        throw "apiBaseUrl must begin with http:// or https://."
    }
    if ($url -match "[`"'\s]") {
        throw "apiBaseUrl must not contain quotes or whitespace."
    }

    $uri = [System.Uri]$url
    if ($uri.UserInfo -ne "") {
        throw "apiBaseUrl must not contain credentials."
    }
    if ($uri.AbsolutePath -match "/displays(/.*)?$") {
        throw "apiBaseUrl must be the REST namespace root, not a displays endpoint."
    }

    return $url
}

function ConvertTo-BrightScriptString([string]$value) {
    return $value.Replace("\", "\\").Replace("""", """""")
}

if (-not (Test-Path -LiteralPath $manifest)) {
    throw "Missing Roku manifest at $manifest"
}

$required = @(
    "source/main.brs",
    "components/MainScene.xml",
    "components/MainScene.brs",
    "components/SignagePlayer.xml",
    "components/SignagePlayer.brs",
    "components/ManifestTask.xml",
    "components/CacheManifestTask.xml",
    "components/ImageDownloadTask.xml"
)

foreach ($relative in $required) {
    $path = Join-Path $root $relative
    if (-not (Test-Path -LiteralPath $path)) {
        throw "Missing required Roku runtime file: $relative"
    }
}

$apiBase = Normalize-ApiBaseUrl (Get-ConfiguredApiBaseUrl)

$dist = Join-Path $root "dist"
$buildRoot = Join-Path $root ".build"
$staging = Join-Path $buildRoot ("package-" + [guid]::NewGuid().ToString("N"))
New-Item -ItemType Directory -Force -Path $dist | Out-Null
New-Item -ItemType Directory -Force -Path $buildRoot | Out-Null

$zipPath = Join-Path $root $OutputPath
if (Test-Path -LiteralPath $zipPath) {
    Remove-Item -LiteralPath $zipPath -Force
}
$tempZip = Join-Path $dist ("wordpress-foyer-roku-" + [guid]::NewGuid().ToString("N") + ".tmp.zip")

try {
    New-Item -ItemType Directory -Force -Path $staging | Out-Null
    Copy-Item -LiteralPath $manifest -Destination (Join-Path $staging "manifest")
    foreach ($dir in @("source", "components", "images")) {
        $source = Join-Path $root $dir
        if (Test-Path -LiteralPath $source) {
            Copy-Item -LiteralPath $source -Destination (Join-Path $staging $dir) -Recurse
        }
    }

    $generated = Join-Path $staging "source/AppConfig.generated.brs"
    $escaped = ConvertTo-BrightScriptString $apiBase
    Set-Content -LiteralPath $generated -Encoding ASCII -Value @(
        "function GetConfiguredApiBaseUrl() as String",
        "    return ""$escaped""",
        "end function"
    )

    Add-Type -AssemblyName System.IO.Compression
    Add-Type -AssemblyName System.IO.Compression.FileSystem
    $zip = [System.IO.Compression.ZipFile]::Open($tempZip, [System.IO.Compression.ZipArchiveMode]::Create)
    try {
        $files = Get-ChildItem -LiteralPath $staging -Recurse -File | Sort-Object FullName
        foreach ($file in $files) {
            $relative = $file.FullName.Substring($staging.Length).TrimStart('\', '/')
            $relative = $relative -replace '\\', '/'
            [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($zip, $file.FullName, $relative, [System.IO.Compression.CompressionLevel]::Optimal) | Out-Null
        }
    }
    finally {
        $zip.Dispose()
    }
    Move-Item -LiteralPath $tempZip -Destination $zipPath -Force
}
finally {
    if (Test-Path -LiteralPath $tempZip) {
        Remove-Item -LiteralPath $tempZip -Force
    }
    if (Test-Path -LiteralPath $staging) {
        Remove-Item -LiteralPath $staging -Recurse -Force
    }
}

Write-Host "Created $zipPath"
