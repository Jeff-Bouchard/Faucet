#!/usr/bin/env python3
"""HTTP integration tests with disposable SQLite/key files and fake wallet/RPC services.
Usage: PHP_BIN=php python3 tests/integration.py /path/to/NVS-exchange
No live network or wallet credentials are used.
"""
import concurrent.futures, http.cookiejar, http.server, json, os, pathlib, re
import shutil, signal, socket, sqlite3, subprocess, sys, tempfile, threading, time
import urllib.error, urllib.parse, urllib.request
PHP = os.environ.get('PHP_BIN', 'php')
SOURCE = pathlib.Path(__file__).resolve().parents[1]
EXCHANGE = pathlib.Path(sys.argv[1]).resolve()
checks = 0

def check(condition, text):
    global checks
    if not condition: raise AssertionError(text)
    checks += 1
    print('PASS', text, flush=True)

def port():
    with socket.socket() as s:
        s.bind(('127.0.0.1', 0)); return s.getsockname()[1]

class Client:
    def __init__(self, base):
        self.base = base
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        self.csrf = self.get('/api.php')[1]['csrf']
    def request(self, path, data=None, csrf=True):
        headers = {}
        if data is not None:
            headers = {'Content-Type':'application/json'}
            if csrf: headers['X-CSRF-Token'] = self.csrf
        request = urllib.request.Request(self.base+path, data=json.dumps(data).encode() if data is not None else None, headers=headers)
        try: response = self.opener.open(request, timeout=30)
        except urllib.error.HTTPError as e: response = e
        content = response.read().decode()
        try: value = json.loads(content)
        except json.JSONDecodeError: value = content
        return response.status, value, response.headers
    def get(self, path): return self.request(path)
    def post(self, data, csrf=True): return self.request('/api.php', data, csrf)

state = {'broadcasts':0, 'fail':False, 'paid':False, 'names':{}, 'addresses':0}
class FakeNode(http.server.BaseHTTPRequestHandler):
    def log_message(self, *args): pass
    def reply(self, value, code=200):
        raw=json.dumps(value).encode(); self.send_response(code)
        self.send_header('Content-Type','application/json'); self.send_header('Content-Length',str(len(raw)))
        self.end_headers(); self.wfile.write(raw)
    def do_GET(self):
        path=urllib.parse.urlparse(self.path).path
        if path.endswith('/csrf'): return self.reply({'csrf_token':'test-csrf'})
        if path.endswith('/wallet/balance'): return self.reply({'addresses':{'A'*34:{'confirmed':{'coins':1000000,'hours':1000000}}}})
        if path.endswith('/balance'): return self.reply({'confirmed':{'coins':0,'hours':0}})
        if path == '/exchange': return self.reply({'status':'activated','address':'A'*34,'pay_address':'B'*34,'gen_address':'C'*34,'hours':100,'recieve':0.1})
        self.reply({})
    def do_POST(self):
        raw=self.rfile.read(int(self.headers.get('Content-Length','0')))
        if self.path.endswith('/wallet/newAddress'):
            state['addresses']+=1; return self.reply({'addresses':['A'*30+str(state['addresses']+1)]})
        data=json.loads(raw)
        if self.path.endswith('/wallet/transaction'):
            state['transaction']=data
            return self.reply({'encoded_transaction':'test-raw-transaction','transaction':{'txid':'a'*64}})
        if self.path.endswith('/injectTransaction'):
            state['broadcasts']+=1
            return self.reply('a'*64 if not state['fail'] else {'error':'unknown'}, 200 if not state['fail'] else 500)
        method=data['method']; params=data['params']; error=None
        if method=='name_show':
            if params[0]=='worm:exchange:ness_exchange_v1_v2':
                value={'name':params[0],'value':f'<worm><exchange title="Exchange" url="http://127.0.0.1:{self.server.server_port}/exchange"/></worm>'}
            elif params[0] in state['names']: value=state['names'][params[0]]
            else: value=None; error={'code':-4,'message':'name not found'}
        elif method=='name_list': value=[]
        elif method=='getnewaddress': value='E'*34
        elif method=='getreceivedbyaddress': value=1 if state['paid'] else 0
        elif method in ('name_new','name_update'):
            state['names'][params[0]]={'name':params[0],'value':params[1]}; value='b'*64
        else: raise AssertionError('Unexpected RPC method: '+method)
        self.reply({'id':data['id'],'result':value,'error':error})

