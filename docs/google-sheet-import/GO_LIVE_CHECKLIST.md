# Google Sheet Import v1 Go-Live Checklist

## Backend

- Merge / deploy branch: `feature/google-sheet-facebook-lead-import-v1`.
- Futtasd a `database/lead_imports.sql` migraciot phpMyAdminban.
- Ellenorizd, hogy letrejott a `lead_imports` tabla.
- Ellenorizd az egyedi indexet: `ux_lead_imports_source_external`.
- Allitsd be a `LEAD_IMPORT_TOKEN` erteket legalabb 32 karakteres veletlen tokenre.
- Allitsd be: `APP_URL=https://mezoenergy.hu`.
- Nethelyen a token vagy szerver oldali kornyezeti valtozo legyen, vagy a `storage/config/local.php` lokalis configban szerepeljen.
- Ne commitolj `storage/config/local.php` fajlt valos tokennel.
- Futtass endpoint kezi tesztet a `docs/google-sheet-import/test_import.ps1 -Mode normal` paranccsal.
- Futtasd a `-Mode duplicate`, `-Mode missing-contact`, `-Mode wrong-token` teszteket is.
- Ellenorizd az admin feluleten, hogy letrejott az ugyfel es a munkaigeny.

## Google Sheet

- Masold be a `docs/google-sheet-import/Code.gs` tartalmat az Apps Scriptbe.
- Futtasd: `setupMezoScriptProperties()`.
- Script Properties alatt allitsd be a `MEZO_API_TOKEN` erteket.
- Ellenorizd, hogy a token nem szerepel a tablazat cellaiban.
- Futtasd: `ensureMezoImportColumns()`.
- Egy tesztsort allits `ÚJ` statuszra.
- Futtasd: `importActiveMezoTestRow()`.
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
