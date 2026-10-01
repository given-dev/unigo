<?php
/**
 * UniGo - passenger bookings.
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\ErrorHandler;
use App\Core\Flash;
use App\Models\BookingModel;

final class BookingController extends Controller
{
    /** /bookings - list the signed-in passenger's bookings. */
    public function index(): void
    {
        $this->requireLogin();

        $status = trim($this->request->str('status'));
        $page   = max(1, $this->request->int('page', 1));

        $filters = ['passenger_id' => (int) Auth::id()];
        if ($status !== '') {
            $filters['status'] = $status;
        }

        $result = $this->safe(
            static fn () => (new BookingModel())->paginateBookings($filters, $page, 10),
            ['items' => [], 'paginator' => null]
        );

        $this->view('bookings/index', [
            'title'     => 'My bookings - ' . app_name(),
            'pageTitle' => 'My bookings',
            'pageSub'   => 'Every seat you have reserved',
            'bookings'  => $result['items'] ?? [],
            'paginator' => $result['paginator'] ?? null,
            'status'    => $status,
        ], 'layouts/app');
    }

    /** /bookings/{id} - a single booking with its ticket. */
    public function show(string $id): void
    {
        $this->requireLogin();
        $bookingId = (int) $id;

        $booking = $this->safe(static fn () => (new BookingModel())->findDetailed($bookingId), null);
        if (!$booking) {
            $this->notFound();
        }
        if ((int) ($booking['passenger_id'] ?? 0) !== (int) Auth::id() && !Auth::isAdmin()) {
            Auth::deny('That booking does not belong to you.');
        }

        $this->view('bookings/show', [
            'title'     => 'Booking ' . ($booking['reference'] ?? '') . ' - ' . app_name(),
            'pageTitle' => 'Booking details',
            'pageSub'   => (string) ($booking['reference'] ?? ''),
            'booking'   => $booking,
        ], 'layouts/app');
    }

    /** POST /bookings/{id}/cancel. */
    public function cancel(string $id): void
    {
        $this->requireLogin();
        $this->verifyCsrf();
        $bookingId = (int) $id;

        try {
            (new BookingModel())->cancel(
                $bookingId,
                (int) Auth::id(),
                trim($this->request->str('reason')),
                Auth::isAdmin()
            );
            Flash::success('Booking cancelled. Any eligible refund has been processed.');
        } catch (\App\Core\AppException $e) {
            Flash::error($e->getMessage());
        } catch (\Throwable $e) {
            ErrorHandler::log('error', 'Cancellation failed: ' . $e->getMessage());
            Flash::error('We could not cancel that booking. Please try again.');
        }

        $this->redirect('/bookings/' . $bookingId);
    }

    private function notFound(): void
    {
        \App\Core\Http::status(404);
        $this->view('errors/404', [
            'title'   => 'Booking not found',
            'heading' => 'Booking not found',
            'message' => 'That booking does not exist, or it is not yours.',
        ], 'layouts/app');
        exit;
    }

    private function safe(callable $callback, $fallback)
    {
        try {
            return $callback();
        } catch (\Throwable $e) {
            ErrorHandler::log('warning', 'Booking data unavailable: ' . $e->getMessage());
            return $fallback;
        }
    }
}
