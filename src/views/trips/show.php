<?php
/**
 * UniGo - trip detail + seat picker (passenger).
 *
 * @var array $trip
 * @var array $seats
 */
declare(strict_types=1);

use App\Core\Csrf;

$trip   = $trip ?? [];
$seats  = $seats ?? [];
$stops  = $trip['stops'] ?? [];
$tripId = (int) ($trip['id'] ?? 0);
$booked = (int) ($trip['seats_booked'] ?? 0);
$total  = (int) ($trip['seats_total'] ?? 0);
$free   = max(0, $total - $booked);

$mine = false;
$byRow = [];
$taken = [];
$mineSeats = [];
$perRow = 0;

foreach ($seats as $seat) {
    $num = (string) ($seat['seat_number'] ?? '');
    if ($num === '') {
        continue;
    }
    if (!empty($seat['is_mine'])) {
        $mine = true;
        $mineSeats[] = $num;
    } elseif (empty($seat['is_available'])) {
        $taken[] = $num;
    }
    $row = max(1, (int) ($seat['row_number'] ?? 1));
    $byRow[$row][] = $num;
}
ksort($byRow);

$layout = [];
foreach ($byRow as $rowSeats) {
    $perRow = max($perRow, count($rowSeats));
    $cells = [];
    foreach ($rowSeats as $i => $num) {
        if ($i === 2) {
            $cells[] = 'A';
        }
        $cells[] = $num;
    }
    $layout[] = $cells;
}
$perRow = max(1, $perRow);
?>
<div class="flex items-center gap-2 mb-4">
    <a class="btn btn--ghost btn--sm" href="<?= e(url('/trips/search')) ?>">
        <i class="icon icon--sm" data-icon="arrow-left">arrow-left</i> Back to search
    </a>
</div>

<div class="grid-3 gap-3" style="display:grid">
    <article class="card" style="grid-column:1 / -1">
        <div class="card__body">
            <div class="flex items-center justify-between mb-2">
                <span class="badge badge-primary"><?= e($trip['route_code'] ?? '') ?></span>
                <span class="badge badge-<?= e(status_tone($trip['status'] ?? '')) ?>"><?= e(status_label($trip['status'] ?? '')) ?></span>
            </div>
            <h2 class="card__title">
                <?= e($trip['origin_name'] ?? '') ?>
                <i class="icon icon--xs" data-icon="arrow-right">arrow-right</i>
                <?= e($trip['destination_name'] ?? '') ?>
            </h2>
            <p class="text-sm text-muted-2 mb-3"><?= e($trip['route_name'] ?? '') ?></p>

            <div class="grid grid-4 gap-2 text-sm">
                <div><span class="text-muted-2">Trip</span><br><strong><?= e($trip['trip_code'] ?? '') ?></strong></div>
                <div><span class="text-muted-2">Fare</span><br><strong class="text-primary"><?= e(money($trip['fare'] ?? 0)) ?></strong></div>
                <div><span class="text-muted-2">Departs</span><br><strong><?= e(dt($trip['departure_time'] ?? null)) ?></strong></div>
                <div><span class="text-muted-2">Arrives</span><br><strong><?= e(dt($trip['arrival_time'] ?? null)) ?></strong></div>
                <div><span class="text-muted-2">Vehicle</span><br><strong><?= e($trip['registration_number'] ?? '') ?></strong></div>
                <div><span class="text-muted-2">Operator</span><br><strong><?= e($trip['company_name'] ?? app_name()) ?></strong></div>
                <div><span class="text-muted-2">Driver</span><br><strong><?= e(trim((string) ($trip['driver_first_name'] ?? '') . ' ' . (string) ($trip['driver_last_name'] ?? '')) ?: 'Assigned soon') ?></strong></div>
                <div><span class="text-muted-2">Seats free</span><br><strong><?= (int) $free ?> of <?= (int) $total ?></strong></div>
            </div>
        </div>
    </article>
</div>

