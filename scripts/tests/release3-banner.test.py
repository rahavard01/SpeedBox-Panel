#!/usr/bin/env python3
"""Exercise the banner functions only, in redirected output and real pseudo terminals.
Never executes install.sh/update.sh themselves or touches services/databases.
"""
from pathlib import Path
import errno,json,os,pty,re,select,subprocess,tempfile
root=Path(__file__).resolve().parents[2]
checks=0

def check(value,label):
 global checks
 if not value:raise AssertionError(label)
 checks+=1

def function(path):
 s=path.read_text();a=s.index('speedbox_banner() {');b=s.index('\n}',a)+2
 return s[a:b]

banner=function(root/'install.sh')
check(banner==function(root/'update.sh'),'installer and updater share identical banner behavior')
ansi=re.compile(r'\x1b\[[0-9;]*m')

def cells(output):
 # SGR bold (1) and faint (2) are independent flags; 1 does not cancel 2.
 bold=faint=False;position=0
 for match in ansi.finditer(output):
  for character in output[position:match.start()]:yield character,bold,faint
  codes=[int(c or '0') for c in match.group()[2:-1].split(';')];i=0
  while i<len(codes):
   code=codes[i]
   if code==0:bold=faint=False
   elif code==1:bold=True
   elif code==2:faint=True
   elif code==22:bold=faint=False
   elif code in (38,48) and i+1<len(codes):i+=4 if codes[i+1]==2 else 2
   i+=1
  position=match.end()
 for character in output[position:]:yield character,bold,faint

with tempfile.TemporaryDirectory(prefix='speedbox-banner-test-') as temp:
 script=Path(temp)/'banner.sh';script.write_text('set -Eeuo pipefail\n'+banner+'\nspeedbox_banner "PANEL UPDATER"\n')
 def execute(terminal=True,**settings):
  env=dict(os.environ,TERM='xterm-256color',COLORTERM='truecolor',COLUMNS='80',LC_ALL='C');env.pop('NO_COLOR',None);env.update(settings)
  if not terminal:return subprocess.run(['bash',str(script)],env=env,check=True,capture_output=True).stdout.decode('utf-8')
  master,slave=pty.openpty();process=subprocess.Popen(['bash',str(script)],stdout=slave,stderr=slave,env=env);os.close(slave);data=b''
  try:
   while True:
    if not select.select([master],[],[],10)[0]:process.kill();raise AssertionError('banner timed out')
    try:part=os.read(master,65536)
    except OSError as e:
     if e.errno==errno.EIO:break
     raise
    if not part:break
    data+=part
  finally:os.close(master)
  check(process.wait()==0,'banner exits successfully with set -e')
  return data.decode('utf-8')
 for name,settings in [('rgb',{}),('256',{'COLORTERM':''}),('ansi',{'COLORTERM':'','TERM':'xterm'}),('no-color',{'NO_COLOR':'1'}),('dumb',{'TERM':'dumb'})]:
  output=execute(**settings);plain=ansi.sub('',output).replace('\r','')
  check(plain.count('PANEL UPDATER')==1,name+' subtitle once')
  check('█' in plain and '╔' in plain and '╚' in plain,name+' filled shadow lettering')
  check('─'*64 in plain,name+' separator below subtitle')
  check(max(map(len,plain.splitlines()))<=80,name+' fits terminal')
  if name in ['no-color','dumb']:check('\x1b' not in output,name+' no escapes')
  elif name=='rgb':check('\x1b[22;1;38;2;192;178;255m' in output,name+' lavender gradient');check('38;2;' in output,name+' truecolor')
  elif name=='256':check(all('38;5;'+str(c)+'m' in output for c in [183,147,117,87,51]),'256-color lavender to cyan palette')
  else:check('95m' in output and '96m' in output,'ANSI purple and cyan')
  if name in ['rgb','256','ansi']:
   painted=list(cells(output))
   check(all(bold and not faint for char,bold,faint in painted if char=='█'),name+' every filled letter stays bright after shadows')
   check(all(faint and not bold for char,bold,faint in painted if char in '╔╗╚╝═║'),name+' shadows stay soft without accumulated bold')
   check(all(bold and not faint for char,bold,faint in painted if char=='─'),name+' separator stays bright')
   check(not painted[-1][1] and not painted[-1][2],name+' terminal intensity is reset after the banner')
  if name=='rgb' and os.environ.get('SPEEDBOX_BANNER_PREVIEW'):Path(os.environ['SPEEDBOX_BANNER_PREVIEW']).write_text(output)
 for columns in ['60','40','0','invalid']:
  output=execute(COLUMNS=columns);plain=ansi.sub('',output).replace('\r','')
  check(plain.count('PANEL UPDATER')==1,columns+' subtitle once')
  if columns=='60':check('╔' not in plain and '█' in plain and max(map(len,plain.splitlines()))<=60,'compact lettering for narrow terminal')
  if columns=='40':check('SPEEDBOX' in plain and max(map(len,plain.splitlines()))<=40,'small terminal uses word mark')
 output=execute(False);check('\x1b' not in output,'redirected logs contain no ANSI escapes')
 check('╔' in output and '─' in output,'redirected output retains readable banner and separator')
pkg=json.loads((root/'package.json').read_text());lock=json.loads((root/'package-lock.json').read_text())
check(pkg['version']=='3.0' and lock['version']=='3.0' and lock['packages']['']['version']=='3.0','root package versions agree on 3.0')
check(len(pkg['panelRelease']['changes'])==5,'exactly five release notes')
print(f'RELEASE 3 BANNER OK: {checks} checks; pseudo terminals and temporary files only.')
