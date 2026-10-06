<?php
declare(strict_types=1);
use App\Core\{Csrf, View};
$path = '/' . $role . '/' . $resource;
$transitions = [
    'trips'=>['scheduled'=>['boarding','cancelled'],'boarding'=>['in_transit','scheduled','cancelled'],'in_transit'=>['completed','cancelled']],
    'deliveries'=>\App\Models\DeliveryModel::STATUS_FLOW,
];
$statuses = match ($resource) {
    'users'=>['active','inactive','suspended'], 'operators'=>['pending','approved','rejected','suspended'],
    'drivers'=>['available','off_duty','suspended'], 'vehicles'=>['active','inactive','maintenance','suspended'],
    'routes'=>['active','inactive','suspended'], 'complaints'=>['open','investigating','resolved','dismissed'],
    'emergencies'=>['new','investigating','responding','resolved','dismissed'], 'ratings'=>['published','hidden'],
    default=>[],
};
$canCreate = in_array($role,['admin','operator'],true) && in_array($resource,['vehicles','routes','trips','drivers','users','operators'],true);
$fields = match ($resource) {
    'vehicles'=>['registration_number'=>'text','vehicle_type'=>'select','capacity'=>'number'],
    'routes'=>['route_code'=>'text','name'=>'text','origin_name'=>'text','destination_name'=>'text','base_fare'=>'number','duration_minutes'=>'number','origin_latitude'=>'number','origin_longitude'=>'number','destination_latitude'=>'number','destination_longitude'=>'number'],
    'trips'=>['vehicle_id'=>'select','route_id'=>'select','driver_id'=>'select','departure_time'=>'datetime-local','arrival_time'=>'datetime-local','fare'=>'number'],
    'drivers'=>['first_name'=>'text','last_name'=>'text','email'=>'email','phone'=>'tel','password'=>'password','license_number'=>'text'],
    'operators'=>['first_name'=>'text','last_name'=>'text','email'=>'email','phone'=>'tel','password'=>'password','company_name'=>'text','license_number'=>'text'],
    'users'=>['first_name'=>'text','last_name'=>'text','email'=>'email','phone'=>'tel','password'=>'password','role'=>'select'],
    default=>[],
};
?>
<?php if ($canCreate): ?>
<details class="card mb-4"><summary class="card__body">Add <?= e(rtrim($pageTitle,'s')) ?></summary>
<form class="card__body" method="post" action="<?= e(url($path)) ?>">
<?= Csrf::field() ?><input type="hidden" name="action" value="create">
<div class="form-grid">
<?php foreach ($fields as $name=>$type): ?>
<div class="field"><label class="label" for="create-<?= e($name) ?>"><?= e(ucwords(str_replace('_',' ',$name))) ?></label>
<?php if ($type === 'select'): ?>
<select class="select" name="<?= e($name) ?>" id="create-<?= e($name) ?>" <?= $name !== 'driver_id' ? 'required' : '' ?>>
<?php if ($name === 'vehicle_type'): foreach (\App\Models\VehicleModel::TYPES as $value): ?><option value="<?= e($value) ?>"><?= e(ucwords(str_replace('_',' ',$value))) ?></option><?php endforeach; ?>
<?php elseif ($name === 'role'): foreach (['passenger','authority','admin'] as $value): ?><option value="<?= e($value) ?>"><?= e(ucfirst($value)) ?></option><?php endforeach; ?>
<?php else: $table=match($name){'vehicle_id'=>'vehicles','route_id'=>'routes','driver_id'=>'drivers',default=>'operators'}; ?>
<option value="">Choose <?= e(str_replace('_id','',$name)) ?></option>
<?php foreach ($choices[$table] ?? [] as $option): ?><option value="<?= (int)$option['id'] ?>"><?= e($option['label']) ?></option><?php endforeach; ?>
<?php endif; ?></select>
<?php else: ?><input class="input" type="<?= e($type) ?>" name="<?= e($name) ?>" id="create-<?= e($name) ?>" required <?= $type === 'number' ? (str_contains($name,'latitude') || str_contains($name,'longitude') ? 'step="any"' : 'min="1" step="any"') : '' ?> <?= $type === 'password' ? 'minlength="8" autocomplete="new-password"' : '' ?>><?php endif; ?>
</div><?php endforeach; ?>
<?php if ($role === 'admin' && in_array($resource,['vehicles','routes','drivers'],true)): ?>
<div class="field"><label class="label" for="operator_id">Operator</label><select class="select" name="operator_id" id="operator_id"><option value="">Independent</option><?php foreach ($choices['operators'] ?? [] as $option): ?><option value="<?= (int)$option['id'] ?>"><?= e($option['label']) ?></option><?php endforeach; ?></select></div>
<?php endif; ?>
</div><button class="btn btn--primary mt-3" type="submit">Create</button></form></details>
<?php endif; ?>

