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
    <button ${x.A(c.openReseau)} class="btn-secondary" style="padding:7px 12px;font-size:12px;white-space:nowrap">Magasins du réseau</button>
    <button ${x.A(c.toggleCompare)} class="btn-secondary" style="padding:7px 12px;font-size:12px;white-space:nowrap">${c.compare ? 'Retour à la carte' : 'Comparer 2 arrondissements'}</button>
    <button ${x.A(c.reload)} class="btn-secondary" style="padding:7px 12px;font-size:12px;white-space:nowrap">Recharger les données</button>
    <button ${x.A(c.exportCsv)} class="btn-primary" style="padding:7px 14px;font-size:12px;white-space:nowrap">Exporter les candidats (${c.nCandidates})</button>
  </div>
  <div style="display:flex;gap:4px;padding:0 18px;background:var(--color-surface);border-bottom:0.5px solid var(--color-border-tertiary);flex:0 0 auto">
    ${c.views.map(v => `<button ${x.A(v.go)} class="hv-text" style="border:none;border-bottom:2px solid ${v.border};background:transparent;padding:9px 14px 7px;font-family:var(--font-ui);font-size:12px;font-weight:500;cursor:pointer;color:${v.color}">${esc(v.label)}</button>`).join('')}
  </div>`;
}

/* --- Panneau gauche : filtres, couches, hypothèses, population, notes ------ */
export function renderLeft(c, x){
  const { esc } = x;
  const check = (on, fn, extra) => `<input type="checkbox"${on ? ' checked' : ''} ${x.C(fn)} style="accent-color:var(--color-primary);${extra || ''}">`;
  return `
  <div class="t-admin-label" style="margin-bottom:8px">Provinces &amp; régions</div>
  <div style="display:flex;flex-direction:column;gap:6px;margin-bottom:20px">
    ${c.provinces.map(p => `
    <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer">
      ${check(p.on, p.toggle)}
      <span style="flex:1">${esc(p.name)}</span>
      <span style="font-size:11px;color:var(--color-text-muted)">${esc(p.count)}</span>
    </label>`).join('')}
  </div>

  <div class="t-admin-label" style="margin-bottom:6px">Arrondissement</div>
  <select ${x.C(c.setArr)} style="${selCss};margin-bottom:20px">${opts(c.arrOptions, c.arr)}</select>

  <div class="t-admin-label" style="margin-bottom:4px">Note Google minimale — ${live('minRatingLabel', esc, c)}</div>
  <input type="range" min="0" max="5" step="0.1" value="${c.minRating}" ${x.I(c.slideMinRating)} ${x.C(c.setMinRating)}>
  <div style="font-size:11px;color:var(--color-text-muted);margin:2px 0 18px">${live('ratingCoverage', esc, c)}</div>

  <div class="t-admin-label" style="margin-bottom:4px">Ménages minimum par commune — ${live('minHhLabel', esc, c)}</div>
  <input type="range" min="0" max="30000" step="500" value="${c.minHh}" ${x.I(c.slideMinHh)} ${x.C(c.setMinHh)}>
  <div style="font-size:11px;color:var(--color-text-muted);margin:2px 0 18px">${live('communeCoverage', esc, c)}</div>

  <div class="t-admin-label" style="margin-bottom:4px">Rayon d'exclusion — ${live('radiusLabel', esc, c)}</div>
  <input type="range" min="0.5" max="5" step="0.1" value="${c.radius}" ${x.I(c.slideRadius)} ${x.C(c.setRadius)}>
  <div style="font-size:11px;color:var(--color-text-muted);margin:2px 0 18px">Approximation de l'isochrone 15–20 min voiture</div>

  <div class="t-admin-label" style="margin-bottom:4px">Seuil « concurrent fort » — ${live('threshLabel', esc, c)}</div>
  <input type="range" min="3.5" max="5" step="0.1" value="${c.thresh}" ${x.I(c.slideThresh)} ${x.C(c.setThresh)}>
  <div style="font-size:11px;color:var(--color-text-muted);margin:2px 0 20px">${live('threshHint', esc, c)}</div>

  <div style="height:0.5px;background:var(--color-border-tertiary);margin-bottom:16px"></div>

  <div class="t-admin-label" style="margin-bottom:8px">Lecture de la carte</div>
  <label style="display:flex;align-items:center;gap:10px;padding:10px 12px;border:1px solid rgba(27,94,32,.35);border-radius:8px;background:rgba(27,94,32,.07);cursor:pointer;margin-bottom:10px">
    ${check(c.onlyPrio, c.toggleOnlyPrio, 'accent-color:#1b5e20;width:16px;height:16px')}
    <span style="flex:1">
      <span style="display:block;font-size:13px;font-weight:500;color:#1b5e20">Zones intéressantes uniquement</span>
      <span style="display:block;font-size:11px;color:var(--color-text-muted)">Masque concurrents et zones rouges · ${esc(c.prioCount)} zones retenues</span>
    </span>
  </label>
  <div class="t-admin-label" style="margin-bottom:4px">Score minimum — ${live('minScoreLabel', esc, c)} / 100</div>
  <input type="range" min="0" max="95" step="5" value="${c.minScore}" ${x.I(c.slideMinScore)} ${x.C(c.setMinScore)}>
  <div style="height:12px"></div>
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-bottom:16px">
    <button ${x.A(c.presetPrio)} class="btn-secondary" style="padding:8px 6px;font-size:12px">Opportunités</button>
    <button ${x.A(c.presetConc)} class="btn-secondary" style="padding:8px 6px;font-size:12px">Concurrence</button>
  </div>

  <div class="t-admin-label" style="margin-bottom:8px;color:#1b5e20">+ &nbsp;Potentiel</div>
  <div style="display:flex;flex-direction:column;gap:7px;margin-bottom:16px;border-left:2px solid rgba(27,94,32,.35);padding-left:10px">
    ${c.layersPlus.map(l => `
    <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer">
      ${check(l.on, l.toggle)}
      <span>${esc(l.name)}</span>
    </label>`).join('')}
  </div>

  <div class="t-admin-label" style="margin-bottom:8px;color:var(--color-primary)">− &nbsp;Contraintes</div>
  <div style="display:flex;flex-direction:column;gap:7px;margin-bottom:20px;border-left:2px solid rgba(141,29,44,.35);padding-left:10px">
    ${c.layersMinus.map(l => `
    <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer">
      ${check(l.on, l.toggle)}
      <span>${esc(l.name)}</span>
    </label>`).join('')}
  </div>

  <div style="height:0.5px;background:var(--color-border-tertiary);margin-bottom:16px"></div>

  <div style="display:flex;align-items:baseline;justify-content:space-between;gap:8px;margin-bottom:8px">
    <div class="t-admin-label">Hypothèses du modèle</div>
    <button ${x.A(c.exportParams)} style="border:none;background:transparent;padding:0;font-family:var(--font-ui);font-size:11px;color:var(--color-primary);cursor:pointer">Exporter CSV</button>
  </div>
  <div style="font-size:11px;color:var(--color-text-muted);margin-bottom:8px">Enregistrées automatiquement et reprises dans ceo_zones.</div>
  <div style="border:0.5px solid var(--color-border-tertiary);border-radius:8px;overflow:hidden;margin-bottom:14px">
    ${c.params.map((p, i) => `
    <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;padding:7px 9px;border-bottom:0.5px solid var(--color-border-tertiary);background:var(--color-surface)">
      <span style="font-size:11px;color:var(--color-text-muted);line-height:1.35">${esc(p.k)}</span>
      <input id="sc-p${i}" type="number" step="any" value="${esc(p.v)}" ${x.C(p.set)} style="width:68px;flex:0 0 auto;${numCss}">
    </div>`).join('')}
  </div>
  <div style="font-size:11px;color:var(--color-text-muted);line-height:1.5">${esc(c.empriseHint)}<br><br>Réseau : 416 € (Max&amp;Sandra), 550 € (Berlo), 586 € (Halle). Emprise Halle 15,5 % pour un CA de 1.296.881 € TTC sur 250 m².</div>

  <div style="height:0.5px;background:var(--color-border-tertiary);margin:16px 0"></div>

  <div class="t-admin-label" style="margin-bottom:6px">Population</div>
  <div style="font-size:11px;color:var(--color-text-muted);line-height:1.5;margin-bottom:8px">${esc(c.popCoverage)}</div>
  <label style="display:block;font-size:12px;color:var(--color-text);border:0.5px dashed var(--color-border-secondary);border-radius:8px;padding:10px;text-align:center;cursor:pointer">
    Importer un CSV StatBel (code NIS ; population)
    <input type="file" accept=".csv,.txt" ${x.C(c.importPops)} style="display:none">
  </label>

  <div style="height:0.5px;background:var(--color-border-tertiary);margin:16px 0"></div>

  <div class="t-admin-label" style="margin-bottom:6px">Notes Google</div>
  <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;padding:8px 9px;border:0.5px solid var(--color-border-tertiary);border-radius:8px;background:var(--color-background-secondary)">
    <div style="width:8px;height:8px;border-radius:50%;flex:0 0 auto;background:${c.gOk ? '#1b5e20' : '#8D1D2C'}"></div>
    <div style="flex:1;font-size:11px;line-height:1.45;color:var(--color-text)">${esc(c.gLabel)}</div>
    ${c.gOk ? '' : `<button ${x.A(c.goParams)} class="btn-secondary" style="flex:0 0 auto;padding:4px 9px;font-size:11px">Paramètres</button>`}
  </div>
  <button ${x.A(c.enrich)} class="btn-secondary" style="width:100%;padding:8px;font-size:12px${c.gOk ? '' : ';opacity:.6'}">${esc(c.enrichLabel)}</button>
  <div style="font-size:11px;color:var(--color-text-muted);margin-top:6px;line-height:1.5">${esc(c.gkeyHint)}</div>`;
}

/* --- Habillage de la carte : légende, ligne d'état, voile de chargement ---- */
export function renderMapUi(c, x){
  const { esc } = x;
  return `
  <div style="position:absolute;top:12px;right:12px;z-index:500;background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:12px;padding:12px 14px;width:208px;box-shadow:0 2px 10px rgba(0,0,0,.08)">
    <div class="t-admin-label" style="margin-bottom:8px">Légende</div>
    ${c.legend.map(i => `
    <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;font-size:11px;line-height:1.3">
      <div style="width:12px;height:12px;border-radius:50%;flex:0 0 auto;background:${i.color};border:1px solid rgba(0,0,0,.15)"></div>
      <span>${esc(i.label)}</span>
    </div>`).join('')}
    <div style="height:0.5px;background:var(--color-border-tertiary);margin:8px 0"></div>
    <div style="font-size:11px;color:var(--color-text-muted);line-height:1.4">Clic sur la carte hors zone rouge = évaluer une zone candidate.</div>
  </div>

  <div style="position:absolute;bottom:14px;left:14px;z-index:500;background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:10px;padding:8px 12px;font-size:11px;color:var(--color-text-muted)">${live('statsLine', esc, c)}</div>

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
    <div class="t-admin-label" style="margin-bottom:4px">Zone candidate</div>
    <div class="t-section-title" style="font-size:18px;margin-bottom:2px">${esc(c.selCommune)}</div>
    <div style="font-size:12px;color:var(--color-text-muted);margin-bottom:14px">${esc(c.selGeo)}</div>

    <div style="background:${c.selVerdictBg};border-radius:10px;padding:12px 14px;margin-bottom:16px">
      <div style="font-size:12px;color:${c.selVerdictColor};font-weight:600;margin-bottom:2px">${esc(c.selVerdict)}</div>
      <div style="font-size:11px;color:var(--color-text-muted);line-height:1.5">${esc(c.selVerdictNote)}</div>
    </div>

    ${c.selRows.map(r => `
    <div style="${rowCss}">
      <span style="font-size:12px;color:var(--color-text-muted)">${esc(r.k)}</span>
      <span style="font-size:13px;font-weight:500;text-align:right">${esc(r.v)}</span>
    </div>`).join('')}

    <div style="display:flex;align-items:baseline;justify-content:space-between;gap:10px;padding:14px 0 4px">
      <span style="font-size:13px;font-weight:500">CA annuel estimé TTC</span>
      <span style="font-family:var(--font-display);font-size:22px;color:var(--color-primary)">${esc(c.selCa)}</span>
    </div>
    <div style="font-size:11px;color:var(--color-text-muted);margin-bottom:14px">${esc(c.selCaDetail)}</div>

    <button ${x.A(c.addCandidate)} class="btn-primary" style="width:100%;padding:10px">Ajouter aux candidats</button>

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

  return `${sel}
  <div class="t-admin-label" style="margin:22px 0 8px">Zones candidates retenues</div>
  ${c.candidates.map(k => `
  <div style="display:flex;align-items:center;gap:8px;padding:8px 0;border-bottom:0.5px solid var(--color-border-tertiary)">
    <div style="flex:1;min-width:0">
      <div style="font-size:12px;font-weight:500">${esc(k.name)}</div>
      <div style="font-size:11px;color:var(--color-text-muted)">${esc(k.meta)}</div>
    </div>
    <button ${x.A(k.focus)} class="icon-circle" style="width:22px;height:22px" title="Voir sur la carte">→</button>
    <button ${x.A(k.remove)} class="icon-circle" style="width:22px;height:22px" title="Retirer">×</button>
  </div>`).join('')}
  ${c.noCandidates ? '<div style="font-size:12px;color:var(--color-text-muted)">Aucune zone retenue pour l\'instant.</div>' : ''}`;
}

