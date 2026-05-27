# Google Sheet import v1 key rotation todo

Ebben a fajlban nem szerepelhet valodi kulcsertek, token, jelszo vagy hozzaferes.

## Miert kell rotaciot tervezni

A release elotti config takaritas soran a `storage/config/local.php` kikerult a repobol, es a secret mintat `storage/config/local.secret.php` iranyba vittuk. Mivel korabban tracked config vagy hardcoded fallback is tartalmazhatott secret-gyanus ertekeket, az erintett integracios kulcsokat biztonsagi okbol rotalni kell.

## Erintett kulcstipusok

- SMS szolgaltatoi kulcsok, ha korabban tracked configban szerepeltek.
- Szamlazz.hu agent key vagy mas Szamlazz.hu kulcs, ha korabban tracked configban vagy fallbackben szerepelt.
- SMTP jelszavak, ha valaha tracked fajlban voltak.
- MVM / kulso API kulcsok, mailbox jelszavak vagy konverter szolgaltatasi kulcsok, ha tracked fajlban voltak.
- OpenAI vagy dokumentum-elotolteshez hasznalt API kulcsok, ha tracked fajlban voltak.
- `LEAD_IMPORT_TOKEN`, ha valaha commitolva vagy megosztva lett volna.
- Nethely / adatbazis hozzaferesek, ha barmilyen nem biztonsagos helyre kerultek.

## Javasolt rotacios sorrend

1. Azonositsd az osszes erintett kulcsot a szolgaltatoi admin feluleteken, ertekek dokumentalasa nelkul.
2. Rotacio elott keszits uzemeltetesi idopontot, mert SMS, szamlazas, email vagy import funkcio atmenetileg erintett lehet.
3. Rotaltasd a kulso szolgaltatoi kulcsokat:
   - SMS szolgaltato,
   - Szamlazz.hu,
   - SMTP / mailbox,
   - MVM vagy egyeb kulso API,
   - OpenAI / dokumentum-elotoltes, ha hasznalt.
4. Generalj uj `LEAD_IMPORT_TOKEN` erteket, ha barmilyen gyanus megosztas vagy commit tortent.
5. Allitsd be az uj ertekeket szerver oldali env valtozokent vagy `storage/config/local.secret.php` fajlban.
6. Frissitsd a Google Apps Script `MEZO_API_TOKEN` erteket, ha az import token rotalodott.
7. Ellenorizd, hogy a regi kulcsok tenyleg ervenytelenek.

## Rotacio utani ellenorzes

- SMS kuldes admin teszt vagy dry-run szerint.
- Szamlazz.hu kapcsolodas teszt szamlazas nelkul, ahol lehet.
- SMTP kuldes vagy rendszer email teszt.
- MVM / kulso API kapcsolodas teszt.
- Google Sheet import backend tesztek:
  - `wrong-token` 401,
  - `missing-contact` 422,
  - `normal` SIKERES,
  - `duplicate` DUPLIKÁLT.
- Google Sheet egy tesztsor importja `importActiveMezoTestRow()` fuggvennyel.
- Git status es secret-check:

```powershell
.\docs\google-sheet-import\check_secrets_before_commit.ps1
git status --short
```

## GitHub secret scanning / history purge

GitHub secret scanning es history purge akkor indokolt, ha:

- valodi kulcs bizonyithatoan bekerult korabbi commitba,
- a repohoz kulso vagy szelesebb hozzaferes volt,
- a kulcs production szolgaltatast erint,
- a szolgaltato nem ad biztos visszajelzest arrol, hogy a regi kulcs mar ervenytelen.

History purge elott keszits kulon tervet, mert a force push erintheti mas fejlesztok lokalis munkajat. A rotacio mindig elobb tortenjen meg, a history tisztitas csak utana.

## Tiltott helyek

Valodi kulcsot vagy jelszot ne irj ide:

- ChatGPT vagy Codex uzenet,
- GitHub issue vagy PR comment,
- dokumentacio,
- outbox riport,
- Google Sheet cella,
- email vagy chat uzenet,
- screenshot,
- `.env.example` vagy `*.example` fajl.
