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
                    Support: <a href="tel:<?= e((string) Config::get('domain.support_phone', '')) ?>"><?= e((string) Config::get('domain.support_phone', 'Not set')) ?></a><br>
                    Emergency: <strong><?= e((string) Config::get('domain.emergency_hotline', '911')) ?></strong>
                </p>
            </div>
        </article>
        <article class="card">
            <div class="card__body">
                <span class="stat__icon mb-2"><i class="icon" data-icon="mail">mail</i></span>
                <h3 class="card__title">Email us</h3>
                <p class="text-sm text-muted-2 mb-0">
                    <a href="mailto:<?= e((string) Config::get('domain.support_email', 'support@unigo.test')) ?>">
                        <?= e((string) Config::get('domain.support_email', 'support@unigo.test')) ?>
                    </a>
                </p>
            </div>
        </article>
        <article class="card">
            <div class="card__body">
                <span class="stat__icon mb-2"><i class="icon" data-icon="pin">pin</i></span>
                <h3 class="card__title">Visit us</h3>
                <p class="text-sm text-muted-2 mb-0">UniGo House, Kampala Road<br>Kampala, Uganda</p>
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
