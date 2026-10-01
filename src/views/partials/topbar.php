<?php
/**
 * UniGo - top bar.
 *
 * @var string      $pageTitle
 * @var string|null $pageSub
 * @var int         $unreadNotifications
 * @var array|null  $recentNotifications  latest few rows for the bell dropdown
 * @var string|null $topbarAction          raw HTML appended on the right
 */
declare(strict_types=1);

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Http;
use App\Core\Request;

$pageTitle = $pageTitle ?? (string) Request::instance()->str('q', '');
$pageTitle = $pageTitle !== '' ? $pageTitle : 'Dashboard';
$pageSub   = $pageSub ?? null;
$unread    = (int) ($unreadNotifications ?? 0);
$recent    = $recentNotifications ?? [];
$canSos    = Auth::hasRole('passenger', 'driver');
$hasSidebar = empty($navHidden);
?>
<header class="topbar">
    <?php if ($hasSidebar): ?>
        <button class="hamburger" id="sidebarToggle" type="button" aria-label="Open navigation" aria-expanded="false" aria-controls="sidebar">
            <i class="icon" data-icon="menu">menu</i>
        </button>
    <?php endif; ?>

    <div style="min-width:0">
        <p class="topbar__title text-truncate"><?= e($pageTitle) ?></p>
        <?php if ($pageSub): ?>
            <p class="topbar__sub text-truncate"><?= e($pageSub) ?></p>
        <?php endif; ?>
    </div>

    <div class="topbar__actions">
        <?= $topbarAction ?? '' ?>

        <?php if (Auth::check()): ?>
            <div class="dropdown" id="notifMenu">
                <a class="btn btn--ghost btn--icon bell" href="<?= e(url('/notifications')) ?>" aria-haspopup="true" aria-expanded="false" aria-label="Notifications<?= $unread > 0 ? ', ' . $unread . ' unread' : '' ?>">
                    <i class="icon" data-icon="bell">bell</i>
                    <?php if ($unread > 0): ?>
                        <span class="bell__count" data-unread="<?= (int) $unread ?>"><?= $unread > 9 ? '9+' : $unread ?></span>
                    <?php else: ?>
                        <span class="bell__count" data-unread="0" hidden>0</span>
                    <?php endif; ?>
                </a>

                <?php if ($recent): ?>
                    <div class="dropdown__menu dropdown__menu--wide" role="menu">
                        <p class="dropdown__heading">Latest notifications</p>
                        <?php foreach (array_slice($recent, 0, 5) as $item): ?>
                            <a class="dropdown__item dropdown__item--stack<?= empty($item['is_read']) ? ' is-unread' : '' ?>"
                               href="<?= e(url((string) ($item['link'] ?? '/notifications'))) ?>" role="menuitem">
                                <span class="notif-row">
                                    <i class="icon" data-icon="<?= e((string) ($item['icon'] ?? 'bell')) ?>">bell</i>
                                    <span class="notif-row__body">
                                        <span class="notif-row__title"><?= e((string) ($item['title'] ?? '')) ?></span>
                                        <?php if (!empty($item['message'])): ?>
                                            <span class="notif-row__text"><?= e((string) $item['message']) ?></span>
                                        <?php endif; ?>
                                    </span>
                                    <span class="notif-row__time"><?= e(time_ago($item['created_at'] ?? null)) ?></span>
                                </span>
                            </a>
                        <?php endforeach; ?>
                        <div class="dropdown__sep"></div>
                        <a class="dropdown__item" href="<?= e(url('/notifications')) ?>" role="menuitem">
                            <i class="icon" data-icon="bell">bell</i> View all notifications
                        </a>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($canSos): ?>
                <a class="btn btn--danger btn--sm only-desktop" href="<?= e(url('/emergency/new')) ?>">
                    <i class="icon" data-icon="siren">siren</i>
                    <span>SOS</span>
                </a>
            <?php endif; ?>

            <div class="dropdown" id="userMenu">
                <button class="btn btn--ghost btn--icon" type="button" aria-haspopup="true" aria-expanded="false" aria-label="Account menu">
                    <?php if (Auth::avatar()): ?>
                        <img class="avatar avatar--sm" src="<?= e((string) Auth::avatar()) ?>" alt="">
                    <?php else: ?>
                        <span class="avatar avatar--sm"><?= e(initials(Auth::name())) ?></span>
                    <?php endif; ?>
                </button>
                <div class="dropdown__menu" role="menu">
                    <div class="px-3 py-2" style="border-bottom:1px solid var(--border);margin-bottom:var(--sp-2)">
                        <p class="fw-600 text-truncate"><?= e(Auth::name()) ?></p>
                        <p class="text-xs text-muted-2 text-truncate"><?= e(Auth::email() ?? '') ?></p>
                    </div>
                    <a class="dropdown__item" href="<?= e(url('/profile')) ?>" role="menuitem">
                        <i class="icon" data-icon="user">user</i> My profile
                    </a>
                    <?php if (Auth::isStaff()): ?>
                        <a class="dropdown__item" href="<?= e(url(Auth::isAdmin() ? '/admin/settings' : '/operator/settings')) ?>" role="menuitem">
                            <i class="icon" data-icon="settings">settings</i> Settings
                        </a>
                    <?php endif; ?>
                    <a class="dropdown__item" href="<?= e(url('/support')) ?>" role="menuitem">
                        <i class="icon" data-icon="headset">headset</i> Help &amp; support
                    </a>
                    <div class="dropdown__sep"></div>
                    <form method="post" action="<?= e(url('/logout')) ?>" data-confirm="Sign out of UniGo?">
                        <?= Csrf::field() ?>
                        <button type="submit" class="dropdown__item dropdown__item--danger" role="menuitem">
                            <i class="icon" data-icon="log-out">log-out</i> Sign out
                        </button>
                    </form>
                </div>
            </div>
        <?php else: ?>
            <a class="btn btn--ghost btn--sm" href="<?= e(url('/login')) ?>">Sign in</a>
        <?php endif; ?>
    </div>
</header>
