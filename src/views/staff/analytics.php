<?php
/**
 * UniGo - authority network analytics.
 *
 * @var array $analytics  top_routes, operators, drivers, transport, trip_status, areas
 */
declare(strict_types=1);

use App\Core\View;

$topRoutes = $analytics['top_routes'] ?? [];
$operators = $analytics['operators'] ?? [];
$drivers   = $analytics['drivers'] ?? [];
$transport = $analytics['transport'] ?? [];
$tripMix   = $analytics['trip_status'] ?? [];
$areas     = $analytics['areas'] ?? [];

$routeLabels  = array_map(static fn ($r) => (string) $r['name'], $topRoutes);
$routeRevenue = array_map(static fn ($r) => (float) $r['revenue'], $topRoutes);
$routeBookings = array_map(static fn ($r) => (int) $r['bookings'], $topRoutes);

$statusLabels = array_map(static fn ($r) => ucwords(str_replace('_', ' ', (string) $r['label'])), $tripMix);
$statusTotals = array_map(static fn ($r) => (int) $r['total'], $tripMix);

$transportLabels = array_map(static fn ($r) => ucwords(str_replace('_', ' ', (string) $r['label'])), $transport);
$transportTotals = array_map(static fn ($r) => (int) $r['total'], $transport);

$operatorLabels = array_map(static fn ($r) => (string) $r['company_name'], $operators);
$operatorBookings = array_map(static fn ($r) => (int) $r['bookings'], $operators);

$areaLabels = array_map(static fn ($r) => (string) $r['label'], $areas);
$areaTotals = array_map(static fn ($r) => (int) $r['total'], $areas);

$fare       = $analytics['fare'] ?? [];
$fareTotals = $analytics['fare_totals'] ?? ['collected' => 0, 'bookings' => 0, 'overcharge' => 0];
$currency   = 'UGX';
?>

<div class="grid grid-3 gap-3 mb-4">
    <?= View::partial('partials/stat-card', ['label' => 'Routes analysed', 'value' => (string) count($topRoutes), 'icon' => 'route']) ?>
    <?= View::partial('partials/stat-card', ['label' => 'Operators ranked', 'value' => (string) count($operators), 'icon' => 'building']) ?>
    <?= View::partial('partials/stat-card', ['label' => 'Drivers ranked', 'value' => (string) count($drivers), 'icon' => 'badge']) ?>
</div>

<div class="grid grid-2 gap-3 mb-4">
    <div class="card"><div class="card__body"><h3 class="card__title">Top routes by revenue</h3>
        <div class="chart-box chart-box--md"><canvas id="topRoutesChart"></canvas></div></div></div>
    <div class="card"><div class="card__body"><h3 class="card__title">Trips by status</h3>
        <div class="chart-box chart-box--md"><canvas id="tripStatusChart"></canvas></div></div></div>
</div>

<div class="grid grid-3 gap-3 mb-4">
    <?= View::partial('partials/stat-card', ['label' => 'Fare collected (30 days)', 'value' => $currency . ' ' . number_format((float) $fareTotals['collected']), 'icon' => 'coins']) ?>
    <?= View::partial('partials/stat-card', ['label' => 'Paid bookings', 'value' => number_format((int) $fareTotals['bookings']), 'icon' => 'ticket']) ?>
    <?= View::partial('partials/stat-card', ['label' => 'Overcharging reports', 'value' => number_format((int) $fareTotals['overcharge']), 'icon' => 'alert']) ?>
</div>

