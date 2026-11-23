/* Quelles vidéos d'instruction refaire, et lesquelles faire ? (la routine des vidéos, 23/11/2026)
 *
 *   node panel/tools/videos/a_refaire.js [référence]        (référence : origin/claude/new-session-deedox par défaut)
 *   node panel/tools/videos/a_refaire.js --json
 *
 * 1. À ADAPTER : pour chaque vidéo de videos/videos.json, les commits qui ont touché ses `sources` (les fichiers dont
 *    l'écran est filmé) depuis son tournage (`tourne_sur`) — à relire : l'écran filmé a-t-il changé ? Le scénario
 *    tourne-t-il encore ?
 * 2. NOUVEAUTÉS : les mises en ligne (lignes de shared/deploy.trigger) depuis le dernier tournage qui parlent du
 *    dashboard franchisé — chacune peut demander une vidéo « nouveau » ou un module de formation.
 * 3. SANS VIDÉO : les pages du dashboard (valeurs de ?vue=) qu'aucune vidéo ne couvre.
 * Rien n'est modifié : la décision (retourner, écrire un scénario) revient à qui lit.
 */
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

const DEPOT = path.resolve(__dirname, '../../..');
const git = (...a) => { try { return execFileSync('git', a, { cwd: DEPOT, stdio: ['ignore', 'pipe', 'ignore'], maxBuffer: 64 * 1024 * 1024 }).toString().trim(); } catch (e) { return null; } };
const args = process.argv.slice(2);
const json = args.includes('--json');
const ref = args.find(a => !a.startsWith('--')) || (git('rev-parse', '--verify', '-q', 'origin/claude/new-session-deedox') ? 'origin/claude/new-session-deedox' : 'HEAD');

const reg = JSON.parse(fs.readFileSync(path.join(DEPOT, 'panel/public/dashboard/videos/videos.json'), 'utf8'));
const lu = chemin => git('show', ref + ':' + chemin) || '';

// 1. À adapter
const adapter = reg.videos.map(v => {
  const base = v.tourne_sur && git('cat-file', '-e', v.tourne_sur + '^{commit}') !== null ? v.tourne_sur : null;
  const commits = base
    ? (git('log', '--format=%h %ad %s', '--date=short', base + '..' + ref, '--', ...(v.sources || [])) || '').split('\n').filter(Boolean)
    : (git('log', '--format=%h %ad %s', '--date=short', '--since=' + (v.tourne_le || '1970-01-01'), ref, '--', ...(v.sources || [])) || '').split('\n').filter(Boolean);
  const fichiers = base ? (git('diff', '--name-only', base, ref, '--', ...(v.sources || [])) || '').split('\n').filter(Boolean) : [];
  return { id: v.id, fichier: v.fichier, scenario: v.fichier.replace(/\.mp4$/, ''), tourne_le: v.tourne_le || null, commits, fichiers };
});

// 2. Nouveautés : les mises en ligne postérieures au plus ancien tournage (l'heure de son commit), qui parlent du
// dashboard franchisé.
const instants = reg.videos.map(v => (v.tourne_sur && git('log', '-1', '--format=%cI', v.tourne_sur)) || (v.tourne_le ? v.tourne_le + 'T00:00:00Z' : null)).filter(Boolean).map(t => Date.parse(t));
const t0 = instants.length ? Math.min(...instants) : 0;
const depuis = new Date(t0).toISOString().slice(0, 16).replace('T', ' ') + ' UTC';
const MOTS = /dashboard|franchis|magasin|mon équipe|planning|trésorerie|promotion|offres?\b|boîte mail|assistant|revue du soir|primes?|tablette|coffre|formation|catalogue|fidélité|analyse|tâches|temps morts/i;
const nouveautes = lu('shared/deploy.trigger').split('\n')
  .map(l => { const m = l.match(/^(\d{4}-\d{2}-\d{2}T\S+)\s+(.*)$/); return m ? { date: m[1], t: Date.parse(m[1]), texte: m[2] } : null; })
  .filter(x => x && x.t > t0 && MOTS.test(x.texte))
  .map(({ date, texte }) => ({ date, texte }));

// 3. Les pages sans vidéo (la liste de ?vue= lue dans dashboard.js, comme le fait le dashboard).
const js = lu('panel/public/dashboard/dashboard.js');
const m = js.match(/\[('ops', 'taches'[^\]]*)\]\.includes\(q\.get\('vue'\)\)/);
const pages = m ? [...m[1].matchAll(/'([a-z]+)'/g)].map(x => x[1]) : [];
const couvertes = new Set(reg.videos.flatMap(v => v.vues || []));
const sansVideo = couvertes.has('*') ? [] : pages.filter(p => !couvertes.has(p));

const r = { reference: ref, adapter: adapter.filter(a => a.commits.length), aJour: adapter.filter(a => !a.commits.length).map(a => a.id), nouveautes, sansVideo };
if (json) { console.log(JSON.stringify(r, null, 2)); process.exit(0); }
console.log('Référence : ' + ref + '\n');
console.log('1. À ADAPTER (sources changées depuis le tournage)');
if (!r.adapter.length) { console.log('   aucune — à jour : ' + r.aJour.join(', ')); }
r.adapter.forEach(a => {
  console.log('   ' + a.id + ' (' + a.scenario + ', tournée le ' + (a.tourne_le || '?') + ') — ' + a.commits.length + ' commit(s)');
  a.commits.slice(0, 12).forEach(c => console.log('     ' + c));
  if (a.fichiers.length) { console.log('     fichiers : ' + a.fichiers.join(', ')); }
});
console.log('\n2. NOUVEAUTÉS MISES EN LIGNE depuis le ' + depuis + ' (shared/deploy.trigger)');
if (!r.nouveautes.length) { console.log('   aucune'); }
r.nouveautes.forEach(n => console.log('   ' + n.date + '  ' + n.texte));
console.log('\n3. PAGES DU DASHBOARD SANS VIDÉO');
console.log('   ' + (r.sansVideo.join(', ') || 'aucune'));
