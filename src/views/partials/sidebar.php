<?php
/**
 * UniGo - application sidebar (desktop rail / mobile off-canvas drawer).
 *
 * @var array $navBadges  optional path => badge count
 * @var bool  $navHidden  render nothing at all (landing pages, wizards)
 */
declare(strict_types=1);

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\View;

if (!empty($navHidden)) {
    return;
}

$groups = View::provider('partials/nav-items', ['navBadges' => $navBadges ?? []]);
$user   = Auth::user();
$avatar = Auth::avatar();
$role   = implode(' / ', Auth::roles());
?>
<aside class="sidebar" id="sidebar" aria-label="Main navigation">
    <div class="sidebar__brand">
        <span class="sidebar__logo" aria-hidden="true">U</span>
        <span>
            <span class="sidebar__name"><?= e(app_name()) ?></span>
            <span class="sidebar__role"><?= e($role !== '' ? $role : 'portal') ?></span>
        </span>
    </div>

    <nav class="sidebar__nav">
        <?php foreach ($groups as $group): ?>
            <div class="nav-group">
                <?php if (count($groups) > 1): ?>
                    <p class="nav-group__label"><?= e($group['label']) ?></p>
                <?php endif; ?>
                <?php foreach ($group['items'] as $item): ?>
                    <a class="nav-item<?= $item['active'] ?><?= !empty($item['danger']) ? ' nav-item--danger' : '' ?>"
                       href="<?= e(url($item['path'])) ?>"
                        <?= $item['active'] ? 'aria-current="page"' : '' ?>>
                        <i class="icon" data-icon="<?= e($item['icon']) ?>"><?= e($item['icon']) ?></i>
                        <span><?= e($item['label']) ?></span>
                        <?php if (!empty($item['count'])): ?>
                            <span class="nav-item__badge"><?= $item['count'] > 99 ? '99+' : (int) $item['count'] ?></span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>

        <div class="nav-group">
            <form method="post" action="<?= e(url('/logout')) ?>" data-confirm="Sign out of UniGo?">
                <?= Csrf::field() ?>
                <button type="submit" class="nav-item nav-item--danger" style="width:100%;border:0;background:none;cursor:pointer;text-align:left">
                    <i class="icon" data-icon="log-out">log-out</i>
                    <span>Sign out</span>
                </button>
            </form>
        </div>
    </nav>

    <?php if ($user): ?>
        <div class="sidebar__footer">
            <a class="sidebar__user" href="<?= e(url('/profile')) ?>">
                <?php if ($avatar): ?>
                    <img class="avatar" src="<?= e($avatar) ?>" alt="" width="36" height="36">
                <?php else: ?>
                    <span class="avatar" aria-hidden="true"><?= e(initials(Auth::name())) ?></span>
                <?php endif; ?>
                <span style="min-width:0">
                    <span class="sidebar__user-name d-block text-truncate"><?= e(Auth::name()) ?></span>
                    <span class="sidebar__user-mail d-block text-truncate"><?= e((string) ($user['email'] ?? '')) ?></span>
                </span>
            </a>
        </div>
    <?php endif; ?>
</aside>
<div class="sidebar-backdrop" id="sidebarBackdrop" data-sidebar-close></div>
