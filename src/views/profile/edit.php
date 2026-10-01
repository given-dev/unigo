<?php
/**
 * UniGo - profile edit.
 *
 * @var array $user
 * @var array $roles
 */
declare(strict_types=1);

use App\Core\Csrf;

$user  = $user ?? [];
$roles = $roles ?? [];
$roleLabels = array_map(static fn ($r) => ucfirst((string) $r), $roles);
?>
<div class="grid grid-2 gap-3">
    <article class="card">
        <div class="card__body">
            <div class="flex items-center gap-3 mb-4">
                <span class="avatar avatar--lg"><?= e(initials(trim((string) ($user['first_name'] ?? '') . ' ' . (string) ($user['last_name'] ?? '')))) ?></span>
                <div>
                    <h2 class="card__title mb-0"><?= e(trim((string) ($user['first_name'] ?? '') . ' ' . (string) ($user['last_name'] ?? ''))) ?></h2>
                    <p class="text-sm text-muted-2 mb-0"><?= e($user['email'] ?? '') ?></p>
                    <div class="flex gap-1 mt-1 flex-wrap">
                        <?php foreach ($roleLabels as $label): ?>
                            <span class="badge badge-primary"><?= e($label) ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <form method="post" action="<?= e(url('/profile')) ?>">
                <?= Csrf::field() ?>
                <div class="form-grid">
                    <div class="field">
                        <label class="label" for="first_name">First name</label>
                        <input class="input" id="first_name" name="first_name" required maxlength="60" value="<?= e($user['first_name'] ?? '') ?>">
                    </div>
                    <div class="field">
                        <label class="label" for="last_name">Last name</label>
                        <input class="input" id="last_name" name="last_name" required maxlength="60" value="<?= e($user['last_name'] ?? '') ?>">
                    </div>
                    <div class="field">
                        <label class="label" for="phone">Phone</label>
                        <input class="input" id="phone" name="phone" maxlength="30" value="<?= e($user['phone'] ?? '') ?>">
                    </div>
                    <div class="field">
                        <label class="label" for="national_id">National ID</label>
                        <input class="input" id="national_id" name="national_id" maxlength="40" value="<?= e($user['national_id'] ?? '') ?>">
                    </div>
                    <div class="field">
                        <label class="label" for="date_of_birth">Date of birth</label>
                        <input class="input" id="date_of_birth" name="date_of_birth" type="date" value="<?= e($user['date_of_birth'] ?? '') ?>">
                    </div>
                    <div class="field">
                        <label class="label" for="gender">Gender</label>
                        <select class="select" id="gender" name="gender">
                            <?php foreach (['' => 'Prefer not to say', 'female' => 'Female', 'male' => 'Male', 'other' => 'Other'] as $val => $label): ?>
                                <option value="<?= e($val) ?>" <?= ($user['gender'] ?? '') === $val ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="flex justify-end mt-3">
                    <button class="btn btn--primary" type="submit">
                        <i class="icon" data-icon="check">check</i> Save changes
                    </button>
                </div>
            </form>
        </div>
    </article>

    <article class="card">
        <div class="card__body">
            <h3 class="card__title mb-3">Change password</h3>
            <form method="post" action="<?= e(url('/profile/password')) ?>">
                <?= Csrf::field() ?>
                <div class="field mb-2">
                    <label class="label" for="current_password">Current password</label>
                    <input class="input" id="current_password" name="current_password" type="password" required autocomplete="current-password">
                </div>
                <div class="field mb-2">
                    <label class="label" for="new_password">New password</label>
                    <input class="input" id="new_password" name="new_password" type="password" required autocomplete="new-password" data-strength>
                </div>
                <div class="field mb-3">
                    <label class="label" for="new_password_confirmation">Confirm new password</label>
                    <input class="input" id="new_password_confirmation" name="new_password_confirmation" type="password" required autocomplete="new-password">
                </div>
                <button class="btn btn--ghost" type="submit">
                    <i class="icon" data-icon="lock">lock</i> Update password
                </button>
            </form>
        </div>
    </article>
</div>
