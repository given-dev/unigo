<?php
/**
 * UniGo - create account page.
 */
declare(strict_types=1);

use App\Core\Csrf;
use App\Core\Flash;
use App\Core\Validator;

$old = Flash::oldAll();
$val = static fn (string $key): string => (string) ($old[$key] ?? '');
?>
<h1 class="auth__title">Create your account</h1>
<p class="auth__sub">It takes a minute. Book trips, send parcels and track everything in one place.</p>

<form method="post" action="<?= e(url('/register')) ?>" novalidate id="registerForm">
    <?= Csrf::field() ?>

    <div class="form-grid">
        <div class="field">
            <label class="label" for="first_name">First name</label>
            <input class="input" id="first_name" name="first_name" type="text" autocomplete="given-name"
                   required value="<?= e($val('first_name')) ?>" placeholder="Amina">
        </div>
        <div class="field">
            <label class="label" for="last_name">Last name</label>
            <input class="input" id="last_name" name="last_name" type="text" autocomplete="family-name"
                   required value="<?= e($val('last_name')) ?>" placeholder="Nakato">
        </div>
    </div>

    <div class="field">
        <label class="label" for="email">Email address</label>
        <div class="input-icon">
            <i class="icon" data-icon="mail">mail</i>
            <input class="input" id="email" name="email" type="email" autocomplete="email"
                   required value="<?= e($val('email')) ?>" placeholder="you@example.com">
        </div>
    </div>

    <div class="form-grid">
        <div class="field">
            <label class="label" for="phone">Phone number</label>
            <div class="input-icon">
                <i class="icon" data-icon="phone">phone</i>
                <input class="input" id="phone" name="phone" type="tel" autocomplete="tel"
                       required value="<?= e($val('phone')) ?>" placeholder="+256 700 000 000">
            </div>
        </div>
        <div class="field">
            <label class="label" for="city">City <span class="hint">(optional)</span></label>
            <input class="input" id="city" name="city" type="text" autocomplete="address-level2"
                   value="<?= e($val('city')) ?>" placeholder="Kampala">
        </div>
    </div>

    <div class="field">
        <label class="label" for="password">Password</label>
        <div style="position:relative">
            <input class="input" id="password" name="password" type="password" autocomplete="new-password"
                   required style="padding-right:2.75rem" placeholder="Create a strong password">
            <button class="btn btn--icon btn--ghost" type="button" data-toggle-password="#password"
                    aria-label="Show password"
                    style="position:absolute;right:4px;top:50%;transform:translateY(-50%);width:36px;min-height:36px">
                <i class="icon" data-icon="eye">eye</i>
            </button>
        </div>
        <div class="pw-strength" data-strength="#password" aria-hidden="true">
            <span class="pw-strength__bar"></span>
            <span class="pw-strength__bar"></span>
            <span class="pw-strength__bar"></span>
            <span class="pw-strength__bar"></span>
        </div>
        <p class="field-help" data-strength-label></p>
        <p class="field-help"><?= e(Validator::passwordHint()) ?></p>
    </div>

    <div class="field">
        <label class="label" for="password_confirmation">Confirm password</label>
        <input class="input" id="password_confirmation" name="password_confirmation" type="password"
               autocomplete="new-password" required placeholder="Repeat your password">
    </div>

    <label class="check mb-3">
        <input type="checkbox" name="terms" value="1" required>
        <span>I agree to the <a href="<?= e(url('/terms')) ?>">terms of use</a> and <a href="<?= e(url('/privacy')) ?>">privacy policy</a>.</span>
    </label>

    <button class="btn btn--primary btn--block" type="submit">
        <i class="icon" data-icon="user-plus">user-plus</i> Create account
    </button>
</form>

<p class="text-sm text-center mt-5 mb-0">
    Already registered? <a href="<?= e(url('/login')) ?>">Sign in</a>
</p>
