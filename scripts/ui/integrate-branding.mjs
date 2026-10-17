import { readFileSync, writeFileSync } from 'node:fs';
import path from 'node:path';

export function integrateBranding(root, manifest) {
  const file = path.join(root, 'public/build', manifest['resources/js/app.js'].file);
  let source = readFileSync(file, 'utf8');
  if (source.includes('/*speedbox-branding*/')) return;
  const login = 'b("h1",null,"🚀 پنل مدیریت",-1)';
  const brandedLogin = 'b("h1",{class:"speedbox-login-heading"},[b("img",{class:"speedbox-login-logo",src:"/favicon-128x128.png",width:32,height:32,alt:"","aria-hidden":"true"}),b("span",null,"پنل مدیریت")],-1)';
  const shell = 'f6={__name:"App",setup(e){return(t,o)=>{const n=yo("router-view");return k(),x(j0,null,[b1(n),b1(A6)],64)}}}';
  const brandedShell = 'f6={__name:"App",setup(e){const speedboxBrandRoute=Sc();return(t,o)=>{const n=yo("router-view");return k(),x(j0,null,[b1(n),/^\\/(admin|panel)(\\/|$)/.test(speedboxBrandRoute.path)?speedboxBrandFooter(b):m0("",!0),b1(A6)],64)}}}';
  if (source.split(login).length !== 2 || source.split(shell).length !== 2) {
    throw Error('Panel login or root layout changed; update the branding bridge.');
  }
  const factory = readFileSync(path.join(root, 'scripts/ui/brand-footer.mjs'), 'utf8').replace('export function ', 'function ');
  source = source.replace(login, brandedLogin).replace(shell, brandedShell) + '\n/*speedbox-branding*/\n' + factory;
  writeFileSync(file, source);
}
