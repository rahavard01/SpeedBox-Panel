import assert from 'node:assert/strict';
import {readFileSync,existsSync} from 'node:fs';
import {spawnSync} from 'node:child_process';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
const {JSDOM}=await import(process.env.SPEEDBOX_TEST_JSDOM||'jsdom');
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'../..');
const template=readFileSync(path.join(root,'resources/views/client/subscribe.blade.php'),'utf8');
const publicEdition=existsSync(path.join(root,'.speedbox-public-edition.json'));
const restricted=['تماشای یوتیوب بدون تبلیغات','باز کردن سایت‌های نتفلیکس و اسپاتیفای'];
const common=['انتخاب خودکار بهترین سرور','باز کردن سایت‌های ایرانی بدون نیاز به خاموش کردن وی پی ان','به اشتراک گذاری وی پی ان با دیگر دستگاهها'];
let checks=0;const check=(ok,message)=>{assert.ok(ok,message);checks++;};
function inspect(html,isPublic){
 const dom=new JSDOM(html,{runScripts:'outside-only',url:'https://panel.example/'}),w=dom.window;
 w.matchMedia=()=>({matches:true});
 const script=html.match(/<script>([\s\S]*?)<\/script>/)[1].replace('@json($subscription_url)',JSON.stringify('https://sub.example/s/test'));
 w.eval(script+'\nwindow.__tutorials=tutorials;');
 const data=JSON.parse(JSON.stringify(w.__tutorials));
 check([...w.document.querySelectorAll('.platform-app')].map(e=>e.textContent).join(',')==='Streisand,SPEEDBOX,SPEEDBOX','platform cards keep their original app names');
 for(const platform of ['ios','android','windows']){
  w.openTutorial(platform);
  check(w.document.querySelector('#tutorialModal').getAttribute('aria-hidden')==='false','tutorial opens: '+platform);
  check(w.document.querySelector('#tutorialModalTitle').textContent===data[platform].title,'original title: '+platform);
  check(w.document.querySelectorAll('#modalSteps .guide-step').length===data[platform].steps.length,'all connection steps rendered: '+platform);
  if(platform==='ios'){
   check(data.ios.app==='Streisand'&&data.ios.steps.length===7,'complete iPhone tutorial is preserved');
   check(w.document.querySelector('#speedboxFeaturesBlock').style.display==='none','iPhone does not inherit app-specific features');
  }else{
   check(data[platform].app.includes('SPEEDBOX'),'SpeedBox remains the suggested app');
   const link=w.document.querySelector('#modalSteps a');
   check(link?.href==='https://io.subupdatefast.info/Download/speedbox/SPEEDBOX.'+(platform==='android'?'apk':'exe'),'original download link retained: '+platform);
   check(link.target==='_blank'&&link.rel.includes('noopener'),'download link keeps original browser behavior');
   const features=[...w.document.querySelectorAll('#speedboxFeatures li')].map(e=>e.textContent);
   check(common.every(feature=>features.includes(feature)),'common app capabilities include Iranian sites');
   check(features.length===(isPublic?3:5),'expected edition-specific feature count');
   check(restricted.every(feature=>features.includes(feature)===!isPublic),'subscription-specific promises are edition-specific');
  }
  w.closeTutorial();check(w.document.querySelector('#tutorialModal').getAttribute('aria-hidden')==='true','tutorial closes: '+platform);
 }
 dom.window.close();return data;
}
const original=inspect(template,publicEdition);
if(!publicEdition){
 const result=spawnSync('python3',['-c','import runpy,sys; print(runpy.run_path(sys.argv[1])["public_subscription"](sys.stdin.read()),end="")',path.join(root,'scripts/public-release/export.py')],{input:template,encoding:'utf8'});
 assert.equal(result.status,0,result.stderr);
 const output=inspect(result.stdout,true);
 check(JSON.stringify(output.ios)===JSON.stringify(original.ios),'public iPhone guide exactly matches private');
 for(const platform of ['android','windows']){
  const expected={...original[platform],features:original[platform].features.filter(feature=>!restricted.includes(feature))};
  check(JSON.stringify(output[platform])===JSON.stringify(expected),'only the approved feature exclusions differ: '+platform);
 }
}
console.log(`PASS: ${checks} subscription guide checks (${publicEdition?'public':'private and exported public'})`);
