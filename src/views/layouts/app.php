<?php
/**
 * UniGo - application layout (signed in shell).
 *
 * @var string      $content       rendered page
 * @var string      $title         document title
 * @var string      $pageTitle     top bar title
 * @var string|null $pageSub       top bar subtitle
 * @var bool        $navHidden     render a bare page (onboarding, full screen maps)
 * @var bool        $withLeaflet   load Leaflet
 * @var bool        $withChart     load Chart.js
 * @var array       $navBadges     path => badge count
 * @var int         $unreadNotifications
 * @var bool        $sosFab        show the floating SOS button
 */
declare(strict_types=1);

use App\Core\Auth;
use App\Core\View;

$hasSidebar = empty($navHidden);
$demo       = $demo ?? is_demo_mode();
$sosFab     = $sosFab ?? ($hasSidebar && Auth::hasRole('passenger', 'driver'));

$bodyClass = trim(($bodyClass ?? '') . ($hasSidebar ? ' has-sidebar' : '') . ($sosFab ? ' has-sos' : ''));
?>
<?= View::partial('partials/head', [
    'title'     => $title ?? (app_name() . ' - Dashboard'),
    'bodyClass' => $bodyClass,
    'demo'      => $demo,
]) ?>

<?php if ($demo): ?>
    <div class="demo-banner no-print" role="note">
        <i class="icon" data-icon="flame">flame</i>
        <span>Simulated data environment &mdash; trips, GPS traces, payments and predictions are demo data, not live feeds.</span>
    </div>
<?php endif; ?>

<div class="offline-banner no-print" role="status">
    <i class="icon" data-icon="wifi-off">wifi-off</i>
    <span>You are offline. Showing the last loaded data.</span>
</div>

<div class="app">
    <?php if ($hasSidebar): ?>
        <?= View::component('sidebar', ['navBadges' => $navBadges ?? []]) ?>
    <?php endif; ?>

    <?= View::component('topbar', [
        'pageTitle'             => $pageTitle ?? null,
        'pageSub'               => $pageSub ?? null,
        'unreadNotifications'   => $unreadNotifications ?? 0,
        'recentNotifications'   => $recentNotifications ?? [],
        'topbarAction'          => $topbarAction ?? null,
        'navHidden'             => !$hasSidebar,
    ]) ?>

    <div class="shell">
        <main class="content" id="main">
            <?= View::component('flash') ?>
            <?= $content ?>
        </main>
    </div>

    <?php if ($hasSidebar): ?>
        <?= View::component('bottom-nav', ['navBadges' => $navBadges ?? []]) ?>
    <?php endif; ?>
</div>

<?php if ($sosFab): ?>
    <a class="fab sos-fab" href="<?= e(url('/emergency/new')) ?>" aria-label="Report an emergency">
        <i class="icon" data-icon="siren">siren</i>
        <span class="only-mobile">SOS</span>
    </a>
<?php endif; ?>

<div class="toast-stack" id="toastStack" aria-live="polite" aria-atomic="true"></div>

<?= View::component('scripts', [
    'withLeaflet' => $withLeaflet ?? false,
    'withChart'   => $withChart ?? false,
    'withSeats'   => $withSeats ?? false,
]) ?>
</body>
</html>
