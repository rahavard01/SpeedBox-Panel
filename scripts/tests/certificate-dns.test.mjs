import assert from 'node:assert/strict';
import {readFileSync,readdirSync} from 'node:fs';
import {fileURLToPath} from 'node:url';
import path from 'node:path';
const {JSDOM}=await import(process.env.SPEEDBOX_TEST_JSDOM||'jsdom');
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'../..');
const dom=new JSDOM('<form></form>',{runScripts:'outside-only'}),w=dom.window;
w.eval(readFileSync(path.join(root,'public/speedbox/certificate-dns.js'),'utf8'));
const api=w.SpeedboxCertificateDns,form=w.document.querySelector('form');
let count=0;const check=(ok,message)=>{assert.ok(ok,message);count++;};
function draw(c,context='node'){form.innerHTML=api.render(c,{context,prefix:'test'});api.bind(form,c,{context,onChange:()=>draw(c,context)});}
function type(key,value){const e=form.querySelector(`[data-dns-key="${key}"]`);assert.ok(e,'field exists: '+key);e.value=value;e.dispatchEvent(new w.Event('input',{bubbles:true}));}
function select(name){const e=form.querySelector('[data-dns-provider]');e.value=name;e.dispatchEvent(new w.Event('change',{bubbles:true}));}

const c={cert_mode:'dns',dns_provider:'cf',dns_env:{CF_API_TOKEN:'stored-node-token',EXISTING_UNRELATED:'keep'}};draw(c);
check(form.querySelector('[data-dns-key="CLOUDFLARE_DNS_API_TOKEN"]').value==='','stored node token never appears in the input');
check(!form.innerHTML.includes('stored-node-token'),'stored token not inserted into HTML');
check(form.querySelector('[data-dns-key]').placeholder==='stor********oken','node alias gets the masked saved-token preview');
check(!form.querySelector('.certificate-dns-saved'),'redundant saved-secret message removed');
check(!form.textContent.includes('KEY=VALUE')&&!form.textContent.includes('CLOUDFLARE_DNS_API_TOKEN'),'no raw environment syntax in visible copy');
api.capture(form,c,{context:'node'});check(c.dns_env.CF_API_TOKEN==='stored-node-token','blank input preserves the existing alias');
type('CLOUDFLARE_DNS_API_TOKEN','replacement');check(c.dns_env.CLOUDFLARE_DNS_API_TOKEN==='replacement'&&!('CF_API_TOKEN' in c.dns_env),'new value replaces aliases');
draw(c);check(form.querySelector('[data-dns-key]').value==='replacement','redraw retains a newly typed token');
type('CLOUDFLARE_DNS_API_TOKEN','');check(c.dns_env.CF_API_TOKEN==='stored-node-token','clearing an unsaved edit restores the stored token');
check(c.dns_env.EXISTING_UNRELATED==='keep','unrelated saved settings are preserved');
select('alidns');check(!('CF_API_TOKEN' in c.dns_env),'changing providers does not send the previous provider token');
type('ALICLOUD_ACCESS_KEY_ID','id');type('ALICLOUD_ACCESS_KEY_SECRET','secret');select('cloudflare');check(c.dns_env.CF_API_TOKEN==='stored-node-token','returning to the original provider restores its credentials');
select('alidns');check(c.dns_env.ALICLOUD_ACCESS_KEY_SECRET==='secret','provider draft survives switching');
check(!('has_saved_secret' in c),'node data does not acquire tunnel-only metadata');

