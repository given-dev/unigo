"""Authority workflows; requires a disposable database seeded by both seeders."""
import http.cookiejar,json,os,pathlib,re,subprocess,sys,urllib.request,urllib.parse,urllib.error
base=sys.argv[1] if len(sys.argv)>1 else 'http://127.0.0.1:8000'
checks=0
def sql(query,params=[]):
 code="require 'src/bootstrap.php';echo json_encode(App\\Core\\Database::instance()->select($argv[1],json_decode($argv[2],true)));"
 return json.loads(subprocess.run([os.environ.get('UNIGO_TEST_PHP','php'),'-r',code,query,json.dumps(params)],cwd=pathlib.Path(__file__).resolve().parents[1],check=True,capture_output=True,text=True).stdout)
def req(op,path,data=None):
 try:
  with op.open(urllib.request.Request(base+path,data=urllib.parse.urlencode(data).encode() if data is not None else None)) as r:return r.status,r.read().decode()
 except urllib.error.HTTPError as e:return e.code,e.read().decode()
def check(value,message):
 global checks
 assert value,message
 checks+=1
def token(page):return re.search(r'name="_token" value="([^"]+)"',page).group(1)
for district,email in [(None,'mowt.authority@unigo.test'),('Kampala','kampala.authority@unigo.test'),('Jinja','jinja.authority@unigo.test'),('Mukono','mukono.authority@unigo.test')]:
 op=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
 _,page=req(op,'/login');status,page=req(op,'/login',{'_token':token(page),'email':email,'password':'UniGo@2026'})
 check(status==200 and 'Authority dashboard' in page if district is None else status==200 and 'District dashboard' in page,'Officer login '+email)
 for path in ['dashboard','traffic','analytics','operators','emergencies','complaints','reports']:
  status,page=req(op,'/authority/'+path);check(status==200,'Officer page '+email+' '+path)
 _,page=req(op,'/authority/traffic')
 status,page=req(op,'/authority/traffic',{'_token':token(page),'action':'create','route_id':99999999,'severity':'medium','category':'congestion'})
 check(status==200 and 'Choose an existing route.' in page,'Unknown route validation '+email)
 if district:
  outside=sql('SELECT id FROM routes WHERE origin_name<>? AND destination_name<>? LIMIT 1',[district,district])[0]['id']
  _,page=req(op,'/authority/traffic');status,page=req(op,'/authority/traffic',{'_token':token(page),'action':'create','route_id':outside,'severity':'medium','category':'congestion'})
  check('outside your district' in page,'District route authorization '+email)
 inside=sql('SELECT id FROM routes WHERE origin_name=? OR destination_name=? LIMIT 1',[district or 'Kampala',district or 'Kampala'])[0]['id']
 _,page=req(op,'/authority/traffic');status,page=req(op,'/authority/traffic',{'_token':token(page),'action':'create','route_id':inside,'severity':'low','category':'congestion','description':'Authority workflow verification','delay_minutes':5})
 check(status==200 and 'Traffic report logged.' in page,'Traffic creation '+email)
print('Authority checks passed:',checks)
