# ADATKEZELÉSI TÁJÉKOZTATÓ

**Mező Energy CRM / mérőhelyi ügyintézési és villanyszerelői munkaszervező rendszer**

Verzió: 0.1
Hatályos: 2026.05.15.
Dokumentum státusza: Jóváhagyott

## 1. Az adatkezelő adatai

Adatkezelő: **Mező Energy Kft.**
Székhely: 5820 Mezőhegyes, Tavasz u. 4.
Cégjegyzékszám: 04-09-015858
Adószám: 29260179-2-04
Képviselő: Hajdu Zoltán
Email: hajdu.zoltan@mvm-mezoenergy.hu
Telefon: +36 30 165 49 41
Weboldal / rendszer:  https://mvm-mezoenergy.hu

Adatvédelmi kapcsolattartó: hajdu Zoltán
Adatvédelmi tisztviselő: : "jelenleg nincs kijelölve"

## 2. A rendszer szerepe és az adatkezelői felelősségi modell

A rendszer eredetileg a Mező Energy Kft. belső CRM és ügyintézési rendszerének készült. A szolgáltatás fokozatosan nyilvánosabb felhasználói kör felé nyílik meg, különösen villanyszerelők, generálkivitelezők és más üzleti felhasználók számára.

Ennek megfelelően két fő adatkezelési helyzet fordulhat elő:

1. **Mező Energy saját ügyfelei és saját ügyei**
   Ilyenkor a Mező Energy Kft. önálló adatkezelőként határozza meg az adatkezelés célját és eszközeit.

2. **Külső szerelő, generálkivitelező vagy üzleti felhasználó saját ügyfelei**
   Ilyenkor az adott szerelő/generálkivitelező minősül az ügyféladatok elsődleges adatkezelőjének. A Mező Energy Kft. a rendszer üzemeltetőjeként adatfeldolgozói szerepben jár el, a felhasználó utasításai és az adatfeldolgozói megállapodás szerint.

A rendszer használója köteles saját ügyfeleit a saját adatkezeléséről megfelelően tájékoztatni, és köteles biztosítani, hogy az általa rögzített személyes adatok kezeléséhez megfelelő jogalappal rendelkezzen.

## 3. Alapelvek

A Mező Energy Kft. az adatkezelést különösen az alábbi elvek szerint végzi:

- jogszerűség, tisztességes eljárás és átláthatóság;
- célhoz kötöttség;
- adattakarékosság;
- pontosság;
- korlátozott tárolhatóság;
- integritás és bizalmas jelleg;
- elszámoltathatóság;
- beépített és alapértelmezett adatvédelem.

## 4. Kezelt adatkategóriák

A rendszerben az alábbi adatkategóriák fordulhatnak elő:

- felhasználói azonosító adatok: név, email cím, telefonszám, szerepkör, jelszó hash, email-megerősítés állapota;
- ügyféladatok: név, születési név, anyja neve, születési hely és idő, lakcím/postai cím, email, telefonszám, cégadatok;
- mérőhelyi és munkavégzési adatok: fogyasztási hely, helyrajzi szám, mérőóra adatok, teljesítményigény, H tarifa, vezérelt mérő, napelemes rendszerre utaló adatok, munkatípus;
- dokumentumok és fájlok: meghatalmazások, MVM/elosztói dokumentumok, fotók, műszaki átadási dokumentumok, kivitelezési képek, árajánlatok, egyéb feltöltött mellékletek;
- kommunikációs adatok: email küldési naplók, kiküldött linkek, státuszértesítések, admin megjegyzések;
- rendszerbiztonsági adatok: IP cím, munkamenet azonosítók, belépési és műveleti naplók, hibajegyek, jogosultsági változások;
- számlázási és szerződéses adatok, ha fizetős szolgáltatás indul: előfizetés, díjcsomag, számlázási cím, fizetési státusz, számlaszám.

A rendszer nem kifejezetten különleges személyes adatok kezelésére készült. A felhasználók kötelesek mellőzni egészségügyi, biometrikus, politikai, vallási, szakszervezeti vagy egyéb különleges adatok feltöltését, kivéve ha arra kifejezett jogalappal rendelkeznek, és a Mező Energy Kft.-vel erről előzetesen írásban egyeztettek.

