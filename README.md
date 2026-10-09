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
3. Configure the database environment variables below and initialize a fresh database from the project folder:

   ```bat
   C:\xampp\php\php.exe scripts\install.php
   ```

4. Register your own account, then grant it the first administrator role locally:

   ```bat
   C:\xampp\php\php.exe scripts\admin.php --email=your-registered-email
   ```

5. Visit `http://localhost/unigo/public/`.

Apache needs `mod_rewrite` and permission to read `.htaccess`. PHP needs PDO MySQL, mbstring, ctype, and sessions. The `storage/logs` directory must be writable.

## Run without Apache

With PHP and a running MySQL/MariaDB database:

```sh
php scripts/install.php
php -S 127.0.0.1:8000 -t public scripts/router.php
```

Open `http://127.0.0.1:8000`. This is a development server. Register your own account, then run `php scripts/admin.php --email=your-registered-email` in another terminal. Sign in again to open the administrator workspace. Initial administrator creation is available only through this local CLI; additional administrators use the Users workspace.

## Configuration

`config/config.php` reads real environment variables. `.env.example` lists supported names, and a git-ignored `.env` file is loaded automatically. Variables already set in the shell or web server take precedence. Defaults are database `unigo_db`, user `root`, no password, port `3306`.

The public document root is `public/`. Keep configuration, source, database scripts, and storage outside the web document root in production.

Vehicle photos are validated by detected MIME type (JPEG, PNG or WebP) and a 2 MB limit (the `uploads` block in `config/config.php`), stored under `public/uploads/vehicles/<vehicle_id>/` with random filenames, and only rows in the `vehicle_images` table are ever displayed. `public/uploads/` is git-ignored and must be writable by the PHP user; include it in backups because the database stores paths, not the image bytes.

## Account sessions

Sign out uses a CSRF-protected POST, clears the authenticated session and remembered sign-in tokens, and returns to the homepage with confirmation. Opening `/logout` only displays a confirmation form. Protected pages require a fresh sign-in after logout or expiry; account suspension, deactivation and role changes are checked on every request. Changing a password invalidates other sessions and remembered tokens while retaining the current session.

Dynamic pages use `no-store`. Restoring a page with Back or returning to another tab rechecks session state so an old dashboard or form is refreshed. Password changes use the same minimum strength rules as registration. Profile edits validate names, phone, gender and calendar dates, and optional birth dates can be cleared.

For an existing local clone, update the files with `git pull --ff-only origin main`, then apply any migration script the update adds. The current one is:

```bat
C:\xampp\php\php.exe scripts\migrate-vehicle-images.php
```

It adds the `vehicle_images` table used by vehicle photo uploads and is safe to re-run. An update does not require reimporting the database or rerunning the demo seeder. Do not reimport `schema.sql` into a database containing data you want to keep.

## Optional test mode

Normal operation defaults to `UNIGO_DEMO_MODE=0`. The installer creates empty tables and roles, never sample accounts or trips, and preserves an existing complete database. Administrators approve operators and set support contacts; operators create fleets, drivers, routes and trips. Do not import `database/schema.sql` over existing data.

For disposable demonstration/testing databases only, explicitly set `UNIGO_DEMO_MODE=1`, select a separate database with `UNIGO_DB_NAME`, run `php scripts/install.php`, and then `php database/seed.php`. `UNIGO_ENV=production` disables test mode even if that flag is set. A database setting cannot enable it.

### Demo accounts

All seeded accounts use password `UniGo@2026`:

| Role | Email |
|---|---|
| Passenger | passenger@unigo.test |
| Admin | admin@unigo.test |
| Operator | operator@unigo.test |
| Driver | driver@unigo.test |
| Authority | authority@unigo.test |

### Test companies

`database/seed-test-companies.php` adds three small, self-contained companies - one bus, one taxi and one logistics truck - each with an operator, driver, passenger/customer and authority account, one vehicle, one route with stops, a scheduled trip and a cash booking (bus, taxi) or parcel (logistics), so every role has real data to verify:

```bat
C:\xampp\php\php.exe database\seed-test-companies.php          :: insert
C:\xampp\php\php.exe database\seed-test-companies.php --fresh  :: replace existing rows
```

It only runs with `UNIGO_DEMO_MODE=1` on a disposable database (the accounts use the reserved `@unigo.test` domain). Routes depart 10 October 2026, cash bookings start unpaid until staff record the fare, and every account shares the password `UniGo@2026`.

## Landing page and booking

The homepage shows approved travel companies from the database, active routes, and a search form for departure, destination, travel date, and company. Guests can browse available trips. Selecting a seat requires sign-in; login or registration returns the user to their selected trip. Company links show upcoming departures over the next 30 days when no date is chosen. Date searches use the selected day. Route matching respects direction and intermediate-stop order.

Operators and administrators photograph their buses and taxis from the Vehicles workspace (JPG, PNG or WebP up to 2 MB, maximum eight photos per vehicle). Trip search cards show the vehicle's cover photo, and the trip detail page shows the full gallery with the registration number, so passengers can recognise the right vehicle when it arrives.

The interface uses a navy and electric blue theme with glass and gradient styling: a gradient top navigation bar on desktop (a drawer and bottom navigation on phones), company cards, cleaner search/result panels, and layouts for smaller screens.

## Workspaces

