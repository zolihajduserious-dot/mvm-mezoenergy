# Google Sheet Import v1 Go-Live Checklist

## Backend

- Merge / deploy branch: `feature/google-sheet-facebook-lead-import-v1`.
- Futtasd a `database/lead_imports.sql` migraciot phpMyAdminban.
- Ellenorizd, hogy letrejott a `lead_imports` tabla.
- Ellenorizd az egyedi indexet: `ux_lead_imports_source_external`.
- Allitsd be a `LEAD_IMPORT_TOKEN` erteket legalabb 32 karakteres veletlen tokenre.
- Allitsd be atmenetileg: `APP_URL=https://mvm-mezoenergy.hu`.
- Jelenlegi mukodo production API URL: `https://mvm-mezoenergy.hu/api/import/facebook-lead`.
- A `mezoenergy.hu` vegleges domainre valtas csak akkor tortenjen meg az Apps Scriptben, ha a `mezoenergy.hu` API endpoint mar redirect nelkul ad 401-et wrong-token tesztre.
- Nethelyen a token vagy szerver oldali kornyezeti valtozo legyen, vagy az ignore-olt `storage/config/local.secret.php` fajlban szerepeljen.
- Ellenorizd, hogy `storage/config/local.php` nem tracked secret fajl, es nem tartalmaz valodi titkot a repoban.
- Ellenorizd, hogy `storage/config/local.secret.php` nincs trackelve.
- Futtasd: `.\docs\google-sheet-import\check_secrets_before_commit.ps1`.
- Ellenorizd, hogy nincs secret a `git diff` vagy staged diff tartalmaban.
- `git status` legyen tiszta a deployra szant commit utan, kiveve tudatosan lokalis / ignored fajlokat.
- Futtass endpoint kezi tesztet a `docs/google-sheet-import/test_import.ps1 -Mode normal` paranccsal.
- Futtasd a `-Mode duplicate`, `-Mode missing-contact`, `-Mode wrong-token` teszteket is.
- Ellenorizd az admin feluleten, hogy letrejott az ugyfel es a munkaigeny.
- Uj importalt ugyfelnel ellenorizd, hogy az email subject `Mező Energy ügyfélportál – fiók aktiválása`, a gomb pedig `Fiók aktiválása`; ez nem lehet normal `Jelszó-visszaállítás` email.
- A normal `Jelszo elfelejtese` funkcio kulon ellenorzendo: ott maradjon a `Jelszó-visszaállítás` tartalom.

## Google Sheet

- Masold be a `docs/google-sheet-import/Code.gs` tartalmat az Apps Scriptbe.
- Ha a Google Sheet `Bovitmenyek -> Apps Script` hibazik, hozz letre standalone projektet a `script.google.com` alatt, es a `docs/google-sheet-import/Code_standalone.gs` tartalmat masold be.
- Futtasd: `setupMezoScriptProperties()`.
- Standalone script eseten futtasd: `setupMezoStandaloneScriptProperties()`.
- Standalone Script Properties:
  - `MEZO_SPREADSHEET_ID`: a Google Sheet URL-ben a `/d/` es `/edit` kozotti resz.
  - `MEZO_SHEET_NAME=Munkalap1`
  - `MEZO_API_URL=https://mvm-mezoenergy.hu/api/import/facebook-lead`
  - `MEZO_MAX_ROWS_PER_RUN=25`
  - `MEZO_RETRY_ERRORS=false`
- Script Properties alatt allitsd be a `MEZO_API_TOKEN` erteket.
- Ellenorizd, hogy a token nem szerepel a tablazat cellaiban.
- Futtasd: `ensureMezoImportColumns()`.
- Egy tesztsort allits `ÚJ` statuszra.
- Futtasd: `importActiveMezoTestRow()`.
- Standalone script eseten az elso teszt: `importFirstNewMezoTestRow()`.
- Ellenorizd:
  - `mezo_import_status`
  - `mezo_customer_id`
  - `mezo_work_request_id`
  - `mezo_error`
  - `mezo_duplicate`
  - `mezo_api_response`
- Csak sikeres kezi teszt utan futtasd: `installMezoFiveMinuteTrigger()`.

## Biztonsag

- Regi, nem importalando sorok statusza legyen `NEM_IMPORTÁL`.
- A `LEAD_IMPORT_TOKEN` legalabb 32 karakter legyen.
- A backend token nem latszodhat a Google Sheetben.
- A backend token nem latszodhat GitHubon.
- A backend token nem latszodhat outbox riportban vagy dokumentacioban.
- A fo hivatkozas mindenhol `https://mezoenergy.hu`, ne a regi `mvm-mezoenergy.hu` domain legyen.
- Szemelyes adatot ne masolj riportba, commitba vagy hibajegybe.
- Ne modositsd kezzel a `lead_imports.source`, `lead_imports.external_lead_id`, `lead_imports.customer_id`, `lead_imports.work_request_id` mezoket.

## Visszagorgetes

- Az 5 perces Apps Script trigger torlese azonnal leallitja az automatikus importot.
- Backend oldalon a `LEAD_IMPORT_TOKEN` eltavolitasa vagy ervenytelenitese 503 JSON hibara allitja az endpointot.
- A mar importalt sorokat hagyd `SIKERES` vagy `DUPLIKÁLT` statuszon, ne torold a `lead_imports` rekordokat.
