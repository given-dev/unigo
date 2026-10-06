# UniGo

PHP 8 / MySQL transport and parcel management for Uganda. No Composer build is required.

## Install for real use

Live mode is the default. It has no example accounts, invented company data, automatic payments or simulated GPS. Existing example databases are kept separate and cannot serve bookings in live mode. A clean installation creates only your administrator account and the role catalogue. Create actual companies in Admin, approve them, add vehicles/routes/drivers, then schedule trips. Passengers register with their own details.

With MySQL running, open PowerShell in your project folder:

```powershell
cd C:\Users\Given\UniGo
$securePassword = Read-Host "Choose your administrator password" -AsSecureString
$env:UNIGO_ADMIN_PASSWORD = (New-Object System.Net.NetworkCredential('', $securePassword)).Password
C:\xampp\php\php.exe scripts\install-live.php --database=unigo_live --email=YOUR_REAL_EMAIL --name=YOUR_NAME
Remove-Item Env:UNIGO_ADMIN_PASSWORD
```

Replace the email/name placeholders. The installer refuses an existing target database or `.env` file and preserves the old database. It writes a private, git-ignored `.env` with the new connection. If `unigo_live` already exists, choose a new database name. If you already have `.env`, preserve it and configure a separate installation rather than overwriting it. No public/default administrator password is created.

Keep MySQL running and launch this copy from its own folder:

```powershell
C:\xampp\php\php.exe -S 127.0.0.1:8000 -t public scripts\router.php
```

Open `http://127.0.0.1:8000`, sign in with your new administrator account, and set real support contact details and your local emergency number in Admin Settings. To make it accessible publicly, configure a PHP host with HTTPS, a restricted database user, backups and `public/` as the document root. The local PHP development server is for your laptop.

Checkout currently offers **cash**. A booking reserves the seat as unpaid; authorized company staff or the assigned driver records the fare after actually receiving it. That creates a receipt and updates collected revenue. Cancellation releases the seat, but does not claim cash was returned: staff records the cash refund after returning it. Duplicate collection/refunds and unauthorized actors are rejected. Online mobile money/card checkout, stored wallet balances, SMS, USSD and email recovery remain unavailable until those providers are connected; no successful result is fabricated.

Tracking displays only real GPS reports from the driver for the matching trip within the last five minutes. No recent report means no vehicle marker. Driver location sharing requires HTTPS or localhost and location permission. Emergency alerts enter staff workspaces; they do not automatically contact police or ambulances.

## Run with XAMPP on Windows

1. Copy or clone this repository into `C:\xampp\htdocs\unigo`.
2. Start Apache and MySQL in XAMPP.
3. Open phpMyAdmin and import `database/schema.sql`. **This schema recreates tables; use a new database, not an existing live installation.**
4. For a separate test installation only, explicitly enable demo fixtures and seed:

   ```bat
   set UNIGO_DEMO_MODE=1
   C:\xampp\php\php.exe database\seed.php
   ```

5. Visit `http://localhost/unigo/public/`.

Apache needs `mod_rewrite` and permission to read `.htaccess`. PHP needs PDO MySQL, mbstring, ctype, and sessions. The `storage/logs` directory must be writable.

## Run without Apache

With PHP and a running MySQL/MariaDB database:

```sh
UNIGO_DEMO_MODE=1 php database/seed.php
php -S 127.0.0.1:8000 -t public scripts/router.php
```

Open `http://127.0.0.1:8000`. This is a development server.

## Configuration

`config/config.php` reads the project-root `.env`; actual shell/web-server environment values take precedence. `.env.example` lists the supported names. Defaults are database `unigo_db`, user `root`, no password, port `3306`. Production always disables simulation; a database setting cannot enable it.

The public document root is `public/`. Keep configuration, source, database scripts, and storage outside the web document root in production.

## Account sessions

Sign out uses a CSRF-protected POST, clears the authenticated session and remembered sign-in tokens, and returns to the homepage with confirmation. Opening `/logout` only displays a confirmation form. Protected pages require a fresh sign-in after logout or expiry; account suspension, deactivation and role changes are checked on every request. Changing a password invalidates other sessions and remembered tokens while retaining the current session.

Dynamic pages use `no-store`. Restoring a page with Back or returning to another tab rechecks session state so an old dashboard or form is refreshed. Password changes use the same minimum strength rules as registration. Profile edits validate names, phone, gender and calendar dates, and optional birth dates can be cleared.

For an existing local clone, update the files with `git pull --ff-only origin main`. An update does not require reimporting the database or rerunning the demo seeder. Do not reimport `schema.sql` into a database containing data you want to keep.

