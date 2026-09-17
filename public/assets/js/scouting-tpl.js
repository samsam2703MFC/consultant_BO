/* Scouting commercial — gabarits HTML.
 * Traduction fidèle du template du prototype Design Component
 * (Scouting Belgique.dc.html) : mêmes styles inline, mêmes structures ;
 * {{ x }} → ${c.x}, sc-for → map, sc-if → ternaire.
 *
 * L'écran est rendu par fragments dans des conteneurs stables (voir
 * scouting.js) : le conteneur de la carte Leaflet n'est jamais recréé.
 * x = { A: click, C: change, I: input, esc }.
 * Les libellés marqués data-sc-live sont rafraîchis sans re-rendu pendant
 * le glissement d'un curseur.
 */

const selCss = 'width:100%;padding:8px;border:0.5px solid var(--color-border-secondary);border-radius:6px;background:var(--color-background-secondary);font-size:13px;font-family:var(--font-ui);color:var(--color-text)';
const numCss = 'padding:4px 6px;border:0.5px solid var(--color-border-secondary);border-radius:6px;background:var(--color-background-secondary);font-size:12px;font-family:var(--font-ui);color:var(--color-text);text-align:right;box-sizing:border-box';
const txtCss = 'padding:4px 6px;border:0.5px solid var(--color-border-tertiary);border-radius:6px;background:var(--color-background-secondary);font-size:11px;font-family:var(--font-ui);color:var(--color-text);box-sizing:border-box';
const rowCss = 'display:flex;align-items:baseline;justify-content:space-between;gap:10px;padding:8px 0;border-bottom:0.5px solid var(--color-border-tertiary)';

function opts(list, val){
  return list.map(o => `<option value="${String(o.value).replace(/"/g, '&quot;')}"${String(o.value) === String(val) ? ' selected' : ''}>${o.label}</option>`).join('');
}

function live(key, esc, c){ return `<span data-sc-live="${key}">${esc(c[key])}</span>`; }

/* (i) — l'explication et la formule d'un libellé, dans data-sc-tip ; la bulle
 * est posée par Scouting.showTip() (position fixe, hors des panneaux). */
function info(esc, t){ return t ? `<i class="sc-i" data-sc-tip="${esc(t)}" title="">i</i>` : ''; }

/* --- Bandeau : état du chargement, actions, onglets ------------------------- */
export function renderTop(c, x){
  const { esc } = x;
  return `
  <div style="display:flex;align-items:center;gap:18px;padding:10px 18px;background:var(--color-surface);border-bottom:0.5px solid var(--color-border-tertiary);flex:0 0 auto">
    <div style="display:flex;align-items:center;gap:8px;font-size:11px;color:var(--color-text-muted);min-width:0">
      <div style="width:8px;height:8px;border-radius:50%;flex:0 0 auto;background:${c.statusColor}"></div>
      <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc(c.statusLabel)}</span>
    </div>
    <div style="flex:1"></div>
    <button ${x.A(c.ouvrirWiz)} class="btn-secondary" style="padding:7px 12px;font-size:12px;white-space:nowrap;border-color:var(--color-primary);color:var(--color-primary);font-weight:600">Où puis-je ouvrir ?</button>
    <button ${x.A(c.openReseau)} class="btn-secondary" style="padding:7px 12px;font-size:12px;white-space:nowrap">Magasins du réseau</button>
    <button ${x.A(c.toggleCompare)} class="btn-secondary" style="padding:7px 12px;font-size:12px;white-space:nowrap">${c.compare ? 'Retour à la carte' : 'Comparer 2 arrondissements'}</button>
    <button ${x.A(c.reload)} class="btn-secondary" style="padding:7px 12px;font-size:12px;white-space:nowrap">Recharger les données</button>
    <button ${x.A(c.exportCsv)} class="btn-primary" style="padding:7px 14px;font-size:12px;white-space:nowrap">Exporter les candidats (${c.nCandidates})</button>
  </div>
  <div style="display:flex;gap:4px;padding:0 18px;background:var(--color-surface);border-bottom:0.5px solid var(--color-border-tertiary);flex:0 0 auto">
    ${c.views.map(v => `<button ${x.A(v.go)} class="hv-text" style="border:none;border-bottom:2px solid ${v.border};background:transparent;padding:9px 14px 7px;font-family:var(--font-ui);font-size:12px;font-weight:500;cursor:pointer;color:${v.color}">${esc(v.label)}</button>`).join('')}
  </div>`;
}

/* --- Panneau gauche : ce que la carte montre, puis les réglages, repliables -- */
const OEIL = '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M1.6 12S5.6 5 12 5s10.4 7 10.4 7-4 7-10.4 7S1.6 12 1.6 12z"/><circle cx="12" cy="12" r="2.8"/></svg>';
const OEIL_FERME = '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M3 3l18 18M10.6 10.7a2.8 2.8 0 0 0 3.9 3.9M6.4 6.5C3.4 8.4 1.6 12 1.6 12s4 7 10.4 7c1.7 0 3.2-.4 4.5-1M9.5 5.3C10.3 5.1 11.1 5 12 5c6.4 0 10.4 7 10.4 7s-.9 1.6-2.5 3.2"/></svg>';
// Une section repliable : le titre est un bouton, le contenu n'est rendu
// qu'ouvert — les curseurs d'une section fermée n'existent pas.
const pli = (x, esc, c, k, titre, corps) => `
  <button ${x.A(c.pli(k))} class="sc-pli${c.plis[k] ? ' open' : ''}" type="button"><span>${esc(titre)}</span><b>${c.plis[k] ? '−' : '+'}</b></button>
  ${c.plis[k] ? `<div class="sc-pli-c">${corps()}</div>` : ''}`;

