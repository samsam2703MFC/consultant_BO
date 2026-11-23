/* Tourne les vidéos d'instruction du dashboard magasin et les range à côté de la page.
 *
 *   node panel/tools/videos/tourner.js                 toutes les vidéos de scenarios/
 *   node panel/tools/videos/tourner.js 02-mon-equipe   une seule (le nom de son scénario)
 *
 * 1. assemble le site public dans un dossier temporaire (shared/bin/assembler_public.php) ;
 * 2. le sert en local (php -S 127.0.0.1:8099) — l'API du cockpit est remplacée par la fausse API de mocks/ ;
 * 3. lance chaque scénario (film.js : Chromium filme, ffmpeg monte) ;
 * 4. copie le MP4 dans panel/public/dashboard/videos/, en tire l'affiche (la couverture) et écrit dans
 *    videos/videos.json sa durée, le commit et le jour du tournage (tourne_sur, tourne_le : a_refaire.js s'en sert).
 * Prérequis : php, ffmpeg, node et le paquet playwright avec son Chromium (voir LISEZMOI.md).
 */
const fs = require('fs');
const os = require('os');
const path = require('path');
const { spawn, execFileSync } = require('child_process');

const ICI = __dirname;
const DEPOT = path.resolve(ICI, '../../..');
const VIDEOS = path.join(DEPOT, 'panel/public/dashboard/videos');
const attendre = ms => new Promise(r => setTimeout(r, ms));
const PORT = +(process.env.FILM_PORT || 8099);   // FILM_PORT=8101 node tourner.js … : un autre port, pour tourner en parallèle

async function serveur(site) {
  const php = spawn('php', ['-S', '127.0.0.1:' + PORT, '-t', site], { stdio: 'ignore' });
  for (let i = 0; i < 40; i++) {
    try { const r = await fetch('http://127.0.0.1:' + PORT + '/dashboard/index.html'); if (r.ok) { return php; } } catch (e) { /* pas encore */ }
    await attendre(250);
  }
  php.kill(); throw new Error('le serveur local ne répond pas sur 127.0.0.1:' + PORT + ' (port déjà pris ?)');
}

function dureeLisible(mp4) {
  const s = parseFloat(execFileSync('ffprobe', ['-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', mp4]).toString());
  const m = Math.floor(s / 60), r = Math.round(s - m * 60);
  return m ? m + ' min' + (r >= 10 ? ' ' + String(r).padStart(2, '0') : '') : r + ' s';
}

(async () => {
  const voulus = process.argv.slice(2);
  const scenarios = fs.readdirSync(path.join(ICI, 'scenarios')).filter(f => /^\d\d-.+\.js$/.test(f)).map(f => f.replace(/\.js$/, ''))
    .filter(n => !voulus.length || voulus.includes(n));
  if (!scenarios.length) { console.error('aucun scénario : ' + voulus.join(' ')); process.exit(2); }

  const site = fs.mkdtempSync(path.join(os.tmpdir(), 'videos-site-'));
  execFileSync('php', [path.join(DEPOT, 'shared/bin/assembler_public.php'), site], { stdio: 'inherit' });
  const php = await serveur(site);
  const registre = path.join(VIDEOS, 'videos.json');
  const reg = JSON.parse(fs.readFileSync(registre, 'utf8'));
  try {
    for (const n of scenarios) {
      console.log('— ' + n);
      const mp4 = await require(path.join(ICI, 'scenarios', n + '.js'))();
      fs.mkdirSync(VIDEOS, { recursive: true });
      fs.copyFileSync(mp4, path.join(VIDEOS, n + '.mp4'));
      execFileSync('ffmpeg', ['-y', '-loglevel', 'error', '-ss', '3', '-i', mp4, '-frames:v', '1', '-vf', 'scale=960:-1', '-q:v', '4', path.join(VIDEOS, n + '.jpg')]);
      const v = reg.videos.find(x => x.fichier === n + '.mp4');
      if (v) {
        v.duree = dureeLisible(mp4); v.affiche = n + '.jpg';
        // Le commit et le jour du tournage : a_refaire.js compare les sources de la vidéo depuis ce commit.
        v.tourne_sur = execFileSync('git', ['rev-parse', 'HEAD'], { cwd: DEPOT }).toString().trim();
        v.tourne_le = new Date().toISOString().slice(0, 10);
      } else { console.log('  (pas dans videos.json : à y ajouter)'); }
    }
  } finally {
    php.kill();
    fs.rmSync(site, { recursive: true, force: true });
  }
  fs.writeFileSync(registre, JSON.stringify(reg, null, 2) + '\n');
  console.log('rangé dans ' + VIDEOS);
})().catch(e => { console.error(e); process.exit(1); });
