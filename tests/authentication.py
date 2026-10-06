"""Session regressions against a disposable database and local PHP server."""
import copy
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
PHP = os.environ.get('UNIGO_TEST_PHP', 'php')
checks = 0


def ok(value, message):
    global checks
    if not value:
        raise AssertionError(message)
    checks += 1


def client():
    jar = http.cookiejar.CookieJar()
    return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar)), jar


def request(op, path, data=None, headers=None):
    req = urllib.request.Request(BASE + path, headers=headers or {},
        data=urllib.parse.urlencode(data).encode() if data is not None else None)
    try:
        with op.open(req) as response:
            return response.status, response.read().decode(), response.headers, response.url
    except urllib.error.HTTPError as error:
        return error.code, error.read().decode(), error.headers, error.url


def token(html):
    match = re.search(r'name="(?:_token|csrf-token)" (?:value|content)="([^"]+)"', html)
    if not match:
        raise AssertionError('Missing CSRF token')
    return match.group(1)


def sql(query, params=()):
    code = "require 'src/bootstrap.php'; $p=json_decode($argv[2],true); echo json_encode(App\\Core\\Database::instance()->run($argv[1],$p)->fetchAll(PDO::FETCH_ASSOC));"
    result = subprocess.run([PHP, '-r', code, query, json.dumps(params)], check=True, capture_output=True, text=True)
    return json.loads(result.stdout)


def sign_in(op, email, password='UniGo@2026', remember=False):
    _, html, _, _ = request(op, '/login')
    return request(op, '/login', {'_token': token(html), 'email': email,
        'password': password, 'remember': '1' if remember else '0'})


email = 'session-' + str(time.time_ns()) + '@unigo.test'
op, jar = client()
_, html, _, _ = request(op, '/register')
status, html, _, _ = request(op, '/register', {'_token': token(html),
    'first_name': 'Session', 'last_name': 'Tester', 'email': email,
    'phone': '+256700123456', 'city': 'Kampala',
    'password': 'UniGo@2026', 'password_confirmation': 'UniGo@2026'})
ok(status == 200 and 'Your account is ready' in html, 'Registration establishes an authenticated session')
user_id = sql('SELECT id FROM users WHERE email=?', [email])[0]['id']

