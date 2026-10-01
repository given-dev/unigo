<?php
/**
 * UniGo - notifications inbox.
 *
 * @var array $items
 * @var \App\Core\Paginator|null $paginator
 * @var string $filter
 * @var int $unread
 */
declare(strict_types=1);

use App\Core\Csrf;
use App\Core\View;

$items     = $items ?? [];
$paginator = $paginator ?? null;
$filter    = $filter ?? '';
$unread    = (int) ($unread ?? 0);
?>
<section class="section">
    <div class="section__head">
        <div>
            <h2 class="section__title">Notifications</h2>
            <p class="text-sm text-muted-2 mb-0"><?= (int) $unread ?> unread</p>
        </div>
        <form method="post" action="<?= e(url('/notifications/read-all')) ?>">
            <?= Csrf::field() ?>
            <button class="btn btn--ghost btn--sm" type="submit">
                <i class="icon icon--sm" data-icon="check">check</i> Mark all read
            </button>
        </form>
    </div>

    <div class="chips mb-4">
        <a class="chip<?= $filter === '' ? ' is-active' : '' ?>" href="<?= e(url('/notifications')) ?>">All</a>
        <a class="chip<?= $filter === 'unread' ? ' is-active' : '' ?>" href="<?= e(url('/notifications?filter=unread')) ?>">Unread</a>
    </div>

    <?php if (empty($items)): ?>
        <?= View::partial('partials/empty-state', [
            'icon'  => 'bell',
            'title' => 'No notifications',
            'text'  => 'You are all caught up.',
        ]) ?>
    <?php else: ?>
        <div class="card card--flat">
            <?php foreach ($items as $n): ?>
                <?php
                $read  = !empty($n['is_read']);
                $tone  = in_array($n['severity'] ?? 'info', ['success', 'warning', 'danger', 'info'], true)
                    ? (string) $n['severity'] : 'info';
                ?>
                <article class="notif notif--<?= e($tone) ?><?= $read ? '' : ' is-unread' ?>">
                    <span class="notif__icon">
                        <i class="icon" data-icon="<?= e($n['icon'] ?? 'bell') ?>"><?= e($n['icon'] ?? 'bell') ?></i>
                    </span>
                    <div class="notif__body">
                        <div class="flex items-center justify-between gap-2">
                            <span class="notif__title"><?= e($n['title'] ?? '') ?></span>
                            <span class="notif__time"><?= e(time_ago($n['created_at'] ?? null)) ?></span>
                        </div>
                        <?php if (!empty($n['message'])): ?>
                            <p class="notif__text"><?= e($n['message']) ?></p>
                        <?php endif; ?>
                        <div class="flex items-center gap-2 mt-2">
                            <?php if (!empty($n['link'])): ?>
                                <a class="btn btn--ghost btn--xs" href="<?= e(url($n['link'])) ?>">Open</a>
                            <?php endif; ?>
                            <?php if (!$read): ?>
                                <form method="post" action="<?= e(url('/notifications/' . (int) ($n['id'] ?? 0) . '/read')) ?>">
                                    <?= Csrf::field() ?>
                                    <button class="btn btn--ghost btn--xs" type="submit">Mark read</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>

        <?= View::partial('partials/pagination', ['paginator' => $paginator]) ?>
    <?php endif; ?>
</section>
