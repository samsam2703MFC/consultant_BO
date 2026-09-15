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

/* --- Panneau gauche : filtres, couches, hypothèses, population, notes ------ */
export function renderLeft(c, x){
  const { esc } = x;
  const check = (on, fn, extra) => `<input type="checkbox"${on ? ' checked' : ''} ${x.C(fn)} style="accent-color:var(--color-primary);${extra || ''}">`;
  return `
  <div class="t-admin-label" style="margin-bottom:6px">Chercher une ville</div>
  <div class="sc-ville">
    <input id="sc-ville" type="text" autocomplete="off" spellcheck="false" placeholder="Nom de commune" value="${esc(c.ville)}" ${x.I(c.setVille)} ${x.K(c.villeEntree)}>
    ${c.villes.length ? `<div class="res">${c.villes.map(v => `
      <button ${x.A(v.aller)}><span class="n">${esc(v.nom)}</span><span class="m">${esc(v.meta)}</span></button>`).join('')}</div>` : ''}
    ${c.villeVide ? `<div class="vide">${esc(c.villeVide)}</div>` : ''}
  </div>

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

  <div class="t-admin-label" style="margin-bottom:4px">Note Google minimale — ${live('minRatingLabel', esc, c)}${info(esc, c.tips.minRating)}</div>
  <input type="range" min="0" max="5" step="0.1" value="${c.minRating}" ${x.I(c.slideMinRating)} ${x.C(c.setMinRating)}>
  <div style="font-size:11px;color:var(--color-text-muted);margin:2px 0 18px">${live('ratingCoverage', esc, c)}</div>

  <div class="t-admin-label" style="margin-bottom:4px">Ménages minimum par commune — ${live('minHhLabel', esc, c)}${info(esc, c.tips.minHh)}</div>
  <input type="range" min="0" max="30000" step="500" value="${c.minHh}" ${x.I(c.slideMinHh)} ${x.C(c.setMinHh)}>
  <div style="font-size:11px;color:var(--color-text-muted);margin:2px 0 18px">${live('communeCoverage', esc, c)}</div>

  <div class="t-admin-label" style="margin-bottom:4px">Rayon d'exclusion — ${live('radiusLabel', esc, c)}${info(esc, c.tips.radius)}</div>
  <input type="range" min="0.5" max="5" step="0.1" value="${c.radius}" ${x.I(c.slideRadius)} ${x.C(c.setRadius)}>
  <div style="font-size:11px;color:var(--color-text-muted);margin:2px 0 18px">Approximation de l'isochrone 15–20 min voiture</div>

  <div class="t-admin-label" style="margin-bottom:4px">Seuil « concurrent fort » — ${live('threshLabel', esc, c)}${info(esc, c.tips.thresh)}</div>
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
  <div class="t-admin-label" style="margin-bottom:4px">Score minimum — ${live('minScoreLabel', esc, c)} / 100${info(esc, c.tips.minScore)}</div>
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
      <span style="font-size:11px;color:var(--color-text-muted);line-height:1.35;display:inline-flex;align-items:center">${esc(p.k)}${info(esc, p.i)}</span>
      <input id="sc-p${i}" type="number" step="any" value="${esc(p.v)}" ${x.C(p.set)} style="width:68px;flex:0 0 auto;${numCss}">
    </div>`).join('')}
  </div>
  <div style="font-size:11px;color:var(--color-text-muted);line-height:1.5">${esc(c.empriseHint)}<br><br>Réseau : 416 € (Max&amp;Sandra), 550 € (Berlo), 586 € (Halle). Emprise Halle 15,5 % pour un CA de 1.296.881 € TTC sur 250 m².</div>

  <div style="height:0.5px;background:var(--color-border-tertiary);margin:16px 0"></div>

  <div class="t-admin-label" style="margin-bottom:6px;display:inline-flex;align-items:center">Calage sur le réseau${info(esc, c.calage.tip)}</div>
  <div style="font-size:11px;color:var(--color-text-muted);line-height:1.5;margin-bottom:8px">${esc(c.calage.intro)}</div>
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
  <div style="font-size:11px;color:var(--color-text-muted);margin-top:6px;line-height:1.5">${esc(c.calage.note)}</div>

  <div style="height:0.5px;background:var(--color-border-tertiary);margin:16px 0"></div>

  <div class="t-admin-label" style="margin-bottom:6px;display:inline-flex;align-items:center">Population${info(esc, c.popTip)}</div>
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
    <div class="t-admin-label" style="margin-bottom:4px">${c.selRang ? 'Point chaud nº' + c.selRang : 'Zone candidate'}</div>
    <div class="t-section-title" style="font-size:18px;margin-bottom:2px">${esc(c.selCommune)}</div>
    <div style="font-size:12px;color:var(--color-text-muted);margin-bottom:14px">${esc(c.selGeo)}</div>

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
        <span style="padding:8px">${r.n}</span>
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
        <span style="padding:8px">${r.n}</span>
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
      <div class="t-section-title" style="font-size:16px">ceo_concurrents</div>
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
      <div class="t-section-title" style="font-size:16px">ceo_arrondissements</div>
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
    <p class="wz-a">« Ménages par point de vente » dit où l’offre est la moins dense : plus le chiffre est haut, plus il reste de la place. C’est la colonne qui trie.</p>
    <table class="wz-t">
      <tr><th style="width:26px"></th><th>Arrondissement</th><th>Communes</th><th>Ménages</th><th>Commerces</th><th>dont forts</th><th>Ménages / point de vente</th><th>Note moy.</th></tr>
      <tr ${x.A(w.choisirTous)} class="${w.arrTous ? 'on' : ''}"><td><span class="rad"></span></td><td>Tous les arrondissements</td>
        <td colspan="6" style="text-align:left;color:var(--color-text-muted)">toute la sélection de provinces</td></tr>
      ${w.arrs.map(a => `<tr ${x.A(a.choisir)} class="${a.on ? 'on' : ''}">
        <td><span class="rad"></span></td><td>${esc(a.nom)}</td><td>${a.communes}</td><td>${esc(a.hhTxt)}</td>
        <td>${a.shops}</td><td>${a.strong}</td>
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
