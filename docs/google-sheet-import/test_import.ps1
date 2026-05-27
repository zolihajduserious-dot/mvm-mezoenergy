$ErrorActionPreference = 'Stop'

$token = $env:MEZO_API_TOKEN
if ([string]::IsNullOrWhiteSpace($token)) {
    throw 'Set the MEZO_API_TOKEN environment variable before running this script.'
}

$appUrl = $env:APP_URL
if ([string]::IsNullOrWhiteSpace($appUrl)) {
    $appUrl = 'https://mezoenergy.hu'
}

$endpoint = $appUrl.TrimEnd('/') + '/api/import/facebook-lead'
$payloadPath = Join-Path $PSScriptRoot 'test_payload.json'
$body = Get-Content -LiteralPath $payloadPath -Raw
$headers = @{
    Authorization = "Bearer $token"
    Accept = 'application/json'
}

try {
    $response = Invoke-WebRequest `
        -Uri $endpoint `
        -Method Post `
        -Headers $headers `
        -ContentType 'application/json; charset=utf-8' `
        -Body $body

    Write-Host ('HTTP status: {0}' -f [int] $response.StatusCode)
    Write-Host $response.Content
} catch {
    $httpResponse = $_.Exception.Response

    if ($null -eq $httpResponse) {
        throw
    }

    $statusCode = [int] $httpResponse.StatusCode
    $stream = $httpResponse.GetResponseStream()
    $reader = New-Object System.IO.StreamReader($stream)
    $content = $reader.ReadToEnd()
    $reader.Dispose()

    Write-Host ('HTTP status: {0}' -f $statusCode)
    Write-Host $content
    exit 1
}
