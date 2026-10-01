// Render the selected Free directory banner. Requires Sharp.
const fs = require('fs');
const path = require('path');
const sharp = require('sharp');
const dir = __dirname;
const studio = fs.readFileSync(path.join(dir, 'studio-logo.svg'), 'utf8');
const studioPaths = [...studio.matchAll(/<path\b[^>]*>[\s\S]*?<\/path>/g)].slice(1).map(match => match[0]).join('');
const icon = fs.readFileSync(path.join(dir, 'icon.svg'), 'utf8');
const iconBody = icon.replace(/^<svg[^>]*>/, '').replace(/<\/svg>\s*$/, '');
const banner = `<svg xmlns="http://www.w3.org/2000/svg" width="1544" height="500" viewBox="0 0 772 250" role="img" aria-label="MUDRAVA Migration and Backup. Free WordPress migration. No paid size cap. Portable backups, resumable exports, optional encryption and verification before restore. Host resources and format limits apply.">
<rect width="772" height="250" fill="#20242C"/>
<rect x="544" width="228" height="250" fill="#F6C85F"/>
<g transform="translate(29 20) scale(.245)">${studioPaths}</g>
<path d="M163 26v17" stroke="#68707C"/>
<g font-family="Arial, Helvetica, sans-serif" font-size="12" fill="#F8F7F3">
<text x="177" y="39">Migration &amp; Backup</text>
<g font-size="32" font-weight="700" letter-spacing="-.6">
<text x="32" y="97">Free WordPress migration.</text>
<text x="32" y="137" fill="#F6C85F">No paid size cap.</text>
</g>
<text x="57" y="179">Streaming .mudrava backups</text>
<text x="296" y="179">Resumable exports</text>
<text x="57" y="206">Optional encryption</text>
<text x="296" y="206">Verification before restore</text>
<text x="32" y="235" fill="#BDC3CD">Host resources and format limits apply.</text>
</g>
<g stroke="#F6C85F" stroke-width="1.5" fill="none" stroke-linecap="round" stroke-linejoin="round">
<path d="M35 166h8l4 4v12H35zM43 166v5h4"/>
<path d="M273 171l1.697-1.303a7.5 7.5 0 1 1 0 10.606M273 166v5h5M280 171v4l3 2"/>
<rect x="34" y="197" width="14" height="12" rx="2"/><path d="M37 197v-3a4 4 0 0 1 8 0v3M41 201v3"/>
<circle cx="280" cy="201" r="8"/><path d="m276 201 3 3 5-6"/>
</g>
<g transform="translate(575 37) scale(.65)">${iconBody}</g>
<text x="658" y="225" text-anchor="middle" font-family="Arial, Helvetica, sans-serif" font-size="12" fill="#20242C">Your portable backup</text>
</svg>`;
(async () => {
 fs.writeFileSync(path.join(dir, 'banner.svg'), banner + '\n');
 for (const [width,height] of [[772,250],[1544,500]]) {
  await sharp(Buffer.from(banner)).resize(width,height).png().toFile(path.join(dir, `../wordpress-org/assets/banner-${width}x${height}.png`));
 }
 await sharp(Buffer.from(banner)).resize(1544,500).png().toFile(path.join(dir, 'preview.png'));
})();
