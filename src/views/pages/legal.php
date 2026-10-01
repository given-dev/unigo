<?php
/**
 * UniGo - legal page (privacy policy, terms of use).
 *
 * @var string $heading
 * @var string $updated
 * @var array<int,array{title:string,body:string}> $sections
 */
declare(strict_types=1);
?>
<section class="section" style="max-width:70ch;margin-inline:auto">
    <div class="section__head">
        <div>
            <h2 class="section__title"><?= e($heading ?? 'Legal') ?></h2>
            <p class="text-sm text-muted-2 mb-0">Last updated <?= e($updated ?? 'January 2026') ?>.</p>
        </div>
    </div>

    <div class="card">
        <div class="card__body">
            <?php foreach (($sections ?? []) as $section): ?>
                <h3 class="card__title mt-3"><?= e($section['title'] ?? '') ?></h3>
                <p class="text-sm text-muted-2"><?= e($section['body'] ?? '') ?></p>
            <?php endforeach; ?>

            <p class="text-sm text-muted-2 mb-0">
                Questions? <a href="<?= e(url('/contact')) ?>">Contact us</a>.
            </p>
        </div>
    </div>
</section>
