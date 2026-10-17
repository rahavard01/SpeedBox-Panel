# Linux + Swoole: PHP_BIN=/path/to/php python scripts/tests/octane-runtime.test.py
# All database, storage and application cache paths are isolated from project data.
import base64, json, os, pathlib, socket, subprocess, tempfile, time, urllib.request, urllib.error, urllib.parse, sqlite3
root=pathlib.Path(__file__).resolve().parents[2]
php=os.environ.get('PHP_BIN','php')
with tempfile.TemporaryDirectory(prefix='speedbox-octane-test-') as directory:
    temp=pathlib.Path(directory)
    for part in ['storage/logs','storage/framework/cache/data','storage/framework/sessions','storage/framework/views']:
        (temp/part).mkdir(parents=True,exist_ok=True)
    database=temp/'test.sqlite'; database.touch()
    with socket.socket() as probe:
        probe.bind(('127.0.0.1',0)); port=probe.getsockname()[1]
    env=dict(os.environ, APP_ENV='testing', APP_KEY='base64:'+base64.b64encode(b'a'*32).decode(),
        APP_URL=f'http://127.0.0.1:{port}', APP_NAME='OctaneQA', DB_CONNECTION='sqlite',DB_DATABASE=str(database),
        CACHE_STORE='file',CACHE_PREFIX=directory,QUEUE_CONNECTION='database',SESSION_DRIVER='file',
        LARAVEL_STORAGE_PATH=str(temp/'storage'),APP_CONFIG_CACHE=str(temp/'config.php'),
        APP_ROUTES_CACHE=str(temp/'routes.php'),APP_EVENTS_CACHE=str(temp/'events.php'),OCTANE_HTTPS='false')
    fixture=subprocess.run([php,'scripts/tests/octane-fixture.php'],cwd=root,env=env,text=True,capture_output=True,check=True)
    auth=json.loads(fixture.stdout)
    log=open(temp/'octane.log','w+')
    server=subprocess.Popen([php,'artisan','octane:start','--server=swoole','--host=127.0.0.1',f'--port={port}',
        '--workers=1','--task-workers=0','--max-requests=100'],cwd=root,env=env,stdout=log,stderr=log)
    def request(path, token=None, payload=None, browser=False):
        headers={'Accept':'application/json'}
        if token: headers['Authorization']='Bearer '+token
        if browser: headers.update(Accept='text/html',**{'Sec-Fetch-Dest':'document'})
        if payload is not None: headers['Content-Type']='application/json'
        req=urllib.request.Request(env['APP_URL']+path,headers=headers,data=json.dumps(payload).encode() if payload is not None else None)
        try:
            with urllib.request.urlopen(req,timeout=8) as response: return response.status,response.read().decode()
        except urllib.error.HTTPError as error: return error.code,error.read().decode()
    try:
        for attempt in range(80):
            if server.poll() is not None: raise AssertionError('Octane exited during startup')
            try:
                if request('/up')[0]==200: break
            except (OSError,TimeoutError): pass
            time.sleep(.1)
        else: raise AssertionError('Octane startup timed out')
        queue='/api/admin/infrastructure/queue'
        for token,expected in [(None,401),(auth['admin'],200),(auth['partner'],403),(auth['admin'],200),(None,401)]:
            status,body=request(queue,token)
            assert status==expected,(status,body)
            if status==200:
                result=json.loads(body)['data']
                assert {x['name'] for x in result['workload']}=={'default','deployments'}
                assert result['processes']==1 and result['status']=='running'
        status,body=request('/api/login',payload={'email':'admin@example.test','password':'test-password'})
        assert status==200 and json.loads(body)['user']['role']==1,(status,body)
        node={'machine_id':auth['machine_id'],'node_id':auth['node_id'],'token':auth['machine_token']}
        query=urllib.parse.urlencode(node)
        assert request('/api/v2/server/config?'+query)[0]==200
        assert request('/api/v2/server/user?'+query)[0]==200
        assert request('/api/v2/server/report',payload=dict(node,traffic={'1':[100,200]}))[0]==200
        with sqlite3.connect(database) as db: assert db.execute('SELECT u,d FROM v2_user WHERE id=1').fetchone()==(200,400)
        for letter in ['a','b','a']:
            status,body=request('/s/'+letter*32+'?raw=1')
            assert status==200 and letter*8+'-1234' in body and ('b' if letter=='a' else 'a')*8+'-1234' not in body,(status,body)
        assert request('/s/'+'a'*32,browser=True)[0]==200
        subprocess.run([php,'scripts/tests/octane-fixture.php','settings'],cwd=root,env=env,check=True,capture_output=True)
        status,body=request('/custom/'+'a'*32+'?raw=1')
        assert status==200 and 'aaaaaaaa-1234' in body,(status,body)
        subprocess.run([php,'artisan','octane:reload','--server=swoole'],cwd=root,env=env,check=True,capture_output=True)
        for attempt in range(30):
            try:
                if request('/up')[0]==200: break
            except OSError: pass
            time.sleep(.1)
        assert request(queue,auth['partner'])[0]==403
        print('Octane/Swoole single-worker login, role isolation, queues, node reports, subscription isolation, runtime settings and reload passed.')
    except Exception:
        log.flush();log.seek(0);print(log.read());raise
    finally:
        subprocess.run([php,'artisan','octane:stop','--server=swoole'],cwd=root,env=env,capture_output=True)
        try: server.wait(timeout=8)
        except subprocess.TimeoutExpired: server.kill();server.wait()
        log.close()