<?php if ($map !== null): ?>
<div class="card mb-4"><div id="staffMap" class="map" data-map='<?= e((string)json_encode($map)) ?>' style="min-height:420px"></div></div>
<a class="btn btn--secondary mb-3" href="<?= e(url($path)) ?>">Refresh positions</a>
<?php if ($role === 'driver'): ?>
<button class="btn btn--primary" id="share-location" type="button">Share my GPS location</button><p class="text-sm" id="location-status" role="status"></p>
<?php endif; ?>
<?php View::start('scripts'); ?><script>
document.addEventListener('DOMContentLoaded',function(){
 var el=document.getElementById('staffMap'); if(el && window.UniGo && UniGo.map) UniGo.map('staffMap',JSON.parse(el.dataset.map));
 var btn=document.getElementById('share-location'); if(!btn)return;
 var status=document.getElementById('location-status');
 btn.addEventListener('click',function(){
  if(!navigator.geolocation){status.textContent='GPS is not available in this browser.';return;}
  status.textContent='Getting your location…';btn.disabled=true;
  navigator.geolocation.getCurrentPosition(function(pos){
   UniGo.api('driver/location',{method:'POST',body:{latitude:pos.coords.latitude,longitude:pos.coords.longitude,accuracy:pos.coords.accuracy,speed:(pos.coords.speed||0)*3.6,heading:pos.coords.heading||0}})
    .then(function(){status.textContent='Location shared. Refresh the map to see it.';}).catch(function(){status.textContent='Could not share location. Start boarding an assigned trip first.';}).finally(function(){btn.disabled=false;});
  },function(err){status.textContent=err.message;btn.disabled=false;},{enableHighAccuracy:true,timeout:15000});
 });
});</script><?php View::stop(); ?>
<?php endif; ?>

<?php if ($report): ?>
<div class="grid grid-3 gap-3 mb-4">
<?= View::partial('partials/stat-card',['label'=>'Bookings','value'=>(string)$report['bookings'],'icon'=>'ticket']) ?>
<?= View::partial('partials/stat-card',['label'=>'Collected fares','value'=>money($report['collected_fares']),'icon'=>'wallet']) ?>
</div><div class="card"><div class="card__body"><h3>Trip activity</h3><?php foreach ($report['trips'] as $item): ?><p><?= e(ucwords(str_replace('_',' ',$item['status']))) ?>: <strong><?= (int)$item['total'] ?></strong></p><?php endforeach; ?>
<?php if (!$report['trips']): ?><p>No trips recorded yet.</p><?php endif; ?>
<p class="text-sm text-muted-2">Collected fares include paid bookings. Driver figures show fares on assigned trips; they are not payout statements.</p></div></div>
<?php endif; ?>

<?php if ($resource === 'settings'): ?>
<form class="card mb-4" method="post" action="<?= e(url($path)) ?>"><div class="card__body"><?= Csrf::field() ?>
<?php if ($role === 'admin'): ?>
<label class="label" for="setting-key">Setting</label><select class="select mb-3" name="setting_key" id="setting-key"><?php foreach (['support_phone','support_email','emergency_hotline','cancellation_window_minutes'] as $key): ?><option value="<?= e($key) ?>"><?= e(ucwords(str_replace('_',' ',$key))) ?></option><?php endforeach; ?></select>
<label class="label" for="setting-value">Value</label><input class="input" id="setting-value" name="setting_value" required>
<?php else: foreach (['company_name','contact_email','contact_phone','address'] as $field): ?><label class="label" for="setting-<?= e($field) ?>"><?= e(ucwords(str_replace('_',' ',$field))) ?></label><input class="input mb-3" name="<?= e($field) ?>" id="setting-<?= e($field) ?>" value="<?= e($rows[0][$field] ?? '') ?>" <?= $field !== 'address' ? 'required' : '' ?>><?php endforeach; endif; ?>
<button class="btn btn--primary mt-3" type="submit">Save settings</button></div></form>
<?php endif; ?>

