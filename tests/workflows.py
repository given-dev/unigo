"""Parcel, complaint, SOS and ownership regressions on a disposable database."""
import http.cookiejar
import json
import os
import re
import subprocess
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

BASE = sys.argv[1] if len(sys.argv) > 1 else 'http://127.0.0.1:8000'
checks = 0


def check(condition, message):
    global checks
    if not condition:
        raise AssertionError(message)
    checks += 1


def client():
    return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))


def request(op, path, data=None):
    req = urllib.request.Request(BASE + path,
        data=urllib.parse.urlencode(data, doseq=True).encode() if data is not None else None)
    try:
        with op.open(req, timeout=15) as response:
            return response.status, response.read().decode()
    except urllib.error.HTTPError as error:
        return error.code, error.read().decode()


def token(page):
    return re.search(r'name="_token" value="([^"]+)"', page).group(1)


def sql(query, params=()):
    code = "require 'src/bootstrap.php';echo json_encode(App\\Core\\Database::instance()->run($argv[1],json_decode($argv[2],true))->fetchAll(PDO::FETCH_ASSOC));"
    result = subprocess.run([os.environ.get('UNIGO_TEST_PHP', 'php'), '-r', code,
        query, json.dumps(params)], check=True, capture_output=True, text=True)
    return json.loads(result.stdout)


def login(email):
    op = client()
    _, page = request(op, '/login')
    request(op, '/login', {'_token': token(page), 'email': email, 'password': 'UniGo@2026'})
    _, session = request(op, '/api/session')
    check(json.loads(session)['data']['user_id'] is not None, 'Login failed: ' + email)
    return op


def post_form(op, path, data, form_path=None):
    _, page = request(op, form_path or path)
    return request(op, path, dict(data, _token=token(page)))


email = 'workflow-' + str(time.time_ns()) + '@unigo.test'
passenger = client()
_, page = request(passenger, '/register')
request(passenger, '/register', {'_token': token(page), 'first_name': 'Workflow',
    'last_name': 'Tester', 'email': email, 'phone': '+256700123456',
    'password': 'Testing@2026', 'password_confirmation': 'Testing@2026'})
user_id = sql('SELECT id FROM users WHERE email=?', [email])[0]['id']

try:
    admin = login('admin@unigo.test')
    driver = login('driver@unigo.test')
    other = login('passenger@unigo.test')
    for path in ['/bookings/1', '/payments/1']:
        status, _ = request(passenger, path)
        check(status == 403, 'A passenger can view someone else record: ' + path)
    for path in ['/admin/users', '/operator/vehicles', '/authority/operators', '/driver/trips']:
        status, _ = request(passenger, path)
        check(status == 403, 'Passenger role bypass: ' + path)

    data = {'recipient_name': 'Test Recipient', 'recipient_phone': '+256700123456',
        'pickup_address': 'Kampala', 'dropoff_address': 'Entebbe', 'parcel_description': 'Books',
        'weight_kg': '1', 'declared_value': '0'}
    for key, invalid in [('weight_kg', '-1'), ('weight_kg', 'invalid'),
                         ('declared_value', '-1'), ('recipient_phone', 'invalid'),
                         ('recipient_name', 'x' * 121), ('weight_kg[]', ['1', '2'])]:
        status, page = post_form(passenger, '/deliveries', dict(data, **{key: invalid}), '/deliveries/new')
        check(status == 200 and 'Request pickup' in page, 'Invalid parcel input was not returned to the form')
        check(not sql('SELECT id FROM deliveries WHERE customer_id=?', [user_id]), 'Invalid parcel input was persisted')
    status, page = post_form(passenger, '/deliveries', data, '/deliveries/new')
    check(status == 200 and 'Parcel request created' in page, 'Valid parcel could not be created')
    delivery = sql('SELECT id,tracking_number FROM deliveries WHERE customer_id=?', [user_id])[0]
    status, _ = request(other, '/deliveries/' + delivery['tracking_number'])
    check(status == 403, 'Another passenger can read parcel details')
    _, page = post_form(admin, '/admin/deliveries', {'id': delivery['id'], 'status': 'assigned'})
    check('Assign a vehicle' in page, 'Status updates can bypass vehicle assignment')
    vehicle_id = sql("SELECT v.id FROM vehicles v JOIN drivers d ON d.id=v.driver_id JOIN users u ON u.id=d.user_id WHERE u.email='driver@unigo.test' AND v.status IN ('active','on_trip') LIMIT 1")[0]['id']
    _, page = post_form(admin, '/admin/deliveries', {'id': delivery['id'], 'action': 'assign', 'vehicle_id': vehicle_id})
    check('Changes saved.' in page, 'Admin parcel assignment failed')
    for state in ['picked_up', 'in_transit', 'delivered']:
        _, page = post_form(driver, '/driver/deliveries', {'id': delivery['id'], 'status': state, 'note': 'Workflow regression'})
        check('Changes saved.' in page, 'Driver parcel transition failed: ' + state)
    events = sql('SELECT status FROM delivery_tracking WHERE delivery_id=? ORDER BY id', [delivery['id']])
    check([row['status'] for row in events] == ['created', 'assigned', 'picked_up', 'in_transit', 'delivered'],
        'Parcel lifecycle history is incomplete or duplicated')
    post_form(admin, '/admin/deliveries', {'id': delivery['id'], 'status': 'cancelled'})
    check(sql('SELECT status FROM deliveries WHERE id=?', [delivery['id']])[0]['status'] == 'delivered',
        'A delivered parcel can be cancelled')

    post_form(passenger, '/complaints', {'category': 'other', 'description': '   '})
    check(not sql('SELECT id FROM complaints WHERE user_id=?', [user_id]), 'Empty complaint was saved')
    _, page = post_form(passenger, '/complaints', {'category': 'other', 'description': 'Test problem'})
    check('submitted' in page and len(sql('SELECT id FROM complaints WHERE user_id=?', [user_id])) == 1,
        'Valid complaint failed')
    for latitude in ['91', 'invalid']:
        post_form(passenger, '/emergency', {'latitude': latitude, 'longitude': '32', 'description': 'Test alert'}, '/emergency/new')
        check(not sql('SELECT id FROM emergency_alerts WHERE user_id=?', [user_id]), 'Invalid emergency coordinates were saved')
    post_form(passenger, '/emergency', {'latitude': '0', 'longitude': '32', 'description': 'Equator test alert'}, '/emergency/new')
    alert = sql('SELECT latitude,longitude,trip_id FROM emergency_alerts WHERE user_id=?', [user_id])[0]
    check(alert['latitude'] is not None and float(alert['latitude']) == 0, 'Zero emergency coordinates were discarded')
    check(alert['trip_id'] is None, 'An off-trip alert acquired an unrelated trip')
finally:
    sql('DELETE FROM users WHERE id=?', [user_id])

print('Workflow regression checks passed:', checks)
