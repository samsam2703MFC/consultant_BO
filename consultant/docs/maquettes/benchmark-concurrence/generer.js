/* Maquette — dashboard magasin, nouvel onglet « Concurrence » : le benchmark
 * saisonnier du magasin face à ses concurrents (prix comparable, échelle,
 * relevés autour du magasin, visibilité web, à faire).
 *
 * RÉEL : les relevés du 7/11/2026 (rapport_cougnou.json, rapport_galette.json,
 * produits par src/benchmark.php : php bin/benchmark.php rapport …), nos prix
 * (atelierby.be, panel de Halle), les contrôles web lus sur atelierby.be, la
 * coque du dashboard (../canaux-offres/contexte-dashboard.html).
 *
 *   node docs/maquettes/benchmark-concurrence/generer.js   (serveur statique sur 8099 depuis la racine)
 */
const fs = require('fs');
const { chromium } = require('playwright');
const OUT = __dirname + '/';
const SHOP = 4, VILLE = 'Halle';
const esc = s => String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
const nf = (n, d = 2) => Number(n).toLocaleString('fr-BE', { minimumFractionDigits: d, maximumFractionDigits: d });
const eur = n => n == null ? '—' : nf(n) + ' €';
const pct = n => n == null ? '—' : (n > 0 ? '+' : n < 0 ? '−' : '') + nf(Math.abs(n), 0) + ' %';

const ctx = fs.readFileSync(__dirname + '/../canaux-offres/contexte-dashboard.html', 'utf8');
// La coque : en-tête et barre d'onglets du vrai dashboard, avec l'onglet ajouté.
const hd = ctx.slice(ctx.indexOf('<div class="db-hd">'), ctx.indexOf('<div class="db-ncbar'))
  .replace('vendredi 2 octobre 2026', 'samedi 7 novembre 2026')
  .replace(/value="2026-10-02" max="2026-10-03"/, 'value="2026-11-07" max="2026-11-07"')
  .replace(' class="on">Jour', ' class="">Jour')
  .replace('<button data-vue="actions" class="">Plan d’action</button>', '<button data-vue="actions" class="">Plan d’action</button><button data-vue="concurrence" class="on">Concurrence</button>');

