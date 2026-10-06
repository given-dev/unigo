<?php
/**
 * UniGo - send a parcel form.
 */
declare(strict_types=1);

use App\Core\Csrf;

$old = \App\Core\Flash::oldAll();
?>
<div class="flex items-center gap-2 mb-4">
    <a class="btn btn--ghost btn--sm" href="<?= e(url('/deliveries')) ?>">
        <i class="icon icon--sm" data-icon="arrow-left">arrow-left</i> My parcels
    </a>
</div>

<form method="post" action="<?= e(url('/deliveries')) ?>">
    <?= Csrf::field() ?>

    <div class="grid grid-2 gap-3">
        <article class="card">
            <div class="card__body">
                <h3 class="card__title mb-3">Recipient</h3>
                <div class="form-grid">
                    <div class="field">
                        <label class="label" for="recipient_name">Full name <span class="req">*</span></label>
                        <input class="input" id="recipient_name" name="recipient_name" required maxlength="120"
                               value="<?= e($old['recipient_name'] ?? '') ?>">
                    </div>
                    <div class="field">
                        <label class="label" for="recipient_phone">Phone <span class="req">*</span></label>
                        <input class="input" id="recipient_phone" name="recipient_phone" required maxlength="25"
                               value="<?= e($old['recipient_phone'] ?? '') ?>">
                    </div>
                </div>
            </div>
        </article>

        <article class="card">
            <div class="card__body">
                <h3 class="card__title mb-3">Parcel</h3>
                <div class="form-grid">
                    <div class="field">
                        <label class="label" for="weight_kg">Weight (kg)</label>
                        <input class="input" id="weight_kg" name="weight_kg" type="number" step="0.1" min="0.1" value="<?= e((string) ($old['weight_kg'] ?? '1')) ?>">
                    </div>
                    <div class="field">
                        <label class="label" for="declared_value">Declared value</label>
                        <input class="input" id="declared_value" name="declared_value" type="number" step="100" min="0" value="<?= e((string) ($old['declared_value'] ?? '0')) ?>">
                    </div>
                    <div class="field" style="grid-column:1 / -1">
                        <label class="label" for="parcel_description">Description <span class="req">*</span></label>
                        <textarea class="textarea" id="parcel_description" name="parcel_description" rows="3" required maxlength="255"><?= e($old['parcel_description'] ?? '') ?></textarea>
                    </div>
                    <label class="check">
                        <input type="checkbox" name="is_fragile" value="1" <?= !empty($old['is_fragile']) ? 'checked' : '' ?>>
                        <span>Fragile &mdash; handle with care</span>
                    </label>
                </div>
            </div>
        </article>

        <article class="card" style="grid-column:1 / -1">
            <div class="card__body">
                <h3 class="card__title mb-3">Route</h3>
                <div class="form-grid">
                    <div class="field">
                        <label class="label" for="pickup_address">Pickup address <span class="req">*</span></label>
                        <input class="input" id="pickup_address" name="pickup_address" required maxlength="200"
                               value="<?= e($old['pickup_address'] ?? '') ?>">
                    </div>
                    <div class="field">
                        <label class="label" for="dropoff_address">Drop-off address <span class="req">*</span></label>
                        <input class="input" id="dropoff_address" name="dropoff_address" required maxlength="200"
                               value="<?= e($old['dropoff_address'] ?? '') ?>">
                    </div>
                </div>
                <p class="text-sm text-muted-2 mt-3 mb-0">
                    <i class="icon icon--sm" data-icon="info">info</i>
                    Pricing is estimated from weight. Staff will confirm pickup and delivery arrangements.
                </p>
            </div>
            <div class="card__footer flex items-center justify-end">
                <button class="btn btn--primary" type="submit">
                    <i class="icon" data-icon="package">package</i> Request pickup
                </button>
            </div>
        </article>
    </div>
</form>
