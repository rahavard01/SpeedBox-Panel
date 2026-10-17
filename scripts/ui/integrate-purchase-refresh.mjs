import { readFileSync, writeFileSync, readdirSync, unlinkSync } from 'node:fs';
import { createHash } from 'node:crypto';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

export function integratePurchaseRefresh(root, manifest) {
  const directory = path.join(root, 'public/build/assets');
  const mainEntry = manifest['resources/js/app.js'];
  const usersEntry = manifest['resources/js/pages/admin/Users.vue'];
  const factory = readFileSync(path.join(root, 'scripts/ui/account-purchase-refresh.mjs'), 'utf8').replace('export async function ', 'async function ');
  let main = readFileSync(path.join(root, 'public/build', mainEntry.file), 'utf8');
  let users = readFileSync(path.join(root, 'public/build', usersEntry.file), 'utf8');
  const replaceOnce = (source, old, next) => {
    if (source.split(old).length !== 2) throw new Error('Purchase refresh anchor changed: ' + old);
    return source.replace(old, next);
  };
  const embed = source => {
    const marker = '/*speedbox-purchase-refresh*/', end = '/*speedbox-purchase-refresh:end*/';
    if (!source.includes(marker)) return source + '\n' + marker + '\n' + factory + '\n' + end;
    const a = source.indexOf(marker), z = source.indexOf(end, a);
    if (z < 0) throw new Error('Purchase refresh factory end missing');
    return source.slice(0, a) + marker + '\n' + factory + '\n' + end + source.slice(z + end.length);
  };
  {
    if (!main.includes('y1.id=Number(v.id),y1.email=')) main = replaceOnce(main, 'y1.email=v.email,y1.expired_at=', 'y1.id=Number(v.id),y1.email=v.email,y1.expired_at=');
    const a = main.includes('/*speedbox-panel-purchase-handlers*/') ? main.indexOf('/*speedbox-panel-purchase-handlers*/') : main.indexOf('async function ga(){if(Y2.value)return;'), z = main.indexOf('function zp(v)', a);
    const c = main.indexOf('async function iM(){', z), d = main.indexOf('x1([S2,xe]', c);
    if ([a,z,c,d].some(value => value < 0)) throw new Error('Representative purchase handlers changed');
    const refresh = `/*speedbox-panel-purchase-handlers*/
    const speedboxCurrentPurchase=target=>xe.value&&Number(y1.id)===Number(target.id);
    async function speedboxRefreshPanelPurchase(target){
      return speedboxRefreshPurchasedAccount({http:w0,target,lists:()=>[bt.value,Xo.value],modal:y1,isCurrent:()=>speedboxCurrentPurchase(target),afterModal:async(fresh,current)=>{
        So.value=!!fresh.banned;
        if(M2.value.some(group=>Number(group.id)===Number(fresh.account_tariff_group_id))){b2.value=Number(fresh.account_tariff_group_id);Po()}
        const link=bp(fresh.token);y1.inviteLink=link;y1.qrDataUrl="";
        const qr=link?await Ab(link):"";if(current())y1.qrDataUrl=qr;
      }});
    }
    async function ga(){
      if(Y2.value)return;if(!Ee.value){i1("لطفاً پلن تمدید اکانت را انتخاب کنید");return}
      const v=Ee.value,target={id:y1.id,email:y1.email};Y2.value=true;
      try{
        await w0.post("/users/extend",{email:target.email,period:v,tariff_group_id:b2.value});
        await speedboxRefreshPanelPurchase(target);
        N1(\`اکانت با موفقیت \${Ra(v)} تمدید شد\`);
      }catch(error){i1(error?.response?.data?.message||"تمدید انجام نشد")}
      finally{Y2.value=false;if(speedboxCurrentPurchase(target)){Vn.value="";E2.value=false;eo.value="result";Ee.value=""}}
    }`;
    const topup = `async function iM(){
      if(aM.value)return;const v=Math.floor(Number(m2.value)||0);
      if(v<1){i1("مقدار ترافیک/گیگ را وارد کنید");return}if(!y1?.email){i1("ایمیل کاربر نامشخص است");return}if(lb.value){i1(Ob(rt.value));return}
      const target={id:y1.id,email:y1.email};aM.value=true;
      try{
        await w0.post("/users/topup",{email:target.email,gb:v,tariff_group_id:b2.value});
        await speedboxRefreshPanelPurchase(target);
        N1(\`\${v} گیگ ترافیک با موفقیت به اکانت اضافه شد\`);
      }catch(error){i1(error?.response?.data?.message||"خرید ترافیک انجام نشد")}
      finally{aM.value=false;if(speedboxCurrentPurchase(target)){m2.value=1;Vn.value="";j2.value=false;eo.value="result"}}
    }`;
    main = main.slice(0,a) + refresh + main.slice(z,c) + topup + main.slice(d);
  }
  {
    if (!users.includes('b.id=Number(s.id),b.email=')) users = replaceOnce(users, 'b.email=s.email,b.expired_at=', 'b.id=Number(s.id),b.email=s.email,b.expired_at=');
    const a = users.includes('/*speedbox-admin-purchase-handlers*/') ? users.indexOf('/*speedbox-admin-purchase-handlers*/') : users.indexOf('async function es(){if(Y.value)return;'), z = users.indexOf('function sa(s)', a);
    const c = users.indexOf('async function ts(){', z), d = users.indexOf('return te([O,G]', c);
    if ([a,z,c,d].some(value => value < 0)) throw new Error('Admin purchase handlers changed');
    const refresh = `/*speedbox-admin-purchase-handlers*/
    const speedboxCurrentPurchase=target=>G.value&&Number(b.id)===Number(target.id);
    async function speedboxRefreshAdminPurchase(target){
      return speedboxRefreshPurchasedAccount({http:B,target,lists:()=>[Ze.value,xe.value],modal:b,isCurrent:()=>speedboxCurrentPurchase(target),afterModal:async(fresh,current)=>{
        ke.value=!!fresh.banned;J.value=Number(fresh.account_is_volume)===1;
        if(K.value.some(group=>Number(group.id)===Number(fresh.account_tariff_group_id))){$.value=Number(fresh.account_tariff_group_id);N.value=J.value?"volume":"time";ze()}
        const link=Kt(fresh.token);b.inviteLink=link;b.qrDataUrl="";
        const qr=link?await Jt(link):"";if(current())b.qrDataUrl=qr;
      }});
    }
    async function es(){
      if(Y.value)return;if(!M.value){_("لطفاً پلن تمدید اکانت را انتخاب کنید");return}
      const s=M.value,target={id:b.id,email:b.email};Y.value=true;
      try{
        await B.post("/admin/users/extend",{email:target.email,period:s,tariff_group_id:$.value});
        await speedboxRefreshAdminPurchase(target);
        ce(\`اکانت با موفقیت \${Ya(s)} تمدید شد\`);
      }catch(error){_(error?.response?.data?.message||"تمدید انجام نشد")}
      finally{Y.value=false;if(speedboxCurrentPurchase(target)){Ke.value="";W.value=false;be.value="result";M.value=""}}
    }`;
    const topup = `async function ts(){
      if(dt.value)return;const s=Math.floor(Number(V.value)||0);
      if(s<1){_("مقدار ترافیک/گیگ را وارد کنید");return}if(!b?.email){_("ایمیل کاربر نامشخص است");return}if(qa.value){_(Qt(we.value));return}
      const target={id:b.id,email:b.email};dt.value=true;
      try{
        const group=J.value?$.value:b.account_tariff_group_id||$.value;
        await B.post("/admin/users/topup",{email:target.email,gb:s,tariff_group_id:group});
        await speedboxRefreshAdminPurchase(target);
        ce(\`\${s} گیگ ترافیک با موفقیت به اکانت اضافه شد\`);
      }catch(error){_(error?.response?.data?.message||"خرید ترافیک انجام نشد")}
      finally{dt.value=false;if(speedboxCurrentPurchase(target)){V.value=1;Ke.value="";X.value=false;be.value="result"}}
    }`;
    users = users.slice(0,a) + refresh + users.slice(z,c) + topup + users.slice(d);
  }
  writeFileSync(path.join(root, 'public/build', mainEntry.file), embed(main));
  const nextUsers = embed(users);
  if (nextUsers !== readFileSync(path.join(root, 'public/build', usersEntry.file), 'utf8')) rotate(usersEntry, directory, nextUsers, 'Users-speedbox-');
}

