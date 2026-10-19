<?php
/**
 * UniGo - sign in page.
 */
declare(strict_types=1);

use App\Core\Csrf;
use App\Core\Flash;

$oldEmail = (string) Flash::old('email');
?>
<h1 class="auth__title">Welcome back</h1>
<p class="auth__sub">Sign in to book trips, track vehicles and manage your journeys.</p>

<form method="post" action="<?= e(url('/login')) ?>" novalidate id="loginForm">
    <?= Csrf::field() ?>

    <div class="field">
        <label class="label" for="email">Email address</label>
        <div class="input-icon">
            <i class="icon" data-icon="mail">mail</i>
            <input class="input" id="email" name="email" type="email" inputmode="email"
                   autocomplete="email" required value="<?= e($oldEmail) ?>"
                   placeholder="you@example.com">
        </div>
    </div>

    <div class="field">
        <div class="flex items-center justify-between">
            <label class="label" for="password">Password</label>
            <a class="text-xs" href="<?= e(url('/forgot-password')) ?>">Forgot password?</a>
        </div>
        <div style="position:relative">
            <input class="input" id="password" name="password" type="password"
                   autocomplete="current-password" required style="padding-right:2.75rem"
                   placeholder="Your password">
            <button class="btn btn--icon btn--ghost" type="button" data-toggle-password="#password"
                    aria-label="Show password"
                    style="position:absolute;right:4px;top:50%;transform:translateY(-50%);width:36px;min-height:36px">
                <i class="icon" data-icon="eye">eye</i>
            </button>
        </div>
    </div>

    <label class="check mb-3">
        <input type="checkbox" name="remember" value="1">
        <span>Keep me signed in on this device</span>
    </label>

    <button class="btn btn--primary btn--block" type="submit">
        <i class="icon" data-icon="login">login</i> Sign in
    </button>
</form>

<p class="text-sm text-center mt-5 mb-0">
    New to <?= e(app_name()) ?>? <a href="<?= e(url('/register')) ?>">Create an account</a>
</p>

<?php if (is_demo_mode()): ?>
    <div class="demo-panel">
        <p class="demo-panel__title">
            <i class="icon" data-icon="sparkles">sparkles</i> Demo accounts (tap to fill)
        </p>
        <p class="text-xs text-muted-2" style="margin:-.5rem 0 var(--sp-3)">
            Password for every account: <code>UniGo@2026</code>
        </p>
        <div class="demo-list">
            <?php
            // Demo accounts belong to the project team (Group 7).
            $companies = [
                'UniGo administration' => [
                    ['Administrator', 'Given Tuhaise Biheegu', 'admin@unigo.test', 'settings'],
                ],
                'Pearl Test Bus Services' => [
                    ['Operator', 'Davis Bwambale', 'bus.operator@unigo.test', 'building'],
                    ['Driver', 'Davis Katawire Atwiine', 'bus.driver@unigo.test', 'bus'],
                    ['Passenger', 'Juliet Mudulo', 'bus.passenger@unigo.test', 'user'],
                ],
                'City Test Taxi Services' => [
                    ['Operator', 'David Oyirwoth', 'taxi.operator@unigo.test', 'building'],
                    ['Driver', 'Idd Ashiraf Lukwago', 'taxi.driver@unigo.test', 'car'],
                    ['Passenger', 'Robin Obote', 'taxi.passenger@unigo.test', 'user'],
                ],
                'Swift Test Logistics' => [
                    ['Operator', 'Jonathan Kizza Ssenoga', 'logistics.operator@unigo.test', 'building'],
                    ['Driver', 'Joshua Engwaru', 'logistics.driver@unigo.test', 'truck'],
                    ['Customer', 'Eric Mutaka Wambona', 'logistics.customer@unigo.test', 'package'],
                ],
                'Regulator offices' => [
                    ['National (MoWT)', 'Emmanuel Kisembo', 'mowt.authority@unigo.test', 'shield'],
                    ['Kampala / KCCA', 'Anold Kitaka', 'kampala.authority@unigo.test', 'shield'],
                    ['Jinja district', 'Angella Mugisha', 'jinja.authority@unigo.test', 'shield'],
                ],
            ];
            ?>
            <?php foreach ($companies as $company => $accounts): ?>
                <div class="demo-group">
                    <p class="demo-group__title"><?= e($company) ?></p>
                    <?php foreach ($accounts as [$role, $name, $mail, $icon]): ?>
                        <button class="demo-item" type="button"
                                data-demo-email="<?= e($mail) ?>" data-demo-password="UniGo@2026">
                            <span class="flex items-center gap-2">
                                <i class="icon icon--sm" data-icon="<?= e($icon) ?>"><?= e($icon) ?></i>
                                <span class="demo-item__role"><?= e($role) ?> · <?= e($name) ?></span>
                            </span>
                            <span class="demo-item__mail"><?= e($mail) ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <script>
        (function () {
            var form = document.getElementById('loginForm');
            if (!form) { return; }
            document.querySelectorAll('[data-demo-email]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    form.email.value = btn.getAttribute('data-demo-email');
                    form.password.value = btn.getAttribute('data-demo-password');
                    form.email.focus();
                });
            });
        }());
    </script>
<?php endif; ?>
