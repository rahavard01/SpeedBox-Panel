"""Offline update regression. Only temporary Git repos and mocked services/tools."""
from pathlib import Path
import hashlib, os, shutil, subprocess, tempfile

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

legacy_applied = {'legacy-already-applied', 'legacy-applied-changed'}
legacy_pending = {'legacy-reviewed', 'legacy-unreviewed', 'legacy-duplicate'}
successful_migrations = {'migration-auto', 'migration-same-sha', 'legacy-reviewed'}
unchanged_migrations = {'migration-already-applied', 'legacy-already-applied'}
blocked_migrations = {'migration-block', 'applied-migration-changed', 'legacy-applied-changed',
                      'legacy-unreviewed', 'legacy-duplicate'}
scenarios = ['success', 'dirty', 'busy', 'real-storage', 'backup-fail', 'cache-fail', 'health-fail',
             'migration-fail', 'dependency-fail', 'agent-fail']
for scenario in [*scenarios, *sorted(successful_migrations | unchanged_migrations | blocked_migrations)]:
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
        if scenario in legacy_applied | legacy_pending:
            migration = 'database/migrations/2025_11_27_000002_create_speedbox_client_app_releases_table.php.php'
        else:
            migration = 'database/migrations/2026_11_22_000001_fixture.php'
        migration_name = Path(migration).name.replace('.php', '')
        migration_code = '<?php // fixture migration\n'
        def add_migration(reviewed=False):
            path=publish/migration
            path.parent.mkdir(parents=True,exist_ok=True)
            path.write_text(migration_code)
            if reviewed:
                (publish/'scripts/automatic-migrations.txt').write_text(hashlib.sha256(path.read_bytes()).hexdigest()+' '+migration+'\n')
        if scenario in unchanged_migrations | legacy_applied | {'migration-same-sha', 'applied-migration-changed'}:
            add_migration(scenario not in legacy_applied)
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
        if scenario in legacy_pending | {'migration-block', 'migration-fail', 'migration-auto'}:
            add_migration(scenario not in {'migration-block', 'legacy-unreviewed'})
        if scenario=='legacy-duplicate':
            alias = migration.removesuffix('.php')
            (publish/alias).write_text(migration_code)
            with (publish/'scripts/automatic-migrations.txt').open('a') as reviews:
                reviews.write(hashlib.sha256((publish/alias).read_bytes()).hexdigest()+' '+alias+'\n')
        if scenario in ('applied-migration-changed', 'legacy-applied-changed'):
            (publish/migration).write_text('<?php // changed already applied migration\n')
        if scenario=='migration-same-sha':
            (publish/'app/version.txt').write_text('old')
        run(['git','-C',str(publish),'add','-A']);run(['git','-C',str(publish),'commit','--allow-empty','-qm','target'])
        target=run(['git','-C',str(publish),'rev-parse','HEAD']).stdout.strip()
        run(['git','-C',str(publish),'push',str(origin),'main'])
        for rel in ['vendor/autoload.php','storage/framework/.keep','storage/app/private/upload.bin','public/.keep','resources/js/private.vue']:
            path=project/rel;path.parent.mkdir(parents=True,exist_ok=True);path.write_text('preserve')
        if scenario=='migration-same-sha':
            run(['git','-C',str(project),'pull','--ff-only'])
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
  -r)
    if [[ "$*" = *ledger-ready* ]]; then
      echo ledger-ready
      if [[ "$TEST_APPLIED" = 1 || -f "$TEST_MIGRATED" ]]; then
        printf '%s\n' "$TEST_MIGRATION_NAME"
      fi
    fi
    exit 0;;
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
      migrate)
        [[ "$TEST_SCENARIO" != migration-fail ]] || exit 2
        [[ "$#" = 4 && "$4" = "--path=$TEST_MIGRATION_PATH" ]] || exit 4
        touch "$TEST_MIGRATED";;
    esac;;