export function renderLeft(c, x){
  const { esc } = x;
  const check = (on, fn, extra) => `<input type="checkbox"${on ? ' checked' : ''} ${x.C(fn)} style="accent-color:var(--color-primary);${extra || ''}">`;
  const filtres = () => `
  <div class="t-admin-label" style="margin-bottom:4px">Note Google minimale — ${live('minRatingLabel', esc, c)}${info(esc, c.tips.minRating)}</div>
  <input type="range" min="0" max="5" step="0.1" value="${c.minRating}" ${x.I(c.slideMinRating)} ${x.C(c.setMinRating)}>
  <div style="font-size:11px;color:var(--color-text-muted);margin:2px 0 16px">${live('ratingCoverage', esc, c)}</div>

  <div class="t-admin-label" style="margin-bottom:4px">Ménages minimum par commune — ${live('minHhLabel', esc, c)}${info(esc, c.tips.minHh)}</div>
  <input type="range" min="0" max="30000" step="500" value="${c.minHh}" ${x.I(c.slideMinHh)} ${x.C(c.setMinHh)}>
  <div style="font-size:11px;color:var(--color-text-muted);margin:2px 0 16px">${live('communeCoverage', esc, c)}</div>

  <div class="t-admin-label" style="margin-bottom:4px">Rayon d'exclusion — ${live('radiusLabel', esc, c)}${info(esc, c.tips.radius)}</div>
  <input type="range" min="0.5" max="5" step="0.1" value="${c.radius}" ${x.I(c.slideRadius)} ${x.C(c.setRadius)}>
  <div style="font-size:11px;color:var(--color-text-muted);margin:2px 0 16px">Le rayon des mailles, de la fiche et des zones — ou dessine la zone sur la carte.</div>

  <div class="t-admin-label" style="margin-bottom:4px">Seuil « concurrent fort » — ${live('threshLabel', esc, c)}${info(esc, c.tips.thresh)}</div>
  <input type="range" min="3.5" max="5" step="0.1" value="${c.thresh}" ${x.I(c.slideThresh)} ${x.C(c.setThresh)}>
  <div style="font-size:11px;color:var(--color-text-muted);margin:2px 0 8px">${live('threshHint', esc, c)}</div>`;

  const couches = () => `
  <div class="t-admin-label" style="margin-bottom:8px;color:#1b5e20">+ &nbsp;Potentiel</div>
  <div style="display:flex;flex-direction:column;gap:7px;margin-bottom:14px;border-left:2px solid rgba(27,94,32,.35);padding-left:10px">
    ${c.layersPlus.map(l => `
    <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer">
      ${check(l.on, l.toggle)}
      <span>${esc(l.name)}</span>
    </label>`).join('')}
  </div>
  <div class="t-admin-label" style="margin-bottom:8px;color:var(--color-primary)">− &nbsp;Contraintes</div>
  <div style="display:flex;flex-direction:column;gap:7px;margin-bottom:8px;border-left:2px solid rgba(141,29,44,.35);padding-left:10px">
    ${c.layersMinus.map(l => `
    <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer">
      ${check(l.on, l.toggle)}
      <span>${esc(l.name)}</span>
    </label>`).join('')}
  </div>`;

  const hyp = () => `
  <div style="display:flex;align-items:baseline;justify-content:flex-end;margin-bottom:8px">
    <button ${x.A(c.exportParams)} style="border:none;background:transparent;padding:0;font-family:var(--font-ui);font-size:11px;color:var(--color-primary);cursor:pointer">Exporter CSV</button>
  </div>
  <div style="font-size:11px;color:var(--color-text-muted);margin-bottom:8px">Enregistrées automatiquement et reprises dans les zones candidates.</div>
  <div style="border:0.5px solid var(--color-border-tertiary);border-radius:8px;overflow:hidden;margin-bottom:10px">
    ${c.params.map((p, i) => `
    <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;padding:7px 9px;border-bottom:0.5px solid var(--color-border-tertiary);background:var(--color-surface)">
      <span style="font-size:11px;color:var(--color-text-muted);line-height:1.35;display:inline-flex;align-items:center">${esc(p.k)}${info(esc, p.i)}</span>
      <input id="sc-p${i}" type="number" step="any" value="${esc(p.v)}" ${x.C(p.set)} style="width:68px;flex:0 0 auto;${numCss}">
    </div>`).join('')}
  </div>
  <div style="font-size:11px;color:var(--color-text-muted);line-height:1.5;margin-bottom:8px">${esc(c.empriseHint)}<br><br>Réseau : 416 € (Max&amp;Sandra), 550 € (Berlo), 586 € (Halle). Emprise Halle 15,5 % pour un CA de 1.296.881 € TTC sur 250 m².</div>`;

  const calage = () => `
  <div style="font-size:11px;color:var(--color-text-muted);line-height:1.5;margin-bottom:8px;display:inline-flex;align-items:flex-start">${esc(c.calage.intro)}${info(esc, c.calage.tip)}</div>
  <div style="border:0.5px solid var(--color-border-tertiary);border-radius:8px;overflow:hidden;margin-bottom:8px">
    ${c.calage.rows.map(r => `
    <div style="padding:7px 9px;border-bottom:0.5px solid var(--color-border-tertiary);background:var(--color-surface)">
      <div style="display:flex;justify-content:space-between;gap:8px;font-size:12px"><span style="font-weight:500">${esc(r.nom)}</span><span style="font-weight:600;color:${r.ecartColor}">${esc(r.ecart)}</span></div>
      <div style="display:flex;justify-content:space-between;gap:8px;font-size:11px;color:var(--color-text-muted);margin-top:2px"><span>réel ${esc(r.reel)}${r.annualise ? ' · ' + r.mois + ' mois' : ''}</span><span>modèle ${esc(r.modele)}</span></div>
      ${r.pos ? '' : `<div style="margin-top:5px"><button ${x.A(r.place)} class="btn-secondary" style="padding:3px 8px;font-size:11px">Placer sur la carte</button></div>`}
    </div>`).join('')}
    ${c.calage.rows.length ? '' : `<div style="padding:8px 9px;font-size:11px;color:var(--color-text-muted)">${esc(c.calage.vide)}</div>`}
  </div>
  ${c.calage.placing ? `<div style="font-size:11px;color:var(--color-primary);font-weight:500;margin-bottom:6px">Clique sur la carte pour placer le magasin.</div>` : ''}
  ${c.calage.med ? `<button ${x.A(c.calage.caler)} class="btn-secondary" style="width:100%;padding:8px;font-size:12px">${esc(c.calage.bouton)}</button>` : ''}
  <div style="font-size:11px;color:var(--color-text-muted);margin:6px 0 8px;line-height:1.5">${esc(c.calage.note)}</div>`;

  const sources = () => `
  <div class="t-admin-label" style="margin-bottom:6px;display:inline-flex;align-items:center">Population${info(esc, c.popTip)}</div>
  <div style="font-size:11px;color:var(--color-text-muted);line-height:1.5;margin-bottom:8px">${esc(c.popCoverage)}</div>
  <label style="display:block;font-size:12px;color:var(--color-text);border:0.5px dashed var(--color-border-secondary);border-radius:8px;padding:10px;text-align:center;cursor:pointer;margin-bottom:16px">
    Importer un CSV StatBel (code NIS ; population)
    <input type="file" accept=".csv,.txt" ${x.C(c.importPops)} style="display:none">
  </label>
  <div class="t-admin-label" style="margin-bottom:6px">Notes Google</div>
  <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;padding:8px 9px;border:0.5px solid var(--color-border-tertiary);border-radius:8px;background:var(--color-background-secondary)">
    <div style="width:8px;height:8px;border-radius:50%;flex:0 0 auto;background:${c.gOk ? '#1b5e20' : '#8D1D2C'}"></div>
    <div style="flex:1;font-size:11px;line-height:1.45;color:var(--color-text)">${esc(c.gLabel)}</div>
    ${c.gOk ? '' : `<button ${x.A(c.goParams)} class="btn-secondary" style="flex:0 0 auto;padding:4px 9px;font-size:11px">Paramètres</button>`}
  </div>
  <button ${x.A(c.enrich)} class="btn-secondary" style="width:100%;padding:8px;font-size:12px${c.gOk ? '' : ';opacity:.6'}">${esc(c.enrichLabel)}</button>
  <div style="font-size:11px;color:var(--color-text-muted);margin:6px 0 8px;line-height:1.5">${esc(c.gkeyHint)}</div>`;

  return `
  <div class="t-admin-label" style="margin-bottom:6px">Chercher une ville</div>
  <div class="sc-ville">
    <input id="sc-ville" type="text" autocomplete="off" spellcheck="false" placeholder="Nom de commune" value="${esc(c.ville)}" ${x.I(c.setVille)} ${x.K(c.villeEntree)}>
    ${c.villes.length ? `<div class="res">${c.villes.map(v => `
      <button ${x.A(v.aller)}><span class="n">${esc(v.nom)}</span><span class="m">${esc(v.meta)}</span></button>`).join('')}</div>` : ''}
    ${c.villeVide ? `<div class="vide">${esc(c.villeVide)}</div>` : ''}
  </div>

  <div class="t-admin-label" style="margin-bottom:6px;display:inline-flex;align-items:center">Ce que la carte montre${info(esc, c.themeTip)}</div>
  <div class="sc-theme">
    ${c.themes.map(t => `<button ${x.A(t.pick)} type="button"${t.on ? ' class="on"' : ''} title="${esc(t.tip)}"><span>${esc(t.label)}</span>${t.on ? '<i>●</i>' : ''}</button>`).join('')}
  </div>
  ${c.themeLegend.rows.length ? `
  <div class="sc-leg">
    ${c.themeLegend.rows.map(r => `
    <div class="r${r.off ? ' off' : ''}">
      <button ${x.A(r.toggle)} class="oe" type="button" title="${r.off ? 'Rallumer' : 'Éteindre'} cette classe">${r.off ? OEIL_FERME : OEIL}</button>
      <span class="sw" style="background:${r.color}"></span>
      <span class="tx">${esc(r.label)}</span>
      <span class="n">${esc(r.n)}</span>
    </div>`).join('')}
  </div>` : ''}
  <div style="font-size:11px;color:var(--color-text-muted);line-height:1.45;margin-bottom:18px">${esc(c.themeLegend.note)}</div>

  <div class="t-admin-label" style="margin-bottom:8px">Provinces &amp; régions</div>
  <div style="display:flex;flex-direction:column;gap:6px;margin-bottom:16px">
    ${c.provinces.map(p => `
    <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer">
      ${check(p.on, p.toggle)}
      <span style="flex:1">${esc(p.name)}</span>
      <span style="font-size:11px;color:var(--color-text-muted)">${esc(p.count)}</span>
    </label>`).join('')}
  </div>

  <div class="t-admin-label" style="margin-bottom:6px">Arrondissement</div>
  <select ${x.C(c.setArr)} style="${selCss};margin-bottom:16px">${opts(c.arrOptions, c.arr)}</select>

  ${pli(x, esc, c, 'filtres', 'Filtres — note, ménages, rayon, seuil', filtres)}

  <div style="height:0.5px;background:var(--color-border-tertiary);margin:16px 0"></div>

  <div class="t-admin-label" style="margin-bottom:8px">Lecture de la carte</div>
  <label style="display:flex;align-items:center;gap:10px;padding:10px 12px;border:1px solid rgba(27,94,32,.35);border-radius:8px;background:rgba(27,94,32,.07);cursor:pointer;margin-bottom:10px">
    ${check(c.onlyPrio, c.toggleOnlyPrio, 'accent-color:#1b5e20;width:16px;height:16px')}
    <span style="flex:1">
      <span style="display:block;font-size:13px;font-weight:500;color:#1b5e20">Zones intéressantes uniquement</span>
      <span style="display:block;font-size:11px;color:var(--color-text-muted)">Masque concurrents et zones rouges · ${esc(c.prioCount)} zones retenues</span>
    </span>
  </label>
  <div class="t-admin-label" style="margin-bottom:4px">Score minimum — ${live('minScoreLabel', esc, c)} / 100${info(esc, c.tips.minScore)}</div>
  <input type="range" min="0" max="95" step="5" value="${c.minScore}" ${x.I(c.slideMinScore)} ${x.C(c.setMinScore)}>
  <div style="height:12px"></div>
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-bottom:12px">
    <button ${x.A(c.presetPrio)} class="btn-secondary" style="padding:8px 6px;font-size:12px">Opportunités</button>
    <button ${x.A(c.presetConc)} class="btn-secondary" style="padding:8px 6px;font-size:12px">Concurrence</button>
  </div>
  ${pli(x, esc, c, 'couches', 'Couches — potentiel et contraintes', couches)}

  <div style="height:0.5px;background:var(--color-border-tertiary);margin:16px 0"></div>

  ${pli(x, esc, c, 'hyp', 'Hypothèses du modèle — ' + c.params.length + ' valeurs', hyp)}
  ${pli(x, esc, c, 'calage', 'Calage sur le réseau', calage)}
  ${pli(x, esc, c, 'sources', 'Population · Notes Google', sources)}
  <div style="font-size:11px;color:var(--color-text-muted);line-height:1.45;margin-top:4px">Repliés : ce sont des réglages, pas une lecture. La carte s'ouvre sur ce qu'elle montre.</div>`;
}

