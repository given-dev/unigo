<?php
/**
 * UniGo - front controller.
 *
 * Page and JSON API requests enter here (see public/.htaccess).
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Controllers\AuthController;
use App\Controllers\BookingController;
use App\Controllers\ComplaintController;
use App\Controllers\DashboardController;
use App\Controllers\DeliveryController;
use App\Controllers\EmergencyController;
use App\Controllers\HomeController;
use App\Controllers\NotificationController;
use App\Controllers\PaymentController;
use App\Controllers\ProfileController;
use App\Controllers\StubController;
use App\Controllers\StaffController;
use App\Controllers\TrackingController;
use App\Controllers\TripController;
use App\Core\Http;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\View;

$router = new Router();

// ---------------------------------------------------------------------------
// Public marketing / information pages
// ---------------------------------------------------------------------------
$router->get('/', [HomeController::class, 'index']);
$router->get('/about', [HomeController::class, 'about']);
$router->get('/support', [HomeController::class, 'support']);
$router->get('/contact', [HomeController::class, 'contact']);
$router->get('/schedule', [HomeController::class, 'schedule']);
$router->get('/privacy', [HomeController::class, 'privacy']);
$router->get('/terms', [HomeController::class, 'terms']);

// ---------------------------------------------------------------------------
// Authentication
// ---------------------------------------------------------------------------
$router->get('/login', [AuthController::class, 'showLogin']);
$router->post('/login', [AuthController::class, 'login']);
$router->get('/register', [AuthController::class, 'showRegister']);
$router->post('/register', [AuthController::class, 'register']);
$router->get('/forgot-password', [AuthController::class, 'showForgot']);
$router->post('/forgot-password', [AuthController::class, 'sendReset']);
$router->get('/logout', [AuthController::class, 'showLogout']);
$router->post('/logout', [AuthController::class, 'logout']);

// ---------------------------------------------------------------------------
// Signed-in shell
// ---------------------------------------------------------------------------
$router->get('/home', [DashboardController::class, 'index']);
$router->get('/dashboard', [DashboardController::class, 'home']);
$router->get('/driver/dashboard', [DashboardController::class, 'driver']);
$router->get('/operator/dashboard', [DashboardController::class, 'operator']);
$router->get('/authority/dashboard', [DashboardController::class, 'authority']);
$router->get('/admin/dashboard', [DashboardController::class, 'admin']);

// ---------------------------------------------------------------------------
// Passenger workspace
// ---------------------------------------------------------------------------
$router->get('/trips/search', [TripController::class, 'search']);
$router->post('/trips/search', [TripController::class, 'search']);
$router->get('/trips/{id}', [TripController::class, 'show']);
$router->post('/trips/{id}/book', [TripController::class, 'book']);

$router->get('/bookings', [BookingController::class, 'index']);
$router->get('/bookings/{id}', [BookingController::class, 'show']);
$router->post('/bookings/{id}/cancel', [BookingController::class, 'cancel']);

$router->post('/api/driver/location', [\App\Controllers\DriverLocationController::class, 'store']);
$router->get('/api/tracking/{id}', [TrackingController::class, 'feed']);
$router->get('/api/notifications/unread-count', [NotificationController::class, 'unreadCount']);
$router->get('/api/session', static function (): void {
    Response::success([
        'user_id' => \App\Core\Auth::id(),
        'csrf' => \App\Core\Csrf::token(),
    ])->send();
});

$router->get('/tracking', [TrackingController::class, 'index']);

$router->get('/ratings', [\App\Controllers\RatingController::class, 'index']);
$router->post('/ratings', [\App\Controllers\RatingController::class, 'store']);

$router->get('/payments', [PaymentController::class, 'index']);
$router->get('/payments/{id}', [PaymentController::class, 'show']);

$router->get('/deliveries/new', [DeliveryController::class, 'create']);
$router->get('/deliveries', [DeliveryController::class, 'index']);
$router->post('/deliveries', [DeliveryController::class, 'store']);
$router->get('/deliveries/{tracking}', [DeliveryController::class, 'show']);

$router->get('/complaints', [ComplaintController::class, 'index']);
$router->post('/complaints', [ComplaintController::class, 'store']);
$router->get('/passenger/complaints', [ComplaintController::class, 'index']);

$router->get('/profile', [ProfileController::class, 'edit']);
$router->post('/profile', [ProfileController::class, 'update']);
$router->post('/profile/password', [ProfileController::class, 'password']);

$router->get('/notifications', [NotificationController::class, 'index']);
$router->post('/notifications/read-all', [NotificationController::class, 'readAll']);
$router->post('/notifications/{id}/read', [NotificationController::class, 'read']);

$router->get('/emergency/new', [EmergencyController::class, 'create']);
$router->post('/emergency', [EmergencyController::class, 'store']);
$router->get('/passenger/emergency', [EmergencyController::class, 'create']);

// ---------------------------------------------------------------------------
// Staff workspace placeholders (single source of truth: StubController::PAGES)
// ---------------------------------------------------------------------------
foreach (array_keys(StubController::PAGES) as $staffPath) {
    $router->get($staffPath, [StaffController::class, 'index']);
    $router->post($staffPath, [StaffController::class, 'save']);
}

// ---------------------------------------------------------------------------
// Dispatch
// ---------------------------------------------------------------------------
$request = Request::instance();
$match   = $router->match($request->method(), $request->path());

if ($match === null) {
    if ($request->wantsJson()) {
        Response::error('The requested resource was not found.', 404)->send();
        return;
    }
    Http::status(404);
    View::render('errors/404', [
        'title'   => 'Page not found',
        'heading' => 'Page not found',
        'message' => 'The page you are looking for does not exist or has moved.',
    ], 'layouts/public');
    return;
}

$handler = $match['handler'];
$params  = array_values($match['params']);

if (is_array($handler) && is_string($handler[0])) {
    [$class, $action] = $handler;
    (new $class())->{$action}(...$params);
} elseif (is_callable($handler)) {
    $handler(...$params);
} else {
    Http::status(500);
    View::render('errors/error', [
        'title'   => 'Something went wrong',
        'code'    => 500,
        'heading' => 'Something went wrong',
        'message' => 'This route is not configured correctly.',
    ], 'layouts/public');
}
