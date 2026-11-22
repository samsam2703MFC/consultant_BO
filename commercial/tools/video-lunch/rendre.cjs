/* Rend la vidéo de la publicité « Lunch au bureau » (video.html) en MP4, image par image : Chromium (Playwright) pose
 * chaque instant (30 images par seconde, 15 s), ffmpeg assemble en H.264 (yuv420p, faststart : lisible par Meta et
 * par tous les navigateurs). Sans son : Meta l'accepte ; une musique s'ajoute dans le Gestionnaire de publicités.
 *
 *   node commercial/tools/video-lunch/rendre.cjs [--langue fr|nl|toutes] [--format 9x16|4x5|tous] [--sortie dossier]
 *
 * Par défaut : fr, les deux formats, dans commercial/public/commercial/meta/ (servi à /commercial/meta/). Chaque vidéo
 * a son affiche (la première carte, en JPEG) pour l'aperçu du portail. */
'use strict';
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const fs = require('fs'), os = require('os'), path = require('path');

const arg = (n, def) => { const i = process.argv.indexOf('--' + n); return i > 0 ? process.argv[i + 1] : def; };
const langues = arg('langue', 'fr') === 'toutes' ? ['fr', 'nl'] : [arg('langue', 'fr')];
const formats = arg('format', 'tous') === 'tous' ? ['9x16', '4x5'] : [arg('format')];
const sortie = path.resolve(arg('sortie', path.join(__dirname, '../../public/commercial/meta')));
const HAUTEUR = { '9x16': 1920, '4x5': 1350 };
const IPS = 30;

(async () => {
  fs.mkdirSync(sortie, { recursive: true });
  const b = await chromium.launch();
  for (const langue of langues) {
    for (const format of formats) {
      const h = HAUTEUR[format];
      const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'video-lunch-'));
      const p = await b.newPage({ viewport: { width: 1080, height: h }, deviceScaleFactor: 1 });
      await p.goto('file://' + path.join(__dirname, 'video.html') + `?format=${format}&langue=${langue}`);
      await p.evaluate(() => window.pret);
      const duree = await p.evaluate(() => window.DUREE);
      const n = Math.round(duree * IPS);
      for (let i = 0; i < n; i++) {
        await p.evaluate(t => window.poser(t), i / IPS);
        await p.screenshot({ path: path.join(tmp, `f${String(i).padStart(4, '0')}.jpg`), type: 'jpeg', quality: 93, clip: { x: 0, y: 0, width: 1080, height: h } });
      }
      const nom = `lunch-au-bureau-${langue}-${format}`;
      await p.evaluate(t => window.poser(t), 1.6);
      await p.screenshot({ path: path.join(sortie, nom + '.jpg'), type: 'jpeg', quality: 86, clip: { x: 0, y: 0, width: 1080, height: h } });
      await p.close();
      execFileSync('ffmpeg', ['-v', 'error', '-y', '-framerate', String(IPS), '-i', path.join(tmp, 'f%04d.jpg'),
        '-c:v', 'libx264', '-preset', 'slow', '-crf', '22', '-pix_fmt', 'yuv420p', '-movflags', '+faststart', '-an', path.join(sortie, nom + '.mp4')]);
      fs.rmSync(tmp, { recursive: true, force: true });
      console.log(nom + '.mp4', Math.round(fs.statSync(path.join(sortie, nom + '.mp4')).size / 1024) + ' Ko', n + ' images');
    }
  }
  await b.close();
})().catch(e => { console.error(e); process.exit(1); });