/* --- Habillage de la carte : outils de dessin, légende, ligne d'état, voile -- */
const ICONE = {
  point: '<path d="M12 21s7-5.6 7-11a7 7 0 1 0-14 0c0 5.4 7 11 7 11z"/><circle cx="12" cy="10" r="2.4"/>',
  cercle: '<circle cx="12" cy="12" r="8"/><circle cx="12" cy="12" r="1.4" fill="currentColor"/>',
  polygone: '<path d="M5 9l7-5 7 6-3 9H8z"/>',
  rectangle: '<rect x="4.5" y="6.5" width="15" height="11" rx="1"/>',
  isochrone: '<path d="M12 3.5c4 2 6.5 4.6 6.5 8.5S16 18.5 12 20.5 5.5 15.9 5.5 12 8 5.5 12 3.5z"/><circle cx="12" cy="12" r="2"/>',
  gomme: '<path d="M8 20h11"/><path d="M15.5 4.5l4 4-9 9-4-4z"/>'
};
const ico = k => `<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${ICONE[k] || ''}</svg>`;

export function renderMapUi(c, x){
  const { esc } = x;
  return `
  <div class="sc-outils" title="">
    ${c.tools.map(t => `<button ${x.A(t.pick)} type="button" class="${t.on ? 'on' : ''}" title="${esc(t.label + ' — ' + t.tip)}">${ico(t.k)}</button>`).join('')}
    <div class="sep"></div>
    <button ${x.A(c.effacerZone)} type="button" title="Effacer la zone et fermer la fiche">${ico('gomme')}</button>
  </div>
  ${c.tool !== 'point' || c.dessinHint ? `
  <div class="sc-dessin">
    ${esc(c.dessinHint)}
    ${c.tool === 'isochrone' ? `<select ${x.C(c.setIso)} style="${selCss}">${opts(c.isoChoix, c.iso)}</select>` : ''}
  </div>` : ''}
  <div style="position:absolute;top:12px;right:12px;z-index:500;background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px;padding:12px 14px;width:208px;box-shadow:0 2px 10px rgba(0,0,0,.08)">
    <div class="t-admin-label" style="margin-bottom:8px">Légende</div>
    ${c.legend.map(i => `
    <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;font-size:11px;line-height:1.3">
      <div style="width:12px;height:12px;border-radius:50%;flex:0 0 auto;background:${i.color};border:${i.border ? '2px solid ' + i.border : '1px solid rgba(0,0,0,.15)'}"></div>
      <span>${esc(i.label)}</span>
    </div>`).join('')}
    <div style="height:0.5px;background:var(--color-border-tertiary);margin:8px 0"></div>
    <div style="font-size:11px;color:var(--color-text-muted);line-height:1.4">Un clic évalue un point dans son rayon ; les outils à gauche dessinent la zone — cercle, polygone, rectangle, isochrone.</div>
  </div>

  <div style="position:absolute;bottom:14px;left:14px;z-index:500;background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:10px;padding:8px 12px;font-size:11px;color:var(--color-text-muted)">${live('statsLine', esc, c)}</div>

  ${c.wiz.fait && !c.wiz.etape ? `
  <div class="wz-rappel">
    <span class="t">${c.wiz.nChauds ? c.wiz.nChauds + ' point' + (c.wiz.nChauds > 1 ? 's' : '') + ' chaud' + (c.wiz.nChauds > 1 ? 's' : '') : 'Aucun emplacement'}</span>
    ${c.wiz.resume.map(r => `<span class="ch">${esc(r)}</span>`).join('')}
    <span class="sp"></span>
    ${c.wiz.obstacle ? `<span class="ch" style="background:#FBEFE0;color:#B26A00">${esc(c.wiz.obstacle)}</span>` : ''}
    ${c.wiz.caRange ? `<span class="ch ok">${esc(c.wiz.caRange)}</span>` : ''}
    <button ${x.A(c.wiz.rouvrir)} class="btn-secondary" style="padding:4px 11px;font-size:11px">Modifier</button>
    <button ${x.A(c.wiz.cacher)} class="btn-secondary" style="padding:4px 9px;font-size:11px">✕</button>
  </div>` : ''}

  ${c.veil ? `
  <div style="position:absolute;inset:0;z-index:600;background:rgba(234,228,220,.86);display:flex;flex-direction:column;align-items:center;justify-content:center;gap:14px">
    <div style="width:34px;height:34px;border:3px solid rgba(141,29,44,.2);border-top-color:var(--color-primary);border-radius:50%;animation:sc-spin 800ms linear infinite"></div>
    <div style="font-size:13px;color:var(--color-text)">${esc(c.progress)}</div>
  </div>` : ''}`;
}

