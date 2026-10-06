<?php
/**
 * UniGo - support / help centre.
 */
declare(strict_types=1);

use App\Core\Config;
?>
<section class="section">
    <div class="section__head">
        <div>
            <h2 class="section__title">Help &amp; support</h2>
            <p class="text-sm text-muted-2 mb-0">Answers to common questions, and how to reach us.</p>
        </div>
    </div>

    <div class="grid grid-2 gap-3 mb-5">
        <article class="card">
            <div class="card__body">
                <span class="stat__icon mb-2"><i class="icon" data-icon="headset">headset</i></span>
                <h3 class="card__title">Contact support</h3>
                <p class="text-sm text-muted-2">Phone <a href="tel:<?= e((string) Config::get('domain.support_phone', '')) ?>"><?= e((string) Config::get('domain.support_phone', 'Not set')) ?></a></p>
                <p class="text-sm text-muted-2 mb-0">Email <a href="mailto:<?= e((string) Config::get('domain.support_email', 'support@unigo.test')) ?>"><?= e((string) Config::get('domain.support_email', 'support@unigo.test')) ?></a></p>
            </div>
        </article>
        <article class="card">
            <div class="card__body">
                <span class="stat__icon mb-2"><i class="icon" data-icon="siren">siren</i></span>
                <h3 class="card__title">Emergency</h3>
                <p class="text-sm text-muted-2">
                    In an emergency, use the SOS button in the app or call the hotline
                    <strong><?= e((string) Config::get('domain.emergency_hotline', '911')) ?></strong>.
                </p>
                <p class="text-xs text-muted-2 mb-0">Alerts are sent to the system workspace. For urgent help, contact emergency services directly.</p>
            </div>
        </article>
    </div>

    <div class="section__head">
        <div>
            <h2 class="section__title">Frequently asked</h2>
        </div>
    </div>

    <?php
    $faqs = [
        ['How do I book a seat?', 'Create an account, search for your route on the schedule, choose a trip and pick an available seat. The seat is held while you pay.'],
        ['How do I track my vehicle?', 'Open the tracking page from your dashboard. Your driver\'s live position and the estimated arrival time update on the map.'],
        ['Can I send a parcel?', 'Yes. Use the delivery flow from your dashboard, describe the item and its destination, and you will get a tracking number.'],
        ['What if I need to cancel?', 'Open the booking from My bookings and cancel. Seats are released back to other passengers instantly.'],
        ['Is my payment real?', is_demo_mode() ? 'Example payments do not move money.' : 'Pay your travel company in cash. A receipt appears after staff records collection; digital checkout is not connected.'],
        ['How do I reset my password?', 'Use the "Forgot password" link on the sign-in page. Contact support for recovery assistance.'],
    ];
    ?>
    <div class="grid gap-2">
        <?php foreach ($faqs as [$question, $answer]): ?>
            <details class="card card--flat">
                <summary class="card__body" style="cursor:pointer;font-weight:650">
                    <?= e($question) ?>
                </summary>
                <div class="card__body" style="padding-top:0">
                    <p class="text-sm text-muted-2 mb-0"><?= e($answer) ?></p>
                </div>
            </details>
        <?php endforeach; ?>
    </div>
</section>
