#!/usr/bin/env python3
"""Real verifier/extractor with tiny ELF fixtures, disposable Git repos, mocked services.
No live application or database; no download or Go execution.
"""
from pathlib import Path
import hashlib, json, os, shutil, subprocess, tempfile

source = Path(__file__).resolve().parents[2]
php = os.environ.get('PHP_AUDIT_BIN', 'php')
checks = 0

def check(ok, label):
    global checks
    if not ok: raise AssertionError(label)
    checks += 1

def run(args, **kw):
    kw.setdefault('capture_output', True); kw.setdefault('text', True)
    return subprocess.run(args, check=True, **kw)

def fixture(root, native):
    (root/'scripts').mkdir(parents=True); (root/'agent').mkdir()
    (root/'agent/source.go').write_text('package fixture\n')
    for name in ['build-agent.sh', 'build-agent-from-source.sh', 'agent-artifacts.php', 'package-agent.py']:
        shutil.copyfile(source/'scripts'/name, root/'scripts'/name)
    out=root/'storage/app/agent';out.mkdir(parents=True)
    for arch, machine in [('amd64',62),('arm64',183)]:
        for program in ['xboard-node','xbctl']:
            path=out/(program+'-linux-'+arch)
            if arch=='amd64':shutil.copyfile(native,path)
            else:
                header=bytearray(64);header[:6]=b'\x7fELF\x02\x01';header[18:20]=machine.to_bytes(2,'little');path.write_bytes(header)
    run(['python3',str(root/'scripts/package-agent.py')])
    shutil.rmtree(root/'storage')

def tool(path, body):
    path.write_text('#!/usr/bin/env bash\nset -eu\n'+body);path.chmod(0o755)

