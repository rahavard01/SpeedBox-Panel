// Browser regression for intermediate scrolling, collapsed borders and blurred headers.
// Optional runners: SPEEDBOX_TEST_PLAYWRIGHT, SPEEDBOX_TEST_CHROMIUM.
import assert from 'node:assert/strict';
import http from 'node:http';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { build } from 'vite';

const { chromium } = await import(process.env.SPEEDBOX_TEST_PLAYWRIGHT || 'playwright');
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const result = await build({ root, configFile: false, logLevel: 'error',
  build: { write: false, minify: false, cssCodeSplit: false,
    rollupOptions: { input: path.join(root, 'resources/js/speedbox/sticky-actions.js'),
      preserveEntrySignatures: 'strict', output: { inlineDynamicImports: true } } } });
const outputs = result.output;
const script = outputs.find(item => item.type === 'chunk').code;
const css = outputs.filter(item => item.type === 'asset').map(item => item.source).join('\n');
const html = `<!doctype html><html><head><style>
body{margin:30px;background:#1e1e1e;color:#fff;font:16px sans-serif}
#shell{width:420px;overflow:auto}
table{width:900px;table-layout:fixed;border-collapse:collapse}
th,td{border:2px solid rgba(255,255,255,.2)!important;padding:18px;text-align:center}
thead{background:rgba(255,255,255,.1);backdrop-filter:blur(6px)}
tbody tr:nth-child(odd) td{background:rgba(255,255,255,.02)}
</style><style>${css}</style></head><body><div id="shell"></div>
<script type="module">import {installStickyActions} from '/fixture.js';window.install=installStickyActions;window.ready=true;</script></body></html>`;
const server = http.createServer((request, response) => {
  response.setHeader('Content-Type', request.url === '/fixture.js' ? 'text/javascript' : 'text/html; charset=utf-8');
  response.end(request.url === '/fixture.js' ? script : html);
});
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
let browser, checks = 0;
try {
  for (const scale of [1, 1.25, 2]) for (const direction of ['ltr', 'rtl']) for (const index of [0, 3]) {
    browser = await chromium.launch({ headless: true,
      ...(process.env.SPEEDBOX_TEST_CHROMIUM ? { executablePath: process.env.SPEEDBOX_TEST_CHROMIUM,
        args: ['--no-sandbox', '--no-zygote', '--single-process'] } : {}) });
    const page = await browser.newPage({ viewport: { width: 1200, height: 400 }, deviceScaleFactor: scale });
    await page.goto('http://127.0.0.1:' + server.address().port);
    await page.waitForFunction(() => window.ready);
    await page.evaluate(({ direction, index }) => {
      const shell = document.querySelector('#shell'); shell.style.direction = direction;
      const cells = (tag, row) => Array.from({ length: 4 }, (_, column) =>
        `<${tag}>${column === index ? (row ? '<button>Action</button>' : 'عملیات') : 'Scrolling ' + column}</${tag}>`).join('');
      shell.innerHTML = `<table><thead><tr>${cells('th', 0)}</tr></thead><tbody>${[1, 2].map(row => `<tr>${cells('td', row)}</tr>`).join('')}</tbody></table>`;
      window.dispose = install();
    }, { direction, index });
    const flush = () => page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
    await flush();
    let baseline;
    for (const offset of [0, 1, 7, 14, 30, 100, 200]) {
      await page.evaluate(offset => { const shell = document.querySelector('#shell'); shell.scrollLeft = getComputedStyle(shell).direction === 'rtl' ? -offset : offset; }, offset);
      await flush();
      const geometry = await page.evaluate(() => [...document.querySelectorAll('.speedbox-sticky-action')].map(cell => cell.getBoundingClientRect().toJSON()));
      assert.equal(geometry.length, 3); checks++;
      for (const cell of geometry) { assert(Math.abs(cell.left - geometry[0].left) < .1); assert(Math.abs(cell.right - geometry[0].right) < .1); checks += 2; }
      const png = await page.screenshot();
      const samples = await page.evaluate(async ({ data, geometry, scale }) => {
        const image = new Image(); image.src = 'data:image/png;base64,' + data; await image.decode();
        const canvas = document.createElement('canvas'); canvas.width = image.width; canvas.height = image.height;
        const context = canvas.getContext('2d'); context.drawImage(image, 0, 0);
        return geometry.map(rect => {
          const y = Math.ceil((rect.top + 6) * scale), x = Math.floor(rect.left * scale), end = Math.ceil(rect.right * scale);
          const pixels = context.getImageData(x, y, end - x, 1).data;
          return { pixels: Array.from(pixels), width: end - x };
        });
      }, { data: png.toString('base64'), geometry, scale });
      if (!baseline) baseline = samples;
      // Fractional display scaling changes edge antialiasing when a collapsed
      // cell first reaches the sticky boundary. Compare exact pixels at 1x and
      // full border coverage/color below at every display scale.
      if (scale === 1) {
        assert.deepEqual(samples, baseline, `Fixed separators/surface changed at ${direction}, index ${index}, scroll ${offset}`); checks++;
      } else if (scale === 2) {
        // The blurred translucent layer can round by one 8-bit color level.
        for (const [index, sample] of samples.entries()) {
          assert.equal(sample.width, baseline[index].width);
          assert(sample.pixels.every((value, pixel) => Math.abs(value - baseline[index].pixels[pixel]) <= 1), 'Retina separator pixels changed'); checks += 2;
        }
      }
      for (const sample of samples) {
        const rgb = pixel => sample.pixels.slice(pixel * 4, pixel * 4 + 3);
        const background = rgb(Math.floor(sample.width / 2));
        const reference = baseline[samples.indexOf(sample)];
        const referenceBackground = reference.pixels.slice(Math.floor(reference.width / 2) * 4, Math.floor(reference.width / 2) * 4 + 3);
        assert(background.every((value, channel) => Math.abs(value - referenceBackground[channel]) <= (scale === 1 ? 0 : 1))); checks++;
        const painted = pixel => rgb(pixel).some((value, channel) => Math.abs(value - background[channel]) > 3);
        let left = 0, right = 0;
        for (let pixel = 0; pixel < Math.ceil(3 * scale); pixel++) {
          if (painted(pixel)) left++;
          if (painted(sample.width - 1 - pixel)) right++;
        }
        const outerLeft = (direction === 'rtl') === (index >= 2);
        const full = Math.floor(2 * scale), inner = Math.max(1, full - (scale > 1 ? 1 : 0));
        assert((outerLeft ? left >= full && right >= inner : right >= full && left >= inner), `Both full-width separators must remain visible: ${JSON.stringify({direction,index,scale,offset,left,right,width:sample.width,first:sample.pixels.slice(0,20),last:sample.pixels.slice(-20)})}`); checks++;
        for (const edge of ['left', 'right']) for (let channel = 0; channel < 3; channel++) {
          const peak = (item, side) => Math.max(...Array.from({ length: Math.ceil(3 * scale) }, (_, pixel) => item.pixels[(side === 'left' ? pixel : item.width - 1 - pixel) * 4 + channel]));
          assert(Math.abs(peak(sample, edge) - peak(reference, edge)) <= (Number.isInteger(scale) ? 1 : 4), `Separator color changed beyond edge antialiasing: ${JSON.stringify({direction,index,scale,offset,edge,channel,peak:peak(sample,edge),before:peak(reference,edge)})}`); checks++;
        }
        for (let pixel = Math.ceil(3 * scale); pixel < sample.width - Math.ceil(3 * scale); pixel++) {
          assert(!painted(pixel), `Stray separator inside fixed cell at scroll ${offset}`); checks++;
        }
      }
    }
    await page.evaluate(() => dispose()); await flush();
    assert.equal(await page.locator('.speedbox-actions-header,.speedbox-border-cell,.speedbox-sticky-action').count(), 0); checks++;
    await browser.close(); browser = null;
    console.log(`PASS ${direction} action ${index} scale ${scale}`);
  }
  console.log(`Sticky operation borders: ${checks} pixel/geometry checks passed across 12 cases and 7 scroll offsets.`);
} finally { if (browser) await browser.close(); await new Promise(resolve => server.close(resolve)); }
