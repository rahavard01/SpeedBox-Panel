// Production assets in a real browser with an isolated, mocked API.
// Optional SPEEDBOX_TEST_PLAYWRIGHT and SPEEDBOX_CHROMIUM_EXECUTABLE.
import { createServer } from 'node:http';
import { readFileSync, existsSync, mkdirSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import assert from 'node:assert/strict';
const { chromium } = await import(process.env.SPEEDBOX_TEST_PLAYWRIGHT || 'playwright');
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const read = file => readFileSync(path.join(root, file), 'utf8');
const manifest = JSON.parse(read('public/build/manifest.json'));
const entry = manifest['resources/js/app.js'];
const template = read('resources/views/infrastructure.blade.php').match(/<template[^>]*>[\s\S]*<\/template>/)[0];
const scripts = ['node-editor', 'emergency-configs', 'infrastructure', 'register-infrastructure', 'panel-tooltip', 'partner-statistics'];
const html = '<!doctype html><html lang="fa"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
  + entry.css.map(file => `<link rel="stylesheet" href="/build/${file}">`).join('')
  + ['panel-controls', 'bot-faqs', 'branding', 'infrastructure', 'emergency-configs', 'partner-statistics'].map(name => `<link rel="stylesheet" href="/speedbox/${name}.css">`).join('')
  + '</head><body><div id="app"></div>' + template + '<script id="__BOOTSTRAP__" type="application/json">{}</script>'
  + scripts.map(name => `<script src="/speedbox/${name}.js"></script>`).join('') + `<script type="module" src="/build/${entry.file}"></script></body></html>`;
const future = Math.floor(Date.now() / 1000) + 864000;
const accounts = Array.from({length:45}, (_,index) => ({id:index+1,email:`sample${index+1}@fixture.com`,plan_id:1,account_tariff_group_id:20,account_plan_name:'Sale B',account_group_type:'time',transfer_enable:60*1073741824,u:0,d:0,expired_at:future,banned:0,device_limit:2,created_at:index+1,online_count:0}));
Object.assign(accounts[0], {email:'alpha@fixture.com',account_tariff_group_id:10,account_plan_name:'Sale A'});
Object.assign(accounts[1], {email:'beta@fixture.com',plan_id:2,expired_at:1});
Object.assign(accounts[2], {email:'gamma@fixture.com',plan_id:2,account_tariff_group_id:10,account_plan_name:'Sale A'});
const salesPlans = [{id:10,name:'Sale A'},{id:20,name:'Sale B'}];
const mainPlans = [{id:1,name:'Main A'},{id:2,name:'Main B'}];
const serverRows = [{id:1,name:'Review Server',host:'192.0.2.1',ssh_user:'root',ssh_port:19022,status:'online',connection_status:'online',deployments:[],machine:{nodes_count:2,last_seen_at:future-864000,load_status:{cpu:12,mem:{total:4096,used:1024},disk:{total:40000,used:12000}}}}];
const nodeRows = [{id:1,name:'Review Node',host:'192.0.2.2',type:'vless',kernel:'xray',port:443,show:true,enabled:true,machine_id:1,machine:{name:'Review Server'},runtime:{online:true,online_users:2}}];
const planRows = [{id:1,name:'Review Plan',group_id:1,transfer_enable:60,speed_limit:10,device_limit:2,reset_traffic_method:2,users_count:3,active_users_count:2}];
const writes = [];
const server = createServer(async(req,res) => {
  const url = new URL(req.url, 'http://fixture.test');
  if (url.pathname.startsWith('/api/')) {
    let raw=''; for await (const chunk of req) raw+=chunk;
    const payload=raw?JSON.parse(raw):{};
    if(req.method!=='GET')writes.push({path:url.pathname,method:req.method,payload});
    const admin=String(req.headers.authorization).includes('admin');
    let data={data:[],count:0,status:'success'};
    const p=url.pathname.slice(4);
    if(p==='/user/panel')data={status:'success',user:{id:9,role:admin?1:2,name:'Review',email:'review@fixture.test',code:'fixture',credit:1000000}};
    else if(p==='/admin/users')data={data:accounts,meta:{filters:{sales_plans:salesPlans,main_plans:mainPlans}}};
    else if(p==='/users')data=accounts;
    else if(p==='/users/by-token')data={data:[{id:2,email:'beta@fixture.com',expired_at:1}]};
    else if(p==='/settings/link-parts')data={data:{part1:'https://fixture.test',part2:'s',part3:''}};
    else if(p.endsWith('/online'))data={data:(payload.ids||[]).map(id=>({id,online_count:0,device_limit:2}))};
    else if(p==='/admin/infrastructure/servers')data={data:serverRows};
    else if(p==='/admin/infrastructure/nodes')data={data:nodeRows,meta:{kernel_capabilities:{protocols:{vless:['xray','singbox']}}}};
    else if(p==='/admin/infrastructure/plans')data={data:planRows};
    else if(p==='/admin/infrastructure/groups')data={data:[{id:1,name:'Access A'}]};
    else if(p==='/admin/infrastructure/machines')data={data:[{id:1,name:'Review Server'}]};
    else if(p.endsWith('/nodes/1/flags'))data={message:'Updated'};
    res.writeHead(200,{'Content-Type':'application/json'});res.end(JSON.stringify(data));return;
  }
  const file=path.resolve(root,'public','.'+decodeURIComponent(url.pathname));
  if(file.startsWith(path.join(root,'public')+path.sep)&&existsSync(file)&&path.extname(file)){
    const type={'.js':'text/javascript','.css':'text/css','.woff2':'font/woff2','.woff':'font/woff'}[path.extname(file)]||'application/octet-stream';
    res.writeHead(200,{'Content-Type':type});res.end(readFileSync(file));return;
  }
  if(path.extname(url.pathname)){res.writeHead(404);res.end();return;}
  res.writeHead(200,{'Content-Type':'text/html; charset=utf-8'});res.end(html);
});
await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
const origin=`http://127.0.0.1:${server.address().port}`;
const executablePath=process.env.SPEEDBOX_CHROMIUM_EXECUTABLE || process.env.SPEEDBOX_TEST_CHROME;
const browser=await chromium.launch({headless:true,...(executablePath?{executablePath}:{}),args:['--no-sandbox','--disable-dev-shm-usage','--disable-gpu']});
let checks=0;
const check=(value,message)=>{assert.ok(value,message);checks++;};
const errors=[];
let lastPage;
const screenshot=async(page,name)=>{
  if(!process.env.SPEEDBOX_QA_ARTIFACT_DIR)return;
  mkdirSync(process.env.SPEEDBOX_QA_ARTIFACT_DIR,{recursive:true});
  await page.screenshot({path:path.join(process.env.SPEEDBOX_QA_ARTIFACT_DIR,name+'.png'),fullPage:true});
};
try {
  for(const width of [1280,390]){
    const context=await browser.newContext({viewport:{width,height:900}});
    await context.addInitScript(()=>{localStorage.setItem('token','fixture-admin');localStorage.setItem('user','{"id":9,"role":1,"code":"fixture"}');localStorage.setItem('panel_seen_version','3.0');});
    const page=await context.newPage();lastPage=page;page.on('pageerror',error=>errors.push(error.message));
    page.setDefaultTimeout(10000);
    await page.goto(origin+'/admin/users');await page.locator('.users-table tbody tr').filter({hasText:'sample45'}).waitFor({state:'attached'});
    await page.locator('.pagination-wrapper button').nth(3).click();
    await page.locator('.users-table tbody tr').filter({hasText:'alpha'}).waitFor({state:'attached'});
    const initialTableTop=await page.locator('.table-toolbar').evaluate(el=>el.getBoundingClientRect().top+scrollY);
    await page.locator('.user-list-filter-trigger').click();
    const dialog=page.getByRole('dialog',{name:'فیلتر کاربران'});await dialog.waitFor();
    check(await dialog.locator('label').allTextContents().then(v=>v.join('|')==='طرح فروش|پلن اصلی'),'Exact filter names');
    check(Math.abs((await page.locator('.table-toolbar').evaluate(el=>el.getBoundingClientRect().top+scrollY))-initialTableTop)<1,'Opening filters does not shift the table');
    await dialog.locator('#user-sales-plan-filter').selectOption('10');
    await dialog.locator('#user-main-plan-filter').selectOption('2');
    await screenshot(page,'user-filters-'+width);
    await dialog.getByRole('button',{name:'اعمال',exact:true}).click();
    await page.locator('.users-table tbody tr').filter({hasText:'gamma'}).waitFor({state:'attached'});
    check(await page.locator('.users-table tbody tr').count()===1,'Sale and main-plan filters combine with AND');
    check((await page.locator('.pagination-wrapper').textContent()).includes('صفحه 1 از 1'),'Filtering resets pagination');
    check(await page.locator('.user-list-filter-count').textContent()==='2','Active filters are visible on the button');
    await page.locator('#userSearch').fill('alpha');
    await page.getByText('موردی یافت نشد',{exact:true}).first().waitFor({state:'attached'});
    await page.locator('#userSearch').fill('gamma');
    await page.locator('.users-table tbody tr').filter({hasText:'gamma'}).waitFor({state:'attached'});
    check(await page.locator('.users-table tbody tr').count()===1,'Text search composes with filters');
    await page.locator('.user-list-filter-trigger').click();
    await dialog.locator('#user-sales-plan-filter').selectOption('20');
    await page.keyboard.press('Escape');
    check(await dialog.count()===0,'Escape closes the modal');
    check(await page.locator('.user-list-filter-trigger').evaluate(el=>el===document.activeElement),'Focus returns to the filter button');
    check((await page.locator('.users-table tbody').textContent()).includes('gamma'),'Closing without apply preserves filters');
    await page.locator('#userSearch').fill('');
    await page.locator('.user-list-filter-trigger').click();
    await dialog.getByRole('button',{name:'پاک‌کردن',exact:true}).click();
    await page.locator('.users-table tbody tr').filter({hasText:'sample45'}).waitFor({state:'attached'});
    check(await page.locator('.user-list-filter-count').count()===0,'Clear removes both filters');
    await page.locator('.user-list-filter-trigger').click();
    await dialog.locator('#user-main-plan-filter').selectOption('2');
    await dialog.getByRole('button',{name:'اعمال',exact:true}).click();
    await page.locator('#userSearch').fill('1234567890abcdef1234567890abcdef');
    await page.locator('.users-table tbody tr').filter({hasText:'beta'}).waitFor({state:'attached'});
    check(await page.locator('.users-table tbody tr').count()===1,'Token search uses the listed account plan metadata');
    await page.getByRole('button',{name:'منقضی',exact:true}).click();
    check((await page.locator('.users-table tbody').textContent()).includes('beta'),'Status filter also composes with token and plan');
    for(const [view,name] of [['servers','Review Server'],['nodes','Review Node'],['plans','Review Plan']]){
      await page.goto(origin+'/admin/infrastructure/'+view);
      const item=page.locator(width<768?'.entity-mobile-card':'.entity-list-shell tbody tr').filter({hasText:name});
      await item.waitFor();
      check(await item.count()===1,view+' contains a single interactive rendering');
      if(width<768){
        check(await page.locator('.entity-list-shell table').count()===0,view+' mobile cards do not depend on table elements');
        check(await item.locator('.entity-card-label').count()>0,view+' has real visible field labels');
        const fields=await item.locator('.entity-card-field').evaluateAll(nodes=>nodes.map(el=>({text:el.textContent.trim(),height:el.getBoundingClientRect().height,visible:getComputedStyle(el).visibility,display:getComputedStyle(el).display})));
        check(fields.every(field=>field.text&&field.height>0&&field.visible==='visible'&&field.display!=='none'),view+' information fields render');
        check((await item.boundingBox()).height<850,view+' height follows content without the blank screen');
        await screenshot(page,'mobile-'+view);
      }else check(await page.locator('.entity-card-list').count()===0,view+' desktop keeps its table');
      if(view==='nodes'){
        await item.locator('[data-node-show]').uncheck();
        await page.waitForTimeout(100);
        check(writes.some(w=>w.path.endsWith('/nodes/1/flags')&&w.payload.show===false),'Mobile/desktop node switch keeps its API action');
      }
      if(view==='servers'){
        await item.locator('[data-server-edit]').click();
        await page.locator('#serverForm').waitFor();
        check(await page.locator('#serverForm input[name="name"]').inputValue()===name,'Server edit retains the selected record');
        await page.keyboard.press('Escape');
      }
    }
    await page.setViewportSize({width:width<768?1280:390,height:900});
    await page.locator(width<768?'.entity-list-shell table':'.entity-card-list').waitFor();
    check(true,'Crossing the breakpoint rebuilds the current view');
    await context.close();
  }
  const partner=await browser.newContext();
  await partner.addInitScript(()=>{localStorage.setItem('token','fixture-partner');localStorage.setItem('user','{"id":8,"role":2,"code":"fixture"}');localStorage.setItem('panel_seen_version','3.0');});
  const page=await partner.newPage();await page.goto(origin+'/panel/users');await page.locator('.users-table').waitFor({state:'attached'});
  check(await page.locator('.user-list-filter-trigger').count()===0,'The new filters are admin-only');
  await partner.close();
  check(errors.length===0,'No browser runtime errors: '+errors.join('; '));
  console.log(`PASS: ${checks} production-browser filter and mobile-card checks.`);
} catch(error) {
  console.error(JSON.stringify({errors,url:lastPage?.url(),text:await lastPage?.locator('body').textContent().catch(()=>'' )}));
  if(lastPage)await screenshot(lastPage,'failure');
  throw error;
} finally {await browser.close();await new Promise(resolve=>server.close(resolve));}
