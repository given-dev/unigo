<?php
/**
 * UniGo - script footer.
 *
 * Loads the shared bundle, then any page specific bundle declared with
 * View::start('scripts') / View::stop() in a controller or view.
 */
declare(strict_types=1);

use App\Core\Config;
use App\Core\View;

$leaflet  = (bool) ($withLeaflet ?? false);
$chartJs  = (bool) ($withChart ?? false);
$apiBase  = e(url('/api'));
?>
<script>
    window.UNIGO = {
        baseUrl: <?= json_encode(url('/')) ?>,
        apiUrl: <?= json_encode($apiBase) ?>,
        csrf: <?= json_encode((string) ($csrfToken ?? \App\Core\Csrf::token())) ?>,
        currency: <?= json_encode((string) Config::get('app.currency_symbol', 'UGX')) ?>,
        demo: <?= is_demo_mode() ? 'true' : 'false' ?>,
        debug: <?= (bool) Config::get('app.debug', false) ? 'true' : 'false' ?>
    };
</script>

<?php if ($leaflet): ?>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
          integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
            integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin="" defer></script>
<?php endif; ?>

<?php if ($chartJs): ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" defer></script>
<?php endif; ?>

<script src="<?= e(asset('assets/js/app.js')) ?>" defer></script>
<?php if ($withSeats ?? false): ?>
    <script src="<?= e(asset('assets/js/modules/seatmap.js')) ?>" defer></script>
<?php endif; ?>
<?php if ($leaflet): ?>
    <script src="<?= e(asset('assets/js/modules/maps.js')) ?>" defer></script>
<?php endif; ?>
<?php if ($chartJs): ?>
    <script src="<?= e(asset('assets/js/modules/charts.js')) ?>" defer></script>
<?php endif; ?>
<?= View::section('scripts') ?>
