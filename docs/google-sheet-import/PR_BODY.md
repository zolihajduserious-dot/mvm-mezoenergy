# Google Sheet / Facebook lead import v1

## PR nyitas

Javasolt celag: `main`.

GitHub CLI parancs:

```powershell
gh pr create --base main --head feature/google-sheet-facebook-lead-import-v1 --title "Google Sheet Facebook lead import v1" --body-file docs/google-sheet-import/PR_BODY.md
```

Kezi GitHub compare link:

```text
https://github.com/zolihajduserious-dot/mvm-mezoenergy/compare/main...feature/google-sheet-facebook-lead-import-v1
```

## Mi keszult

- Biztonsagos backend import endpoint: `POST /api/import/facebook-lead`.
- Bearer token vedelem `LEAD_IMPORT_TOKEN` alapon.
- JSON-only API valaszok es hibakezeles.
- Idempotens import naplozas `lead_imports` tablaval.
- Facebook / Google Sheet lead customerhez kapcsolasa vagy eloregisztralt customer letrehozasa.
- Belső munkaigeny / lead rekord letrehozasa `connection_requests` alapon.
- Google Apps Script importalo `Code.gs` LockService hasznalattal, statusz visszairassal es normalizalt header matchinggel.
- PowerShell teszt script normal, duplicate, missing-contact es wrong-token modokkal.
- Secret/config hardening: `storage/config/local.php` kikerult a repobol, a secret fallback `storage/config/local.secret.php`.
- Release dokumentacio, go-live checklist, security config, production deploy runbook es kulcsrotacios todo.

## Mi nem keszult

- Nincs szereloi marketplace / leadkiosztas.
- Nincs fizetos lead vagy partneri elszamolas.
- Nincs fizetesi rendszer.
- Nincs eles Google Sheet trigger telepites.
- Nincs valodi token vagy jelszo a repoban.
- Nincs eles adat import ebben a PR-ben.

## Hogyan kell tesztelni

Lokalis / pre-merge ellenorzesek:

```powershell
php -l public_html\includes\lead-import.php
php -l public_html\api\import\facebook-lead.php
php -l public_html\index.php
php -l public_html\includes\crm.php

$script = Get-Content -LiteralPath 'docs\google-sheet-import\test_import.ps1' -Raw
[scriptblock]::Create($script) | Out-Null

$script = Get-Content -LiteralPath 'docs\google-sheet-import\check_secrets_before_commit.ps1' -Raw
[scriptblock]::Create($script) | Out-Null

Get-Content -LiteralPath 'docs\google-sheet-import\test_payload.json' -Raw | ConvertFrom-Json | Out-Null
Get-Content -LiteralPath 'docs\google-sheet-import\Code.gs' -Raw | node --check --input-type=commonjs -
git diff --check
```

Production endpoint tesztek csak deploy es token beallitas utan:

```powershell
$env:MEZO_API_TOKEN = '<ugyanaz_a_backend_token>'
$env:APP_URL = 'https://mezoenergy.hu'

.\docs\google-sheet-import\test_import.ps1 -Mode wrong-token
.\docs\google-sheet-import\test_import.ps1 -Mode missing-contact
.\docs\google-sheet-import\test_import.ps1 -Mode normal
.\docs\google-sheet-import\test_import.ps1 -Mode duplicate
```

Vart eredmeny:

- `wrong-token`: HTTP 401.
- `missing-contact`: HTTP 422.
- `normal`: `SIKERES`.
- `duplicate`: `DUPLIKÁLT`.

A `normal` teszt teszt customer es munkaigeny rekordot hozhat letre. Ne hasznalj valodi ugyfeladatot.

## Elesites feltetelei

- `database/lead_imports.sql` lefuttatva phpMyAdminban.
- `lead_imports` tabla es indexek ellenorizve.
- `LEAD_IMPORT_TOKEN` legalabb 32 karakteres veletlen ertek.
- Token env valtozoban vagy `storage/config/local.secret.php` fajlban, nem GitHubon.
- Google Apps Script `MEZO_API_TOKEN` ugyanaz, mint a backend token.
- Regi Google Sheet sorok `NEM_IMPORTÁL` statuszon.
- Egy kontrollalt tesztsor sikeresen importal.
- 5 perces trigger csak sikeres kezi teszt utan telepitve.

## Rollback terv

- Apps Script 5 perces trigger torlese vagy kikapcsolasa.
- Uj Google Sheet sorok ideiglenesen `STOP` statuszra allitasa.
- Backend regi verzio visszaallitasa Gitbol vagy backupbol.
- `lead_imports` tablat nem toroljuk automatikusan, mert audit es idempotencia adat.
- Token kiszivargas gyanúja eseten backend token es Apps Script token azonnali rotacio.

## Titokkezelesi figyelmeztetes

- Ne keruljon GitHubra valodi `LEAD_IMPORT_TOKEN`, `MEZO_API_TOKEN`, SMTP jelszo, SMS API kulcs, Szamlazz.hu kulcs, DB jelszo vagy Nethely hozzaferes.
- `storage/config/local.php`, `storage/config/local.secret.php`, `.env` es `.env.*` ignore-olt.
- `storage/config/local.secret.php.example` csak placeholder mintat tartalmaz.
- Commit elott futtathato: `.\docs\google-sheet-import\check_secrets_before_commit.ps1`.

## No-Go esetek

- HTML hiba jon JSON helyett.
- Endpoint token nelkul mukodik.
- Ures vagy tul rovid backend token elfogadodik.
- Regi Google Sheet sorok importalodnak.
- Duplikalt external leadbol uj customer vagy uj munkaigeny keszul.
- Customer vagy munkaigeny nem jon letre sikeres importnal.
- `mezo_error` ismeretlen PHP hibakkal telik meg.
- Valodi secret jelenik meg PR diffben, dokumentacioban vagy Google Sheetben.
