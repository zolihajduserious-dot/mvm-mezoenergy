# Google Sheet / Facebook Lead Import

## Backend beallitas

Allitsd be a backend kornyezeti valtozokat:

```env
LEAD_IMPORT_TOKEN=
APP_URL=https://mvm-mezoenergy.hu
```

A `LEAD_IMPORT_TOKEN` legalabb 32 karakteres, veletlenszeru titok legyen. Ha hianyzik, ures vagy tul rovid, az API 503 JSON valaszt ad:

```json
{
  "status": "HIBA",
  "error": "Import API is not configured"
}
```

Nethely/shared hosting alatt a projekt jelenlegi konfiguracios mintaja szerint ket biztonsagos lehetoseg van:

- szerver oldali kornyezeti valtozo: `LEAD_IMPORT_TOKEN`
- vagy ignore-olt `storage/config/local.secret.php` fajlban, a `storage/config/local.secret.php.example` alapjan

Ne tedd a tokent `.env` fajlba, `storage/config/local.php` fajlba, Google Sheetbe, GitHubra vagy dokumentacioba. A `storage/config/local.php` nem titoktarolo; csak lokalis, nem secret override maradhat.

Az API vegpont:

```text
POST https://mvm-mezoenergy.hu/api/import/facebook-lead
Authorization: Bearer <LEAD_IMPORT_TOKEN>
Content-Type: application/json
```

Jelenlegi mukodo production API URL:

```text
https://mvm-mezoenergy.hu/api/import/facebook-lead
```

A `mezoenergy.hu` vegleges domainre valtas csak akkor tortenjen meg az Apps Scriptben, ha a `mezoenergy.hu` API endpoint mar redirect nelkul ad 401-et wrong-token tesztre.

Az endpoint elfogadja az `application/json` es az `application/json; charset=utf-8` content-type fejlecet is.

## Migracio Nethely / phpMyAdmin alatt

Az import naplohoz futtasd a `database/lead_imports.sql` migraciot. Az endpoint indulaskor is megprobalja letrehozni a `lead_imports` tablat, de eles telepitesnel a migracio futtatasa az ajanlott.

1. Nyisd meg a Nethely adminbol a phpMyAdmin feluletet.
2. Valaszd ki a Mezo Energy eles adatbazisat.
3. Nyisd meg az SQL futtatasi feluletet.
4. Masold be es futtasd a `database/lead_imports.sql` tartalmat.
5. Siker eseten megjelenik a `lead_imports` tabla.
6. Ellenorizd, hogy van egyedi index: `ux_lead_imports_source_external`.
7. Ellenorizd, hogy vannak segedindexek: `idx_lead_imports_customer`, `idx_lead_imports_work_request`, `idx_lead_imports_status`.

Kezzel ne modositd a `source`, `external_lead_id`, `customer_id`, `work_request_id` mezoket, mert ezek adjak az idempotens import alapjat.

## Elvart Google Sheet oszlopok

Facebook / Google Sheet lead oszlopok:

- `id`
- `created_time`
- `campaign_name`
- `form_name`
- `hol_van_az_ingatlan?`
- `milyen_munkára_van_szükség?`
- `van_már_beadott_igény_a_szolgáltató_felé?`
- `település?`
- `email`
- `full_name`
- `phone`
- `lead_status`

Importvezerlo oszlopok:

- `mezo_import_status`
- `mezo_customer_id`
- `mezo_work_request_id`
- `mezo_imported_at`
- `mezo_last_attempt_at`
- `mezo_error`
- `mezo_duplicate`
- `mezo_api_response`
- `mezo_notes`

Az Apps Script normalizalt header matchinget hasznal, ezert az ekezetes es kerdojeles oszlopneveket stabilan megtalalja. Ettol fuggetlenul elesben a fenti oszlopneveket tartsd meg.

## Apps Script telepites

1. A Google Sheetben nyisd meg: Extensions -> Apps Script.
2. Masold be a `docs/google-sheet-import/Code.gs` tartalmat.
3. Futtasd a `setupMezoScriptProperties()` fuggvenyt.
4. A Project Settings -> Script Properties alatt allitsd be:
   `MEZO_API_TOKEN`: ugyanaz az ertek, mint a backend `LEAD_IMPORT_TOKEN`.
5. Szükség esetén módosítsd:
   `MEZO_API_URL`: `https://mvm-mezoenergy.hu/api/import/facebook-lead`
   `MEZO_MAX_ROWS_PER_RUN`: alapertelmezetten `25`, a script futasonkent legfeljebb 25 sort dolgoz fel.
   `MEZO_RETRY_ERRORS`: `false`; `true` eseten a `HIBA` statuszu sorokat is ujraprobalja.
