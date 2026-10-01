<?php
/**
 * UniGo - passenger booking list.
 *
 * @var array                       $bookings
 * @var \App\Core\Paginator|null    $paginator
 * @var string                      $status
 */
declare(strict_types=1);

use App\Core\View;

$bookings  = $bookings ?? [];
$paginator = $paginator ?? null;
$status    = $status ?? '';

$tabs = [
    ''           => 'All',
    'confirmed'  => 'Confirmed',
    'pending'    => 'Pending',
    'completed'  => 'Completed',
    'cancelled'  => 'Cancelled',
];
?>
<section class="section">
    <div class="section__head">
        <div>
            <h2 class="section__title">My bookings</h2>
            <p class="text-sm text-muted-2 mb-0">Reserved seats, past and upcoming.</p>
        </div>
    </div>

    <div class="chips mb-4">
        <?php foreach ($tabs as $key => $label): ?>
            <a class="chip<?= $status === $key ? ' is-active' : '' ?>" href="<?= e(url('/bookings' . ($key !== '' ? '?status=' . $key : ''))) ?>">
                <?= e($label) ?>
            </a>
        <?php endforeach; ?>
    </div>

    <?php if (empty($bookings)): ?>
        <?= View::partial('partials/empty-state', [
            'icon'  => 'ticket',
            'title' => 'No bookings here',
            'text'  => 'Book a trip and your tickets will appear in this list.',
            'action' => '<a class="btn btn--primary" href="' . e(url('/trips/search')) . '">Find a trip</a>',
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
                            <th>Seat</th>
                            <th>Status</th>
                            <th class="num">Fare</th>
                            <th></th>
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
                                    <br><span class="text-xs text-muted-2"><?= e($booking['trip_code'] ?? '') ?></span>
                                </td>
                                <td data-label="Departure"><?= e(dt($booking['departure_time'] ?? null)) ?></td>
                                <td data-label="Seat"><?= e($booking['seat_number'] ?? '-') ?></td>
                                <td data-label="Status">
                                    <span class="badge badge-<?= e(status_tone($booking['status'] ?? '')) ?>"><?= e(status_label($booking['status'] ?? '')) ?></span>
                                </td>
                                <td data-label="Fare" class="num"><?= e(money($booking['fare'] ?? 0)) ?></td>
                                <td data-label="">
                                    <a class="btn btn--ghost btn--xs" href="<?= e(url('/bookings/' . (int) ($booking['id'] ?? 0))) ?>">View</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?= View::partial('partials/pagination', ['paginator' => $paginator]) ?>
    <?php endif; ?>
</section>
