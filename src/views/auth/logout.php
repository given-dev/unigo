<?php
declare(strict_types=1);
use App\Core\Auth;
use App\Core\Csrf;
?>
<h1 class="auth__title">Sign out?</h1>
<p class="auth__sub">You are signed in as <?= e(Auth::name()) ?>.</p>
<form method="post" action="<?= e(url('/logout')) ?>">
    <?= Csrf::field() ?>
    <button class="btn btn--primary btn--block" type="submit">Sign out</button>
</form>
<p class="text-sm text-center mt-5"><a href="<?= e(url(Auth::homeRoute())) ?>">Stay signed in</a></p>
