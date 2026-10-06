"""Real cash journey. Run ONLY against a disposable empty database."""
import re,time,datetime,sys,urllib.request,urllib.error,urllib.parse,http.cookiejar,json,html,subprocess,os
BASE=sys.argv[1] if len(sys.argv)>1 else 'http://127.0.0.1:8000'
KEY=str(int(time.time()))
checks=0
def client():return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
def req(op,path,data=None,headers=None):
 r=op.open(urllib.request.Request(BASE+path,data=urllib.parse.urlencode(data).encode() if data else None,headers=headers or {}))
 return r.status,r.read().decode()
def token(page):return re.search(r'name="_token" value="([^"]+)"',page).group(1)
def check(condition,message):
 global checks
 if not condition:raise AssertionError(message)
 checks+=1
def login(email,password='UniGo@2026'):
 op=client();_,page=req(op,'/login');_,page=req(op,'/login',{'_token':token(page),'email':email,'password':password});_,session=req(op,'/api/session');check(json.loads(session)['data']['user_id'] is not None,'Login '+email);return op
def save(op,path,data):
 _,page=req(op,path);_,page=req(op,path,dict(data,_token=token(page)));check('Changes saved.' in page,'Saving '+path+' failed: '+re.sub('<[^>]+>',' ',page)[-1200:]);return page
def rowid(page,marker):
 for row in re.findall(r'<tr>(.*?)</tr>',page,re.S):
  if marker in row:return int(re.search(r'<td>(\d+)</td>',row).group(1))
 raise AssertionError('No row for '+marker)
def sql(query,params=()):
 code="require 'src/bootstrap.php';echo json_encode(App\\Core\\Database::instance()->select($argv[1],json_decode($argv[2],true)));"
 result=subprocess.run([os.environ.get('UNIGO_TEST_PHP','php'),'-r',code,query,json.dumps(params)],check=True,capture_output=True,text=True)
 return json.loads(result.stdout)