## 5. Adatkezelési célok és jogalapok

### 5.1. Fiók létrehozása, belépés, jogosultságkezelés

Cél: felhasználói fiók létrehozása, azonosítása, szerepkörök és jogosultságok kezelése.
Jogalap: szerződés teljesítése vagy szerződéskötést megelőző lépések; jogos érdek a rendszer biztonságos működtetéséhez.
Adatok: név, email, telefonszám, jelszó hash, szerepkör, email-megerősítés állapota, belépési adatok.
Megőrzés: fiók fennállása alatt, majd a jogi igényérvényesítési és biztonsági megőrzési idő végéig.

### 5.2. Email megerősítés és visszaélések csökkentése

Cél: hamis vagy téves regisztrációk, illetéktelen hozzáférések és visszaélések csökkentése.
Jogalap: jogos érdek; szerződés teljesítése.
Adatok: email cím, megerősítő kód hash-e, lejárati idő, próbálkozások száma, IP cím.
Megőrzés: a kód lejáratáig, illetve biztonsági és visszaélés-megelőzési célból korlátozott ideig.

### 5.3. Ügyfél- és munkalapkezelés

Cél: mérőhelyi igények, kivitelezési feladatok, ügyféladatlapok, dokumentumok, árajánlatok és munkafolyamatok kezelése.
Jogalap: szerződés teljesítése; szerződéskötést megelőző lépések; jogos érdek; jogi kötelezettség, ha a dokumentumkezelés vagy megőrzés jogszabályon alapul.
Adatok: ügyfél- és munkaadatok, címek, műszaki adatok, dokumentumok, fotók, státuszok, kapcsolattartási adatok.

Ha az adatokat külső szerelő vagy generálkivitelező saját ügyfeleiről rögzíti, akkor a Mező Energy Kft. a rögzített ügyféladatok tekintetében adatfeldolgozóként jár el.

### 5.4. MVM/elosztói ügyintézés és dokumentumküldés

Cél: dokumentumok előkészítése, kitöltése, feltöltése, továbbítása, ügyintézési folyamat követése.
Jogalap: szerződés teljesítése; jogi kötelezettség; ügyfél vagy meghatalmazó hozzájárulása, ha az adott ügyintézés jellege ezt megköveteli.
Címzettek: illetékes elosztói vagy közműszolgáltatói szereplők, meghatalmazottak, szerelők, adminisztrátorok.

### 5.5. Árajánlatok, díjak, számlázás

Cél: ajánlatkészítés, ajánlat elfogadása, díjazás, esetleges előfizetés és számlázás.
Jogalap: szerződés teljesítése; jogi kötelezettség; jogos érdek a követelések érvényesítéséhez.
Megőrzés: a számviteli és adózási megőrzési kötelezettségek szerint.

### 5.6. Rendszerbiztonság, naplózás, hibakeresés

Cél: jogosulatlan hozzáférés megakadályozása, hibák feltárása, incidenskezelés, bizonyíthatóság.
Jogalap: jogos érdek; jogi kötelezettség bizonyos incidenskezelési esetekben.
Adatok: IP cím, böngészőadatok, műveleti naplók, hibakódok, hozzáférési naplók.
Megőrzés: biztonsági szükséglethez igazított korlátozott idő, eltérő jogi igény esetén a szükséges ideig.

### 5.7. Kapcsolattartás, támogatás, panaszkezelés

Cél: felhasználói támogatás, panaszok és kérelmek kezelése, adminisztratív egyeztetés.
Jogalap: szerződés teljesítése; jogos érdek; jogi kötelezettség.
Adatok: név, email, telefonszám, üzenetek, ügyazonosítók.

### 5.8. Marketing és hírlevél

Marketing célú megkeresést a Mező Energy Kft. csak külön, önkéntes hozzájárulás alapján küld, kivéve azokat a szűken értelmezett szolgáltatási üzeneteket, amelyek a rendszer működéséhez vagy a szerződés teljesítéséhez szükségesek.

