param(
    [Parameter(Mandatory = $true)]
    [string] $ManifestPath,

    [string] $CredentialDir = 'C:\Szaki24-dev\ACTIVE_PROJECTS\mezoenergy.hu\_codex_comm\secrets\nethely_deploy',

    [string] $LogDir = 'C:\Szaki24-dev\ACTIVE_PROJECTS\mezoenergy.hu\_codex_comm\deploy\logs',

    [string] $HttpUrl = 'https://mvm-mezoenergy.hu/customer/work-requests',

    [switch] $SkipHttpCheck
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

    throw 'WinSCP command-line client was not found. Install WinSCP or add winscp.com to PATH before verifying.'
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
        throw 'SFTP verify requires a hostKey value in deploy-config.json.'
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

$manifestFullPath = Resolve-Path $ManifestPath
$manifest = Get-Content -Raw -Path $manifestFullPath | ConvertFrom-Json
$credentialBundle = Read-DeployCredential -Directory $CredentialDir
$remoteRoot = [string] $manifest.remoteRoot
if ([string]::IsNullOrWhiteSpace($remoteRoot)) {
    $remoteRoot = [string] $credentialBundle.Config.remoteRoot
}

if ([string]::IsNullOrWhiteSpace($remoteRoot)) {
    throw 'Remote root is missing from manifest and deploy config.'
}

New-Item -ItemType Directory -Force -Path $LogDir | Out-Null
$winscp = Find-WinScp
$timestamp = Get-Date -Format 'yyyyMMdd_HHmmss'
$winscpScriptPath = Join-Path $LogDir "winscp_verify_$timestamp.tmp"
$winscpLogPath = Join-Path $LogDir "winscp_verify_$timestamp.log"

$scriptLines = New-Object System.Collections.Generic.List[string]
$scriptLines.Add('option batch abort')
$scriptLines.Add('option confirm off')
$scriptLines.Add((New-WinScpOpenCommand -Credential $credentialBundle.Credential -Config $credentialBundle.Config))

foreach ($file in $manifest.files) {
    $remotePath = Join-RemotePath -Root $remoteRoot -RelativePath ([string] $file.remote)
    $scriptLines.Add(('ls "{0}"' -f $remotePath))
}

$scriptLines.Add('exit')

try {
    $scriptLines | Set-Content -Path $winscpScriptPath -Encoding ASCII
    & $winscp /ini=nul /script="$winscpScriptPath" /log="$winscpLogPath" /loglevel=0
    if ($LASTEXITCODE -ne 0) {
        throw "WinSCP verify exited with code $LASTEXITCODE."
    }

    Write-Host 'Remote file listing check completed.'
} finally {
    if (Test-Path -LiteralPath $winscpScriptPath) {
        Remove-Item -LiteralPath $winscpScriptPath -Force
    }
}

if (-not $SkipHttpCheck) {
    try {
        $response = Invoke-WebRequest -Uri $HttpUrl -Method Get -UseBasicParsing -MaximumRedirection 5 -TimeoutSec 20
        Write-Host ("HTTP check completed: {0} {1}" -f [int] $response.StatusCode, $response.StatusDescription)
    } catch {
        $statusCode = $null
        if ($_.Exception.Response) {
            $statusCode = [int] $_.Exception.Response.StatusCode
        }
        Write-Host ("HTTP check failed or requires login. Status: {0}. Message: {1}" -f $statusCode, $_.Exception.Message)
    }
}
