<?php
/**
 * UniGo - payment receipt.
 *
 * @var array $payment
 */
declare(strict_types=1);

$payment = $payment ?? [];
?>
<div class="flex items-center gap-2 mb-4">
    <a class="btn btn--ghost btn--sm" href="<?= e(url('/payments')) ?>">
        <i class="icon icon--sm" data-icon="arrow-left">arrow-left</i> All payments
    </a>
</div>

<div class="grid grid-2 gap-3">
    <article class="card">
        <div class="card__body">
            <div class="flex items-center justify-between mb-3">
                <span class="badge badge-neutral"><?= e($payment['reference'] ?? '') ?></span>
                <span class="badge badge-<?= e(status_tone($payment['status'] ?? '')) ?>"><?= e(status_label($payment['status'] ?? '')) ?></span>
            </div>
            <h2 class="card__title mb-3"><?= e(money($payment['amount'] ?? 0)) ?></h2>

            <div class="grid grid-2 gap-2 text-sm">
                <div><span class="text-muted-2">Method</span><br><strong><?= e(status_label($payment['method'] ?? '')) ?></strong></div>
                <div><span class="text-muted-2">Provider</span><br><strong><?= e($payment['provider'] ?? '-') ?></strong></div>
                <div><span class="text-muted-2">Account</span><br><strong><?= e($payment['masked_account'] ?? '-') ?></strong></div>
                <div><span class="text-muted-2">Currency</span><br><strong><?= e($payment['currency'] ?? 'UGX') ?></strong></div>
                <div><span class="text-muted-2">Initiated</span><br><strong><?= e(dt($payment['created_at'] ?? null)) ?></strong></div>
                <div><span class="text-muted-2">Completed</span><br><strong><?= e(dt($payment['completed_at'] ?? null)) ?></strong></div>
                <div><span class="text-muted-2">Refunded</span><br><strong><?= e(money($payment['refunded_amount'] ?? 0)) ?></strong></div>
                <div><span class="text-muted-2">Booking</span><br><strong><?= e($payment['booking_reference'] ?? '-') ?></strong></div>
            </div>
        </div>
        <div class="card__footer flex items-center justify-between">
            <span class="text-xs text-muted-2">
                <?= !empty($payment['is_mock']) ? 'Simulated transaction' : 'Transaction' ?>
            </span>
            <button class="btn btn--ghost btn--sm" type="button" onclick="window.print()">
                <i class="icon icon--sm" data-icon="print">print</i> Print
            </button>
        </div>
    </article>
</div>
