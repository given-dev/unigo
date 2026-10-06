<?php
declare(strict_types=1);
use App\Core\{Csrf,View};
?>
<div class="card mb-4"><form class="card__body" method="post" action="<?= e(url('/ratings')) ?>"><?= Csrf::field() ?>
<h3 class="card__title">Rate a completed trip</h3>
<?php if ($trips): ?>
<div class="form-grid"><div class="field"><label class="label" for="trip_id">Trip</label><select class="select" name="trip_id" id="trip_id" required><?php foreach ($trips as $trip): ?><option value="<?= (int)$trip['trip_id'] ?>"><?= e($trip['trip_code'].' · '.$trip['route_name']) ?></option><?php endforeach; ?></select></div>
<div class="field"><label class="label" for="rating">Rating</label><select class="select" name="rating" id="rating"><?php for($i=5;$i>=1;$i--): ?><option value="<?= $i ?>"><?= $i ?> stars</option><?php endfor; ?></select></div></div>
<label class="label" for="comment">Comment</label><textarea class="input" name="comment" id="comment" maxlength="500"></textarea><button class="btn btn--primary mt-3" type="submit">Submit rating</button>
<?php else: ?><p>You can rate a trip after it is completed.</p><?php endif; ?>
</form></div>
<?php foreach ($ratings as $rating): ?><article class="card mb-3"><div class="card__body"><strong><?= e($rating['route_name'] ?? $rating['trip_code'] ?? 'Trip') ?> · <?= (int)$rating['rating'] ?>/5</strong><p><?= e($rating['comment']) ?></p></div></article><?php endforeach; ?>
<?= View::partial('partials/pagination',['paginator'=>$paginator]) ?>
