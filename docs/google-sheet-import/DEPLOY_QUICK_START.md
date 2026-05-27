# Google Sheet import v1 deploy quick start

Rovid napi deploy lista. Reszletek: `docs/google-sheet-import/PRODUCTION_DEPLOY_RUNBOOK.md`.

## 1. Backup

- Keszits adatbazis mentest phpMyAdminbol.
- Mentsd a jelenlegi `public_html` / Git deploy rollback pontot.
- Mentsd a production `storage/config` mappat biztonsagos helyre, de ne GitHubba.

## 2. PR merge

- Celag: `main`.
- Feature branch: `feature/google-sheet-facebook-lead-import-v1`.
- Merge csak kezi review es jovahagyas utan.
- Ellenorizd, hogy nincs valodi secret a PR diffben.

## 3. Deploy fajlok

Backend minimum:

- `public_html/api/import/facebook-lead.php`
- `public_html/includes/lead-import.php`
- `public_html/index.php`
- `database/lead_imports.sql`
- `storage/config/local.secret.php.example`
- `.env.example`

Ne deployolj valodi `.env`, `storage/config/local.php`, `storage/config/local.secret.php`, dump vagy backup fajlt GitHubbol.

## 4. Adatbazis

- phpMyAdminban futtasd: `database/lead_imports.sql`.
- Ellenorizd:
  - `lead_imports` tabla letezik,
  - `ux_lead_imports_source_external`,
  - `idx_lead_imports_customer`,
  - `idx_lead_imports_work_request`,
  - `idx_lead_imports_status`.

## 5. Token config

- `LEAD_IMPORT_TOKEN`: legalabb 32 karakteres veletlen ertek.
- Elsodleges: szerver oldali env valtozo.
- Fallback: `storage/config/local.secret.php`.
- Atmenetileg: `APP_URL=https://mvm-mezoenergy.hu`.
- Jelenlegi mukodo production API URL: `https://mvm-mezoenergy.hu/api/import/facebook-lead`.
- A `mezoenergy.hu` vegleges domainre valtas csak akkor tortenjen meg az Apps Scriptben, ha a `mezoenergy.hu` API endpoint mar redirect nelkul ad 401-et wrong-token tesztre.
- A token ne keruljon GitHubra, Google Sheet cellaba vagy dokumentacioba.

## 6. Backend PowerShell tesztek

```powershell
$env:MEZO_API_TOKEN = '<ugyanaz_a_backend_token>'
$env:APP_URL = 'https://mvm-mezoenergy.hu'

.\docs\google-sheet-import\test_import.ps1 -Mode wrong-token
.\docs\google-sheet-import\test_import.ps1 -Mode missing-contact
.\docs\google-sheet-import\test_import.ps1 -Mode normal
.\docs\google-sheet-import\test_import.ps1 -Mode duplicate
```

Elvart:

- `wrong-token`: 401.
- `missing-contact`: 422.
- `normal`: SIKERES.
- `duplicate`: DUPLIKÁLT.

## 7. Google Sheet egy tesztsor

- Masold be a `Code.gs` tartalmat Apps Scriptbe.
- Ha a Google Sheet `Bovitmenyek -> Apps Script` hibazik, hozz letre standalone projektet:
  - `script.google.com` -> Uj projekt, vagy
  - Google Drive -> Uj -> Tovabbiak -> Apps Script.
- Standalone eseten a `Code_standalone.gs` tartalmat masold be.
- Futtasd: `setupMezoScriptProperties()`.
- Standalone eseten futtasd: `setupMezoStandaloneScriptProperties()`.
- Script Properties:
  - `MEZO_SPREADSHEET_ID=<a Google Sheet URL-ben a /d/ es /edit kozotti resz>`
  - `MEZO_SHEET_NAME=Munkalap1`
  - `MEZO_API_URL=https://mvm-mezoenergy.hu/api/import/facebook-lead`
  - `MEZO_API_TOKEN=<ugyanaz_a_backend_token>`
  - `MEZO_MAX_ROWS_PER_RUN=25`
  - `MEZO_RETRY_ERRORS=false`
- Futtasd: `ensureMezoImportColumns()`.
- Regi sorok: `NEM_IMPORTÁL`.
- Egy tesztsor: `ÚJ`.
- Futtasd: `importActiveMezoTestRow()`.
- Standalone eseten futtasd: `importFirstNewMezoTestRow()`.
- Ellenorizd a `mezo_import_status`, `mezo_customer_id`, `mezo_work_request_id`, `mezo_error`, `mezo_duplicate`, `mezo_api_response` mezoket.

## 8. Trigger

- Csak sikeres backend tesztek es egytesztsoros Google Sheet import utan futtasd: `installMezoFiveMinuteTrigger()`.
- Hiba eseten trigger torlese, uj sorok `STOP` statuszra allitasa, majd rollback terv kovetese.