/* --- Panneau droit : fiche zone / distribution, candidats retenus ---------- */
export function renderRight(c, x){
  const { esc } = x;
  const sel = c.hasSel ? `
  <div>
    <div class="t-admin-label" style="margin-bottom:4px">${c.selRang ? 'Point chaud nº' + c.selRang : c.selZone ? 'Zone dessinée' : 'Zone candidate'}</div>
    <div class="t-section-title" style="font-size:18px;margin-bottom:2px">${esc(c.selCommune)}</div>
    <div style="font-size:12px;color:var(--color-text-muted);margin-bottom:${c.selZone ? 4 : 14}px">${esc(c.selGeo)}</div>
    ${c.selZone ? `<div style="font-size:12px;font-weight:500;color:#1b5e20;margin-bottom:14px">${esc(c.selZone)}</div>` : ''}

    <div style="background:${c.selVerdictBg};border-radius:10px;padding:12px 14px;margin-bottom:16px">
      <div style="font-size:12px;color:${c.selVerdictColor};font-weight:600;margin-bottom:2px">${esc(c.selVerdict)}</div>
      <div style="font-size:11px;color:var(--color-text-muted);line-height:1.5">${esc(c.selVerdictNote)}</div>
    </div>

    ${c.selRows.map(r => `
    <div style="${rowCss}">
      <span style="font-size:12px;color:var(--color-text-muted);display:inline-flex;align-items:center">${esc(r.k)}${info(esc, r.i)}</span>
      <span style="font-size:13px;font-weight:500;text-align:right">${esc(r.v)}</span>
    </div>`).join('')}

    <div style="display:flex;align-items:baseline;justify-content:space-between;gap:10px;padding:14px 0 4px">
      <span style="font-size:13px;font-weight:500;display:inline-flex;align-items:center">CA annuel estimé TTC${info(esc, c.tips.ca)}</span>
      <span style="font-family:var(--font-display);font-size:22px;color:var(--color-primary)">${esc(c.selCa)}</span>
    </div>
    <div style="font-size:11px;color:var(--color-text-muted);margin-bottom:14px">${esc(c.selCaDetail)}</div>
    ${c.selDisque ? `<div style="font-size:11px;line-height:1.5;color:var(--color-text);background:var(--color-background-secondary);border-radius:8px;padding:9px 11px;margin-bottom:14px">${esc(c.selDisque)}</div>` : ''}

    <button ${x.A(c.addCandidate)} class="btn-primary" style="width:100%;padding:10px">Ajouter aux candidats</button>
    <button ${x.A(c.ouvrirDossier)} class="btn-secondary" style="width:100%;padding:9px;margin-top:8px;font-size:12px">Éditer le dossier d'implantation</button>

    <div style="display:flex;align-items:baseline;justify-content:space-between;margin:20px 0 4px">
      <div class="t-admin-label">Concurrents dans le rayon</div>
      <div class="t-admin-label" style="letter-spacing:0">Note / 5</div>
    </div>
    <div style="font-size:11px;color:var(--color-text-muted);margin-bottom:8px;line-height:1.5">Saisis une note pour requalifier un concurrent : elle prime sur Google et recalcule zone rouge, emprise et CA.</div>
    ${c.selCompetitors.map(k => `
    <div style="display:flex;align-items:center;gap:8px;padding:7px 0;border-bottom:0.5px solid var(--color-border-tertiary)">
      <div style="width:8px;height:8px;border-radius:50%;flex:0 0 auto;background:${k.color}"></div>
      <div style="flex:1;min-width:0">
        <div style="font-size:12px;font-weight:500;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc(k.name)}</div>
        <div style="font-size:11px;color:var(--color-text-muted)">${esc(k.meta)} · ${esc(k.dist)}</div>
        <input id="sc-sm-${esc(k.id)}" type="text" maxlength="200" placeholder="Commentaire terrain (200 car.)" value="${esc(k.comment)}" ${x.C(k.setComment)} style="width:100%;margin-top:4px;${txtCss}">
      </div>
      <input id="sc-sn-${esc(k.id)}" type="number" min="0" max="5" step="0.1" placeholder="–" value="${esc(k.note)}" ${x.C(k.setNote)} style="width:52px;flex:0 0 auto;${numCss}">
    </div>`).join('')}
  </div>` : `
  <div>
    <div class="t-admin-label" style="margin-bottom:8px">Distribution</div>
    <div style="font-size:12px;color:var(--color-text-muted);line-height:1.6;margin-bottom:14px">${esc(c.histTitle)}</div>
    <div style="display:flex;align-items:flex-end;gap:5px;height:130px;margin-bottom:20px">
      ${c.hist.map(h => `
      <div style="flex:1;display:flex;flex-direction:column;justify-content:flex-end;align-items:center;gap:4px;height:100%">
        <span style="font-size:9px;color:var(--color-text-muted)">${h.n}</span>
        <div style="width:100%;border-radius:3px 3px 0 0;background:${h.color};height:${h.h}"></div>
        <span style="font-size:9px;color:var(--color-text-muted)">${esc(h.label)}</span>
      </div>`).join('')}
    </div>
    <div style="font-size:12px;color:var(--color-text-muted);line-height:1.6">Clique un point blanc de la carte pour évaluer une implantation : ménages accessibles, concurrence, emprise et CA estimé selon le modèle de l'étude Halle.</div>
  </div>`;

  const chauds = `
  <div class="t-admin-label" style="margin:22px 0 6px">Points chauds</div>
  <div style="font-size:11px;color:var(--color-text-muted);line-height:1.5;margin-bottom:8px">${esc(c.chaudsNote)}</div>
  ${c.pointsChauds.map(p => `
  <div id="sc-hot-${p.rang}" ${x.A(p.voir)} class="sc-hot${p.on ? ' on' : ''}" title="Voir sur la carte">
    <span class="r">${p.rang}</span>
    <div style="flex:1;min-width:0">
      <div class="n">${esc(p.commune)}</div>
      <div class="m">${esc(p.meta)}</div>
    </div>
    <div style="flex:0 0 auto;text-align:right">
      <div class="ca">${esc(p.ca)}</div>
      <div class="m">score ${p.score}</div>
    </div>
  </div>`).join('')}`;

  return `${sel}${chauds}
  <div class="t-admin-label" style="margin:22px 0 8px">Zones candidates retenues</div>
  ${c.candidates.map(k => `
  <div style="display:flex;align-items:center;gap:8px;padding:8px 0;border-bottom:0.5px solid var(--color-border-tertiary)">
    <div style="flex:1;min-width:0">
      <div style="font-size:12px;font-weight:500">${esc(k.name)}</div>
      <div style="font-size:11px;color:var(--color-text-muted)">${esc(k.meta)}</div>
    </div>
    <button ${x.A(k.focus)} class="icon-circle" style="width:22px;height:22px" title="Voir sur la carte">→</button>
    <button ${x.A(k.dossier)} class="icon-circle" style="width:22px;height:22px;font-size:10px;letter-spacing:-.02em" title="Éditer le dossier d'implantation">PDF</button>
    <button ${x.A(k.remove)} class="icon-circle" style="width:22px;height:22px" title="Retirer">×</button>
  </div>`).join('')}
  ${c.noCandidates ? '<div style="font-size:12px;color:var(--color-text-muted)">Aucune zone retenue pour l\'instant.</div>' : ''}`;
}


/* --- Le dossier d'implantation : une page, quatre lectures ----------------- */
/* La même page sert à l'écran (overlay), à la fenêtre d'impression et,
   côté serveur, le PDF reprend les mêmes données. Les styles vivent ici pour
   suivre la page partout où elle va. */
export const DOSS_CSS = `
.sc-doss{font-family:var(--font-ui,Helvetica,Arial,sans-serif);color:#221E1A;background:#fff;width:210mm;max-width:100%;margin:0 auto;padding:14mm 16mm 16mm;box-sizing:border-box;box-shadow:0 4px 24px rgba(0,0,0,.13);font-size:12px;line-height:1.4}
.sc-doss .hd{display:flex;align-items:flex-start;gap:12px;border-bottom:2px solid #8D1D2C;padding-bottom:10px}
.sc-doss .hd img{height:30px;display:block}
.sc-doss .hd .t{flex:1;min-width:0}
.sc-doss .hd .t b{display:block;font-family:var(--font-display,Georgia,"DejaVu Serif",serif);font-size:21px;line-height:1.15;font-weight:400;margin-top:4px}
.sc-doss .hd .t span{display:block;font-size:11.5px;color:#7a736a;margin-top:2px}
.sc-doss .hd .t em{display:block;font-style:normal;font-size:12px;color:#2d7a3e;font-weight:600;margin-top:3px}
.sc-doss .hd .sc{text-align:right;flex:0 0 auto;padding-top:6px}
.sc-doss .hd .sc b{display:block;font-family:var(--font-display,Georgia,"DejaVu Serif",serif);font-size:32px;line-height:1;color:#2d7a3e;font-weight:400}
.sc-doss .hd .sc span{font-size:9.5px;color:#7a736a;letter-spacing:.08em;text-transform:uppercase}
.sc-doss h3{font-family:var(--font-display,Georgia,"DejaVu Serif",serif);font-weight:400;font-size:15px;margin:18px 0 8px;padding-bottom:4px;border-bottom:1.4px solid #8D1D2C}
.sc-doss .verdict{border-radius:8px;padding:10px 12px;margin:14px 0 0}
.sc-doss .verdict b{display:block;font-size:12.5px;margin-bottom:2px}
.sc-doss .verdict span{font-size:11px;color:#7a736a;line-height:1.5}
.sc-doss .carte{width:100%;height:auto;display:block;border:1px solid #e6e0d8;border-radius:6px}
.sc-doss .attente{height:200px;display:flex;align-items:center;justify-content:center;border:1px dashed #d8d0c4;border-radius:6px;color:#7a736a;font-size:12px}
.sc-doss .legende{font-size:10.5px;color:#7a736a;margin-top:5px;line-height:1.5}
.sc-doss .q{display:grid;grid-template-columns:repeat(4,1fr);gap:9px}
.sc-doss .q>div{border:1px solid #e6e0d8;border-radius:8px;background:#fbf9f5;padding:8px 10px}
.sc-doss .q .k{font-size:9.5px;letter-spacing:.08em;text-transform:uppercase;color:#7a736a;min-height:24px}
.sc-doss .q .v{font-family:var(--font-display,Georgia,"DejaVu Serif",serif);font-size:19px;line-height:1.1;margin-top:3px}
.sc-doss .q .s{font-size:10px;color:#7a736a;margin-top:2px}
.sc-doss table{width:100%;border-collapse:collapse;font-size:11.5px}
.sc-doss th{text-align:right;font-size:9.5px;letter-spacing:.07em;text-transform:uppercase;color:#7a736a;font-weight:400;padding:5px 6px;border-bottom:1px solid #221E1A}
.sc-doss td{text-align:right;padding:5px 6px;border-bottom:.5px solid #EAE3D8;vertical-align:top;font-variant-numeric:tabular-nums}
.sc-doss .l{text-align:left}.sc-doss .mut{color:#7a736a}.sc-doss .acc{color:#8D1D2C}.sc-doss .ok{color:#2d7a3e}.sc-doss td.n{white-space:nowrap}
.sc-doss .ch{display:inline-block;font-size:9.5px;font-weight:600;border-radius:9px;padding:1px 7px;background:#EEE9E1;color:#221E1A;margin-left:5px;white-space:nowrap}
.sc-doss .note{border:1px solid #e6e0d8;border-radius:8px;background:#fbf9f5;padding:9px 11px;font-size:10.5px;color:#7a736a;line-height:1.6;margin-top:10px}
.sc-doss .note b{color:#221E1A}
.sc-doss .hyp{display:grid;grid-template-columns:1fr 1fr;gap:0 18px}
.sc-doss .hyp div{display:flex;justify-content:space-between;gap:8px;padding:4px 0;border-bottom:.5px solid #EAE3D8;font-size:11px}
.sc-doss .hyp div span{color:#7a736a}
`;
export const DOSS_PRINT = `
@page{size:A4;margin:14mm 15mm 18mm}
body{margin:0;background:#fff}
.sc-doss{box-shadow:none;width:auto;max-width:none;padding:0}
.sc-doss h3{page-break-after:avoid}
.sc-doss tr,.sc-doss .q>div,.sc-doss .note{page-break-inside:avoid}
`;

