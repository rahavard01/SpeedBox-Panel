# python scripts/tests/octane-service.test.py — no real systemd services are changed.
from pathlib import Path
import os, subprocess, tempfile
source=Path(__file__).resolve().parents[1]/'setup-octane.sh'
with tempfile.TemporaryDirectory(prefix='speedbox-service-test-') as name:
    root=Path(name); (root/'scripts').mkdir();(root/'vendor').mkdir();(root/'units').mkdir();(root/'bin').mkdir()
    (root/'vendor/autoload.php').touch()
    script=root/'scripts/setup-octane.sh'
    script.write_text(source.read_text().replace('/etc/systemd/system',str(root/'units')))
    def tool(name,body):
        p=root/'bin'/name;p.write_text('#!/usr/bin/bash\n'+body);p.chmod(0o755)
    tool('php','if [[ "$*" == *vendor/autoload.php* ]];then echo "$TEST_SETTINGS";else exit "${TEST_SWOOLE_EXIT:-0}";fi\n')
    tool('id','if [ "$1" = -u ];then echo 0;elif [ "$1" = -gn ];then echo www;fi\n')
    tool('chown','exit 0\n');tool('chmod','exit 0\n');tool('sleep','exit 0\n')
    tool('systemctl','echo "$*" >> "$TEST_LOG"\nexit 0\n')
    tool('curl','echo "${TEST_HTTP_CODE:-200}"\n')
    env=dict(os.environ,PATH=str(root/'bin')+':/usr/bin',PHP_BIN=str(root/'bin/php'),TEST_LOG=str(root/'systemctl.log'))
    for settings,status in [('1 7010 2 500',200),('1 7010 2 500',503)]:
        result=subprocess.run(['/usr/bin/bash',str(script)],env=dict(env,TEST_SETTINGS=settings,TEST_HTTP_CODE=str(status)),text=True,capture_output=True)
        assert result.returncode==0,(result.stdout,result.stderr)
        unit=(root/'units/panel-speedbox-octane.service').read_text()
        assert '--host=127.0.0.1 --port=7010 --workers=2 --task-workers=0 --max-requests=500' in unit
        assert 'octane:stop --server=swoole' in unit and 'KillMode=mixed' in unit
    for settings in ['1 99999 2 500','1 7010 0 500','1 bad 2 500']:
        result=subprocess.run(['/usr/bin/bash',str(script)],env=dict(env,TEST_SETTINGS=settings),capture_output=True)
        assert result.returncode!=0
    result=subprocess.run(['/usr/bin/bash',str(script)],env=dict(env,TEST_SETTINGS='1 7010 2 500',TEST_SWOOLE_EXIT='1'),text=True,capture_output=True)
    assert result.returncode!=0 and 'Install the Swoole' in result.stderr
    result=subprocess.run(['/usr/bin/bash',str(script)],env=dict(env,TEST_SETTINGS='1 7010 2 500',TEST_HTTP_CODE='500'),text=True,capture_output=True)
    assert result.returncode!=0 and 'did not start' in result.stderr
    result=subprocess.run(['/usr/bin/bash',str(script)],env=dict(env,TEST_SETTINGS='0 7010 2 500'),text=True,capture_output=True)
    assert result.returncode==0 and 'disable --now panel-speedbox-octane.service' in (root/'systemctl.log').read_text()
print('Octane unit, local binding, restart/stop, maintenance health, invalid settings and missing Swoole checks passed.')
