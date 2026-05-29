# Emailt megerősített ügyfél keresése admin felületen

## Admin útvonalak

- Read-only ügyfélkereső: `/admin/customer-lookup`
- Előtöltött keresés email alapján: `/admin/customer-lookup?search=<email>`
- Részletes ügyfél CRM adatlap: `/admin/customers?customer=<customer_id>#customer-<customer_id>`
- Kapcsolódó munka közvetlen linkje, ha van: `/admin/minicrm-import?request=<request_id>#portal-work-<request_id>`

Az `/admin/customer-lookup` csak főadmin jogosultsággal nyitható meg. Nem tartalmaz törlés, export, import vagy emailküldés funkciót.

## Normál ügyfélregisztrációs flow

1. A `/register` oldal a `create_customer_account()` függvényt hívja.
2. A flow customer rekordot hoz létre vagy meglévő claimelhető customer rekordot kapcsol.
3. Ezután létrejön a `users` rekord `customer` szerepkörrel.
4. A `customers.user_id` és a `users.customer_id` összekapcsolódik.
5. A rendszer `email_verification_codes` rekordot hoz létre.
6. A `/verify-email` route sikeres kódmegadás után kitölti a `users.email_verified_at` mezőt.
7. Az admin értesítőt a `send_verified_registration_admin_notification()` küldi.

Normál ügyfélregisztrációnál nem kötelező, hogy azonnal legyen `connection_requests` rekord. Ha az ügyfél csak regisztrált és még nem adott le munkaigényt, akkor ez várt állapot.

## Read-only diagnosztikai SQL minták

Teljes email címet vagy telefonszámot ne írj riportba. A példákban csak részlet vagy maszkolt érték szerepeljen.

```sql
SELECT id, name, email, role, customer_id, email_verified_at, created_at
FROM users
WHERE email LIKE '%<email_reszlet>%'
ORDER BY created_at DESC, id DESC;
```

```sql
SELECT id, user_id, requester_name, email, phone, source, status, created_at
FROM customers
WHERE email LIKE '%<email_reszlet>%'
   OR requester_name LIKE '%<nev_reszlet>%'
   OR phone LIKE '%<telefon_reszlet>%'
ORDER BY created_at DESC, id DESC;
```

```sql
SELECT id, user_id, expires_at, used_at, attempts, created_at
FROM email_verification_codes
WHERE user_id = <user_id>
ORDER BY created_at DESC, id DESC;
```

```sql
SELECT id, customer_id, address_type, postal_code, city, address_line, created_at
FROM customer_addresses
WHERE customer_id = <customer_id>
ORDER BY created_at DESC, id DESC;
```

```sql
SELECT id, customer_id, project_name, request_status, submitted_at, created_at
FROM connection_requests
WHERE customer_id = <customer_id>
ORDER BY created_at DESC, id DESC;
```
