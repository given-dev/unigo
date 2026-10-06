<?php
/**
 * UniGo - passenger SOS / emergency reporting.
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\ErrorHandler;
use App\Core\Flash;
use App\Models\EmergencyModel;

final class EmergencyController extends Controller
{
    /** /emergency/new - SOS form. */
    public function create(): void
    {
        $this->requireLogin();

        $this->view('emergency/create', [
            'title'     => 'Emergency SOS - ' . app_name(),
            'pageTitle' => 'Emergency SOS',
            'pageSub'   => 'Raise an alert with our response team',
            'types'     => EmergencyModel::TYPES,
            'severities' => EmergencyModel::SEVERITIES,
            'demo'      => is_demo_mode(),
        ], 'layouts/app');
    }

    /** POST /emergency - raise an alert. */
    public function store(): void
    {
        $this->requireLogin();
        $this->verifyCsrf();

        try {
            $alert = (new EmergencyModel())->raise((int) Auth::id(), [
                'emergency_type' => $this->request->str('emergency_type', 'other'),
                'severity'       => $this->request->str('severity', 'high'),
                'description'    => $this->request->str('description'),
                'contact_phone'  => $this->request->str('contact_phone'),
                'latitude'       => $this->request->input('latitude') === '' ? null : $this->request->input('latitude'),
                'longitude'      => $this->request->input('longitude') === '' ? null : $this->request->input('longitude'),
                'address_text'   => $this->request->str('address_text'),
                'notify_police'  => $this->request->bool('notify_police') ? 1 : 0,
                'is_simulated'   => is_demo_mode() ? 1 : 0,
            ]);
            Flash::success('Alert ' . ($alert['reference'] ?? '') . ' raised. Our team has been notified.');
        } catch (\App\Core\AppException $e) {
            Flash::withInput($_POST, []);
            Flash::error($e->getMessage());
            $this->redirect('/emergency/new');
        } catch (\Throwable $e) {
            ErrorHandler::log('error', 'Emergency raise failed: ' . $e->getMessage());
            Flash::error('We could not raise your alert. Please call the emergency line directly.');
            $this->redirect('/emergency/new');
        }

        $this->redirect('/home');
    }
}
