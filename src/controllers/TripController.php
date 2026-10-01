<?php
/**
 * UniGo - trip discovery and booking (passenger).
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\ErrorHandler;
use App\Core\Flash;
use App\Models\BookingModel;
use App\Models\RouteModel;
use App\Models\TripModel;

final class TripController extends Controller
{
    /** /trips/search - search bookable trips. */
    public function search(): void
    {
        $this->requireLogin();

        $filters = [
            'from'           => trim($this->request->str('from')),
            'to'             => trim($this->request->str('to')),
            'date'           => trim($this->request->str('date')),
            'transport_type' => trim($this->request->str('transport_type')),
        ];
        $page = max(1, $this->request->int('page', 1));

        $result = $this->safe(
            static fn () => (new RouteModel())->search($filters, $page, 10),
            ['items' => [], 'paginator' => null]
        );

        $this->view('trips/search', [
            'title'     => 'Find a trip - ' . app_name(),
            'pageTitle' => 'Find a trip',
            'pageSub'   => 'Search live schedules and reserve a seat',
            'trips'     => $result['items'] ?? [],
            'paginator' => $result['paginator'] ?? null,
            'filters'   => $filters,
        ], 'layouts/app');
    }

    /** /trips/{id} - trip detail with a seat picker. */
    public function show(string $id): void
    {
        $this->requireLogin();
        $tripId = (int) $id;

        $trip = $this->safe(static fn () => (new TripModel())->findDetailed($tripId), null);
        if (!$trip) {
            $this->notFound();
        }

        $seats = $this->safe(
            static fn () => (new BookingModel())->bookableSeats($tripId, (int) Auth::id()),
            []
        );

        $this->view('trips/show', [
            'title'       => ($trip['origin_name'] ?? '') . ' to ' . ($trip['destination_name'] ?? '') . ' - ' . app_name(),
            'pageTitle'   => 'Trip details',
            'pageSub'     => (string) ($trip['route_name'] ?? ''),
            'trip'        => $trip,
            'seats'       => $seats,
            'withSeats'   => true,
            'withLeaflet' => !empty($trip['stops']),
        ], 'layouts/app');
    }

    /** POST /trips/{id}/book - create a booking. */
    public function book(string $id): void
    {
        $this->requireLogin();
        $this->verifyCsrf();
        $tripId = (int) $id;

        $seatInput = $this->request->input('seat_number');
        if (is_array($seatInput)) {
            $seatInput = reset($seatInput);
        }
        $seat = strtoupper(trim((string) $seatInput));
        if ($seat === '') {
            Flash::error('Please choose a seat before continuing.');
            $this->redirect('/trips/' . $tripId);
        }

        try {
            $result = (new BookingModel())->createBooking([
                'trip_id'        => $tripId,
                'passenger_id'   => (int) Auth::id(),
                'seat_number'    => $seat,
                'from_stop_id'   => $this->request->int('from_stop_id') ?: null,
                'to_stop_id'     => $this->request->int('to_stop_id') ?: null,
                'pickup_point'   => $this->request->str('pickup_point'),
                'payment_method' => $this->request->str('payment_method', 'mobile_money'),
                'notes'          => $this->request->str('notes'),
                'is_simulated'   => is_demo_mode() ? 1 : 0,
            ], (int) Auth::id());
        } catch (\App\Core\AppException $e) {
            Flash::withInput($_POST, []);
            Flash::error($e->getMessage());
            $this->redirect('/trips/' . $tripId);
        } catch (\Throwable $e) {
            ErrorHandler::log('error', 'Booking failed: ' . $e->getMessage());
            Flash::error('We could not complete that booking. Please try again.');
            $this->redirect('/trips/' . $tripId);
        }

        $booking = $result['booking'] ?? [];
        $ref     = (string) ($booking['reference'] ?? '');
        Flash::success('Booking confirmed' . ($ref !== '' ? ' - ' . $ref : '') . '.');
        $this->redirect('/bookings/' . (int) ($booking['id'] ?? 0));
    }

    private function notFound(): void
    {
        \App\Core\Http::status(404);
        $this->view('errors/404', [
            'title'   => 'Trip not found',
            'heading' => 'Trip not found',
            'message' => 'That trip is no longer available or has already departed.',
        ], 'layouts/app');
        exit;
    }

    private function safe(callable $callback, $fallback)
    {
        try {
            return $callback();
        } catch (\Throwable $e) {
            ErrorHandler::log('warning', 'Trip data unavailable: ' . $e->getMessage());
            return $fallback;
        }
    }
}