## 6. Adatfeldolgozók és címzettek

A személyes adatokhoz kizárólag azok férhetnek hozzá, akiknek erre feladatuk ellátásához szükségük van.

Lehetséges címzettek:

- Mező Energy Kft. adminisztrátorai, kijelölt munkatársai;
- a feladathoz rendelt szerelő, generálkivitelező vagy alvállalkozó;
- tárhelyszolgáltató és informatikai üzemeltető;
- email szolgáltató / SMTP szolgáltató;
- könyvelő, jogi tanácsadó, követeléskezelő, biztosító;
- hatóságok, bíróságok, közműszolgáltatók, elosztói engedélyesek, ha az ügyintézés vagy jogszabály ezt indokolja.

Az aktuálisan igénybe vett adatfeldolgozók kategóriái:

| Adatfeldolgozói kategória | Szolgáltatás | Terület | Garancia |
| --- | --- | --- | --- |
| Tárhely- és szerverüzemeltető | tárhely, szerver, adatbázis | EGT vagy megfelelő GDPR-garanciával biztosított terület | írásbeli szerződés, titoktartás, adatbiztonsági intézkedések |
| Email / SMTP szolgáltató | rendszerüzenetek és email küldés | EGT vagy megfelelő GDPR-garanciával biztosított terület | adatfeldolgozói feltételek, hozzáférés-korlátozás |
| Biztonsági mentési szolgáltató | mentések tárolása | EGT vagy megfelelő GDPR-garanciával biztosított terület | korlátozott hozzáférés, biztonsági mentési rend |

A konkrét szolgáltatói lista az alkalmazott infrastruktúrával együtt kerül vezetésre, és érdemi változás esetén a tájékoztató frissül.

## 7. Harmadik országba történő adattovábbítás

A Mező Energy Kft. alapértelmezés szerint az Európai Gazdasági Térségen belüli adattárolásra törekszik. Harmadik országba történő adattovábbítás csak megfelelő GDPR-garanciák mellett történhet, így különösen megfelelőségi határozat, általános szerződési feltételek, kiegészítő technikai intézkedések vagy más jogszerű garancia alapján.

## 8. Megőrzési idők

A Mező Energy Kft. az adatokat csak a szükséges ideig kezeli. A fő megőrzési szabályok:

- felhasználói fiókadatok: a fiók fennállása alatt, majd törlési vagy jogi igényérvényesítési idő végéig;
- megerősítő kódok: rövid, biztonsági célhoz kötött ideig;
- ügyfél- és munkadokumentumok: az ügy lezárásáig, majd jogi, számviteli, garanciális vagy igényérvényesítési megőrzési idő végéig;
- számlázási adatok: jogszabályi megőrzési idő szerint;
- biztonsági mentések: rotációs mentési rend szerint, korlátozott hozzáféréssel;
- rendszer- és biztonsági naplók: a biztonsági célhoz szükséges ideig.

Ha a Mező Energy Kft. adatfeldolgozóként jár el, az adatkezelő felhasználó utasítása szerint törli vagy visszaadja az adatokat, kivéve ha jogszabály ettől eltérő megőrzést ír elő.

## 9. Érintetti jogok

Az érintettet az alábbi jogok illetik meg:

- hozzáférés joga;
- helyesbítés joga;
- törléshez való jog;
- adatkezelés korlátozásához való jog;
- adathordozhatósághoz való jog;
- tiltakozás joga;
- hozzájárulás visszavonása, ha az adatkezelés hozzájáruláson alapul;
- panasz benyújtása a felügyeleti hatósághoz;
- bírósági jogorvoslat.

A Mező Energy Kft. az érintetti kérelmeket indokolatlan késedelem nélkül, főszabály szerint legfeljebb egy hónapon belül megválaszolja. Összetett vagy nagy számú kérelem esetén a határidő a GDPR szerint meghosszabbítható.

Külső szerelő vagy generálkivitelező saját ügyfelének kérelme esetén a Mező Energy Kft. adatfeldolgozóként a kérelmet továbbítja az adatkezelő felhasználónak, illetve a megállapodás szerint segíti annak teljesítését.