esac
''')
        tool('id','case "${1:-}" in -u) echo 0;; -gn) echo www;; *) exit 0;; esac\n')
        tool('chown','exit 0\n');tool('sleep','exit 0\n')
        real_stat=shutil.which('stat')
        tool('stat', f"""case "$2" in
'%u %a') printf '0 '; exec {real_stat} -c '%a' -- "${{@: -1}}";;
'%u') echo 0;;
*) exec {real_stat} "$@";;
esac
""")
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
            TEST_LOG=str(base/'calls.log'),TEST_MIGRATED=str(base/'migrated'),TEST_PROJECT=str(project),TEST_SCENARIO=scenario,
            TEST_MIGRATION_PATH=migration, TEST_MIGRATION_NAME=migration_name,
            TEST_APPLIED=str(int(scenario in unchanged_migrations | legacy_applied | {'applied-migration-changed'})),
            SPEEDBOX_BACKUP_DIR=str(base/'backups'))
        if scenario=='success':
            backup_base=base/'backups'
            namespace=backup_base/('panel-'+hashlib.sha256(str(project).encode()).hexdigest())
            namespace.mkdir(parents=True,mode=0o700)
            backup_base.chmod(0o755)
            earlier=namespace/'update-20260101-000001-AAAAAAAA'
            failed=namespace/'update-20260101-000002-BBBBBBBB'
            manual=backup_base/'update-20260101-000003-CCCCCCCC'
            for path in [earlier,failed,manual]: path.mkdir(mode=0o700)
            (earlier/'.successful').write_text(namespace.name.removeprefix('panel-')+'\n')
            link=namespace/'update-20260101-000004-DDDDDDDD'
            link.symlink_to(manual,target_is_directory=True)
        result=subprocess.run(['bash',str(project/'update.sh')],cwd=project,env=env,capture_output=True,text=True)
        check(result.stdout.count('PANEL UPDATER') == 1,scenario+' banner printed exactly once by snapshot')
        if result.returncode not in [0,1] and scenario=='success':raise AssertionError(result.stdout+result.stderr)
        expected_success = scenario in successful_migrations | unchanged_migrations | {'success'}
        check((result.returncode==0)==expected_success,scenario+' exit status '+result.stdout+result.stderr)
        head=run(['git','-C',str(project),'rev-parse','HEAD']).stdout.strip()
        check(head==(target if expected_success or scenario=='migration-fail' else old),scenario+' expected commit')
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
            check(len(list((base/'backups').glob('panel-*/*/database.sql-or-sqlite')))==1,'database backup created')
            check(not earlier.exists(),'previous successful automatic backup pruned')
            check(failed.is_dir() and manual.is_dir() and link.is_symlink(),'failed, manual and symlink backups retained')
            check((project/'app/version.txt').stat().st_mode & 0o044 != 0,'updated source remains readable by web user')
        if scenario=='dependency-fail':check((project/'vendor/autoload.php').read_text()=='preserve','failed dependency update restores vendor')
        if scenario=='dirty':check((project/'app/version.txt').read_text()=='local edit','dirty source preserved')
        if scenario=='agent-fail':
            check('SIGUSR2' not in (base/'calls.log').read_text(),'agent failure before pausing queue')
            check('artifact-preflight-fixture' in result.stderr,'target artifact preflight ran')
        if scenario=='busy':check('SIGCONT' in (base/'calls.log').read_text(),'busy worker resumed before maintenance')
        if scenario in successful_migrations:
            check((base/'migrated').is_file(),scenario+' reviewed migration included')
        if scenario in unchanged_migrations:
            check(not (base/'migrated').is_file(),'applied migration not rerun')
        if scenario in blocked_migrations:
            check('SIGUSR2' not in (base/'calls.log').read_text(),scenario+' stopped before downtime')
        if scenario=='legacy-applied-changed':
            check('An applied migration has changed' in result.stderr, 'legacy applied name protects the saved ledger')
        if scenario=='legacy-unreviewed':
            check('Pending migration requires review' in result.stderr, 'legacy pending migration requires review')
        if scenario=='legacy-duplicate':
            check('Duplicate migration name' in result.stderr, 'legacy migration aliases are rejected')
        if scenario=='migration-fail':check('do not restore SQL automatically' in result.stderr,'migration failure requires manual DB recovery')

print(f'SAFE UPDATE OK: {checks} checks passed; temporary repositories and mocked services only.')
