<?php
/**
 * UniGo - parcel list.
 *
 * @var array $deliveries
 * @var \App\Core\Paginator|null $paginator
 */
declare(strict_types=1);

use App\Core\View;

$deliveries = $deliveries ?? [];
$paginator  = $paginator ?? null;
?>
<section class="section">
    <div class="section__head">
        <div>
            <h2 class="section__title">My parcels</h2>
            <p class="text-sm text-muted-2 mb-0">Send and track deliveries across the network.</p>
        </div>
        <a class="btn btn--primary btn--sm" href="<?= e(url('/deliveries/new')) ?>">
            <i class="icon icon--sm" data-icon="plus">plus</i> Send a parcel
        </a>
    </div>

    <?php if (empty($deliveries)): ?>
        <?= View::partial('partials/empty-state', [
            'icon'  => 'package',
            'title' => 'No parcels yet',
            'text'  => 'Send your first parcel and track it every step of the way.',
            'action' => '<a class="btn btn--primary" href="' . e(url('/deliveries/new')) . '">Send a parcel</a>',
        ]) ?>
    <?php else: ?>
        <div class="grid grid-2 gap-3">
            <?php foreach ($deliveries as $d): ?>
                <article class="card card--hover">
                    <div class="card__body">
                        <div class="flex items-center justify-between mb-2">
                            <span class="badge badge-neutral"><?= e($d['tracking_number'] ?? '') ?></span>
                            <span class="badge badge-<?= e(status_tone($d['status'] ?? '')) ?>"><?= e(status_label($d['status'] ?? '')) ?></span>
                        </div>
                        <h3 class="card__title"><?= e($d['parcel_description'] ?? 'Parcel') ?></h3>
                        <p class="text-sm text-muted-2">
                            <i class="icon icon--xs" data-icon="pin">pin</i> <?= e($d['pickup_address'] ?? '') ?>
                            <i class="icon icon--xs" data-icon="arrow-right">arrow-right</i>
                            <?= e($d['dropoff_address'] ?? '') ?>
                        </p>
                        <div class="flex items-center justify-between text-sm mt-2">
                            <span>To <strong><?= e($d['recipient_name'] ?? '') ?></strong></span>
                            <span class="fw-700 text-primary"><?= e(money($d['price'] ?? 0)) ?></span>
                        </div>
                    </div>
                    <div class="card__footer flex items-center justify-between">
                        <span class="text-xs text-muted-2"><?= e(dt($d['created_at'] ?? null)) ?></span>
                        <a class="btn btn--ghost btn--sm" href="<?= e(url('/deliveries/' . urlencode((string) ($d['tracking_number'] ?? '')))) ?>">
                            Track <i class="icon icon--sm" data-icon="chevron-right">chevron-right</i>
                        </a>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>

        <?= View::partial('partials/pagination', ['paginator' => $paginator]) ?>
    <?php endif; ?>
</section>
