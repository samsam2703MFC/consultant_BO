/* Les gestes communs des vidéos de la Boîte mail par onglet (20-01-porte … 20-11-radar, 24/11/2026).
 * Les données sont celles de mocks/70_boite.js (fictives). Pas un scénario : tourner.js ne lance que les fichiers NN-…. */
const API = '../api/cockpit';

/** Un POST à la fausse API, depuis la page (hors film, ou sous une diapositive). */
const poster = (p, chemin, corps) => p.evaluate(([u, b]) => fetch(u, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify(b) }).then(r => r.json()),
  [API + chemin, Object.assign({ shop: '4' }, corps || {})]);
const lire = (p, chemin) => p.evaluate(u => fetch(u, { headers: { Accept: 'application/json' } }).then(r => r.json()), API + chemin + (chemin.includes('?') ? '&' : '?') + 'shop=4');

/** Pour le film seulement : de la marge sous « fermer sur cet appareil », le bas de la page, pour qu'il remonte au-dessus
 * des sous-titres quand on le montre (sinon il reste caché sous eux, collé au bas de l'écran). */
async function marge(p) {
  const css = '.bx-bas{margin-bottom:150px}';
  await p.context().addInitScript(c => { document.addEventListener('DOMContentLoaded', () => { const s = document.createElement('style'); s.textContent = c; document.head.appendChild(s); }); }, css);
  await p.addStyleTag({ content: css });
}

/** L'état de départ (Film.ouvrir({avant})) : la boîte ouverte sur cet appareil, puis `plus` (d'autres écritures), la page relue. */
const ouverte = plus => async p => {
  await marge(p);
  await poster(p, '/dashboard/boite/ouvrir', { motDePasse: 'demo' });
  if (plus) { await plus(p); }
  await p.reload(); await p.waitForTimeout(2500);
  await p.evaluate(() => window.__film.prets());
};

/** Un cadre autour d'un élément tel qu'il est à l'écran, sans faire défiler la page (coupé aux bords de la fenêtre) :
 * pour les grands blocs (la liste, le volet de droite) que f.montrer ferait défiler jusqu'à cacher la barre. */
async function cadre(f, sel, etiq, ms) {
  const r = await f.p.locator(sel).first().evaluate(e => {
    const b = e.getBoundingClientRect(), y = Math.max(8, b.top), y2 = Math.min(innerHeight - 8, b.bottom);
    return { x: b.left, y, w: b.width, h: Math.max(12, y2 - y) };
  });
  await f.p.evaluate(([r, e]) => window.__film.cadre(r, e), [r, etiq || '']);
  if (ms) { await f.pause(ms); }
}

/** Pendant la diapositive qui vient (elle couvre l'écran ~0,6 s après son début) : remonter en haut de la page et,
 * au besoin, relire la boîte (DashBoite.relire) après des écritures faites hors champ. Appeler juste avant f.fonction(). */
async function sousLaDiapo(f, { relire = false, ecritures = [] } = {}) {
  await f.p.evaluate(([api, ecritures, relire]) => setTimeout(async () => {
    for (const [chemin, corps] of ecritures) {
      await fetch(api + chemin, { method: chemin.endsWith('/cron') ? 'GET' : 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: chemin.endsWith('/cron') ? undefined : JSON.stringify(Object.assign({ shop: '4' }, corps || {})) });
    }
    window.scrollTo(0, 0);
    if (relire && window.DashBoite) { window.DashBoite.relire(); }
  }, 900), [API, ecritures, relire]);
}

/** CONTOURNEMENT, à retirer quand boite.js sera corrigé : « Enregistrer le modèle » (boite.js:971-974) et « Enregistrer »
 * d'un message automatique (boite.js:983) redessinent le formulaire (rendre()) AVANT d'en lire les champs : ce qui a été
 * tapé est perdu (un nouveau modèle part vide, refusé ; un modèle ou un message modifié repart tel quel). Pour le film,
 * on relève les champs juste avant le clic et la requête les reprend : la vidéo montre ce que l'écran doit faire. */
async function garderSaisie(f, champs) {
  await f.p.evaluate(ch => {
    const v = {};
    Object.entries(ch).forEach(([k, s]) => { const n = document.querySelector(s); if (n) { v[k] = k === 'jours' ? (+n.value || 1) : n.value; } });
    window.__filmSaisie = v;
    if (window.__filmFetch) { return; }
    window.__filmFetch = window.fetch;
    window.fetch = (u, o) => {
      if (window.__filmSaisie && o && o.method === 'POST' && /\/dashboard\/boite\/(modeles|automatiques)$/.test(String(u))) {
        const b = JSON.parse(o.body);
        if (b.action !== 'supprimer' && !('actif' in b)) { Object.assign(b, window.__filmSaisie); o = Object.assign({}, o, { body: JSON.stringify(b) }); window.__filmSaisie = null; }
      }
      return window.__filmFetch(u, o);
    };
  }, champs);
}

/** La case d'un e-mail de la liste : le label (la case visible), pas l'input caché. */
const coche = uid => 'label.bx-coche:has([data-bxc="' + uid + '"])';

/** Remettre la page en haut, sans à-coup. */
const enHaut = f => f.defiler(-5000);

module.exports = { API, poster, lire, marge, ouverte, cadre, sousLaDiapo, garderSaisie, coche, enHaut };
