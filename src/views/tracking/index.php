<?php
/**
 * UniGo - live tracking view (passenger).
 *
 * @var array $trips
 * @var array|null $trip
 * @var array $mapConfig
 */
declare(strict_types=1);

use App\Core\View;

$trips     = $trips ?? [];
$trip      = $trip ?? null;
$mapConfig = $mapConfig ?? ['center' => [0.3476, 32.5825], 'zoom' => 12, 'markers' => [], 'vehicles' => [], 'route' => []];
?>
<section class="section">
    <div class="section__head">
        <div>
            <h2 class="section__title">Live tracking</h2>
            <p class="text-sm text-muted-2 mb-0">
                <?= is_demo_mode() ? 'Test vehicle positions.' : 'Follow the latest position shared by your driver. A position appears after the driver shares GPS.' ?>
            </p>
        </div>
    </div>

    <?php if (empty($trips)): ?>
        <?= View::partial('partials/empty-state', [
            'icon'  => 'navigation',
            'title' => 'Nothing to track yet',
            'text'  => 'Book a trip and you will be able to follow it here.',
            'action' => '<a class="btn btn--primary" href="' . e(url('/trips/search')) . '">Find a trip</a>',
        ]) ?>
    <?php else: ?>
        <div class="chips mb-4">
            <?php foreach ($trips as $t): ?>
                <?php $active = $trip && (int) $t['id'] === (int) $trip['id']; ?>
                <a class="chip<?= $active ? ' is-active' : '' ?>" href="<?= e(url('/tracking?trip=' . (int) $t['id'])) ?>">
                    <?= e($t['trip_code'] ?? '') ?>
                    <span class="text-xs text-muted-2"><?= e($t['origin_name'] ?? '') ?>&rarr;<?= e($t['destination_name'] ?? '') ?></span>
                </a>
            <?php endforeach; ?>
        </div>

        <?php if ($trip): ?>
            <div class="card mb-4">
                <div class="card__body">
                    <div class="flex items-center justify-between mb-2 flex-wrap gap-2">
                        <div>
                            <h3 class="card__title mb-0">
                                <?= e($trip['origin_name'] ?? '') ?>
                                <i class="icon icon--xs" data-icon="arrow-right">arrow-right</i>
                                <?= e($trip['destination_name'] ?? '') ?>
                            </h3>
                            <p class="text-sm text-muted-2 mb-0"><?= e($trip['trip_code'] ?? '') ?> &middot; <?= e($trip['registration_number'] ?? '') ?></p>
                        </div>
                        <span class="badge badge-<?= e(status_tone($trip['status'] ?? '')) ?>"><?= e(status_label($trip['status'] ?? '')) ?></span>
                    </div>
                </div>
                <div class="map map--trip" id="tripMap" data-map='<?= e((string) json_encode($mapConfig)) ?>' style="min-height:380px"></div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</section>

<?php if ($trip): ?>
    <?php View::start('scripts'); ?>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var el = document.getElementById('tripMap');
            if (!el || !window.UniGo || !window.UniGo.map) return;
            var config = {};
            try { config = JSON.parse(el.getAttribute('data-map') || '{}'); } catch (e) {}
            UniGo.map('tripMap', config);
        });
    </script>
    <?php View::stop(); ?>
<?php endif; ?>
