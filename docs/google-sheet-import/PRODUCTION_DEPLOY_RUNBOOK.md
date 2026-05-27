# Google Sheet import v1 production deploy runbook

Ez a runbook a Google Sheet / Facebook lead import v1 elesitesenek sorrendje. Ne irj bele valodi tokent, jelszot vagy ugyfeladatot. Eles API importot csak a Go / No-Go pontok szerint, kontrollalt tesztsorral futtass.

## A) Deploy elotti mentes

1. Keszits adatbazis mentest phpMyAdminbol az eles adatbazisrol.
2. Keszits `public_html` mentest, vagy jegyezd fel a Git deploy rollback pontot.
3. Mentsd le kulon a szerver `storage/config` mappajat lokalis, biztonsagos helyre.
4. A `storage/config` mentes nem kerulhet GitHubra, issue-ba, ChatGPT-be vagy dokumentacioba.
5. Jegyezd fel a jelenlegi production commitot vagy fajlallapotot.
6. Ellenorizd, hogy a production configban nincs token vagy jelszo a webroot alatt publikus helyen.

## B) GitHub / branch lepes

### Opcio 1: Pull request / merge GitHubon

1. Ellenorizd a feature branchet: `feature/google-sheet-facebook-lead-import-v1`.
2. Nyiss PR-t `main` vagy `master` fele, attol fuggoen, melyik a production deploy alapja.
3. Tekintsd at legalabb ezeket:
   - import endpoint es routing,
   - `lead-import.php`,
   - `database/lead_imports.sql`,
   - Google Sheet dokumentaciok,
   - config es secret handling fajlok.
4. Ellenorizd, hogy nincs valodi secret a diffben.
5. Merge utan production deploy a `main` vagy `master` branchbol menjen.

### Opcio 2: Kozvetlen feature branch deploy

1. Csak akkor hasznald, ha ez a projekt jelenlegi, bevalt elesitesi modja.
2. Deploy elott jegyezd fel a feature branch pontos commitjat.
3. Kockazat: feature branch kozvetlen elesitese nehezebben kovetheto, ha tobb fuggo valtozas is van rajta.
4. Javaslat: productionra inkabb PR / merge utan deployolj.

Ne merge-olj automatikusan ebbol a runbookbol. A merge kulon dontesi pont.

## C) Nethely fajl deploy

### Ha Git pull van a szerveren

1. Lepj be a szerver projektmappajaba.
2. Ellenorizd az aktualis branchet es commitot.
3. Ellenorizd, hogy nincs lokalis, nem mentett eles modositas.
4. Frissitsd a production branchbol vagy a jovahagyott feature commitbol.
5. Deploy utan ellenorizd, hogy az import endpoint fajljai a vart verzion vannak.

### Ha FTP vagy fajlkezelo van

Toltsd fel a backend elesiteshez szukseges fajlokat:

- `public_html/api/import/facebook-lead.php`
- `public_html/includes/lead-import.php`
- `public_html/index.php`, ha routing valtozas kerul ki
- `database/lead_imports.sql`, csak migracio futtatashoz / referenciahoz
- `.env.example`, ha a deploy folyamat dokumentacios fajlokat is szinkronizal
- `storage/config/local.secret.php.example`, csak mintafajlkent, valodi ertek nelkul

Toltsd fel a dokumentacios / teszt fajlokat, ha a szerveren is kell kezi teszt:

- `docs/google-sheet-import/Code.gs`
- `docs/google-sheet-import/README.md`
- `docs/google-sheet-import/GO_LIVE_CHECKLIST.md`
- `docs/google-sheet-import/SECURITY_CONFIG.md`
- `docs/google-sheet-import/PRODUCTION_DEPLOY_RUNBOOK.md`
- `docs/google-sheet-import/KEY_ROTATION_TODO.md`
- `docs/google-sheet-import/test_payload.json`
- `docs/google-sheet-import/test_import.ps1`
- `docs/google-sheet-import/check_secrets_before_commit.ps1`

Ne toltsd fel GitHubbol vagy FTP-n keresztul:

- `.env`
- `.env.*`
- `storage/config/local.php`
- valodi `storage/config/local.secret.php`
- dump, export vagy backup fajlokat
- barmilyen tokent, jelszot vagy kulcsot tartalmazo ideiglenes fajlt

## D) Adatbazis migracio

1. Nyisd meg a Nethely phpMyAdmin feluletet.
2. Valaszd ki a Mezo Energy eles adatbazisat.
3. Nyisd meg az SQL futtatasi feluletet.
4. Masold be es futtasd a `database/lead_imports.sql` tartalmat.
5. Siker eseten latszodnia kell a `lead_imports` tablanak.
6. Ellenorizd az indexeket:
   - `ux_lead_imports_source_external`
   - `idx_lead_imports_customer`
   - `idx_lead_imports_work_request`
   - `idx_lead_imports_status`
7. Ha a `lead_imports` tabla mar letezik, ellenorizd, hogy a fenti mezok es indexek megvannak.
8. Ne modositd kezzel a `source`, `external_lead_id`, `customer_id`, `work_request_id`, `payload_hash` mezoket.
9. Ne torold a `lead_imports` tablat rollbackkor sem, mert audit es idempotencia adatot tartalmazhat.

## E) Production config

