<?php

declare(strict_types=1);
namespace App\Controllers;

use App\Core\{Auth, ActivityLog, Database, Flash, Paginator, Request, ValidationException, AuthorizationException, NotFoundException};
use App\Models\{TripModel, BookingModel, DeliveryModel, ComplaintModel, EmergencyModel, UserModel, VehicleModel};
use App\Services\{GpsService, SettingsService};

/** Staff records are explicitly scoped before any read or mutation. */
final class StaffController extends Controller
{
    private const RESOURCES = [
        'users' => ['users', 'id,email,first_name,last_name,phone,status,created_at'],
        'operators' => ['operators', 'id,company_name,license_number,contact_phone,approval_status,total_revenue'],
        'drivers' => ['drivers', 'id,user_id,license_number,operator_id,status,rating_avg,total_trips'],
        'vehicles' => ['vehicles', 'id,registration_number,vehicle_type,capacity,operator_id,driver_id,status,inspection_status'],
        'routes' => ['routes', 'id,route_code,name,origin_name,destination_name,base_fare,duration_minutes,status'],
        'trips' => ['trips', 'id,trip_code,route_id,vehicle_id,driver_id,departure_time,arrival_time,fare,status'],
        'bookings' => ['bookings', 'id,reference,trip_id,passenger_id,seat_number,status,payment_status,fare,boarded_at'],
        'deliveries' => ['deliveries', 'id,tracking_number,vehicle_id,recipient_name,recipient_phone,pickup_address,dropoff_address,status,price'],
        'payments' => ['payments', 'id,reference,user_id,booking_id,amount,method,status,is_mock,created_at'],
        'emergencies' => ['emergency_alerts', 'id,reference,trip_id,emergency_type,description,contact_phone,severity,status,resolution_note'],
        'sos' => ['emergency_alerts', 'id,reference,trip_id,emergency_type,description,contact_phone,severity,status'],
        'complaints' => ['complaints', 'id,reference,category,description,severity,status,resolution'],
        'ratings' => ['ratings', 'id,trip_id,driver_id,rating,comment,created_at'],
        'audit' => ['activity_logs', 'id,user_id,action,entity_type,entity_id,description,created_at'],
    ];

    private function workspace(): array
    {
        $path = $this->request->path();
        $entry = StubController::PAGES[$path] ?? null;
        if (!$entry) {
            throw new NotFoundException('Workspace not found.');
        }
        $this->requireRole($entry[2], 'admin');
        return [$entry[2], basename($path), $entry];
    }

    /** Returns SQL written only from our resource and role allowlists. */
    private function scope(string $role, string $resource): array
    {
        if ($role === 'admin' || $role === 'authority') return ['1', []];
        if ($role === 'operator') {
            $id = Auth::operatorId();
            if (!$id) throw new AuthorizationException('An operator profile is required.');
            return match ($resource) {
                'vehicles', 'drivers', 'routes', 'trips', 'deliveries' => ['operator_id = ?', [$id]],
                'bookings' => ['trip_id IN (SELECT id FROM trips WHERE operator_id = ?)', [$id]],
                default => ['0', []],
            };
        }
        $id = Auth::driverId();
        if (!$id) throw new AuthorizationException('A driver profile is required.');
        return match ($resource) {
            'trips', 'ratings' => ['driver_id = ?', [$id]],
            'bookings', 'sos' => ['trip_id IN (SELECT id FROM trips WHERE driver_id = ?)', [$id]],
            'deliveries' => ["trip_id IN (SELECT id FROM trips WHERE driver_id = ?) OR vehicle_id IN (SELECT vehicle_id FROM trips WHERE driver_id = ? AND status IN ('boarding','in_transit')) OR vehicle_id IN (SELECT id FROM vehicles WHERE driver_id = ?)", [$id,$id,$id]],
            default => ['0', []],
        };
    }

