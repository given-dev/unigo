<?php
/**
 * UniGo - pagination control.
 *
 * @var \App\Core\Paginator $paginator
 * @var string             $paginatorParam  query string key
 * @var array              $paginatorLabels prev/next text
 */
declare(strict_types=1);

use App\Core\Paginator;

if (!isset($paginator) || !$paginator instanceof Paginator) {
    return;
}

$param  = $paginatorParam ?? 'page';
$labels = $paginatorLabels ?? ['prev' => 'Previous', 'next' => 'Next'];

/** Page numbers with ellipsis: 1 ... 4 5 6 ... 12 */
$window = 2;
$pages  = [];
for ($i = 1; $i <= $paginator->lastPage; $i++) {
    if ($i === 1 || $i === $paginator->lastPage
        || ($i >= $paginator->page - $window && $i <= $paginator->page + $window)) {
        $pages[] = $i;
    } elseif (end($pages) !== '...') {
        $pages[] = '...';
    }
}
?>
<?php if ($paginator->total > 0): ?>
    <nav class="pagination" aria-label="Pagination">
        <p class="pagination__info">
            Showing <strong><?= (int) $paginator->from ?></strong>&ndash;<strong><?= (int) $paginator->to ?></strong>
            of <strong><?= number_format((float) $paginator->total, 0, '.', ',') ?></strong>
        </p>

        <?php if ($paginator->hasPages()): ?>
            <div class="pagination__list">
                <a class="pagination__link<?= $paginator->onFirstPage() ? ' is-disabled' : '' ?>"
                   href="<?= e($paginator->url($paginator->page - 1, $param)) ?>"
                   rel="prev"<?= $paginator->onFirstPage() ? ' aria-disabled="true" tabindex="-1"' : '' ?>>
                    <i class="icon icon--sm" data-icon="chevron-left">chevron-left</i>
                </a>

                <?php foreach ($pages as $page): ?>
                    <?php if ($page === '...'): ?>
                        <span class="pagination__ellipsis">&hellip;</span>
                    <?php else: ?>
                        <a class="pagination__link<?= $page === $paginator->page ? ' is-active' : '' ?>"
                           href="<?= e($paginator->url((int) $page, $param)) ?>"
                            <?= $page === $paginator->page ? 'aria-current="page"' : '' ?>><?= (int) $page ?></a>
                    <?php endif; ?>
                <?php endforeach; ?>

                <a class="pagination__link<?= $paginator->onLastPage() ? ' is-disabled' : '' ?>"
                   href="<?= e($paginator->url($paginator->page + 1, $param)) ?>"
                   rel="next"<?= $paginator->onLastPage() ? ' aria-disabled="true" tabindex="-1"' : '' ?>>
                    <i class="icon icon--sm" data-icon="chevron-right">chevron-right</i>
                </a>
            </div>
        <?php endif; ?>
    </nav>
<?php endif; ?>
