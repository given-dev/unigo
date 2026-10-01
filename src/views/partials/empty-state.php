<?php
/**
 * UniGo - empty state block.
 *
 * @var string      $icon
 * @var string      $title
 * @var string|null $text
 * @var string|null $action   raw HTML (usually one or two buttons)
 * @var bool        $isError
 */
declare(strict_types=1);

$icon    = $icon ?? 'clipboard';
$title   = $title ?? 'Nothing here yet';
$text    = $text ?? null;
$action  = $action ?? null;
$isError = $isError ?? false;
?>
<div class="empty<?= $isError ? ' empty--error' : '' ?>">
    <span class="empty__icon"><i class="icon" data-icon="<?= e($icon) ?>"><?= e($icon) ?></i></span>
    <p class="empty__title"><?= e($title) ?></p>
    <?php if ($text): ?>
        <p class="empty__text"><?= e($text) ?></p>
    <?php endif; ?>
    <?php if ($action): ?>
        <div class="flex gap-2 flex-wrap justify-center mt-2"><?= $action ?></div>
    <?php endif; ?>
</div>
