<?php
/**
 * UniGo - trip search results (passenger).
 *
 * @var array                       $trips
 * @var \App\Core\Paginator|null    $paginator
 * @var array                       $filters
 */
declare(strict_types=1);

use App\Core\View;

$trips     = $trips ?? [];
$paginator = $paginator ?? null;
$filters   = $filters ?? [];
?>
<section class="section">
    <form method="get" action="<?= e(url('/trips/search')) ?>" class="search-panel mb-5">
        <div class="search-panel__grid">
            <div class="search-field">
                <i class="icon search-field__icon" data-icon="pin">pin</i>
                <input class="input" type="text" name="from" value="<?= e($filters['from'] ?? '') ?>"
                       placeholder="From (town or stop)">
            </div>
            <div class="search-field">
                <i class="icon search-field__icon" data-icon="navigation">navigation</i>
                <input class="input" type="text" name="to" value="<?= e($filters['to'] ?? '') ?>"
                       placeholder="To (town or stop)">
            </div>
            <div class="search-field">
                <i class="icon search-field__icon" data-icon="calendar">calendar</i>
                <input class="input" type="date" name="date" value="<?= e($filters['date'] ?? date('Y-m-d')) ?>">
            </div>
            <div class="search-field">
                <i class="icon search-field__icon" data-icon="bus">bus</i>
                <select class="select" name="transport_type">
                    <option value="">Any transport</option>
                    <option value="bus" <?= ($filters['transport_type'] ?? '') === 'bus' ? 'selected' : '' ?>>Bus</option>
                    <option value="minibus" <?= ($filters['transport_type'] ?? '') === 'minibus' ? 'selected' : '' ?>>Minibus</option>
                    <option value="taxi" <?= ($filters['transport_type'] ?? '') === 'taxi' ? 'selected' : '' ?>>Taxi</option>
                    <option value="boda" <?= ($filters['transport_type'] ?? '') === 'boda' ? 'selected' : '' ?>>Boda</option>
                    <option value="shared_ride" <?= ($filters['transport_type'] ?? '') === 'shared_ride' ? 'selected' : '' ?>>Shared ride</option>
                </select>
            </div>
            <button class="btn btn--primary" type="submit">
                <i class="icon" data-icon="search">search</i> Search
            </button>
        </div>
    </form>

    <div class="section__head">
        <div>
            <h2 class="section__title">Available trips</h2>
            <p class="text-sm text-muted-2 mb-0"><?= count($trips) ?> result<?= count($trips) === 1 ? '' : 's' ?> for your search.</p>
        </div>
    </div>

    <?php if (empty($trips)): ?>
        <?= View::partial('partials/empty-state', [
            'icon'  => 'search',
            'title' => 'No trips found',
            'text'  => 'Try a different date, town or transport type.',
            'action' => '<a class="btn btn--ghost" href="' . e(url('/trips/search')) . '">Reset search</a>',
        ]) ?>
    <?php else: ?>
        <div class="grid grid-2 gap-3">
            <?php foreach ($trips as $trip): ?>
                <?php
                $booked    = (int) ($trip['seats_booked'] ?? 0);
                $total     = (int) ($trip['seats_total'] ?? 0);
                $available = max(0, $total - $booked);
                ?>
                <article class="card card--hover">
                    <div class="card__body">
                        <div class="flex items-center justify-between mb-2">
                            <span class="badge badge-primary"><?= e($trip['route_code'] ?? $trip['trip_code'] ?? '') ?></span>
                            <span class="badge badge-<?= e(status_tone($trip['trip_status'] ?? '')) ?>">
                                <?= e(status_label($trip['trip_status'] ?? '')) ?>
                            </span>
                        </div>
                        <h3 class="card__title">
                            <?= e($trip['origin_name'] ?? '') ?>
                            <i class="icon icon--xs" data-icon="arrow-right">arrow-right</i>
                            <?= e($trip['destination_name'] ?? '') ?>
                        </h3>
                        <p class="text-sm text-muted-2">
                            <i class="icon icon--xs" data-icon="building">building</i>
                            <?= e($trip['company_name'] ?? app_name()) ?>
                            <span class="mx-1">&middot;</span>
                            <?= e($trip['registration_number'] ?? '') ?>
                        </p>
                        <div class="flex items-center gap-3 text-sm mt-3 flex-wrap">
                            <span><i class="icon icon--xs" data-icon="clock">clock</i> <?= e(time_only($trip['departure_time'] ?? null)) ?></span>
                            <span><i class="icon icon--xs" data-icon="<?= e(transport_icon($trip['vehicle_type'] ?? '')) ?>"><?= e(transport_icon($trip['vehicle_type'] ?? '')) ?></i> <?= e(transport_label($trip['vehicle_type'] ?? '')) ?></span>
                            <span class="badge badge-<?= $available > 0 ? 'success' : 'danger' ?>"><?= (int) $available ?> seats</span>
                        </div>
                    </div>
                    <div class="card__footer flex items-center justify-between">
                        <span class="fw-700 text-primary"><?= e(money($trip['fare'] ?? 0)) ?></span>
                        <?php if ($available > 0): ?>
                            <a class="btn btn--primary btn--sm" href="<?= e(url('/trips/' . (int) $trip['trip_id'])) ?>">
                                <i class="icon icon--sm" data-icon="ticket">ticket</i> Book a seat
                            </a>
                        <?php else: ?>
                            <span class="badge badge-neutral">Fully booked</span>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>

        <?= View::partial('partials/pagination', ['paginator' => $paginator]) ?>
    <?php endif; ?>
</section>
