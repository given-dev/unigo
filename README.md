# UniGo

PHP 8 / MySQL transport and parcel management for Uganda. A single codebase
serves passengers, drivers, transport companies, regulators and platform
administrators. There is no Composer build step and no external runtime
dependency beyond PHP and MySQL; the application uses a small built-in
autoloader (`src/bootstrap.php`).

- **Passengers** search trips, pick a seat, pay (cash in this build), track the
  bus and manage bookings, parcels, complaints and emergencies.
- **Companies (operators)** run fleets, drivers, routes and trips, record cash
  fares on the manifest, and review revenue.
- **Drivers** run their assigned trips, board passengers and share live GPS.
- **Authorities** monitor the network, traffic reports, emergencies and
  compliance.
- **Administrators** approve operators, manage accounts, settings and audit.

The default (live) mode ships no sample accounts or data. A separate, explicit
test mode adds a small disposable demo dataset.

---

## Contents

- [Requirements](#requirements)
- [Install for real use](#install-for-real-use)
- [Run with XAMPP on Windows](#run-with-xampp-on-windows)
- [Run without Apache](#run-without-apache)
- [Test (demo) mode](#test-demo-mode)
- [Demo accounts](#demo-accounts)
- [Seeding the demo data](#seeding-the-demo-data)
- [Configuration](#configuration)
- [Booking, cash and refunds](#booking-cash-and-refunds)
- [Tracking](#tracking)
- [Vehicle photos](#vehicle-photos)
- [Workspaces and routes](#workspaces-and-routes)
- [Account sessions and security](#account-sessions-and-security)
- [Project layout](#project-layout)
- [Tests](#tests)
- [Troubleshooting](#troubleshooting)

---

## Requirements

- **PHP 8.0+** with the `pdo_mysql`, `mbstring`, `ctype` and `session`
  extensions. The bundled `start.bat` assumes `C:\xampp\php\php.exe`.
- **MySQL 5.7+ / MariaDB 10.4+**.
- **Apache** with `mod_rewrite` and `.htaccess` enabled (XAMPP ships this), or
  PHP's built-in development server.
- Writable **`storage/logs/`**. Writable **`public/uploads/`** if you upload
  vehicle photos.

The documents root must be `public/`; configuration, source, database scripts
and storage live outside it.

---

## Install for real use

Live mode is the default. It has no example accounts, invented company data,
automatic payments or simulated GPS. A clean installation creates only your
administrator account and the role catalogue. Create actual companies in Admin,
approve them, add vehicles/routes/drivers, then schedule trips. Passengers
register with their own details.

With MySQL running, open PowerShell in your project folder:

```powershell
cd C:\xampp\htdocs\unigo
$securePassword = Read-Host "Choose your administrator password" -AsSecureString
$env:UNIGO_ADMIN_PASSWORD = (New-Object System.Net.NetworkCredential('', $securePassword)).Password
C:\xampp\php\php.exe scripts\install-live.php --database=unigo_live --email=YOUR_REAL_EMAIL --name=YOUR_NAME
Remove-Item Env:UNIGO_ADMIN_PASSWORD
```

Replace the email/name placeholders. The installer refuses an existing target
database or `.env` file and preserves the old database. It writes a private,
git-ignored `.env` with the new connection. If `unigo_live` already exists,
choose a new database name. If you already have `.env`, preserve it and
configure a separate installation rather than overwriting it. No public/default
administrator password is created.

Keep MySQL running and launch this copy from its own folder:

```powershell
C:\xampp\php\php.exe -S 127.0.0.1:8000 -t public scripts\router.php
```

Open `http://127.0.0.1:8000`, sign in with your new administrator account, and
set real support contact details and your local emergency number in Admin
Settings. To expose it publicly, use a PHP host with HTTPS, a restricted
database user, automated backups and `public/` as the document root. The local
PHP development server is for your laptop only.

---

## Run with XAMPP on Windows

1. Copy or clone this repository into `C:\xampp\htdocs\unigo`.
2. Start **Apache** and **MySQL** in the XAMPP control panel.
3. Initialize an empty database from the project folder:

   ```bat
   C:\xampp\php\php.exe scripts\install.php
   ```

   `scripts\install.php` creates the tables and the role catalogue in the
   database named by `UNIGO_DB_NAME` (default `unigo_db`). It never imports
   sample accounts, and it refuses to touch a database that already holds data.

4. Register your own account in the browser, then grant it the first
   administrator role from the command line:

   ```bat
   C:\xampp\php\php.exe scripts\admin.php --email=your-registered-email
   ```

   The very first administrator can only be created this way; further
   administrators are added through the Admin → Users workspace.

5. Visit `http://localhost/unigo/public/`.

`start.bat` is a convenience wrapper that runs the PHP development server on
`http://127.0.0.1:8000` without Apache.

---

## Run without Apache

With PHP and a running MySQL/MariaDB database:

```sh
php scripts/install.php
php -S 127.0.0.1:8000 -t public scripts/router.php
```

Open `http://127.0.0.1:8000`. This is a development server. Register your own
account, then run `php scripts/admin.php --email=your-registered-email` in
another terminal. Sign in again to open the administrator workspace.

---

## Test (demo) mode

Normal operation defaults to `UNIGO_DEMO_MODE=0`. For a **disposable**
demonstration/testing database only, explicitly enable test mode against a
separate database:

```bat
set UNIGO_DEMO_MODE=1
set UNIGO_DB_NAME=unigo_demo
C:\xampp\php\php.exe scripts\install.php
C:\xampp\php\php.exe database\seed-test-companies.php
C:\xampp\php\php.exe database\seed-vehicle-images.php
```

In test mode the interface shows a persistent **SIMULATED DATA** ribbon so
simulated GPS traces, mock payments and demo accounts are never mistaken for
real data. `UNIGO_ENV=production` disables test mode even if the flag is set,
and a database setting cannot switch it on.

> Test mode refuses to run against most production-looking setups: the seeders
> use the reserved `@unigo.test` email domain and exit unless
> `UNIGO_DEMO_MODE=1`.

If this demo database is later opened with `UNIGO_DEMO_MODE=0`, `bootstrap.php`
detects the example `admin@unigo.test` account and shows a "finish setting up"
notice instead of serving bookings. Use a dedicated database for live use.

---

## Demo accounts

Every seeded account shares the password **`UniGo@2026`**. When test mode is on,
the sign-in page lists these accounts and fills the form when tapped.

| Role | Email | Belongs to |
|---|---|---|
| Administrator | `admin@unigo.test` | Platform administration |
| Operator | `bus.operator@unigo.test` | Pearl Test Bus Services (bus) |
| Driver | `bus.driver@unigo.test` | Pearl Test Bus Services |
| Passenger | `bus.passenger@unigo.test` | Pearl Test Bus Services |
| Operator | `taxi.operator@unigo.test` | City Test Taxi Services (taxi) |
| Driver | `taxi.driver@unigo.test` | City Test Taxi Services |
| Passenger | `taxi.passenger@unigo.test` | City Test Taxi Services |
| Operator | `logistics.operator@unigo.test` | Swift Test Logistics (truck) |
| Driver | `logistics.driver@unigo.test` | Swift Test Logistics |
| Customer | `logistics.customer@unigo.test` | Swift Test Logistics |
| Authority (national) | `mowt.authority@unigo.test` | Ministry of Works and Transport |
| Authority | `kampala.authority@unigo.test` | Kampala Capital City Authority |
| Authority | `jinja.authority@unigo.test` | Jinja District Transport Officer |
| Authority | `mukono.authority@unigo.test` | Mukono District Transport Officer |

That is the complete login set for this demo database — 14 accounts and nothing
else. The national authority officer sees the whole network; the district
officers are scoped to routes that start or end in their district.

---

## Seeding the demo data

### `database/seed-test-companies.php` — the small, focused dataset

Creates one administrator and three self-contained companies (bus, taxi and
logistics), each with an operator, driver, passenger/customer, one vehicle and
seat map, one route with origin/destination stops, a scheduled trip, and the
record that proves the flow (a paid mobile-money booking for the bus, a cash
booking for the taxi, a parcel for logistics). It also creates the four
regulator officers. Trips are timed relative to *now*, so the bus is mid-route
while the bookable taxi and logistics trips depart a day or two ahead, keeping
the seat-picker demo working long after seeding.

```bat
C:\xampp\php\php.exe database\seed-test-companies.php          :: insert (refuses if present)
C:\xampp\php\php.exe database\seed-test-companies.php --fresh  :: purge these rows, then insert
```

### `database/seed-vehicle-images.php` — vehicle photos

Downloads real, freely-licensed photographs from Wikimedia Commons (CC BY-SA)
for the three test vehicles — one exterior and one interior each — into
`public/uploads/vehicles/<vehicle_id>/`, and records them in `vehicle_images`.
The attribution is stored on each row's caption. If a download fails (for
example, offline), the seeder falls back to a flat SVG illustration so the demo
still renders. Run it after the test-companies seeder.

```bat
C:\xampp\php\php.exe database\seed-vehicle-images.php          :: add (skips existing)
C:\xampp\php\php.exe database\seed-vehicle-images.php --fresh  :: replace, then add
```

Because the photos are CC BY-SA, keep the attribution (in the captions, or in
your own credits) if you reuse them anywhere public. To use your own photos,
drop them into `public/uploads/vehicles/<vehicle_id>/` and update the rows, or
change the URLs in `photo_sources()` and re-run with `--fresh`.

### `database/seed.php` — the larger dataset (optional)

`database/seed.php --fresh` seeds a much bigger Kampala-focused dataset (many
operators, drivers, passengers, vehicles, routes, trips, bookings, payments and
simulated GPS). Use it *instead of* the focused seeder on a given database, not
both, since they create overlapping accounts.

---

## Configuration

`config/config.php` reads real environment variables. `.env.example` lists the
supported names, and a git-ignored `.env` file in the project root is loaded
automatically. Variables already set in the shell or web server take
precedence.

| Variable | Default | Purpose |
|---|---|---|
| `UNIGO_ENV` | `local` | `local`, `staging` or `production`. `production` disables test mode. |
| `UNIGO_DEMO_MODE` | `0` | `1` enables the SIMULATED DATA ribbon and demo seeding. |
| `UNIGO_DEBUG` | `0` | `1` shows detailed errors. |
| `UNIGO_BASE_URL` | *(empty)* | Absolute/relative URL to the front controller, e.g. `http://localhost/unigo/public`. |
| `UNIGO_DB_HOST` | `127.0.0.1` | Database host. |
| `UNIGO_DB_PORT` | `3306` | Database port. |
| `UNIGO_DB_NAME` | `unigo_db` | Database name. |
| `UNIGO_DB_USER` | `root` | Database user. |
| `UNIGO_DB_PASS` | *(empty)* | Database password. |
| `UNIGO_HTTPS` | `0` | `1` marks the session cookie `Secure` (requires HTTPS). |
| `UNIGO_SUPPORT_PHONE` | *(empty)* | Shown on the help screen. |
| `UNIGO_SUPPORT_EMAIL` | *(empty)* | Shown on the help screen. |
| `UNIGO_EMERGENCY_HOTLINE` | *(empty)* | Emergency number shown to users. |
| `UNIGO_ADMIN_PASSWORD` | — | Read only by `scripts/install-live.php` to create the first admin. |

Other values (timezone `Africa/Kampala`, currency `UGX`, upload limits, login
lockout rules, fare fallbacks) live in `config/config.php`. Support contacts
and the emergency number can also be set at runtime in Admin Settings.

---

## Booking, cash and refunds

Checkout currently offers **cash**. A booking reserves the seat as unpaid;
authorized company staff or the assigned driver records the fare after actually
receiving it. That creates a receipt and updates collected revenue. Cancelling
releases the seat but does not claim cash was returned: staff records the cash
refund after returning it. Duplicate collection/refunds and unauthorized actors
are rejected. Online mobile-money/card checkout, stored wallet balances, SMS,
USSD and email recovery remain unavailable until those providers are connected;
no successful result is fabricated.

On the operator/driver manifest, the fare can be recorded once, and the payment
status badge shows `Paid` or `Cash` (unpaid). Collecting cash on a simulated
booking is only allowed while test mode is on.

---

## Tracking

Tracking displays only real GPS reports from the driver for the matching trip
within the last five minutes. No recent report means no vehicle marker. Driver
location sharing requires HTTPS or localhost and browser location permission.
Emergency alerts enter staff workspaces; they do not automatically contact
police or ambulances. Simulated positions are excluded from normal tracking,
including the location trail. Maps require access to Leaflet and OpenStreetMap.

---

## Vehicle photos

Vehicle photos are validated by detected MIME type (JPEG, PNG or WebP) and a
2 MB limit (the `uploads` block in `config/config.php`), stored under
`public/uploads/vehicles/<vehicle_id>/` with random filenames, and only rows in
the `vehicle_images` table are ever displayed. Operators and administrators
manage them from the Vehicles workspace (maximum eight photos per vehicle).
Trip search cards show the vehicle's cover photo and the trip detail page shows
the full gallery with the registration number.

`public/uploads/` is git-ignored and must be writable by the PHP user; include
it in backups because the database stores paths, not the image bytes.

---

## Workspaces and routes

Every staff navigation link resolves to a real page. Full CRUD is added role by
role; the shared workspaces are:

| Workspace | Example pages |
|---|---|
| Passenger | `/home`, `/trips/search`, `/bookings`, `/tracking`, `/payments`, `/deliveries`, `/complaints`, `/notifications`, `/profile` |
| Driver | `/driver/dashboard`, `/driver/trips`, `/driver/bookings`, `/driver/tracking`, `/driver/deliveries`, `/driver/earnings`, `/driver/ratings`, `/driver/sos` |
| Operator | `/operator/trips`, `/operator/tracking`, `/operator/vehicles`, `/operator/drivers`, `/operator/routes`, `/operator/bookings`, `/operator/revenue`, `/operator/reports`, `/operator/settings` |
| Authority | `/authority/emergencies`, `/authority/complaints`, `/authority/operators`, `/authority/traffic`, `/authority/reports`, `/authority/analytics` |
| Admin | `/admin/dashboard`, `/admin/users`, `/admin/operators`, `/admin/vehicles`, `/admin/drivers`, `/admin/routes`, `/admin/trips`, `/admin/bookings`, `/admin/deliveries`, `/admin/payments`, `/admin/emergencies`, `/admin/complaints`, `/admin/ratings`, `/admin/reports`, `/admin/audit`, `/admin/settings` |

Staff lists support search and pagination. Mutations require a session, the
matching role, ownership where applicable, and CSRF validation. Trips enforce
lifecycle transitions and vehicle/driver scheduling conflicts. The public site
(`/`, `/about`, `/support`, `/contact`, `/schedule`, `/privacy`, `/terms`) and
the auth pages (`/login`, `/register`, `/forgot-password`, `/logout`) are open to
guests; selecting a seat requires sign-in.

The landing page shows approved travel companies (with a vehicle cover photo),
active routes and a search form. Company links show upcoming departures over the
next 30 days when no date is chosen; date searches use the selected day. Route
matching respects direction and intermediate-stop order.

---

## Account sessions and security

Sign out uses a CSRF-protected POST, clears the authenticated session and
remembered sign-in tokens, and returns to the homepage with confirmation.
Opening `/logout` only displays a confirmation form. Protected pages require a
fresh sign-in after logout or expiry; account suspension, deactivation and role
changes are checked on every request. Changing a password invalidates other
sessions and remembered tokens while retaining the current session.

Dynamic pages use `no-store`. Restoring a page with Back or returning to another
tab rechecks session state so an old dashboard or form is refreshed. Password
changes use the same minimum strength rules as registration (at least 8
characters, mixed case). Logins are rate-limited per account and per IP; profile
edits validate names, phone, gender and calendar dates.

---

## Project layout

- `public/` — front controller (`index.php`), JSON API endpoints, CSS,
  JavaScript and the service worker. This is the document root.
- `public/uploads/` — uploaded vehicle photos (git-ignored, created on demand).
- `src/core/` — routing, requests, sessions, database, config, responses,
  validation, error handling.
- `src/controllers/`, `src/models/`, `src/services/`, `src/views/` — the
  application (staff workspace shell, passenger flows, tracking, payments).
- `src/helpers/functions.php` — global helpers (`e()`, `money()`,
  `is_demo_mode()`, …).
- `src/bootstrap.php` — constants, autoloader, config, session and services.
- `config/config.php` — all environment-specific settings.
- `database/` — `schema.sql` plus the seeders described above.
- `scripts/` — `install.php`, `install-live.php`, `admin.php`,
  `migrate-vehicle-images.php`, and `router.php` for the dev server.
- `storage/logs/` — application logs (must be writable).
- `tests/` — PHP and Python regression suites.

### Updating an existing clone

Update the files, then apply any migration the update adds. The current one is:

```bat
C:\xampp\php\php.exe scripts\migrate-vehicle-images.php
C:\xampp\php\php.exe scripts\migrate-authorities.php
```

These scripts add the `vehicle_images` table for vehicle photos and the `authorities` table for national and district officer profiles. Both are safe to re-run and preserve existing records. An update does not require reimporting the database or rerunning the demo seeder. Do not reimport `schema.sql` into a database containing data you want to keep.

---

## Tests

For **real-mode** regressions, select a disposable **empty** database and run:

```sh
export UNIGO_DB_NAME=unigo_operations_tests UNIGO_DEMO_MODE=0
php scripts/install.php
php tests/operations.php
php -S 127.0.0.1:8000 -t public scripts/router.php
# In another terminal with the same environment:
python tests/operational_journey.py http://127.0.0.1:8000
```

`tests/operations.php` rolls back its fixtures and checks staff authorization,
duplicate collection, actual cash refunds, disabled mock payments and real
location filtering. The HTTP journey creates accounts and operational records;
never run it on a live database.

The remaining suites require a separate **disposable seeded** database with
`UNIGO_DEMO_MODE=1` in both the server and test processes:

```sh
php tests/booking.php
php tests/system.php
python tests/http_smoke.py http://127.0.0.1:8000
python tests/journey.py http://127.0.0.1:8000
python tests/authentication.py http://127.0.0.1:8000
python tests/workflows.py http://127.0.0.1:8000
php database/seed-test-companies.php
python tests/authority.py http://127.0.0.1:8000
```

- `tests/booking.php` — passenger-profile creation, paid checkout, duplicate
  booking rejection, refund, released-seat rebooking, invalid-seat rejection
  (changes rolled back).
- `tests/system.php` — nested transaction rollback, seat-map ownership, fleet
  lifecycle conflicts, scoped dashboards/revenue, refund failures, parcel
  assignment, notification recipients, SOS context and large-fleet tracking
  (changes rolled back).
- `tests/http_smoke.py` — logs into all five roles, opens every staff workspace,
  checks passenger restrictions and JSON APIs.
- `tests/journey.py` — writes an operator, fleet, driver, trip, passenger,
  booking, GPS report and rating to verify a full journey and operator
  isolation; removes its fixtures.
- `tests/authentication.py` — logout, session replay, remember-token rotation,
  expiry, password changes across two clients, suspended/inactive accounts,
  changed roles, profile validation and login lockouts.
- `tests/workflows.py` — parcel delivery through driver handover, invalid input,
  complaint and SOS creation, and cross-user access checks.

For the JavaScript regressions, install `jsdom` outside the repository and set
`NODE_PATH` to its `node_modules`, then run:

```sh
node tests/seatmap.cjs
node tests/confirmation.cjs
node tests/maps.cjs
node tests/serviceworker.cjs
```

GitHub Actions runs these checks automatically.

---

## Troubleshooting

- **404 on every page under Apache** — `mod_rewrite` is off, or Apache is not
  allowed to read `.htaccess`. Confirm `AllowOverride All` for the site and that
  the document root is `public/`.
- **"Database already initialized"** — `scripts/install.php` refuses to change
  an existing database. Point `UNIGO_DB_NAME` at a new name, or use the seeder
  scripts for demo data.
- **Demo accounts missing** — the seeders only run with `UNIGO_DEMO_MODE=1`.
  Re-run `database/seed-test-companies.php --fresh`.
- **Vehicle photos missing** — run `database/seed-vehicle-images.php`; for
  real uploads, confirm `public/uploads/` is writable.
- **Empty trip search** — the search only lists trips whose departure time is
  in the future. Re-run the test-companies seeder to make the demo trips current.
- **"Finish setting up UniGo" notice** — an example `admin@unigo.test` account
  exists while `UNIGO_DEMO_MODE=0`. Use a separate live database.

## External integrations and limits

Password-reset email delivery, SMS, USSD, automatic police dispatch, production
payment integrations, hardware GPS and regulatory verification require providers
or operational processes. The password-reset page explicitly directs users to
support and does not claim an email was sent. Fleet creation, status changes and
vehicle photo uploads are supported; editing seat layouts, intermediate stops,
route geometry and existing trip scheduling remain future extensions. New routes
can include origin and destination coordinates in the staff form.
