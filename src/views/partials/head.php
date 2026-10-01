<?php
/**
 * UniGo - shared document head.
 *
 * Expects (all optional):
 *   $title       string  document title
 *   $bodyClass   string  extra classes on <body>
 *   $demo        bool    force the simulated-data ribbon on/off
 *   $noIndex     bool    set on auth pages so they are not indexed
 */
declare(strict_types=1);

use App\Core\Config;
use App\Core\Csrf;
use App\Core\View;

$title = $title ?? (app_name() . ' - ' . (string) Config::get('app.tagline', ''));
$bodyClass = $bodyClass ?? '';
$demo = $demo ?? is_demo_mode();
?>
<!DOCTYPE html>
<html lang="en" class="no-js">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0F172A">
    <meta name="format-detection" content="telephone=no">
    <meta name="csrf-token" content="<?= e(Csrf::token()) ?>">
    <meta name="base-url" content="<?= e(url('/')) ?>">
    <meta name="app-name" content="<?= e(app_name()) ?>">
    <meta name="demo-mode" content="<?= $demo ? '1' : '0' ?>">
    <?php if (!empty($noIndex)): ?>
        <meta name="robots" content="noindex, nofollow">
    <?php endif; ?>
    <title><?= e($title) ?></title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">

    <link rel="stylesheet" href="<?= e(asset('assets/css/unigo.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('assets/css/icons.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('assets/css/layout.css')) ?>">

    <link rel="icon" type="image/svg+xml" href="<?= e(asset('assets/img/favicon.svg')) ?>">
    <link rel="apple-touch-icon" href="<?= e(asset('assets/img/favicon.svg')) ?>">
    <link rel="manifest" href="<?= e(url('manifest.webmanifest')) ?>">

    <?php /* Per page <head> additions (page specific styles, preloads) */ ?>
    <?= View::section('head') ?>
</head>
<body class="<?= e(trim('unigo ' . $bodyClass)) ?><?= $demo ? ' is-demo' : '' ?>">

<script>document.documentElement.classList.remove('no-js');</script>

<?php /* SVG sprite - must be rendered before any icon markup is upgraded */ ?>
<?= View::partial('partials/icon-sprite') ?>
