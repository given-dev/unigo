<?php
/**
 * UniGo - passenger complaints / problem reports.
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\ErrorHandler;
use App\Core\Flash;
use App\Models\BookingModel;
use App\Models\ComplaintModel;

final class ComplaintController extends Controller
{
    /** /complaints - list the signed-in user's reports. */
    public function index(): void
    {
        $this->requireLogin();

        $page   = max(1, $this->request->int('page', 1));
        $result = $this->safe(
            static fn () => (new ComplaintModel())->paginateComplaints(
                ['user_id' => (int) Auth::id()],
                $page,
                10
            ),
            ['items' => [], 'paginator' => null]
        );

        $bookings = $this->safe(
            static fn () => (new BookingModel())->paginateBookings(
                ['passenger_id' => (int) Auth::id()],
                1,
                20
            )['items'] ?? [],
            []
        );

        $this->view('complaints/index', [
            'title'      => 'My complaints - ' . app_name(),
            'pageTitle'  => 'My complaints',
            'pageSub'    => 'Report a problem and follow its progress',
            'complaints' => $result['items'] ?? [],
            'paginator'  => $result['paginator'] ?? null,
            'bookings'   => $bookings,
            'categories' => ComplaintModel::CATEGORIES,
        ], 'layouts/app');
    }

    /** POST /complaints - file a new report. */
    public function store(): void
    {
        $this->requireLogin();
        $this->verifyCsrf();

        try {
            $complaint = (new ComplaintModel())->openComplaint([
                'category'    => $this->request->str('category', 'other'),
                'severity'    => $this->request->str('severity', 'medium'),
                'description' => $this->request->str('description'),
                'booking_id'  => $this->request->int('booking_id') ?: null,
            ], (int) Auth::id());
            Flash::success('Complaint ' . ($complaint['reference'] ?? '') . ' submitted. Our team will review it.');
        } catch (\App\Core\AppException $e) {
            Flash::withInput($_POST, []);
            Flash::error($e->getMessage());
        } catch (\Throwable $e) {
            ErrorHandler::log('error', 'Complaint creation failed: ' . $e->getMessage());
            Flash::error('We could not submit that complaint. Please try again.');
        }

        $this->redirect('/complaints');
    }

    private function safe(callable $callback, $fallback)
    {
        try {
            return $callback();
        } catch (\Throwable $e) {
            ErrorHandler::log('warning', 'Complaint data unavailable: ' . $e->getMessage());
            return $fallback;
        }
    }
}
