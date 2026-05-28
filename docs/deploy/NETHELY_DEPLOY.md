# Nethely deploy Codexbol

Ez a dokumentum a Mezo Energy Nethely deploy biztonsagos, lokalisan futtathato folyamatat irja le. A cel nem a teljes repo vak feltoltese, hanem egy elore osszeallitott manifestben felsorolt production fajlok kontrollalt feltoltese.

## Meglevo deploy

A repoban van GitHub Actions alapu deploy:

- `.github/workflows/deploy.yml`
- trigger: `main` branch push
- mod: FTPS, GitHub secretsbol
- feltoltes: `public_html/` es `templates/`

Ez teljesebb production szinkron, ezert kezi hotfix vagy szuk fajllistas elesites eseten a helyi manifest alapu deploy hasznalhato.

## Google Sheet import secret deploy

A manualis admin Google Sheet import Web App URL/token parjat nem kell es nem javasolt kezzel a production `local.secret.php` fajlba irni. Ehhez kulon workflow van:

```text
.github/workflows/deploy-google-sheet-import-secret.yml
```

Kezi inditasu workflow:

```powershell
gh workflow run deploy-google-sheet-import-secret.yml
```

Szukseges GitHub Secrets:

- `GOOGLE_SHEET_IMPORT_WEBAPP_URL`
- `GOOGLE_SHEET_IMPORT_WEBAPP_TOKEN`
- `FTP_HOST`
- `FTP_USER`
- `FTP_PASS`

A workflow GitHub runneren generalja ezt a fajlt, majd FTPS-sel csak ezt tolti fel:

```text
storage/config/google-sheet-import.secret.php
```

Nem modosul:

- `storage/config/local.secret.php`
- `LEAD_IMPORT_TOKEN`
- adatbazis
- Google Sheet trigger

Elso admin hasznalat elott az Apps Script Web App `health` tesztnek JSON valaszt kell adnia: HTTP 200, `status: OK`, `action: health`.

## Lokalis credential helye

A Nethely credential nem lehet a repoban. A setup script ezt a repón kivuli mappat hasznalja:

```text
C:\Szaki24-dev\ACTIVE_PROJECTS\mezoenergy.hu\_codex_comm\secrets\nethely_deploy\
```

Itt jon letre:

- `credential.clixml` - a Windows felhasznalohoz kotott titkositott credential
- `deploy-config.json` - nem jelszo jellegu kapcsolati beallitasok

Ezeket nem szabad GitHubra, ChatGPT-be, riportba vagy dokumentacioba masolni.

## WinSCP kovetelmeny

A deploy es verify script a WinSCP parancssori klienset hasznalja:

```text
winscp.com
```

Elfogadott helyek:

- PATH-ban elerheto `winscp.com`
- `C:\Program Files (x86)\WinSCP\WinSCP.com`
- `C:\Program Files\WinSCP\WinSCP.com`

Ha nincs telepitve, telepites utan futtasd ujra a deployt. Telepitest csak jovahagyott, helyi admin folyamatban szabad vegezni.

## Egyszeri credential setup

Futtasd lokalis PowerShellbol:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\setup-nethely-deploy-credential.ps1
```

A script helyben kerdezi be:

- protocol: `ftps`, `ftp` vagy `sftp`
- host
- port
- username
- password secure prompttal
- remote root, pelda: `/public_html`

Alapertelmezett ajanlas Nethely FTP deployhoz: `ftps`, port `21`, remote root `/public_html`.

## Deploy manifest

A manifest a repón kivul legyen, pelda:

```text
C:\Szaki24-dev\ACTIVE_PROJECTS\mezoenergy.hu\_codex_comm\deploy\2026-05-27_customer_portal_email_fix_manifest.json
```

Pelda szerkezet:

```json
{
  "name": "customer portal and activation email fix",
  "commit": "COMMIT_HASH",
  "remoteRoot": "/public_html",
  "files": [
    {
      "local": "public_html/includes/crm.php",
      "remote": "includes/crm.php"
    }
  ]
}
```

A `remote` ertek a `remoteRoot` alatti relativ utvonal. A script tiltja a secret, config, dump, backup, docs es git jellegu utvonalakat.

## Dry run

Deploy elott ellenorizd a tervet:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\deploy-nethely.ps1 -ManifestPath "C:\Szaki24-dev\ACTIVE_PROJECTS\mezoenergy.hu\_codex_comm\deploy\2026-05-27_customer_portal_email_fix_manifest.json" -DryRun
```

## Eles feltoltes

Csak akkor futtasd, ha:

- a main branch naprakész,
- a manifest csak a szukseges production fajlokat tartalmazza,
- nincs benne secret vagy dokumentacios fajl,
- a credential lokalisan be van allitva.

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\deploy-nethely.ps1 -ManifestPath "C:\Szaki24-dev\ACTIVE_PROJECTS\mezoenergy.hu\_codex_comm\deploy\2026-05-27_customer_portal_email_fix_manifest.json"
```

A feltoltesrol sanitizalt log keszul:

```text
C:\Szaki24-dev\ACTIVE_PROJECTS\mezoenergy.hu\_codex_comm\deploy\logs\
```

A log nem tartalmazhat jelszot vagy tokent.

## Verify

FTP/FTPS/SFTP oldali fajllista es publikus HTTP ellenorzes:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\verify-nethely-deploy.ps1 -ManifestPath "C:\Szaki24-dev\ACTIVE_PROJECTS\mezoenergy.hu\_codex_comm\deploy\2026-05-27_customer_portal_email_fix_manifest.json"
```

HTTP ellenorzes celja:

```text
https://mvm-mezoenergy.hu/customer/work-requests
```

Nem kell es nem szabad mas ugyfel fiokjaval belepni. A publikus valasz lehet bejelentkezesi oldal vagy atiranyitas, a lenyeg, hogy ne legyen szerverhiba.

## Mit nem szabad deployolni

- `.git`
- `.env`, `.env.*`
- `storage/config/local.php`
- `storage/config/local.secret.php`
- `_codex_comm/secrets`
- `docs/`, ha nincs kulon indok
- dump, backup, export, log es zip fajlok
- barmilyen token, jelszo vagy kulcs

## Rollback alaplepesek

1. Ne torolj production fajlt automatikusan.
2. Allitsd vissza az elozo fajlverziot backupbol vagy Git commitbol.
3. Csak ugyanazokat a fajlokat toltsd vissza, amelyeket a manifest deployolt.
4. Ha credential vagy token kiszivargas gyanus, rotald az erintett credentialt.
5. A Google Sheet triggert csak kulon dontesi pont utan szabad bekapcsolni.
