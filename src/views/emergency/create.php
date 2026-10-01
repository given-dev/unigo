<?php
/**
 * UniGo - emergency SOS form.
 *
 * @var array $types
 * @var array $severities
 * @var bool $demo
 */
declare(strict_types=1);

use App\Core\Csrf;

$types      = $types ?? [];
$severities = $severities ?? [];
$demo       = (bool) ($demo ?? false);
?>
<div class="empty empty--error mb-4">
    <span class="empty__icon">
        <i class="icon" data-icon="siren">siren</i>
    </span>
    <h2 class="empty__title">Emergency SOS</h2>
    <p class="empty__text">
        Use this only in a genuine emergency. Your alert is sent to our response team with your
        booking and vehicle details.
        <?php if (!$demo): ?> If you are in immediate danger, call the national emergency line first.<?php endif; ?>
    </p>
</div>

<?php if ($demo): ?>
    <p class="text-sm text-muted-2 mb-3">
        <i class="icon icon--sm" data-icon="info">info</i>
        Demo mode: this SOS is recorded as a simulated alert and does not contact real services.
    </p>
<?php endif; ?>

<form method="post" action="<?= e(url('/emergency')) ?>">
    <?= Csrf::field() ?>
    <div class="grid grid-2 gap-3">
        <article class="card">
            <div class="card__body">
                <h3 class="card__title mb-3">What is happening?</h3>
                <div class="form-grid">
                    <div class="field">
                        <label class="label" for="emergency_type">Emergency type</label>
                        <select class="select" id="emergency_type" name="emergency_type" required>
                            <?php foreach ($types as $type): ?>
                                <option value="<?= e($type) ?>"><?= e(status_label($type)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label class="label" for="severity">Severity</label>
                        <select class="select" id="severity" name="severity" required>
                            <?php foreach ($severities as $sev): ?>
                                <option value="<?= e($sev) ?>" <?= $sev === 'high' ? 'selected' : '' ?>><?= e(ucfirst($sev)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field" style="grid-column:1 / -1">
                        <label class="label" for="address_text">Where are you?</label>
                        <input class="input" id="address_text" name="address_text" maxlength="190"
                               placeholder="Nearest landmark, road or stop">
                    </div>
                    <div class="field">
                        <label class="label" for="contact_phone">Callback phone</label>
                        <input class="input" id="contact_phone" name="contact_phone" maxlength="30">
                    </div>
                    <div class="field" style="grid-column:1 / -1">
                        <label class="label" for="description">Describe the situation</label>
                        <textarea class="textarea" id="description" name="description" rows="4" maxlength="500" required></textarea>
                    </div>
                    <label class="check">
                        <input type="checkbox" name="notify_police" value="1">
                        <span>Also notify the police</span>
                    </label>
                </div>
            </div>
            <div class="card__footer flex items-center justify-between">
                <a class="btn btn--ghost" href="<?= e(url('/home')) ?>">Cancel</a>
                <button class="btn btn--danger" type="submit"
                        onclick="return confirm('Send this emergency alert now?');">
                    <i class="icon" data-icon="siren">siren</i> Send SOS
                </button>
            </div>
        </article>

        <article class="card">
            <div class="card__body">
                <h3 class="card__title mb-3">What happens next</h3>
                <ol class="timeline">
                    <li class="timeline__item timeline__item--active"><span class="timeline__dot"></span><div><strong>Alert raised</strong><br><span class="text-sm text-muted-2">Your SOS is logged with a reference.</span></div></li>
                    <li class="timeline__item"><span class="timeline__dot"></span><div><strong>Team notified</strong><br><span class="text-sm text-muted-2">Admins, the operator and the driver on your vehicle are alerted.</span></div></li>
                    <li class="timeline__item"><span class="timeline__dot"></span><div><strong>Response</strong><br><span class="text-sm text-muted-2">Responders update the status and try to reach you.</span></div></li>
                </ol>
            </div>
        </article>
    </div>
</form>
