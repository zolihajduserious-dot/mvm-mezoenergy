# Google Sheet / Facebook Lead Import

## Backend beallitas

Allitsd be a backend kornyezeti valtozokat:

```env
LEAD_IMPORT_TOKEN=
APP_URL=https://mvm-mezoenergy.hu
GOOGLE_SHEET_IMPORT_WEBAPP_URL=
GOOGLE_SHEET_IMPORT_WEBAPP_TOKEN=
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

A kezi admin import felulethez a backendnek kulon Apps Script webapp URL es kulon admin futtatasi token kell:

- `GOOGLE_SHEET_IMPORT_WEBAPP_URL`: a standalone Apps Script Web app URL-je
- `GOOGLE_SHEET_IMPORT_WEBAPP_TOKEN`: ugyanaz a titok, mint az Apps Script `MEZO_ADMIN_RUN_TOKEN` Script Property

Ezek nem jelenhetnek meg HTML-ben, GitHubon, Google Sheet cellaban vagy dokumentacioban.

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

## Importalt ugyfel email

Ha a Google Sheet import uj ugyfel fiokot hoz letre, az ugyfel fiokaktivalo emailt kap, nem normal jelszo-visszaallitasi emailt.

- subject: `Mező Energy ügyfélportál – fiók aktiválása`
- cim: `Fiók aktiválása`
- gomb: `Fiók aktiválása`
- a szoveg roviden elmagyarazza, hogy a mezoenergy.hu a merohelyi, arambovitesi es elosztoi ugyintezesi folyamat szakmai elokesziteseben segit
- a szoveg tartalmazza, hogy a Mezo Energy Kft. fuggetlen szakmai segitseget nyujt, nem az MVM Csoport tagja, nem hivatalos MVM ugyfelszolgalat, es nem az MVM neveben jar el
- a szoveg vilagossa teszi, hogy a linkkel az ugyfel sajat jelszot allit be es aktivalja az ugyfelportal-hozzaferest

A normal `Jelszo elfelejtese` / password reset folyamat kulon marad, es tovabbra is a `Jelszó-visszaállítás` tartalmat kuldi.

## Importalt ugyfel adatpontositas

Az importalt uj ugyfel az ugyfelportalon a sajat adatlapjat tudja pontositani. Csak a sajat `customer_id` ala tartozo work request mentheto, mas ugyfel adatlapja nem.

Ugyfel oldalon szerkesztheto alapmezok:

- adatlap neve / munka neve
- igenytipus
- cim / ingatlan helye
- HRSZ
- mero gyari szama
- fogyasztasi hely azonosito
- MVM UK szam
- meglevo es igenyelt mindennapszaki teljesitmeny
- ugyfel megjegyzes / pontositas

Nem szerkesztheto ugyfel oldalrol: belso admin statusz, szerelo hozzarendeles, ajanlat statusz, import audit, `source`, `external_lead_id` es `lead_imports` naplo.

A technikai import osszefoglalo admin auditkent a `work_note` mezoben marad. Az ugyfel altal szerkesztheto pontositas a `notes` mezot hasznalja, igy nem irja felul az import auditot.

## Adatlapnev generalas

Az import most eloszor explicit adatlapnevet keres: `work_request_title`, `request_title`, `adatlap_neve`, `adatlap neve`, `munka_neve`, `munka neve`, `igeny_neve`, `igény neve`.

Ha nincs explicit adatlapnev, a cim a `work_type` alapjan keszul, es csak finoman kapja meg a telepulest vagy ingatlan helyet. Pelda:

- regi rossz forma: `TESZT_TESZT_3_fázisra_átállás`
- uj forma: `3 fázisra átállás – TESZT`

Az automata Google Sheet trigger jelenleg nem hasznalando. Az importot az admin inditja a `/admin/google-sheet-import` oldalon, miutan a Google Sheet sorait atnezte es `IMPORTÁLANDÓ` / `JÓVÁHAGYVA` statuszra allitotta.

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

## Kezi admin import workflow

Az import jelenlegi uzleti modja admin-gombos, nem idozitett.

1. Facebook lead beerkezik a Google Sheetbe.
2. A sor alapbol ures statuszon vagy ellenorzesre varo allapotban marad.
3. Az admin / ugyvezeto atnezi a sort a Google Sheetben.
4. Ha valosnak tunik, a `mezo_import_status` erteke legyen `IMPORTÁLANDÓ` vagy `JÓVÁHAGYVA`.
5. Ha nem kell importalni, a statusz legyen `ELUTASÍTVA` vagy `NEM_IMPORTÁL`.
6. Admin felulet: `/admin/google-sheet-import`.
7. Eloszor: `Állapot lekérdezése`.
8. Ezutan: `Jóváhagyott sorok importálása`.
9. A Google Sheetbe visszairodik: `SIKERES`, `DUPLIKÁLT` vagy `HIBA`.
10. Idozitett trigger nincs hasznalatban.

Importalhato statuszok:

- `IMPORTÁLANDÓ`
- `IMPORTALANDO`
- `JÓVÁHAGYVA`
- `JOVAHAGYVA`

Nem importalhato statuszok:

- ures statusz
- `ÚJ` / `UJ`
- `ELLENŐRZÉSRE_VÁR` / `ELLENORZESRE_VAR`
- `NEM_IMPORTÁL` / `NEM_IMPORTAL`
- `ELUTASÍTVA` / `ELUTASITVA`
- `SIKERES`
- `DUPLIKÁLT` / `DUPLIKALT`
- `FOLYAMATBAN`
- `HIBA`

## Apps Script telepites

1. A Google Sheetben nyisd meg: Extensions -> Apps Script.
2. Masold be a `docs/google-sheet-import/Code.gs` tartalmat.
3. Futtasd a `setupMezoScriptProperties()` fuggvenyt.
4. A Project Settings -> Script Properties alatt allitsd be:
   `MEZO_API_TOKEN`: ugyanaz az ertek, mint a backend `LEAD_IMPORT_TOKEN`.
5. Szükség esetén módosítsd:
   `MEZO_API_URL`: `https://mvm-mezoenergy.hu/api/import/facebook-lead`
   `MEZO_MAX_ROWS_PER_RUN`: alapertelmezetten `25`, a script futasonkent legfeljebb 25 sort dolgoz fel.
   `MEZO_RETRY_ERRORS`: `false`; a `HIBA` statuszu sorok automatikus ujraprobalasa jelenleg nincs hasznalatban.
