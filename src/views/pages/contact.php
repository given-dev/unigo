<?php
/**
 * UniGo - contact page.
 */
declare(strict_types=1);

use App\Core\Config;
?>
<section class="section">
    <div class="section__head">
        <div>
            <h2 class="section__title">Contact us</h2>
            <p class="text-sm text-muted-2 mb-0">We are here to help passengers, drivers and operators.</p>
        </div>
    </div>

    <div class="grid grid-3 gap-3">
        <article class="card">
            <div class="card__body">
                <span class="stat__icon mb-2"><i class="icon" data-icon="phone">phone</i></span>
                <h3 class="card__title">Call us</h3>
                <p class="text-sm text-muted-2 mb-0">
                    Support: <a href="tel:<?= e((string) Config::get('domain.support_phone', '')) ?>"><?= e((string) (Config::get('domain.support_phone') ?: 'Not configured')) ?></a><br>
                    Emergency: <strong><?= e((string) (Config::get('domain.emergency_hotline') ?: 'Not configured')) ?></strong>
                </p>
            </div>
        </article>
        <article class="card">
            <div class="card__body">
                <span class="stat__icon mb-2"><i class="icon" data-icon="mail">mail</i></span>
                <h3 class="card__title">Email us</h3>
                <p class="text-sm text-muted-2 mb-0">
                    <a href="mailto:<?= e((string) (Config::get('domain.support_email') ?: 'Not configured')) ?>">
                        <?= e((string) (Config::get('domain.support_email') ?: 'Not configured')) ?>
                    </a>
                </p>
            </div>
        </article>
        <article class="card">
            <div class="card__body">
                <span class="stat__icon mb-2"><i class="icon" data-icon="pin">pin</i></span>
                <h3 class="card__title">Support requests</h3>
                <p class="text-sm text-muted-2 mb-0">Sign in to submit a tracked support request. Staff can configure contact details in Settings.</p>
            </div>
        </article>
    </div>

    <div class="card mt-5">
        <div class="card__body">
            <h3 class="card__title">Send a message</h3>
            <p class="text-sm text-muted-2">
                Sign in to raise a tracked complaint or support ticket from your dashboard. This keeps your
                request linked to your account and lets our team respond with full context.
            </p>
            <div class="flex gap-2 flex-wrap mt-3">
                <a class="btn btn--primary" href="<?= e(url('/login')) ?>">
                    <i class="icon" data-icon="login">login</i> Sign in
                </a>
                <a class="btn btn--ghost" href="<?= e(url('/support')) ?>">
                    <i class="icon" data-icon="help">help</i> Read the FAQ
                </a>
            </div>
        </div>
    </div>
</section>
