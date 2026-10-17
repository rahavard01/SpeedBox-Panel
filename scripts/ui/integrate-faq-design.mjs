import { readFileSync, writeFileSync } from 'node:fs';
import { createHash } from 'node:crypto';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

export function integrateFaqDesign(root, manifest) {
  const directory = path.join(root, 'public/build/assets'), active = new Map();
  function visit(file) {
    if (active.has(file)) return;
    const source = readFileSync(path.join(directory, file), 'utf8'); active.set(file, source);
    for (const match of source.matchAll(/["']\.\/([^"']+\.js)["']/g)) visit(match[1]);
  }
  for (const entry of Object.values(manifest)) if (entry.file?.endsWith('.js')) visit(path.basename(entry.file));
  const file = path.basename(manifest['resources/js/pages/admin/Settings.vue'].file), source = active.get(file);
  const factory = readFileSync(path.join(root, 'scripts/ui/global-bot-faqs.mjs'), 'utf8').replace('export function ', 'function ');
  if (source.includes('/*speedbox-faq-design*/') && source.includes(factory)) return;
  const start = source.indexOf('function createGlobalBotFaqEditor(');
  if (start < 0) throw Error('Global FAQ factory missing from the current Settings build');
  active.set(file, source.slice(0, start) + factory + '\n/*speedbox-faq-design*/\n');
  const version = createHash('sha256').update(active.get(file)).digest('hex').slice(0, 12);
  const names = new Map([...active.keys()].map(file => [file, file.replace(/(?:-faq-[0-9a-f]{12})?\.js$/, '-faq-' + version + '.js')]));
  // Rotate the entire graph together, retaining prior files for existing tabs.
  for (const [file, source] of active) {
    let next = source;
    for (const [oldName, newName] of names) next = next.replaceAll(oldName, newName);
    writeFileSync(path.join(directory, names.get(file)), next);
  }
  for (const entry of Object.values(manifest)) if (entry.file?.endsWith('.js')) entry.file = 'assets/' + names.get(path.basename(entry.file));
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
  const file = path.join(root, 'public/build/manifest.json'), manifest = JSON.parse(readFileSync(file, 'utf8'));
  integrateFaqDesign(root, manifest);
  writeFileSync(file, JSON.stringify(manifest, null, 2) + '\n');
  console.log('FAQ design integrated:', manifest['resources/js/pages/admin/Settings.vue'].file);
}