    public function index(): void
    {
        [$role, $resource, $entry] = $this->workspace();
        $db = Database::instance();
        $rows = []; $columns = []; $paginator = null; $map = null; $report = [];
        if (isset(self::RESOURCES[$resource])) {
            [$table, $select] = self::RESOURCES[$resource];
            [$where, $params] = $this->scope($role, $resource);
            $term = $this->request->str('search');
            if ($term !== '') {
                $conditions = [];
                foreach (explode(',', $select) as $column) { $conditions[] = "CAST(`$column` AS CHAR) LIKE ?"; $params[] = '%' . $term . '%'; }
                $where = '(' . $where . ')';
                $where .= ' AND (' . implode(' OR ', $conditions) . ')';
            }
            $total = $db->count("SELECT COUNT(*) FROM `$table` WHERE $where", $params);
            $paginator = new Paginator($total, $this->request->int('page', 1), 20);
            $rows = $db->select("SELECT $select FROM `$table` WHERE $where ORDER BY id DESC LIMIT " . $paginator->limit() . ' OFFSET ' . $paginator->offset(), $params);
            $columns = explode(',', $select);
        } elseif (in_array($resource, ['monitor', 'tracking'], true)) {
            $ids = null;
            if ($role === 'driver') {
                $ids = array_column($db->select('SELECT id FROM vehicles WHERE driver_id = ? OR id IN (SELECT vehicle_id FROM trips WHERE driver_id = ?)', [(int) Auth::driverId(), (int) Auth::driverId()]), 'id');
                if (!$ids) $ids = [0];
            }
            $positions = GpsService::latestPositions($ids);
            $map = ['center' => [0.3476,32.5825], 'zoom' => 12, 'vehicles' => array_map(static fn ($p) => [
                'id' => (int) $p['vehicle_id'], 'lat' => (float) $p['latitude'], 'lng' => (float) $p['longitude'],
                'label' => $p['registration_number'], 'status' => $p['status'], 'simulated' => (bool) $p['is_simulated'],
            ], $positions)];
        } elseif ($resource === 'settings') {
            if ($role === 'admin') {
                $rows = $db->select('SELECT setting_key,setting_value,label FROM system_settings ORDER BY group_name,setting_key');
                $columns = ['setting_key','setting_value','label'];
            } else {
                $rows = $db->select('SELECT company_name,contact_email,contact_phone,address,description FROM operators WHERE id = ?', [(int) Auth::operatorId()]);
                $columns = ['company_name','contact_email','contact_phone','address','description'];
            }
        } else {
            $report = $this->report($role);
        }
        $choices = [];
        if (in_array($resource, ['trips','vehicles','routes','deliveries','drivers'], true) && in_array($role, ['admin','operator'], true)) {
            foreach (['vehicles' => 'registration_number', 'routes' => 'name', 'drivers' => 'license_number'] as $table => $label) {
                [$where,$params] = $this->scope($role, $table);
                $choices[$table] = $db->select("SELECT id, `$label` AS label FROM `$table` WHERE $where ORDER BY id LIMIT 500", $params);
            }
            if ($role === 'admin') $choices['operators'] = $db->select('SELECT id,company_name AS label FROM operators ORDER BY company_name LIMIT 500');
        }
        $manifest = [];
        if ($resource === 'trips' && $this->request->int('trip') > 0) {
            [$where,$params] = $this->scope($role, 'trips');
            $id = $this->request->int('trip');
            if (!$db->exists("SELECT 1 FROM trips WHERE id = ? AND ($where)", array_merge([$id],$params))) throw new AuthorizationException('This trip is outside your workspace.');
            $manifest = (new BookingModel())->passengersForTrip($id);
        }
        $this->view('staff/workspace', compact('role','resource','rows','columns','paginator','choices','map','report','manifest') + [
            'title' => $entry[0] . ' - UniGo', 'pageTitle' => $entry[0], 'pageSub' => $entry[1], 'withLeaflet' => $map !== null,
        ], 'layouts/app');
    }

    private function report(string $role): array
    {
        $db = Database::instance();
        [$where,$params] = $this->scope($role, 'trips');
        $trips = $db->select("SELECT status,COUNT(*) AS total FROM trips WHERE $where GROUP BY status", $params);
        [$bw,$bp] = $this->scope($role, 'bookings');
        $revenue = $db->first("SELECT COUNT(*) AS bookings,COALESCE(SUM(CASE WHEN payment_status = 'paid' AND status <> 'cancelled' THEN fare ELSE 0 END),0) AS collected_fares FROM bookings WHERE $bw", $bp);
        return ['trips' => $trips, 'bookings' => $revenue['bookings'] ?? 0, 'collected_fares' => $revenue['collected_fares'] ?? 0];
    }

