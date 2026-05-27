param(
    [ValidateSet('normal', 'duplicate', 'missing-contact', 'wrong-token')]
    [string] $Mode = 'normal',

    [Alias('VerboseHttp')]
    [switch] $ShowRequestSummary
)

$ErrorActionPreference = 'Stop'

$appUrl = $env:APP_URL
if ([string]::IsNullOrWhiteSpace($appUrl)) {
    $appUrl = 'https://mezoenergy.hu'
}

$endpoint = $appUrl.TrimEnd('/') + '/api/import/facebook-lead'
$payloadPath = Join-Path $PSScriptRoot 'test_payload.json'
$payload = Get-Content -LiteralPath $payloadPath -Raw | ConvertFrom-Json

if ($Mode -eq 'missing-contact') {
    $payload.email = ''
    $payload.phone = ''
    $payload.external_lead_id = 'test-facebook-lead-missing-contact'
}

if ($Mode -eq 'wrong-token') {
    $token = 'invalid-test-token-not-a-real-secret'
} else {
    $token = $env:MEZO_API_TOKEN
    if ([string]::IsNullOrWhiteSpace($token)) {
        throw 'Set the MEZO_API_TOKEN environment variable before running this script.'
    }
}

function Invoke-MezoLeadImport {
    param(
        [string] $Endpoint,
        [string] $Token,
        [object] $Payload
    )

    $body = $Payload | ConvertTo-Json -Depth 10
    $headers = @{
        Authorization = "Bearer $Token"
        Accept = 'application/json'
    }

    try {
        $response = Invoke-WebRequest `
            -Uri $Endpoint `
            -Method Post `
            -Headers $headers `
            -ContentType 'application/json; charset=utf-8' `
            -Body $body

        [PSCustomObject]@{
            StatusCode = [int] $response.StatusCode
            Content = $response.Content
        }
    } catch {
        $httpResponse = $_.Exception.Response

        if ($null -eq $httpResponse) {
            throw
        }

        $stream = $httpResponse.GetResponseStream()
        $reader = New-Object System.IO.StreamReader($stream)
        $content = $reader.ReadToEnd()
        $reader.Dispose()

        [PSCustomObject]@{
            StatusCode = [int] $httpResponse.StatusCode
            Content = $content
        }
    }
}

function Write-MezoRequestSummary {
    param(
        [string] $Endpoint,
        [string] $Token,
        [string] $Mode
    )

    Write-Host ('Request URL: {0}' -f $Endpoint)
    Write-Host 'HTTP method: POST'
    Write-Host 'Content-Type: application/json; charset=utf-8'
    Write-Host ('Authorization Bearer token present: {0}' -f (-not [string]::IsNullOrWhiteSpace($Token)))
    Write-Host 'Authorization Bearer token value: hidden'
    Write-Host ('Payload mode: {0}' -f $Mode)
    Write-Host 'Payload body: hidden'
}

function Write-MezoResult {
    param(
        [string] $Label,
        [object] $Result
    )

    Write-Host ('[{0}] HTTP status: {1}' -f $Label, $Result.StatusCode)
    Write-Host $Result.Content
}

if ($ShowRequestSummary) {
    Write-MezoRequestSummary -Endpoint $endpoint -Token $token -Mode $Mode
}

if ($Mode -eq 'duplicate') {
    $first = Invoke-MezoLeadImport -Endpoint $endpoint -Token $token -Payload $payload
    Write-MezoResult -Label 'duplicate first request' -Result $first

    $second = Invoke-MezoLeadImport -Endpoint $endpoint -Token $token -Payload $payload
    Write-MezoResult -Label 'duplicate second request' -Result $second

    if (($first.StatusCode -eq 200 -or $first.StatusCode -eq 201) -and $second.StatusCode -eq 200) {
        exit 0
    }

    exit 1
}

$result = Invoke-MezoLeadImport -Endpoint $endpoint -Token $token -Payload $payload
Write-MezoResult -Label $Mode -Result $result

if ($Mode -eq 'wrong-token' -and $result.StatusCode -eq 401) {
    exit 0
}

if ($Mode -eq 'missing-contact' -and $result.StatusCode -eq 422) {
    exit 0
}

if ($Mode -eq 'normal' -and ($result.StatusCode -eq 200 -or $result.StatusCode -eq 201)) {
    exit 0
}

exit 1
