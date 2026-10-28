#!/usr/bin/env python3
"""Exercise the production executor with real OpenSSH clients and local AsyncSSH.
Requires asyncssh, PHP and symfony/process (vendor or SPEEDBOX_PROCESS_SOURCE).
"""
import asyncio,json,os,pathlib,subprocess,tempfile,argparse
import asyncssh
if os.environ.get("SSH_TEST_DEBUG"):
 import logging
 logging.basicConfig(filename=os.environ["SSH_TEST_DEBUG"],level=logging.DEBUG)
 asyncssh.set_debug_level(2)
ROOT=pathlib.Path(__file__).resolve().parents[2]
async def main():
 p=argparse.ArgumentParser();p.add_argument('--php',default='php');args=p.parse_args()
 with tempfile.TemporaryDirectory(prefix='speedbox-host-key-') as temp:
  tmp=pathlib.Path(temp);keys={n:asyncssh.generate_private_key('ssh-ed25519') for n in ['host','second','identity','wrong']};commands=[]
  class Server(asyncssh.SSHServer):
   def begin_auth(self,username):return True
   def public_key_auth_supported(self):return True
   def validate_public_key(self,username,key):return key==keys['identity'].convert_to_public()
  async def process(proc):
   commands.append(proc.command)
   child=await asyncio.create_subprocess_shell(proc.command,stdin=asyncio.subprocess.PIPE,stdout=asyncio.subprocess.PIPE,stderr=asyncio.subprocess.PIPE)
   data=await proc.stdin.read();child.stdin.write(data.encode());child.stdin.close()
   async def pump(source,target):
    while chunk:=await source.read(1024):target.write(chunk.decode())
   await asyncio.gather(pump(child.stdout,proc.stdout),pump(child.stderr,proc.stderr));proc.exit(await child.wait())
  class SFTP(asyncssh.SFTPServer):
   def exit(self):self.channel.exit(0)
  async def start(key,port=0):return await asyncssh.create_server(Server,'127.0.0.1',port,server_host_keys=[key],process_factory=process,sftp_factory=SFTP)
  daemon=await start(keys['host']);port=daemon.get_port();second=None
  server={'id':1,'host':'127.0.0.1','ssh_port':port,'ssh_user':'root','auth_type':'key','private_key':keys['identity'].export_private_key().decode(),'host_fingerprint':keys['host'].get_fingerprint('sha256')}
  async def check(name,changes=None,**payload):
   f=tmp/(name+'.json');f.write_text(json.dumps({'server':{**server,**(changes or {})},**payload}))
   proc=await asyncio.create_subprocess_exec(args.php,'-n',str(ROOT/'scripts/tests/ssh-host-key.test.php'),str(f),stdout=asyncio.subprocess.PIPE,stderr=asyncio.subprocess.PIPE,env={**os.environ,'SPEEDBOX_SSH_TEST_STORAGE':str(tmp/'storage')})
   out,err=await asyncio.wait_for(proc.communicate(),45);return proc.returncode,out.decode(),err.decode()
  try:
   r=await check('direct',command='printf DIRECT_OK');assert r[:2]==(0,'DIRECT_OK'),r
   r=await check('stdin',command='cat',input='sent on stdin');assert r[:2]==(0,'sent on stdin'),r
   sentinel=tmp/'storage/app/private/known_hosts';sentinel.write_text('unrelated-host ssh-ed25519 preserved\n')
   daemon.close();await daemon.wait_closed();daemon=await start(keys['second'],port)
   r=await check('rotate',command='printf ROTATED_OK');assert r[:2]==(0,'ROTATED_OK'),r
   saved={f.name:f.read_bytes() for f in sentinel.parent.glob('known-host-*')};assert len(saved)==1
   assert keys['second'].export_public_key().decode().split()[1] in next(iter(saved.values())).decode()
   r=await check('auth-failure',{'private_key':keys['wrong'].export_private_key().decode()},command='printf MUST_NOT_RUN');assert r[0]!=0 and 'Permission denied' in r[2],r
   assert saved=={f.name:f.read_bytes() for f in sentinel.parent.glob('known-host-*')}
   assert 'printf MUST_NOT_RUN' not in commands
   second=await start(keys['host']);r=await check('other-port',{'ssh_port':second.get_port(),'host_fingerprint':None},command='printf OTHER_OK');assert r[:2]==(0,'OTHER_OK'),r
   assert len(list(sentinel.parent.glob('known-host-*')))==2
   assert sentinel.read_text()=='unrelated-host ssh-ed25519 preserved\n'
   marker=tmp/'once';r=await check('once',command=f'printf x >> {marker}; false');assert r[0]!=0 and marker.read_text()=='x',r
   local=tmp/'payload';local.write_text('SCP_OK');target=tmp/'uploaded';r=await check('scp',mode='upload',source=str(local),destination=str(target));assert r[:2]==(0,'SCP_OK'),r
   stream=tmp/'stream';task=asyncio.create_task(check('stream',command='printf FIRST; sleep 0.6; printf SECOND',stream_path=str(stream)))
   for _ in range(100):
    if stream.exists() and 'FIRST' in stream.read_text():break
    await asyncio.sleep(.01)
   assert stream.exists() and 'FIRST' in stream.read_text() and not task.done(),'SSH callback buffered until completion'
   r=await task;assert r[:2]==(0,'FIRSTSECOND'),r
   assert not list(sentinel.parent.glob('speedbox-*')),'temporary credentials or pins leaked'
   print('PASS: real SSH/SCP/stdin, host reinstall rotation, isolated ports/hosts, rejected credentials, secret cleanup, no command replay, live process streaming')
  finally:
   daemon.close();await daemon.wait_closed()
   if second:second.close();await second.wait_closed()
asyncio.run(main())
