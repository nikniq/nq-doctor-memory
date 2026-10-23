/**
 * Regenerates the favicon and share images from public_html/favicon.svg:
 *   node scripts/make-icons.mjs
 * Writes public_html/favicon.ico (32px), public_html/images/apple-touch-icon.png
 * (180px) and public_html/images/og-image.png (1200x630 link preview).
 */
import { readFileSync, writeFileSync } from 'node:fs';
import { chromium } from 'playwright';

const svg = readFileSync('public_html/favicon.svg', 'utf8');
const browser = await chromium.launch();

async function render(html, width, height) {
    const page = await browser.newPage({ viewport: { width, height } });
    await page.setContent(`<!doctype html><html><body style="margin:0">${html}</body></html>`);
    const png = await page.screenshot({ omitBackground: false });
    await page.close();
    return png;
}

const icon = (size) => `<div style="width:${size}px;height:${size}px">${svg.replace('<svg ', `<svg width="${size}" height="${size}" `)}</div>`;

// An .ico file holding one PNG image (supported by every current browser).
function icoFromPng(png, size) {
    const header = Buffer.alloc(6);
    header.writeUInt16LE(0, 0); // reserved
    header.writeUInt16LE(1, 2); // type: icon
    header.writeUInt16LE(1, 4); // one image
    const entry = Buffer.alloc(16);
    entry.writeUInt8(size, 0);
    entry.writeUInt8(size, 1);
    entry.writeUInt8(0, 2); // no palette
    entry.writeUInt8(0, 3);
    entry.writeUInt16LE(1, 4); // colour planes
    entry.writeUInt16LE(32, 6); // bits per pixel
    entry.writeUInt32LE(png.length, 8);
    entry.writeUInt32LE(6 + 16, 12); // image offset
    return Buffer.concat([header, entry, png]);
}

writeFileSync('public_html/favicon.ico', icoFromPng(await render(icon(32), 32, 32), 32));
writeFileSync('public_html/images/apple-touch-icon.png', await render(icon(180), 180, 180));

const card = (rotate, x) => `<div style="position:absolute;left:${x}px;top:150px;width:220px;height:300px;border-radius:24px;
    background:#3b82f6;box-shadow:inset 0 0 0 8px rgba(255,255,255,.25),0 20px 40px rgba(0,0,0,.35);transform:rotate(${rotate}deg);
    display:flex;align-items:center;justify-content:center">
    <div style="position:relative;width:80px;height:80px">
      <div style="position:absolute;left:27px;top:0;width:26px;height:80px;border-radius:6px;background:#fff"></div>
      <div style="position:absolute;left:0;top:27px;width:80px;height:26px;border-radius:6px;background:#fff"></div>
    </div></div>`;
const og = `<div style="position:relative;width:1200px;height:630px;overflow:hidden;background:#1e3a5f;font-family:Arial,Helvetica,sans-serif;color:#fff">
  ${card(-8, 700)}${card(6, 920)}
  <div style="position:absolute;left:72px;top:170px;width:580px">
    <div style="font-size:70px;font-weight:bold;line-height:1.05">Doctor Memory Game</div>
    <div style="margin-top:28px;font-size:34px;line-height:1.35;opacity:.9">Match medical cards, learn a fact with every pair, and take on the daily challenge.</div>
  </div></div>`;
writeFileSync('public_html/images/og-image.png', await render(og, 1200, 630));

await browser.close();
console.log('icons written');
