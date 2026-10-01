<?php
/**
 * UniGo - public landing page.
 *
 * @var array $routes  popular active routes (may be empty)
 */
declare(strict_types=1);

$routes = $routes ?? [];

$features = [
    ['icon' => 'search',     'title' => 'Search every mode',   'text' => 'Buses, taxis, boda-bodas and shared rides in one search, with live seat availability.'],
    ['icon' => 'ticket',     'title' => 'Conflict-free booking', 'text' => 'Lock a seat in seconds. Double bookings are impossible by design.'],
    ['icon' => 'navigation', 'title' => 'Live tracking',       'text' => 'Follow your vehicle on the map with ETA updates and stop-by-stop progress.'],
    ['icon' => 'package',    'title' => 'Send a parcel',       'text' => 'Move goods on the same fleet and track them end to end with a tracking number.'],
    ['icon' => 'siren',      'title' => 'One-tap SOS',         'text' => 'Alerts reach the driver and the transport authority instantly, with your location.'],
    ['icon' => 'bar-chart',  'title' => 'Operational insight', 'text' => 'Operators and regulators get revenue, safety, congestion and usage analytics.'],
];

$roles = [
    ['icon' => 'user',     'title' => 'Passenger',  'text' => 'Book, track, pay and report from one dashboard.'],
    ['icon' => 'bus',      'title' => 'Driver',     'text' => 'See today\'s trips, navigate and manage passengers.'],
    ['icon' => 'building', 'title' => 'Operator',   'text' => 'Run your fleet, routes, drivers and revenue.'],
    ['icon' => 'shield',   'title' => 'Authority',  'text' => 'Monitor the network and coordinate emergency response.'],
    ['icon' => 'settings', 'title' => 'Admin',      'text' => 'Govern accounts, roles, settings and the audit trail.'],
];
?>
<section class="section">
    <div class="hero">
        <span class="badge badge-demo">
            <i class="icon icon--xs" data-icon="flame">flame</i> Simulated data environment
        </span>
        <h1 class="text-xl mt-3" style="font-size:clamp(1.75rem,4vw,2.75rem);line-height:1.1">
            Smart transport for people and parcels &mdash; in one platform.
        </h1>
        <p class="hero__sub mt-3" style="max-width:60ch">
            <?= e(app_name()) ?> connects passengers, drivers, operators and the transport authority,
            with booking, live tracking, payments, delivery and safety built in.
        </p>

        <div class="flex gap-2 flex-wrap mt-5">
            <?php if (\App\Core\Auth::check()): ?>
                <a class="btn btn--primary btn--lg" href="<?= e(url('/home')) ?>">
                    <i class="icon" data-icon="dashboard">dashboard</i> Open dashboard
                </a>
                <a class="btn btn--light btn--lg" href="<?= e(url('/schedule')) ?>">Browse schedule</a>
            <?php else: ?>
                <a class="btn btn--primary btn--lg" href="<?= e(url('/register')) ?>">
                    <i class="icon" data-icon="user-plus">user-plus</i> Create free account
                </a>
                <a class="btn btn--light btn--lg" href="<?= e(url('/login')) ?>">Sign in</a>
            <?php endif; ?>
        </div>

        <div class="search-panel mt-6">
            <form method="get" action="<?= e(url('/schedule')) ?>">
                <div class="search-panel__grid" style="grid-template-columns:1fr auto">
                    <div class="search-field">
                        <i class="icon search-field__icon" data-icon="search">search</i>
                        <input class="input" type="search" name="q" placeholder="Where are you going? Try &ldquo;Kampala&rdquo;">
                    </div>
                    <button class="btn btn--primary" type="submit">
                        <i class="icon" data-icon="search">search</i> Find a trip
                    </button>
                </div>
            </form>
            <div class="chips">
                <?php foreach (['Kampala', 'Entebbe', 'Jinja', 'Mbarara', 'Gulu'] as $place): ?>
                    <a class="chip" href="<?= e(url('/schedule?q=' . urlencode($place))) ?>">
                        <i class="icon icon--xs" data-icon="pin">pin</i> <?= e($place) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="section__head">
        <div>
            <h2 class="section__title">Everything in one platform</h2>
            <p class="text-sm text-muted-2 mb-0">From a first search to fleet-wide analytics.</p>
        </div>
    </div>
    <div class="grid grid-3 gap-3">
        <?php foreach ($features as $feature): ?>
            <article class="card">
                <div class="card__body">
                    <span class="stat__icon mb-3"><i class="icon" data-icon="<?= e($feature['icon']) ?>"><?= e($feature['icon']) ?></i></span>
                    <h3 class="card__title"><?= e($feature['title']) ?></h3>
                    <p class="text-sm text-muted-2 mb-0"><?= e($feature['text']) ?></p>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