    public function save(): void
    {
        [$role,$resource] = $this->workspace();
        $this->verifyCsrf();
        $db = Database::instance();
        try {
            $db->transaction(function () use ($db,$role,$resource): void {
                $action = $this->request->str('action');
                if ($action === 'create') { $this->create($role,$resource); return; }
                if ($resource === 'settings') { $this->settings($role); return; }
                if (!isset(self::RESOURCES[$resource])) throw new ValidationException('No update is available here.');
                [$table] = self::RESOURCES[$resource];
                [$where,$params] = $this->scope($role,$resource);
                $id = $this->request->int('id');
                $row = $db->first("SELECT * FROM `$table` WHERE id = ? AND ($where) FOR UPDATE", array_merge([$id],$params));
                if (!$row) throw new AuthorizationException('This record is outside your workspace.');
                $status = $this->request->str('status');
                $note = substr($this->request->str('note'),0,500);
                if ($resource === 'trips') {
                    (new TripModel())->updateStatus($id,$status,(int) Auth::id(),$note);
                } elseif ($resource === 'bookings') {
                    if ($action === 'collect_cash') {
                        \App\Services\CashPaymentService::collect($id);
                    } elseif ($action === 'refund_cash') {
                        \App\Services\CashPaymentService::refund($id);
                    } elseif ($action === 'board' && $role === 'driver') {
                        $trip = $db->first('SELECT status FROM trips WHERE id = ?', [(int) $row['trip_id']]);
                        if ($row['status'] !== 'confirmed' || !in_array($trip['status'],['boarding','in_transit'],true)) throw new ValidationException('Only confirmed bookings on boarding or running trips can board.');
                        $db->update('bookings',['boarded_at' => date('Y-m-d H:i:s')],'id = ?',[$id]);
                    } else {
                        if (!in_array($role,['admin','operator'],true)) throw new AuthorizationException('You cannot cancel this booking.');
                        (new BookingModel())->cancel($id,(int) Auth::id(),$note,$role === 'admin');
                    }
                } elseif ($resource === 'deliveries') {
                    if ($action === 'assign' && $role === 'admin') {
                        $vehicle = $db->first('SELECT * FROM vehicles WHERE id = ?',[$this->request->int('vehicle_id')]);
                        if (!$vehicle) throw new ValidationException('Choose a vehicle.');
                        (new DeliveryModel())->assign($id,(int) $vehicle['id'],null,$vehicle['operator_id'] ? (int) $vehicle['operator_id'] : null,(int) Auth::id());
                    } else (new DeliveryModel())->updateStatus($id,$status,['description'=>$note],(int) Auth::id());
                } elseif ($resource === 'complaints' && in_array($role,['admin','authority'],true)) {
                    (new ComplaintModel())->resolve($id,$status,$note,(int) Auth::id());
                } elseif ($resource === 'emergencies' && in_array($role,['admin','authority'],true)) {
                    (new EmergencyModel())->updateStatus($id,$status,$note,(int) Auth::id());
                } elseif ($resource === 'users' && $role === 'admin') {
                    if ($id === (int) Auth::id()) throw new ValidationException('Change your own profile from Profile.');
                    $this->enum($status,['active','inactive','suspended']);
                    (new UserModel())->setStatus($id,$status,(int) Auth::id());
                } elseif ($resource === 'operators' && in_array($role,['admin','authority'],true)) {
                    $this->enum($status,['pending','approved','rejected','suspended']);
                    $db->update('operators',['approval_status'=>$status,'approved_by'=>Auth::id(),'approved_at'=>$status === 'approved' ? date('Y-m-d H:i:s') : null],'id = ?',[$id]);
                } elseif ($resource === 'vehicles' && in_array($role,['admin','operator'],true)) {
                    $this->enum($status,['active','inactive','maintenance','suspended']);
                    if ($row['status'] === 'on_trip') throw new ValidationException('Finish the vehicle trip before changing fleet status.');
                    $db->update('vehicles',['status'=>$status],'id = ?',[$id]);
                } elseif ($resource === 'drivers' && in_array($role,['admin','operator'],true)) {
                    $this->enum($status,['available','off_duty','suspended']);
                    if ($row['status'] === 'on_trip') throw new ValidationException('Finish the driver trip first.');
                    $db->update('drivers',['status'=>$status],'id = ?',[$id]);
                } elseif ($resource === 'routes' && in_array($role,['admin','operator'],true)) {
                    $this->enum($status,['active','inactive','suspended']);
                    $db->update('routes',['status'=>$status],'id = ?',[$id]);
                } elseif ($resource === 'ratings' && $role === 'admin') {
                    $this->enum($status, ['published', 'hidden']);
                    (new \App\Models\RatingModel())->setPublished($id, $status === 'published');
                } else throw new AuthorizationException('This action is not permitted.');
                ActivityLog::record('staff.updated',$id,$resource,'Updated ' . $resource . ' status to ' . $status);
            });
            Flash::success('Changes saved.');
        } catch (\App\Core\AppException $e) { Flash::error($e->getMessage());
        } catch (\Throwable $e) { \App\Core\ErrorHandler::log('error','Staff update failed: ' . $e->getMessage()); Flash::error('The change could not be saved. Check the values and try again.'); }
        $this->redirect($this->request->path());
    }

