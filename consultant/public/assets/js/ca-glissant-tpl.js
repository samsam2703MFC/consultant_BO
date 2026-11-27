/* Cockpit CEO — gabarit du CA du réseau sur douze mois glissants, Résultat › Mois (valeurs : ca-glissant-vals.js).
 * Une seule échelle, des traits fins (2 px), le réalisé en `--chart-real`, le N-1 en pointillé gris (une référence,
 * pas une série de même rang), des étiquettes choisies (le dernier mois, le plus haut) et la bulle au survol :
 * le mois, le réseau face au N-1, puis chaque magasin. Au-dessus, la rangée des six cases (tout le CA, puis les
 * catégories principales) : une case est un bouton, celle choisie est cernée — c'est elle que la section dessine. */

export function tplCaGlissant(g, x){
  const { esc } = x;
  const cap = 'font-size:10px;font-weight:400;text-transform:uppercase;letter-spacing:.07em;color:var(--color-text-muted)';
  const num = 'font-variant-numeric:tabular-nums';
  const ft = 'font-family:var(--font-ui)';
  const tete = `<div style="display:flex;justify-content:space-between;align-items:baseline;gap:12px;flex-wrap:wrap;margin-bottom:10px">
      <div style="${cap}">${esc(g.titre)}</div>
      ${g.periode ? `<div style="font-size:10.5px;color:var(--color-text-muted)">${esc(g.periode)}</div>` : ''}
    </div>`;
  const cases = `<div class="rg-cases" role="tablist">${(g.cases || []).map(c => `<button type="button" role="tab" aria-selected="${c.on}" ${x.A(c.go)} class="rg-case${c.on ? ' is-on' : ''}"${c.titre ? ` title="${esc(c.titre)}"` : ''}>
      <span style="${cap}">${esc(c.nom)}</span>
      <span class="rg-case__v">${esc(c.v)}</span>
      <span class="rg-case__s">${c.evo ? `<em style="color:${c.evoCol}">${esc(c.evo)}</em>` : ''}${c.evo && c.s ? ' · ' : ''}${esc(c.s || '')}</span>
    </button>`).join('')}</div>${g.casesNote ? `<div style="font-size:10.5px;color:var(--color-text-muted);margin:-6px 0 12px">${esc(g.casesNote)}</div>` : ''}`;
  if (g.attente) {
    return `<div class="rg-carte">${tete}${cases}<div style="display:flex;align-items:center;font-size:12px;color:var(--color-text-muted);padding:26px 4px">${g.enCours ? '<i class="rj-ring"></i>' : ''}${esc(g.attente)}</div></div>`;
  }
  const S = g.svg;
  const svg = `<svg viewBox="0 0 ${S.W} ${S.H}" preserveAspectRatio="xMidYMid meet" class="rg-svg" role="img" aria-label="${esc(g.titre)}">
      ${S.grad.map(t => `<line x1="${S.ML}" x2="${S.W - S.MR}" y1="${t.y}" y2="${t.y}" stroke="rgba(34,34,34,.09)"/>
        <text x="${S.ML - 10}" y="${(+t.y + 3.5).toFixed(1)}" font-size="10.5" text-anchor="end" fill="#7a736a" style="${ft};${num}">${esc(t.t)}</text>`).join('')}
      ${S.points.filter(p => p.on).map(p => `<line x1="${p.x}" x2="${p.x}" y1="${S.grad[S.grad.length - 1].y}" y2="${S.base}" stroke="rgba(34,34,34,.28)" stroke-width="1"/>`).join('')}
      ${S.n1.map(s => `<polyline points="${s}" fill="none" stroke="#9A9288" stroke-width="2" stroke-dasharray="5 4" stroke-linejoin="round" stroke-linecap="round"/>`).join('')}
      ${S.ca.map(s => `<polyline points="${s}" fill="none" stroke="var(--chart-real)" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>`).join('')}
      ${S.points.filter(p => p.on && p.yN1).map(p => `<circle cx="${p.x}" cy="${p.yN1}" r="4" fill="#fff" stroke="#9A9288" stroke-width="2"/>`).join('')}
      ${S.points.filter(p => p.y).map(p => `<circle cx="${p.x}" cy="${p.y}" r="${p.on ? 5.5 : 4}" fill="var(--chart-real)" stroke="#fff" stroke-width="2"/>`).join('')}
      ${S.points.filter(p => p.etiq).map(p => `<text x="${p.x}" y="${(+p.y - 11).toFixed(1)}" font-size="11" text-anchor="middle" fill="#1c1a17" style="${ft};${num}">${esc(p.etiq)}</text>`).join('')}
      ${S.points.map(p => `<text x="${p.x}" y="${(+S.base + 17).toFixed(1)}" font-size="10.5" text-anchor="middle" fill="${p.on ? '#1c1a17' : '#7a736a'}" style="${ft}">${esc(p.lab)}</text>
        ${p.an ? `<text x="${p.x}" y="${(+S.base + 31).toFixed(1)}" font-size="10" text-anchor="middle" fill="#9A9288" style="${ft}">${esc(p.an)}</text>` : ''}`).join('')}
      ${S.points.map(p => `<rect ${x.EN(p.enter)} x="${p.hx}" y="0" width="${p.hw}" height="${S.H}" fill="transparent"/>`).join('')}
    </svg>`;
  const B = g.bulle;
  const bulle = !B ? '' : `<div class="rg-bulle rg-bulle--${B.cote}" style="left:${B.gauche}%">
      <div style="font-size:11.5px;color:var(--color-text);margin-bottom:5px">${esc(B.titre.charAt(0).toUpperCase() + B.titre.slice(1))}</div>
      <div class="rg-l"><span><i class="rg-pt"></i>Réseau</span><b>${esc(B.ca)}</b></div>
      <div class="rg-l"><span><i class="rg-pt rg-pt--n1"></i>N-1</span><span style="${num}">${esc(B.n1)}${B.ecart ? ` <em style="color:${B.ecartCol}">${esc(B.ecart)}</em>` : ''}</span></div>
      ${B.mags.length ? `<div class="rg-sep"></div>${B.mags.map(m => `<div class="rg-l rg-l--mag"><span>${esc(m.nom)}</span><span style="${num}">${esc(m.ca)}${m.ecart ? ` <em style="color:${m.ecartCol}">${esc(m.ecart)}</em>` : ''}</span></div>`).join('')}` : ''}
    </div>`;
  return `<div class="rg-carte">
      ${tete}
      ${cases}
      <div class="rg-corps">
        <div class="rg-kpis">${g.kpis.map(k => `<div class="rg-kpi"><div style="${cap}">${esc(k.k)}</div>
          <div style="font-family:var(--font-display);font-size:21px;line-height:1.15;margin-top:3px;${num}${k.col ? ';color:' + k.col : ''}">${esc(k.v)}</div>
          <div style="font-size:10.5px;color:var(--color-text-muted);margin-top:2px;line-height:1.4">${esc(k.s)}</div></div>`).join('')}</div>
        <div ${x.EN(g.sortir)} class="rg-zone">
          <div class="rg-plot">${svg}${bulle}</div>
          <div class="rm-leg" style="margin-top:2px;padding-left:${(100 * S.ML / S.W).toFixed(1)}%">
            <span><i style="background:var(--chart-real);border-radius:50%"></i>${esc(g.serieNom)}, mois par mois</span>
            ${g.avecN1 ? `<span><i style="width:16px;height:0;border-top:2px dashed #9A9288;border-radius:0;vertical-align:3px"></i>N-1 — les mêmes mois un an plus tôt</span>` : ''}
            <span style="margin-left:auto">survoler un mois pour le détail par magasin</span>
          </div>
        </div>
      </div>
    </div>`;
}
