param(
    [Parameter(Mandatory = $true)]
    [string] $ManifestPath,

    [string] $CredentialDir = 'C:\Szaki24-dev\ACTIVE_PROJECTS\mezoenergy.hu\_codex_comm\secrets\nethely_deploy',

    [string] $LogDir = 'C:\Szaki24-dev\ACTIVE_PROJECTS\mezoenergy.hu\_codex_comm\deploy\logs',

    [switch] $DryRun
)

$ErrorActionPreference = 'Stop'

function Find-WinScp {
    $command = Get-Command 'winscp.com' -ErrorAction SilentlyContinue
    if ($command) {
        return $command.Source
    }

    $candidates = @(
        'C:\Program Files (x86)\WinSCP\WinSCP.com',
        'C:\Program Files\WinSCP\WinSCP.com'
    )

    foreach ($candidate in $candidates) {
        if (Test-Path -LiteralPath $candidate) {
            return $candidate
        }
    }

    throw 'WinSCP command-line client was not found. Install WinSCP or add winscp.com to PATH before deploying.'
}

function Join-RemotePath {
    param(
        [Parameter(Mandatory = $true)]
        [string] $Root,

        [Parameter(Mandatory = $true)]
        [string] $RelativePath
    )

    return ('/' + (($Root.Trim('/') + '/' + $RelativePath.Trim('/')) -replace '\\', '/')).Replace('//', '/')
}

function Assert-DeployPathAllowed {
    param(
        [Parameter(Mandatory = $true)]
        [string] $RelativePath
    )

    $normalized = ($RelativePath -replace '\\', '/').TrimStart('/').ToLowerInvariant()

    $blockedExact = @(
        '.env',
        'storage/config/local.php',
        'storage/config/local.secret.php'
    )

    if ($blockedExact -contains $normalized) {
        throw "Blocked deploy path: $RelativePath"
    }

    $blockedPrefixes = @(
        '.git/',
        '.github/',
        '_codex_comm/',
        'docs/',
        'storage/config/',
        'private_logs/'
    )

    foreach ($prefix in $blockedPrefixes) {
        if ($normalized.StartsWith($prefix)) {
            throw "Blocked deploy path: $RelativePath"
        }
    }

    $blockedExtensions = @('.bak', '.backup', '.dump', '.log', '.sql', '.zip')
    foreach ($extension in $blockedExtensions) {
        if ($normalized.EndsWith($extension)) {
            throw "Blocked deploy file extension: $RelativePath"
        }
    }
}

