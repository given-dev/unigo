# UniGo

PHP 8 / MySQL transport and parcel management for Uganda. No Composer build is required.

## Run with XAMPP on Windows

1. Copy or clone this repository into `C:\xampp\htdocs\unigo`.
2. Start Apache and MySQL in XAMPP.
3. Open phpMyAdmin and import `database/schema.sql`. **This schema recreates tables; use a new database, not an existing live installation.**
4. Open a terminal in the project folder and seed the demo accounts:

   ```bat
   C:\xampp\php\php.exe database\seed.php
   ```

5. Visit `http://localhost/unigo/public/`.

Apache needs `mod_rewrite` and permission to read `.htaccess`. PHP needs PDO MySQL, mbstring, ctype, and sessions. The `storage/logs` directory must be writable.

## Run without Apache

With PHP and a running MySQL/MariaDB database:

```sh
php database/seed.php
php -S 127.0.0.1:8000 -t public scripts/router.php
```

Open `http://127.0.0.1:8000`. This is a development server.

## Configuration

`config/config.php` reads real environment variables. `.env.example` lists supported names, but copying it to `.env` does not load variables automatically. Configure them in your shell or web server. Defaults are database `unigo_db`, user `root`, no password, port `3306`.

The public document root is `public/`. Keep configuration, source, database scripts, and storage outside the web document root in production.

## Demo accounts

All seeded accounts use password `UniGo@2026`:

| Role | Email |
|---|---|
| Passenger | passenger@unigo.test |
| Admin | admin@unigo.test |
| Operator | operator@unigo.test |
| Driver | driver@unigo.test |
| Authority | authority@unigo.test |

## Workspaces

- Passenger: registration, trip search, seat selection, segment fares, demo payments, cancellation/refunds, tracking, parcel requests, notifications, complaints, emergencies, and ratings.
- Admin: account creation/status, operator approval, fleet/driver/route creation and status, scheduling/dispatch, bookings, parcel assignment, payment ledger, emergency and complaint handling, rating visibility, audit, reports, settings.
- Operator: own fleet, drivers, routes, trips, manifests, bookings, revenue reports, company settings.
- Driver: assigned trips, passenger boarding, trip lifecycle, assigned parcels, phone GPS reporting, ratings and emergency records. Earnings displays passenger fares on assigned trips; payouts and commissions are not calculated.
- Authority: network positions, operator approvals, emergency and complaint handling, trip/revenue reports.

Staff lists support search and pagination. Mutations require a session, the matching role, ownership where applicable, and CSRF validation. Trips enforce lifecycle transitions and vehicle/driver scheduling conflicts.

## External integrations and limits

Payments, including wallet checkout, use a **mock gateway** and move no real money. Replace `PaymentGatewayInterface`'s mock adapter with a payment-provider adapter and credentials before enabling real checkout. No stored-value wallet or payout settlement is implemented.

Seeded GPS is simulated. Driver phone location reports use browser geolocation and are stored as real device reports; HTTPS or localhost and location permission are required. Passenger tracking polls only trips with an owned active booking. Maps require access to Leaflet and OpenStreetMap.

Password-reset email delivery, SMS, USSD, automatic police dispatch, production payment integrations, hardware GPS, and regulatory verification require providers or operational processes. The current password-reset page explicitly directs users to support and does not claim an email was sent.

Fleet/route creation and status changes are supported; editing seat layouts, intermediate stops, route geometry, and existing trip scheduling remains a future extension. New routes can include origin and destination coordinates in the staff form.

## Tests

Use a **disposable seeded database** for backend tests:

```sh
php tests/booking.php
python tests/http_smoke.py http://127.0.0.1:8000
python tests/journey.py http://127.0.0.1:8000
```

`tests/booking.php` verifies passenger-profile creation, paid checkout, duplicate booking rejection, refund, released-seat rebooking, and invalid-seat rejection. Its changes are rolled back.

`tests/http_smoke.py` logs into all five roles, opens all staff workspaces, and checks passenger restrictions and JSON APIs. It requires the development server and seeded accounts. `tests/journey.py` writes an operator, fleet, driver, trip, passenger, booking, GPS report, and rating to verify a full journey and operator isolation. Run it only on a disposable database. GitHub Actions runs these checks automatically.

For the JavaScript seat-selection regression, install `jsdom` outside the repository and set `NODE_PATH` to that installation's `node_modules`, then run `node tests/seatmap.cjs`.

## Layout

- `public/`: front controller, CSS, JavaScript, service worker.
- `src/core/`: routing, sessions, validation, database, responses.
- `src/controllers/`, `src/models/`, `src/services/`, `src/views/`: application.
- `database/`: schema and demo seed.
- `scripts/router.php`: PHP development-server routing.
- `_old_prototype/`: retired prototype, not part of the current application.
