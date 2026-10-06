<?php
/** Run against a disposable database imported from schema.sql and seeded. */
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use App\Core\{Database, Auth, ConflictException, ValidationException};
use App\Models\{UserModel, BookingModel, TripModel};
$db=Database::instance();
$checks=0;
function check(bool $condition,string $message): void { global $checks; if (!$condition) throw new RuntimeException($message); $checks++; }
$db->beginTransaction();
try {
 $users=new UserModel(); $bookings=new BookingModel();
 $user=$users->createUser(['email'=>'test-'.bin2hex(random_bytes(4)).'@example.test','password_hash'=>Auth::hashPassword('Testing@2026'),'first_name'=>'Test','last_name'=>'Passenger'],['passenger'],['city'=>'Kampala'],'passengers');
 check($db->exists('SELECT 1 FROM passengers WHERE user_id = ?',[$user]),'Registration must create a passenger profile.');
 $trip=$db->first("SELECT * FROM trips WHERE status='scheduled' AND departure_time > DATE_ADD(NOW(),INTERVAL 1 HOUR) ORDER BY departure_time LIMIT 1");
 check($trip !== null,'Need a future seeded trip.');
 $seats=$bookings->bookableSeats((int)$trip['id'],$user); $available=array_values(array_filter($seats,fn($s)=>$s['is_available']));
 check(count($available)>0,'A future trip should have seats.');
 $data=['trip_id'=>(int)$trip['id'],'passenger_id'=>$user,'seat_number'=>$available[0]['seat_number'],'payment_method'=>'card','is_simulated'=>1];
 $result=$bookings->createBooking($data,$user);
 check($result['booking']['payment_status']==='paid','Successful demo payment must mark booking paid.');
 check((int)$db->value('SELECT total_bookings FROM passengers WHERE user_id = ?',[$user])===1,'Booking count must increment once, not become user id.');
 $duplicate=false; try {$bookings->createBooking($data,$user);}catch(ConflictException $e){$duplicate=true;}
 check($duplicate,'Duplicate passenger booking must fail.');
 $bookings->cancel((int)$result['booking']['id'],$user);
 check($db->value('SELECT payment_status FROM bookings WHERE id = ?',[$result['booking']['id']])==='refunded','Cancelling a paid booking must refund it.');
 $result=$bookings->createBooking($data,$user);
 check($result['booking']['status']==='confirmed','Cancelled seats must be bookable again.');
 $invalid=false;try{$bookings->createBooking(array_merge($data,['seat_number'=>'999','passenger_id'=>$user+1]),$user);}catch(ValidationException $e){$invalid=true;}
 check($invalid,'Seat outside capacity must fail.');
 echo "Booking regression checks passed: $checks\n";
} finally {$db->rollback();}