function page(fam) {
  const R = JSON.parse(fs.readFileSync(OUT + 'rapport_' + fam + '.json', 'utf8'));
  const diam = R.mesure === 'diametre';
  const u = diam ? '€ à Ø 24 cm' : '€/kg';
  const ref = R.ref;
  const nous = R.nous.find(p => p.reference);
  const autres = R.nous.filter(p => !p.reference && p.prix > 0);
  const med = t => (R.par_type.find(x => x.type === t) || {});
  const lignes = R.concurrents.filter(l => l.valeur != null);
  const ici = R.concurrents.filter(l => (l.magasins || []).includes(SHOP));
  const web = R.web;

  // --- tuiles -------------------------------------------------------------------
  const tui = (k, v, s, cls = '') => `<div class="db-tui ${cls}"><div class="k">${k}</div><div class="v">${v}</div><div class="s">${s}</div></div>`;
  const tuiles = [
    tui('Notre ' + (diam ? 'galette' : 'cougnou'), eur(ref), `${eur(nous.prix)} · ${diam ? 'Ø 24 cm' : '≈ ' + R.taille_ref + ' g <span class="wa">à confirmer</span>'} · ${u}`, 'vif'),
    ...['GMS', 'Artisan', 'Chaîne'].map(t => { const m = med(t); return tui('Médiane ' + ({ GMS: 'supermarchés', Artisan: 'artisans', 'Chaîne': 'chaînes' })[t], eur(m.mediane),
      `${m.n || 0} relevé(s) · nous <b class="${m.ecart_pct > 0 ? 'ko' : 'ok'}">${pct(m.ecart_pct)}</b>`); }),
    tui('Moins chers que nous', `${R.position.moins_chers} <small style="font-size:13px" class="mu">/ ${R.position.sur}</small>`, diam ? 'tous des supermarchés' : '7 supermarchés et 1 artisan en promo'),
    tui('Visibilité web', `${web.ok} <small style="font-size:13px" class="mu">/ ${web.total}</small>`, 'contrôles atelierby.be passés · lu le 07/11', web.ok / web.total < 0.5 ? '' : 'bon'),
  ].join('');

  // --- échelle : une puce par relevé, notre prix en trait ---------------------------
  const vals = lignes.map(l => l.valeur), max = Math.ceil(Math.max(...vals, ref) / 5) * 5, min = 0;
  const W = 1180, H = 92, x = v => 40 + (v - min) / (max - min) * (W - 60);
  const coul = { GMS: '#B8B2A8', Artisan: '#B0792A', 'Chaîne': '#5c5852' };
  let pts = '', ty = { GMS: 30, Artisan: 50, 'Chaîne': 70 };
  lignes.forEach(l => { pts += `<circle cx="${x(l.valeur).toFixed(1)}" cy="${ty[l.type] || 50}" r="5.5" fill="${coul[l.type] || '#999'}" fill-opacity=".85"><title>${esc(l.concurrent + ' — ' + l.produit + ' : ' + eur(l.valeur))}</title></circle>`; });
  let ticks = '';
  for (let v = 0; v <= max; v += 5) ticks += `<line x1="${x(v)}" x2="${x(v)}" y1="16" y2="80" stroke="#e6e0d8"/><text x="${x(v)}" y="92" font-size="10" text-anchor="middle" fill="#7a736a">${v} €</text>`;
  const svg = `<svg viewBox="0 0 ${W} ${H + 8}" width="100%" style="display:block">${ticks}
    ${Object.entries(ty).map(([t, y]) => `<text x="0" y="${y + 4}" font-size="10" fill="#7a736a">${t === 'GMS' ? 'Superm.' : t === 'Artisan' ? 'Artisans' : 'Chaînes'}</text>`).join('')}
    ${pts}
    <line x1="${x(ref)}" x2="${x(ref)}" y1="10" y2="82" stroke="#8D1D2C" stroke-width="2.5"/>
    <rect x="${x(ref) - 34}" y="0" width="68" height="15" rx="3" fill="#8D1D2C"/><text x="${x(ref)}" y="11" font-size="10" text-anchor="middle" fill="#fff">nous ${nf(ref)} €</text></svg>`;
  const echelle = `<div class="db-card"><div class="ct"><span class="db-lab">Où nous situons-nous — ${esc(u)}</span><span class="db-mini">${lignes.length} relevés dont la taille est connue · ${diam ? 'prix × (24 / Ø)², parts converties en diamètre' : 'prix ÷ poids'} · survol = le relevé</span></div>
    <div style="padding:12px 16px 8px">${svg}</div>
    <div class="db-leg"><span><i style="background:#B8B2A8"></i>supermarché</span><span><i style="background:#B0792A"></i>artisan</span><span><i style="background:#5c5852"></i>chaîne</span><span><i style="background:#8D1D2C"></i>notre prix</span>${autres.map(p => `<span>· ${esc(p.nom)} : ${eur(p.valeur)}</span>`).join('')}</div></div>`;

  // --- relevés autour du magasin --------------------------------------------------
  const th = 'style="text-align:left;padding:7px 10px;font:400 10.5px var(--font-ui);letter-spacing:.06em;text-transform:uppercase;color:var(--color-text-muted);border-bottom:1px solid var(--color-border-tertiary)"';
  const td = (s, r = false) => `<td style="padding:7px 10px;border-top:1px solid rgba(34,34,34,.06);${r ? 'text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums' : ''}">${s}</td>`;
  const rows = ici.sort((a, b) => (a.valeur ?? 1e9) - (b.valeur ?? 1e9)).map(l => {
    const e = l.ecart_pct;
    const pos = l.valeur == null ? `<span class="mu">${diam && l.unite === 'g' ? 'vendu au poids' : 'taille non publiée'}</span>` : Math.abs(e) < 0.5 ? '<span class="mu">même prix</span>' : e > 0 ? `<span class="ko">nous ${pct(e)}</span>` : `<span class="ok">nous ${pct(e)}</span>`;
    return `<tr>${td(esc(l.concurrent) + `<div class="db-mini">${esc(l.type)}${l.vu_le ? ' · ' + esc(l.vu_le) : ''}</div>`)}${td(esc(l.produit))}${td(l.taille ? l.taille + ' ' + esc(l.unite) : '—', true)}${td(eur(l.prix), true)}${td('<b>' + eur(l.valeur) + '</b>', true)}${td(pos, true)}${td(l.source ? '<a class="db-lien" href="#">source ↗</a>' : '', true)}</tr>`;
  }).join('');
  const table = `<div class="db-card"><div class="ct"><span class="db-lab">Concurrents de ${VILLE} — relevé du 7 novembre</span><span class="db-mini">${ici.length} produits · supermarchés nationaux et boulangeries à moins de 20 km · dans l’ERP : module Benchmark de TFBuddy</span><button class="db-btn" style="margin-left:8px">+ Relevé terrain</button></div>
    <table style="width:100%;border-collapse:collapse;font-size:12.5px"><thead><tr><th ${th}>Concurrent</th><th ${th}>Produit</th><th ${th} style="text-align:right">${diam ? 'Ø' : 'Poids'}</th><th ${th}>Prix</th><th ${th}>${esc(u)}</th><th ${th}>Écart</th><th ${th}></th></tr></thead><tbody>${rows}</tbody></table></div>`;

  // --- visibilité web + à faire ------------------------------------------------------
  const ctrl = web.controles.map(c => `<div style="display:flex;gap:10px;padding:6px 16px;border-top:1px solid rgba(34,34,34,.06);font-size:12.5px"><span class="${c.ok ? 'ok' : 'ko'}" style="width:78px;flex:none;white-space:nowrap">${c.ok ? '✓ fait' : '✗ manque'}</span><span>${esc(c.libelle)}${c.detail && c.cle !== 'page' ? `<div class="db-mini">${esc(c.detail.slice(0, 90))}</div>` : ''}</span></div>`).join('');
  const afaire = R.constats.slice(0, 5).map((c, i) => `<div style="display:flex;gap:10px;padding:8px 16px;border-top:1px solid rgba(34,34,34,.06);font-size:12.5px;line-height:1.45"><span class="mu" style="flex:none;width:16px">${i + 1}</span><span>${esc(c)}</span></div>`).join('');
  const bas = `<div style="display:grid;grid-template-columns:1fr 1.25fr;gap:12px">
    <div class="db-card"><div class="ct"><span class="db-lab">Visibilité web — atelierby.be</span><span class="db-mini">relu à chaque passage de la routine</span></div>${ctrl}
      <div style="padding:9px 16px;font-size:11.5px" class="mu">Recherche « ${diam ? 'galette des rois' : 'cougnou'} ${VILLE} » : ${diam ? 'pas de prix concurrent publié à Halle' : 'L’Atelier By absent des résultats, aucun concurrent local non plus'} · rang Google Maps mesuré dès que la clé Places est posée</div></div>
    <div class="db-card"><div class="ct"><span class="db-lab">À faire</span><span class="db-mini">tiré du benchmark · ajouter au plan d’action</span><button class="db-btn" style="margin-left:8px">→ Plan d’action</button></div>${afaire}</div></div>`;

  const choix = `<div class="db-ong" style="margin-left:10px"><button class="${fam === 'cougnou' ? 'on' : ''}">Cougnou</button><button class="${fam === 'galette' ? 'on' : ''}">Galette des rois</button></div>`;
  return `<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>Dashboard magasin — Concurrence</title>
<link rel="stylesheet" href="/pilotage-reseau/public/assets/ds/global.css"><link rel="stylesheet" href="/pilotage-reseau/public/dashboard/dashboard.css">
<style>.db-tuiles .db-tui .s b{font-weight:400}</style></head><body><div id="dash">${hd}
<div class="db-sec">Concurrence — ${esc(R.libelle)}${choix}<small>prix comparable, position et visibilité face aux concurrents · relevé du 7/11/2026 · saison ${diam ? 'du 31/12 au 15/01' : 'du 1/11 au 6/01'}</small><span class="db-mini" style="margin-left:auto">prochain relevé : jeudi 12/11, 7 h (routine)</span></div>
<div class="db-tuiles">${tuiles}</div>${echelle}${table}${bas}</div></body></html>`;
}

(async () => {
  for (const f of ['cougnou', 'galette']) fs.writeFileSync(OUT + f + '.html', page(f));
  const b = await chromium.launch();
  const p = await b.newPage({ viewport: { width: 1440, height: 1000 }, deviceScaleFactor: 2 });
  for (const f of ['cougnou', 'galette']) {
    await p.goto('http://127.0.0.1:8099/pilotage-reseau/docs/maquettes/benchmark-concurrence/' + f + '.html', { waitUntil: 'load' });
    await p.waitForTimeout(600);
    await p.screenshot({ path: OUT + f + '.jpg', type: 'jpeg', quality: 86, fullPage: true });
  }
  await b.close();
})();
