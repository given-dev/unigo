<?php
/** Real-mode cash and GPS regression. Fixtures are always rolled back. */
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
use App\Core\{Auth, Database, AuthorizationException, ConflictException, ValidationException};
use App\Models\{BookingModel, UserModel};
use App\Services\{CashPaymentService, GpsService, MockPaymentGateway, PaymentService, SettingsService};
$checks=0;
function check(bool $ok,string $message): void { global $checks; if (!$ok) throw new RuntimeException($message); $checks++; }
function rejects(callable $call,string $type): void { try {$call();} catch (Throwable $e) {check($e instanceof $type,get_class($e).': '.$e->getMessage());return;} throw new RuntimeException('Unsafe operation accepted'); }
check(!is_demo_mode(),'This test requires real mode.');
$db=Database::instance();$db->beginTransaction();
try {
 $users=new UserModel();$key=bin2hex(random_bytes(4));
 $create=fn(string $role,array $profile,string $table)=>$users->createUser(['email'=>$role.$key.'@example.test','password_hash'=>Auth::hashPassword('Testing@2026'),'first_name'=>'Operations','last_name'=>$role],[$role],$profile,$table);
 $operator=$create('operator',['company_name'=>'Operational test','license_number'=>'O'.$key,'approval_status'=>'approved'],'operators');
 $oid=(int)$db->value('SELECT id FROM operators WHERE user_id=?',[$operator]);
 $driver=$create('driver',['operator_id'=>$oid,'license_number'=>'D'.$key,'status'=>'available'],'drivers');
 $did=(int)$db->value('SELECT id FROM drivers WHERE user_id=?',[$driver]);
 $passenger=$create('passenger',[],'passengers');
 $route=$db->insert('routes',['route_code'=>'O'.$key,'name'=>'Operations route','origin_name'=>'Origin','destination_name'=>'Destination','operator_id'=>$oid,'base_fare'=>15000]);
 $vehicle=$db->insert('vehicles',['registration_number'=>'O'.$key,'operator_id'=>$oid,'driver_id'=>$did,'capacity'=>4,'status'=>'active']);
 $trip=$db->insert('trips',['trip_code'=>'O'.$key,'route_id'=>$route,'vehicle_id'=>$vehicle,'operator_id'=>$oid,'driver_id'=>$did,'departure_time'=>date('Y-m-d H:i:s',strtotime('+60 days')),'arrival_time'=>date('Y-m-d H:i:s',strtotime('+60 days +1 hour')),'seats_total'=>4,'fare'=>15000,'status'=>'scheduled']);
 check(array_column(PaymentService::methods(),'value')===['cash'],'Unconfigured payment method offered.');
 SettingsService::set('demo_mode','1');check(!is_demo_mode(),'Database setting enabled simulation.');
 rejects(fn()=>PaymentService::useGateway(new MockPaymentGateway()),ConflictException::class);
 rejects(fn()=>PaymentService::charge($passenger,15000,'card'),ConflictException::class);
 check(!$db->exists('SELECT 1 FROM payments WHERE user_id=?',[$passenger]),'Rejected online payment wrote a receipt.');
 $model=new BookingModel();
 $result=$model->createBooking(['trip_id'=>$trip,'passenger_id'=>$passenger,'seat_number'=>'1','is_simulated'=>1]);$bid=(int)$result['booking']['id'];
 check($result['booking']['payment_status']==='unpaid','Cash booking prematurely marked paid.');
 check(!(bool)$result['booking']['is_simulated'],'Real booking marked simulated.');
 rejects(fn()=>Auth::asUser($passenger,fn()=>CashPaymentService::collect($bid)),AuthorizationException::class);
 $receipt=Auth::asUser($driver,fn()=>CashPaymentService::collect($bid));
 check($receipt['status']==='successful' && !(bool)$receipt['is_mock'] && $receipt['method']==='cash','Receipt is not an actual cash receipt.');
 check((float)$receipt['amount']===15000.0,'Receipt amount incorrect.');
 check((int)$receipt['initiated_by']===$driver,'Receipt actor missing.');
 check($db->value('SELECT payment_status FROM bookings WHERE id=?',[$bid])==='paid','Cash receipt did not mark booking paid.');
 rejects(fn()=>Auth::asUser($driver,fn()=>CashPaymentService::collect($bid)),ConflictException::class);
 $model->cancel($bid,$passenger,'Test return');
 check($db->value('SELECT payment_status FROM bookings WHERE id=?',[$bid])==='paid','Cancellation fabricated cash return.');
 rejects(fn()=>Auth::asUser($driver,fn()=>CashPaymentService::refund($bid)),AuthorizationException::class);
 Auth::asUser($operator,fn()=>CashPaymentService::refund($bid));
 check($db->value('SELECT payment_status FROM bookings WHERE id=?',[$bid])==='refunded','Staff refund not recorded.');
 check((float)$db->value('SELECT total_revenue FROM operators WHERE id=?',[$oid])===0.0,'Refund revenue incorrect.');
 rejects(fn()=>Auth::asUser($operator,fn()=>CashPaymentService::refund($bid)),ConflictException::class);
 rejects(fn()=>GpsService::simulateStep(),ValidationException::class);
 rejects(fn()=>GpsService::record($vehicle,0.3,32.5,0,0,'simulator'),ValidationException::class);
 GpsService::recordFromDevice($vehicle,0.3,32.5,20,90,10,$trip);
 $db->insert('vehicle_locations',['vehicle_id'=>$vehicle,'latitude'=>1,'longitude'=>33,'source'=>'simulator','is_simulated'=>1,'recorded_at'=>date('Y-m-d H:i:s',time()+60)]);
 check(!(bool)GpsService::latestForVehicle($vehicle)['is_simulated'],'Tracking chose simulated position.');
 check(!(bool)GpsService::latestPositions([$vehicle])[0]['is_simulated'],'Fleet map chose simulated position.');
 check(count(GpsService::trail($vehicle))===1,'Trail includes simulated position.');
 echo "Operational checks: $checks passed\n";
} finally {$db->rollback();if($db->pdo()->inTransaction())$db->pdo()->rollBack();}