<?php if ($mine): ?>
    <div class="card mt-3">
        <div class="card__body">
            <?= \App\Core\View::partial('partials/empty-state', [
                'icon'  => 'check-circle',
                'title' => 'You already booked this trip',
                'text'  => 'You hold a seat on this trip. Manage it from your bookings.',
                'action' => '<a class="btn btn--primary btn--sm" href="' . e(url('/bookings')) . '">View my bookings</a>',
            ]) ?>
        </div>
    </div>
<?php elseif (empty($seats)): ?>
    <div class="card mt-3">
        <div class="card__body">
            <?= \App\Core\View::partial('partials/empty-state', [
                'icon'  => 'bus',
                'title' => 'No seats available',
                'text'  => 'This trip is full or not open for booking.',
            ]) ?>
        </div>
    </div>
<?php else: ?>
    <form method="post" action="<?= e(url('/trips/' . $tripId . '/book')) ?>" class="mt-3">
        <?= Csrf::field() ?>

        <div class="seat-map">
            <div class="seat-layout">
                <div id="seatPlan"
                     data-seat-map
                     data-layout='<?= e((string) json_encode($layout)) ?>'
                     data-taken="<?= e(implode(',', $taken)) ?>"
                     data-mine="<?= e(implode(',', $mineSeats)) ?>"
                     data-max="1"
                     data-base-price="<?= e((string) ($trip['fare'] ?? 0)) ?>"
                     data-input="seat_number"
                     data-front-label="Driver"></div>
            </div>

            <div>
                <article class="card">
                    <div class="card__body">
                        <h3 class="card__title mb-3">Your selection</h3>
                        <div class="flex items-center justify-between text-sm mb-2">
                            <span class="text-muted-2">Seat</span>
                            <strong id="seatList">-</strong>
                        </div>
                        <div class="flex items-center justify-between text-sm mb-3">
                            <span class="text-muted-2">Seats chosen</span>
                            <strong id="seatCount">0</strong>
                        </div>
                        <div class="flex items-center justify-between mb-4">
                            <span class="text-muted-2">Total</span>
                            <strong class="text-primary" id="seatTotal"><?= e(money(0)) ?></strong>
                        </div>

                        <div class="form-grid">
                            <div class="field">
                                <label class="label" for="from_stop_id">Boarding stop</label>
                                <select class="select" id="from_stop_id" name="from_stop_id">
                                    <option value="">Origin</option>
                                    <?php foreach ($stops as $stop): ?>
                                        <option data-fare="<?= e((string)($stop['fare_from_origin'] ?? 0)) ?>" value="<?= (int) ($stop['id'] ?? 0) ?>"><?= e($stop['stop_name'] ?? '') ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="field">
                                <label class="label" for="to_stop_id">Drop-off stop</label>
                                <select class="select" id="to_stop_id" name="to_stop_id">
                                    <option value="">Destination</option>
                                    <?php foreach ($stops as $stop): ?>
                                        <option data-fare="<?= e((string)($stop['fare_from_origin'] ?? 0)) ?>" value="<?= (int) ($stop['id'] ?? 0) ?>"><?= e($stop['stop_name'] ?? '') ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="field">
                                <label class="label" for="payment_method">Payment</label>
                                <select class="select" id="payment_method" name="payment_method">
                                    <option value="mobile_money">Mobile money (simulated)</option>
                                    <option value="card">Card (simulated)</option>
                                    <option value="wallet">UniGo wallet</option>
                                    <option value="cash">Pay the driver (cash)</option>
                                </select>
                            </div>
                            <div class="field">
                                <label class="label" for="pickup_point">Pickup note (optional)</label>
                                <input class="input" id="pickup_point" name="pickup_point" maxlength="150" placeholder="e.g. near the taxi stage">
                            </div>
                        </div>

                        <p class="text-sm text-muted-2 mt-3 mb-0">
                            <i class="icon icon--sm" data-icon="info">info</i>
                            Payments are simulated in this demo environment.
                        </p>
                    </div>
                    <div class="card__footer">
                        <button class="btn btn--primary btn--block" type="submit" data-seat-submit disabled>
                            <i class="icon" data-icon="ticket">ticket</i> Confirm booking
                        </button>
                    </div>
                </article>
            </div>
        </div>
    </form>
<?php endif; ?>
