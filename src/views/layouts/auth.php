<?php
/**
 * UniGo - authentication layout (sign in, register, forgot password, 2FA).
 *
 * @var string $content
 * @var string $title
 */
declare(strict_types=1);

use App\Core\Config;
use App\Core\View;

$noIndex = true;
$features = [
    ['icon' => 'search',        'text' => 'Search live trips across buses, taxis, boda-bodas and shared rides.'],
    ['icon' => 'ticket',        'text' => 'Lock a seat with instant, conflict free booking.'],
    ['icon' => 'navigation',    'text' => 'Follow your vehicle on a map using shared driver locations with ETA updates.'],
    ['icon' => 'siren',         'text' => 'One tap SOS with instant alerts to the driver and authority.'],
    ['icon' => 'package',       'text' => 'Send parcels on the same fleet and track them end to end.'],
    ['icon' => 'bar-chart',     'text' => 'Operators and the authority get revenue, safety and usage insight.'],
];
?>
<?= View::partial('partials/head', [
    'title'     => $title ?? ('Sign in - ' . app_name()),
    'bodyClass' => $bodyClass ?? 'auth-page',
    'noIndex'   => true,
    'demo'      => $demo ?? is_demo_mode(),
]) ?>

<div class="auth">
    <aside class="auth__aside">
        <div>
            <div class="auth__aside-brand">
                <span class="sidebar__logo" aria-hidden="true">U</span>
                <span>
                    <span class="sidebar__name"><?= e(app_name()) ?></span>
                    <span class="sidebar__role"><?= e((string) Config::get('app.tagline', '')) ?></span>
                </span>
            </div>

            <h2 class="mt-8">One account for every kind of journey.</h2>
            <p>Book, track, pay, report and analyse &mdash; a single smart transport platform for Kampala and beyond.</p>

            <ul class="auth__features">
                <?php foreach ($features as $feature): ?>
                    <li>
                        <i class="icon" data-icon="<?= e($feature['icon']) ?>"><?= e($feature['icon']) ?></i>
                        <span><?= e($feature['text']) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>

        <div class="auth__stats">
            <div>
                <p class="auth__stat-num">6</p>
                <p class="auth__stat-label">Transport modes</p>
            </div>
            <div>
                <p class="auth__stat-num">5</p>
                <p class="auth__stat-label">User roles</p>
            </div>
            <div>
                <p class="auth__stat-num">24/7</p>
                <p class="auth__stat-label">Safety monitoring</p>
            </div>
        </div>
    </aside>

    <main class="auth__main">
        <div class="auth__card">
            <div class="auth__logo-mobile">
                <span class="sidebar__logo" aria-hidden="true" style="background:linear-gradient(135deg,var(--primary),#60A5FA);color:#fff">U</span>
                <span>
                    <span class="d-block fw-700 text-lg" style="color:var(--secondary)"><?= e(app_name()) ?></span>
                    <span class="text-xs text-muted-2"><?= e((string) Config::get('app.tagline', '')) ?></span>
                </span>
            </div>

            <?= View::component('flash') ?>
            <?= $content ?>
        </div>
    </main>
</div>

<?= View::component('scripts') ?>
</body>
</html>
