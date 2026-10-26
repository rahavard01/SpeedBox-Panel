"""Small isolated release fixtures; no network, Go, .env or live services."""
from pathlib import Path
import hashlib,json,os,shutil,struct,subprocess,tarfile,tempfile,unittest

SOURCE=Path(__file__).resolve().parents[2]
PHP=os.environ.get('PHP_AUDIT_BIN','php')
sha=lambda data:hashlib.sha256(data).hexdigest()

class AgentPartsTests(unittest.TestCase):
 def setUp(self):
  self.temp=tempfile.TemporaryDirectory();self.root=Path(self.temp.name)
  (self.root/'agent').mkdir();(self.root/'agent/fixture.go').write_text('package fixture\n')
  (self.root/'scripts').mkdir();(self.root/'storage/app/agent').mkdir(parents=True)
  for name in ['build-agent.sh','build-agent-from-source.sh','agent-artifacts.php','package-agent.py']:
   shutil.copyfile(SOURCE/'scripts'/name,self.root/'scripts'/name)
  for arch,machine in [('amd64',62),('arm64',183)]:
   for program in ['xboard-node','xbctl']:
    header=bytearray(64);header[:6]=b'\x7fELF\x02\x01';header[18:20]=struct.pack('<H',machine)
    (self.root/f'storage/app/agent/{program}-linux-{arch}').write_bytes(header+program.encode())
  subprocess.run(['python3',str(self.root/'scripts/package-agent.py')],check=True,capture_output=True)
  self.manifest=json.loads((self.root/'agent-dist/manifest.json').read_text())
  # Legacy archives are fixtures only; the new packager ships reviewed parts.
  for arch,entry in self.manifest['archives'].items():
   data=b''.join((self.root/'agent-dist'/p['file']).read_bytes() for p in entry['parts'])
   (self.root/f'agent-dist/linux-{arch}.tar.gz').write_bytes(data)
 def tearDown(self):self.temp.cleanup()
 def save(self):(self.root/'agent-dist/manifest.json').write_text(json.dumps(self.manifest))
 def run_helper(self,mode='verify',stage=None,ok=True):
  command=[PHP,str(self.root/'scripts/agent-artifacts.php'),mode,str(self.root)]
  if stage:command.append(str(stage))
  result=subprocess.run(command,capture_output=True,text=True)
  self.assertEqual(result.returncode==0,ok,result.stdout+result.stderr)
  return result
 def test_split_and_legacy_archives_materialize_and_extract(self):
  for legacy in [False,True]:
   if legacy:
    for entry in self.manifest['archives'].values():entry.pop('parts')
    self.save()
   self.run_helper()
   stage=self.root/('legacy' if legacy else 'split');stage.mkdir()
   self.run_helper('archives',stage)
   for arch in ['amd64','arm64']:
    archive=stage/f'linux-{arch}.tar.gz'
    self.assertEqual(sha(archive.read_bytes()),self.manifest['archives'][arch]['sha256'])
    with tarfile.open(archive,'r:gz') as tar:tar.extractall(stage,filter='data')
   self.run_helper('extracted',stage)
 def test_corrupt_part_is_rejected_even_when_full_archive_exists(self):
  name=self.manifest['archives']['amd64']['parts'][0]['file'];p=self.root/'agent-dist'/name
  data=bytearray(p.read_bytes());data[-1]^=1;p.write_bytes(data)
  self.run_helper(ok=False)
 def test_missing_part_is_rejected(self):
  (self.root/'agent-dist'/self.manifest['archives']['arm64']['parts'][0]['file']).unlink()
  self.run_helper(ok=False)
 def test_part_path_and_sequence_are_strict(self):
  self.manifest['archives']['amd64']['parts'][0]['file']='../outside';self.save()
  self.run_helper(ok=False)
 def test_symlink_part_is_rejected(self):
  p=self.root/'agent-dist'/self.manifest['archives']['amd64']['parts'][0]['file'];p.unlink()
  p.symlink_to(self.root/'agent-dist/linux-amd64.tar.gz');self.run_helper(ok=False)
 def test_combined_checksum_is_verified(self):
  self.manifest['archives']['amd64']['sha256']='0'*64;self.save();self.run_helper(ok=False)
 def test_empty_parts_cannot_fall_back_to_an_archive(self):
  self.manifest['archives']['amd64']['parts']=[];self.save();self.run_helper(ok=False)
 def test_existing_staging_file_is_not_overwritten(self):
  stage=self.root/'existing';stage.mkdir();p=stage/'linux-amd64.tar.gz';p.write_text('keep')
  self.run_helper('archives',stage,ok=False);self.assertEqual(p.read_text(),'keep')
 def test_reordered_parts_are_rejected(self):
  entry=self.manifest['archives']['amd64'];data=(self.root/'agent-dist/linux-amd64.tar.gz').read_bytes();parts=[]
  for index,chunk in enumerate([data[:len(data)//2],data[len(data)//2:]]):
   name=f'linux-amd64.tar.gz.part-{index:03d}';(self.root/'agent-dist'/name).write_bytes(chunk)
   parts.append({'file':name,'bytes':len(chunk),'sha256':sha(chunk)})
  entry['parts']=parts;self.save();self.run_helper()
  entry['parts'].reverse();self.save();self.run_helper(ok=False)

if __name__=='__main__':unittest.main(verbosity=2)