const sealed={cert_mode:'dns',dns_provider:'arvancloud',dns_env:{},has_saved_secret:true,saved_secret_mode:'dns',saved_dns_fields:['ARVANCLOUD_API_KEY'],saved_dns_previews:{ARVANCLOUD_API_KEY:'abcd********wxyz'}};draw(sealed,'tunnel');
check(!form.querySelector('[data-dns-key]').required,'encrypted saved tunnel secret can stay blank');
check(!form.textContent.includes('اطلاعات دسترسی ثبت شده است'),'saved-secret sentence removed');
check(form.querySelector('[data-dns-key]').placeholder==='abcd********wxyz','saved Arvan key has a masked preview');
api.capture(form,sealed,{context:'tunnel'});check(Object.keys(sealed.dns_env).length===0,'masked preview is never submitted as a key');
type('ARVANCLOUD_API_KEY','abcd********wxyz');check(!form.querySelector('[data-dns-key]').checkValidity()&&Object.keys(sealed.dns_env).length===0,'pasting a masked preview is invalid and cannot replace the real key');
type('ARVANCLOUD_API_KEY','replacement-complete-key');check(form.querySelector('[data-dns-key]').checkValidity()&&sealed.dns_env.ARVANCLOUD_API_KEY==='replacement-complete-key','complete replacement accepted after a masked paste');
type('ARVANCLOUD_API_KEY','');draw(sealed,'tunnel');check(form.querySelector('[data-dns-key]').placeholder==='abcd********wxyz'&&Object.keys(sealed.dns_env).length===0,'clearing a draft restores the saved preview and preserves the secret');
check(form.querySelector('[data-dns-key]').parentElement.contains(form.querySelector('.certificate-dns-hint')),'purpose hint belongs to the credential field');
select('cloudflare');check(form.querySelector('[data-dns-key="CLOUDFLARE_DNS_API_TOKEN"]').required,'different provider requires its own token');
check(!form.querySelector('[data-dns-key="CLOUDFLARE_ZONE_API_TOKEN"]'),'unused optional zone credential does not clutter the form');
type('CLOUDFLARE_DNS_API_TOKEN','unsaved-cf-token');select('arvancloud');check(!form.querySelector('[data-dns-key]').required,'returning restores the saved encrypted-secret flag');
select('cloudflare');check(form.querySelector('[data-dns-key="CLOUDFLARE_DNS_API_TOKEN"]').value==='unsaved-cf-token','new tunnel provider draft survives switching back');
select('arvancloud');check(form.querySelector('[data-dns-key]').placeholder==='abcd********wxyz','returning to a provider restores its own masked preview');
select('cloudflare');check(form.querySelector('[data-dns-key]').placeholder==='CLOUDFLARE_DNS_API_TOKEN','new provider does not reuse another provider preview');
for(const token of ['x','12345678','123456789012']){draw({cert_mode:'dns',dns_provider:'cf',dns_env:{CF_API_TOKEN:token}});check(form.querySelector('[data-dns-key]').placeholder==='********','short keys never reveal the full token');}
draw({cert_mode:'dns',dns_provider:'cf',dns_env:{}});check(form.querySelector('[data-dns-key]').placeholder==='CLOUDFLARE_DNS_API_TOKEN'&&form.querySelector('[data-dns-key]').required,'missing key keeps the original technical hint and remains required');

const registry=path.join(root,'agent/internal/cert/dnsproviders');
for(const f of readdirSync(registry).filter(f=>f.endsWith('.go')&&!f.endsWith('_test.go'))){
 const source=readFileSync(path.join(registry,f),'utf8');const names=source.match(/Names:\s*\[\]string\{([^}]+)\}/);if(!names)continue;
 const canonical=names[1].match(/"([^"]+)"/)[1],p=api.providers('node').find(p=>p.name===canonical);check(!!p,'provider remains available: '+canonical);
 const consumed=[...source.matchAll(/firstOf\(env,\s*([^\)]+)\)/g)].flatMap(m=>[...m[1].matchAll(/"([^"]+)"/g)].map(v=>v[1]));
 const known=p.fields.flatMap(f=>[f.key,...f.aliases]);check(consumed.every(k=>known.includes(k)),'all accepted credential keys preserved: '+canonical);
 draw({cert_mode:'dns',dns_provider:canonical,dns_env:{}});check(form.querySelectorAll('[data-dns-key]').length===p.fields.length,'dedicated fields rendered: '+canonical);
 check([...form.querySelectorAll('[data-dns-key]')].every(input=>input.placeholder===input.dataset.dnsKey),'technical credential placeholders preserved: '+canonical);
}
console.log(`PASS: ${count} shared certificate DNS checks`);
dom.window.close();
