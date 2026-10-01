<?php
/**
 * UniGo - single booking ticket.
 *
 * @var array $booking
 */
declare(strict_types=1);

use App\Core\Csrf;

$booking = $booking ?? [];
$id      = (int) ($booking['id'] ?? 0);
$status  = (string) ($booking['status'] ?? '');
$canCancel = in_array($status, ['pending', 'confirmed'], true)
    && !in_array((string) ($booking['trip_status'] ?? ''), ['in_transit', 'completed'], true);
$driverName = trim((string) ($booking['driver_first_name'] ?? '') . ' ' . (string) ($booking['driver_last_name'] ?? ''));
?>
<div class="flex items-center gap-2 mb-4">
    <a class="btn btn--ghost btn--sm" href="<?= e(url('/bookings')) ?>">
        <i class="icon icon--sm" data-icon="arrow-left">arrow-left</i> All bookings
    </a>
</div>

<div class="grid grid-2 gap-3">
    <article class="card">
        <div class="card__body">
            <div class="flex items-center justify-between mb-3">
                <span class="badge badge-neutral"><?= e($booking['reference'] ?? '') ?></span>
                <span class="badge badge-<?= e(status_tone($status)) ?>"><?= e(status_label($status)) ?></span>
            </div>
            <h2 class="card__title">
                <?= e($booking['origin_name'] ?? '') ?>
                <i class="icon icon--xs" data-icon="arrow-right">arrow-right</i>
                <?= e($booking['destination_name'] ?? '') ?>
            </h2>
            <p class="text-sm text-muted-2"><?= e($booking['route_name'] ?? '') ?></p>

            <div class="grid grid-2 gap-2 text-sm mt-3">
                <div><span class="text-muted-2">Trip</span><br><strong><?= e($booking['trip_code'] ?? '') ?></strong></div>
                <div><span class="text-muted-2">Seat</span><br><strong><?= e($booking['seat_number'] ?? '-') ?></strong></div>
                <div><span class="text-muted-2">Departs</span><br><strong><?= e(dt($booking['departure_time'] ?? null)) ?></strong></div>
                <div><span class="text-muted-2">Arrives</span><br><strong><?= e(dt($booking['arrival_time'] ?? null)) ?></strong></div>
                <div><span class="text-muted-2">Fare</span><br><strong class="text-primary"><?= e(money($booking['fare'] ?? 0)) ?></strong></div>
                <div><span class="text-muted-2">Payment</span><br><strong><?= e(status_label($booking['payment_status'] ?? 'unpaid')) ?></strong></div>
                <div><span class="text-muted-2">Vehicle</span><br><strong><?= e($booking['registration_number'] ?? '-') ?></strong></div>
                <div><span class="text-muted-2">Driver</span><br><strong><?= e($driverName !== '' ? $driverName : 'Assigned soon') ?></strong></div>
            </div>
        </div>
        <div class="card__footer flex gap-2 flex-wrap">
            <a class="btn btn--primary btn--sm" href="<?= e(url('/tracking')) ?>">
                <i class="icon icon--sm" data-icon="navigation">navigation</i> Track trip
            </a>
            <?php if ($canCancel): ?>
                <form method="post" action="<?= e(url('/bookings/' . $id . '/cancel')) ?>"
                      data-confirm="Cancel this booking?">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="reason" value="Cancelled by the passenger">
                    <button class="btn btn--danger btn--sm" type="submit">
                        <i class="icon icon--sm" data-icon="x">x</i> Cancel booking
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </article>

    <article class="card">
        <div class="card__body">
            <h3 class="card__title mb-3">Journey status</h3>
            <ol class="timeline">
                <?php
                $steps = [
                    'pending'   => 'Reserved',
                    'confirmed' => 'Confirmed',
                    'completed' => 'Completed',
                ];
                $order = ['pending' => 1, 'confirmed' => 2, 'completed' => 3];
                $current = $order[$status] ?? 0;
                ?>
                <?php foreach ($steps as $key => $label): ?>
                    <li class="timeline__item">
                        <span class="timeline__dot<?= ($order[$key] <= $current) ? ' is-done' : '' ?>"></span>
                        <div><strong><?= e($label) ?></strong></div>
                    </li>
                <?php endforeach; ?>
                <?php if ($status === 'cancelled'): ?>
                    <li class="timeline__item">
                        <span class="timeline__dot is-danger"></span>
                        <div><strong>Cancelled</strong><br><span class="text-sm text-muted-2"><?= e($booking['cancel_reason'] ?? '') ?></span></div>
                    </li>
                <?php endif; ?>
            </ol>
        </div>
    </article>
</div>
