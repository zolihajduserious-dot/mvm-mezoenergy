# Google Sheet Import v1 Go-Live Checklist

## Backend

- Merge / deploy branch: `feature/google-sheet-facebook-lead-import-v1`.
- Futtasd a `database/lead_imports.sql` migraciot phpMyAdminban.
- Ellenorizd, hogy letrejott a `lead_imports` tabla.
- Ellenorizd az egyedi indexet: `ux_lead_imports_source_external`.
- Allitsd be a `LEAD_IMPORT_TOKEN` erteket legalabb 32 karakteres veletlen tokenre.
- Allitsd be atmenetileg: `APP_URL=https://mvm-mezoenergy.hu`.
- Allitsd be a kezi admin import backend configot:
  - `GOOGLE_SHEET_IMPORT_WEBAPP_URL`
  - `GOOGLE_SHEET_IMPORT_WEBAPP_TOKEN`
- Javasolt mod: GitHub Secrets + `deploy-google-sheet-import-secret.yml` workflow.
- A workflow kulon `storage/config/google-sheet-import.secret.php` fajlt deployol, es nem modositja a `storage/config/local.secret.php` fajlt.
- Az Apps Script Web App `health` teszt csak akkor elfogadott, ha JSON valasz jon: HTTP 200, `status: OK`, `action: health`.
- Jelenlegi mukodo production API URL: `https://mvm-mezoenergy.hu/api/import/facebook-lead`.
- A `mezoenergy.hu` vegleges domainre valtas csak akkor tortenjen meg az Apps Scriptben, ha a `mezoenergy.hu` API endpoint mar redirect nelkul ad 401-et wrong-token tesztre.
- Nethelyen a token vagy szerver oldali kornyezeti valtozo legyen, vagy az ignore-olt `storage/config/local.secret.php` fajlban szerepeljen.
- A manualis admin import Web App URL/token parja inkabb az ignore-olt `storage/config/google-sheet-import.secret.php` fajlba keruljon GitHub Actions secret deployjal.
- Ellenorizd, hogy `storage/config/local.php` nem tracked secret fajl, es nem tartalmaz valodi titkot a repoban.
- Ellenorizd, hogy `storage/config/local.secret.php` nincs trackelve.
- Ellenorizd, hogy `storage/config/google-sheet-import.secret.php` nincs trackelve.
- Futtasd: `.\docs\google-sheet-import\check_secrets_before_commit.ps1`.
- Ellenorizd, hogy nincs secret a `git diff` vagy staged diff tartalmaban.
- `git status` legyen tiszta a deployra szant commit utan, kiveve tudatosan lokalis / ignored fajlokat.
- Futtass endpoint kezi tesztet a `docs/google-sheet-import/test_import.ps1 -Mode normal` paranccsal.
- Futtasd a `-Mode duplicate`, `-Mode missing-contact`, `-Mode wrong-token` teszteket is.
- Ellenorizd az admin feluleten, hogy letrejott az ugyfel es a munkaigeny.
- Uj importalt ugyfelnel ellenorizd, hogy az email subject `Mező Energy ügyfélportál – fiók aktiválása`, a gomb pedig `Fiók aktiválása`; ez nem lehet normal `Jelszó-visszaállítás` email.
- Uj importalt ugyfelnel ellenorizd, hogy az email elmagyarazza a Mezo Energy kuldeteset: fuggetlen szakmai segitseg merohelyi / arambovitesi / elosztoi ugyekben.
- Uj importalt ugyfelnel ellenorizd, hogy szerepel a fuggetlensegi tajekoztatas: a Mezo Energy Kft. nem az MVM Csoport tagja, nem hivatalos MVM ugyfelszolgalat, es nem az MVM neveben jar el.
- A normal `Jelszo elfelejtese` funkcio kulon ellenorzendo: ott maradjon a `Jelszó-visszaállítás` tartalom.
- Importalt uj munka adatlapneve legyen ugyfelbarat, pelda: `3 fázisra átállás – TESZT`, ne duplikalt technikai cim.
- Ugyfelportalon ellenorizd, hogy a sajat importalt adatlap szerkesztheto: adatlapnev, igenytipus, cim, HRSZ, mero, fogyasztasi hely, MVM UK szam, teljesitmeny adatok es ugyfel pontositas mentheto.
- Az automata Google Sheet trigger jelenleg uzleti dontes szerint NO-GO. Import csak admin gombbal indulhat.

