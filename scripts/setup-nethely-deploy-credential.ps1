param(
    [string] $CredentialDir = 'C:\Szaki24-dev\ACTIVE_PROJECTS\mezoenergy.hu\_codex_comm\secrets\nethely_deploy'
)

$ErrorActionPreference = 'Stop'

function Read-RequiredValue {
    param(
        [Parameter(Mandatory = $true)]
        [string] $Prompt
    )

    $value = Read-Host $Prompt
    if ([string]::IsNullOrWhiteSpace($value)) {
        throw "$Prompt is required."
    }

    return $value.Trim()
}

New-Item -ItemType Directory -Force -Path $CredentialDir | Out-Null

$protocol = Read-Host 'Protocol (ftps/ftp/sftp) [ftps]'
if ([string]::IsNullOrWhiteSpace($protocol)) {
    $protocol = 'ftps'
}
$protocol = $protocol.Trim().ToLowerInvariant()
if ($protocol -notin @('ftps', 'ftp', 'sftp')) {
    throw 'Protocol must be ftps, ftp, or sftp.'
}

$defaultPort = if ($protocol -eq 'sftp') { 22 } else { 21 }
$portInput = Read-Host "Port [$defaultPort]"
$port = $defaultPort
if (-not [string]::IsNullOrWhiteSpace($portInput)) {
    $port = [int] $portInput
}

$hostName = Read-RequiredValue -Prompt 'FTP/SFTP host'
$userName = Read-RequiredValue -Prompt 'Username'
$password = Read-Host 'Password' -AsSecureString
if ($password.Length -eq 0) {
    throw 'Password is required.'
}

$remoteRoot = Read-Host 'Remote root [/public_html]'
if ([string]::IsNullOrWhiteSpace($remoteRoot)) {
    $remoteRoot = '/public_html'
}
$remoteRoot = '/' + $remoteRoot.Trim().Trim('/')

$hostKey = ''
if ($protocol -eq 'sftp') {
    $hostKey = Read-Host 'SFTP host key fingerprint (required for sftp; leave blank only if you will add it later)'
}

$credential = [System.Management.Automation.PSCredential]::new($userName, $password)
$credentialPath = Join-Path $CredentialDir 'credential.clixml'
$configPath = Join-Path $CredentialDir 'deploy-config.json'

$credential | Export-Clixml -Path $credentialPath

[ordered]@{
    protocol = $protocol
    host = $hostName
    port = $port
    remoteRoot = $remoteRoot
    hostKey = $hostKey
    createdAt = (Get-Date).ToString('o')
} | ConvertTo-Json | Set-Content -Path $configPath -Encoding UTF8

Write-Host 'Nethely deploy credential saved locally for the current Windows user.'
Write-Host "Credential file: $credentialPath"
Write-Host "Config file: $configPath"
Write-Host 'Do not commit or copy these files to GitHub.'
