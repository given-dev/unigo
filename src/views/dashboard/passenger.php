<?php
/**
 * UniGo - passenger home dashboard.
 *
 * @var array $upcoming
 * @var array $bookings
 * @var int   $bookingsTotal
 * @var array $summary
 */
declare(strict_types=1);

use App\Core\Auth;
use App\Core\View;

$upcoming      = $upcoming ?? [];
$bookings      = $bookings ?? [];
$bookingsTotal = (int) ($bookingsTotal ?? 0);
$summary       = $summary ?? ['total_bookings' => 0, 'total_spent' => 0, 'loyalty_points' => 0];
?>
<div class="hero">
    <p class="hero__greeting">Hello, <?= e(Auth::firstName()) ?></p>
    <p class="hero__sub">Where would you like to go today?</p>

    <form class="search-panel mt-4" method="get" action="<?= e(url('/schedule')) ?>">
        <div class="search-panel__grid" style="grid-template-columns:1fr auto">
            <div class="search-field">
                <i class="icon search-field__icon" data-icon="search">search</i>
                <input class="input" type="search" name="q" placeholder="Search a destination or route">
            </div>
            <button class="btn btn--primary" type="submit">
                <i class="icon" data-icon="search">search</i> Find a trip
            </button>
        </div>
    </form>
</div>

<div class="stat-grid mb-5">
    <?= View::component('stat-card', [
        'label' => 'Upcoming trips', 'value' => (string) count($upcoming),
        'icon' => 'bus', 'tone' => 'primary', 'href' => '/bookings',
    ]) ?>
    <?= View::component('stat-card', [
        'label' => 'Total bookings', 'value' => number_short($bookingsTotal),
        'icon' => 'ticket', 'tone' => 'info', 'href' => '/bookings',
    ]) ?>
    <?= View::component('stat-card', [
        'label' => 'Loyalty points', 'value' => number_short($summary['loyalty_points'] ?? 0),
        'icon' => 'award', 'tone' => 'warning', 'demo' => is_demo_mode(),
    ]) ?>
    <?= View::component('stat-card', [
        'label' => 'Total spent', 'value' => money($summary['total_spent'] ?? 0),
        'icon' => 'wallet', 'tone' => 'success', 'demo' => is_demo_mode(),
    ]) ?>
</div>

<section class="section">
    <div class="section__head">
        <div>
            <h2 class="section__title">Upcoming trips</h2>
            <p class="text-sm text-muted-2 mb-0">Your confirmed journeys, soonest first.</p>
        </div>
        <a class="btn btn--ghost btn--sm" href="<?= e(url('/bookings')) ?>">
            All bookings <i class="icon icon--sm" data-icon="chevron-right">chevron-right</i>
        </a>
    </div>

    <?php if (empty($upcoming)): ?>
        <?= View::partial('partials/empty-state', [
            'icon'  => 'bus',
            'title' => 'No upcoming trips',
            'text'  => 'Search the schedule to book your next journey.',
            'action' => '<a class="btn btn--primary" href="' . e(url('/schedule')) . '">Find a trip</a>',
        ]) ?>
    <?php else: ?>
        <div class="grid grid-2 gap-3">
            <?php foreach ($upcoming as $trip): ?>
                <article class="card">
                    <div class="card__body">
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-xs text-muted-2"><?= e($trip['trip_code'] ?? '') ?></span>
                            <span class="badge badge-<?= e(status_tone($trip['booking_status'] ?? '')) ?>">
                                <?= e(status_label($trip['booking_status'] ?? '')) ?>
                            </span>
                        </div>
                        <h3 class="card__title">
                            <?= e($trip['origin_name'] ?? '') ?>
                            <i class="icon icon--xs" data-icon="arrow-right">arrow-right</i>
                            <?= e($trip['destination_name'] ?? '') ?>
                        </h3>
                        <p class="text-sm text-muted-2">
                            <i class="icon icon--xs" data-icon="clock">clock</i>
                            <?= e(dt($trip['departure_time'] ?? null)) ?>
                        </p>
                        <div class="flex items-center gap-3 text-sm mt-2">
                            <span class="badge badge-neutral"><?= e($trip['reference'] ?? '') ?></span>
                            <span>Seat <strong><?= e($trip['seat_number'] ?? '-') ?></strong></span>
                            <span class="text-primary fw-700"><?= e(money($trip['booking_fare'] ?? 0)) ?></span>
                        </div>
                    </div>
                    <div class="card__footer flex gap-2">
                        <a class="btn btn--primary btn--sm" href="<?= e(url('/tracking')) ?>">
                            <i class="icon icon--sm" data-icon="navigation">navigation</i> Track
                        </a>
                        <a class="btn btn--ghost btn--sm" href="<?= e(url('/bookings')) ?>">Details</a>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<section class="section">
    <div class="section__head">
        <div>
            <h2 class="section__title">Recent bookings</h2>
            <p class="text-sm text-muted-2 mb-0">Your latest activity.</p>
        </div>
    </div>

    <?php if (empty($bookings)): ?>
        <?= View::partial('partials/empty-state', [
            'icon'  => 'ticket',
            'title' => 'No bookings yet',
            'text'  => 'When you book a trip it will show up here.',
        ]) ?>
    <?php else: ?>
        <div class="card">
            <div class="card__body card__body--flush">
                <table class="table table--responsive">
                    <thead>
                        <tr>
                            <th>Reference</th>
                            <th>Route</th>
                            <th>Departure</th>
                            <th>Status</th>
                            <th class="num">Fare</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($bookings as $booking): ?>
                            <tr>
                                <td data-label="Reference"><span class="text-xs fw-600"><?= e($booking['reference'] ?? '') ?></span></td>
                                <td data-label="Route">
                                    <?= e($booking['origin_name'] ?? '') ?>
                                    <i class="icon icon--xs" data-icon="arrow-right">arrow-right</i>
                                    <?= e($booking['destination_name'] ?? '') ?>
                                </td>
                                <td data-label="Departure"><?= e(dt($booking['departure_time'] ?? null)) ?></td>
                                <td data-label="Status">
                                    <span class="badge badge-<?= e(status_tone($booking['status'] ?? '')) ?>">
                                        <?= e(status_label($booking['status'] ?? '')) ?>
                                    </span>
                                </td>
                                <td data-label="Fare" class="num"><?= e(money($booking['fare'] ?? 0)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</section>