6. Futtasd az `ensureMezoImportColumns()` fuggvenyt az import oszlopok letrehozasahoz.
7. Csak sikeres kezi teszt utan futtasd az `installMezoFiveMinuteTrigger()` fuggvenyt az 5 perces idoziteshez.

## Standalone Apps Script, ha a bound script nem nyilik meg

Ha a Google Sheet `Bovitmenyek -> Apps Script` menupontja Google Drive hibaval megall, hasznald a standalone verziot.

1. Nyisd meg: `https://script.google.com/`, majd `Uj projekt`.
2. Alternativa: Google Drive -> Uj -> Tovabbiak -> Apps Script.
3. Masold be a `docs/google-sheet-import/Code_standalone.gs` tartalmat.
4. Futtasd: `setupMezoStandaloneScriptProperties()`.
5. Project Settings -> Script Properties alatt allitsd be:
   `MEZO_SPREADSHEET_ID`: a Google Sheet URL-ben a `/d/` es `/edit` kozotti resz.
   `MEZO_SHEET_NAME`: `Munkalap1`.
   `MEZO_API_URL`: `https://mvm-mezoenergy.hu/api/import/facebook-lead`.
   `MEZO_API_TOKEN`: ugyanaz az ertek, mint a backend `LEAD_IMPORT_TOKEN`.
   `MEZO_MAX_ROWS_PER_RUN`: `25`.
   `MEZO_RETRY_ERRORS`: `false`.
6. Futtasd: `ensureMezoImportColumns()`.
7. Regi sorok statusza legyen `NEM_IMPORTÁL`.
8. Csak egy tesztsort allits `ÚJ` vagy `UJ` statuszra.
9. Elso import: `importFirstNewMezoTestRow()`.
10. Csak sikeres teszt utan futtasd: `installMezoFiveMinuteTrigger()`.

## Kezi teszt

Egyetlen sor tesztelesehez jelolj ki egy adatsort, majd futtasd:

```text
importActiveMezoTestRow()
```

Standalone scriptben nincs aktiv kijelolt sorra epulo teszt. Ott ezt futtasd:

```text
importFirstNewMezoTestRow()
```

Tomeges futtatashoz:

```text
runMezoFacebookLeadImport()
```

Standalone idozitett / tomeges futtatas:

```text
importPendingLeads()
```

A script csak ezeket dolgozza fel:

- ures `mezo_import_status`
- `UJ` vagy `ÚJ`
- `HIBA`, ha `MEZO_RETRY_ERRORS=true`

Nem importalja ujra:

- `SIKERES`
- `DUPLIKALT` vagy `DUPLIKÁLT`
- `NEM_IMPORTAL` vagy `NEM_IMPORTÁL`
- `FOLYAMATBAN`
- barmilyen mas, nem engedelyezett statusz

A feldolgozott sor eloszor `FOLYAMATBAN` statust kap, igy egy masik futas nem dolgozza fel ujra.

## Hiba eseten ujraprobalas

Egy sor ujraprobalasahoz allitsd a `mezo_import_status` erteket `ÚJ`-ra, vagy allitsd `MEZO_RETRY_ERRORS=true` ertekre es hagyd `HIBA` statuszon. A `NEM_IMPORTÁL` statuszu regi sorokhoz a script nem nyul.

## Kezi backend probahivas

A `test_import.ps1` a `MEZO_API_TOKEN` kornyezeti valtozobol olvassa a tokent, az `APP_URL`-t pedig opcionalisan hasznalja:

```powershell
$env:MEZO_API_TOKEN = '<token>'
$env:APP_URL = 'https://mvm-mezoenergy.hu'
.\docs\google-sheet-import\test_import.ps1 -Mode normal
.\docs\google-sheet-import\test_import.ps1 -Mode duplicate
.\docs\google-sheet-import\test_import.ps1 -Mode missing-contact
.\docs\google-sheet-import\test_import.ps1 -Mode wrong-token
```

A teszt payload a `docs/google-sheet-import/test_payload.json` fajlban van, csak mintaadatokat tartalmaz.

## Biztonsagi config ellenorzes

Elesites es commit elott olvasd el:

```text
docs/google-sheet-import/SECURITY_CONFIG.md
```

Futtasd a commit elotti segedellenorzest:

```powershell
.\docs\google-sheet-import\check_secrets_before_commit.ps1
```

Ez a script staged es unstaged diffeket nez, erteket nem ir ki, es csak figyelmeztet, ha token / secret / jelszo jellegu kulcsszot lat.