function rotate(entry, directory, source, prefix) {
  const oldName = path.basename(entry.file), newName = prefix + createHash('sha256').update(source).digest('hex').slice(0,12) + '.js';
  writeFileSync(path.join(directory,newName), source);
  for (const file of readdirSync(directory).filter(file=>file.endsWith('.js')&&file!==oldName&&file!==newName)) {
    const content=readFileSync(path.join(directory,file),'utf8');
    if(content.includes(oldName))writeFileSync(path.join(directory,file),content.replaceAll(oldName,newName));
  }
  entry.file='assets/'+newName;if(oldName!==newName)unlinkSync(path.join(directory,oldName));
}

if(process.argv[1]&&path.resolve(process.argv[1])===fileURLToPath(import.meta.url)) {
  const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'../..'), manifestPath=path.join(root,'public/build/manifest.json');
  const manifest=JSON.parse(readFileSync(manifestPath,'utf8'));integratePurchaseRefresh(root,manifest);
  const entry=manifest['resources/js/app.js'];rotate(entry,path.join(root,'public/build/assets'),readFileSync(path.join(root,'public/build',entry.file),'utf8'),'app-speedbox-');
  writeFileSync(manifestPath,JSON.stringify(manifest,null,2)+'\n');console.log('Admin and representative purchase refresh integrated:',entry.file);
}
