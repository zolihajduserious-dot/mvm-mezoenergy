# Mező Energy Kft. CRM + árajánlatkészítő

PHP 8.1 + MySQL alapú ügyfélintegrációs és árajánlatkészítő rendszer.

## Fő funkciók

- Ügyfél regisztráció és ügyféladatlap.
- Ügyféloldali mérőhelyi igény fájlfeltöltéssel.
- Admin/szakember ügyfélkezelés.
- Helyszíni felmérési adatok rögzítése.
- Admin árlista és ajánlati tételek.
- Ajánlat készítése, PDF generálása és SMTP email küldése.
- Helyszíni fotók feltöltése nem publikus tárhelyre.
- Csatlakozási igények emailes továbbítása a beállított címre.
- MiniCRM XLSX export a `MiniCRM_Import_Minta_Normal.xlsx` fejlece szerint.

## Composer függések

A PDF, email és XLSX funkciókhoz:

```bash
composer install
```

Majd a `vendor` mappát is fel kell tölteni a tárhelyre a `public_html` mappa mellé.

Ha nincs `vendor` mappa:

```text
PDF generálás: nem működik
SMTP email küldés: nem működik
MiniCRM XLSX export: nem működik, de CSV fallback készül
```

Használt csomagok:

```text
dompdf/dompdf
phpmailer/phpmailer
phpoffice/phpspreadsheet
```

## Adatbázis

phpMyAdminban válaszd ki a `mezoenergy24` adatbázist, majd futtasd a teljes:

```text
database/schema.sql
```

fájl tartalmát.

A fájl nem tartalmaz `CREATE DATABASE` vagy `USE` parancsot.

Az árajánlat-készítő aktuális bruttó árlistájához utána futtasd ezt is:

```text
database/quote_price_items_catalog.sql
```

Ez idempotens frissítő: a régi aktív árlista tételeit inaktiválja, az új katalógust pedig beszúrja vagy frissíti.

Az ügyféloldali ajánlat-elfogadás és egyeztetés kérése funkcióhoz futtasd:

```text
database/quote_response_actions.sql
```

Az ügyféloldali igénypiszkozatokhoz, utólagos dokumentumfeltöltéshez és lezáráshoz futtasd:

```text
database/connection_request_drafts.sql
```

Az igénytípus legördülőhöz és a H tarifa kötelező mellékleteihez futtasd:

```text
database/connection_request_types.sql
```

Az elfelejtett jelszó és emailes jelszó-visszaállítás funkcióhoz futtasd:

```text
database/password_reset_tokens.sql
```

A MiniCRM-ből exportált, aktív munkaállomány Excel importjához futtasd:

```text
database/minicrm_import.sql
```

A szerelői fiókokhoz, munkakiadáshoz és kötelező kivitelezési fotókhoz futtasd:

```text
database/electrician_workflow.sql
```

## Tárhely beállítások

Az adatbázis és SMTP beállítások:

```text
public_html/includes/config.php
```

Aktuális adatbázis:

```text
DB_HOST=mysql.nethely.hu
DB_NAME=mezoenergy24
DB_USER=mezoenergy24
DB_PORT=3306
```

SMTP-hez ezeket kell beallitani:

```text
SMTP_HOST
SMTP_PORT
SMTP_USER
SMTP_PASS
SMTP_SECURE
MAIL_FROM
MAIL_FROM_NAME
CONNECTION_REQUEST_EMAIL
```

## Fontos mappak

```text
public_html
storage/uploads/quotes
storage/uploads/electrician-work
storage/pdf
storage/exports
vendor
```

A `storage` es `vendor` mappa a `public_html` mellett legyen, ne a publikus webgyokerben. Ha a `storage` megis publikus helyre kerul, a benne levo `.htaccess` tiltja a kozvetlen elerest Apache alatt.

## Fo URL-ek

```text
/register
/customer/profile
/customer/work-requests
/customer/work-request
/customer/quotes
/admin/login
/admin/dashboard
/admin/customers
/admin/connection-requests
/admin/electricians
/admin/quotes
/admin/price-items
/admin/minicrm-export
/admin/minicrm-import
/electrician/register
/electrician/login
/electrician/work-requests
/electrician/work-request
```

Ha a tiszta URL nem működik, használható a fallback forma:

```text
/index.php?route=admin/dashboard
```