export function dossierPage(d, esc, logo){
  const tuile = t => `<div><div class="k">${esc(t[0])}</div><div class="v">${esc(t[1])}</div>${t[2] ? `<div class="s">${esc(t[2])}</div>` : ''}</div>`;
  return `
  <div class="sc-doss">
    <div class="hd">
      ${logo ? `<img src="${esc(logo)}" alt="">` : ''}
      <div class="t"><b>${esc(d.titre)}</b><span>${esc(d.geo)} · éditée le ${esc(d.date)}</span>${d.zone ? `<em>${esc(d.zone)}</em>` : ''}</div>
      <div class="sc"><b>${esc(d.score)}</b><span>score / 100</span></div>
    </div>
    <div class="verdict" style="background:${d.verdictOk ? '#E3EFE6' : '#F6E4E7'}"><b style="color:${d.verdictOk ? '#2d7a3e' : '#8D1D2C'}">${esc(d.verdict)}</b><span>${esc(d.verdictNote)}</span></div>

    <h3>Situation</h3>
    ${d.carte ? `<img class="carte" src="${d.carte}" alt="">` : `<div class="attente">${d.carteAttente || 'Carte en cours d’assemblage…'}</div>`}
    <div class="legende">${esc(d.carteNote)}</div>

    <h3>L'essentiel</h3>
    <div class="q">${d.essentiel.map(tuile).join('')}</div>

    <h3>Le marché</h3>
    <table><tr><th class="l">Mesure</th><th>Valeur</th><th class="l" style="padding-left:18px">Source · calcul</th></tr>
      ${d.marche.map(r => `<tr><td class="l">${esc(r[0])}</td><td class="n"><b>${esc(r[1])}</b></td><td class="l mut" style="padding-left:18px">${esc(r[2])}</td></tr>`).join('')}
    </table>

    <h3>La concurrence relevée${d.chaines ? ` <span class="ch">chaînes : ${esc(d.chaines)}</span>` : ''}</h3>
    ${d.concurrence.length ? `
    <table><tr><th class="l">Commerce</th><th class="l">Commune</th><th>Distance</th><th>Note / 5</th><th>Force</th><th class="l" style="padding-left:12px">Lecture</th></tr>
      ${d.concurrence.map(r => `<tr><td class="l"><b>${esc(r[0])}</b>${r[6] ? `<span class="ch">${esc(r[6])}</span>` : ''}</td><td class="l mut">${esc(r[1])}</td><td>${esc(r[2])}</td><td>${esc(r[3])}</td><td>${esc(r[4])}</td>
        <td class="l ${r[5] ? 'acc' : 'mut'}" style="padding-left:12px">${r[5] ? '<b>concurrent fort</b>' : 'concurrent'}</td></tr>`).join('')}
    </table>` : '<p class="ok">Aucune boulangerie ni pâtisserie relevée dans la zone.</p>'}

    <h3>Comparaison au réseau</h3>
    <table><tr><th class="l">Point de vente</th><th class="l">Statut</th><th>Ménages</th><th>Dépense</th><th>Emprise</th><th>CA annuel</th><th class="l" style="padding-left:12px">Note</th></tr>
      ${d.reseau.map((r, i) => `<tr><td class="l"><b>${esc(r[0])}</b></td><td class="l mut">${esc(r[1])}</td><td>${esc(r[2])}</td><td>${esc(r[3])}</td><td>${esc(r[4])}</td><td class="n ${i ? '' : 'ok'}"><b>${esc(r[5])}</b></td><td class="l mut" style="padding-left:12px">${esc(r[6])}</td></tr>`).join('')}
    </table>
    ${d.notes.map(n => `<div class="note">${esc(n)}</div>`).join('')}

    <h3>Les hypothèses au moment de l'édition</h3>
    <div class="hyp">${d.hypotheses.map(r => `<div><span>${esc(r[0])}</span><b>${esc(r[1])}</b></div>`).join('')}</div>
    <div class="note"><b>Sources.</b> ${esc(d.sources)}</div>
  </div>`;
}

function renderDossier(c, x){
  const { esc } = x;
  const d = c.dossier;
  return `
  <div style="position:absolute;inset:0;z-index:1200;background:#EDE7DE;display:flex;flex-direction:column">
    <div style="display:flex;align-items:center;gap:10px;padding:10px 16px;background:var(--color-surface);border-bottom:0.5px solid var(--color-border-tertiary);flex:0 0 auto">
      <div class="t-section-title" style="font-size:16px">Dossier d'implantation</div>
      <div style="font-size:11px;color:var(--color-text-muted);flex:1">${esc(d.commune)} · ${esc(d.zone)} · les chiffres de la fiche, mis en page</div>
      <button ${x.A(d.pdf)} class="btn-primary" style="padding:7px 12px;font-size:12px${d.busy ? ';opacity:.6' : ''}">${d.busy ? 'PDF en cours…' : 'Télécharger le PDF'}</button>
      <button ${x.A(d.csv)} class="btn-secondary" style="padding:7px 12px;font-size:12px">Exporter les tableaux (CSV)</button>
      <button ${x.A(d.imprimer)} class="btn-secondary" style="padding:7px 12px;font-size:12px">Imprimer</button>
      <button ${x.A(d.fermer)} class="btn-secondary" style="padding:7px 12px;font-size:12px">Fermer</button>
    </div>
    <div id="sc-dossier" class="sc-scroll" style="flex:1;overflow:auto;padding:18px 16px 28px">
      <style>${DOSS_CSS}</style>
      ${dossierPage(Object.assign({}, d, { carte: d.img }), esc, 'assets/img/logo.png')}
    </div>
  </div>`;
}

/* --- Vues tabulaires et comparaison d'arrondissements ---------------------- */
const overlayCss = 'position:absolute;inset:0;z-index:1200;background:var(--color-bg);overflow:auto;padding:16px';
const boxCss = 'background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:10px;overflow-x:auto;overflow-y:visible';
const headCss = 'background:var(--color-background-secondary);border-bottom:0.5px solid var(--color-border-secondary);min-width:max-content';
const lineCss = 'align-items:center;border-bottom:0.5px solid var(--color-border-tertiary);font-size:12px;min-width:max-content';
const ZONES_GRID = '48px 180px 160px 62px 90px 112px 64px 220px 80px 130px 90px';
const CONC_GRID = '16px 230px 150px 170px 76px 64px 74px 64px 280px 64px';
const ARR_GRID = '190px 84px 104px 104px 150px 92px 76px 96px 84px 110px';

