<?php
/**
 * UniGo - public marketing and information pages.
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\ErrorHandler;
use App\Models\RouteModel;

final class HomeController extends Controller
{
    public function index(): void
    {
        $this->view('pages/home', [
            'title'    => app_name() . ' - ' . (string) Config::get('app.tagline', ''),
            'bodyClass' => 'public-page',
            'companies' => $this->safe(static fn () => \App\Core\Database::instance()->select("SELECT o.id,o.company_name,o.description, (SELECT COUNT(*) FROM routes r WHERE r.operator_id=o.id AND r.status='active') AS route_count, (SELECT COUNT(*) FROM trips t WHERE t.operator_id=o.id AND t.status IN ('scheduled','boarding') AND t.departure_time>NOW()) AS upcoming_trips FROM operators o JOIN users u ON u.id=o.user_id WHERE o.approval_status='approved' AND u.status='active' ORDER BY upcoming_trips DESC,o.company_name LIMIT 12"), []),
            'routes'   => $this->safe(static fn () => (new RouteModel())->popular(6), []),
            'withChart' => false,
        ], 'layouts/public');
    }

    public function schedule(): void
    {
        $search = trim($this->request->str('q'));
        $page   = max(1, $this->request->int('page', 1));
        $result = $this->safe(
            static fn () => (new RouteModel())->paginateRoutes(
                $search !== '' ? ['search' => $search] : [],
                $page,
                12
            ),
            ['items' => [], 'paginator' => null]
        );

        $this->view('pages/schedule', [
            'title'      => 'Trip schedule - ' . app_name(),
            'bodyClass'  => 'public-page',
            'routes'     => $result['items'] ?? [],
            'paginator'  => $result['paginator'] ?? null,
            'search'     => $search,
        ], 'layouts/public');
    }

    public function about(): void
    {
        $this->view('pages/about', [
            'title'     => 'About - ' . app_name(),
            'bodyClass' => 'public-page',
        ], 'layouts/public');
    }

    public function support(): void
    {
        $this->view('pages/support', [
            'title'     => 'Support - ' . app_name(),
            'bodyClass' => 'public-page',
        ], 'layouts/public');
    }

    public function contact(): void
    {
        $this->view('pages/contact', [
            'title'     => 'Contact - ' . app_name(),
            'bodyClass' => 'public-page',
        ], 'layouts/public');
    }

    public function privacy(): void
    {
        $this->view('pages/legal', [
            'title'     => 'Privacy policy - ' . app_name(),
            'bodyClass' => 'public-page',
            'heading'   => 'Privacy policy',
            'updated'   => 'January 2026',
            'sections'  => $this->privacySections(),
        ], 'layouts/public');
    }

    public function terms(): void
    {
        $this->view('pages/legal', [
            'title'     => 'Terms of use - ' . app_name(),
            'bodyClass' => 'public-page',
            'heading'   => 'Terms of use',
            'updated'   => 'January 2026',
            'sections'  => $this->termsSections(),
        ], 'layouts/public');
    }

    /** Run a data callback, degrading gracefully when the database is absent. */
    private function safe(callable $callback, $fallback)
    {
        try {
            return $callback();
        } catch (\Throwable $e) {
            ErrorHandler::log('warning', 'Public page data unavailable: ' . $e->getMessage());
            return $fallback;
        }
    }

    /** @return array<int,array{title:string,body:string}> */
    private function privacySections(): array
    {
        return [
            ['title' => 'What we collect', 'body' => 'Account details you provide (name, email, phone), trip and booking history, delivery records, and the device data needed to run the service. Location is only shared while you actively track a trip.'],
            ['title' => 'How we use it', 'body' => 'To match you with trips, process bookings and payments, keep drivers and operators coordinated, and improve safety. Cash receipts are recorded by authorized staff after collection.'],
            ['title' => 'Who can see it', 'body' => 'Operators see the bookings on their own trips. Authorities see anonymised network data and safety reports. We never sell personal data.'],
            ['title' => 'Your choices', 'body' => 'You can update your profile, control notifications, and request deletion of your account at any time from your dashboard or by contacting support.'],
        ];
    }

    /** @return array<int,array{title:string,body:string}> */
    private function termsSections(): array
    {
        return [
            ['title' => 'Using UniGo', 'body' => 'You agree to provide accurate details, keep your password secure, and use the platform lawfully. Accounts that abuse the service may be suspended.'],
            ['title' => 'Bookings and payments', 'body' => 'Fares are confirmed at the time of booking. Cash payment is recorded after collection; online methods require a connected provider.'],
            ['title' => 'Deliveries', 'body' => 'Parcel contents must be lawful and accurately described. UniGo may refuse or return items that breach these terms.'],
            ['title' => 'Service availability', 'body' => 'Companies maintain their own trip schedules. Tracking depends on fresh driver location reports. Emergency reports enter the staff workspace; contact emergency services directly when urgent.'],
        ];
    }
}
