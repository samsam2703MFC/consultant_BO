/* Génère les deux maquettes « Caisse : ouvertures, fermetures et reports ».
 *
 *   node docs/maquettes/caisse-reports/generer.js            les pages HTML
 *   node docs/maquettes/caisse-reports/generer.js --captures  + les PNG (Chromium, 1560 px, ×2)
 *
 * Données RÉELLES de Corbais (magasin 2), lues le 09/11/2026 dans TFBuddy avec
 * les comptes du cockpit : les services de caisse (daily-shifts) et l'export
 * comptable du jour (cash-flow). Les employés sont remplacés par des repères. Rien n'est
 * inventé : une donnée que TFBuddy ne rend pas (les retraits) est dite absente.
 */
const fs = require('fs'), path = require('path');
const OUT = __dirname;
const D = JSON.parse(fs.readFileSync(path.join(OUT, 'donnees.json'), 'utf8'));
const DS = '../../../public/assets/ds/global.css';
const LOGO = '../../../public/assets/img/logo.png';

/* --- formats ---------------------------------------------------------------- */
const esc = s => String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
const eur = v => v == null ? '—' : v.toLocaleString('fr-BE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).replace(/ | /g, ' ') + ' €';
const eur0 = v => v == null ? '—' : Math.round(v).toLocaleString('fr-BE').replace(/ | /g, ' ') + ' €';
const hm = ts => ts ? ts.slice(11, 16) : '—';
const JS = ['dim.', 'lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.'];
const JL = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
const dt = j => new Date(j + 'T12:00:00Z');
const jc = j => JS[dt(j).getUTCDay()] + ' ' + (+j.slice(8, 10));             // « lun. 2 »
const jl = j => JL[dt(j).getUTCDay()] + ' ' + (+j.slice(8, 10)) + ' novembre';  // « lundi 2 novembre »
const jcm = j => JS[dt(j).getUTCDay()] + ' ' + (+j.slice(8, 10)) + '/' + (+j.slice(5, 7));
const plus1 = j => { const d = dt(j); d.setUTCDate(d.getUTCDate() + 1); return d.toISOString().slice(0, 10); };
const moins1 = j => { const d = dt(j); d.setUTCDate(d.getUTCDate() - 1); return d.toISOString().slice(0, 10); };

/* --- la semaine regardée ---------------------------------------------------- */
const JOURS = ['2026-11-02', '2026-11-03', '2026-11-04', '2026-11-05', '2026-11-06', '2026-11-07', '2026-11-08'];
const CAISSES = [[11, 'Boulangerie'], [9, 'Traiteur']];
const svc = (c, j) => D.services.filter(s => s.caisse === c && s.jour === j).sort((a, b) => a.ouv.localeCompare(b.ouv));

/* --- les règles de contrôle ---------------------------------------------------
 * Une seule définition, servie aux deux pages : c'est elle qui sera codée. */
const ABERRANT = 1000;   // un fond laissé au-delà de 1 000 € n'est pas un fond
function jourCaisse(c, j) {
  const l = svc(c, j);
  if (!l.length) { return null; }
  const prem = l[0];
  // Le service de NUIT : ouvert après la fermeture du soir, refermé le lendemain.
  const journe = l.filter(s => !(s.ouv.slice(11, 13) >= '17' && s.ferm && s.ferm.slice(0, 10) > s.jour));
  const nuit = l.filter(s => !journe.includes(s));
  const dernierJour = journe[journe.length - 1] || prem;
  const dernier = l[l.length - 1];
  const veille = svc(c, moins1(j));
  const report = veille.length ? veille[veille.length - 1].laisse : null;
  const pts = [];
  if (report != null && prem.fond !== report) {
    pts.push({ g: 'stop', k: 'rupture', t: 'Report rompu : ' + eur(report) + ' laissés, ' + eur(prem.fond) + ' au fond', d: prem.fond - report });
  }
  if (journe.length > 1) {
    const a = journe[0];
    pts.push({ g: 'warn', k: 'reouv', t: 'Fermée à ' + hm(a.ferm) + ' puis rouverte' + (a.fond > 0 && a.final === 0 ? ' : le fond de ' + eur(a.fond) + ' a disparu' : '') });
  }
  if (journe.some(s => s.ferme && s.final === 0)) { pts.push({ g: 'warn', k: 'final0', t: 'Fermeture sans montant (0,00 € déclaré)' }); }
  for (const s of l) {
    if (s.laisse != null && s.laisse > ABERRANT) {
      const x100 = s.final != null && Math.abs(s.laisse - s.final * 100) < 0.5;
      pts.push({ g: 'stop', k: 'aberrant', t: 'Laissé ' + eur(s.laisse) + (x100 ? ' = 100 × le total déclaré : virgule oubliée ?' : ' : montant impossible') });
    }
  }
  for (const s of nuit) {
    pts.push({ g: 'warn', k: 'nuit', t: 'Rouverte à ' + hm(s.ouv) + ' après la fermeture, refermée le ' + jcm(s.ferm.slice(0, 10)) + ' à ' + hm(s.ferm) });
  }
  if (l.some(s => !s.ferme)) { pts.push({ g: 'stop', k: 'ouvert', t: 'Service jamais fermé' }); }
  return { l, prem, dernierJour, dernier, nuit, report, pts };
}
function jourMagasin(j) {
  const cf = D.comptable[j] || {};
  const caisses = CAISSES.map(([c]) => jourCaisse(c, j)).filter(Boolean);
  const fonds = caisses.reduce((a, x) => a + x.prem.fond, 0);
  const aberrant = caisses.some(x => x.pts.some(p => p.k === 'aberrant'));
  const laisse = caisses.reduce((a, x) => a + (x.dernier.laisse || 0), 0);
  const declare = caisses.reduce((a, x) => a + x.l.reduce((b, s) => b + (s.final || 0), 0), 0);
  const versements = (cf['Versement espèces'] || 0) + (cf['Dépots'] || 0);
  const especes = cf['Espèce'] ?? null;
  const sorties = aberrant || especes == null ? null : fonds + especes - laisse - versements;
  return { j, cf, recette: cf.RECETCAIS ?? null, especes, versements, versementsVus: cf['Versement espèces'] != null || cf['Dépots'] != null,
    fonds, laisse, declare, sorties, aberrant,
    titres: (cf.Sodexo || 0) + (cf.Monizze || 0), bancontact: cf.Bancontact ?? null, payonic: cf.Payonic ?? null,
    webshop: (cf.Webshop || 0) + (cf['Webshop (2)'] || 0), caisses };
}
const SEM = JOURS.map(jourMagasin);
const PTS = [];
for (const [c, nom] of CAISSES) {
  for (const j of JOURS) { const x = jourCaisse(c, j); if (x) { x.pts.forEach(p => PTS.push(Object.assign({ c, nom, j }, p))); } }
}
const nb = k => PTS.filter(p => p.k === k).length;
const tot = f => SEM.reduce((a, x) => a + (f(x) || 0), 0);

/* --- le décor commun ---------------------------------------------------------- */
const ICO = {
  tab: 'M4 5h16v14H4zM4 10h16M10 5v14',
  res: 'M4 19h16M7 15l3-4 3 2 4-6',
  caisse: 'M4 9h16v10H4zM7 9V5h10v4M8 14h2M14 14h2',
  inv: 'M5 7h14l-1 12H6zM9 7V5h6v2',
  cmd: 'M6 6h15l-2 8H8zM6 6L5 3H3M9 20a1 1 0 100-2 1 1 0 000 2zM18 20a1 1 0 100-2 1 1 0 000 2z',
  taches: 'M9 11l3 3 8-8M20 12v7H4V5h11',
  visites: 'M12 21s-7-6-7-11a7 7 0 0114 0c0 5-7 11-7 11zM12 12a2 2 0 100-4 2 2 0 000 4z',
};
const svg = d => `<svg class="ico" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="${d}"/></svg>`;
const rail = () => `<nav class="sidebar" aria-label="Navigation principale">
  <div class="sidebar__head"><img class="sidebar__logo" src="${LOGO}" alt="L’Atelier By"><div class="sidebar__kicker">Pilotage réseau</div></div>
  <div class="sidebar__groups">
    <div class="sidebar__group"><div class="sidebar__group-label">Pilotage</div>
      <a class="sidebar__item" href="#">${svg(ICO.tab)}Tableau des magasins</a>
      <a class="sidebar__item" href="#">${svg(ICO.res)}Résultat</a></div>
    <div class="sidebar__group"><div class="sidebar__group-label">Exploitation</div>
      <a class="sidebar__item is-active" aria-current="page" href="#">${svg(ICO.caisse)}Caisse</a>
      <a class="sidebar__item" href="#">${svg(ICO.inv)}Invendus et poubelle</a>
      <a class="sidebar__item" href="#">${svg(ICO.cmd)}Commandes</a></div>
    <div class="sidebar__group"><div class="sidebar__group-label">Terrain</div>
      <a class="sidebar__item" href="#">${svg(ICO.taches)}Contrôle des tâches</a>
      <a class="sidebar__item" href="#">${svg(ICO.visites)}Visites</a></div>
  </div>
  <div class="sidebar__account"><div class="sidebar__account-row"><span class="sidebar__account-who">Sam Verheyden</span><span class="sidebar__account-role">Stratégie et Développement</span></div></div>
</nav>`;

const CSS = `
html,body{height:100%}
.workspace{display:flex;height:100vh;overflow:hidden}
.workspace__main{flex:1;display:flex;flex-direction:column;min-width:0}
.app__body{flex:1;overflow-y:auto;padding:22px 24px 40px}
.mq-u{display:inline-block;font-size:11px;color:var(--prize-ink);background:var(--prize-soft);border-radius:4px;padding:3px 9px;margin:0 0 14px}
.tb-ctl{display:flex;align-items:center;gap:8px}
.tb-ctl .sel{font:400 12.5px 'Gotham',system-ui,sans-serif;border:1px solid var(--line);border-radius:8px;padding:7px 12px;background:#fff;color:var(--ink)}
.tb-ctl .per{font-size:13px;color:var(--ink);padding:0 4px;min-width:150px;text-align:center}
.kpi-row{background:#fff;border:1px solid var(--line);border-radius:12px;margin:0 0 16px}
.kpi.stop{border-left-color:var(--stop)} .kpi.warn{border-left-color:var(--prize)} .kpi.ok{border-left-color:var(--green)}
.kpi__value small{font-size:13px;font-weight:400;color:var(--muted);margin-left:6px;letter-spacing:0}
h2{font-size:.95rem;font-weight:400;margin:0 0 .5rem;text-transform:uppercase;letter-spacing:.06em;color:var(--muted)}
.blk{margin:0 0 16px}
.blk__hd{display:flex;align-items:baseline;justify-content:space-between;gap:12px;margin:0 0 10px}
.blk__hd p{margin:0;font-size:12px;color:var(--muted);max-width:820px;line-height:1.5}
.tag-warn,.tag-stop,.tag-ok{display:inline-flex;align-items:center;font-size:11px;padding:2px 7px;border-radius:4px;white-space:nowrap}
.tag-ok{background:rgba(63,122,82,.12);color:var(--green)}
.tag-warn{background:var(--prize-soft);color:var(--prize-ink)}
.tag-stop{background:var(--stop-soft);color:var(--stop)}
.nul{color:var(--faint)}

/* La chaîne : une rangée par caisse, un bloc par jour, le report entre deux blocs. */
.ch{display:grid;grid-template-columns:104px repeat(7,minmax(0,1fr));column-gap:16px;align-items:stretch;margin:0 0 6px}
.ch__lab{display:flex;flex-direction:column;justify-content:center;font-size:13px;color:var(--ink)}
.ch__lab small{font-size:10.5px;color:var(--muted);margin-top:2px}
.ch__lab .rep0{margin-top:10px;font-size:11px;color:var(--muted)}
.jr{position:relative;background:#fff;border:1px solid var(--line);border-radius:12px;padding:10px 10px 9px;font-size:11.5px;min-width:0;display:flex;flex-direction:column;gap:5px}
.jr.stop{border-color:#E7B7AF;box-shadow:inset 0 3px 0 var(--stop)}
.jr.warn{box-shadow:inset 0 3px 0 var(--prize)}
.jr__hd{display:flex;justify-content:space-between;align-items:baseline;gap:6px;white-space:nowrap;font-size:10.5px;color:var(--muted);text-transform:uppercase;letter-spacing:.06em}
.jr__hd b{font-weight:400;color:var(--ink)} .jr__hd a{color:var(--accent);text-decoration:none}
.jr__l{display:flex;justify-content:space-between;gap:6px;align-items:baseline}
.jr__l span{color:var(--muted)} .jr__l b{font-weight:400;font-variant-numeric:tabular-nums;color:var(--ink);white-space:nowrap}
.jr__l b.z{color:var(--prize-ink)} .jr__l b.x{color:var(--stop);font-weight:700}
.jr__p{font-size:10.5px;color:var(--muted);line-height:1.35}
.jr__pts{display:flex;flex-direction:column;gap:3px;margin-top:2px}
.jr__pts .tag-warn,.jr__pts .tag-stop{white-space:normal;line-height:1.3;font-size:10.5px}
/* le report, dans l'intervalle à gauche du bloc */
.lk{position:absolute;left:-17px;top:42px;width:18px;display:flex;flex-direction:column;align-items:center;gap:2px;font-size:10px}
.lk i{display:block;width:18px;height:20px;line-height:20px;text-align:center;border-radius:5px;font-style:normal;font-size:11px}
.lk i.ok{background:rgba(63,122,82,.12);color:var(--green)} .lk i.ko{background:var(--stop-soft);color:var(--stop)}
.ch-hd{display:grid;grid-template-columns:150px repeat(7,minmax(0,1fr));column-gap:22px;margin:0 0 6px;font-size:10.5px;text-transform:uppercase;letter-spacing:.06em;color:var(--muted)}
.ch-hd a{color:var(--accent);text-decoration:none}

/* Le tableau du magasin, aligné sur les mêmes colonnes */
table.mg{width:100%;border-collapse:collapse;font-size:12.5px;table-layout:fixed}
table.mg th,table.mg td{padding:8px 10px;text-align:right;font-variant-numeric:tabular-nums}
table.mg th:first-child,table.mg td:first-child{text-align:left;width:208px}
table.mg thead th{font-weight:400;font-size:10.5px;letter-spacing:.06em;text-transform:uppercase;color:var(--muted);background:var(--table-head)}
table.mg tbody td{border-top:1px solid var(--row-line)}
table.mg td:first-child small{display:block;font-size:10.5px;color:var(--muted);margin-top:1px}
table.mg tr.tot td{background:var(--total-line)}
table.mg tr.cle td{background:var(--stop-soft)} table.mg tr.cle td:first-child{color:var(--stop)}
table.mg td.sem,table.mg th.sem{border-left:1px solid var(--line)}
table.mg tr.sep td{padding:4px 10px;font-size:10px;letter-spacing:.14em;text-transform:uppercase;color:var(--faint);background:#fff;text-align:left}

.cols{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;margin:0 0 16px}
.cols3{display:grid;grid-template-columns:1.25fr 1fr;gap:16px;margin:0 0 16px}
.card h3{font-size:15px;font-weight:400;margin:0 0 2px;color:var(--ink)}
.card .sous{font-size:11.5px;color:var(--muted);margin:0 0 12px}
.lst{list-style:none;margin:0;padding:0;font-size:12.5px}
.lst li{display:grid;grid-template-columns:86px 1fr auto;gap:10px;padding:8px 0;border-top:1px solid var(--row-line);align-items:baseline}
.lst li:first-child{border-top:0}
.lst li .q{color:var(--muted);font-size:11.5px}
.lst li a{color:var(--accent);text-decoration:none;font-size:12px;white-space:nowrap}
.src{font-size:10.5px;color:var(--muted);margin:10px 0 0;line-height:1.5}
.src code{font-size:10.5px;background:var(--chip);padding:1px 4px;border-radius:4px}

/* Page Jour */
.alerte{display:flex;gap:12px;align-items:flex-start;background:var(--stop-soft);border:1px solid #E7B7AF;border-radius:12px;padding:12px 16px;margin:0 0 16px;font-size:12.5px;color:var(--ink)}
.alerte b{font-weight:700;color:var(--stop)} .alerte ul{margin:4px 0 0;padding-left:18px;line-height:1.6}
.tl{position:relative;margin:4px 0 0}
.tl__ax{position:relative;height:16px;margin-left:150px;font-size:10px;color:var(--muted)}
.tl__ax span{position:absolute;transform:translateX(-50%)}
.tl__r{display:flex;align-items:center;height:34px;border-top:1px solid var(--row-line)}
.tl__n{width:150px;font-size:12.5px} .tl__n small{display:block;font-size:10.5px;color:var(--muted)}
.tl__t{position:relative;flex:1;height:100%}
.tl__t .g{position:absolute;top:0;bottom:0;border-left:1px dashed var(--line)}
.tl__t .b{position:absolute;top:8px;height:18px;border-radius:5px;font-size:10.5px;line-height:18px;padding:0 7px;color:#fff;background:var(--chart-real);white-space:nowrap;overflow:hidden}
.tl__t .b.n{background:repeating-linear-gradient(135deg,var(--prize) 0 6px,#C9934A 6px 12px)}
.tl__t .fer{position:absolute;top:4px;bottom:4px;background:rgba(34,34,34,.04);border-left:1px solid var(--line);border-right:1px solid var(--line)}
.fiche{display:grid;grid-template-columns:118px 1fr auto;gap:2px 12px;font-size:12.5px;align-items:baseline}
.fiche > *{padding:7px 0;border-top:1px solid var(--row-line)}
.fiche .h{color:var(--muted);font-size:11.5px}
.fiche .m{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
.fiche .m.x{color:var(--stop);font-weight:700} .fiche .m.z{color:var(--prize-ink)}
.fiche .note{grid-column:2 / span 2;border-top:0;padding-top:0}
.fiche .note .tag-warn,.fiche .note .tag-stop{white-space:normal;line-height:1.35}
.fiche .t1{border-top:0}
.wf{display:grid;grid-template-columns:22px 1fr auto;gap:0 10px;font-size:12.5px;align-items:baseline}
.wf > *{padding:8px 0;border-top:1px solid var(--row-line)}
.wf .op{color:var(--muted);text-align:center}
.wf .m{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
.wf .s{display:block;font-size:10.5px;color:var(--muted);margin-top:2px}
.wf .eg{font-weight:700} .wf .r{background:var(--total-line)}
.wf .na{color:var(--muted);font-style:italic}
.mp{width:100%;border-collapse:collapse;font-size:12.5px}
.mp td{padding:7px 0;border-top:1px solid var(--row-line)} .mp td.m{text-align:right;font-variant-numeric:tabular-nums}
.mp tr.tot td{background:var(--total-line);padding-left:6px;padding-right:6px}
.bar{display:inline-block;height:8px;border-radius:2px;background:var(--chart-real);vertical-align:middle;margin-right:6px}
.bar.es{background:var(--chart-goal)}
`;

const page = (titre, sousTitre, actif, corps) => `<!DOCTYPE html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>${esc(titre)} — maquette Caisse</title>
<link rel="stylesheet" href="${DS}">
<style>${CSS}</style></head>
<body><div class="workspace">${rail()}
<div class="workspace__main">
<header class="topbar">
  <div><p class="topbar__kicker">Exploitation · Corbais</p><h1>Caisse</h1></div>
  <div class="topbar__actions tb-ctl">
    <select class="sel" aria-label="Magasin"><option>Corbais</option></select>
    <div class="seg" role="tablist"><button class="${actif === 'semaine' ? 'is-on' : ''}">Semaine</button><button class="${actif === 'jour' ? 'is-on' : ''}">Jour</button></div>
    <button class="btn btn--sm btn--icon" aria-label="Précédent">‹</button><span class="per">${esc(sousTitre)}</span><button class="btn btn--sm btn--icon" aria-label="Suivant">›</button>
    <button class="btn btn--sm">Exporter</button>
  </div>
</header>
<main class="app__body">
<span class="mq-u">maquette · données réelles de Corbais lues dans TFBuddy le 09/11/2026 · employés remplacés par des repères</span>
${corps}
</main></div></div></body></html>`;

/* ============================== A. LA SEMAINE ================================ */
function blocJour(c, j, x) {
  if (!x) { return `<div class="jr"><div class="jr__hd"><b>${jc(j)}</b></div><div class="jr__p">aucun service</div></div>`; }
  const grave = x.pts.some(p => p.g === 'stop') ? 'stop' : (x.pts.length ? 'warn' : '');
  const rupt = x.pts.find(p => p.k === 'rupture');
  const lk = x.report == null ? '' : `<div class="lk" title="Report de la veille : ${eur(x.report)} laissés · fond d’ouverture ${eur(x.prem.fond)}">${rupt ? `<i class="ko">✗</i>` : `<i class="ok">✓</i>`}</div>`;
  const decl = x.l.filter(s => !x.nuit.includes(s)).reduce((a, s) => a + (s.final || 0), 0);
  const dern = x.dernierJour;
  const laisseX = dern.laisse != null && dern.laisse > ABERRANT;
  return `<div class="jr ${grave}">${lk}
    <div class="jr__hd"><a href="b-jour.html">${jc(j)}</a><span>${x.l.length > 1 ? x.l.length + ' services' : ''}</span></div>
    <div class="jr__p">ouv. ${hm(x.prem.ouv)} · ${esc(x.prem.par_ouv)}</div>
    <div class="jr__l"><span>Fond</span><b class="${rupt ? 'x' : ''}">${eur(x.prem.fond)}</b></div>
    <div class="jr__l"><span>Déclaré</span><b class="${decl === 0 ? 'z' : ''}">${eur(decl)}</b></div>
    <div class="jr__l"><span>Laissé</span><b class="${laisseX ? 'x' : ''}">${eur(dern.laisse)}</b></div>
    <div class="jr__p">ferm. ${hm(dern.ferm)} · ${esc(dern.par_ferm)}</div>
    ${x.pts.length ? `<div class="jr__pts">${x.pts.map(p => `<span class="tag-${p.g}">${esc(p.t)}</span>`).join('')}</div>` : ''}
  </div>`;
}
function chaine(c, nom) {
  const dim = svc(c, moins1(JOURS[0]));
  const rep0 = dim.length ? dim[dim.length - 1].laisse : null;
  return `<div class="ch">
    <div class="ch__lab">${esc(nom)}<small>caisse ${c}</small><span class="rep0">laissé ${jc(moins1(JOURS[0]))} : ${eur(rep0)}</span></div>
    ${JOURS.map(j => blocJour(c, j, jourCaisse(c, j))).join('')}
  </div>`;
}
function tableMagasin() {
  const td = (f, cls = '') => SEM.map(x => `<td class="${cls}">${f(x)}</td>`).join('');
  const pct = x => x.recette ? Math.round(100 * x.declare / x.recette) + ' %' : '—';
  const sortiesTot = SEM.filter(x => x.sorties != null);
  return `<table class="mg"><thead><tr><th>Le magasin</th>${SEM.map(x => `<th>${jc(x.j)}</th>`).join('')}<th class="sem">Semaine</th></tr></thead><tbody>
  <tr class="sep"><td colspan="9">Encaissé · export comptable TFBuddy</td></tr>
  <tr class="tot"><td>Recette du jour<small>compte 400000 · RECETCAIS</small></td>${td(x => eur(x.recette))}<td class="sem">${eur(tot(x => x.recette))}</td></tr>
  <tr><td>Bancontact</td>${td(x => eur(x.bancontact))}<td class="sem">${eur(tot(x => x.bancontact))}</td></tr>
  <tr><td>Espèces<small>compte 580012</small></td>${td(x => eur(x.especes))}<td class="sem">${eur(tot(x => x.especes))}</td></tr>
  <tr><td>Titres-repas<small>Sodexo + Monizze</small></td>${td(x => eur(x.titres))}<td class="sem">${eur(tot(x => x.titres))}</td></tr>
  <tr><td>Payonic · Webshop</td>${td(x => eur((x.payonic || 0) + x.webshop))}<td class="sem">${eur(tot(x => (x.payonic || 0) + x.webshop))}</td></tr>
  <tr class="sep"><td colspan="9">Les espèces · du fond du matin au fond du soir</td></tr>
  <tr><td>Fonds d’ouverture<small>les deux caisses</small></td>${td(x => eur(x.fonds))}<td class="sem">${eur(tot(x => x.fonds))}</td></tr>
  <tr><td>+ Espèces encaissées</td>${td(x => eur(x.especes))}<td class="sem">${eur(tot(x => x.especes))}</td></tr>
  <tr><td>− Retraits<small>Course · Caution · Remboursement client</small></td>${td(() => '<span class="nul">non lisibles</span>')}<td class="sem"><span class="nul">—</span></td></tr>
  <tr><td>− Versements · dépôts<small>comptes 580000</small></td>${td(x => x.versements ? eur(x.versements) : '<span class="nul">aucun</span>')}<td class="sem">${eur(tot(x => x.versements))}</td></tr>
  <tr><td>− Laissé pour demain</td>${td(x => x.aberrant ? '<span class="tag-stop">aberrant</span>' : eur(x.laisse))}<td class="sem"><span class="nul">—</span></td></tr>
  <tr class="cle"><td>= Sorti du tiroir sans trace<small>ni versement ni retrait enregistré</small></td>${td(x => x.sorties == null ? '<span class="tag-stop">non calculable</span>' : eur(x.sorties))}<td class="sem">${eur(tot(x => x.sorties))}<br><small class="nul">${sortiesTot.length} j. sur 7</small></td></tr>
  <tr class="sep"><td colspan="9">Ce que les caissières déclarent à la fermeture</td></tr>
  <tr><td>Total déclaré<small>final_cash, les deux caisses</small></td>${td(x => x.declare === 0 ? '<span class="tag-warn">0,00 €</span>' : eur(x.declare))}<td class="sem">${eur(tot(x => x.declare))}</td></tr>
  <tr><td>… en % de la recette</td>${td(x => x.declare === 0 ? '<span class="nul">—</span>' : pct(x))}<td class="sem"><span class="nul">—</span></td></tr>
  </tbody></table>`;
}
function listePoints() {
  const ordre = { stop: 0, warn: 1 };
  const l = PTS.slice().sort((a, b) => a.j.localeCompare(b.j) || ordre[a.g] - ordre[b.g]);
  return `<ul class="lst">${l.map(p => `<li><span class="q">${jc(p.j)} · ${esc(p.nom)}</span><span><span class="tag-${p.g}">${p.g === 'stop' ? 'À corriger' : 'À vérifier'}</span> ${esc(p.t)}</span><a href="b-jour.html">ouvrir le jour ›</a></li>`).join('')}</ul>`;
}
function pageSemaine() {
  const sorties = tot(x => x.sorties);
  const jSorties = SEM.filter(x => x.sorties != null).length;
  const recette = tot(x => x.recette), especes = tot(x => x.especes);
  const nStop = PTS.filter(p => p.g === 'stop').length;
  const corps = `
<div class="kpi-row">
  <div class="kpi"><div class="kpi__value">${eur0(recette)}</div><div class="kpi__label">Recette de la semaine</div><div class="kpi__note">export comptable · 7 jours</div></div>
  <div class="kpi"><div class="kpi__value">${eur(especes)}<small>${(100 * especes / recette).toFixed(1).replace('.', ',')} %</small></div><div class="kpi__label">Espèces encaissées</div><div class="kpi__note">le reste en carte et titres-repas</div></div>
  <div class="kpi stop"><div class="kpi__value">${eur(tot(x => x.versements))}</div><div class="kpi__label">Versements enregistrés</div><div class="kpi__note">aucun versement ni dépôt sur 7 jours</div></div>
  <div class="kpi stop"><div class="kpi__value">${eur(sorties)}</div><div class="kpi__label">Sorti du tiroir sans trace</div><div class="kpi__note">${jSorties} jours sur 7 · le ${jc(JOURS[6])} non calculable</div></div>
  <div class="kpi warn"><div class="kpi__value">${PTS.length}<small>dont ${nStop} à corriger</small></div><div class="kpi__label">Points à vérifier</div><div class="kpi__note">${nb('rupture')} reports rompus · ${nb('final0')} fermetures à 0 · ${nb('aberrant')} montants impossibles</div></div>
</div>

<section class="blk">
  <div class="blk__hd"><h2>La chaîne des reports, caisse par caisse</h2>
    <p>Chaque soir, ce qui est <b>laissé</b> dans le tiroir doit se retrouver le lendemain comme <b>fond</b> d’ouverture. Entre deux jours, ✓ : le fond suit ; ✗ : la chaîne est rompue.</p></div>
  ${CAISSES.map(([c, n]) => chaine(c, n)).join('')}
</section>

<section class="card blk" style="padding:0;overflow:hidden">
  ${tableMagasin()}
</section>

<div class="cols3">
  <section class="card"><h3>Ce qu’il faut regarder</h3><p class="sous">${PTS.length} points sur la semaine, dans l’ordre des jours. Un point se clôt quand le franchisé a corrigé la saisie ou donné la raison.</p>${listePoints()}</section>
  <section class="card"><h3>Ce que la semaine dit</h3><p class="sous">Lu dans les chiffres ci-contre, pas supposé.</p>
    <ul>
      <li><b>Le fond de caisse n’est pas tenu.</b> Il vaut 12,00 € un jour, 0,00 € le lendemain, sans que rien ne soit laissé ni retiré : ${nb('rupture')} reports rompus en 7 jours.</li>
      <li><b>« Déclaré » n’est pas l’espèce comptée.</b> Les jours où il est saisi, il fait ${SEM.filter(x => x.declare > 0).map(x => Math.round(100 * x.declare / x.recette) + ' %').join(', ')} de la recette — or les espèces n’en font que ${(100 * especes / recette).toFixed(0)} %. Les caissières reportent un total de caisse, pas le comptage du tiroir.</li>
      <li><b>Les espèces partent sans trace.</b> ${eur(sorties)} encaissés en liquide sont sortis des tiroirs sur ${jSorties} jours, sans un seul versement ni retrait enregistré.</li>
      <li><b>Dimanche 8, deux saisies impossibles</b> (laissé 100 × le déclaré) et une caisse rouverte après la fermeture.</li>
    </ul>
    <p class="src">Sources : services <code>GET /shops/2/owner/daily-shifts?date=</code>, caisse de chaque service <code>GET /daily-shifts/{id}</code> ; recette et moyens de paiement <code>GET /shops/2/reports/sales/daily/cash-flow/csv?date=</code>. Les retraits s’enregistrent (<code>POST /devices/{id}/cash-withdrawals</code>) mais ne se relisent par aucune route : à demander à TFBuddy.</p>
  </section>
</div>`;
  return page('A · Semaine — la chaîne des reports', '2 – 8 novembre 2026', 'semaine', corps);
}

/* ============================== B. LE JOUR =================================== */
const JOUR = '2026-11-08';
function timeline() {
  // 04:00 → 08:00 le lendemain : 28 heures, pour voir le service de nuit en entier.
  const H0 = 4, H1 = 32, w = h => (100 * (h - H0) / (H1 - H0)).toFixed(2) + '%';
  const hh = (ts, base) => { const d = ts.slice(0, 10) > base ? 24 : 0; return d + (+ts.slice(11, 13)) + (+ts.slice(14, 16)) / 60; };
  const ax = [4, 6, 8, 10, 12, 14, 16, 18, 20, 22, 24, 26, 28, 30, 32].map(h => `<span style="left:${w(h)}">${String(h % 24).padStart(2, '0')}h</span>`).join('');
  const grilles = [6, 8, 10, 12, 14, 16, 18, 20, 22, 24, 26, 28, 30].map(h => `<i class="g" style="left:${w(h)}"></i>`).join('');
  const fer = `<i class="fer" style="left:${w(19)};width:calc(${w(29.5)} - ${w(19)})" title="magasin fermé"></i>`;
  return `<div class="tl"><div class="tl__ax">${ax}</div>
  ${CAISSES.map(([c, n]) => {
    const l = svc(c, JOUR);
    return `<div class="tl__r"><div class="tl__n">${esc(n)}<small>caisse ${c}</small></div><div class="tl__t">${grilles}${fer}
      ${l.map(s => { const a = hh(s.ouv, JOUR), b = s.ferm ? hh(s.ferm, JOUR) : H1; const nuit = s.ferm && s.ferm.slice(0, 10) > JOUR;
        return `<span class="b${nuit ? ' n' : ''}" style="left:${w(a)};width:calc(${w(b)} - ${w(a)})" title="service ${s.id}">${hm(s.ouv)} → ${hm(s.ferm)}${nuit ? ' (lun.)' : ''} · fond ${eur(s.fond)}</span>`; }).join('')}
    </div></div>`; }).join('')}
  </div>`;
}
function ficheCaisse(c, nom) {
  const x = jourCaisse(c, JOUR);
  const lend = svc(c, plus1(JOUR))[0];
  const rupt = x.pts.find(p => p.k === 'rupture');
  const lignes = [];
  lignes.push(`<span class="h t1">${jcm(moins1(JOUR))}</span><span class="t1">Laissé la veille au soir</span><span class="m t1">${eur(x.report)}</span>`);
  x.l.forEach((s, i) => {
    const nuit = x.nuit.includes(s);
    const x100 = s.laisse > ABERRANT && Math.abs(s.laisse - s.final * 100) < 0.5;
    lignes.push(`<span class="h">${hm(s.ouv)}</span><span>${nuit ? '<span class="tag-warn">Rouverte après la fermeture</span> ' : ''}Ouverture · ${esc(s.par_ouv)}</span><span class="m${i === 0 && rupt ? ' x' : ''}">fond ${eur(s.fond)}</span>`);
    if (i === 0) {
      lignes.push(rupt
        ? `<span class="note"><span class="tag-stop">${esc(rupt.t)}</span></span>`
        : `<span class="note"><span class="tag-ok">✓ suit le laissé de la veille</span></span>`);
    }
    lignes.push(`<span class="h">${hm(s.ferm)}${s.ferm && s.ferm.slice(0, 10) > JOUR ? ' <small>lun.</small>' : ''}</span><span>Fermeture · ${esc(s.par_ferm)} — total déclaré</span><span class="m${s.final === 0 ? ' z' : ''}">${eur(s.final)}</span>`);
    lignes.push(`<span class="h"></span><span>Laissé pour le lendemain</span><span class="m${s.laisse > ABERRANT ? ' x' : ''}">${eur(s.laisse)}</span>`);
    if (x100) { lignes.push(`<span class="note"><span class="tag-stop">100 × le total déclaré : virgule oubliée ? Attendu : le fond, 12,00 €</span></span>`); }
    if (nuit) { lignes.push(`<span class="note"><span class="tag-warn">Ouverte ${hm(s.ouv)} → ${hm(s.ferm)} le lendemain, fond ${eur(s.fond)}, rien déclaré : qui, et pourquoi ?</span></span>`); }
  });
  if (lend) {
    const ok = lend.fond === x.dernier.laisse;
    lignes.push(`<span class="h">${jcm(lend.jour)}</span><span>Ouverture du lendemain · ${esc(lend.par_ouv)}</span><span class="m${ok ? '' : ' x'}">fond ${eur(lend.fond)}</span>`);
    lignes.push(`<span class="note">${ok ? '<span class="tag-ok">✓ suit</span>' : `<span class="tag-stop">Ne suit pas le laissé (${eur(x.dernier.laisse)})</span>`}</span>`);
  }
  const g = x.pts.some(p => p.g === 'stop');
  return `<section class="card"><h3>${esc(nom)} <span class="${g ? 'tag-stop' : 'tag-warn'}" style="margin-left:6px">${x.pts.length} point${x.pts.length > 1 ? 's' : ''}</span></h3><p class="sous">caisse ${c} · ${x.l.length} service${x.l.length > 1 ? 's' : ''} ce jour-là</p>
    <div class="fiche">${lignes.join('')}</div></section>`;
}
function pageJour() {
  const m = jourMagasin(JOUR), cf = m.cf;
  const pts = PTS.filter(p => p.j === JOUR);
  const mp = [['Bancontact', cf.Bancontact, ''], ['Espèces', cf['Espèce'], 'es'], ['Sodexo', cf.Sodexo, ''], ['Monizze', cf.Monizze, ''], ['Payonic', cf.Payonic, ''], ['Webshop', (cf.Webshop || 0) + (cf['Webshop (2)'] || 0), '']];
  const max = Math.max(...mp.map(r => r[1] || 0));
  const corps = `
<div class="alerte"><span>⚠</span><div><b>${pts.length} points à vérifier ce ${jl(JOUR)}</b>
  <ul>${pts.map(p => `<li>${esc(p.nom)} — ${esc(p.t)}</li>`).join('')}</ul></div></div>

<section class="card blk"><h3>Les services de la journée</h3><p class="sous">Une barre par service ouvert sur la caisse ; la zone grisée est le magasin fermé. Hachuré : un service ouvert après la fermeture.</p>
  ${timeline()}</section>

<div class="cols">${CAISSES.map(([c, n]) => ficheCaisse(c, n)).join('')}</div>

<div class="cols">
  <section class="card"><h3>Les espèces, du fond du matin au fond du soir</h3><p class="sous">Le magasin, les deux caisses ensemble.</p>
    <div class="wf">
      <span class="op"></span><span>Fonds d’ouverture<span class="s">12,00 € + 12,00 €, saisis à l’ouverture</span></span><span class="m">${eur(m.fonds)}</span>
      <span class="op">+</span><span>Espèces encaissées<span class="s">export comptable, compte 580012</span></span><span class="m">${eur(m.especes)}</span>
      <span class="op">−</span><span>Retraits<span class="s">Course · Caution · Remboursement client — enregistrés à la caisse, illisibles par l’API</span></span><span class="m na">non lisibles</span>
      <span class="op">−</span><span>Versements espèces · dépôts<span class="s">comptes 580000</span></span><span class="m na">aucun</span>
      <span class="op r">=</span><span class="r eg">Attendu dans les tiroirs au soir</span><span class="m r eg">${eur(m.fonds + m.especes)}</span>
      <span class="op"></span><span>Laissé pour le lendemain, déclaré</span><span class="m" style="color:var(--stop);font-weight:700">${eur(m.caisses.reduce((a, x) => a + x.l.reduce((b, s) => b + (s.laisse || 0), 0), 0))}</span>
      <span class="op"></span><span><span class="tag-stop">Écart non calculable</span><span class="s">les deux « laissé » sont des saisies impossibles. Sans comptage réel du tiroir, la journée ne se boucle pas.</span></span><span></span>
    </div></section>
  <section class="card"><h3>Les moyens de paiement</h3><p class="sous">Export comptable TFBuddy, ${jl(JOUR)}.</p>
    <table class="mp">${mp.map(r => `<tr><td><span class="bar ${r[2]}" style="width:${max ? Math.max(2, Math.round(110 * (r[1] || 0) / max)) : 0}px"></span>${esc(r[0])}</td><td class="m">${eur(r[1])}</td></tr>`).join('')}
      <tr class="tot"><td>Recette du jour</td><td class="m">${eur(m.recette)}</td></tr>
      <tr><td>Total déclaré aux fermetures</td><td class="m">${eur(m.declare)} <span class="nul">· ${Math.round(100 * m.declare / m.recette)} %</span></td></tr>
    </table>
    <p class="src">Le total déclaré suit la <b>recette</b> (${Math.round(100 * m.declare / m.recette)} %), pas les <b>espèces</b> (${eur(m.especes)}) : <code>final_cash</code> n’est pas le comptage du tiroir. Il faudra soit l’appeler « total de caisse » et demander un comptage à part, soit former les équipes à y mettre l’espèce comptée.</p>
  </section>
</div>`;
  return page('B · Jour — ' + jl(JOUR), jl(JOUR) + ' 2026', 'jour', corps);
}

/* --- écrire, et capturer si demandé ----------------------------------------- */
const pages = { 'a-semaine': pageSemaine(), 'b-jour': pageJour() };
for (const [n, h] of Object.entries(pages)) { fs.writeFileSync(path.join(OUT, n + '.html'), h); }
console.log('pages :', Object.keys(pages).join(', '), '·', PTS.length, 'points sur la semaine');

if (process.argv.includes('--captures')) {
  (async () => {
    const { chromium } = require('playwright');
    const b = await chromium.launch();
    const p = await b.newPage({ viewport: { width: 1560, height: 1000 }, deviceScaleFactor: 2 });
    for (const n of Object.keys(pages)) {
      await p.setViewportSize({ width: 1560, height: 1000 });
      await p.goto('file://' + path.join(OUT, n + '.html'));
      await p.evaluate(() => document.fonts.ready);
      // Toute la page : le contenu défile dans .app__body, on le déplie le temps de la capture.
      const h = await p.evaluate(() => { const m = document.querySelector('.app__body'); return m.scrollHeight + document.querySelector('.topbar').offsetHeight; });
      await p.setViewportSize({ width: 1560, height: Math.max(1000, h + 2) });
      await p.screenshot({ path: path.join(OUT, n + '.png'), fullPage: false });
    }
    await b.close();
    console.log('captures : ok');
  })();
}
