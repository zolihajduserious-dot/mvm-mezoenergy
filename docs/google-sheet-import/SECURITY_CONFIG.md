# Google Sheet Import Security Config

## Cel

A Google Sheet / Facebook lead import tokenje es minden mas integracios titok csak szerver oldali kornyezeti valtozoban vagy ignore-olt local secret fajlban szerepelhet. Valodi token, SMTP jelszo, SMS API kulcs, adatbazis jelszo, OpenAI kulcs vagy Nethely hozzaferes nem kerulhet GitHubra.

## Elsodleges beallitas Nethelyen

Az elsodleges javaslat a szerver oldali kornyezeti valtozo:

```text
LEAD_IMPORT_TOKEN=<legalabb 32 karakteres veletlen token>
APP_URL=https://mezoenergy.hu
```

Ha a Nethely admin feluleten van PHP / Apache / hosting kornyezeti valtozo beallitasi lehetoseg, ott allitsd be a `LEAD_IMPORT_TOKEN` erteket. A token ne keruljon Google Sheet cellaba, dokumentacioba, hibajegybe vagy outbox riportba.

## Fallback local secret fajl

Ha a hosting nem ad kenyelmes kornyezeti valtozo beallitast, a projekt tamogatja az ignore-olt secret fajlt:

```text
storage/config/local.secret.php
```

Indulj a placeholder fajlbol:

```text
storage/config/local.secret.php.example
```

Masold a szerveren `storage/config/local.secret.php` neven, es csak ott toltsd ki az ertekeket. Ez a fajl gitignore-olt, nem commitolhato.

## Verzionalt local config

A `storage/config/local.php` nem valo titkok tarolasara. A tracked allapotbol ki kell vezetni, es lokalis / eles kornyezetben csak ignore-olt fajlkent maradhat meg. A repoban a biztonsagos minta:

```text
storage/config/local.php.example
```

Ez csak nem titkos, pelda jellegu beallitasokat tartalmazhat.

## Mit nem szabad GitHubra tolteni

- `LEAD_IMPORT_TOKEN`
- `MEZO_API_TOKEN`
- SMS API token vagy SMS kulcs
- SMTP jelszo
- adatbazis jelszo
- OpenAI kulcs
- Szamlazz.hu agent key
- Nethely hozzaferesi adat
- teljes `.env` fajl
- `storage/config/local.secret.php`
- export, dump, backup vagy ideiglenes token fajl

## Ellenorzes commit elott

Futtasd:

```powershell
.\docs\google-sheet-import\check_secrets_before_commit.ps1
git status --short
git diff --check
```

A script csak figyelmeztet. Ha gyanus kulcsszot jelez, kezzel nezd at a valtozast, es ne commitolj erteket.

Hasznos tovabbi ellenorzesek, amelyek csak fajlneveket vagy statuszt irnak ki:

```powershell
git grep -l "LEAD_IMPORT_TOKEN"
git grep -l "MEZO_API_TOKEN"
git status --ignored --short -- storage/config/local.php storage/config/local.secret.php .env .env.example
```

## Token csere

1. Generalj uj, legalabb 32 karakteres veletlen tokent.
2. Allitsd be backend oldalon `LEAD_IMPORT_TOKEN` kornyezeti valtozokent vagy `storage/config/local.secret.php` alatt.
3. A Google Apps Script Project Settings / Script Properties alatt csereld a `MEZO_API_TOKEN` erteket.
4. Ha az admin-run token is erintett, csereld a `MEZO_ADMIN_RUN_TOKEN` es backend `GOOGLE_SHEET_IMPORT_WEBAPP_TOKEN` erteket is.
5. Futtass egy `test_import.ps1 -Mode wrong-token` ellenorzest, majd admin feluleten kontrollalt preview / run-approved tesztet.
6. Idozitett trigger jelenleg nem hasznalando.

## Veszkori rotacio

1. Azonnal torold vagy ervenytelenitsd a backend `LEAD_IMPORT_TOKEN` erteket. Igy az import endpoint 503 JSON hibara valt, ha nincs ervenyes token.
2. Torold az Apps Script 5 perces triggeret, ha korabban veletlenul telepult.
3. Generalj uj tokent, es allitsd be backend + Apps Script oldalon.
4. Futtasd ujra a kezi teszteket.
5. Ellenorizd, hogy a regi token nem szerepel GitHubon, Google Sheetben, outbox riportban vagy dokumentacioban.
