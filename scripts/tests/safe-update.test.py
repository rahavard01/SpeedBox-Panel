"""Offline update regression. Only temporary Git repos and mocked services/tools."""
from pathlib import Path
import os, shutil, subprocess, tempfile

source=Path(__file__).resolve().parents[2]
isolated_env={k:v for k,v in os.environ.items() if not k.startswith(('GIT_','SPEEDBOX_')) and k not in ('COMPOSER_BIN','MYSQLDUMP_BIN')}
isolated_env.update(GIT_CONFIG_NOSYSTEM='1',GIT_CONFIG_GLOBAL=os.devnull)
checks=0
def check(ok,label):
    global checks
    if not ok: raise AssertionError(label)
    checks+=1

def run(args,**kw):
    kw.setdefault('env',isolated_env)
    return subprocess.run(args,check=True,capture_output=True,text=True,**kw)

for scenario in ['success','dirty','busy','real-storage','backup-fail','cache-fail','health-fail','migration-block','migration-fail','dependency-fail','agent-fail']:
    with tempfile.TemporaryDirectory(prefix='speedbox-safe-update-') as temp:
        base=Path(temp);publish=base/'publisher';origin=base/'origin.git';project=base/'project';tools=base/'bin';tools.mkdir()
        run(['git','init','-q','--initial-branch=main',str(publish)])
        run(['git','-C',str(publish),'config','user.name','Fixture'])
        run(['git','-C',str(publish),'config','user.email','fixture@example.test'])
        run(['git','-C',str(publish),'config','core.hooksPath',os.devnull])
        (publish/'scripts').mkdir();(publish/'app').mkdir()
        (publish/'update.sh').write_text((source/'update.sh').read_text())
        (publish/'scripts/update-database.php').write_text('<?php // mocked deployment helper\n')
        (publish/'scripts/build-agent.sh').write_text('#!/usr/bin/env bash\nexit 0\n')
        (publish/'composer.json').write_text('{}\n')
        (publish/'composer.lock').write_text('{}\n')
        (publish/'app/version.txt').write_text('old')
        (publish/'.gitignore').write_text('.env\nvendor/\nstorage/\npublic/storage\nresources/js/\n')
        run(['git','-C',str(publish),'add','-A']);run(['git','-C',str(publish),'commit','-qm','baseline'])
        old=run(['git','-C',str(publish),'rev-parse','HEAD']).stdout.strip()
        run(['git','clone','-q','--bare',str(publish),str(origin)])
        run(['git','clone','-q',str(origin),str(project)])
        run(['git','-C',str(project),'config','core.hooksPath',os.devnull])
        (publish/'app/version.txt').write_text('new')
        if scenario=='dependency-fail':(publish/'composer.lock').write_text('{"fixture":true}\n')
        if scenario=='agent-fail':
            (publish/'scripts/build-agent.sh').write_text('#!/usr/bin/env bash\necho artifact-preflight-fixture >&2\nexit 2\n')
            (publish/'agent').mkdir();(publish/'agent/source.go').write_text('fixture')
            (publish/'agent-dist').mkdir();(publish/'agent-dist/manifest.json').write_text('{}')
            for path in ['build-agent-from-source.sh','agent-artifacts.php','package-agent.py']:(publish/'scripts'/path).write_text('fixture')
        if scenario.startswith('migration'):
            (publish/'database/migrations').mkdir(parents=True)
            (publish/'database/migrations/new.php').write_text('<?php // fixture migration\n')
        run(['git','-C',str(publish),'add','-A']);run(['git','-C',str(publish),'commit','-qm','target'])
        target=run(['git','-C',str(publish),'rev-parse','HEAD']).stdout.strip()
        run(['git','-C',str(publish),'push',str(origin),'main'])
        for rel in ['vendor/autoload.php','storage/framework/.keep','storage/app/private/upload.bin','public/.keep','resources/js/private.vue']:
            path=project/rel;path.parent.mkdir(parents=True,exist_ok=True);path.write_text('preserve')
        (project/'.env').write_text('APP_KEY=fixture-preserved\n')
        (project/'untracked-important.txt').write_text('keep')
        if scenario=='dirty':(project/'app/version.txt').write_text('local edit')
        if scenario=='real-storage':(project/'public/storage').mkdir();(project/'public/storage/private.txt').write_text('keep')
        def tool(name,body):
            p=tools/name;p.write_text('#!/usr/bin/env bash\nset -eu\n'+body);p.chmod(0o755)
        tool('php',r'''
printf '%s\n' "$*" >> "$TEST_LOG"
if [[ "$1" = *composer ]];then
  [[ "$*" != *install* || "$TEST_SCENARIO" != dependency-fail ]] || { echo changed > vendor/autoload.php; exit 2; }
  exit 0
fi
case "${1:-}" in
  -r) exit 0;;
  scripts/update-database.php)
    case "${2:-}" in
      inspect) echo '1 7011 panel.test';;
      queue-idle) [[ "$TEST_SCENARIO" != busy ]];;
      backup) [[ "$TEST_SCENARIO" != backup-fail ]] || exit 2; printf 'fixture sql\n' > "$3";;
    esac;;
  artisan)
    case "${2:-}" in
      down) printf '{"fixture":true}' > storage/framework/down;;
      up) rm -f storage/framework/down;;
      config:cache) [[ "$TEST_SCENARIO" != cache-fail ]];;
      migrate) [[ "$TEST_SCENARIO" != migration-fail ]];;
    esac;;
esac
''')
        tool('id','case "${1:-}" in -u) echo 0;; -gn) echo www;; *) exit 0;; esac\n')
        tool('chown','exit 0\n');tool('sleep','exit 0\n')
        tool('runuser','shift 3; exec "$@"\n')
        tool('systemctl',r'''
printf 'systemctl %s\n' "$*" >> "$TEST_LOG"
case "$1" in
 list-unit-files) printf 'fixture-http.service enabled\nfixture-queue.service enabled\n';;
 show)
   case "$3" in
     --property=WorkingDirectory) echo "$TEST_PROJECT";;
     --property=User) echo www;;
     --property=ExecStart) if [[ "$2" = fixture-http.service ]];then echo "php $TEST_PROJECT/artisan octane:start";else echo "php $TEST_PROJECT/artisan queue:work database";fi;;
   esac;;
esac
''')
        tool('curl','if [[ "$TEST_SCENARIO" = health-fail ]];then echo 500;else echo 200;fi\n')
        tool('composer','exit 0\n')
        env=dict(isolated_env,PATH=str(tools)+':'+os.environ['PATH'],PHP_BIN=str(tools/'php'),
            TEST_LOG=str(base/'calls.log'),TEST_PROJECT=str(project),TEST_SCENARIO=scenario,
            SPEEDBOX_BACKUP_DIR=str(base/'backups'))
        if scenario=='migration-fail':env['SPEEDBOX_ALLOW_MIGRATIONS']='1'
        result=subprocess.run(['bash',str(project/'update.sh')],cwd=project,env=env,capture_output=True,text=True)
        check(result.stdout.count('PANEL UPDATER') == 1,scenario+' banner printed exactly once by snapshot')
        if result.returncode not in [0,1] and scenario=='success':raise AssertionError(result.stdout+result.stderr)
        check((result.returncode==0)==(scenario=='success'),scenario+' exit status '+result.stdout+result.stderr)
        head=run(['git','-C',str(project),'rev-parse','HEAD']).stdout.strip()
        check(head==(target if scenario in ['success','migration-fail'] else old),scenario+' expected commit')
        check((project/'storage/app/private/upload.bin').read_text()=='preserve',scenario+' private upload retained')
        check((project/'untracked-important.txt').read_text()=='keep',scenario+' untracked file retained')
        check((project/'.env').read_text()=='APP_KEY=fixture-preserved\n',scenario+' env/key unchanged')
        check((project/'resources/js/private.vue').is_file(),scenario+' local frontend retained')
        check((project/'update.sh').is_file(),scenario+' original updater retained')
        down=(project/'storage/framework/down').exists()
        check(down==(scenario in ['backup-fail','cache-fail','health-fail','migration-fail','dependency-fail']),scenario+' maintenance state')
        if scenario=='success':
            check('UPDATE FINISHED' in result.stdout,'success marker')
            check((project/'public/storage').is_symlink(),'storage link created without deleting data')
            check(len(list((base/'backups').glob('*/database.sql-or-sqlite')))==1,'database backup created')
            check((project/'app/version.txt').stat().st_mode & 0o044 != 0,'updated source remains readable by web user')
        if scenario=='dependency-fail':check((project/'vendor/autoload.php').read_text()=='preserve','failed dependency update restores vendor')
        if scenario=='dirty':check((project/'app/version.txt').read_text()=='local edit','dirty source preserved')
        if scenario=='agent-fail':
            check('SIGUSR2' not in (base/'calls.log').read_text(),'agent failure before pausing queue')
            check('artifact-preflight-fixture' in result.stderr,'target artifact preflight ran')
        if scenario=='busy':check('SIGCONT' in (base/'calls.log').read_text(),'busy worker resumed before maintenance')
        if scenario=='migration-fail':check('do not restore SQL automatically' in result.stderr,'migration failure requires manual DB recovery')

print(f'SAFE UPDATE OK: {checks} checks passed; temporary repositories and mocked services only.')
