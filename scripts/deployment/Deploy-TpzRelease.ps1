[CmdletBinding()]
param(
    [Parameter(Mandatory)]
    [ValidateSet('staging', 'uat')]
    [string] $Environment,

    [Parameter(Mandatory)]
    [ValidatePattern('^[a-f0-9]{40}$')]
    [string] $Commit,

    [Parameter(Mandatory)]
    [ValidatePattern('^[a-f0-9]{40}$')]
    [string] $ExpectedBaseline,

    [string[]] $ExpectedMigrations = @(),

    [switch] $DataChange,

    [switch] $BuildAssets
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$repository = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..\..')).Path
$ssh = Join-Path $env:WINDIR 'System32\OpenSSH\ssh.exe'
$scp = Join-Path $env:WINDIR 'System32\OpenSSH\scp.exe'
$sshAdd = Join-Path $env:WINDIR 'System32\OpenSSH\ssh-add.exe'
$sshKeygen = Join-Path $env:WINDIR 'System32\OpenSSH\ssh-keygen.exe'
$key = Join-Path $env:USERPROFILE '.ssh\tpz_erp_vps_2026'
$publicKey = "$key.pub"
$remoteScript = Join-Path $PSScriptRoot 'Apply-TpzRelease.sh'
foreach ($path in @($ssh, $scp, $sshAdd, $sshKeygen, $key, $publicKey, $remoteScript)) {
    if (-not (Test-Path -LiteralPath $path -PathType Leaf)) {
        throw 'A required existing deployment tool, key, or script is unavailable.'
    }
}

# Use the existing Windows OpenSSH agent. Fail before an asset upload or any
# server mutation if this specific authorized key is not already unlocked.
try {
    $agentService = Get-Service -Name ssh-agent -ErrorAction Stop
    if ($agentService.Status -ne 'Running') {
        Start-Service -Name ssh-agent -ErrorAction Stop
    }
} catch {
    throw 'The existing Windows OpenSSH agent is unavailable. Start it and unlock the authorized key once; no deployment was started.'
}
$publicKeyDescription = (& $sshKeygen -lf $publicKey 2>$null) -join ' '
if ($LASTEXITCODE -ne 0 -or $publicKeyDescription -notmatch 'SHA256:[A-Za-z0-9+/]+') {
    throw 'The existing deployment public key cannot be verified.'
}
$expectedFingerprint = $Matches[0]
$loadedKeys = (& $sshAdd -l 2>$null) -join "`n"
if ($LASTEXITCODE -ne 0 -or -not $loadedKeys.Contains($expectedFingerprint)) {
    throw 'The existing deployment key is not loaded in Windows ssh-agent. Unlock it once outside this helper; do not put its passphrase in a script.'
}

$head = (& git -C $repository rev-parse HEAD).Trim()
if ($LASTEXITCODE -ne 0 -or $head -ne $Commit) {
    throw 'The local worktree is not at the approved release commit.'
}
$changes = & git -C $repository status --porcelain --untracked-files=normal
if ($LASTEXITCODE -ne 0 -or $changes) {
    throw 'The local release worktree is not clean.'
}
foreach ($name in $ExpectedMigrations) {
    if ($name -notmatch '^[0-9]{4}_[0-9]{2}_[0-9]{2}_[0-9]{6}_[a-z0-9_]+$') {
        throw 'An expected migration name is invalid.'
    }
}
if ($ExpectedMigrations.Count -ne (@($ExpectedMigrations | Sort-Object -Unique)).Count) {
    throw 'Expected migration names must be unique.'
}

$database = if ($Environment -eq 'staging') { 'tpz_erp_staging' } else { 'tpz_erp_uat' }
$assetName = 'NONE'
$assetHash = 'NONE'
$archive = $null
try {
    if ($BuildAssets) {
        if (-not (Test-Path -LiteralPath (Join-Path $repository 'node_modules') -PathType Container)) {
            throw 'Local Node dependencies are unavailable. No packages will be installed automatically.'
        }
        Push-Location -LiteralPath $repository
        try {
            & npm.cmd run build
            if ($LASTEXITCODE -ne 0) { throw 'The local frontend build failed.' }
        } finally {
            Pop-Location
        }
        $build = Join-Path $repository 'public\build'
        if (-not (Test-Path -LiteralPath (Join-Path $build 'manifest.json') -PathType Leaf)) {
            throw 'The frontend build has no Vite manifest.'
        }
        if (Get-ChildItem -LiteralPath $build -Recurse -Force -Attributes ReparsePoint) {
            throw 'The frontend build contains a symlink or junction and cannot be packaged safely.'
        }
        $assetName = 'tpz-' + $Environment + '-' + $Commit.Substring(0, 8) + '-' + [guid]::NewGuid().ToString('N') + '-assets.tar.gz'
        $archive = Join-Path ([IO.Path]::GetTempPath()) $assetName
        & tar.exe -czf $archive -C (Join-Path $repository 'public') build
        if ($LASTEXITCODE -ne 0 -or -not (Test-Path -LiteralPath $archive -PathType Leaf)) {
            throw 'Could not package the verified local frontend build.'
        }
        $assetHash = (Get-FileHash -LiteralPath $archive -Algorithm SHA256).Hash.ToLowerInvariant()
        & $scp -i $key -o BatchMode=yes -o StrictHostKeyChecking=yes -o HostKeyAlgorithms=ssh-ed25519 -o IdentitiesOnly=yes `
            $archive ('tpzadmin@187.53.133.85:/home/tpzadmin/.tpz-deployments/' + $assetName)
        if ($LASTEXITCODE -ne 0) { throw 'The secure frontend upload failed; deployment was not started.' }
    }

    $source = [IO.File]::ReadAllText($remoteScript).Replace("`r`n", "`n")
    $payload = [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($source))
    $migrations = [string]::Join(',', $ExpectedMigrations)
    $dataFlag = if ($DataChange) { 'true' } else { 'false' }
    # Every interpolated argument above has a fixed value or is validated by a
    # strict whitelist. The script is sent in one SSH session and never edited
    # into the application's source tree on the VPS.
    $command = "echo $payload | base64 -d | bash -s -- '$Environment' '$Commit' '$ExpectedBaseline' '$database' '$migrations' '$dataFlag' '$assetName' '$assetHash'"
    & $ssh -i $key -o BatchMode=yes -o StrictHostKeyChecking=yes -o HostKeyAlgorithms=ssh-ed25519 -o IdentitiesOnly=yes `
        -o ConnectTimeout=20 -T tpzadmin@187.53.133.85 $command
    if ($LASTEXITCODE -ne 0) {
        throw 'The deployment stopped. Do not rerun automatically; inspect its STOPPED_STAGE and retained release evidence.'
    }
} finally {
    if ($null -ne $archive -and (Test-Path -LiteralPath $archive -PathType Leaf)) {
        Remove-Item -LiteralPath $archive
    }
}