<?php if ($columns): ?>
<form class="flex gap-2 mb-3" method="get"><input class="input" type="search" name="search" placeholder="Search records" value="<?= e(\App\Core\Request::instance()->str('search')) ?>"><button class="btn btn--secondary" type="submit">Search</button></form>
<div class="card" style="overflow-x:auto"><table class="table"><thead><tr><?php foreach ($columns as $column): ?><th><?= e(ucwords(str_replace('_',' ',$column))) ?></th><?php endforeach; ?><th>Actions</th></tr></thead><tbody>
<?php foreach ($rows as $row): ?>
<tr><?php foreach ($columns as $column): ?><td><?= e($row[$column] ?? '—') ?></td><?php endforeach; ?><td>
<?php if ($resource === 'trips'): ?><a class="btn btn--ghost btn--sm" href="<?= e(url($path . '?trip=' . (int)$row['id'])) ?>">Passenger list</a><?php endif; ?>

<?php $options=$transitions[$resource][$row['status'] ?? ''] ?? $statuses;
if ($resource === 'deliveries' && ($row['status'] ?? '') === 'created') $options = ['cancelled'];
$editable=in_array($role,['admin','operator'],true) || ($role === 'authority' && in_array($resource,['operators','complaints','emergencies'],true)) || ($role === 'driver' && in_array($resource,['trips','deliveries'],true)); ?>
<?php if ($editable && $options && $resource !== 'settings'): ?>
<form method="post" action="<?= e(url($path)) ?>" class="flex gap-2"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><select class="select" name="status" aria-label="Status"><?php foreach ($options as $option): ?><option value="<?= e($option) ?>"><?= e(ucwords(str_replace('_',' ',$option))) ?></option><?php endforeach; ?></select><input class="input" name="note" maxlength="500" placeholder="Resolution or note" aria-label="Resolution or note"><button class="btn btn--secondary btn--sm" type="submit">Update</button></form>
<?php endif; ?>
<?php if ($resource === 'bookings' && in_array($role,['admin','operator'],true) && in_array($row['status'],['pending','confirmed'],true)): ?>
<form method="post" action="<?= e(url($path)) ?>" data-confirm="Cancel this booking?"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><button class="btn btn--ghost btn--sm" type="submit">Cancel booking</button></form><?php endif; ?>
<?php if ($resource === 'bookings' && in_array($role,['admin','operator','driver'],true) && $row['payment_status'] === 'unpaid' && in_array($row['status'],['confirmed','completed'],true)): ?>
<form method="post" action="<?= e(url($path)) ?>" data-confirm="Confirm you have received the full fare in cash?"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><input type="hidden" name="action" value="collect_cash"><button class="btn btn--secondary btn--sm" type="submit">Record cash received</button></form>
<?php endif; ?>
<?php if ($resource === 'bookings' && in_array($role,['admin','operator'],true) && $row['status'] === 'cancelled' && $row['payment_status'] === 'paid'): ?>
<form method="post" action="<?= e(url($path)) ?>" data-confirm="Confirm you have returned the fare in cash?"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><input type="hidden" name="action" value="refund_cash"><button class="btn btn--secondary btn--sm" type="submit">Record cash returned</button></form>
<?php endif; ?>
<?php if ($resource === 'deliveries' && $role === 'admin' && $row['status'] === 'created'): ?>
<form method="post" action="<?= e(url($path)) ?>"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><input type="hidden" name="action" value="assign"><select class="select" name="vehicle_id" aria-label="Assigned vehicle" required><?php foreach ($choices['vehicles'] ?? [] as $option): ?><option value="<?= (int)$option['id'] ?>"><?= e($option['label']) ?></option><?php endforeach; ?></select><button class="btn btn--secondary btn--sm" type="submit">Assign vehicle</button></form><?php endif; ?>
</td></tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="<?= count($columns)+1 ?>">No records found.</td></tr><?php endif; ?>
</tbody></table></div>
<?= View::partial('partials/pagination',['paginator'=>$paginator]) ?>
<?php endif; ?>

<?php if ($manifest): ?>
<div class="card mt-4" style="overflow-x:auto"><div class="card__body"><h3>Passenger list</h3><table class="table"><thead><tr><th>Reference</th><th>Passenger</th><th>Seat</th><th>Phone</th><th>Status</th><th>Boarding</th></tr></thead><tbody>
<?php foreach ($manifest as $booking): ?><tr><td><?= e($booking['reference']) ?></td><td><?= e($booking['first_name'].' '.$booking['last_name']) ?></td><td><?= e($booking['seat_number']) ?></td><td><?= e($booking['phone']) ?></td><td><?= e($booking['status']) ?></td><td><?php if ($role === 'driver' && $booking['status'] === 'confirmed'): ?><form method="post" action="<?= e(url('/driver/bookings')) ?>"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int)$booking['id'] ?>"><input type="hidden" name="action" value="board"><button class="btn btn--secondary btn--sm">Mark boarded</button></form><?php endif; ?></td></tr><?php endforeach; ?>
</tbody></table></div></div>
<?php endif; ?>