## Google Sheet

- Masold be a `docs/google-sheet-import/Code.gs` tartalmat az Apps Scriptbe.
- Ha a Google Sheet `Bovitmenyek -> Apps Script` hibazik, hozz letre standalone projektet a `script.google.com` alatt, es a `docs/google-sheet-import/Code_standalone.gs` tartalmat masold be.
- Futtasd: `setupMezoScriptProperties()`.
- Standalone script eseten futtasd: `setupMezoStandaloneScriptProperties()`.
- Standalone Script Properties:
  - `MEZO_SPREADSHEET_ID`: a Google Sheet URL-ben a `/d/` es `/edit` kozotti resz.
  - `MEZO_SHEET_NAME=Munkalap1`
  - `MEZO_API_URL=https://mvm-mezoenergy.hu/api/import/facebook-lead`
  - `MEZO_ADMIN_RUN_TOKEN=<kulon admin futtatasi token>`
  - `MEZO_MAX_ROWS_PER_RUN=25`
  - `MEZO_RETRY_ERRORS=false`
- Script Properties alatt allitsd be a `MEZO_API_TOKEN` erteket.
- Ellenorizd, hogy a token nem szerepel a tablazat cellaiban.
- Futtasd: `ensureMezoImportColumns()`.
- Regi / nem importalando sorok statusza legyen `NEM_IMPORTÁL` vagy `ELUTASÍTVA`.
- Egy tesztsort allits `IMPORTÁLANDÓ` statuszra.
- Admin oldal: `/admin/google-sheet-import`.
- Elso admin hasznalat elott: Web App `health` JSON OK legyen.
- Futtasd adminbol: `Állapot lekérdezése`.
- Csak kontrollalt tesztnel futtasd adminbol: `Jóváhagyott sorok importálása`.
- Ellenorizd:
  - `mezo_import_status`
  - `mezo_customer_id`
  - `mezo_work_request_id`
  - `mezo_error`
  - `mezo_duplicate`
  - `mezo_api_response`
- Ne futtasd: `installMezoFiveMinuteTrigger()`. Idozitett trigger nincs hasznalatban.
- `run-approved` import csak kulon dontes es sikeres health/config ellenorzes utan fusson.

## Biztonsag

- Regi, nem importalando sorok statusza legyen `NEM_IMPORTÁL` vagy `ELUTASÍTVA`.
- Csak az `IMPORTÁLANDÓ` / `JÓVÁHAGYVA` statuszu sorok importalhatok.
- Ures, `ÚJ`, `ELLENŐRZÉSRE_VÁR`, `HIBA`, `SIKERES`, `DUPLIKÁLT`, `FOLYAMATBAN`, `NEM_IMPORTÁL` es `ELUTASÍTVA` statuszu sor nem importalodik.
- A `LEAD_IMPORT_TOKEN` legalabb 32 karakter legyen.
- A backend token nem latszodhat a Google Sheetben.
- A backend token nem latszodhat GitHubon.
- A backend token nem latszodhat outbox riportban vagy dokumentacioban.
- A fo hivatkozas mindenhol `https://mezoenergy.hu`, ne a regi `mvm-mezoenergy.hu` domain legyen.
- Szemelyes adatot ne masolj riportba, commitba vagy hibajegybe.
- Ne modositsd kezzel a `lead_imports.source`, `lead_imports.external_lead_id`, `lead_imports.customer_id`, `lead_imports.work_request_id` mezoket.

## Visszagorgetes

- Ha korabban veletlenul telepult 5 perces Apps Script trigger, torold az admin feluleten vagy Apps Scriptbol a `deleteMezoImportTriggers()` fuggvennyel.
- Backend oldalon a `LEAD_IMPORT_TOKEN` eltavolitasa vagy ervenytelenitese 503 JSON hibara allitja az endpointot.
- A mar importalt sorokat hagyd `SIKERES` vagy `DUPLIKÁLT` statuszon, ne torold a `lead_imports` rekordokat.