## Demo accounts

For explicitly enabled, isolated test fixtures only. These accounts are not created by the live installer. All seeded accounts use password `UniGo@2026`:

| Role | Email |
|---|---|
| Passenger | passenger@unigo.test |
| Admin | admin@unigo.test |
| Operator | operator@unigo.test |
| Driver | driver@unigo.test |
| Authority | authority@unigo.test |

## Landing page and booking

The homepage shows approved travel companies from the database, active routes, and a search form for departure, destination, travel date, and company. Guests can browse available trips. Selecting a seat requires sign-in; login or registration returns the user to their selected trip. Company links show upcoming departures over the next 30 days when no date is chosen. Date searches use the selected day. Route matching respects direction and intermediate-stop order.

The interface uses a green and white theme, company cards, cleaner search/result panels, and layouts for smaller screens.

## Workspaces

- Passenger: registration, trip search, seat selection, segment fares, demo payments, cancellation/refunds, tracking, parcel requests, notifications, complaints, emergencies, and ratings.
- Admin: account creation/status, operator approval, fleet/driver/route creation and status, scheduling/dispatch, bookings, parcel assignment, payment ledger, emergency and complaint handling, rating visibility, audit, reports, settings.
- Operator: own fleet, drivers, routes, trips, manifests, bookings, revenue reports, company settings.
- Driver: assigned trips, passenger boarding, trip lifecycle, assigned parcels, phone GPS reporting, ratings and emergency records. Earnings displays passenger fares on assigned trips; payouts and commissions are not calculated.
- Authority: network positions, operator approvals, emergency and complaint handling, trip/revenue reports.

Staff lists support search and pagination. Mutations require a session, the matching role, ownership where applicable, and CSRF validation. Trips enforce lifecycle transitions and vehicle/driver scheduling conflicts.

## External integrations and limits

Live mode supports recorded cash collection and cash refunds. Online checkout is blocked until a verified provider adapter and credentials are connected. A mock gateway is available only in explicitly enabled test mode. No stored-value wallet or payout settlement is implemented.

Live maps exclude simulated and stale GPS records. Driver phone location reports use browser geolocation; HTTPS or localhost and location permission are required. Passenger tracking polls only trips with an owned active booking and shows only that trip's fresh reports. Maps require access to Leaflet and OpenStreetMap.

Password-reset email delivery, SMS, USSD, automatic police dispatch, production payment integrations, hardware GPS, and regulatory verification require providers or operational processes. The current password-reset page explicitly directs users to support and does not claim an email was sent.

Fleet/route creation and status changes are supported; editing seat layouts, intermediate stops, route geometry, and existing trip scheduling remains a future extension. New routes can include origin and destination coordinates in the staff form.

## Tests

Use a **disposable seeded database** for backend tests:

```sh
export UNIGO_DEMO_MODE=1
php tests/booking.php
php tests/live.php
python tests/http_smoke.py http://127.0.0.1:8000
python tests/journey.py http://127.0.0.1:8000
python tests/authentication.py http://127.0.0.1:8000
python tests/install_live.py
```

`tests/booking.php` verifies passenger-profile creation, paid checkout, duplicate booking rejection, refund, released-seat rebooking, and invalid-seat rejection. Its changes are rolled back.

`tests/http_smoke.py` logs into all five roles, opens all staff workspaces, and checks passenger restrictions and JSON APIs. It requires the development server and seeded accounts. `tests/journey.py` writes an operator, fleet, driver, trip, passenger, booking, GPS report, and rating to verify a full journey and operator isolation. Run it only on a disposable database. GitHub Actions runs these checks automatically.

For the JavaScript seat-selection regression, install `jsdom` outside the repository and set `NODE_PATH` to that installation's `node_modules`, then run `node tests/seatmap.cjs`.

`tests/authentication.py` exercises logout, session replay, remember-token rotation, expiry, password changes across two clients, suspended/inactive accounts, changed roles, profile validation and login lockouts. It invokes PHP for fixture changes; set `UNIGO_TEST_PHP` when PHP is not on your PATH. Run it only against a disposable database.

## Layout

- `public/`: front controller, CSS, JavaScript, service worker.
- `src/core/`: routing, sessions, validation, database, responses.
- `src/controllers/`, `src/models/`, `src/services/`, `src/views/`: application.
- `database/`: schema and demo seed.
- `scripts/router.php`: PHP development-server routing.
- `_old_prototype/`: retired prototype, not part of the current application.
