/* Cockpit CEO — gabarit de la carte « Clients par mois » de Résultat › Année › 1 an (24/10/2026).
 * Valeurs : valsClientsAnnee (clients-annee-vals.js). Le dessin est en repère 1000 × H ; la bulle de chaque mois est
 * du HTML posé au-dessus, montré au survol par le CSS (.clan-col:hover) — aucun redessin de l'écran. */

const attrs = (a, esc) => Object.keys(a).map(k => k + '="' + esc(String(a[k])) + '"').join(' ');

function bulle(b, esc){
  if (!b) { return ''; }
  return `<b class="t">${esc(b.titre)}</b>`
    + b.lignes.map(l => `<div>${l.k ? `${esc(l.k)} : <b>${esc(l.v)}</b>` : ''}${l.s ? `<span class="mu">${l.k ? ' · ' : ''}${esc(l.s)}</span>` : ''}</div>`).join('')
    + (b.ecart ? `<div>Écart${b.ecart.a ? ' ' + esc(b.ecart.a) : ''} : <b class="${b.ecart.up ? 'up' : 'dn'}">${esc(b.ecart.v)}</b></div>${b.ecart.s ? `<div class="mu">${esc(b.ecart.s)}</div>` : ''}` : '');
}

export function tplClientsAnnee(c, x){
  const { esc } = x;
  const tete = `<div class="clan-tete"><span class="clan-titre">${esc(c.titre)}</span>
      <span class="clan-leg"><span><i></i>${esc(c.an)}</span><span><i class="n1"></i>${esc(c.an1)}</span><span><i class="bd"></i>${esc(c.an)} devant / derrière</span></span>
      <span class="clan-seg">${c.mesures.map(m => `<button ${x.A(m.go)} class="${m.on ? 'on' : ''}">${esc(m.nom)}</button>`).join('')}</span></div>`;
  if (c.chargement) {
    return `<div class="clan">${tete}<div class="clan-etat"><i class="rj-ring"></i>Lecture des ventes de l’année au panel…</div></div>`;
  }
  if (c.erreur) {
    return `<div class="clan">${tete}<div class="clan-etat err"><span style="flex:1">${esc(c.erreur)}</span><button ${x.A(c.relire)} class="clan-relire">Relire</button></div></div>`;
  }
  const g = c.svg;
  return `<div class="clan">${tete}
    <div class="clan-puces">${c.puces.map(p => `<button ${x.A(p.go)} class="clan-puce${p.on ? ' on' : ''}">${esc(p.nom)}<b style="color:${p.on ? '#fff' : esc(p.coul)}">${esc(p.ecart)}</b></button>`).join('')}
      ${c.estReseau ? `<span class="clan-perim">périmètre <button ${x.A(c.setTous)} class="${c.perimTous ? 'on' : ''}">tous les magasins</button><button ${x.A(c.setComp)} class="${c.perimComp ? 'on' : ''}">constant</button></span>` : ''}</div>
    <div class="clan-chiffres">${c.chiffres.map(k => `<div><small>${esc(k.k)}</small><b${k.coul ? ` style="color:${esc(k.coul)}"` : ''}>${esc(k.v)}</b><span>${esc(k.s)}</span></div>`).join('')}</div>
    ${g ? `<div class="clan-g"><svg viewBox="0 0 ${g.W} ${g.H}" class="clan-svg" role="img" aria-label="${esc('Clients par mois, ' + c.an + ' face à ' + c.an1)}">${g.el.map(([tag, a, txt]) => txt == null ? `<${tag} ${attrs(a, esc)}/>` : `<${tag} ${attrs(a, esc)}>${esc(txt)}</${tag}>`).join('')}</svg>
      <div class="clan-cols">${g.cols.map(k => `<div class="clan-col" style="left:${k.l}%;width:${k.w}%"><i class="clan-guide" style="bottom:${(100 - k.hb).toFixed(2)}%"></i><div class="clan-tip${k.droite ? ' d' : ''}">${bulle(k.bulle, esc)}</div></div>`).join('')}</div></div>`
      : `<div class="clan-etat">Aucun client lu pour ${esc(c.an)}.</div>`}
    <div class="clan-note">${esc(c.note)}</div>
  </div>`;
}

/** La cellule de la colonne « Clients (ou Panier) vs N-1 » du tableau des magasins. */
export function tplClientsCellule(m, esc){
  if (!m) { return '<span style="color:var(--color-text-muted)">—</span>'; }
  return `${esc(m.v)}${m.e ? ` <b style="font-weight:600;color:${esc(m.coul)}">${esc(m.e)}</b>` : ''}<div style="font-size:10px;color:var(--color-text-muted)"${m.titre ? ` title="${esc(m.titre)}"` : ''}>${esc(m.s)}</div>`;
}
