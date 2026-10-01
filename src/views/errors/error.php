<?php
/**
 * UniGo - generic error page (rendered by the error handler).
 *
 * @var int         $code
 * @var string      $heading
 * @var string      $message
 * @var string|null $debug
 */
declare(strict_types=1);
?>
<section class="section">
    <div class="empty empty--error">
        <span class="empty__icon"><i class="icon" data-icon="alert">alert</i></span>
        <h1 class="empty__title"><?= e($heading ?? 'Something went wrong') ?></h1>
        <p class="empty__text"><?= e($message ?? 'We could not complete your request. Please try again.') ?></p>

        <?php if (!empty($debug)): ?>
            <pre class="text-xs" style="max-width:60ch;overflow:auto;text-align:left;background:var(--surface-sunk);padding:12px;border-radius:8px"><?= e($debug) ?></pre>
        <?php endif; ?>

        <div class="flex gap-2 flex-wrap justify-center mt-3">
            <a class="btn btn--primary" href="<?= e(url('/')) ?>">
                <i class="icon" data-icon="home">home</i> Back to home
            </a>
            <a class="btn btn--ghost" href="<?= e(url('/support')) ?>">
                <i class="icon" data-icon="headset">headset</i> Contact support
            </a>
        </div>
        <?php if (!empty($code)): ?>
            <p class="text-xs text-muted-2 mt-3">Error code <?= e((string) $code) ?></p>
        <?php endif; ?>
    </div>
</section>
