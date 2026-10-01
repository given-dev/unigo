<?php
/**
 * UniGo - 404 page.
 *
 * @var string $heading
 * @var string $message
 */
declare(strict_types=1);
?>
<section class="section">
    <div class="empty empty--error">
        <span class="empty__icon"><i class="icon" data-icon="compass">compass</i></span>
        <h1 class="empty__title"><?= e($heading ?? 'Page not found') ?></h1>
        <p class="empty__text"><?= e($message ?? 'The page you are looking for does not exist or has moved.') ?></p>
        <div class="flex gap-2 flex-wrap justify-center mt-3">
            <a class="btn btn--primary" href="<?= e(url('/')) ?>">
                <i class="icon" data-icon="home">home</i> Back to home
            </a>
            <a class="btn btn--ghost" href="<?= e(url('/support')) ?>">
                <i class="icon" data-icon="headset">headset</i> Get support
            </a>
        </div>
    </div>
</section>
