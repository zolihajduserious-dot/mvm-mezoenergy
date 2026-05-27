[CmdletBinding()]
param()

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$keywords = @(
    'LEAD_IMPORT_TOKEN',
    'MEZO_API_TOKEN',
    'api_key',
    'apikey',
    'password',
    'passwd',
    'secret',
    'token',
    'smtp',
    'sms'
)

function Find-KeywordHitsInDiff {
    param(
        [string] $Name,
        [string[]] $GitArgs
    )

    $diff = & git @GitArgs
    $currentFile = '<unknown>'
    $hits = New-Object System.Collections.Generic.List[string]

    foreach ($line in $diff) {
        if ($line -like '--- a/*') {
            $currentFile = $line.Substring(6)
            continue
        }

        if ($line -like '+++ b/*') {
            $currentFile = $line.Substring(6)
            continue
        }

        if ($line -notmatch '^[+-]' -or $line -match '^(---|\+\+\+)') {
            continue
        }

        foreach ($keyword in $keywords) {
            if ($line -match [regex]::Escape($keyword)) {
                $hits.Add(('{0}: keyword "{1}" appears in {2}' -f $Name, $keyword, $currentFile))
            }
        }
    }

    return $hits
}

$allHits = New-Object System.Collections.Generic.List[string]

Find-KeywordHitsInDiff -Name 'staged diff' -GitArgs @('diff', '--cached', '--unified=0', '--no-ext-diff') |
    ForEach-Object { $allHits.Add($_) }

Find-KeywordHitsInDiff -Name 'unstaged diff' -GitArgs @('diff', '--unified=0', '--no-ext-diff') |
    ForEach-Object { $allHits.Add($_) }

if ($allHits.Count -gt 0) {
    Write-Warning 'Possible secret-related keywords were found in git diffs. Values are not printed by this script.'
    $allHits | Sort-Object -Unique | ForEach-Object { Write-Warning $_ }
} else {
    Write-Output 'No configured secret keywords were found in staged or unstaged diffs.'
}

Write-Output 'Manual review is still required before commit. This script is only a helper and does not remove or hide secrets.'
