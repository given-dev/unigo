<?php
/**
 * UniGo - parcel deliveries (passenger / customer).
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\ErrorHandler;
use App\Core\Flash;
use App\Models\DeliveryModel;

final class DeliveryController extends Controller
{
    /** /deliveries - the user's parcels. */
    public function index(): void
    {
        $this->requireLogin();

        $page   = max(1, $this->request->int('page', 1));
        $result = $this->safe(
            static fn () => (new DeliveryModel())->paginateDeliveries(
                ['customer_id' => (int) Auth::id()],
                $page,
                10
            ),
            ['items' => [], 'paginator' => null]
        );

        $this->view('deliveries/index', [
            'title'     => 'My parcels - ' . app_name(),
            'pageTitle' => 'My parcels',
            'pageSub'   => 'Track and send deliveries',
            'deliveries' => $result['items'] ?? [],
            'paginator' => $result['paginator'] ?? null,
        ], 'layouts/app');
    }

    /** /deliveries/new - send a parcel form. */
    public function create(): void
    {
        $this->requireLogin();
        $this->view('deliveries/create', [
            'title'     => 'Send a parcel - ' . app_name(),
            'pageTitle' => 'Send a parcel',
            'pageSub'   => 'Request a pickup and delivery',
        ], 'layouts/app');
    }

    /** POST /deliveries - create a parcel request. */
    public function store(): void
    {
        $this->requireLogin();
        $this->verifyCsrf();

        $required = ['recipient_name', 'recipient_phone', 'pickup_address', 'dropoff_address', 'parcel_description'];
        foreach ($required as $field) {
            if (trim($this->request->str($field)) === '') {
                Flash::error('Please complete all required parcel details.');
                Flash::withInput($_POST, []);
                $this->redirect('/deliveries/new');
            }
        }

        try {
            $delivery = (new DeliveryModel())->createDelivery([
                'recipient_name'     => $this->request->str('recipient_name'),
                'recipient_phone'    => $this->request->str('recipient_phone'),
                'pickup_address'     => $this->request->str('pickup_address'),
                'dropoff_address'    => $this->request->str('dropoff_address'),
                'parcel_description' => $this->request->str('parcel_description'),
                'weight_kg'          => $this->request->input('weight_kg', 1.0),
                'is_fragile'         => $this->request->bool('is_fragile') ? 1 : 0,
                'declared_value'     => $this->request->input('declared_value', 0.0),
                'pickup_latitude'    => null,
                'pickup_longitude'   => null,
                'dropoff_latitude'   => null,
                'dropoff_longitude'  => null,
            ], (int) Auth::id());
        } catch (\App\Core\AppException $e) {
            Flash::withInput($_POST, []);
            Flash::error($e->getMessage());
            $this->redirect('/deliveries/new');
        } catch (\Throwable $e) {
            ErrorHandler::log('error', 'Delivery creation failed: ' . $e->getMessage());
            Flash::error('We could not create that parcel request. Please try again.');
            $this->redirect('/deliveries/new');
        }

        Flash::success('Parcel request created. Tracking: ' . ($delivery['tracking_number'] ?? ''));
        $this->redirect('/deliveries/' . urlencode((string) ($delivery['tracking_number'] ?? '')));
    }

    /** /deliveries/{tracking} - follow one parcel. */
    public function show(string $tracking): void
    {
        $this->requireLogin();

        $delivery = $this->safe(
            static fn () => (new DeliveryModel())->findByTracking($tracking),
            null
        );
        if (!$delivery) {
            $this->notFound();
        }
        if ((int) ($delivery['customer_id'] ?? 0) !== (int) Auth::id() && !Auth::isAdmin()) {
            Auth::deny('That parcel does not belong to you.');
        }

        $history = $this->safe(
            static fn () => (new DeliveryModel())->history((int) $delivery['id']),
            []
        );

        $this->view('deliveries/show', [
            'title'     => 'Parcel ' . ($delivery['tracking_number'] ?? '') . ' - ' . app_name(),
            'pageTitle' => 'Parcel tracking',
            'pageSub'   => (string) ($delivery['tracking_number'] ?? ''),
            'delivery'  => $delivery,
            'history'   => $history,
        ], 'layouts/app');
    }

    private function notFound(): void
    {
        \App\Core\Http::status(404);
        $this->view('errors/404', [
            'title'   => 'Parcel not found',
            'heading' => 'Parcel not found',
            'message' => 'We could not find a parcel with that tracking number.',
        ], 'layouts/app');
        exit;
    }

    private function safe(callable $callback, $fallback)
    {
        try {
            return $callback();
        } catch (\Throwable $e) {
            ErrorHandler::log('warning', 'Delivery data unavailable: ' . $e->getMessage());
            return $fallback;
        }
    }
}
