<?php
/**
 * UniGo - passenger complaints list + file form.
 *
 * @var array $complaints
 * @var \App\Core\Paginator|null $paginator
 * @var array $bookings
 * @var array $categories
 */
declare(strict_types=1);

use App\Core\Csrf;
use App\Core\View;

$complaints = $complaints ?? [];
$paginator  = $paginator ?? null;
$bookings   = $bookings ?? [];
$categories = $categories ?? [];
?>
<div class="grid grid-3 gap-3">
    <div style="grid-column:span 2">
        <section class="section">
            <div class="section__head">
                <div>
                    <h2 class="section__title">My complaints</h2>
                    <p class="text-sm text-muted-2 mb-0">Reports you have filed and their status.</p>
                </div>
            </div>

            <?php if (empty($complaints)): ?>
                <?= View::partial('partials/empty-state', [
                    'icon'  => 'message',
                    'title' => 'No complaints filed',
                    'text'  => 'If something went wrong on a trip, let us know using the form.',
                ]) ?>
            <?php else: ?>
                <div class="grid gap-3">
                    <?php foreach ($complaints as $c): ?>
                        <article class="card">
                            <div class="card__body">
                                <div class="flex items-center justify-between mb-2">
                                    <span class="badge badge-neutral"><?= e($c['reference'] ?? '') ?></span>
                                    <span class="badge badge-<?= e(status_tone($c['status'] ?? '')) ?>"><?= e(status_label($c['status'] ?? '')) ?></span>
                                </div>
                                <h3 class="card__title"><?= e(status_label($c['category'] ?? '')) ?></h3>
                                <p class="text-sm text-muted-2"><?= e($c['description'] ?? '') ?></p>
                                <?php if (!empty($c['resolution'])): ?>
                                    <p class="text-sm"><strong>Resolution:</strong> <?= e($c['resolution']) ?></p>
                                <?php endif; ?>
                                <div class="flex items-center gap-3 text-xs text-muted-2 mt-2">
                                    <span><?= e(dt($c['created_at'] ?? null)) ?></span>
                                    <span class="badge badge-<?= e(status_tone($c['severity'] ?? '')) ?>"><?= e(status_label($c['severity'] ?? '')) ?></span>
                                    <?php if (!empty($c['trip_code'])): ?><span><?= e($c['trip_code']) ?></span><?php endif; ?>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
                <?= View::partial('partials/pagination', ['paginator' => $paginator]) ?>
            <?php endif; ?>
        </section>
    </div>

    <div>
        <article class="card">
            <div class="card__body">
                <h3 class="card__title mb-3">File a complaint</h3>
                <form method="post" action="<?= e(url('/complaints')) ?>">
                    <?= Csrf::field() ?>
                    <div class="field mb-2">
                        <label class="label" for="category">Category</label>
                        <select class="select" id="category" name="category">
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= e($cat) ?>"><?= e(status_label($cat)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field mb-2">
                        <label class="label" for="severity">Severity</label>
                        <select class="select" id="severity" name="severity">
                            <option value="low">Low</option>
                            <option value="medium" selected>Medium</option>
                            <option value="high">High</option>
                        </select>
                    </div>
                    <div class="field mb-2">
                        <label class="label" for="booking_id">Related booking (optional)</label>
                        <select class="select" id="booking_id" name="booking_id">
                            <option value="">None</option>
                            <?php foreach ($bookings as $b): ?>
                                <option value="<?= (int) ($b['id'] ?? 0) ?>">
                                    <?= e($b['reference'] ?? '') ?> &mdash; <?= e($b['origin_name'] ?? '') ?> to <?= e($b['destination_name'] ?? '') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field mb-3">
                        <label class="label" for="description">What happened?</label>
                        <textarea class="textarea" id="description" name="description" rows="4" maxlength="1000" required></textarea>
                    </div>
                    <button class="btn btn--primary btn--block" type="submit">
                        <i class="icon" data-icon="send">send</i> Submit complaint
                    </button>
                </form>
            </div>
        </article>
    </div>
</div>