export function renderOverlays(c, x){
  const { esc } = x;
  if (c.dossier) return renderDossier(c, x);
  if (c.isZones) return `
  <div id="sc-table" class="sc-scroll" style="${overlayCss}">
    <div style="display:flex;align-items:baseline;gap:12px;margin-bottom:12px">
      <div class="t-section-title" style="font-size:16px">Zones candidates</div>
      <div style="font-size:11px;color:var(--color-text-muted);flex:1">${c.wiz.fait ? 'Balayage des arrondissements retenus par l’assistant — indépendant du cadrage de la carte' : 'Balayage de la vue carte courante'} · score minimum ${c.minScore} · clic sur une ligne pour ouvrir la fiche</div>
      <button ${x.A(c.exportZones)} class="btn-primary" style="padding:7px 12px;font-size:12px">Exporter CSV</button>
    </div>
    <div class="sc-scroll" style="${boxCss}">
      <div style="display:grid;grid-template-columns:${ZONES_GRID};${headCss}">
        ${c.zonesCols.map(k => `<button ${x.A(k.sort)} class="t-admin-label" style="border:none;background:transparent;text-align:left;padding:9px 8px;cursor:pointer">${esc(k.label)}${info(esc, k.tip)}</button>`).join('')}
      </div>
      ${c.zonesRows.map(r => `
      <div ${x.A(r.open)} class="hv-bg" style="display:grid;grid-template-columns:${ZONES_GRID};${lineCss};cursor:pointer">
        <span style="padding:8px;color:var(--color-text-muted)">${r.rang}</span>
        <span style="padding:8px;font-weight:500">${esc(r.commune)}</span>
        <span style="padding:8px;color:var(--color-text-muted)">${esc(r.arr)}</span>
        <span style="padding:8px;font-weight:600;color:#1b5e20">${r.score}</span>
        <span style="padding:8px">${esc(r.hh)}</span>
        <span style="padding:8px"${r.noms ? ` data-sc-tip="${esc(r.noms)}"` : ''}>${r.n}</span>
        <span style="padding:8px;color:${r.forts ? 'var(--color-primary)' : 'var(--color-text-muted)'}">${r.forts}</span>
        <span style="padding:8px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;${r.chainesN ? 'font-weight:500' : 'color:var(--color-text-muted)'}" title="${esc(r.chaines)}">${esc(r.chaines)}</span>
        <span style="padding:8px">${esc(r.emprise)}</span>
        <span style="padding:8px;font-weight:500">${esc(r.ca)}</span>
        <span style="padding:8px;color:var(--color-text-muted)">${esc(r.m2)}</span>
      </div>`).join('')}
      ${c.zonesRows.length ? '' : `<div style="padding:14px 12px;font-size:12px;color:var(--color-text-muted)">${esc(c.zonesEmpty)}</div>`}
    </div>
  </div>`;

  if (c.isTop5) return `
  <div id="sc-table" class="sc-scroll" style="${overlayCss}">
    <div style="display:flex;align-items:baseline;gap:12px;margin-bottom:12px">
      <div class="t-section-title" style="font-size:16px">Top 5 par province</div>
      <div style="font-size:11px;color:var(--color-text-muted);flex:1">Balayage de chaque province cochée sur toute son emprise · une zone par commune · sans score minimum (score sous ${c.minScore} en orange) · clic sur une ligne pour ouvrir la fiche</div>
      <button ${x.A(c.exportTop5)} class="btn-primary" style="padding:7px 12px;font-size:12px">Exporter CSV</button>
    </div>
    <div class="sc-scroll" style="${boxCss}">
      <div style="display:grid;grid-template-columns:${ZONES_GRID};${headCss}">
        ${c.top5Cols.map(k => `<span class="t-admin-label" style="padding:9px 8px;display:inline-flex;align-items:center">${esc(k.label)}${info(esc, k.tip)}</span>`).join('')}
      </div>
      ${c.top5.map(g => `
      <div style="display:flex;align-items:baseline;gap:10px;padding:10px 8px 6px;border-bottom:0.5px solid var(--color-border-tertiary);background:var(--color-background-secondary)">
        <span style="font-size:12.5px;font-weight:600">${esc(g.prov)}</span>
        <span style="font-size:11px;color:var(--color-text-muted)">${esc(g.detail)}</span>
      </div>
      ${g.rows.map(r => `
      <div ${x.A(r.open)} class="hv-bg" style="display:grid;grid-template-columns:${ZONES_GRID};${lineCss};cursor:pointer">
        <span style="padding:8px;color:var(--color-text-muted)">${r.rang}</span>
        <span style="padding:8px;font-weight:500">${esc(r.commune)}</span>
        <span style="padding:8px;color:var(--color-text-muted)">${esc(r.arr)}</span>
        <span style="padding:8px;font-weight:600;color:${r.score >= c.minScore ? '#1b5e20' : '#c17a2a'}">${r.score}</span>
        <span style="padding:8px">${esc(r.hh)}</span>
        <span style="padding:8px"${r.noms ? ` data-sc-tip="${esc(r.noms)}"` : ''}>${r.n}</span>
        <span style="padding:8px;color:${r.forts ? 'var(--color-primary)' : 'var(--color-text-muted)'}">${r.forts}</span>
        <span style="padding:8px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;${r.chaines !== '—' ? 'font-weight:500' : 'color:var(--color-text-muted)'}" title="${esc(r.chaines)}">${esc(r.chaines)}</span>
        <span style="padding:8px">${esc(r.emprise)}</span>
        <span style="padding:8px;font-weight:500">${esc(r.ca)}</span>
        <span style="padding:8px;color:var(--color-text-muted)">${esc(r.m2)}</span>
      </div>`).join('')}
      ${g.rows.length ? '' : `<div style="padding:10px 12px;font-size:12px;color:var(--color-text-muted)">Aucune zone hors des rayons d'exclusion dans cette province.</div>`}`).join('')}
      ${c.top5.length ? '' : `<div style="padding:14px 12px;font-size:12px;color:var(--color-text-muted)">${esc(c.top5Empty)}</div>`}
    </div>
  </div>`;

  if (c.isConc) return `
  <div id="sc-table" class="sc-scroll" style="${overlayCss}">
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px">
      <div class="t-section-title" style="font-size:16px">Concurrents</div>
      <input id="sc-q" type="text" placeholder="Filtrer par nom, commune, arrondissement" value="${esc(c.q)}" ${x.I(c.setQ)} style="flex:1;max-width:300px;padding:7px 9px;border:0.5px solid var(--color-border-secondary);border-radius:6px;background:var(--color-surface);font-size:12px;font-family:var(--font-ui);color:var(--color-text)">
      <div style="font-size:11px;color:var(--color-text-muted);flex:1">${esc(c.concCount)} · 400 lignes max</div>
      <button ${x.A(c.enrichAll)} class="btn-secondary" style="padding:7px 12px;font-size:12px">${esc(c.enrichAllLabel)}</button>
      <button ${x.A(c.exportConc)} class="btn-primary" style="padding:7px 12px;font-size:12px">Exporter CSV</button>
    </div>
    <div class="sc-scroll" style="${boxCss}">
      <div style="display:grid;grid-template-columns:${CONC_GRID};${headCss}">
        <span></span>
        ${['Enseigne', 'Commune', 'Arrondissement', 'Note / 5', 'Avis', 'Source', 'Force', 'Commentaire', 'Carte'].map(l => `<span class="t-admin-label" style="padding:9px 8px">${l}${info(esc, c.concTips[l])}</span>`).join('')}
      </div>
      ${c.concRows.map(r => `
      <div style="display:grid;grid-template-columns:${CONC_GRID};${lineCss}">
        <span style="width:8px;height:8px;border-radius:50%;background:${r.color}"></span>
        <span style="padding:6px 8px;font-weight:500;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc(r.name)}</span>
        <span style="padding:6px 8px;color:var(--color-text-muted)">${esc(r.commune)}</span>
        <span style="padding:6px 8px;color:var(--color-text-muted)">${esc(r.arr)}</span>
        <input id="sc-cn-${esc(r.id)}" type="number" min="0" max="5" step="0.1" placeholder="–" value="${esc(r.note)}" ${x.C(r.setNote)} style="width:58px;margin:4px 8px;${numCss}">
        <span style="padding:6px 8px;color:var(--color-text-muted)">${esc(r.avis)}</span>
        <span style="padding:6px 8px;color:var(--color-text-muted)">${esc(r.src)}</span>
        <span style="padding:6px 8px">${r.force} %</span>
        <input id="sc-cc-${esc(r.id)}" type="text" maxlength="200" placeholder="Commentaire terrain" value="${esc(r.comment)}" ${x.C(r.setComment)} style="margin:4px 8px;min-width:0;${txtCss}">
        <button ${x.A(r.locate)} class="icon-circle" style="width:22px;height:22px;margin:0 8px" title="Voir sur la carte">→</button>
      </div>`).join('')}
    </div>
  </div>`;

  if (c.isArr) return `
  <div id="sc-table" class="sc-scroll" style="${overlayCss}">
    <div style="display:flex;align-items:baseline;gap:12px;margin-bottom:12px">
      <div class="t-section-title" style="font-size:16px">Arrondissements</div>
      <div style="font-size:11px;color:var(--color-text-muted);flex:1">Clic sur une ligne pour filtrer la carte</div>
      <button ${x.A(c.exportArr)} class="btn-primary" style="padding:7px 12px;font-size:12px">Exporter CSV</button>
    </div>
    <div class="sc-scroll" style="${boxCss}">
      <div style="display:grid;grid-template-columns:${ARR_GRID};${headCss}">
        ${c.arrCols.map(k => `<button ${x.A(k.sort)} class="t-admin-label" style="border:none;background:transparent;text-align:left;padding:9px 8px;cursor:pointer">${esc(k.label)}${info(esc, k.tip)}</button>`).join('')}
      </div>
      ${c.arrRows.map(r => `
      <div ${x.A(r.pick)} class="hv-bg" style="display:grid;grid-template-columns:${ARR_GRID};${lineCss};cursor:pointer">
        <span style="padding:8px;font-weight:500">${esc(r.arr)}</span>
        <span style="padding:8px;color:var(--color-text-muted)">${r.communes}</span>
        <span style="padding:8px">${esc(r.pop)}</span>
        <span style="padding:8px">${esc(r.hh)}</span>
        <span style="padding:8px;font-weight:500">${esc(r.market)}</span>
        <span style="padding:8px">${r.shops}</span>
        <span style="padding:8px;color:var(--color-primary)">${r.strong}</span>
        <span style="padding:8px">${esc(r.dens)}</span>
        <span style="padding:8px">${esc(r.avg)}</span>
        <span style="padding:8px;color:var(--color-text-muted)">${esc(r.perShop)}</span>
      </div>`).join('')}
    </div>
  </div>`;

  if (c.compare) return `
  <div style="position:absolute;inset:0;z-index:700;background:var(--color-bg);display:flex;flex-direction:column">
    <div style="display:flex;align-items:center;gap:12px;padding:12px 16px;background:var(--color-surface);border-bottom:0.5px solid var(--color-border-tertiary)">
      <div class="t-section-title" style="font-size:16px">Comparaison d'arrondissements</div>
      <div style="flex:1"></div>
      <button ${x.A(c.toggleCompare)} class="btn-secondary" style="padding:6px 12px;font-size:12px">Retour à la carte</button>
    </div>
    <div id="sc-compare" class="sc-scroll" style="flex:1;display:grid;grid-template-columns:1fr 1fr;gap:16px;padding:16px;overflow-y:auto;align-content:start">
      ${c.compareCols.map(k => `
      <div style="background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px;padding:16px">
        <select ${x.C(k.setArr)} style="${selCss};margin-bottom:14px">${opts(k.options, k.arr)}</select>
        ${k.rows.map(r => `
        <div style="display:flex;align-items:baseline;justify-content:space-between;gap:10px;padding:7px 0;border-bottom:0.5px solid var(--color-border-tertiary)">
          <span style="font-size:12px;color:var(--color-text-muted)">${esc(r.k)}</span>
          <span style="font-size:13px;font-weight:500">${esc(r.v)}</span>
        </div>`).join('')}
        <div class="t-admin-label" style="margin:16px 0 8px">${esc(k.histTitle)}</div>
        <div style="display:flex;align-items:flex-end;gap:4px;height:96px">
          ${k.hist.map(h => `
          <div style="flex:1;display:flex;flex-direction:column;justify-content:flex-end;align-items:center;gap:4px;height:100%">
            <span style="font-size:9px;color:var(--color-text-muted)">${h.n}</span>
            <div style="width:100%;border-radius:3px 3px 0 0;background:${h.color};height:${h.h}"></div>
            <span style="font-size:9px;color:var(--color-text-muted)">${esc(h.label)}</span>
          </div>`).join('')}
        </div>
      </div>`).join('')}
    </div>
  </div>`;

  return '';
}

