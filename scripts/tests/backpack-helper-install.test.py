import hashlib,importlib.util,io,json,os,tarfile,tempfile,unittest,zipfile
from pathlib import Path

source=Path(__file__).resolve().parents[1]/'backpack/helper-install.py'
spec=importlib.util.spec_from_file_location('helper_install',source)
helper=importlib.util.module_from_spec(spec);spec.loader.exec_module(helper)

class HelperInstallTests(unittest.TestCase):
    def test_verified_install_reuse_and_tamper_repair(self):
        for form in ['zip','tar']:
            with self.subTest(form=form),tempfile.TemporaryDirectory(prefix='speedbox-managed.') as folder:
                root=Path(folder);helper.ROOT=root/'opt/helpers';archive=root/'helper.archive'
                payload=b'\x7fELF'+bytes([2,1])+bytes(12)+(62).to_bytes(2,'little')+bytes(180)
                if form=='zip':
                    with zipfile.ZipFile(archive,'w') as ar:ar.writestr('xray',payload)
                else:
                    with tarfile.open(archive,'w:gz') as ar:
                        entry=tarfile.TarInfo('release/xray');entry.size=len(payload);ar.addfile(entry,io.BytesIO(payload))
                expected={'tool':'xray','version':'v26.3.27','arch':'amd64','format':form,'sha256':hashlib.sha256(archive.read_bytes()).hexdigest()}
                request={'action':'install','archive':str(archive),'expected':expected}
                self.assertFalse(helper.execute({**request,'action':'status'})['current'])
                self.assertTrue(helper.execute(request)['current'])
                binary=helper.ROOT/'xray/v26.3.27-amd64/xray'
                self.assertEqual(binary.read_bytes(),payload)
                binary.write_bytes(b'tampered')
                self.assertFalse(helper.execute({**request,'action':'status'})['current'])
                self.assertTrue(helper.execute(request)['current'])
                self.assertEqual(binary.read_bytes(),payload)
                archive.write_bytes(b'corrupted')
                binary.write_bytes(b'tampered')
                with self.assertRaisesRegex(ValueError,'checksum'):helper.execute(request)
                self.assertEqual(binary.read_bytes(),b'tampered')

    def test_helper_identity_cannot_escape_its_directory(self):
        with self.assertRaisesRegex(ValueError,'identity'):
            helper.execute({'expected':{'tool':'../xray','version':'v1','arch':'amd64','sha256':'a'*64}})

if __name__=='__main__':unittest.main()
