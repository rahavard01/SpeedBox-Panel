import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
const {chromium}=await import(process.env.SPEEDBOX_TEST_PLAYWRIGHT||'playwright');
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'../..');
const script=readFileSync(root+'/public/speedbox/node-editor.js','utf8');
const css=readFileSync(root+'/public/speedbox/infrastructure.css','utf8');
const browser=await chromium.launch({headless:true,executablePath:process.env.SPEEDBOX_TEST_CHROME||undefined,args:['--no-sandbox']});
let checks=0;
try {
 for(const width of [390,1280])for(const [status,progress,wanted] of [['running',75,75],['queued',0,0],['succeeded',100,100],['failed',100,99]]){
  const page=await browser.newPage({viewport:{width,height:950}});
  await page.setContent('<style>body{background:#17131e;color:white;font-family:sans-serif}*{box-sizing:border-box}</style><div class="speedbox-infrastructure-overlay"><section class="infrastructure-modal node-modal"><div id="body"></div></section></div>');
  await page.addStyleTag({content:css});await page.addScriptTag({content:script});
  await page.evaluate(({status,progress})=>{
   window.disposeEditor=window.SpeedboxNodeEditor.mount({
    node:{id:7,name:'Test',type:'vless',machine_id:1,kernel:'xray',host:'node.test',port:'443',server_port:443,warp_enabled:true},
    state:{nodes:[],groups:[],routes:[],machines:[{id:1,name:'Server'}]},
    api:async()=>({data:{supported:true,status:{installed:true,ready:status==='succeeded',account:'free'},operation:{id:8,status,action:'warp_prepare',progress,log:'در حال بررسی حساب WARP…'}}}),
    openModal:(title,body)=>document.getElementById('body').innerHTML=body,closeModal(){},toast(){},reload(){},enhanceControls(){},isSaving:()=>false,
   });
  },{status,progress});
  const bar=page.getByRole('progressbar');await bar.waitFor();
  assert.equal(await bar.getAttribute('aria-valuenow'),String(wanted));checks++;
  const box=await bar.boundingBox();assert(box.width>80&&box.x>=0&&box.x+box.width<=width);checks++;
  assert.equal(await page.locator('[data-warp-action="test"]').isDisabled(),['running','queued'].includes(status));checks++;
  assert.equal(await page.locator('.node-warp-progress').count(),1);checks++;
  if(status==='running')assert.match(await page.locator('.node-warp-progress-heading').innerText(),/۷۵/);
  await page.evaluate(()=>window.disposeEditor());await page.close();
 }
 console.log(`WARP progress browser: PASS (${checks} checks, mobile and desktop)`);
}finally{await browser.close();}