1. Generalj legalabb 32 karakteres veletlen `LEAD_IMPORT_TOKEN` erteket.
2. Elsodleges beallitas: szerver oldali kornyezeti valtozo, ha Nethely tamogatja.
3. Fallback: `storage/config/local.secret.php`.
4. A `local.secret.php` a projekt `storage/config` mappajaban legyen, ne publikus webroot alatt kulon kimasolva.
5. A token ne keruljon GitHubra, Google Sheet cellaba, dokumentacioba, outbox riportba vagy hibajegybe.
6. Allitsd be: `APP_URL=https://mezoenergy.hu`.

Pelda `storage/config/local.secret.php` fajlra placeholder ertekkel:

```php
<?php
declare(strict_types=1);

return [
    'LEAD_IMPORT_TOKEN' => 'IDE_JON_A_32_PLUSZ_KARAKTERES_RANDOM_TOKEN',
    'APP_URL' => 'https://mezoenergy.hu',
];
```

Az eles fajlban csak a placeholder helyere keruljon valodi token, es ezt a fajlt soha ne commitold.

## F) Backend endpoint teszt

PowerShell teszt elott allitsd be a lokalis teszt kornyezeti valtozokat:

```powershell
$env:MEZO_API_TOKEN = '<ugyanaz_a_token>'
$env:APP_URL = 'https://mezoenergy.hu'
```

Futtasd ebben a sorrendben:

```powershell
.\docs\google-sheet-import\test_import.ps1 -Mode wrong-token
.\docs\google-sheet-import\test_import.ps1 -Mode missing-contact
.\docs\google-sheet-import\test_import.ps1 -Mode normal
.\docs\google-sheet-import\test_import.ps1 -Mode duplicate
```

Vart eredmenyek:

- `wrong-token`: HTTP 401, JSON `status = HIBA`.
- `missing-contact`: HTTP 422, JSON `status = HIBA`.
- `normal`: JSON `status = SIKERES`, `duplicate = false`.
- `duplicate`: masodik hivasnal JSON `status = DUPLIKÁLT`, `duplicate = true`.

Fontos: a `normal` teszt teszt ugyfelet es munkaigenyt hozhat letre. Utana adminban ellenorizd, es ha kell, jelold egyertelmuen teszt rekordkent. Ne hasznalj valodi ugyfeladatot a teszt payloadban.

## G) Google Sheet beallitas

1. A Google Sheetben nyisd meg: Extensions -> Apps Script.
2. Masold be a `docs/google-sheet-import/Code.gs` tartalmat.
3. Futtasd: `setupMezoScriptProperties()`.
4. Script Properties alatt allitsd be:
   - `MEZO_API_URL=https://mezoenergy.hu/api/import/facebook-lead`
   - `MEZO_API_TOKEN=<ugyanaz_a_backend_token>`
   - `MEZO_MAX_ROWS_PER_RUN=25`
   - `MEZO_RETRY_ERRORS=false`
5. Futtasd: `ensureMezoImportColumns()`.
6. A regi, nem importalando sorok `mezo_import_status` erteke legyen `NEM_IMPORTÁL`.
7. Egyetlen kontrollalt tesztsort allits `ÚJ` statuszra.
8. Jelold ki a tesztsort, majd futtasd: `importActiveMezoTestRow()`.
9. Ellenorizd ezeket az oszlopokat:
   - `mezo_import_status`
   - `mezo_customer_id`
   - `mezo_work_request_id`
   - `mezo_imported_at`
   - `mezo_error`
   - `mezo_duplicate`
   - `mezo_api_response`
10. Csak sikeres kezi teszt utan futtasd: `installMezoFiveMinuteTrigger()`.

## H) Rollback terv

1. Ha az endpoint hibazik, torold vagy kapcsold ki az 5 perces Apps Script triggert.
2. Google Sheetben az uj sorokat ideiglenesen allitsd `STOP` statuszra, ne hagyd uresen.
3. Allitsd vissza a backend regi verziojat Gitbol vagy backupbol.
4. Ne torold automatikusan a `lead_imports` tablat, mert audit es duplikacio-vedelmi adatot tarol.
5. Ha token kiszivargas gyanus, azonnal rotald a backend `LEAD_IMPORT_TOKEN` es Apps Script `MEZO_API_TOKEN` parost.
6. Rotacio utan futtasd ujra a wrong-token es normal backend tesztet.

## I) Go / No-Go dontesi pont

GO, ha:

- `wrong-token` teszt HTTP 401-et ad.
- `missing-contact` teszt HTTP 422-t ad.
- `normal` teszt `SIKERES`.
- `duplicate` teszt `DUPLIKÁLT`.
- Adminban latszik a teszt customer es munkaigeny.
- A Google Sheet visszairja a statuszt es az azonosito mezoket.
- Regi `NEM_IMPORTÁL` sorok nem importalodnak.
- Minden valasz JSON, nem HTML hibauzenet.

NO-GO, ha:

- HTML hiba jon JSON helyett.
- Az endpoint token nelkul vagy ures tokennel mukodik.
- Regi sorokat importal.
- Duplikalt leadbol uj customer vagy uj munkaigeny keszul.
- Customer vagy munkaigeny nem jon letre sikeres importnal.
- `mezo_error` ismeretlen PHP hibakkal telik meg.
- A token megjelenik GitHubon, Google Sheet cellaban vagy riportban.
