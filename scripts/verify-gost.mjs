import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import zlib from 'node:zlib';
import {fileURLToPath} from 'node:url';
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const sha=b=>crypto.createHash('sha256').update(b).digest('hex');
const manifest=JSON.parse(fs.readFileSync(path.join(root,'gost-dist/manifest.json')));
let count=0;
for(const version of ['2.11.5','3.0.0'])for(const arch of ['amd64','arm64']){
 const e=manifest.artifacts[version][arch];
 if(!/^gost-(2\.11\.5|3\.0\.0)-linux-(amd64|arm64)\.gz$/.test(e.file))throw Error('invalid artifact path');
 const bytes=fs.readFileSync(path.join(root,'gost-dist',e.file));if(sha(bytes)!==e.sha256)throw Error('archive hash mismatch: '+e.file);
 const binary=zlib.gunzipSync(bytes);if(sha(binary)!==e.binary_sha256)throw Error('binary hash mismatch: '+e.file);
 if(binary.subarray(0,4).toString('hex')!=='7f454c46'||binary[4]!==2||binary.readUInt16LE(18)!==(arch==='amd64'?62:183))throw Error('ELF architecture mismatch: '+e.file);
 count++;
}
console.log('GOST ARTIFACTS OK: '+count+' pinned Linux binaries, amd64 and arm64');
