<?php
/**
 * UniGo - shared staff dashboard overview (driver, operator, authority, admin).
 *
 * @var string $role
 * @var array  $headline
 */
declare(strict_types=1);

use App\Core\Auth;
use App\Core\View;

$role     = $role ?? 'operator';
$headline = $headline ?? [];

$titles = [
    'driver'    => ['Driver dashboard', 'Your trips and activity on the network.'],
    'operator'  => ['Operator dashboard', 'Fleet, drivers, bookings and revenue at a glance.'],
    'authority' => ['Authority dashboard', 'Network health, safety and compliance oversight.'],
    'admin'     => ['Administrator dashboard', 'Platform-wide accounts, activity and system health.'],
];

$stats = [
    'driver' => [
        ['Active trips',     $headline['active_trips'] ?? 0,    'bus',      'primary', '/driver/trips'],
        ['Total trips',      $headline['total_trips'] ?? 0,     'route',    'info',    '/driver/trips'],
        ['Today bookings',   $headline['today_bookings'] ?? 0,  'ticket',   'warning', '/driver/trips'],
        ['Active routes',    $headline['active_routes'] ?? 0,   'navigation', 'success', '/driver/tracking'],
    ],
    'operator' => [
        ['Active vehicles',  $headline['active_vehicles'] ?? 0, 'car',      'primary', '/operator/vehicles'],
        ['Drivers',          $headline['total_drivers'] ?? 0,   'users',    'info',    '/operator/drivers'],
        ['Today bookings',   $headline['today_bookings'] ?? 0,  'ticket',   'warning', '/operator/bookings'],
        ['Today revenue',    money($headline['today_revenue'] ?? 0), 'coins', 'success', '/operator/revenue'],
    ],
    'authority' => [
        ['Active trips',     $headline['active_trips'] ?? 0,      'bus',     'primary', '/authority/monitor'],
        ['Open emergencies', $headline['open_emergencies'] ?? 0,  'siren',   'danger',  '/authority/emergencies'],
        ['Open complaints',  $headline['open_complaints'] ?? 0,   'message', 'warning', '/authority/complaints'],
        ['Active routes',    $headline['active_routes'] ?? 0,     'route',   'info',    '/authority/operators'],
    ],
    'admin' => [
        ['Total users',      $headline['total_users'] ?? 0,       'users',   'primary', '/admin/users'],
        ['Active trips',     $headline['active_trips'] ?? 0,      'bus',     'info',    '/admin/trips'],
        ['Today bookings',   $headline['today_bookings'] ?? 0,    'ticket',  'warning', '/admin/bookings'],
        ['Today revenue',    money($headline['today_revenue'] ?? 0), 'banknote', 'success', '/admin/payments'],
    ],
];

$links = [
    'driver' => [
        ['/driver/dashboard', 'gauge',      'Dashboard'],
        ['/driver/trips',     'bus',        'My trips'],
        ['/driver/tracking',  'navigation', 'Live tracking'],
        ['/driver/earnings',  'coins',      'Earnings'],
    ],
    'operator' => [
        ['/operator/trips',    'bus',       'Trips'],
        ['/operator/vehicles', 'car',       'Fleet'],
        ['/operator/drivers',  'users',     'Drivers'],
        ['/operator/reports',  'bar-chart', 'Reports'],
    ],
    'authority' => [
        ['/authority/monitor',     'globe',     'Live network'],
        ['/authority/emergencies', 'siren',     'Emergencies'],
        ['/authority/operators',   'building',  'Operators'],
        ['/authority/reports',     'bar-chart', 'Reports'],
    ],
    'admin' => [
        ['/admin/users',    'users',     'Users'],
        ['/admin/trips',    'bus',       'Trips'],
        ['/admin/reports',  'bar-chart', 'Reports'],
        ['/admin/settings', 'settings',  'Settings'],
    ],
];

$title = $titles[$role] ?? ['Dashboard', ''];
?>
<div class="page-head">
    <div>
        <h1 class="page-head__title"><?= e($title[0]) ?></h1>
        <p class="page-head__sub"><?= e($title[1]) ?></p>
    </div>
    <div class="page-head__actions">
        <a class="btn btn--ghost btn--sm" href="<?= e(url('/support')) ?>">
            <i class="icon icon--sm" data-icon="help">help</i> Support
        </a>
    </div>
</div>

<div class="stat-grid mb-5">
    <?php foreach ($stats[$role] ?? [] as [$label, $value, $icon, $tone, $href]): ?>
        <?= View::component('stat-card', [
            'label' => $label,
            'value' => (string) $value,
            'icon'  => $icon,
            'tone'  => $tone,
            'href'  => $href,
            'demo'  => is_demo_mode() && $tone === 'success',
        ]) ?>
    <?php endforeach; ?>
</div>

<section class="section">
    <div class="section__head">
        <div>
            <h2 class="section__title">Quick actions</h2>
            <p class="text-sm text-muted-2 mb-0">Jump straight into your workspace.</p>
        </div>
    </div>
    <div class="grid grid-4 gap-3">
        <?php foreach ($links[$role] ?? [] as [$path, $icon, $label]): ?>
            <a class="card card--hover" href="<?= e(url($path)) ?>" style="text-decoration:none;color:inherit">
                <div class="card__body">
                    <span class="stat__icon mb-2"><i class="icon" data-icon="<?= e($icon) ?>"><?= e($icon) ?></i></span>
                    <p class="fw-650 mb-0"><?= e($label) ?></p>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
</section>

<section class="section">
    <div class="card card--flat">
        <div class="card__body">
            <h2 class="card__title">Signed in as <?= e(Auth::name()) ?></h2>
            <p class="text-sm text-muted-2 mb-0">
                Roles: <?= e(implode(', ', array_map(static fn ($r): string => ucfirst((string) $r), Auth::roles()))) ?>.
                <?= is_demo_mode() ? 'This is a test environment.' : 'Statistics reflect the records in your workspace.' ?>
            </p>
        </div>
    </div>
</section>
