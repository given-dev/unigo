<?php
/**
 * UniGo - placeholder for workspaces that are wired into navigation but not
 * yet implemented. Keeps every nav link a real, styled page (never a 404).
 *
 * @var string      $heading
 * @var string|null $message
 * @var string      $area
 */
declare(strict_types=1);

use App\Core\View;

$heading = $heading ?? 'Coming soon';
$message = $message ?? 'This workspace is being built and will be available shortly.';
$area    = $area ?? '';
?>
<section class="section">
    <div class="section__head">
        <div>
            <h2 class="section__title"><?= e($heading) ?></h2>
            <p class="text-sm text-muted-2 mb-0"><?= e($message) ?></p>
        </div>
        <?php if ($area !== ''): ?>
            <span class="badge badge-neutral"><?= e($area) ?></span>
        <?php endif; ?>
    </div>

    <?= View::partial('partials/empty-state', [
        'icon'  => 'sparkles',
        'title' => 'Under construction',
        'text'  => $message,
        'action' => '<a class="btn btn--ghost btn--sm" href="' . e(url('/dashboard')) . '">'
            . '<i class="icon icon--sm" data-icon="arrow-left">arrow-left</i> Back to dashboard</a>',
    ]) ?>
</section>