<div class="card mb-4"><div class="card__body">
    <h3 class="card__title">Fare revenue &amp; tariff compliance</h3>
    <p class="text-sm text-muted-2">Approved fare is the regulated route tariff; operators charging above it are flagged.</p>
    <?php if ($fare): ?>
        <div class="table-wrap" style="overflow-x:auto"><table class="table">
            <thead><tr>
                <th>Route</th>
                <th class="num">Approved fare</th>
                <th class="num">Avg charged</th>
                <th class="num">Collected (30d)</th>
                <th class="num">Bookings</th>
                <th class="num">Overcharge reports</th>
            </tr></thead>
            <tbody>
            <?php foreach ($fare as $r):
                $approved = (float) $r['approved_fare'];
                $charged  = (float) $r['avg_charged_fare'];
                $over     = $approved > 0 && $charged > $approved * 1.05;
            ?>
                <tr>
                    <td><?= e($r['name']) ?> <span class="text-sm text-muted-2"><?= e($r['route_code']) ?></span></td>
                    <td class="num"><?= $currency ?> <?= number_format($approved) ?></td>
                    <td class="num"><?= $charged > 0 ? $currency . ' ' . number_format($charged) : '-' ?><?php if ($over): ?> <span class="badge badge-danger">above tariff</span><?php endif; ?></td>
                    <td class="num"><?= $currency ?> <?= number_format((float) $r['collected']) ?></td>
                    <td class="num"><?= (int) $r['bookings'] ?></td>
                    <td class="num"><?= (int) $r['overcharge_complaints'] ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php else: ?>
        <p class="text-sm text-muted-2 mb-0">No active routes in your area yet.</p>
    <?php endif; ?>
</div></div>

<div class="grid grid-2 gap-3 mb-4">
    <div class="card"><div class="card__body"><h3 class="card__title">Demand by transport type</h3>
        <div class="chart-box chart-box--md"><canvas id="transportChart"></canvas></div></div></div>
    <div class="card"><div class="card__body"><h3 class="card__title">Bookings by operator</h3>
        <div class="chart-box chart-box--md"><canvas id="operatorChart"></canvas></div></div></div>
</div>

<div class="grid grid-2 gap-3 mb-4">
    <div class="card"><div class="card__body"><h3 class="card__title">Trips by origin (30 days)</h3>
        <div class="chart-box chart-box--md"><canvas id="areaChart"></canvas></div></div></div>
    <div class="card"><div class="card__body"><h3 class="card__title">Top drivers</h3>
        <?php if ($drivers): ?>
            <div style="overflow-x:auto"><table class="table"><thead><tr><th>Driver</th><th>Operator</th><th>Trips</th><th>Rating</th></tr></thead><tbody>
            <?php foreach ($drivers as $d): ?>
                <tr>
                    <td><?= e(trim(($d['first_name'] ?? '') . ' ' . ($d['last_name'] ?? ''))) ?></td>
                    <td><?= e($d['company_name'] ?? 'Independent') ?></td>
                    <td><?= (int) $d['total_trips'] ?></td>
                    <td><?= number_format((float) $d['rating_avg'], 1) ?> (<?= (int) $d['rating_count'] ?>)</td>
                </tr>
            <?php endforeach; ?>
            </tbody></table></div>
        <?php else: ?>
            <p class="text-sm text-muted-2 mb-0">No completed trips have been recorded yet.</p>
        <?php endif; ?>
    </div>
</div>

<?php View::start('scripts'); ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (!window.UniGo || !window.UniGo.chart) return;
    UniGo.chart('topRoutesChart', {
        type: 'bar',
        data: { labels: <?= View::json($routeLabels) ?>, datasets: [{ label: 'Revenue', data: <?= View::json($routeRevenue) ?> }] },
        options: { currency: true }
    });
    UniGo.chart('tripStatusChart', {
        type: 'doughnut',
        data: { labels: <?= View::json($statusLabels) ?>, datasets: [{ data: <?= View::json($statusTotals) ?> }] },
        options: { scales: {} }
    });
    UniGo.chart('transportChart', {
        type: 'pie',
        data: { labels: <?= View::json($transportLabels) ?>, datasets: [{ data: <?= View::json($transportTotals) ?> }] },
        options: { scales: {} }
    });
    UniGo.chart('operatorChart', {
        type: 'bar',
        data: { labels: <?= View::json($operatorLabels) ?>, datasets: [{ label: 'Bookings', data: <?= View::json($operatorBookings) ?> }] },
        options: {}
    });
    UniGo.chart('areaChart', {
        type: 'bar',
        data: { labels: <?= View::json($areaLabels) ?>, datasets: [{ label: 'Trips', data: <?= View::json($areaTotals) ?> }] },
        options: { indexAxis: 'y' }
    });
});
</script>
<?php View::stop(); ?>
