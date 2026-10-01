<?php
/**
 * UniGo - stat tile.
 *
 * @var string      $label
 * @var string      $value
 * @var string|null $icon
 * @var string      $tone      primary|success|warning|danger|info
 * @var string|null $meta      small line under the value
 * @var string|null $trend     up|down (colours the meta line)
 * @var string|null $href      wrap the tile in a link
 * @var bool        $demo      append a simulated-data marker
 */
declare(strict_types=1);

$label = $label ?? '';
$value = $value ?? '0';
$icon  = $icon ?? null;
$tone  = $tone ?? 'primary';
$meta  = $meta ?? null;
$trend = $trend ?? null;
$href  = $href ?? null;
$demo  = $demo ?? false;
$mod   = $tone === 'primary' ? '' : ' stat--' . e($tone);
?>
<?php if ($href !== null): ?>
    <a class="stat<?= $mod ?> card--hover" href="<?= e(url($href)) ?>" style="text-decoration:none">
<?php else: ?>
    <div class="stat<?= $mod ?>">
<?php endif; ?>

    <?php if ($icon): ?>
        <span class="stat__icon"><i class="icon" data-icon="<?= e($icon) ?>"><?= e($icon) ?></i></span>
    <?php endif; ?>

    <span class="stat__label">
        <?= e($label) ?>
        <?php if ($demo): ?>
            <span class="badge badge-demo" style="margin-left:auto">SIM</span>
        <?php endif; ?>
    </span>

    <span class="stat__value"><?= e($value) ?></span>

    <?php if ($meta !== null): ?>
        <span class="stat__meta<?= $trend ? ' ' . e($trend) : '' ?>">
            <?php if ($trend === 'up'): ?><i class="icon icon--xs" data-icon="trending-up">trending-up</i><?php endif; ?>
            <?php if ($trend === 'down'): ?><i class="icon icon--xs" data-icon="trending-down">trending-down</i><?php endif; ?>
            <?= e($meta) ?>
        </span>
    <?php endif; ?>

<?= $href !== null ? '</a>' : '</div>' ?>
