<?php
/**
 * UniGo - forgot password page.
 */
declare(strict_types=1);

use App\Core\Csrf;
?>
<h1 class="auth__title">Reset your password</h1>
<p class="auth__sub">Enter the email on your account and we will send a reset link.</p>

<form method="post" action="<?= e(url('/forgot-password')) ?>" novalidate>
    <?= Csrf::field() ?>
    <div class="field">
        <label class="label" for="email">Email address</label>
        <div class="input-icon">
            <i class="icon" data-icon="mail">mail</i>
            <input class="input" id="email" name="email" type="email" autocomplete="email"
                   required placeholder="you@example.com">
        </div>
    </div>
    <button class="btn btn--primary btn--block" type="submit">
        <i class="icon" data-icon="send">send</i> Send reset link
    </button>
</form>

<p class="text-sm text-center mt-5 mb-0">
    Remembered it? <a href="<?= e(url('/login')) ?>">Back to sign in</a>
</p>
