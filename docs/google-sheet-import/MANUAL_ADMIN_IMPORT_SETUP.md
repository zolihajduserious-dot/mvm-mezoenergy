# Manualis admin Google Sheet import beallitas

Ez a folyamat valtja ki az idozitett Google Sheet importot. A jelenlegi uzleti dontes szerint nincs 5 perces trigger: az ugyvezeto elobb atnezi a Google Sheetet, majd admin feluletrol inditja az importot.

## 1. Standalone Apps Script frissites

1. Nyisd meg a standalone Apps Script projektet a `script.google.com` feluleten.
2. Masold be a repo `docs/google-sheet-import/Code_standalone.gs` fajljanak aktualis tartalmat.
3. Mentsd a projektet.
4. Ne irj valodi tokent a kodba.

Ha meg nincs standalone projekt:

1. `script.google.com` -> `Uj projekt`, vagy Google Drive -> Uj -> Tovabbiak -> Apps Script.
2. A kodfajlba a `Code_standalone.gs` tartalma keruljon.

## 2. Script Properties

Project Settings -> Script Properties alatt ezek kellenek:

- `MEZO_SPREADSHEET_ID`: a Google Sheet URL-ben a `/d/` es `/edit` kozotti resz
- `MEZO_SHEET_NAME`: `Munkalap1`
- `MEZO_API_URL`: `https://mvm-mezoenergy.hu/api/import/facebook-lead`
- `MEZO_API_TOKEN`: a backend `LEAD_IMPORT_TOKEN` erteke
- `MEZO_MAX_ROWS_PER_RUN`: `25`
- `MEZO_RETRY_ERRORS`: `false`
- `MEZO_ADMIN_RUN_TOKEN`: kulon, legalabb 32 karakteres admin futtatasi token

A `MEZO_ADMIN_RUN_TOKEN` ne legyen azonos a `MEZO_API_TOKEN` ertekevel, ha lehet. Egyik token sem kerulhet Google Sheet cellaba, GitHubra, dokumentacioba vagy riportba.

## 3. Apps Script Web app deploy

1. Apps Script jobb felso sarok: `Deploy` / `Bevezetés`.
2. `New deployment` / `Uj telepites`.
3. Type: `Web app`.
4. Execute as: `Me` / sajat fiok.
5. Who has access: olyan beallitas, amely mellett a mezoenergy.hu backend szerveroldali POST hivas eleri a webappot. Tipikusan `Anyone with the link`.
6. Deploy utan masold ki a Web app URL-t.

Fontos: a Web app URL nem token, de ne tedd publikus dokumentacioba. A tenyleges vedelmet a `MEZO_ADMIN_RUN_TOKEN` adja.

## 4. Backend config

Javasolt eles beallitas: ne a meglevo `storage/config/local.secret.php` fajlt szerkeszd kezzel, hanem kulon, ignore-olt Google Sheet import secret fajlt hasznalj:

```text
storage/config/google-sheet-import.secret.php
```

Ezt a fajlt a `deploy-google-sheet-import-secret.yml` GitHub Actions workflow tudja legeneralni es feltolteni GitHub Secretsbol. A workflow nem modositja a `LEAD_IMPORT_TOKEN` erteket es nem nyul a `local.secret.php` fajlhoz.

Szukseges GitHub Secrets:

- `GOOGLE_SHEET_IMPORT_WEBAPP_URL`
- `GOOGLE_SHEET_IMPORT_WEBAPP_TOKEN`

Nethelyen szerver oldali env valtozokent, vagy az ignore-olt `storage/config/google-sheet-import.secret.php` fajlban allitsd be:

- `GOOGLE_SHEET_IMPORT_WEBAPP_URL`: Apps Script Web app URL
- `GOOGLE_SHEET_IMPORT_WEBAPP_TOKEN`: ugyanaz az ertek, mint a `MEZO_ADMIN_RUN_TOKEN`

Pelda placeholderrel:

```php
<?php
return [
    'GOOGLE_SHEET_IMPORT_WEBAPP_URL' => 'IDE_JON_A_WEBAPP_URL',
    'GOOGLE_SHEET_IMPORT_WEBAPP_TOKEN' => 'IDE_JON_A_32_PLUSZ_KARAKTERES_ADMIN_TOKEN',
];
```

Valodi token nem kerulhet GitHubra.

Kezi `run-approved` import elott kotelezo:

- Apps Script Web App `health` teszt JSON valasszal terjen vissza.
- Elvart: HTTP 200, `status: OK`, `action: health`.
- Idozitett trigger tovabbra sincs hasznalatban.

## 5. Google Sheet statusz workflow

1. Facebook lead beerkezik a Sheetbe.
2. A sor alapbol ures / ellenorzesre var.
3. Admin vagy ugyvezeto atnezi.
4. Ha importalando: `mezo_import_status = IMPORTÁLANDÓ`.
5. Ha nem importalando: `mezo_import_status = ELUTASÍTVA` vagy `NEM_IMPORTÁL`.
6. Admin oldal: `/admin/google-sheet-import`.
7. `Állapot lekérdezése`.
8. `Jóváhagyott sorok importálása`.
9. A Sheet visszakapja: `SIKERES`, `DUPLIKÁLT` vagy `HIBA`.

## 6. Teszt

1. Futtasd Apps Scriptben: `ensureMezoImportColumns()`.
2. Egyetlen kontrollalt tesztsor statusza legyen `IMPORTÁLANDÓ`.
3. Admin feluleten kattints: `Állapot lekérdezése`.
4. Ellenorizd, hogy az importalhato sorok szama 1.
5. Kattints: `Jóváhagyott sorok importálása`.
6. Ellenorizd a Sheetben:
   - `mezo_import_status`
   - `mezo_customer_id`
   - `mezo_work_request_id`
   - `mezo_error`
   - `mezo_duplicate`
   - `mezo_api_response`

## 7. Trigger

Jelenleg nem hasznaljuk:

- `installMezoFiveMinuteTrigger()`

Ha korabban letrejott idozitett trigger, torold:

- admin felulet: `Automata triggerek törlése`
- vagy Apps Script: `deleteMezoImportTriggers()`

Az automata trigger bekapcsolasa kulon uzleti dontest igenyel.
