// Real shared tunnel menus: queued scrolling must not undo the user's click.
import assert from 'node:assert/strict';
import {createServer} from 'node:http';
import {readFileSync,existsSync} from 'node:fs';
import {fileURLToPath} from 'node:url';
import path from 'node:path';
const {chromium}=await import(process.env.SPEEDBOX_TEST_PLAYWRIGHT||'playwright');
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'../..');
const read=name=>readFileSync(path.join(root,name),'utf8');
const manifest=JSON.parse(read('public/build/manifest.json'));
const template=read('resources/views/infrastructure.blade.php').match(/<template[^>]*>([\s\S]*)<\/template>/)[1];
const css=[...(manifest['resources/js/app.js'].css||[]).map(file=>'/build/'+file),...['panel-controls','branding','infrastructure','tunnels','network-tunnels'].map(name=>'/speedbox/'+name+'.css')];
const scripts=['tunnels','network-tunnels','tunnel-cdn','certificate-dns','node-editor','infrastructure'];
const html=`<!doctype html><html dir="rtl"><meta charset="utf-8">${css.map(url=>`<link rel="stylesheet" href="${url}">`).join('')}<style>body{margin:0;background:#17171c;color:white}.center-box{margin-top:30px}</style><div class="center-box"><div id="mount" class="speedbox-infrastructure">${template}</div></div>${scripts.map(name=>`<script src="/speedbox/${name}.js"></script>`).join('')}</html>`;
const server=createServer((req,res)=>{
 const url=new URL(req.url,'http://localhost');
 if(url.pathname==='/'){res.setHeader('Content-Type','text/html');res.end(html);return;}
 const file=path.resolve(root,'public','.'+url.pathname);
 if(!file.startsWith(path.join(root,'public')+path.sep)||!existsSync(file)){res.writeHead(404).end();return;}
 res.setHeader('Content-Type',file.endsWith('.js')?'text/javascript':file.endsWith('.css')?'text/css':'application/octet-stream');res.end(readFileSync(file));
});
await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
const browser=await chromium.launch({headless:true,executablePath:process.env.SPEEDBOX_TEST_CHROME||undefined,args:['--no-sandbox','--disable-dev-shm-usage']});
try{
 const page=await browser.newPage({viewport:{width:1280,height:900},hasTouch:false});
 await page.goto(`http://127.0.0.1:${server.address().port}/?tunnel_preview=full`);
 await page.evaluate(()=>{
  localStorage.setItem('token','fixture');localStorage.setItem('user','{"role":1}');
  window.fetch=()=>{throw Error('Unexpected external API request');};
  window.fixture=SpeedboxInfrastructure.mount(document.getElementById('mount'),{view:'tunnels',router:{push(){},replace(){}},notify:{success(){},error(message){throw Error(message);},notif(){}}});
 });
 const first=page.locator('[data-tunnel-card="605"] .tunnel-actions>summary');
 const second=page.locator('[data-tunnel-card="606"] .tunnel-actions>summary');
 await first.click();assert.equal(await page.locator('.tunnel-actions[open]').count(),1);
 await second.click();assert.equal(await page.locator('.tunnel-actions[open]').count(),1);
 await page.evaluate(()=>window.dispatchEvent(new Event('scroll')));
 assert.equal(await page.locator('[data-tunnel-card="606"] .tunnel-actions[open]').count(),1,'a queued event with unchanged anchor must retain the menu');
 await page.locator('.tunnel-preview-banner p').click();assert.equal(await page.locator('.tunnel-actions[open]').count(),0,'outside click closes');
 await first.click();await page.keyboard.press('Escape');assert.equal(await page.locator('.tunnel-actions[open]').count(),0,'Escape closes');
 await second.click();
 const moved=await page.evaluate(()=>{document.body.style.minHeight='3000px';const before=scrollY;scrollBy(0,before>20?-20:20);return scrollY!==before;});
 assert.ok(moved,'the fixture must actually move the anchor');
 await page.waitForFunction(()=>document.querySelectorAll('.tunnel-actions[open]').length===0);
 await page.evaluate(()=>fixture.dispose());
 console.log('PASS: one shared menu, queued-scroll retention, outside/Escape closure and actual-scroll closure');
}finally{await browser.close();server.closeAllConnections();await new Promise(resolve=>server.close(resolve));}
