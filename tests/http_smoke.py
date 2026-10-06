"""Run against an isolated seeded application: python tests/http_smoke.py [URL]."""
import sys,re,urllib.request,urllib.parse,urllib.error,http.cookiejar,json
base=sys.argv[1] if len(sys.argv)>1 else 'http://127.0.0.1:8000'
checks=0
def client():return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
def request(op,path,data=None):
 req=urllib.request.Request(base+path,data=urllib.parse.urlencode(data).encode() if data else None)
 try:
  with op.open(req) as r:return r.status,r.read().decode()
 except urllib.error.HTTPError as e:return e.code,e.read().decode()
def ok(condition,message):
 global checks
 if not condition:raise AssertionError(message)
 checks+=1
def token(html):
 match=re.search(r'name="_token" value="([^"]+)"',html)
 if not match:match=re.search(r'name="csrf-token" content="([^"]+)"',html)
 if not match:raise AssertionError('Missing CSRF token')
 return match.group(1)
public=client()
for path in ['/','/about','/contact','/schedule','/trips/search','/login','/register']:
 status,html=request(public,path);ok(status==200 and 'Fatal error' not in html,f'{path}: {status}')
status,home=request(public,'/');ok('Travel companies, together.' in home and 'Kampala City Bus Services' in home,'Landing page must display seeded companies')
status,html=request(public,'/api/notifications/unread-count');ok(status==401,f'API must require authentication: {status} {html[:200]}')
pages=re.findall(r"'(/(?:admin|operator|authority|driver)/[^']+)'\s*=>",open('src/controllers/StubController.php').read())
for role in ['passenger','admin','operator','authority','driver']:
 op=client();_,html=request(op,'/login')
 status,html=request(op,'/login',{'_token':token(html),'email':role+'@unigo.test','password':'UniGo@2026'})
 ok(status==200 and 'Sign in to your account' not in html,f'{role} login failed')
 targets=['/profile','/notifications','/dashboard']+[p for p in pages if p.startswith('/'+role+'/')]
 if role=='passenger':targets+=['/ratings','/trips/search','/bookings','/payments','/deliveries','/tracking','/complaints','/emergency/new']
 for path in targets:
  status,html=request(op,path);ok(status==200 and 'We could not complete your request' not in html and 'Fatal error' not in html,f'{role}: {path}: {status}')
 if role=='passenger':
  status,_=request(op,'/admin/users');ok(status==403,'Passenger must not access admin users')
  status,raw=request(op,'/api/notifications/unread-count');ok(status==200 and 'unread' in json.loads(raw)['data'],'Unread API response')
  status,_=request(op,'/api/tracking/999999');ok(status==404,'Tracking must reject a trip without an owned booking')
print('HTTP smoke checks passed:',checks)
