# Google Sheet / Facebook Lead Import

## Backend beallitas

Allitsd be a backend kornyezeti valtozokat:

```env
LEAD_IMPORT_TOKEN=
APP_URL=https://mezoenergy.hu
```

Az API vegpont:

```text
POST https://mezoenergy.hu/api/import/facebook-lead
Authorization: Bearer <LEAD_IMPORT_TOKEN>
Content-Type: application/json
```

Az import naplohoz futtasd a `database/lead_imports.sql` migraciot, vagy a teljes `database/schema.sql` frissitett valtozatat. Az endpoint indulaskor is megprobalja letrehozni a `lead_imports` tablat, de eles telepitesnel a migracio futtatasa az ajanlott.

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

## Apps Script telepites

1. A Google Sheetben nyisd meg: Extensions -> Apps Script.
2. Masold be a `docs/google-sheet-import/Code.gs` tartalmat.
3. Futtasd a `setupMezoScriptProperties()` fuggvenyt.
4. A Project Settings -> Script Properties alatt allitsd be:
   `MEZO_API_TOKEN`: ugyanaz az ertek, mint a backend `LEAD_IMPORT_TOKEN`.
5. Szükség esetén módosítsd:
   `MEZO_API_URL`: `https://mezoenergy.hu/api/import/facebook-lead`
   `MEZO_MAX_ROWS_PER_RUN`: alapertelmezetten `25`, a script futasonkent legfeljebb 25 sort dolgoz fel.
   `MEZO_RETRY_ERRORS`: `false`; `true` eseten a `HIBA` statuszu sorokat is ujraprobalja.
6. Futtasd az `ensureMezoImportColumns()` fuggvenyt az import oszlopok letrehozasahoz.
7. Futtasd az `installMezoFiveMinuteTrigger()` fuggvenyt az 5 perces idoziteshez.

## Kezi teszt

Egyetlen sor tesztelesehez jelolj ki egy adatsort, majd futtasd:

```text
importActiveMezoTestRow()
```

Tomeges futtatashoz:

```text
runMezoFacebookLeadImport()
```

A script csak ezeket dolgozza fel:

- ures `mezo_import_status`
- `ÚJ`
- `HIBA`, ha `MEZO_RETRY_ERRORS=true`

Nem importalja ujra:

- `SIKERES`
- `DUPLIKÁLT`
- `NEM_IMPORTÁL`

## Hiba eseten ujraprobalas

Egy sor ujraprobalasahoz allitsd a `mezo_import_status` erteket `ÚJ`-ra, vagy allitsd `MEZO_RETRY_ERRORS=true` ertekre es hagyd `HIBA` statuszon. A `NEM_IMPORTÁL` statuszu regi sorokhoz a script nem nyul.

## Kezi backend probahivas

A `test_import.ps1` a `MEZO_API_TOKEN` kornyezeti valtozobol olvassa a tokent, az `APP_URL`-t pedig opcionálisan hasznalja:

```powershell
$env:MEZO_API_TOKEN = '<token>'
$env:APP_URL = 'https://mezoenergy.hu'
.\docs\google-sheet-import\test_import.ps1
```

A teszt payload a `docs/google-sheet-import/test_payload.json` fajlban van, csak mintaadatokat tartalmaz.
