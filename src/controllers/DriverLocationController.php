<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\{Auth, Database, Response, ValidationException};
use App\Services\GpsService;

final class DriverLocationController extends Controller
{
    public function store(): void
    {
        $this->requireRole('driver');
        $this->verifyCsrf();
        $lat=$this->request->input('latitude'); $lon=$this->request->input('longitude');
        if (!is_numeric($lat) || !is_numeric($lon)) {
            Response::validation(['location'=>['Latitude and longitude are required.']])->send(); return;
        }
        $trip=Database::instance()->first("SELECT id,vehicle_id FROM trips WHERE driver_id = ? AND status IN ('boarding','in_transit') ORDER BY departure_time DESC LIMIT 1",[(int)Auth::driverId()]);
        if (!$trip) { Response::conflict('Start boarding an assigned trip before sharing GPS.')->send(); return; }
        try {
            $result=GpsService::recordFromDevice((int)$trip['vehicle_id'],(float)$lat,(float)$lon,$this->request->float('speed'),$this->request->float('heading'),$this->request->float('accuracy',10),(int)$trip['id']);
            Response::created($result,'Location shared.')->send();
        } catch (ValidationException $e) { Response::validation(['location'=>[$e->getMessage()]])->send(); }
    }
}
