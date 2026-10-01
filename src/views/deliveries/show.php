<?php
/**
 * UniGo - parcel tracking detail.
 *
 * @var array $delivery
 * @var array $history
 */
declare(strict_types=1);

$delivery = $delivery ?? [];
$history  = $history ?? [];
?>
<div class="flex items-center gap-2 mb-4">
    <a class="btn btn--ghost btn--sm" href="<?= e(url('/deliveries')) ?>">
        <i class="icon icon--sm" data-icon="arrow-left">arrow-left</i> My parcels
    </a>
</div>

<div class="grid grid-2 gap-3">
    <article class="card">
        <div class="card__body">
            <div class="flex items-center justify-between mb-3">
                <span class="badge badge-neutral"><?= e($delivery['tracking_number'] ?? '') ?></span>
                <span class="badge badge-<?= e(status_tone($delivery['status'] ?? '')) ?>"><?= e(status_label($delivery['status'] ?? '')) ?></span>
            </div>
            <h2 class="card__title"><?= e($delivery['parcel_description'] ?? 'Parcel') ?></h2>
            <p class="text-sm text-muted-2 mb-3">
                To <?= e($delivery['recipient_name'] ?? '') ?>
                &middot; <?= e($delivery['recipient_phone'] ?? '') ?>
            </p>

            <div class="grid grid-2 gap-2 text-sm">
                <div><span class="text-muted-2">Pickup</span><br><strong><?= e($delivery['pickup_address'] ?? '') ?></strong></div>
                <div><span class="text-muted-2">Drop-off</span><br><strong><?= e($delivery['dropoff_address'] ?? '') ?></strong></div>
                <div><span class="text-muted-2">Weight</span><br><strong><?= e((string) ($delivery['weight_kg'] ?? '')) ?> kg</strong></div>
                <div><span class="text-muted-2">Declared value</span><br><strong><?= e(money($delivery['declared_value'] ?? 0)) ?></strong></div>
                <div><span class="text-muted-2">Price</span><br><strong class="text-primary"><?= e(money($delivery['price'] ?? 0)) ?></strong></div>
                <div><span class="text-muted-2">Fragile</span><br><strong><?= !empty($delivery['is_fragile']) ? 'Yes' : 'No' ?></strong></div>
                <div><span class="text-muted-2">Vehicle</span><br><strong><?= e($delivery['registration_number'] ?? 'Awaiting assignment') ?></strong></div>
                <div><span class="text-muted-2">Trip</span><br><strong><?= e($delivery['trip_code'] ?? '-') ?></strong></div>
            </div>
        </div>
    </article>

    <article class="card">
        <div class="card__body">
            <h3 class="card__title mb-3">Tracking history</h3>
            <?php if (empty($history)): ?>
                <p class="text-sm text-muted-2 mb-0">No tracking events yet.</p>
            <?php else: ?>
                <ol class="timeline">
                    <?php foreach ($history as $event): ?>
                        <li class="timeline__item timeline__item--done">
                            <span class="timeline__dot"></span>
                            <div>
                                <strong><?= e(status_label($event['status'] ?? '')) ?></strong>
                                <br><span class="text-sm text-muted-2"><?= e($event['description'] ?? '') ?></span>
                                <br><span class="text-xs text-muted-2"><?= e(dt($event['recorded_at'] ?? null)) ?><?= !empty($event['location_name']) ? ' · ' . e($event['location_name']) : '' ?></span>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
        </div>
    </article>
</div>
