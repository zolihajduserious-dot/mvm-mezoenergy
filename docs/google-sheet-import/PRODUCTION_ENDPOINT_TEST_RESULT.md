# Production endpoint teszt eredmeny

Teszt datuma: 2026-05-27

APP_URL:

```text
https://mvm-mezoenergy.hu
```

Mukodo production API URL:

```text
https://mvm-mezoenergy.hu/api/import/facebook-lead
```

## Eredmenyek

- `wrong-token`: HTTP 401, Unauthorized.
- `missing-contact`: HTTP 422, email or phone is required.
- `normal`: HTTP 201, SIKERES, customer_id `439`, work_request_id `436`.
- `duplicate`: HTTP 200, DUPLIKÁLT, customer_id `439`, work_request_id `436`.

## Fontos figyelmeztetes

A `normal` teszt letrehozott egy eles teszt customer / work request rekordot:

- customer_id `439`
- work_request_id `436`

Token ertek nem szerepel ebben a dokumentumban.

## Email megjegyzes

A production backend teszt utan javitas keszult az importalt uj ugyfel email szovegere. A kovetkezo importalt uj ugyfelnek fiokaktivalo emailt kell kapnia:

- subject: `Mező Energy ügyfélportál – fiók aktiválása`
- gomb: `Fiók aktiválása`
- tartalom: rovid, bizalomepito magyarazat a mezoenergy.hu celjarol es a merohelyi / arambovitesi / elosztoi ugyintezesi segitsegrol
- fuggetlensegi tajekoztatas: a Mezo Energy Kft. nem az MVM Csoport tagja, nem hivatalos MVM ugyfelszolgalat, es nem az MVM neveben jar el

A normal jelszo-visszaallitasi folyamat ettol kulon marad, es tovabbra is `Jelszó-visszaállítás` emailt kuld.

## Ugyfelportal adatpontositas megjegyzes

A Google Sheet import idozitett triggeret jelenleg nem hasznaljuk. A kezi admin import elott ellenorizni kell, hogy az importalt teszt ugyfel a sajat portaljan tudja menteni az adatlap alapadatait es pontositasat.

Ellenorizendo tesztadat:

- work_request_id `437`
- customer_id `440`

Ezek csak kontrollalt tesztadatkent hasznalhatok. Az admin gombos import eles hasznalata addig NO-GO, amig az ugyfeloldali mentes nem mukodik reload utan es admin oldalon is visszaellenorizheto.

## Domain megjegyzes

A `mezoenergy.hu` domain jelenleg 301 redirectet ad, ezert az Apps Scriptben most meg ne ezt hasznald API URL-kent.

A `mezoenergy.hu` vegleges domainre valtas csak akkor tortenjen meg az Apps Scriptben, ha a `mezoenergy.hu` API endpoint mar redirect nelkul ad 401-et wrong-token tesztre.

## Kovetkezo lepes

Manualis admin import beallitasa es egyetlen `IMPORTÁLANDÓ` tesztsoros proba az atmeneti production API URL-lel:

```text
https://mvm-mezoenergy.hu/api/import/facebook-lead
```