- Passenger: registration, trip search, seat selection, segment fares, cash receipts, cancellation/refunds, tracking, parcel requests, notifications, complaints, emergencies, and ratings.
- Admin: account creation/status, operator approval, fleet/driver/route creation and status, vehicle photo upload/removal, scheduling/dispatch, bookings, parcel assignment, payment ledger, emergency and complaint handling, rating visibility, audit, reports, settings.
- Operator: own fleet and vehicle photos, drivers, routes, trips, manifests, bookings, revenue reports, company settings.
- Driver: assigned trips, passenger boarding, trip lifecycle, assigned parcels, phone GPS reporting, ratings and emergency records. Earnings displays passenger fares on assigned trips; payouts and commissions are not calculated.
- Authority: network positions, operator approvals, emergency and complaint handling, trip/revenue reports.

Staff lists support search and pagination. Mutations require a session, the matching role, ownership where applicable, and CSRF validation. Trips enforce lifecycle transitions and vehicle/driver scheduling conflicts.

## External integrations and limits

Normal operation offers cash only. Bookings start unpaid. Assigned drivers, owning operators, and administrators record cash received through the Bookings workspace, creating a receipt with the responsible staff member and updating revenue. Duplicate collection is rejected. Cancelling a paid cash booking does not claim money was returned: owning operators or administrators must confirm cash returned through the same workspace. Online payments fail before creating a receipt until a provider adapter is configured. Mock payments, wallet checkout, and GPS simulation require explicit test mode. No stored-value wallet or payout settlement is implemented.

Simulated positions are excluded from normal tracking, including the location trail. Driver phone location reports use browser geolocation and are stored as real device reports; HTTPS or localhost and location permission are required. Passenger tracking polls only trips with an owned active booking. Maps require access to Leaflet and OpenStreetMap.

Password-reset email delivery, SMS, USSD, automatic police dispatch, production payment integrations, hardware GPS, and regulatory verification require providers or operational processes. The current password-reset page explicitly directs users to support and does not claim an email was sent.

Fleet creation, status changes, and vehicle photo uploads are supported; editing seat layouts, intermediate stops, route geometry, and existing trip scheduling remains a future extension. New routes can include origin and destination coordinates in the staff form.

## Tests

For real-mode regressions, select a **disposable empty database** and run:

```sh
export UNIGO_DB_NAME=unigo_operations_tests UNIGO_DEMO_MODE=0
php scripts/install.php
php tests/operations.php
php -S 127.0.0.1:8000 -t public scripts/router.php
# In another terminal with the same environment:
python tests/operational_journey.py http://127.0.0.1:8000
```

`operations.php` rolls back its fixtures and checks staff authorization, duplicate collection, actual cash refunds, disabled mock payments, and real location filtering. The HTTP journey creates accounts and operational records; never run it on a live database.

The existing test suites require a separate **disposable seeded database** and `UNIGO_DEMO_MODE=1` in both the server and test processes:

```sh
php tests/booking.php
php tests/system.php
python tests/http_smoke.py http://127.0.0.1:8000
python tests/journey.py http://127.0.0.1:8000
python tests/authentication.py http://127.0.0.1:8000
python tests/workflows.py http://127.0.0.1:8000
```

`tests/booking.php` verifies passenger-profile creation, paid checkout, duplicate booking rejection, refund, released-seat rebooking, and invalid-seat rejection. Its changes are rolled back.

`tests/system.php` checks nested transaction rollback, seat-map ownership, fleet lifecycle conflicts, scoped dashboards and revenue, refund failures, parcel assignment, notification recipients, SOS context and tracking in large fleets. Its database changes are rolled back. `tests/workflows.py` checks parcel delivery from creation through driver handover, invalid form input, complaint and SOS creation, and access to other users' records. It removes its test customer afterward. Both require a disposable seeded database.

`tests/http_smoke.py` logs into all five roles, opens all staff workspaces, and checks passenger restrictions and JSON APIs. It requires the development server and seeded accounts. `tests/journey.py` writes an operator, fleet, driver, trip, passenger, booking, GPS report, and rating to verify a full journey and operator isolation. Run it only on a disposable database. GitHub Actions runs these checks automatically.

For the JavaScript seat-selection regression, install `jsdom` outside the repository and set `NODE_PATH` to that installation's `node_modules`, then run `node tests/seatmap.cjs`.

Also run `node tests/confirmation.cjs` and `node tests/maps.cjs` with the same `NODE_PATH`. Run `node tests/serviceworker.cjs` to check that offline caching excludes dynamic pages and uploaded files, supports a subdirectory installation, and preserves other applications' caches.

`tests/authentication.py` exercises logout, session replay, remember-token rotation, expiry, password changes across two clients, suspended/inactive accounts, changed roles, profile validation and login lockouts. It invokes PHP for fixture changes; set `UNIGO_TEST_PHP` when PHP is not on your PATH. Run it only against a disposable database.

## Layout

- `public/`: front controller, CSS, JavaScript, service worker.
- `public/uploads/`: operator-uploaded vehicle photos (git-ignored, created by the upload flow).
- `src/core/`: routing, sessions, validation, database, responses.
- `src/controllers/`, `src/models/`, `src/services/`, `src/views/`: application.
- `database/`: schema and demo seed.
- `scripts/router.php`: PHP development-server routing.
- `scripts/migrate-vehicle-images.php`: adds the `vehicle_images` table to an existing database; safe to re-run.
- `_old_prototype/`: retired prototype, not part of the current application.
