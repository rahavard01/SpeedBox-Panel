#!/usr/bin/env python3
"""Exercise the distributed ACME executable without contacting a certificate authority."""
import base64,gzip,hashlib,json,pathlib,subprocess,tempfile,unittest
ROOT=pathlib.Path(__file__).resolve().parents[2]
class CertificateBinary(unittest.TestCase):
    def test_cached_certificate_is_reused_without_provider_or_network(self):
        with tempfile.TemporaryDirectory() as temp:
            p=pathlib.Path(temp);manifest=json.loads((ROOT/'tunnel-cdn-dist/manifest.json').read_text());entry=manifest['archives']['amd64']
            raw=(ROOT/'tunnel-cdn-dist'/entry['file']).read_bytes();self.assertEqual(hashlib.sha256(raw).hexdigest(),entry['sha256']);binary=p/'certificate';binary.write_bytes(gzip.decompress(raw));self.assertEqual(hashlib.sha256(binary.read_bytes()).hexdigest(),entry['binary_sha256']);binary.chmod(0o700)
            cert,key=p/'source.crt',p/'source.key'
            subprocess.run(['openssl','req','-x509','-newkey','ec','-pkeyopt','ec_paramgen_curve:P-256','-nodes','-days','45','-subj','/CN=tunnel.example.com','-addext','subjectAltName=DNS:tunnel.example.com','-keyout',str(key),'-out',str(cert)],check=True,capture_output=True)
            state=p/'state';state.mkdir(mode=0o700)
            # Lego excludes the PEM fields from Resource JSON; both files must
            # be read alongside metadata before deciding to issue or renew.
            (state/'certificate.json').write_text(json.dumps({'domain':'tunnel.example.com','certUrl':'https://invalid.example/certificate'}))
            (state/'fullchain.pem').write_bytes(cert.read_bytes());(state/'privkey.pem').write_bytes(key.read_bytes())
            request={'domain':'tunnel.example.com','cert_mode':'dns','dns_provider':'arvancloud','dns_env':{},'state_dir':str(state)}
            result=subprocess.run([str(binary)],input=json.dumps(request),text=True,capture_output=True,timeout=5)
            self.assertEqual(result.returncode,0,result.stdout);reply=json.loads(result.stdout);self.assertTrue(reply['ok'])
            self.assertEqual(pathlib.Path(reply['data']['cert_file']).read_bytes(),cert.read_bytes());self.assertEqual(pathlib.Path(reply['data']['key_file']).read_bytes(),key.read_bytes());self.assertEqual(pathlib.Path(reply['data']['key_file']).stat().st_mode&0o777,0o600)
            self.assertFalse((state/'account.key').exists(),'cached certificate must not register another ACME account')
            request.update(domain='bad/domain',dns_env={'ARVANCLOUD_API_KEY':'fixture-secret-never-in-errors'})
            failed=subprocess.run([str(binary)],input=json.dumps(request),text=True,capture_output=True,timeout=5)
            self.assertNotEqual(failed.returncode,0);self.assertNotIn('fixture-secret',failed.stdout+failed.stderr);self.assertFalse(json.loads(failed.stdout)['ok'])
if __name__=='__main__':unittest.main()