## 10. Jogorvoslat

Felügyeleti hatóság:

Nemzeti Adatvédelmi és Információszabadság Hatóság (NAIH)
Web: https://www.naih.hu
Online ügyindítás: https://www.naih.hu/online-ugyinditas
Az aktuális postai és elektronikus elérhetőségek a NAIH honlapján érhetők el.

Az érintett bírósághoz is fordulhat. A per az érintett választása szerint az adatkezelő székhelye vagy az érintett lakóhelye/tartózkodási helye szerint illetékes törvényszék előtt is megindítható.

## 11. Biztonsági intézkedések

A Mező Energy Kft. különösen az alábbi intézkedéseket alkalmazza vagy tervezi alkalmazni:

- kötelező email-megerősítés regisztrációnál;
- erős jelszókövetelmény és jelszó hash-elés;
- szerepkör-alapú hozzáférés;
- adminisztrátori törlési és jogosultságkezelési lehetőségek;
- hozzáférések naplózása;
- adatbázis- és fájlrendszer-mentések;
- titkosított HTTPS kapcsolat;
- tárhely- és szerverhozzáférések korlátozása;
- incidenskezelési eljárás;
- jogosultságok rendszeres felülvizsgálata;
- szükségtelen adatok törlése vagy anonimizálása.

## 12. Adatvédelmi incidensek

Adatvédelmi incidens esetén a Mező Energy Kft. haladéktalanul megvizsgálja az incidenst, felméri a kockázatot, megteszi a szükséges elhárító intézkedéseket, és ha a GDPR alapján szükséges, bejelentést tesz a NAIH felé, illetve tájékoztatja az érintetteket.

Ha a Mező Energy Kft. adatfeldolgozóként jár el, az adatvédelmi incidenst indokolatlan késedelem nélkül jelzi az érintett adatkezelő felhasználónak.

## 13. Sütik és munkamenetek

A rendszer a működéshez szükséges munkamenet sütiket használhat. Ezek célja a belépett állapot fenntartása, a CSRF védelem, a biztonságos működés és a jogosultságkezelés. Analitikai vagy marketing sütik csak külön tájékoztatás és megfelelő jogalap mellett alkalmazhatók.

## 14. Automatizált döntéshozatal

A rendszer jelenlegi működése szerint nem végez olyan automatizált döntéshozatalt vagy profilalkotást, amely az érintettre joghatással járna vagy őt hasonlóan jelentős mértékben érintené.

## 15. Kiskorúak

A rendszer üzleti és ügyintézési célú használatra készült. Kiskorú felhasználó nem regisztrálhat. Amennyiben kiskorú személyes adatai kerülnek dokumentumba vagy ügyiratba, az csak megfelelő jogalap és szükségesség esetén kezelhető.

## 16. A tájékoztató módosítása

A Mező Energy Kft. jogosult a tájékoztatót módosítani. Lényeges módosítás esetén a felhasználókat a rendszerben, emailben vagy más megfelelő módon tájékoztatja. A módosítás nem érintheti visszamenőlegesen hátrányosan az érintettek jogait.

## 17. Kötelező nyilvános ellenőrzési lista

Éles nyilvános indulás előtt kötelező kitölteni és ellenőrizni:

- adatkezelő pontos cégadatai;
- adatvédelmi kapcsolattartó;
- tárhelyszolgáltató és email szolgáltató DPA;
- alvállalkozói/adatfeldolgozói lista;
- adatvédelmi incidens eljárás;
- törlési és exportálási folyamat;
- külső szerelők saját adatkezelői kötelezettségeinek elfogadtatása;
- ÁSZF és adatfeldolgozói megállapodás elfogadtatása regisztrációkor;
- regisztrációs checkboxok: ÁSZF elfogadása, adatkezelési tájékoztató megismerése, adatfeldolgozói feltételek elfogadása üzleti felhasználóknak;
- DPIA/adatvédelmi hatásvizsgálat szükségességének vizsgálata.