function Get-PlainTextPassword {
    param(
        [Parameter(Mandatory = $true)]
        [securestring] $SecureString
    )

    $bstr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($SecureString)
    try {
        return [Runtime.InteropServices.Marshal]::PtrToStringBSTR($bstr)
    } finally {
        if ($bstr -ne [IntPtr]::Zero) {
            [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($bstr)
        }
    }
}

function Read-DeployCredential {
    param(
        [Parameter(Mandatory = $true)]
        [string] $Directory
    )

    $credentialPath = Join-Path $Directory 'credential.clixml'
    $configPath = Join-Path $Directory 'deploy-config.json'

    if (-not (Test-Path -LiteralPath $credentialPath) -or -not (Test-Path -LiteralPath $configPath)) {
        throw "Deploy credential is missing. Run scripts/setup-nethely-deploy-credential.ps1 first. Expected directory: $Directory"
    }

    return [ordered]@{
        Credential = Import-Clixml -Path $credentialPath
        Config = Get-Content -Raw -Path $configPath | ConvertFrom-Json
    }
}

function New-WinScpOpenCommand {
    param(
        [Parameter(Mandatory = $true)]
        [pscredential] $Credential,

        [Parameter(Mandatory = $true)]
        $Config
    )

    $protocol = [string] $Config.protocol
    $scheme = switch ($protocol) {
        'ftps' { 'ftpes' }
        'ftp' { 'ftp' }
        'sftp' { 'sftp' }
        default { throw "Unsupported protocol: $protocol" }
    }

    if ($protocol -eq 'sftp' -and [string]::IsNullOrWhiteSpace([string] $Config.hostKey)) {
        throw 'SFTP deploy requires a hostKey value in deploy-config.json.'
    }

    $hostName = [string] $Config.host
    $port = [int] $Config.port
    $userName = [Uri]::EscapeDataString($Credential.UserName)
    $password = [Uri]::EscapeDataString((Get-PlainTextPassword -SecureString $Credential.Password))
    $openCommand = 'open "{0}://{1}:{2}@{3}:{4}/"' -f $scheme, $userName, $password, $hostName, $port

    if ($protocol -eq 'sftp') {
        $openCommand += ' -hostkey="{0}"' -f ([string] $Config.hostKey)
    }

    return $openCommand
}

$repoRoot = Resolve-Path (Join-Path $PSScriptRoot '..')
$manifestFullPath = Resolve-Path $ManifestPath
$manifest = Get-Content -Raw -Path $manifestFullPath | ConvertFrom-Json

if (-not $manifest.files -or $manifest.files.Count -eq 0) {
    throw 'Deploy manifest does not contain any files.'
}

New-Item -ItemType Directory -Force -Path $LogDir | Out-Null

$credentialBundle = $null
$remoteRoot = [string] $manifest.remoteRoot
if ([string]::IsNullOrWhiteSpace($remoteRoot)) {
    $credentialBundle = Read-DeployCredential -Directory $CredentialDir
    $remoteRoot = [string] $credentialBundle.Config.remoteRoot
}

if ([string]::IsNullOrWhiteSpace($remoteRoot)) {
    throw 'Remote root is missing from manifest and deploy config.'
}

$plannedFiles = @()
foreach ($file in $manifest.files) {
    $relativeLocal = [string] $file.local
    $relativeRemote = [string] $file.remote

    if ([string]::IsNullOrWhiteSpace($relativeLocal) -or [string]::IsNullOrWhiteSpace($relativeRemote)) {
        throw 'Each manifest file must contain local and remote values.'
    }

    Assert-DeployPathAllowed -RelativePath $relativeLocal
    Assert-DeployPathAllowed -RelativePath $relativeRemote

    $localPath = Join-Path $repoRoot $relativeLocal
    if (-not (Test-Path -LiteralPath $localPath -PathType Leaf)) {
        throw "Local deploy file was not found: $relativeLocal"
    }

    $item = Get-Item -LiteralPath $localPath
    $plannedFiles += [ordered]@{
        Local = $item.FullName
        Remote = Join-RemotePath -Root $remoteRoot -RelativePath $relativeRemote
        Size = $item.Length
    }
}

Write-Host 'Deploy plan:'
foreach ($planned in $plannedFiles) {
    Write-Host ('- {0} -> {1} ({2} bytes)' -f $planned.Local, $planned.Remote, $planned.Size)
}

if ($DryRun) {
    Write-Host 'Dry run only. No files were uploaded.'
    exit 0
}

if ($null -eq $credentialBundle) {
    $credentialBundle = Read-DeployCredential -Directory $CredentialDir
}

$winscp = Find-WinScp
$timestamp = Get-Date -Format 'yyyyMMdd_HHmmss'
$winscpScriptPath = Join-Path $LogDir "winscp_upload_$timestamp.tmp"
$winscpLogPath = Join-Path $LogDir "winscp_upload_$timestamp.log"
$sanitizedLogPath = Join-Path $LogDir "deploy_$timestamp.json"

$scriptLines = New-Object System.Collections.Generic.List[string]
$scriptLines.Add('option batch abort')
$scriptLines.Add('option confirm off')
$scriptLines.Add((New-WinScpOpenCommand -Credential $credentialBundle.Credential -Config $credentialBundle.Config))

foreach ($planned in $plannedFiles) {
    $scriptLines.Add(('put -transfer=binary -preservetime "{0}" "{1}"' -f $planned.Local, $planned.Remote))
}

$scriptLines.Add('exit')

try {
    $scriptLines | Set-Content -Path $winscpScriptPath -Encoding ASCII
    & $winscp /ini=nul /script="$winscpScriptPath" /log="$winscpLogPath" /loglevel=0
    if ($LASTEXITCODE -ne 0) {
        throw "WinSCP exited with code $LASTEXITCODE. See sanitized deploy output and local WinSCP log outside the repository."
    }

    $entries = foreach ($planned in $plannedFiles) {
        [ordered]@{
            local = $planned.Local
            remote = $planned.Remote
            size = $planned.Size
            uploadedAt = (Get-Date).ToString('o')
            status = 'uploaded'
        }
    }

    [ordered]@{
        manifest = $manifestFullPath.Path
        commit = [string] $manifest.commit
        uploadedAt = (Get-Date).ToString('o')
        files = $entries
    } | ConvertTo-Json -Depth 5 | Set-Content -Path $sanitizedLogPath -Encoding UTF8

    Write-Host "Deploy completed. Sanitized log: $sanitizedLogPath"
} finally {
    if (Test-Path -LiteralPath $winscpScriptPath) {
        Remove-Item -LiteralPath $winscpScriptPath -Force
    }
}