/* --- Vues tabulaires ceo_ et comparaison d'arrondissements ----------------- */
const overlayCss = 'position:absolute;inset:0;z-index:1200;background:var(--color-bg);overflow:auto;padding:16px';
const boxCss = 'background:var(--color-surface);border:0.5px solid var(--color-border-tertiary);border-radius:10px;overflow-x:auto;overflow-y:visible';
const headCss = 'background:var(--color-background-secondary);border-bottom:0.5px solid var(--color-border-secondary);min-width:max-content';
const lineCss = 'align-items:center;border-bottom:0.5px solid var(--color-border-tertiary);font-size:12px;min-width:max-content';
const ZONES_GRID = '56px 200px 190px 66px 96px 108px 84px 150px 96px';
const CONC_GRID = '16px 230px 150px 170px 76px 64px 74px 64px 280px 64px';
const ARR_GRID = '190px 84px 104px 104px 150px 92px 76px 96px 84px 110px';

export function renderOverlays(c, x){
  const { esc } = x;
  if (c.isZones) return `
  <div id="sc-table" class="sc-scroll" style="${overlayCss}">
    <div style="display:flex;align-items:baseline;gap:12px;margin-bottom:12px">
      <div class="t-section-title" style="font-size:16px">ceo_zones</div>
      <div style="font-size:11px;color:var(--color-text-muted);flex:1">Balayage de la vue carte courante · score minimum ${c.minScore} · clic sur une ligne pour ouvrir la fiche</div>
      <button ${x.A(c.exportZones)} class="btn-primary" style="padding:7px 12px;font-size:12px">Exporter CSV</button>
    </div>
    <div class="sc-scroll" style="${boxCss}">
      <div style="display:grid;grid-template-columns:${ZONES_GRID};${headCss}">
        ${c.zonesCols.map(k => `<button ${x.A(k.sort)} class="t-admin-label" style="border:none;background:transparent;text-align:left;padding:9px 8px;cursor:pointer">${esc(k.label)}</button>`).join('')}
      </div>
      ${c.zonesRows.map(r => `
      <div ${x.A(r.open)} class="hv-bg" style="display:grid;grid-template-columns:${ZONES_GRID};${lineCss};cursor:pointer">
        <span style="padding:8px;color:var(--color-text-muted)">${r.rang}</span>
        <span style="padding:8px;font-weight:500">${esc(r.commune)}</span>
        <span style="padding:8px;color:var(--color-text-muted)">${esc(r.arr)}</span>
        <span style="padding:8px;font-weight:600;color:#1b5e20">${r.score}</span>
        <span style="padding:8px">${esc(r.hh)}</span>
        <span style="padding:8px">${r.n}</span>
        <span style="padding:8px">${esc(r.emprise)}</span>
        <span style="padding:8px;font-weight:500">${esc(r.ca)}</span>
        <span style="padding:8px;color:var(--color-text-muted)">${esc(r.m2)}</span>
      </div>`).join('')}
      ${c.zonesRows.length ? '' : `<div style="padding:14px 12px;font-size:12px;color:var(--color-text-muted)">${esc(c.zonesEmpty)}</div>`}
    </div>
  </div>`;

  if (c.isConc) return `
  <div id="sc-table" class="sc-scroll" style="${overlayCss}">
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px">
      <div class="t-section-title" style="font-size:16px">ceo_concurrents</div>
      <input id="sc-q" type="text" placeholder="Filtrer par nom, commune, arrondissement" value="${esc(c.q)}" ${x.I(c.setQ)} style="flex:1;max-width:300px;padding:7px 9px;border:0.5px solid var(--color-border-secondary);border-radius:6px;background:var(--color-surface);font-size:12px;font-family:var(--font-ui);color:var(--color-text)">
      <div style="font-size:11px;color:var(--color-text-muted);flex:1">${esc(c.concCount)} · 400 lignes max</div>
      <button ${x.A(c.enrichAll)} class="btn-secondary" style="padding:7px 12px;font-size:12px">${esc(c.enrichAllLabel)}</button>
      <button ${x.A(c.exportConc)} class="btn-primary" style="padding:7px 12px;font-size:12px">Exporter CSV</button>
    </div>
    <div class="sc-scroll" style="${boxCss}">
      <div style="display:grid;grid-template-columns:${CONC_GRID};${headCss}">
        <span></span>
        ${['Enseigne', 'Commune', 'Arrondissement', 'Note / 5', 'Avis', 'Source', 'Force', 'Commentaire', 'Carte'].map(l => `<span class="t-admin-label" style="padding:9px 8px">${l}</span>`).join('')}
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
      <div class="t-section-title" style="font-size:16px">ceo_arrondissements</div>
      <div style="font-size:11px;color:var(--color-text-muted);flex:1">Clic sur une ligne pour filtrer la carte</div>
      <button ${x.A(c.exportArr)} class="btn-primary" style="padding:7px 12px;font-size:12px">Exporter CSV</button>
    </div>
    <div class="sc-scroll" style="${boxCss}">
      <div style="display:grid;grid-template-columns:${ARR_GRID};${headCss}">
        ${c.arrCols.map(k => `<button ${x.A(k.sort)} class="t-admin-label" style="border:none;background:transparent;text-align:left;padding:9px 8px;cursor:pointer">${esc(k.label)}</button>`).join('')}
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

  const toast = c.toast ? `<div style="position:fixed;bottom:24px;right:24px;z-index:1300;background:#222222;color:#fff;border-radius:10px;padding:12px 18px;font-size:13px;box-shadow:0 10px 30px rgba(34,34,34,0.3);animation:toastIn 200ms ease;max-width:420px">${esc(c.toast)}</div>` : '';
  return modal + toast;
}