/* --- Modale « Magasins du réseau » et notification ------------------------- */
export function renderModal(c, x){
  const { esc } = x;
  const col = (k, green) => `
  <div style="flex:1 1 0;min-width:200px;border:${green ? '1px solid rgba(27,94,32,.4)' : '0.5px solid var(--color-border-tertiary)'};border-radius:10px;overflow:hidden">
    <div style="padding:12px 14px;background:${green ? 'rgba(27,94,32,.08)' : 'var(--color-background-secondary)'};border-bottom:0.5px solid var(--color-border-tertiary)">
      <div style="font-size:13px;font-weight:600;${green ? 'color:#1b5e20' : ''}">${esc(k.nom)}</div>
      <div style="font-size:11px;color:var(--color-text-muted)">${esc(k.statut)}</div>
    </div>
    ${k.rows.map(r => `
    <div style="display:flex;align-items:baseline;justify-content:space-between;gap:8px;padding:6px 12px;border-bottom:0.5px solid var(--color-border-tertiary)">
      <span style="font-size:11px;color:var(--color-text-muted)">${esc(r.k)}</span>
      <span style="font-size:12px;font-weight:500;text-align:right">${esc(r.v)}</span>
    </div>`).join('')}
    ${green ? '' : `
    <div style="display:flex;gap:6px;padding:10px 12px">
      <button ${x.A(k.locate)} class="btn-secondary" style="flex:1;padding:7px 6px;font-size:11px">Voir sur la carte</button>
      <button ${x.A(k.applyDepense)} class="btn-secondary" style="flex:1;padding:7px 6px;font-size:11px">Reprendre sa dépense</button>
    </div>`}
  </div>`;

  const modal = c.reseau ? `
  <div style="position:fixed;inset:0;z-index:900;background:rgba(34,34,34,.45);display:flex;align-items:center;justify-content:center;padding:32px;animation:fadeIn 140ms ease">
    <div style="background:var(--color-surface);border-radius:14px;width:100%;max-width:1100px;max-height:88vh;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 18px 50px rgba(0,0,0,.28)">
      <div style="display:flex;align-items:baseline;gap:14px;padding:16px 20px;border-bottom:0.5px solid var(--color-border-tertiary)">
        <div class="t-section-title" style="font-size:18px">Magasins du réseau — points de comparaison</div>
        <div style="font-size:11px;color:var(--color-text-muted);flex:1">Chiffres de l'étude GeoConsulting, Halle, 28-08-2024 · la colonne de droite reprend la zone évaluée en cours</div>
        <button ${x.A(c.closeReseau)} class="btn-secondary" style="padding:6px 12px;font-size:12px">Fermer</button>
      </div>
      <div id="sc-modal" class="sc-scroll" style="flex:1;overflow:auto;padding:18px 20px 22px">
        <div style="display:flex;gap:14px;align-items:flex-start">
          ${c.reseauCols.map(k => col(k, false)).join('')}
          ${c.hasZoneCol ? col(c.zoneCol, true) : ''}
        </div>
      </div>
    </div>
  </div>` : '';

  const wizard = renderWizard(c, x);

  const toast = c.toast ? `<div style="position:fixed;bottom:24px;right:24px;z-index:1300;background:#222222;color:#fff;border-radius:10px;padding:12px 18px;font-size:13px;box-shadow:0 10px 30px rgba(34,34,34,0.3);animation:toastIn 200ms ease;max-width:420px">${esc(c.toast)}</div>` : '';
  return modal + wizard + toast;
}

/* --- L'assistant : quatre questions, puis les points chauds ----------------
 * Il n'invente aucun calcul : il écrit dans les réglages que l'écran utilise
 * déjà, et montre en même temps ce que chaque réponse change. */
