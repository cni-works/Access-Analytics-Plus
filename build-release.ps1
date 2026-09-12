param(
	[switch] $Force
)

$ErrorActionPreference = 'Stop'

$projectRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
$slug = 'access-analytics-plus'
$pluginFile = Join-Path $projectRoot 'access-analytics-plus.php'
$readmeFile = Join-Path $projectRoot 'readme.txt'
$updaterFile = Join-Path $projectRoot 'includes/updater/class-github-release-updater.php'
$releaseDirectory = Join-Path $projectRoot 'release'
$expectedUpdateUri = 'https://github.com/cni-works/Access-Analytics-Plus'

$pluginContents = Get-Content -Raw -Encoding UTF8 -LiteralPath $pluginFile
$readmeContents = Get-Content -Raw -Encoding UTF8 -LiteralPath $readmeFile
$versionMatch = [regex]::Match($pluginContents, '(?m)^\s*\*\s*Version:\s*([^\s]+)\s*$')
$updateUriMatch = [regex]::Match($pluginContents, '(?m)^\s*\*\s*Update URI:\s*(\S+)\s*$')
$stableTagMatch = [regex]::Match($readmeContents, '(?m)^Stable tag:\s*([^\s]+)\s*$')

if (-not $versionMatch.Success) { throw 'Unable to read Version from access-analytics-plus.php.' }
if (-not $updateUriMatch.Success) { throw 'Unable to read Update URI from access-analytics-plus.php.' }
if (-not $stableTagMatch.Success) { throw 'Unable to read Stable tag from readme.txt.' }
if (-not (Test-Path -LiteralPath $updaterFile -PathType Leaf)) {
	throw 'GitHub updater is missing: includes/updater/class-github-release-updater.php.'
}

$version = $versionMatch.Groups[1].Value
$updateUri = $updateUriMatch.Groups[1].Value
$stableTag = $stableTagMatch.Groups[1].Value
if ($version -ne $stableTag) { throw "Version ($version) and Stable tag ($stableTag) do not match." }
if ($updateUri -ne $expectedUpdateUri) { throw "Unexpected Update URI: $updateUri" }
if ($version -notmatch '^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(-[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?$') {
	throw "Version is not a supported X.Y.Z or X.Y.Z-prerelease value: $version"
}

$zipName = "$slug-$version.zip"
$destinationZip = Join-Path $releaseDirectory $zipName
if ((Test-Path -LiteralPath $destinationZip) -and -not $Force) {
	throw "A ZIP for this Version already exists: $destinationZip`nUse -Force only when replacement is intended."
}

$temporaryRoot = Join-Path ([System.IO.Path]::GetTempPath()) ("aap-release-" + [guid]::NewGuid().ToString('N'))
$packageRoot = Join-Path $temporaryRoot $slug
$candidateZip = Join-Path $temporaryRoot $zipName
$requiredRootFiles = @(
	'access-analytics-plus.php',
	'uninstall.php',
	'readme.txt',
	'THIRD_PARTY_NOTICES.md'
)
$requiredDirectories = @('assets', 'data', 'includes')

