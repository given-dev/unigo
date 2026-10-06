<?php
/**
 * UniGo - payment history list.
 *
 * @var array $payments
 * @var \App\Core\Paginator|null $paginator
 */
declare(strict_types=1);

use App\Core\View;

$payments  = $payments ?? [];
$paginator = $paginator ?? null;
?>
<section class="section">
    <div class="section__head">
        <div>
            <h2 class="section__title">Payments</h2>
            <p class="text-sm text-muted-2 mb-0"><?= is_demo_mode() ? 'Test payment history.' : 'Receipts for payments confirmed by authorised staff.' ?></p>
        </div>
    </div>

    <?php if (empty($payments)): ?>
        <?= View::partial('partials/empty-state', [
            'icon'  => 'card',
            'title' => 'No payments yet',
            'text'  => 'Your fares and parcel charges will appear here.',
            'action' => '<a class="btn btn--primary" href="' . e(url('/trips/search')) . '">Book a trip</a>',
        ]) ?>
    <?php else: ?>
        <div class="card">
            <div class="card__body card__body--flush">
                <table class="table table--responsive">
                    <thead>
                        <tr>
                            <th>Reference</th>
                            <th>For</th>
                            <th>Method</th>
                            <th>Date</th>
                            <th>Status</th>
                            <th class="num">Amount</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($payments as $p): ?>
                            <tr>
                                <td data-label="Reference"><span class="text-xs fw-600"><?= e($p['reference'] ?? '') ?></span></td>
                                <td data-label="For"><?= e($p['booking_reference'] ?? ($p['delivery_id'] ? 'Parcel delivery' : '-')) ?></td>
                                <td data-label="Method"><?= e(status_label($p['method'] ?? '')) ?></td>
                                <td data-label="Date"><?= e(dt($p['created_at'] ?? null)) ?></td>
                                <td data-label="Status">
                                    <span class="badge badge-<?= e(status_tone($p['status'] ?? '')) ?>"><?= e(status_label($p['status'] ?? '')) ?></span>
                                </td>
                                <td data-label="Amount" class="num"><?= e(money($p['amount'] ?? 0)) ?></td>
                                <td data-label="">
                                    <a class="btn btn--ghost btn--xs" href="<?= e(url('/payments/' . (int) ($p['id'] ?? 0))) ?>">Receipt</a>
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