try:
    _, html, headers, _ = request(op, '/profile')
    ok('no-store' in headers.get('Cache-Control', ''), 'Private responses must not be cached')
    old_token = token(html)
    status, _, _, _ = request(op, '/logout', {}, {'Accept': 'application/json'})
    ok(status == 419, 'Logout rejects missing CSRF')
    _, html, _, _ = request(op, '/logout')
    ok('Sign out?' in html, 'GET logout displays confirmation')
    status, _, _, _ = request(op, '/api/notifications/unread-count')
    ok(status == 200, 'GET logout must leave authentication intact')
    old_cookies = '; '.join(c.name + '=' + c.value for c in jar)
    status, html, _, url = request(op, '/logout', {'_token': token(html)})
    ok(status == 200 and url.rstrip('/') == BASE and 'You have been signed out' in html,
        'Logout returns to the homepage and persists confirmation')
    _, html, _, _ = request(op, '/')
    ok('You have been signed out' not in html, 'Logout confirmation appears only once')
    status, _, _, url = request(op, '/profile')
    ok(status == 200 and url.endswith('/login'), 'Protected pages redirect after logout')
    status, _, _, _ = request(op, '/api/notifications/unread-count')
    ok(status == 401, 'API rejects signed-out clients')
    status, _, _, _ = request(urllib.request.build_opener(), '/api/notifications/unread-count',
        headers={'Cookie': old_cookies})
    ok(status == 401, 'Destroyed session IDs cannot be replayed')
    status, html, _, _ = sign_in(op, email, remember=True)
    ok(status == 200 and 'Sign in to your account' not in html, 'Sign-in works again after logout')
    _, html, _, _ = request(op, '/profile')
    ok(token(html) != old_token, 'Authentication rotates the CSRF token')
    remembered = next(copy.copy(c) for c in jar if c.name == 'unigo_remember')
    resume, resume_jar = client()
    resume_jar.set_cookie(copy.copy(remembered))
    status, raw, _, _ = request(resume, '/api/notifications/unread-count')
    ok(status == 200, 'Remember-me resumes authentication without a session cookie')
    ok(next(c.value for c in resume_jar if c.name == 'unigo_remember') != remembered.value,
        'Remember-me rotates after use')
    replay, replay_jar = client()
    replay_jar.set_cookie(copy.copy(remembered))
    status, _, _, _ = request(replay, '/api/notifications/unread-count')
    ok(status == 401, 'Used remember-me tokens cannot be replayed')
    _, html, _, _ = request(resume, '/profile')
    request(resume, '/logout', {'_token': token(html)})
    ok(not any(c.name == 'unigo_remember' for c in resume_jar), 'Logout removes remember-me cookies')
    ok(not sql('SELECT id FROM remember_tokens WHERE user_id=?', [user_id]), 'Logout revokes stored remember tokens')

    # Account edits and role removals take effect in sessions already open.
    sql("UPDATE users SET status='suspended' WHERE id=?", [user_id])
    status, _, _, _ = request(op, '/api/notifications/unread-count')
    ok(status == 401, 'Suspended accounts lose existing API sessions')
    sql("UPDATE users SET status='active' WHERE id=?", [user_id])
    sign_in(op, email)
    role_id = sql("SELECT id FROM roles WHERE slug='admin'")[0]['id']
    sql('INSERT INTO user_roles (user_id,role_id) VALUES (?,?)', [user_id, role_id])
    status, _, _, _ = request(op, '/admin/users')
    ok(status == 200, 'New roles take effect in the current session')
    sql('DELETE FROM user_roles WHERE user_id=? AND role_id=?', [user_id, role_id])
    status, _, _, _ = request(op, '/admin/users')
    ok(status == 403, 'Removed roles no longer authorize an existing session')

    other, other_jar = client()
    sign_in(other, email, remember=True)
    _, html, _, _ = request(op, '/profile')
    status, html, _, _ = request(op, '/profile/password', {'_token': token(html),
        'current_password': 'UniGo@2026', 'new_password': 'x', 'new_password_confirmation': 'x'})
    ok('Password must be at least' in html, 'Password changes reject weak passwords')
    _, html, _, _ = request(op, '/profile')
    status, html, _, _ = request(op, '/profile/password', {'_token': token(html),
        'current_password': 'UniGo@2026', 'new_password': 'BetterPass2026!',
        'new_password_confirmation': 'BetterPass2026!'})
    ok(status == 200 and 'Password changed.' in html, 'Password changes preserve the current session')
    status, _, _, _ = request(other, '/api/notifications/unread-count')
    ok(status == 401, 'Password changes revoke other active sessions including remember-me')
    ok(not sql('SELECT id FROM remember_tokens WHERE user_id=?', [user_id]), 'Password changes revoke all remember tokens')

    _, html, _, _ = request(op, '/profile')
    fields = {'_token': token(html), 'first_name': 'Session', 'last_name': 'Tester',
        'phone': '+256700123456', 'gender': 'undisclosed', 'date_of_birth': '2000-01-01'}
    request(op, '/profile', fields)
    _, html, _, _ = request(op, '/profile')
    fields.update(_token=token(html), date_of_birth='')
    request(op, '/profile', fields)
    ok(sql('SELECT date_of_birth FROM users WHERE id=?', [user_id])[0]['date_of_birth'] is None,
        'Optional profile dates can be cleared')
    _, html, _, _ = request(op, '/profile')
    fields.update(_token=token(html), date_of_birth='2000-02-30')
    _, html, _, _ = request(op, '/profile', fields)
    ok('Enter a valid date of birth' in html, 'Invalid calendar dates are rejected')

    # Expiry must not immediately sign the user back in through remember-me.
    sign_in(resume, email, 'BetterPass2026!', remember=True)
    session_id = next(c.value for c in resume_jar if c.name == 'UNIGOSESSID')
    code = "session_name('UNIGOSESSID');session_id($argv[1]);session_start();$_SESSION['_auth_last_seen']=time()-7201;session_write_close();"
    subprocess.run([PHP, '-r', code, session_id], check=True, capture_output=True)
    status, _, _, _ = request(resume, '/api/notifications/unread-count')
    ok(status == 401, 'Idle expiry signs out even when remember-me was enabled')
    sign_in(resume, email, 'BetterPass2026!', remember=True)
    status, _, _, _ = request(resume, '/api/notifications/unread-count', headers={'User-Agent': 'different-client'})
    ok(status == 401, 'Fingerprint changes terminate authentication without resuming remember-me')
    sign_in(resume, email, 'BetterPass2026!')
    sql("UPDATE users SET status='inactive' WHERE id=?", [user_id])
    status, _, _, _ = request(resume, '/api/notifications/unread-count')
    ok(status == 401, 'Inactive accounts lose existing sessions')
    sql("UPDATE users SET status='active' WHERE id=?", [user_id])
    sign_in(resume, email, 'BetterPass2026!')
    session_id = next(c.value for c in resume_jar if c.name == 'UNIGOSESSID')
    code = "session_name('UNIGOSESSID');session_id($argv[1]);session_start();$_SESSION['_auth_started_at']=time()-86401;session_write_close();"
    subprocess.run([PHP, '-r', code, session_id], check=True, capture_output=True)
    status, _, _, _ = request(resume, '/api/notifications/unread-count')
    ok(status == 401, 'Absolute expiry signs out active sessions')

    visitor, _ = client()
    for i in range(6):
        sign_in(visitor, f'unknown-{time.time_ns()}-{i}@unigo.test', 'WrongPassword')
    status, html, _, _ = sign_in(visitor, email, 'BetterPass2026!')
    ok(status == 200 and 'Sign in to your account' not in html,
        'IP limit allows a valid account after six failures across different accounts')
    request(visitor, '/logout', {'_token': token(html)})
    for _ in range(5):
        sign_in(visitor, email, 'WrongPassword')
    _, html, _, _ = sign_in(visitor, email, 'BetterPass2026!')
    ok('Too many sign-in attempts' in html, 'Per-account limit blocks repeated failed credentials')
    sql("UPDATE login_attempts SET attempted_at=DATE_SUB(NOW(),INTERVAL 901 SECOND) WHERE identifier_hash=SHA2(?,256)", ['email:' + email])
    _, html, _, _ = sign_in(visitor, email, 'BetterPass2026!')
    ok('Sign in to your account' not in html, 'Account limits expire on the application clock')
finally:
    sql('DELETE FROM users WHERE id=?', [user_id])

print('Authentication regression checks passed:', checks)