try {
	New-Item -ItemType Directory -Path $packageRoot -Force | Out-Null
	foreach ($name in $requiredRootFiles) {
		$source = Join-Path $projectRoot $name
		if (-not (Test-Path -LiteralPath $source -PathType Leaf)) { throw "Required file is missing: $name" }
		Copy-Item -LiteralPath $source -Destination $packageRoot -Force
	}
	foreach ($name in $requiredDirectories) {
		$source = Join-Path $projectRoot $name
		if (-not (Test-Path -LiteralPath $source -PathType Container)) { throw "Required directory is missing: $name" }
		Copy-Item -LiteralPath $source -Destination $packageRoot -Recurse -Force
	}

	$osMetadataFiles = @(Get-ChildItem -LiteralPath $packageRoot -Recurse -Force -File | Where-Object {
		$_.Name -eq 'desktop.ini' -or $_.Name -eq 'Thumbs.db' -or $_.Name -eq '.DS_Store'
	})
	foreach ($metadataFile in $osMetadataFiles) {
		Remove-Item -LiteralPath $metadataFile.FullName -Force
	}

	$unexpectedFiles = @(Get-ChildItem -LiteralPath $packageRoot -Recurse -Force | Where-Object {
		$_.Extension -in @('.zip', '.log', '.bak') -or
		$_.Name -like '.env*' -or
		$_.Name.EndsWith('~')
	})
	if ($unexpectedFiles.Count -gt 0) {
		throw ('Unexpected package files found: ' + (($unexpectedFiles | ForEach-Object FullName) -join ', '))
	}

	Add-Type -AssemblyName System.IO.Compression
	Add-Type -AssemblyName System.IO.Compression.FileSystem
	$zipStream = [System.IO.File]::Open($candidateZip, [System.IO.FileMode]::CreateNew)
	try {
		$zipArchive = [System.IO.Compression.ZipArchive]::new($zipStream, [System.IO.Compression.ZipArchiveMode]::Create, $false)
		try {
			Get-ChildItem -LiteralPath $packageRoot -Recurse -Force -File | ForEach-Object {
				$relativePath = $_.FullName.Substring($packageRoot.Length).TrimStart('\', '/')
				$entryName = $slug + '/' + $relativePath.Replace('\', '/')
				[System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile(
					$zipArchive,
					$_.FullName,
					$entryName,
					[System.IO.Compression.CompressionLevel]::Optimal
				) | Out-Null
			}
		} finally {
			if ($null -ne $zipArchive) { $zipArchive.Dispose() }
		}
	} finally {
		$zipStream.Dispose()
	}

	$archive = [System.IO.Compression.ZipFile]::OpenRead($candidateZip)
	try {
		$rawEntryNames = @($archive.Entries | ForEach-Object FullName)
		$entryNames = @($rawEntryNames | ForEach-Object { $_.Replace('\', '/') })
		$pluginEntry = $archive.GetEntry("$slug/access-analytics-plus.php")
		$packagedPluginContents = ''
		if ($null -ne $pluginEntry) {
			$stream = $pluginEntry.Open()
			try {
				$reader = [System.IO.StreamReader]::new($stream, [System.Text.UTF8Encoding]::new($false), $true)
				try { $packagedPluginContents = $reader.ReadToEnd() } finally { $reader.Dispose() }
			} finally { $stream.Dispose() }
		}
	} finally { $archive.Dispose() }

	$errors = [System.Collections.Generic.List[string]]::new()
	if ($entryNames.Count -eq 0) { $errors.Add('The ZIP is empty.') }
	if (@($rawEntryNames | Where-Object { $_.Contains('\') }).Count -gt 0) { $errors.Add('A ZIP entry contains a Windows backslash.') }
	if (@($entryNames | Where-Object { -not $_.StartsWith("$slug/") }).Count -gt 0) { $errors.Add("The top-level folder is not $slug.") }
	if ($entryNames -notcontains "$slug/access-analytics-plus.php") { $errors.Add('The main plugin file is missing.') }
	if ($entryNames -notcontains "$slug/includes/updater/class-github-release-updater.php") { $errors.Add('The GitHub updater is missing.') }
	if ($entryNames -notcontains "$slug/data/dbip-country-lite-2026-09.mmdb") { $errors.Add('The bundled DB-IP Country Lite database is missing.') }
	if ($entryNames -notcontains "$slug/data/aap-japan-prefecture-2026-09.mmdb") { $errors.Add('The bundled Japan prefecture database is missing.') }
	if ($entryNames -notcontains "$slug/data/aap-japan-prefecture-2026-09.manifest.json") { $errors.Add('The Japan prefecture data manifest is missing.') }
	if ($entryNames -notcontains "$slug/includes/vendor/maxmind-db-reader/LICENSE") { $errors.Add('The MaxMind DB Reader license is missing.') }
	if (@($entryNames | Where-Object { $_.StartsWith("$slug/$slug/") }).Count -gt 0) { $errors.Add('A duplicate top-level folder was found.') }

	$packagedVersionMatch = [regex]::Match($packagedPluginContents, '(?m)^\s*\*\s*Version:\s*([^\s]+)\s*$')
	$packagedUpdateUriMatch = [regex]::Match($packagedPluginContents, '(?m)^\s*\*\s*Update URI:\s*(\S+)\s*$')
	if (-not $packagedVersionMatch.Success -or $packagedVersionMatch.Groups[1].Value -ne $version) {
		$errors.Add('The ZIP Plugin Header Version does not match the source Version.')
	}
	if (-not $packagedUpdateUriMatch.Success -or $packagedUpdateUriMatch.Groups[1].Value -ne $updateUri) {
		$errors.Add('The ZIP Update URI does not match the source Update URI.')
	}

	$forbiddenPatterns = @(
		'(^|/)\.git(/|$)', '(^|/)\.github(/|$)', '(^|/)docs(/|$)', '(^|/)tools(/|$)',
		'(^|/)release(/|$)', '(^|/)AGENTS\.md$', '(^|/)README\.md$',
		'(^|/)build-release\.ps1$', '(^|/)\.gitignore$', '(^|/)\.gitattributes$',
		'(^|/)\.env(?:\.[^/]+)?$', '(^|/)[^/]+\.(?:zip|log|bak)$',
		'(^|/)desktop\.ini$', '(^|/)Thumbs\.db$', '(^|/)\.DS_Store$'
	)
	foreach ($pattern in $forbiddenPatterns) {
		if (@($entryNames | Where-Object { $_ -match $pattern }).Count -gt 0) {
			$errors.Add("A development-only file was found: $pattern")
		}
	}
	if ($errors.Count -gt 0) { throw ("ZIP structure validation failed.`n- " + ($errors -join "`n- ")) }

	New-Item -ItemType Directory -Path $releaseDirectory -Force | Out-Null
	[System.IO.File]::Copy($candidateZip, $destinationZip, $true)
	Write-Output "ZIP created: $destinationZip"
	Write-Output "Version: $version"
	Write-Output "Update URI: $updateUri"
	Write-Output "Entry count: $($entryNames.Count)"
	Write-Output 'Structure validation: passed'
} finally {
	$resolvedTemporaryRoot = [System.IO.Path]::GetFullPath($temporaryRoot)
	$resolvedSystemTemp = [System.IO.Path]::GetFullPath([System.IO.Path]::GetTempPath())
	if (
		$resolvedTemporaryRoot.StartsWith($resolvedSystemTemp, [System.StringComparison]::OrdinalIgnoreCase) -and
		(Split-Path -Leaf $resolvedTemporaryRoot).StartsWith('aap-release-', [System.StringComparison]::OrdinalIgnoreCase) -and
		(Test-Path -LiteralPath $resolvedTemporaryRoot)
	) {
		Remove-Item -LiteralPath $resolvedTemporaryRoot -Recurse -Force
	}
}
