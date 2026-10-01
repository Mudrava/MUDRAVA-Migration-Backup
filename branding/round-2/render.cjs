// Rebuild editable logo concepts and the review board.
const fs = require('fs');
const path = require('path');
const sharp = require('sharp');
const dir = __dirname;
const studio = fs.readFileSync(path.join(dir, '../studio-logo.svg'), 'utf8');
const originalPaths = [...studio.matchAll(/<path\b[^>]*>/g)].slice(1,2).map(x=>x[0].replace(/\/?>$/, '/>')).join('');
const m = originalPaths.replace(/fill="white"/g, 'fill="currentColor"');
const svg = (name, bg, content) => `<svg xmlns="http://www.w3.org/2000/svg" width="256" height="256" viewBox="0 0 256 256" role="img" aria-label="MUDRAVA ${name}"><rect width="256" height="256" rx="56" fill="${bg}"/>${content}</svg>`;
const marks = [
 {id:'01-brand',name:'BRAND',bg:'#092570',ink:'#F9FAFF',content:`<g transform="translate(2.3 8.2) scale(2.35)" color="#F9FAFF">${m}</g>`},
 {id:'02-transfer',name:'TRANSFER',bg:'#352547',ink:'#FFB48A',content:`<path d="M58 77h87V52l51 44-51 44v-25H58z" fill="#FFEFE5"/><path d="M198 179h-87v25l-51-44 51-44v25h87z" fill="#FFB48A"/>`},
 {id:'03-archive',name:'ARCHIVE',bg:'#20242C',ink:'#F6C85F',content:`<rect x="55" y="55" width="146" height="31" rx="9" fill="#F6C85F"/><path d="M65 100h126v80a17 17 0 0 1-17 17H82a17 17 0 0 1-17-17z" fill="#F8F7F3"/><g transform="translate(72.9 94.995) scale(1.03)" color="#20242C">${m}</g>`},
 {id:'04-link',name:'LINK',bg:'#253F45',ink:'#BCE9CA',content:`<path d="M116 62H87a25 25 0 0 0-25 25v48a25 25 0 0 0 25 25h29" fill="none" stroke="#F4F9F3" stroke-width="25" stroke-linecap="round"/><path d="M140 194h29a25 25 0 0 0 25-25v-48a25 25 0 0 0-25-25h-29" fill="none" stroke="#BCE9CA" stroke-width="25" stroke-linecap="round"/><path d="m105 151 46-46" fill="none" stroke="#BCE9CA" stroke-width="25" stroke-linecap="round"/>`}
];
(async()=>{
 const layers=[];
 for(const [i,mark] of marks.entries()) {
  const source=svg(mark.name,mark.bg,mark.content);
  fs.writeFileSync(path.join(dir,mark.id+'.svg'), source+'\n');
  await sharp(Buffer.from(source)).png().toFile(path.join(dir,mark.id+'.png'));
  const x=36+(i%2)*484, y=32+Math.floor(i/2)*348;
  const panel=`<svg width="468" height="332"><rect x=".5" y=".5" width="467" height="331" rx="16" fill="#fff" stroke="#E1E3E8"/><text x="28" y="36" font-family="Arial, Helvetica, sans-serif" font-size="16" font-weight="700" letter-spacing="1.2" fill="#323740">0${i+1}  ${mark.name}</text><text x="270" y="103" font-family="Arial, Helvetica, sans-serif" font-size="13" fill="#707680">48 px</text><text x="357" y="103" font-family="Arial, Helvetica, sans-serif" font-size="13" fill="#707680">32 px</text><text x="270" y="215" font-family="Arial, Helvetica, sans-serif" font-size="13" fill="#707680">MONO</text></svg>`;
  layers.push({input:Buffer.from(panel),left:x,top:y});
  layers.push({input:await sharp(Buffer.from(source)).resize(192).png().toBuffer(),left:x+28,top:y+72});
  layers.push({input:await sharp(Buffer.from(source)).resize(48).png().toBuffer(),left:x+270,top:y+116});
  layers.push({input:await sharp(Buffer.from(source)).resize(32).png().toBuffer(),left:x+357,top:y+124});
  // A true single-ink version, keeping the source letterform counter intact.
  let mono = mark.content.replaceAll(mark.bg,'#ffffff').replace(/#F9FAFF|#FFEFE5|#FFB48A|#F6C85F|#F8F7F3|#F4F9F3|#BCE9CA/g,'#252A33');
  const monoSvg=`<svg xmlns="http://www.w3.org/2000/svg" width="256" height="256" viewBox="32 32 192 192">${mono}</svg>`;
  fs.writeFileSync(path.join(dir,mark.id+'-mono.svg'),monoSvg+'\n');
  layers.push({input:await sharp(Buffer.from(monoSvg)).resize(64).png().toBuffer(),left:x+270,top:y+230});
 }
 await sharp({create:{width:1040,height:736,channels:3,background:'#F1F2F4'}}).composite(layers).png().toFile(path.join(dir,'comparison.png'));
})();
