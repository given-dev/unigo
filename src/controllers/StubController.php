<?php
/**
 * UniGo - staff placeholder pages.
 *
 * Every staff navigation link resolves to a real page so the shell never
 * shows a 404. Each entry names the workspace it will become; full CRUD is
 * added role by role.
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;

final class StubController extends Controller
{
    /**
     * path => [heading, subtitle, role]
     *
     * @var array<string,array{0:string,1:string,2:string}>
     */
    public const PAGES = [
        // Driver
        '/driver/bookings'    => ['Boarding', 'Board passengers on your assigned trips.', 'driver'],
        '/driver/trips'       => ['My trips', 'Trips assigned to you, with boarding and status controls.', 'driver'],
        '/driver/tracking'    => ['Live tracking', 'Share your position and follow the trip progress.', 'driver'],
        '/driver/deliveries'  => ['Deliveries', 'Parcels assigned to your vehicle.', 'driver'],
        '/driver/earnings'    => ['Earnings', 'Trip and delivery earnings, payouts and statements.', 'driver'],
        '/driver/ratings'     => ['My ratings', 'Feedback left by passengers on your trips.', 'driver'],
        '/driver/sos'         => ['SOS log', 'Emergency alerts raised on your trips.', 'driver'],

        // Operator
        '/operator/trips'     => ['Trips', 'Schedule, dispatch and monitor your trips.', 'operator'],
        '/operator/vehicles'  => ['Fleet', 'Vehicles, seat layouts and compliance.', 'operator'],
        '/operator/drivers'   => ['Drivers', 'Your drivers, licences and assignments.', 'operator'],
        '/operator/routes'    => ['Routes', 'Routes, stops and fare tables.', 'operator'],
        '/operator/bookings'  => ['Bookings', 'Passenger bookings across your trips.', 'operator'],
        '/operator/revenue'   => ['Revenue', 'Income, refunds and settlement summaries.', 'operator'],
        '/operator/reports'   => ['Reports', 'Operational and financial reports.', 'operator'],
        '/operator/settings'  => ['Settings', 'Company profile and operating preferences.', 'operator'],

        // Authority
        '/authority/monitor'      => ['Live network', 'Network-wide vehicle map and congestion.', 'authority'],
        '/authority/emergencies'  => ['Emergencies', 'Open alerts requiring a coordinated response.', 'authority'],
        '/authority/complaints'   => ['Complaints', 'Public complaints and their resolution.', 'authority'],
        '/authority/operators'    => ['Operators', 'Licensed operators and compliance watchlist.', 'authority'],
        '/authority/reports'      => ['Reports', 'Safety and regulatory reporting.', 'authority'],
        '/authority/analytics'    => ['Analytics', 'Network performance and demand analytics.', 'authority'],

        // Admin
        '/admin/users'        => ['Users', 'All accounts, roles and statuses.', 'admin'],
        '/admin/operators'    => ['Operators', 'Operator accounts and approval.', 'admin'],
        '/admin/vehicles'     => ['Vehicles', 'Fleet registry and compliance.', 'admin'],
        '/admin/drivers'      => ['Drivers', 'Driver profiles and verification.', 'admin'],
        '/admin/routes'       => ['Routes', 'Route catalogue and stops.', 'admin'],
        '/admin/trips'        => ['Trips', 'All scheduled and live trips.', 'admin'],
        '/admin/bookings'     => ['Bookings', 'Every booking, its payment and status.', 'admin'],
        '/admin/deliveries'   => ['Deliveries', 'Parcel deliveries and tracking.', 'admin'],
        '/admin/payments'     => ['Payments', 'The ledger of simulated transactions.', 'admin'],
        '/admin/emergencies'  => ['Emergencies', 'All emergency alerts across the platform.', 'admin'],
        '/admin/complaints'   => ['Complaints', 'Every complaint and its resolution.', 'admin'],
        '/admin/ratings'      => ['Ratings', 'Passenger ratings for drivers and vehicles.', 'admin'],
        '/admin/reports'      => ['Reports', 'Platform-wide reporting.', 'admin'],
        '/admin/audit'        => ['Activity log', 'Administrative and system activity.', 'admin'],
        '/admin/settings'     => ['Settings', 'Platform configuration and demo controls.', 'admin'],
    ];

    public function show(): void
    {
        $this->requireLogin();

        $path  = Request::instance()->path();
        $entry = self::PAGES[$path] ?? null;

        if ($entry === null) {
            $this->redirect('/dashboard');
        }

        [$heading, $sub, $role] = $entry;

        if (!Auth::hasRole($role) && !Auth::isAdmin()) {
            Auth::deny('That workspace belongs to the ' . $role . ' role.');
        }

        $this->view('pages/coming-soon', [
            'title'     => $heading . ' - ' . app_name(),
            'pageTitle' => $heading,
            'pageSub'   => $sub,
            'heading'   => $heading,
            'message'   => $sub . ' This workspace is being built and is not available yet.',
            'area'      => ucfirst($role) . ' workspace',
        ], 'layouts/app');
    }
}
