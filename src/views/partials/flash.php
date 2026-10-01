<?php
/**
 * UniGo - flash messages + validation errors.
 *
 * Flash messages are pulled from the session (one request lifetime) and
 * validation errors survive a single redirect so forms can re-display them.
 */
declare(strict_types=1);

use App\Core\Flash;

$flashes = Flash::pull();
$errors  = Flash::errors();

$icons = [
    'success' => 'check-circle',
    'error'   => 'alert',
    'warning' => 'alert',
    'info'    => 'info',
];
?>
<?php if (!empty($errors)): ?>
    <div class="alert alert-danger" role="alert" data-autodismiss="0">
        <i class="icon alert__icon" data-icon="alert">alert</i>
        <div class="alert__body">
            <p class="alert__title">Please fix the following</p>
            <ul style="margin:6px 0 0;padding-left:18px">
                <?php foreach ($errors as $field => $message): ?>
                    <li><?= e(is_array($message) ? implode(' ', $message) : $message) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <button class="alert__close" type="button" data-alert-close aria-label="Dismiss">
            <i class="icon" data-icon="x">x</i>
        </button>
    </div>
<?php endif; ?>

<?php foreach ($flashes as $flash): ?>
    <?php $type = isset($icons[$flash['type']]) ? $flash['type'] : 'info'; ?>
    <div class="alert alert-<?= e($type) ?>" role="status"<?= $type === 'error' ? '' : ' data-autodismiss="7000"' ?>>
        <i class="icon alert__icon" data-icon="<?= e($icons[$type]) ?>"><?= e($icons[$type]) ?></i>
        <div class="alert__body"><?= e($flash['message']) ?></div>
        <button class="alert__close" type="button" data-alert-close aria-label="Dismiss">
            <i class="icon" data-icon="x">x</i>
        </button>
    </div>
<?php endforeach; ?>
