<?php
/**
 * UniGo - public trip schedule / route directory.
 *
 * @var array                       $routes
 * @var \App\Core\Paginator|null    $paginator
 * @var string                      $search
 */
declare(strict_types=1);

use App\Core\Auth;
use App\Core\View;

$routes    = $routes ?? [];
$paginator = $paginator ?? null;
$search    = $search ?? '';
?>
<section class="section">
    <div class="section__head">
        <div>
            <h2 class="section__title">Trip schedule</h2>
            <p class="text-sm text-muted-2 mb-0">
                Browse active routes. Sign in to book a seat or track a vehicle.
            </p>
        </div>
    </div>

    <form method="get" action="<?= e(url('/schedule')) ?>" class="search-panel mb-5">
        <div class="search-panel__grid" style="grid-template-columns:1fr auto">
            <div class="search-field">
                <i class="icon search-field__icon" data-icon="search">search</i>
                <input class="input" type="search" name="q" value="<?= e($search) ?>"
                       placeholder="Search by route, origin or destination">
            </div>
            <button class="btn btn--primary" type="submit">
                <i class="icon" data-icon="search">search</i> Search
            </button>
        </div>
    </form>

    <?php if (empty($routes)): ?>
        <?= View::partial('partials/empty-state', [
            'icon'  => 'route',
            'title' => $search !== '' ? 'No routes matched your search' : 'No routes published yet',
            'text'  => $search !== ''
                ? 'Try a different town or route name.'
                : 'Operators have not published any schedules yet.',
            'action' => $search !== ''
                ? '<a class="btn btn--ghost" href="' . e(url('/schedule')) . '">Clear search</a>'
                : null,
        ]) ?>
    <?php else: ?>
        <div class="grid grid-3 gap-3">
            <?php foreach ($routes as $route): ?>
                <article class="card card--hover">
                    <div class="card__body">
                        <div class="flex items-center justify-between mb-3">
                            <span class="badge badge-primary"><?= e($route['route_code'] ?? '') ?></span>
                            <?php if (!empty($route['status'])): ?>
                                <span class="badge badge-<?= e(status_tone($route['status'])) ?>">
                                    <?= e(status_label($route['status'])) ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        <h3 class="card__title">
                            <?= e($route['origin_name'] ?? '') ?>
                            <i class="icon icon--xs" data-icon="arrow-right">arrow-right</i>
                            <?= e($route['destination_name'] ?? '') ?>
                        </h3>
                        <p class="text-sm text-muted-2"><?= e($route['company_name'] ?? app_name()) ?></p>
                        <div class="flex items-center justify-between text-sm mt-3">
                            <span><i class="icon icon--xs" data-icon="route">route</i> <?= e((string) ($route['distance_km'] ?? '0')) ?> km</span>
                            <span><i class="icon icon--xs" data-icon="clock">clock</i> <?= e(duration_minutes((int) ($route['duration_minutes'] ?? 0))) ?></span>
                            <span class="fw-700 text-primary"><?= e(money($route['base_fare'] ?? 0)) ?></span>
                        </div>
                    </div>
                    <div class="card__footer">
                        <?php if (Auth::check()): ?>
                            <a class="btn btn--primary btn--sm btn--block" href="<?= e(url('/home')) ?>">
                                <i class="icon icon--sm" data-icon="ticket">ticket</i> Book a seat
                            </a>
                        <?php else: ?>
                            <a class="btn btn--outline btn--sm btn--block" href="<?= e(url('/login')) ?>">
                                <i class="icon icon--sm" data-icon="login">login</i> Sign in to book
                            </a>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>

        <?= View::partial('partials/pagination', ['paginator' => $paginator]) ?>
    <?php endif; ?>
</section>
