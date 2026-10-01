<?php
/**
 * UniGo - mobile bottom navigation.
 *
 * Renders the first four entries of the role nav map plus a profile shortcut,
 * so the most used screens stay one thumb tap away on phones.
 */
declare(strict_types=1);

use App\Core\Auth;
use App\Core\Request;
use App\Core\View;

$current = Request::instance()->path();
$flat    = [];
foreach (View::provider('partials/nav-items', ['navBadges' => $navBadges ?? []]) as $group) {
    foreach ($group['items'] as $item) {
        if ($item['path'] === '/profile') {
            continue;
        }
        $flat[] = $item;
        if (count($flat) >= 4) {
            break 2;
        }
    }
}

$items = $flat;
if (Auth::check()) {
    $items[] = [
        'path'   => '/profile',
        'label'  => 'Profile',
        'icon'   => 'user',
        'active' => nav_active('/profile', $current),
        'count'  => 0,
    ];
}
if ($items === []) {
    return;
}
?>
<nav class="bottom-nav" aria-label="Quick navigation">
    <?php foreach ($items as $item): ?>
        <a class="bottom-nav__item<?= $item['active'] ?>"
           href="<?= e(url($item['path'])) ?>"
            <?= $item['active'] ? 'aria-current="page"' : '' ?>>
            <i class="icon" data-icon="<?= e($item['icon']) ?>"><?= e($item['icon']) ?></i>
            <span class="text-truncate" style="max-width:100%"><?= e($item['label']) ?></span>
            <?php if (!empty($item['count'])): ?>
                <span class="nav-item__badge"><?= (int) $item['count'] ?></span>
            <?php endif; ?>
        </a>
    <?php endforeach; ?>
</nav>
