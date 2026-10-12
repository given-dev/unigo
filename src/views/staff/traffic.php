<?php
/**
 * UniGo - authority traffic & congestion workspace.
 *
 * @var array  $traffic  reports, congestion, diversions
 * @var array  $choices  routes for the create form
 * @var string $path     form action path (e.g. /authority/traffic)
 */
declare(strict_types=1);

use App\Core\Csrf;
use App\Core\View;

$reports    = $traffic['reports'] ?? [];
$congestion = $traffic['congestion'] ?? [];
$diversions = $traffic['diversions'] ?? [];
$routes     = $choices['routes'] ?? [];

$open     = count(array_filter($reports, static fn ($r) => ($r['status'] ?? '') !== 'cleared'));
$critical = count(array_filter($reports, static fn ($r) => ($r['severity'] ?? '') === 'critical'));
$avgDelay = $congestion ? (int) round(array_sum(array_map(static fn ($c) => (float) $c['avg_delay'], $congestion)) / count($congestion)) : 0;
$ai = $traffic['ai'] ?? null;
$best = $ai['best'] ?? null;
?>

<?php if (!empty($traffic['map'])): ?>
<div class="card mb-4"><div id="trafficMap" class="map" data-map='<?= e((string) json_encode($traffic['map'])) ?>' style="min-height:420px"></div></div>
<?php endif; ?>

<?php if ($ai && $best): ?>
<?php $tone = match ($best['label']) { 'Clear' => 'success', 'Light' => 'info', 'Busy' => 'warning', 'Congested' => 'danger', default => 'primary' }; ?>
<div class="card mb-4"><div class="card__body">
    <div class="flex-between gap-2 mb-1">
        <h3 class="card__title mb-0"><span class="badge badge-primary">AI</span> Route advisor</h3>
        <span class="text-xs text-muted-2">linear model v1 &middot; <?= (int) ($ai['feedback'] ?? 0) ?> feedback sample(s)</span>
    </div>
    <p class="mb-1">Recommended free route: <strong><?= e($best['route']['name']) ?></strong>
        <span class="badge badge-<?= e($tone) ?>"><?= e($best['label']) ?></span>
        <span class="text-xs text-muted-2">congestion cost <?= e((string) $best['cost']) ?></span></p>
    <p class="text-sm text-muted-2"><?= e($ai['reason']) ?></p>
    <div class="flex gap-2 mt-2">
        <form method="post" action="<?= e(url($path)) ?>"><?= Csrf::field() ?><input type="hidden" name="action" value="ai_feedback"><input type="hidden" name="helpful" value="1"><button class="btn btn--secondary btn--sm" type="submit">Helpful</button></form>
        <form method="post" action="<?= e(url($path)) ?>"><?= Csrf::field() ?><input type="hidden" name="action" value="ai_feedback"><input type="hidden" name="helpful" value="0"><button class="btn btn--ghost btn--sm" type="submit">Not helpful</button></form>
    </div>
    <details class="mt-3"><summary class="text-sm">How the AI decided (every active route)</summary>
    <table class="table mt-2"><thead><tr><th>Route</th><th>Open</th><th>Critical</th><th>Avg delay</th><th>Cost</th><th>Grade</th></tr></thead><tbody>
    <?php foreach ($ai['ranking'] as $i => $row): ?>
        <tr<?= $i === 0 ? ' style="font-weight:600"' : '' ?>>
            <td><?= e($row['route']['name']) ?><?= $i === 0 ? ' <span class="text-xs text-muted-2">(recommended)</span>' : '' ?></td>
            <td><?= (int) $row['features']['open'] ?></td>
            <td><?= (int) $row['features']['critical'] ?></td>
            <td><?= (int) round($row['features']['avg_delay']) ?> min</td>
            <td><?= e((string) $row['cost']) ?></td>
            <td><?= e($row['label']) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody></table>
    <p class="text-xs text-muted-2">Weights:
        <?php foreach ($ai['weights'] as $k => $v): ?><code><?= e($k) ?>=<?= e((string) $v) ?></code> <?php endforeach; ?>.
        These shift when you give feedback - a tiny, honest example of "training". The score is
        <code>open&times;w1 + critical&times;w2 + high&times;w3 + avg_delay&times;w4 + total_delay&times;w5</code>; lowest cost wins.</p>
    </details>
</div></div>
<?php endif; ?>

<div class="grid grid-3 gap-3 mb-4">
    <?= View::partial('partials/stat-card', ['label' => 'Active reports', 'value' => (string) $open, 'icon' => 'alert', 'tone' => $open ? 'warning' : 'primary']) ?>
    <?= View::partial('partials/stat-card', ['label' => 'Critical', 'value' => (string) $critical, 'icon' => 'siren', 'tone' => $critical ? 'danger' : 'primary']) ?>
    <?= View::partial('partials/stat-card', ['label' => 'Avg delay', 'value' => $avgDelay . ' min', 'icon' => 'clock']) ?>
</div>

