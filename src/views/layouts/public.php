<?php
/**
 * UniGo - public layout (landing page, about, public schedule, legal pages).
 *
 * @var string $content
 * @var string $title
 */
declare(strict_types=1);

use App\Core\Auth;
use App\Core\Config;
use App\Core\Request;
use App\Core\View;

$current = Request::instance()->path();
$links   = [
    ['/trips/search', 'Find a trip'],
    ['/#travel-companies', 'Companies'],
    ['/about',    'About'],
    ['/support',  'Support'],
    ['/contact',  'Contact'],
];
?>
<?= View::partial('partials/head', [
    'title'     => $title ?? (app_name() . ' - ' . (string) Config::get('app.tagline', '')),
    'bodyClass' => $bodyClass ?? 'public-page',
    'demo'      => $demo ?? is_demo_mode(),
]) ?>

<?php if (is_demo_mode()): ?>
    <div class="demo-banner no-print">
        <i class="icon" data-icon="flame">flame</i>
        <span>Simulated data environment &mdash; nothing on this site is a live transport feed.</span>
    </div>
<?php endif; ?>

<header class="topbar" style="position:sticky">
    <a class="flex items-center gap-2" href="<?= e(url('/')) ?>">
        <span class="sidebar__logo" aria-hidden="true" style="background:linear-gradient(135deg,var(--primary),#60A5FA);color:#fff">U</span>
        <span>
            <span class="d-block fw-700" style="color:var(--secondary);line-height:1"><?= e(app_name()) ?></span>
            <span class="text-xs text-muted-2"><?= e((string) Config::get('app.tagline', '')) ?></span>
        </span>
    </a>

    <?php if (empty($setupPending)): ?>
    <nav class="topbar__actions only-desktop items-center gap-1">
        <?php foreach ($links as $link): ?>
            <a class="btn btn--ghost btn--sm<?= nav_active($link[0], $current) ?>" href="<?= e(url($link[0])) ?>"><?= e($link[1]) ?></a>
        <?php endforeach; ?>
    </nav>

    <div class="topbar__actions">
        <?php if (Auth::check()): ?>
            <a class="btn btn--primary btn--sm" href="<?= e(url('/home')) ?>">
                <i class="icon" data-icon="dashboard">dashboard</i> Open dashboard
            </a>
        <?php else: ?>
            <a class="btn btn--ghost btn--sm" href="<?= e(url('/login')) ?>">Sign in</a>
            <a class="btn btn--primary btn--sm" href="<?= e(url('/register')) ?>">Create account</a>
        <?php endif; ?>
    </div>
    <?php else: ?><span class="setup-status">Setup required</span><?php endif; ?>
</header>

<main id="main">
    <?= View::component('flash') ?>
    <?= $content ?>
</main>

<?php if (empty($setupPending)): ?>
<footer class="site-footer">
    <div class="footer-grid">
        <div>
            <div class="flex items-center gap-2 mb-3">
                <span class="sidebar__logo" aria-hidden="true" style="background:linear-gradient(135deg,var(--primary),#60A5FA);color:#fff">U</span>
                <span class="footer-title mb-0"><?= e(app_name()) ?></span>
            </div>
            <p style="max-width:38ch">
                <?= e(app_name()) ?> is an integrated smart transport system for passenger travel, freight
                delivery and fleet oversight &mdash; built for Kampala first, expandable to any city.
            </p>
            <p class="mt-3 text-xs">
                <a href="<?= e(url('/support')) ?>">Contact support and emergency guidance</a>.
            </p>
        </div>
        <div>
            <p class="footer-title">Platform</p>
            <p><a href="<?= e(url('/schedule')) ?>">Trip schedule</a></p>
            <p><a href="<?= e(url('/about')) ?>">About UniGo</a></p>
            <p><a href="<?= e(url('/register')) ?>">Create account</a></p>
        </div>
        <div>
            <p class="footer-title">Roles</p>
            <p><a href="<?= e(url('/login')) ?>">Passenger portal</a></p>
            <p><a href="<?= e(url('/login')) ?>">Driver portal</a></p>
            <p><a href="<?= e(url('/login')) ?>">Operator portal</a></p>
            <p><a href="<?= e(url('/login')) ?>">Authority portal</a></p>
        </div>
        <div>
            <p class="footer-title">Support</p>
            <p><a href="<?= e(url('/support')) ?>">Help centre</a></p>
            <p><a href="<?= e(url('/contact')) ?>">Contact us</a></p>
            <p><a href="<?= e(url('/privacy')) ?>">Privacy</a></p>
            <p><a href="<?= e(url('/terms')) ?>">Terms of use</a></p>
        </div>
    </div>
    <p class="mt-6 text-xs" style="max-width:var(--content-max);margin-inline:auto">
        &copy; <?= date('Y') ?> <?= e(app_name()) ?>. Travel and fleet management.
    </p>
</footer>
<?php endif; ?>

<div class="toast-stack" id="toastStack" aria-live="polite"></div>

<?= View::component('scripts', [
    'withLeaflet' => $withLeaflet ?? false,
    'withChart'   => $withChart ?? false,
]) ?>
</body>
</html>
