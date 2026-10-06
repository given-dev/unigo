<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
use App\Core\{Auth, Config, Database, ConflictException, AuthorizationException, ValidationException};
use App\Models\{UserModel, BookingModel};
use App\Services\{PaymentService, CashPaymentService, GpsService};
$db=Database::instance(); $checks=0;
function verifyLive(bool $ok,string $message): void {global $checks; if (!$ok) throw new RuntimeException($message); $checks++;}
$db->beginTransaction();
try {
 Config::setOverride('domain.demo_mode',false);
 verifyLive(array_column(PaymentService::methods(),'value')===['cash'],'Only cash should be offered without a provider.');
 $failed=false; try { PaymentService::charge(1,1000,'card'); } catch(ValidationException|ConflictException $e) {$failed=true;}
 verifyLive($failed,'Unconnected online payments must not succeed.');
 $failed=false; try { GpsService::simulateStep(); } catch(ValidationException $e) {$failed=true;}
 verifyLive($failed,'Live mode must disable GPS simulation.');
 verifyLive(array_filter((new \App\Models\VehicleModel())->positionsForMap(),fn($p)=>(bool)$p['is_simulated'])===[],'Live maps must hide seeded simulated positions.');
 $users=new UserModel();$model=new BookingModel();
 $passenger=$users->createUser(['email'=>'live-'.bin2hex(random_bytes(5)).'@example.test','password_hash'=>Auth::hashPassword('Testing2026!'),'first_name'=>'Live','last_name'=>'Passenger'],['passenger'],['city'=>'Kampala'],'passengers');
 $trip=$db->first("SELECT * FROM trips WHERE status='scheduled' AND departure_time>DATE_ADD(NOW(),INTERVAL 1 HOUR) ORDER BY departure_time LIMIT 1");
 $gps=GpsService::record((int)$trip['vehicle_id'],0.3476,32.5825,0,0,'driver_phone',(int)$trip['id']);
 verifyLive(count(array_filter((new \App\Models\VehicleModel())->positionsForMap(),fn($p)=>(int)$p['id']===(int)$trip['vehicle_id']))===1,'Fresh real driver reports appear on maps.');
 $db->run('UPDATE vehicle_locations SET recorded_at=DATE_SUB(NOW(),INTERVAL 6 MINUTE) WHERE id=?',[$gps['id']]);
 verifyLive(array_values(array_filter((new \App\Models\VehicleModel())->positionsForMap(),fn($p)=>(int)$p['id']===(int)$trip['vehicle_id']))===[],'Stale reports must not appear as current vehicle positions.');
 $seats=array_values(array_filter($model->bookableSeats((int)$trip['id'],$passenger),fn($s)=>$s['is_available']));
 $data=['trip_id'=>$trip['id'],'passenger_id'=>$passenger,'seat_number'=>$seats[0]['seat_number'],'payment_method'=>'cash','is_simulated'=>1];
 $booking=$model->createBooking($data,$passenger)['booking'];
 verifyLive($booking['payment_status']==='unpaid' && !(bool)$booking['is_simulated'],'A live booking reserves the seat without pretending it was paid.');
 $failed=false; try {Auth::asUser($passenger,fn()=>CashPaymentService::record((int)$booking['id']));}catch(AuthorizationException $e){$failed=true;}
 verifyLive($failed,'Passengers cannot confirm their own cash payment.');
 $admin=(int)$db->value("SELECT u.id FROM users u JOIN user_roles ur ON ur.user_id=u.id JOIN roles r ON r.id=ur.role_id WHERE r.slug='admin' LIMIT 1");
 $payment=Auth::asUser($admin,fn()=>CashPaymentService::record((int)$booking['id']));
 verifyLive($payment['status']==='successful' && !(bool)$payment['is_mock'] && $payment['provider']==='cash_collection','Collection must create a real cash receipt.');
 verifyLive((float)$db->value('SELECT total_spent FROM passengers WHERE user_id=?',[$passenger])===(float)$booking['fare'],'Only collected cash contributes to spending.');
 $failed=false;try{Auth::asUser($admin,fn()=>CashPaymentService::record((int)$booking['id']));}catch(ConflictException $e){$failed=true;}
 verifyLive($failed && $db->count('SELECT COUNT(*) FROM payments WHERE booking_id=?',[$booking['id']])===1,'Cash collection cannot be recorded twice.');
 $model->cancel((int)$booking['id'],$admin,'Cash refund test',true);
 verifyLive($db->value('SELECT payment_status FROM bookings WHERE id=?',[$booking['id']])==='paid','Cancellation must not falsely claim cash was refunded.');
 Auth::asUser($admin,fn()=>CashPaymentService::record((int)$booking['id'],true));
 verifyLive($db->value('SELECT payment_status FROM bookings WHERE id=?',[$booking['id']])==='refunded','Staff records cash returned after cancellation.');
 verifyLive((float)$db->value('SELECT total_spent FROM passengers WHERE user_id=?',[$passenger])===0.0,'Returned cash reverses totals.');
 $failed=false;try{Auth::asUser($admin,fn()=>CashPaymentService::record((int)$booking['id'],true));}catch(ConflictException $e){$failed=true;}
 verifyLive($failed,'Cash refunds cannot be recorded twice.');
 echo "Live operation checks passed: $checks\n";
} finally {$db->rollback();}