<details class="card mb-4"><summary class="card__body">Log a traffic report</summary>
<form class="card__body" method="post" action="<?= e(url($path)) ?>">
<?= Csrf::field() ?><input type="hidden" name="action" value="create">
<div class="form-grid">
    <div class="field"><label class="label" for="traffic-route">Route (optional)</label>
        <select class="select" name="route_id" id="traffic-route">
            <option value="">Not route-specific</option>
            <?php foreach ($routes as $route): ?><option value="<?= (int) $route['id'] ?>"><?= e($route['label']) ?></option><?php endforeach; ?>
        </select></div>
    <div class="field"><label class="label" for="traffic-location">Location</label>
        <input class="input" id="traffic-location" name="location_name" maxlength="150" placeholder="e.g. Namboole junction"></div>
    <div class="field"><label class="label" for="traffic-category">Category</label>
        <select class="select" name="category" id="traffic-category">
            <?php foreach (\App\Models\TrafficModel::CATEGORIES as $cat): ?><option value="<?= e($cat) ?>"><?= e(ucwords(str_replace('_', ' ', $cat))) ?></option><?php endforeach; ?>
        </select></div>
    <div class="field"><label class="label" for="traffic-severity">Severity</label>
        <select class="select" name="severity" id="traffic-severity">
            <?php foreach (\App\Models\TrafficModel::SEVERITIES as $sev): ?><option value="<?= e($sev) ?>" <?= $sev === 'medium' ? 'selected' : '' ?>><?= e(ucfirst($sev)) ?></option><?php endforeach; ?>
        </select></div>
    <div class="field"><label class="label" for="traffic-delay">Delay (minutes)</label>
        <input class="input" id="traffic-delay" name="delay_minutes" type="number" min="0" max="1440" value="0"></div>
</div>
<div class="field"><label class="label" for="traffic-description">Details</label>
    <input class="input" id="traffic-description" name="description" maxlength="500" placeholder="What is causing the delay?"></div>
<p class="text-sm text-muted-2">High or critical reports notify everyone booked on the route with a suggested alternate route.</p>
<button class="btn btn--primary mt-2" type="submit">Log report</button>
</form></details>

<?php if ($diversions): ?>
<div class="card mb-4"><div class="card__body">
    <h3 class="card__title">Suggested diversions</h3>
    <?php foreach ($diversions as $group): ?>
        <div class="mb-3">
            <p class="mb-1"><strong><?= e($group['route']['name']) ?></strong>
                <span class="text-xs text-muted-2"><?= e($group['report']['severity'] ?? '') ?> · <?= e(ucfirst((string) ($group['report']['category'] ?? ''))) ?></span></p>
            <div class="chips">
                <?php foreach ($group['alternates'] as $alt): ?>
                    <span class="chip"><?= e($alt['name']) ?>
                        <span class="text-xs text-muted-2"><?= e($alt['origin_name']) ?>&rarr;<?= e($alt['destination_name']) ?> · <?= (int) round((float) $alt['distance_km']) ?> km</span>
                    </span>
                <?php endforeach; ?>
            </div>
            <form method="post" action="<?= e(url($path)) ?>" class="mt-2" data-confirm="Notify affected users of this diversion?">
                <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $group['report']['id'] ?>"><input type="hidden" name="action" value="diversion">
                <button class="btn btn--secondary btn--sm" type="submit">Notify affected users</button>
            </form>
        </div>
    <?php endforeach; ?>
</div></div>
<?php endif; ?>

<div class="card mb-4" style="overflow-x:auto"><div class="card__body">
    <h3 class="card__title">Reports</h3>
    <table class="table"><thead><tr><th>Route</th><th>Location</th><th>Category</th><th>Severity</th><th>Delay</th><th>Status</th><th>Actions</th></tr></thead><tbody>
    <?php foreach ($reports as $r): ?>
        <tr>
            <td><?= e($r['route_name'] ?? '—') ?></td>
            <td><?= e($r['location_name'] ?: '—') ?></td>
            <td><?= e(ucfirst((string) $r['category'])) ?></td>
            <td><span class="badge badge-<?= e(status_tone($r['severity'])) ?>"><?= e(ucfirst((string) $r['severity'])) ?></span></td>
            <td><?= (int) $r['delay_minutes'] ?> min</td>
            <td><?= e(ucfirst((string) $r['status'])) ?></td>
            <td>
                <form method="post" action="<?= e(url($path)) ?>" class="flex gap-2">
                    <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="action" value="status">
                    <select class="select" name="status" aria-label="Status">
                        <?php foreach (\App\Models\TrafficModel::STATUSES as $st): ?><option value="<?= e($st) ?>" <?= $st === $r['status'] ? 'selected' : '' ?>><?= e(ucfirst($st)) ?></option><?php endforeach; ?>
                    </select>
                    <button class="btn btn--secondary btn--sm" type="submit">Update</button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$reports): ?><tr><td colspan="7">No traffic reports yet.</td></tr><?php endif; ?>
    </tbody></table>
</div></div>

<?php if (!empty($traffic['map'])): View::start('scripts'); ?><script>
document.addEventListener('DOMContentLoaded', function () {
    var el = document.getElementById('trafficMap');
    if (el && window.UniGo && UniGo.map) UniGo.map('trafficMap', JSON.parse(el.dataset.map));
});
</script><?php View::stop(); endif; ?>