# Register and bootstrap the first administrator without a seeded account.
admin_email='admin'+KEY+'@example.test'
bootstrap=client();_,page=req(bootstrap,'/register')
_,page=req(bootstrap,'/register',{'_token':token(page),'first_name':'Test','last_name':'Admin','email':admin_email,'password':'Journey@2026','password_confirmation':'Journey@2026','phone':'+256780123450','city':'Kampala'})
check('Your account is ready' in page,'Administrator registration')
subprocess.run([os.environ.get('UNIGO_TEST_PHP','php'),'scripts/admin.php','--email='+admin_email],check=True)
subprocess.run([os.environ.get('UNIGO_TEST_PHP','php'),'scripts/admin.php','--email='+admin_email],check=True)
admin=login(admin_email,'Journey@2026')
company='Test Operator '+KEY
page=save(admin,'/admin/operators',{'action':'create','first_name':'Test','last_name':'Operator','email':'op'+KEY+'@example.test','phone':'+256780123456','password':'Journey@2026','company_name':company,'license_number':'OP'+KEY})
save(admin,'/admin/operators',{'id':rowid(page,company),'status':'approved'})
op=login('op'+KEY+'@example.test','Journey@2026')
route='TEST'+KEY
page=save(op,'/operator/routes',{'action':'create','route_code':route,'name':'Test Kampala Entebbe','origin_name':'Kampala','destination_name':'Entebbe','base_fare':15000,'duration_minutes':60,'origin_latitude':0.3476,'origin_longitude':32.5825,'destination_latitude':0.0512,'destination_longitude':32.4637})
rid=rowid(page,route)
plate='T'+KEY
page=save(op,'/operator/vehicles',{'action':'create','registration_number':plate,'vehicle_type':'bus','capacity':4})
vid=rowid(page,plate)
lic='DL'+KEY
page=save(op,'/operator/drivers',{'action':'create','first_name':'Test','last_name':'Driver','email':'driver'+KEY+'@example.test','phone':'+256780123457','password':'Journey@2026','license_number':lic})
did=rowid(page,lic)
future=datetime.datetime.now()+datetime.timedelta(days=1)
page=save(op,'/operator/trips',{'action':'create','vehicle_id':vid,'route_id':rid,'driver_id':did,'departure_time':future.strftime('%Y-%m-%dT%H:%M'),'arrival_time':(future+datetime.timedelta(hours=1)).strftime('%Y-%m-%dT%H:%M'),'fare':15000})
tid=rowid(page,str(vid))
passenger=client();_,page=req(passenger,'/register')
_,page=req(passenger,'/register',{'_token':token(page),'first_name':'Test','last_name':'Passenger','email':'pass'+KEY+'@example.test','phone':'+256780123458','city':'Kampala','password':'Journey@2026','password_confirmation':'Journey@2026'})
check('Your account is ready' in page,'Passenger registration')
guest=client()
_,page=req(guest,'/trips/search?operator_id='+str(rowid(req(admin,'/admin/operators')[1],company)))
check('Test Kampala Entebbe' not in page or company in page,'Company search retains operator')
check('/trips/'+str(tid) in page,'Guest can discover a company trip')
_,reverse=req(guest,'/trips/search?from=Entebbe&to=Kampala&operator_id='+str(rowid(req(admin,'/admin/operators')[1],company)))
check('/trips/'+str(tid)+'\"' not in reverse,'Search must respect route direction')
_,page=req(guest,'/trips/'+str(tid))
check('Sign in' in page,'Guest booking requires login')
_,page=req(guest,'/login',{'_token':token(page),'email':'pass'+KEY+'@example.test','password':'Journey@2026'})
check('Trip details' in page and '/trips/'+str(tid)+'/book' in page,'Login resumes selected trip')
_,page=req(passenger,'/trips/'+str(tid))
_,page=req(passenger,'/trips/'+str(tid)+'/book',{'_token':token(page),'seat_number':'1','payment_method':'cash'})
check('Booking confirmed' in page,'Passenger checkout')
bid=int(re.search(r'/bookings/(\d+)/cancel',page).group(1))
check(sql('SELECT payment_status,is_simulated FROM bookings WHERE id=?',[bid])[0]=={'payment_status':'unpaid','is_simulated':0},'Cash booking starts unpaid and real')
driver=login('driver'+KEY+'@example.test','Journey@2026')
save(driver,'/driver/bookings',{'id':bid,'action':'collect_cash'})
receipt=sql('SELECT method,status,is_mock,amount FROM payments WHERE booking_id=?',[bid])[0]
check(receipt['method']=='cash' and receipt['status']=='successful' and receipt['is_mock']==0 and float(receipt['amount'])==15000,'Staff recorded real cash receipt')
_,page=req(passenger,'/payments');check('Cash' in page and '15000' in page.replace(',',''),'Passenger can see cash receipt')
save(driver,'/driver/trips',{'id':tid,'status':'boarding'})
save(driver,'/driver/bookings',{'id':bid,'action':'board'})
_,page=req(driver,'/driver/trips');csrf=token(page)
_,data=req(driver,'/api/driver/location',{'latitude':0.35,'longitude':32.58,'accuracy':10},{'X-CSRF-Token':csrf})
check(json.loads(data)['success'],'Driver GPS sharing')
_,data=req(passenger,'/api/tracking/'+str(tid));check(len(json.loads(data)['data']['vehicles'])==1,'Passenger GPS feed')
save(driver,'/driver/trips',{'id':tid,'status':'in_transit'})
save(driver,'/driver/trips',{'id':tid,'status':'completed'})
_,page=req(driver,'/trips/'+str(tid))
check('/trips/'+str(tid)+'/book' not in page,'Completed trips must not display a booking form')
_,page=req(passenger,'/ratings');_,page=req(passenger,'/ratings',{'_token':token(page),'trip_id':tid,'rating':5,'comment':'Journey regression'})
check('Thank you for rating' in page,'Passenger review after completion')
_,page=req(admin,'/admin/ratings?search=Journey+regression')
rating_id=int(sql('SELECT id FROM ratings WHERE trip_id=?',[tid])[0]['id'])
save(admin,'/admin/ratings',{'id':rating_id,'status':'hidden'})
check(sql('SELECT rating_count FROM drivers WHERE id=?',[did])[0]['rating_count']==0,'Hiding a review removes it from the driver aggregate')
save(admin,'/admin/ratings',{'id':rating_id,'status':'published'})
check(sql('SELECT rating_count,rating_avg FROM drivers WHERE id=?',[did])[0]['rating_count']==1,'Publishing a review restores the driver aggregate')
print('Full journey checks passed:',checks)