with tempfile.TemporaryDirectory(prefix='speedbox-agent-tests-') as temp:
    top=Path(temp); c=top/'version.c'; native=top/'version'
    c.write_text('#include <stdio.h>\nint main(void){puts("panel-speedbox-http-obfs-uuid-v1");return 0;}\n')
    run(['cc',str(c),'-o',str(native)])
    tools=top/'tools';tools.mkdir()
    for name in ['go','curl']:
        tool(tools/name,'echo unexpected-network-or-build >> "$BLOCK_LOG"\nexit 99\n')
    env=dict(os.environ,PHP_BIN=php,PATH=str(tools)+':'+os.environ['PATH'],BLOCK_LOG=str(top/'blocked'))
    good=top/'good';fixture(good,native)
    for scenario in ['success','crlf','source-change','extra-source','missing-archive','bad-archive','binary-mismatch','wrong-architecture','output-symlink','wrong-version','unsupported-host']:
        root=top/scenario;shutil.copytree(good,root)
        output=root/'storage/app/agent';output.mkdir(parents=True)
        old=output/'xboard-node-linux-amd64';old.write_bytes(b'keep previous binary');old.chmod(0o751)
        if scenario=='crlf':
            for path in (root/'agent').rglob('*'):
                if path.is_file():path.write_bytes(path.read_bytes().replace(b'\n',b'\r\n'))
            # Git Bash executes the LF shell; PHP canonicalizes all source hashes.
        if scenario=='source-change':(root/'agent/source.go').write_text('changed\n')
        if scenario=='extra-source':(root/'agent/new.go').write_text('new\n')
        if scenario=='missing-archive':(root/'agent-dist/linux-amd64.tar.gz').unlink()
        if scenario=='bad-archive':(root/'agent-dist/linux-amd64.tar.gz').write_bytes(b'bad')
        if scenario in ['binary-mismatch','wrong-architecture','wrong-version']:
            # Re-package controlled fixtures, then test the independent verifier.
            shutil.rmtree(output.parent.parent)
            shutil.copytree(good/'agent-dist', root/'old-dist')
            fixture_out=root/'storage/app/agent';fixture_out.mkdir(parents=True)
            import tarfile
            for arch in ['amd64','arm64']:
                with tarfile.open(root/'old-dist'/('linux-'+arch+'.tar.gz')) as tar:tar.extractall(fixture_out,filter='data')
            if scenario=='wrong-architecture':(fixture_out/'xboard-node-linux-arm64').write_bytes(native.read_bytes())
            if scenario=='wrong-version':
                c.write_text('#include <stdio.h>\nint main(void){puts("old-release");return 0;}\n')
                run(['cc',str(c),'-o',str(fixture_out/'xboard-node-linux-amd64')])
            if scenario!='wrong-architecture':run(['python3',str(root/'scripts/package-agent.py')])
            if scenario=='wrong-architecture':
                # Keep a known archive, alter its expected binary architecture in extracted mode below.
                shutil.copyfile(root/'old-dist/linux-arm64.tar.gz',root/'agent-dist/linux-arm64.tar.gz')
            shutil.rmtree(root/'storage');output.mkdir(parents=True);old.write_bytes(b'keep previous binary');old.chmod(0o751)
            if scenario=='binary-mismatch':
                meta=json.loads((root/'agent-dist/manifest.json').read_text());meta['archives']['amd64']['binaries']['xboard-node-linux-amd64']['sha256']='0'*64
                (root/'agent-dist/manifest.json').write_text(json.dumps(meta))
        if scenario=='output-symlink':
            shutil.rmtree(output);output.symlink_to(top/'outside',target_is_directory=True)
        if scenario=='unsupported-host':tool(tools/'uname','echo mips\n')
        if scenario=='wrong-architecture':
            extracted=root/'extracted';extracted.mkdir()
            import tarfile
            for arch in ['amd64','arm64']:
                with tarfile.open(root/'agent-dist'/('linux-'+arch+'.tar.gz')) as tar:tar.extractall(extracted,filter='data')
            (extracted/'xboard-node-linux-arm64').write_bytes(native.read_bytes())
            meta=json.loads((root/'agent-dist/manifest.json').read_text());entry=meta['archives']['arm64']['binaries']['xboard-node-linux-arm64'];entry.update(bytes=native.stat().st_size,sha256=hashlib.sha256(native.read_bytes()).hexdigest());(root/'agent-dist/manifest.json').write_text(json.dumps(meta))
            result=subprocess.run([php,str(root/'scripts/agent-artifacts.php'),'extracted',str(root),str(extracted)],capture_output=True,text=True,env=env)
        else:result=subprocess.run(['bash',str(root/'scripts/build-agent.sh')],capture_output=True,text=True,env=env)
        check((result.returncode==0)==(scenario in ['success','crlf']),scenario+' exit '+result.stdout+result.stderr)
        if scenario in ['success','crlf']:
            meta=json.loads((root/'agent-dist/manifest.json').read_text())
            for entry in meta['archives'].values():
                for name,binary in entry['binaries'].items():
                    check(hashlib.sha256((output/name).read_bytes()).hexdigest()==binary['sha256'],scenario+' binary '+name)
                    check((output/name).stat().st_mode&0o777==0o755,scenario+' permissions')
            again=subprocess.run(['bash',str(root/'scripts/build-agent.sh')],capture_output=True,text=True,env=env)
            check(again.returncode==0,scenario+' idempotent')
        elif scenario!='output-symlink':check(old.read_bytes()==b'keep previous binary',scenario+' preserves previous binary')
        if output.is_dir():check(not list(output.glob('.release-*')),scenario+' temporary extraction cleaned')
        if (tools/'uname').exists():(tools/'uname').unlink()
    check(not (top/'blocked').exists(),'no Go or curl execution')
    # Installer runs the real bundled preflight before Composer/.env/migrations.
    installer=(source/'install.sh').read_text()
    check(installer.index('bash scripts/build-agent.sh') < installer.index('\nensure_composer_min\n'),'installer preflight before dependency/database changes')
    # Real Git archive + real verifier/extractor, with only service/database boundaries mocked.
    for scenario in ['agent-success','agent-first-upgrade','agent-corrupt','agent-source-mismatch','agent-cache-fail','app-only']:
        base=top/('update-'+scenario);base.mkdir();pub=base/'pub';shutil.copytree(good,pub)
        (pub/'update.sh').write_text('#!/usr/bin/env bash\necho OLD UPDATER MUST NOT RUN >&2\nexit 99\n' if scenario=='agent-first-upgrade' else (source/'update.sh').read_text())
        (pub/'scripts/update-database.php').write_text('<?php // boundary mocked\n')
        (pub/'.gitignore').write_text('.env\nvendor/\nstorage/\npublic/storage\n')
        (pub/'app').mkdir();(pub/'app/version.txt').write_text('old')
        def git(*args, cwd=pub):return run(['git','-c','core.hooksPath=/dev/null',*args],cwd=cwd).stdout.strip()
        git('init','-q','--initial-branch=main');git('config','user.name','Fixture');git('config','user.email','fixture@example.test')
        git('add','-A');git('commit','-qm','baseline');old_commit=git('rev-parse','HEAD')
        origin=base/'origin.git';git('clone','-q','--bare',str(pub),str(origin))
        project=base/'project';git('clone','-q',str(origin),str(project))
        (pub/'app/version.txt').write_text('new')
        if scenario=='agent-first-upgrade':(pub/'update.sh').write_text((source/'update.sh').read_text())
        if scenario!='app-only':
            (pub/'agent/source.go').write_text('package fixture // new source\n')
            out=pub/'storage/app/agent';out.mkdir(parents=True)
            import tarfile
            for arch in ['amd64','arm64']:
                with tarfile.open(pub/'agent-dist'/('linux-'+arch+'.tar.gz')) as tar:tar.extractall(out,filter='data')
            run(['python3',str(pub/'scripts/package-agent.py')])
            if scenario=='agent-corrupt':(pub/'agent-dist/linux-amd64.tar.gz').write_bytes(b'bad archive')
            if scenario=='agent-source-mismatch':(pub/'agent/source.go').write_text('unpackaged change\n')
        git('add','-A');git('commit','-qm','release');target=git('rev-parse','HEAD');git('push',str(origin),'main')
        (project/'.env').write_text('keep-env')
        (project/'vendor').mkdir();(project/'vendor/autoload.php').write_text('mocked')
        (project/'storage/framework').mkdir(parents=True)
        (project/'public').mkdir()
        out=project/'storage/app/agent';out.mkdir(parents=True)
        names=['xboard-node-linux-amd64','xbctl-linux-amd64','xboard-node-linux-arm64','xbctl-linux-arm64']
        for name in names:(out/name).write_text('original '+name);(out/name).chmod(0o751)
        bins=base/'bin';bins.mkdir()
        tool(bins/'php','''echo "$*" >> "$LOG"
if [[ "$1" = *agent-artifacts.php ]];then exec "$REAL_PHP" "$@";fi
case "$1" in
 -r) exit 0;;
 scripts/update-database.php)
  case "$2" in inspect) echo '1 7011 panel.test';; queue-idle) exit 0;; backup) echo sql-fixture > "$3";; esac;;
 artisan)
  case "$2" in down) touch storage/framework/down;; up) rm -f storage/framework/down;; config:cache) [[ "$CASE" != agent-cache-fail ]];; esac;;
esac
''')
        tool(bins/'systemctl','''echo "systemctl $*" >> "$LOG"
case "$1" in
 list-unit-files) printf 'fixture-http.service enabled\nfixture-queue.service enabled\n';;
 show)
  case "$3" in
   --property=WorkingDirectory) echo "$PROJECT";;
   --property=User) echo www;;
   --property=ExecStart) if [[ "$2" = fixture-http.service ]];then echo 'php artisan octane:start';else echo 'php artisan queue:work';fi;;
  esac;;
esac
''')
        tool(bins/'runuser','shift 3; exec "$@"\n');tool(bins/'chown','exit 0\n');tool(bins/'sleep','exit 0\n');tool(bins/'curl','printf 200\n')
        tool(bins/'id','case "${1:-}" in -u) echo 0;; -gn) echo www;; *) exit 0;; esac\n')
        tool(bins/'go','echo unexpected-go >> "$BLOCK_LOG"; exit 99\n')
        environment=dict(env,PATH=str(bins)+':'+os.environ['PATH'],REAL_PHP=php,PHP_BIN=str(bins/'php'),LOG=str(base/'calls'),PROJECT=str(project),CASE=scenario,SPEEDBOX_BACKUP_DIR=str(base/'backups'),GIT_CONFIG_GLOBAL=os.devnull,GIT_CONFIG_NOSYSTEM='1')
        for key in list(environment):
            if key.startswith('SPEEDBOX_') and key!='SPEEDBOX_BACKUP_DIR':del environment[key]
        launcher=project/'update.sh'
        if scenario=='agent-first-upgrade':
            launcher=base/'target-updater.sh';launcher.write_text((source/'update.sh').read_text())
            environment.update(SPEEDBOX_UPDATE_SNAPSHOT='1',SPEEDBOX_UPDATE_PROJECT=str(project))
        result=subprocess.run(['bash',str(launcher)],cwd=project,env=environment,capture_output=True,text=True)
        success=scenario in ['agent-success','agent-first-upgrade','app-only']
        check((result.returncode==0)==success,scenario+' updater exit '+result.stdout+result.stderr)
        check(git('rev-parse','HEAD',cwd=project)==(target if success else old_commit),scenario+' code version')
        check((project/'storage/framework/down').exists()==(scenario=='agent-cache-fail'),scenario+' maintenance')
        log=(base/'calls').read_text()
        if scenario in ['agent-corrupt','agent-source-mismatch']:
            check('SIGUSR2' not in log and 'artisan down' not in log,scenario+' stops before downtime')
            check(not (base/'backups').exists(),scenario+' before backup/DB access')
        for name in names:
            if scenario in ['agent-success','agent-first-upgrade']:check((out/name).read_bytes()!=(('original '+name).encode()),scenario+' installed binary')
            else:
                check((out/name).read_text()=='original '+name,scenario+' retains/restores binary')
                check((out/name).stat().st_mode&0o777==0o751,scenario+' retains/restores permissions')
        check((project/'.env').read_text()=='keep-env',scenario+' environment retained')
    check(not (top/'blocked').exists(),'updater never executes Go')
print(f'AGENT ARTIFACT TESTS OK: {checks} checks; tiny ELF fixtures; no live database or services.')
