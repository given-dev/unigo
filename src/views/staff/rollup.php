<?php
/**
 * UniGo - national regulator rollup.
 *
 * The national office (authority account with no district) sees grouped
 * summaries instead of raw records: tile totals plus one grouped table.
 *
 * @var array $summary  tiles, title, note, cols, right, rows, resource
 */
declare(strict_types=1);

use App\Core\View;

$tiles = $summary['tiles'] ?? [];
$cols  = $summary['cols'] ?? [];
$right = $summary['right'] ?? [];
$rows  = $summary['rows'] ?? [];
$title = $summary['title'] ?? 'Summary';
$note  = $summary['note'] ?? '';
$empty = match ($summary['resource'] ?? '') {
    'emergencies' => 'No emergency alerts have been raised yet.',
    'complaints'  => 'No complaints have been filed yet.',
    'operators'   => 'No operators are registered yet.',
    default       => 'Nothing to summarise yet.',
};
?>

<?php if ($tiles): ?>
<div class="grid grid-3 gap-3 mb-4">
    <?php foreach ($tiles as $tile): ?>
        <?= View::partial('partials/stat-card', [
            'label' => $tile[0],
            'value' => (string) $tile[1],
            'icon'  => $tile[2] ?? null,
            'tone'  => $tile[3] ?? 'primary',
        ]) ?>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($cols): ?>
<div class="card"><div class="card__body">
    <h3 class="card__title"><?= e($title) ?></h3>
    <?php if ($note): ?><p class="text-sm text-muted-2"><?= e($note) ?></p><?php endif; ?>
    <?php if ($rows): ?>
        <div class="table-wrap" style="overflow-x:auto"><table class="table">
            <thead><tr><?php foreach ($cols as $key => $label): ?><th class="<?= in_array($key, $right, true) ? 'num' : '' ?>"><?= e($label) ?></th><?php endforeach; ?></tr></thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr><?php foreach ($cols as $key => $label): ?>
                    <td class="<?= in_array($key, $right, true) ? 'num' : '' ?>"><?= e((string) ($row[$key] ?? '—')) ?></td>
                <?php endforeach; ?></tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php else: ?>
        <p class="text-sm text-muted-2 mb-0"><?= e($empty) ?></p>
    <?php endif; ?>
</div></div>
<?php endif; ?>