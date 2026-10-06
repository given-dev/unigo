"""Disposable installation test; requires no pre-existing .env file."""
import os, pathlib, subprocess, time, urllib.request, urllib.parse, http.cookiejar, re
php=os.environ.get('UNIGO_TEST_PHP','php')
envfile=pathlib.Path('.env')
assert not envfile.exists(), 'Installation test requires a workspace without .env'
name='unigo_install_test_'+str(time.time_ns())
env=dict(os.environ,UNIGO_ADMIN_PASSWORD='InstallTest2026!')
args=[php,'scripts/install-live.php','--database='+name,'--email=admin@example.test','--name=Owner']
server=None
try:
 r=subprocess.run(args,env=env,capture_output=True,text=True)
 assert r.returncode==0, r.stderr+r.stdout
 assert envfile.exists() and 'UNIGO_DEMO_MODE="0"' in envfile.read_text()
 code="require 'src/bootstrap.php'; $d=App\\Core\\Database::instance();echo json_encode([$d->count('SELECT COUNT(*) FROM users'),$d->count('SELECT COUNT(*) FROM trips'),App\\Core\\Config::get('domain.demo_mode')]);"
 liveenv=dict(env,UNIGO_DB_NAME=name,UNIGO_ENV='production',UNIGO_DEMO_MODE='0')
 r=subprocess.run([php,'-r',code],env=liveenv,capture_output=True,text=True,check=True)
 assert r.stdout=='[1,0,false]', r.stdout
 server=subprocess.Popen([php,'-S','127.0.0.1:8101','-t','public','scripts/router.php'],env=liveenv,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
 base='http://127.0.0.1:8101'
 client=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
 for _ in range(50):
  try:
   with client.open(base+'/') as response: home=response.read().decode()
   break
  except OSError:time.sleep(.1)
 assert 'Simulated data environment' not in home and 'Kampala City Bus Services' not in home
 with client.open(base+'/register') as response: page=response.read().decode()
 token=re.search(r'name="_token" value="([^"]+)"',page).group(1)
 data={'_token':token,'first_name':'Real','last_name':'Passenger','email':'passenger@example.test',
       'phone':'+256700123456','city':'Kampala','password':'LiveUser2026!','password_confirmation':'LiveUser2026!'}
 with client.open(base+'/register',urllib.parse.urlencode(data).encode()) as response:page=response.read().decode()
 assert 'Your account is ready' in page and 'Simulated data environment' not in page
 with client.open(base+'/profile') as response:page=response.read().decode()
 token=re.search(r'name="_token" value="([^"]+)"',page).group(1)
 with client.open(base+'/logout',urllib.parse.urlencode({'_token':token}).encode()) as response:page=response.read().decode()
 assert 'You have been signed out' in page
 with client.open(base+'/login') as response:page=response.read().decode()
 token=re.search(r'name="_token" value="([^"]+)"',page).group(1)
 with client.open(base+'/login',urllib.parse.urlencode({'_token':token,'email':'admin@example.test','password':'InstallTest2026!'}).encode()) as response:page=response.read().decode()
 assert 'Sign in to your account' not in page and 'Simulated data environment' not in page
 r=subprocess.run(args,env=env,capture_output=True,text=True)
 assert r.returncode!=0 and 'already exists' in r.stderr
 envfile.unlink()
 r=subprocess.run(args,env=env,capture_output=True,text=True)
 assert r.returncode!=0 and 'database already exists' in r.stderr
 print('Clean installation checks passed: empty database, private admin, live homepage, registration, logout, admin login and overwrite protection.')
finally:
 if server:server.terminate();server.wait(timeout=10)
 if envfile.exists():envfile.unlink()
 code="require 'src/bootstrap.php';App\\Core\\Database::instance()->pdo()->exec('DROP DATABASE IF EXISTS `'. $argv[1] .'`');"
 subprocess.run([php,'-r',code,name],env=env,check=True,capture_output=True)