    private function enum(string $value,array $values): void
    {
        if (!in_array($value,$values,true)) throw new ValidationException('Choose a valid status.');
    }

    private function required(string $name,int $max=120): string
    {
        $value = $this->request->str($name);
        if ($value === '' || mb_strlen($value) > $max) throw new ValidationException(ucwords(str_replace('_',' ',$name)) . ' is required and must be at most ' . $max . ' characters.');
        return $value;
    }

    private function create(string $role,string $resource): void
    {
        if (!in_array($role,['admin','operator'],true)) throw new AuthorizationException('You cannot create records here.');
        $db = Database::instance();
        $operator = $role === 'operator' ? (int) Auth::operatorId() : ($this->request->int('operator_id') ?: null);
        if ($role === 'operator' && !$operator) throw new AuthorizationException('An operator profile is required.');
        if ($resource === 'vehicles') {
            $type = $this->request->str('vehicle_type'); $this->enum($type,VehicleModel::TYPES);
            $capacity = $this->request->int('capacity');
            if ($capacity < 1 || $capacity > 200) throw new ValidationException('Capacity must be between 1 and 200.');
            $id = $db->insert('vehicles',['registration_number'=>strtoupper($this->required('registration_number',20)),'vehicle_type'=>$type,'capacity'=>$capacity,'operator_id'=>$operator,'status'=>'active']);
            for ($i=1;$i<=$capacity;$i++) $db->insert('seats',['vehicle_id'=>$id,'seat_number'=>(string)$i,'row_number'=>(int)ceil($i/4),'is_active'=>1]);
        } elseif ($resource === 'routes') {
            $fare=$this->request->float('base_fare'); $minutes=$this->request->int('duration_minutes');
            if ($fare <= 0 || $minutes < 1 || $minutes > 65535) throw new ValidationException('Enter a positive fare and duration.');
            foreach (['origin_latitude','destination_latitude'] as $key) if (!\App\Core\Validator::isLatitude($this->request->str($key))) throw new ValidationException('Enter valid route latitudes.');
            foreach (['origin_longitude','destination_longitude'] as $key) if (!\App\Core\Validator::isLongitude($this->request->str($key))) throw new ValidationException('Enter valid route longitudes.');
            $db->insert('routes',['origin_latitude'=>$this->request->float('origin_latitude'),'origin_longitude'=>$this->request->float('origin_longitude'),'destination_latitude'=>$this->request->float('destination_latitude'),'destination_longitude'=>$this->request->float('destination_longitude'),'route_code'=>$this->required('route_code',20),'name'=>$this->required('name'),'origin_name'=>$this->required('origin_name'),'destination_name'=>$this->required('destination_name'),'base_fare'=>$fare,'duration_minutes'=>$minutes,'operator_id'=>$operator,'status'=>'active']);
        } elseif ($resource === 'trips') {
            $vehicle=$db->first('SELECT * FROM vehicles WHERE id = ? FOR UPDATE',[$this->request->int('vehicle_id')]);
            $route=$db->first('SELECT * FROM routes WHERE id = ?',[$this->request->int('route_id')]);
            if (!$vehicle || !$route || $vehicle['status'] !== 'active' || $route['status'] !== 'active') throw new ValidationException('Choose an active vehicle and route.');
            if ($role === 'operator' && ((int)$vehicle['operator_id'] !== $operator || (int)$route['operator_id'] !== $operator)) throw new AuthorizationException('Choose your own vehicle and route.');
            $driver=$this->request->int('driver_id');
            if ($driver && !$db->exists("SELECT 1 FROM drivers WHERE id = ? AND status = 'available' AND (operator_id <=> ?)",[$driver,$vehicle['operator_id']])) throw new ValidationException('Choose an available driver from this operator.');
            $departure=strtotime($this->required('departure_time',30)); $arrival=strtotime($this->required('arrival_time',30));
            if (!$departure || !$arrival || $departure <= time() || $arrival <= $departure) throw new ValidationException('Departure must be in the future and arrival after departure.');
            $fare=$this->request->float('fare'); if ($fare <= 0) throw new ValidationException('Enter a positive fare.');
            (new TripModel())->createTrip(['vehicle_id'=>(int)$vehicle['id'],'route_id'=>(int)$route['id'],'driver_id'=>$driver ?: null,'operator_id'=>$vehicle['operator_id'],'departure_time'=>date('Y-m-d H:i:s',$departure),'arrival_time'=>date('Y-m-d H:i:s',$arrival),'fare'=>$fare],(int)Auth::id());
        } elseif (in_array($resource,['users','drivers','operators'],true)) {
            if ($resource === 'users' && $role !== 'admin') throw new AuthorizationException('Only admins create users.');
            $email=$this->required('email',150); $password=$this->required('password',100);
            if (!filter_var($email,FILTER_VALIDATE_EMAIL)) throw new ValidationException('Enter a valid email.');
            if (!\App\Core\Validator::isStrongPassword($password)) throw new ValidationException(\App\Core\Validator::passwordHint());
            $accountRole=$resource === 'users' ? $this->request->str('role','passenger') : ($resource === 'drivers' ? 'driver' : 'operator');
            $this->enum($accountRole,['passenger','driver','operator','authority','admin']);
            if ($resource === 'users' && !in_array($accountRole,['passenger','authority','admin'],true)) throw new ValidationException('Create driver and operator accounts in their respective workspaces.');
            $profileTable=$accountRole === 'driver' ? 'drivers' : ($accountRole === 'operator' ? 'operators' : ($accountRole === 'passenger' ? 'passengers' : ''));
            $profile=$accountRole === 'driver' ? ['license_number'=>$this->required('license_number',60),'operator_id'=>$operator,'status'=>'available'] : ($accountRole === 'operator' ? ['company_name'=>$this->required('company_name',150),'license_number'=>$this->required('license_number',60)] : []);
            $userId=(new UserModel())->createUser(['email'=>$email,'password_hash'=>Auth::hashPassword($password),'first_name'=>$this->required('first_name',60),'last_name'=>$this->required('last_name',60),'phone'=>$this->required('phone',25)],[$accountRole],$profile,$profileTable);
            if ($accountRole === 'passenger') $db->insert('passengers',['user_id'=>$userId]);
        } else throw new ValidationException('No creation form is available here.');
        ActivityLog::record('staff.created',null,$resource,'Created ' . $resource);
    }

    private function settings(string $role): void
    {
        if ($role === 'admin') {
            $key=$this->required('setting_key',80);
            $allowed=['support_phone','support_email','emergency_hotline','cancellation_window_minutes'];
            if (!in_array($key,$allowed,true)) throw new ValidationException('This setting is not editable here.');
            $value=$this->required('setting_value',200);
            if ($key === 'support_email' && !filter_var($value,FILTER_VALIDATE_EMAIL)) throw new ValidationException('Enter a valid support email.');
            if ($key === 'cancellation_window_minutes' && (!ctype_digit($value) || (int)$value > 1440)) throw new ValidationException('Enter a cancellation window from 0 to 1440 minutes.');
            SettingsService::set($key,$value);
        } elseif ($role === 'operator') {
            Database::instance()->update('operators',['company_name'=>$this->required('company_name',150),'contact_email'=>$this->required('contact_email',150),'contact_phone'=>$this->required('contact_phone',25),'address'=>$this->request->str('address')],'id = ?',[(int)Auth::operatorId()]);
        } else throw new AuthorizationException('You cannot edit these settings.');
    }
}