6. Futtasd az `ensureMezoImportColumns()` fuggvenyt az import oszlopok letrehozasahoz.
7. Az `installMezoFiveMinuteTrigger()` jelenleg nem hasznalando. Az uzleti folyamat kezi admin importot hasznal.

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
   `MEZO_ADMIN_RUN_TOKEN`: kulon, legalabb 32 karakteres admin futtatasi token a backend admin oldalhoz.
   `MEZO_MAX_ROWS_PER_RUN`: `25`.
   `MEZO_RETRY_ERRORS`: `false`.
6. Futtasd: `ensureMezoImportColumns()`.
7. Regi sorok statusza legyen `NEM_IMPORTÁL` vagy `ELUTASÍTVA`.
8. Egy kontrollalt tesztsort allits `IMPORTÁLANDÓ` statuszra.
9. Elso kezi teszt: admin oldalon `Állapot lekérdezése`, majd `Jóváhagyott sorok importálása`.
10. Az `installMezoFiveMinuteTrigger()` jelenleg nem hasznalando.

## Kezi teszt

Egyetlen sor tesztelesehez jelolj ki egy adatsort, majd futtasd:

```text
importActiveMezoTestRow()
```

Standalone scriptben nincs aktiv kijelolt sorra epulo teszt. Ha Apps Scriptbol kezzel tesztelsz, csak `IMPORTÁLANDÓ` vagy `JÓVÁHAGYVA` statuszu sort hasznalj:

```text
importFirstNewMezoTestRow()
```

Tomeges importot ne Apps Scriptbol indits. Hasznald az admin feluletet:

```text
/admin/google-sheet-import
```

A script es az admin gombos import csak ezeket dolgozza fel:

- `IMPORTÁLANDÓ` / `IMPORTALANDO`
- `JÓVÁHAGYVA` / `JOVAHAGYVA`

Nem importalja ujra:

- ures statusz
- `UJ` vagy `ÚJ`
- `ELLENŐRZÉSRE_VÁR` vagy `ELLENORZESRE_VAR`
- `SIKERES`
- `DUPLIKALT` vagy `DUPLIKÁLT`
- `NEM_IMPORTAL` vagy `NEM_IMPORTÁL`
- `ELUTASITVA` vagy `ELUTASÍTVA`
- `FOLYAMATBAN`
- `HIBA`
- barmilyen mas, nem engedelyezett statusz

A feldolgozott sor eloszor `FOLYAMATBAN` statust kap, igy egy masik futas nem dolgozza fel ujra.

## Hiba eseten ujraprobalas

Egy sor ujraprobalasahoz az admin ellenorzes utan allitsd vissza a `mezo_import_status` erteket `IMPORTÁLANDÓ` vagy `JÓVÁHAGYVA` ertekre. A `HIBA` statuszu sorok automatikus ujraprobalasa jelenleg nincs hasznalatban. A `NEM_IMPORTÁL` es `ELUTASÍTVA` statuszu sorokhoz a script nem nyul.

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