export function renderWizard(c, x){
  const { esc } = x;
  const w = c.wiz;
  if (!w || !w.etape) return '';
  const e = w.etape;

  const pas = `<div class="wz-pas">${w.pas.map((p, i) => `
    <button ${x.A(w.aller(i + 1))} class="${i + 1 === e ? 'on' : (i + 1 < e ? 'fait' : '')}"><b>${i + 1 < e ? '✓' : i + 1}</b>${esc(p[0])}<small>${esc(p[1])}</small></button>`).join('')}</div>`;

  const corps1 = `
    <div class="wz-q">Dans quelles provinces cherchez-vous ?</div>
    <p class="wz-a">Le chiffre est le nombre de boulangeries et pâtisseries relevées. Vous resserrerez sur un arrondissement à l’étape suivante.</p>
    ${['Bruxelles', 'Flandre', 'Wallonie'].map(reg => {
      const L = w.provinces.filter(p => p.reg === reg);
      return L.length ? `<div class="wz-reg">${esc(reg)}</div><div class="wz-prov">${L.map(p => `
        <button ${x.A(p.toggle)} class="wz-p${p.on ? ' on' : ''}"><span class="ck"></span>
          <div class="n">${esc(p.nom)}</div>
          <div class="s">${p.shops ? p.shops.toLocaleString('fr-BE') + ' commerces' : '—'}<br>${p.hh ? Math.round(p.hh).toLocaleString('fr-BE') + ' ménages' : ''}</div></button>`).join('')}</div>` : '';
    }).join('')}`;

  const corps2 = `
    <div class="wz-q">Quel arrondissement ?</div>
    <p class="wz-a">« Ménages par point de vente » dit où l’offre est la moins dense : plus le chiffre est haut, plus il reste de la place. C’est la colonne qui trie. « Dont chaînes » compte les enseignes déjà implantées — elles ne s’installent pas au hasard, leur présence valide la zone de chalandise autant qu’elle la dispute.</p>
    <div class="sc-ville wz-ville">
      <input id="wz-ville" type="text" autocomplete="off" spellcheck="false" placeholder="Vous connaissez la ville ? Tapez son nom" value="${esc(w.wville)}" ${x.I(w.setWville)} ${x.K(w.wvilleEntree)}>
      ${w.wvilles.length ? `<div class="res">${w.wvilles.map(v => `
        <button ${x.A(v.aller)}><span class="n">${esc(v.nom)}</span><span class="m">${esc(v.meta)}</span></button>`).join('')}</div>` : ''}
      ${w.wvilleVide ? `<div class="vide">${esc(w.wvilleVide)}</div>` : ''}
    </div>
    <table class="wz-t">
      <tr><th style="width:26px"></th><th>Arrondissement</th><th>Communes</th><th>Ménages</th><th>Commerces</th><th>dont forts</th><th>dont chaînes</th><th>Ménages / point de vente</th><th>Note moy.</th></tr>
      <tr ${x.A(w.choisirTous)} class="${w.arrTous ? 'on' : ''}"><td><span class="rad"></span></td><td>Tous les arrondissements</td>
        <td colspan="7" style="text-align:left;color:var(--color-text-muted)">toute la sélection de provinces</td></tr>
      ${w.arrs.map(a => `<tr ${x.A(a.choisir)} class="${a.on ? 'on' : ''}">
        <td><span class="rad"></span></td><td>${esc(a.nom)}</td><td>${a.communes}</td><td>${esc(a.hhTxt)}</td>
        <td>${a.shops}</td><td>${a.strong}</td>
        <td${a.marquesTxt ? ` title="${esc(a.marquesTxt)}"` : ''}>${a.chains}${a.marquesTxt ? `<em class="mq">${esc(a.marquesTxt)}</em>` : ''}</td>
        <td><span class="bar" style="width:${a.barre}px"></span>${esc(a.perTxt)}</td><td>${esc(a.avgTxt)}</td></tr>`).join('')}
    </table>
    ${w.arrs.length ? '' : '<p class="wz-a" style="margin-top:10px">Aucun arrondissement dans les provinces cochées — revenez à l’étape 1.</p>'}`;

  const n = w.notes;
  const corps3 = `
    <div class="wz-q">Qu’est-ce qu’un vrai concurrent, pour vous ?</div>
    <p class="wz-a">Deux notes suffisent à le dire. En dessous de la première, le commerce est ignoré ; au-dessus de la seconde, il interdit une implantation autour de lui. Entre les deux, il pèse à proportion de sa note.</p>
    <div class="wz-seuil">
      <div class="wz-s"><div class="k">En dessous, ce n’est pas un concurrent</div>
        <div class="v"><input id="wz-weak" value="${w.weak.toFixed(1).replace('.', ',')}" ${x.C(w.setWeak)}><em>★ sur 5</em></div>
        <div class="s">Un commerce noté sous cette barre ne pèse rien dans la pression concurrentielle. <b>${n.ignores}</b> commerce${n.ignores > 1 ? 's sortent' : ' sort'} ainsi du calcul.</div></div>
      <div class="wz-s fort"><div class="k">Au-dessus, c’est un concurrent fort</div>
        <div class="v"><input id="wz-thresh" value="${w.thresh.toFixed(1).replace('.', ',')}" ${x.C(w.setThresh)}><em>★ sur 5</em></div>
        <div class="s">Zone rouge de <input id="wz-radius" value="${w.radius.toFixed(1).replace('.', ',')}" ${x.C(w.setRadius)} style="width:44px;border:1px solid var(--color-border-secondary);border-radius:5px;padding:1px 4px;font:700 11px var(--font-ui);text-align:center"> km autour de lui, et poids majoré de moitié. <b>${n.forts}</b> commerce${n.forts > 1 ? 's sont' : ' est'} dans ce cas.</div></div>
    </div>
    <div class="wz-q" style="margin-top:4px">Où ces deux barres coupent</div>
    <p class="wz-a">${n.notes ? 'Les ' + n.notes + ' commerces notés de la sélection, par tranche de note.' : 'Aucun commerce noté dans cette sélection : les deux barres ne coupent rien pour l’instant. La force vient alors des signaux OpenStreetMap (enseigne, site, horaires), et les notes Google se chargent depuis l’écran.'}</p>
    ${n.notes ? `<div class="wz-dist">
      ${n.bars.map(b => `<div><em>${b.n}</em><i class="${b.cls}" style="height:${Math.round(58 * b.h / 100)}px"></i><span>${esc(b.label)}</span></div>`).join('')}
      <div class="wz-coupe" style="left:${n.xWeak.toFixed(1)}%"><span>${w.weak.toFixed(1).replace('.', ',')} ★</span></div>
      <div class="wz-coupe f" style="left:${n.xFort.toFixed(1)}%"><span>${w.thresh.toFixed(1).replace('.', ',')} ★ — fort</span></div>
    </div>` : ''}
    <div class="wz-cnt"><span class="ign">${n.ignores} ignoré${n.ignores > 1 ? 's' : ''}</span><span>${n.comptes} compté${n.comptes > 1 ? 's' : ''} à proportion</span><span class="fort">${n.forts} fort${n.forts > 1 ? 's' : ''} → autant de zones rouges</span><span class="ign">${n.sans} sans note</span></div>

    <div class="wz-q" style="margin-top:14px">Le terrain que vous cherchez</div>
    <p class="wz-a">Deux conditions de plus, facultatives. La première cherche le vide : un emplacement où personne n’est installé dans le rayon. La seconde écarte les campagnes : il faut du monde autour.</p>
    <div class="wz-seuil">
      <div class="wz-s"><div class="k">Concurrents dans le rayon</div>
        <div class="v"><input id="wz-nmax" value="${esc(w.nMaxTxt)}" placeholder="sans limite" ${x.C(w.setNMax)} style="width:104px;font-size:15px"><em>au plus</em></div>
        <div class="s"><button ${x.A(w.toggleSansBoul)} class="btn-secondary" style="padding:3px 9px;font-size:10.5px;${w.sansBoul ? 'border-color:var(--color-primary);color:var(--color-primary);font-weight:600' : ''}">${w.sansBoul ? '✓ aucune boulangerie' : 'aucune boulangerie'}</button> — laissez vide pour ne pas filtrer.</div></div>
      <div class="wz-s"><div class="k">Ménages minimum dans le rayon</div>
        <div class="v"><input id="wz-hhmin" value="${esc(w.hhMinTxt)}" placeholder="0" ${x.C(w.setHhMin)} style="width:104px;font-size:15px"><em>ménages</em></div>
        <div class="s">La densité, là où elle compte : les ménages du rayon de ${w.radius.toFixed(1).replace('.', ',')} km, pas ceux de la commune. À 0, aucun minimum.</div></div>
    </div>
    <div class="wz-seuil" style="grid-template-columns:1fr">
      <div class="wz-s"><div class="k">Près d’un zoning d’activité</div>
        <div class="v"><input id="wz-zone" value="${esc(w.zoneMaxTxt)}" placeholder="sans condition" ${x.C(w.setZoneMax)} style="width:134px;font-size:15px"${w.zoningPret ? '' : ' disabled'}><em>km au plus</em>
          ${w.zoningPret ? `<button ${x.A(w.toggleZoning)} class="btn-secondary" style="padding:4px 10px;font-size:10.5px;margin-left:6px;${w.zoneMax != null ? 'border-color:var(--color-primary);color:var(--color-primary);font-weight:600' : ''}">${w.zoneMax != null ? '✓ à 2 km d’un zoning' : 'à 2 km d’un zoning'}</button>` : ''}</div>
        <div class="s">${w.zoningPret
          ? 'Zones d’activité, de commerce et de vente relevées sur OpenStreetMap — <b>' + w.zoning.toLocaleString('fr-BE') + '</b> en Belgique. La distance se mesure au <b>bord</b> de la zone, pas à son centre. Les travailleurs d’un parc déjeunent à côté ; le passage y est en semaine, pas le dimanche.'
          : 'Le relevé du zoning n’est pas encore en cache. Il se fait le dimanche, séparément des commerces — le filtre s’allumera tout seul quand les zones seront là.'}</div></div>
    </div>`;

  const corps4 = `
    <div class="wz-q">Quel chiffre d’affaires visez-vous ?</div>
    <p class="wz-a">L’assistant ne retiendra que les emplacements dont le CA théorique atteint ce montant, au modèle de l’étude Halle. Laissez à 0 pour ne pas filtrer.</p>
    <div class="wz-ca">
      <div class="big"><div class="k">CA annuel TTC visé</div><input id="wz-ca" value="${esc(w.caViseTxt)}" placeholder="0" ${x.C(w.setCaVise)}></div>
      <div class="eq">CA = ménages du rayon × dépense par ménage × emprise ÷ (1 − passage)<br>
        À ce montant et à une emprise de <b>${esc(w.empriseVise)}</b>, il faut environ <b>${esc(w.hhVises)} ménages</b> dans le rayon de ${w.radius.toFixed(1).replace('.', ',')} km.</div>
    </div>
    <div class="wz-q" style="margin-top:6px">Les hypothèses du modèle</div>
    <p class="wz-a">Elles viennent de l’étude GeoConsulting (Halle, août 2024) et sont les mêmes que dans le panneau de gauche.</p>
    <div class="wz-hyp">
      ${w.hyp.map((h, i) => `<label><span class="k">${esc(h.k)} (${esc(h.u)})</span><input id="wz-hyp-${i}" value="${esc(h.v)}" ${x.C(h.set)}></label>`).join('')}
    </div>`;

  const corps = e === 1 ? corps1 : e === 2 ? corps2 : e === 3 ? corps3 : corps4;
  const resume = e === 4 && w.obstacle ? w.obstacle
    : e === 1 ? w.provResume : e === 2 ? w.arrResume
    : e === 3 ? 'Les deux barres sont enregistrées dans les hypothèses : elles servent aussi à l’écran hors assistant.'
    : (w.caVise ? 'Le balayage ne gardera que les emplacements à ' + w.resume[4].replace('CA ≥ ', '') + ' ou plus.' : 'Sans plancher, tous les emplacements au-dessus du score minimum sont gardés.');

  return `
  <div class="wz-ov">
    <div class="wz">
      <button ${x.A(w.fermer)} class="wz-x">✕</button>
      <div class="wz-hd"><h2>Où puis-je ouvrir, et pour combien ?</h2>
        <p>Quatre questions. À la fin, la carte ne montre plus que les emplacements qui tiennent vos conditions.</p></div>
      ${pas}
      <div id="sc-wiz" class="wz-corps sc-scroll">${corps}</div>
      <div class="wz-pd"><span class="t">${esc(resume)}</span><span class="sp"></span>
        ${e > 1 ? `<button ${x.A(w.aller(e - 1))} class="btn-secondary" style="padding:7px 14px;font-size:12px">← Retour</button>` : `<button ${x.A(w.fermer)} class="btn-secondary" style="padding:7px 14px;font-size:12px">Annuler</button>`}
        ${e < 4 ? `<button ${x.A(w.aller(e + 1))} class="btn-primary" style="padding:8px 18px;font-size:12px"${e === 1 && w.provVide ? ' disabled' : ''}>Continuer →</button>`
                : `<button ${x.A(w.terminer)} class="btn-primary" style="padding:8px 18px;font-size:12px">Voir les points chauds →</button>`}
      </div>
    </div>
  </div>`;
}