processes=[]
with tempfile.TemporaryDirectory(prefix='privateness-integration-') as tmp:
    root=pathlib.Path(tmp)
    faucet=root/'faucet'; exchange=root/'exchange'
    for source,dest in [(SOURCE,faucet),(EXCHANGE,exchange)]:
        shutil.copytree(source,dest,ignore=shutil.ignore_patterns('.git','config.php','*.db','*.key.json','*.lock'))
        (dest/'data').mkdir(exist_ok=True)
    node=http.server.ThreadingHTTPServer(('127.0.0.1',0),FakeNode)
    threading.Thread(target=node.serve_forever,daemon=True).start()
    nodeport=node.server_port
    keycode="$seed=random_bytes(32);$pair=sodium_crypto_sign_seed_keypair($seed);echo json_encode(['keys'=>['private'=>base64_encode($seed),'verify'=>base64_encode(sodium_crypto_sign_publickey($pair))]]);"
    (faucet/'data/test.key.json').write_bytes(subprocess.check_output([PHP,'-r',keycode]))
    (faucet/'config/config.php').write_text("<?php return ['keyfile'=>__DIR__.'/../data/test.key.json','ness'=>['host'=>'127.0.0.1','port'=>%d,'wallet'=>'test','password'=>'test','prefix'=>'http://','price'=>[0.0001,1]]];"%nodeport)
    (exchange/'config/config.php').write_text("<?php return ['ness'=>['host'=>'127.0.0.1','port'=>%d,'wallets'=>['test'=>'test'],'main_wallet_id'=>'test','prefix'=>'http://','gen_address'=>true],'emercoin'=>['host'=>'127.0.0.1','port'=>%d,'user'=>'test','password'=>'test'],'db'=>['filename'=>'../data/exchange.db'],'exchange'=>['min_sum'=>['emc'=>0.01,'ness'=>0.1,'nch'=>1]]];"%(nodeport,nodeport))
    log=open(root/'php.log','w+')
    try:
        bases=[]
        for repo in [faucet,exchange]:
            p=port(); bases.append('http://127.0.0.1:'+str(p))
            processes.append(subprocess.Popen([PHP,'-S','127.0.0.1:'+str(p),'-t',str(repo/'web')],env={**os.environ,'PHP_CLI_SERVER_WORKERS':'4'},stdout=log,stderr=log,start_new_session=True))
        for base in bases:
            for attempt in range(100):
                try: urllib.request.urlopen(base+'/api.php',timeout=1).read(); break
                except OSError: time.sleep(.05)
            else: raise RuntimeError('PHP HTTP fixture failed to start')
        a,b=Client(bases[0]),Client(bases[0])
        code,html,headers=a.get('/')
        check(code==200 and not re.search(r'\d{10}_[A-Za-z0-9+/]{86}==',html),'initial HTML contains no time token')
        check('no-store' in headers['Cache-Control'] and "frame-src 'none'" in headers['Content-Security-Policy'],'portal cache and frame restrictions')
        check(a.post({'action':'start'},False)[0]==403,'CSRF-less claim rejected')
        start=time.monotonic(); code,claim,_=a.post({'action':'start'})
        check(code==200 and 'token' not in claim,'start response contains only claim metadata')
        check(a.post({'action':'release','claim_id':claim['claim_id']})[0]==425,'direct early release denied by HTTP service')
        check(b.post({'action':'release','claim_id':claim['claim_id']})[0]==400,'claim cannot be released in a foreign session')
        check(a.post({'action':'start'})[1]['claim_id']==claim['claim_id'],'repeated start recovers claim')
        time.sleep(max(0,10.1-(time.monotonic()-start)))
        code,released,_=a.post({'action':'release','claim_id':claim['claim_id']}); token=released.get('token')
        check(code==200 and token and time.monotonic()-start>=10,'token released after full ten-second wait')
        check(a.post({'action':'release','claim_id':claim['claim_id']})[1]['token']==token,'lost release response can be safely replayed')
        check(a.post({'action':'quote','token':token})[0]==200,'released token signature and value accepted')
        check(a.post({'action':'quote','token':'bad'})[0]==400,'invalid bearer token rejected')
        payload={'action':'withdraw','token':token,'address':'B'*34}
        with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
            results=list(pool.map(lambda client:client.post(payload),[a,b]))
        check(any(r[0]==200 for r in results) and state['broadcasts']==1,'concurrent withdrawals broadcast at most once')
        code,receipt,_=a.post(payload)
        check(code==200 and receipt['redemption']['txid']=='a'*64 and state['broadcasts']==1,'withdrawal retry returns stored receipt without payment')
        check(state['transaction']['to'][0]['address']=='B'*34,'wallet transaction uses the chosen recipient')
        signer=faucet/'sign.php'; signer.write_text("<?php require __DIR__.'/lib/Container.php'; echo lib\\Container::Cryptor()->show(time()-100);")
        token2=subprocess.check_output([PHP,str(signer)]).decode(); state['fail']=True
        bad={'action':'withdraw','token':token2,'address':'B'*34}
        check(a.post(bad)[0]==503,'failed submission enters review')
        check(a.post(bad)[0]==409 and state['broadcasts']==2,'uncertain submission is never automatically rebroadcast')
        x,y=Client(bases[1]),Client(bases[1])
        req={'action':'create','kind':'nvs','name':'test:portal','value':'<worm>test & value</worm>','days':'100','owner':''}
        code,slot,_=x.post(req)
        check(code==201 and set(slot.get('payments',{}))=={'EMC','NESS','NCH'},'NVS request displays all three payment options')
        id=slot['slot_id']
        check(x.post(req)[1]['slot_id']==id,'duplicate NVS create recovers existing slot')
        check(y.post({'action':'status','kind':'nvs','slot_id':id})[0]==403,'foreign-session slot status denied')
        check(x.post({**req,'name':'test:second'})[0]==429,'exchange creation cooldown retained')
        state['paid']=True
        code,slot,_=x.post({'action':'status','kind':'nvs','slot_id':id,'process':True})
        check(code==200 and slot['status']=='payed','NVS payment processes through existing Emercoin adapter')
        db=sqlite3.connect(exchange/'data/exchange.db'); db.execute('UPDATE slots SET created=created-31'); db.commit(); db.close()
        state['paid']=False
        req={'action':'create','kind':'exchange','address':'A'*34,'pay_address':'B'*34}
        code,slot,_=x.post(req)
        check(code==201 and slot['name'].startswith('worm:token:ness_exchange_v1_v2:'),'NCH exchange uses its existing WORM token namespace')
        state['paid']=True
        code,slot,_=x.post({'action':'status','kind':'exchange','slot_id':slot['slot_id'],'process':True})
        check(code==200 and slot['status']=='activated' and slot['gen_address']=='C'*34,'exchange activation returns service instructions')
        check(state['addresses']==4,'generated-address adapters execute without corrupting JSON')
        print(str(checks)+' HTTP integration checks passed',flush=True)
    except Exception:
        log.flush(); log.seek(0)
        print(log.read()[-10000:],file=sys.stderr)
        raise
    finally:
        for process in processes:
            try: os.killpg(process.pid,signal.SIGTERM)
            except ProcessLookupError: pass
            process.wait(timeout=5)
        node.shutdown(); log.close()