</section>

<section class="section">
    <div class="section__head">
        <div>
            <h2 class="section__title">Popular routes</h2>
            <p class="text-sm text-muted-2 mb-0">Active routes with scheduled trips.</p>
        </div>
        <a class="btn btn--ghost btn--sm" href="<?= e(url('/schedule')) ?>">
            View all <i class="icon icon--sm" data-icon="chevron-right">chevron-right</i>
        </a>
    </div>

    <?php if (empty($routes)): ?>
        <?= \App\Core\View::partial('partials/empty-state', [
            'icon'  => 'route',
            'title' => 'No routes published yet',
            'text'  => 'Routes will appear here once operators publish their schedules.',
            'action' => '<a class="btn btn--primary" href="' . e(url('/register')) . '">Create an account</a>',
        ]) ?>
    <?php else: ?>
        <div class="grid grid-3 gap-3">
            <?php foreach ($routes as $route): ?>
                <a class="card card--hover" style="text-decoration:none;color:inherit"
                   href="<?= e(url('/schedule?q=' . urlencode((string) ($route['route_code'] ?? '')))) ?>">
                    <div class="card__body">
                        <div class="flex items-center justify-between mb-3">
                            <span class="badge badge-primary"><?= e($route['route_code'] ?? '') ?></span>
                            <span class="text-xs text-muted-2">
                                <i class="icon icon--xs" data-icon="bus">bus</i>
                                <?= (int) ($route['upcoming_trips'] ?? 0) ?> upcoming
                            </span>
                        </div>
                        <p class="fw-700 mb-1">
                            <?= e($route['origin_name'] ?? '') ?>
                            <i class="icon icon--xs" data-icon="arrow-right">arrow-right</i>
                            <?= e($route['destination_name'] ?? '') ?>
                        </p>
                        <p class="text-sm text-muted-2 mb-3"><?= e($route['company_name'] ?? app_name()) ?></p>
                        <div class="flex items-center justify-between text-sm">
                            <span><i class="icon icon--xs" data-icon="route">route</i> <?= e((string) ($route['distance_km'] ?? '0')) ?> km</span>
                            <span><i class="icon icon--xs" data-icon="clock">clock</i> <?= e(duration_minutes((int) ($route['duration_minutes'] ?? 0))) ?></span>
                            <span class="fw-700 text-primary"><?= e(money($route['base_fare'] ?? 0)) ?></span>
                        </div>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<section class="section">
    <div class="section__head">
        <div>
            <h2 class="section__title">Built for every role</h2>
            <p class="text-sm text-muted-2 mb-0">One account, a dashboard tailored to what you do.</p>
        </div>
    </div>
    <div class="grid grid-auto-sm gap-3">
        <?php foreach ($roles as $role): ?>
            <article class="card card--flat">
                <div class="card__body">
                    <span class="stat__icon mb-2"><i class="icon" data-icon="<?= e($role['icon']) ?>"><?= e($role['icon']) ?></i></span>
                    <h3 class="card__title" style="font-size:var(--fs-base)"><?= e($role['title']) ?></h3>
                    <p class="text-sm text-muted-2 mb-0"><?= e($role['text']) ?></p>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
</section>

<section class="section">
    <div class="card" style="background:linear-gradient(140deg,#0F172A,#16305C);color:#E2E8F0;border:0">
        <div class="card__body" style="text-align:center;padding:var(--sp-8) var(--sp-5)">
            <h2 class="text-lg" style="color:#fff">Ready to move with <?= e(app_name()) ?>?</h2>
            <p class="mb-5" style="max-width:52ch;margin-inline:auto">
                Create your account and try the full passenger, driver, operator and authority experience with demo data.
            </p>
            <?php if (\App\Core\Auth::check()): ?>
                <a class="btn btn--primary btn--lg" href="<?= e(url('/home')) ?>">Go to dashboard</a>
            <?php else: ?>
                <div class="flex gap-2 flex-wrap justify-center">
                    <a class="btn btn--primary btn--lg" href="<?= e(url('/register')) ?>">Create free account</a>
                    <a class="btn btn--light btn--lg" href="<?= e(url('/login')) ?>">Sign in</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>
