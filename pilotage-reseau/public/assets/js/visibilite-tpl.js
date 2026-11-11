/* Cockpit CEO — gabarit de Marque & marketing › Visibilité Google (09/11/2026).
 * Valeurs : visibilite-vals.js (`c.vg`) ; style : visibilite.css (vg-) ; maquettes docs/maquettes/visibilite-google. */

function kpis(list, esc){
  return `<div class="kpi-row vg-kpis">${list.map(k => `<div class="kpi${k.accent ? ' vg-kpi-accent' : ''}">
    <div class="kpi__value">${esc(k.val)}</div><div class="kpi__label">${esc(k.lib)}</div><div class="kpi__note">${esc(k.note)}</div></div>`).join('')}</div>`;
}

function cellule(c, esc){
  const tags = [
    c.site ? `<span class="vg-site">${esc(c.site)}</span>` : '',
    c.warn ? `<span class="tag-warn">${esc(c.warn)}</span>` : '',
    c.ok ? `<span class="chip chip--ok">${esc(c.ok)}</span>` : '',
    c.neutre ? `<span class="chip chip--neutral">${esc(c.neutre)}</span>` : ''].join('');
  // Une plage « lun. → sam. 6:30–18:30 » ne se coupe pas en deux : les morceaux se rangent ligne par ligne.
  const txt = String(c.txt).split(' · ').map(t => `<span class="vg-nw">${esc(t)}</span>`).join(' · ');
  return `<td${c.muet ? ' class="vg-muet"' : ''}>${txt}${tags}</td>`;
}

function tplFiches(v, x){
  const { esc } = x;
  return `${kpis(v.kpis, esc)}
  <section class="card vg-table vg-fiches">
    <table class="ds-table"><thead><tr><th>Donnée de la fiche</th>${v.magasins.map(m => `<th>${esc(m.ville)}<span class="sub">${esc(m.raccorde ? m.nom : 'fiche non raccordée')}</span></th>`).join('')}</tr></thead>
    <tbody>${v.lignes.map(l => `<tr><td>${esc(l.lib)}${l.sous ? `<span class="sub">${esc(l.sous)}</span>` : ''}</td>${l.cels.map(c => cellule(c, esc)).join('')}</tr>`).join('')}</tbody></table>
  </section>
  <div class="vg-bas">
    <section class="card"><h3 class="vg-h">À corriger, du plus urgent au moins urgent<small>${v.ecarts.length} écart(s) · ${v.magasins.length} magasin(s)</small></h3>
      ${v.ecarts.length ? `<ol class="vg-liste">${v.ecarts.map((e, i) => `<li><span class="n">${i + 1}</span><div><div>${esc(e.titre)}</div><div class="q">${esc(e.detail)}</div>
        ${e.action ? (e.action.href ? `<a class="linklike" href="${esc(e.action.href)}" target="_blank" rel="noopener">${esc(e.action.lib)}</a>` : `<button class="linklike" ${x.A(e.action.fn)}>${esc(e.action.lib)}</button>`) : ''}</div></li>`).join('')}</ol>`
        : `<p class="vg-note">Aucun écart entre les fiches Google et le site.</p>`}
    </section>
    <section class="card"><h3 class="vg-h">Ce que l’écran ne fait pas</h3>
      <p class="vg-note">Il lit et compare, il n’écrit pas : une fiche se corrige sur Google (business.google.com), un horaire du site dans son CMS.</p>
      <p class="vg-note">Les horaires exceptionnels des fêtes ne se vérifient qu’à 7 jours : Google ne les publie pas plus tôt.</p>
      <p class="vg-note">Écrire depuis le cockpit (horaires, posts, réponses aux avis) demande l’API Business Profile : connexion du compte propriétaire des fiches et accès validé par Google.</p>
    </section>
  </div>`;
}

function tplRang(v, x){
  const { esc } = x;
  const d = v.detail;
  const ev = n => n == null ? '' : `<span class="vg-ev ${n > 0 ? 'up' : 'down'}">${n > 0 ? '▲ ' + n : '▼ ' + (-n)}</span>`;
  const fr = n => n == null ? '—' : String(n).replace('.', ',');
  return `<div class="vg-outils">${v.themes.map(t => `<button class="filter${t.on ? ' is-on' : ''}" ${x.A(t.choisir)}>${esc(t.nom)}</button>`).join('')}</div>
  ${kpis(v.rangKpis, esc)}
  <div class="vg-grille">
    <section class="card vg-table vg-rang">
      <table class="ds-table"><thead><tr><th>Recherche</th>${v.magasins.map(m => `<th>${esc(m.ville)}${m.langue === 'nl' ? '<span class="sub">en néerlandais</span>' : ''}</th>`).join('')}</tr></thead>
      <tbody>${v.groupes.map(g => `<tr class="vg-theme"><td colspan="${v.magasins.length + 1}">${esc(g.nom)}</td></tr>
        ${g.lignes.map(l => `<tr><td class="vg-req">${esc(l.requete)}${l.nl ? `<span class="sub">${esc(l.nl)}</span>` : ''}</td>
          ${l.cels.map(c => c.vide ? '<td class="vg-muet">—</td>' : `<td><button class="vg-rg ${c.ton}${c.on ? ' on' : ''}" ${x.A(c.choisir)}>${esc(c.txt)}</button>${ev(c.ev)}</td>`).join('')}</tr>`).join('')}`).join('')}</tbody></table>
      <div class="vg-leg"><span><i class="vg-rg top">1–3</i>visible sans défiler</span><span><i class="vg-rg mid">4–10</i>premier écran</span>
        <span><i class="vg-rg bas">11–20</i>à travailler</span><span><i class="vg-rg abs">hors des 20</i>invisible</span><span>${ev(2)} gagné depuis le relevé précédent</span></div>
    </section>
    <div class="vg-pile">
      ${d ? `<section class="card"><h3 class="vg-h">${esc(d.requete)}<small>${esc(d.ville)} · ${d.erreur ? 'erreur : ' + esc(d.erreur) : (d.rang ? esc(d.rang + (d.rang === 1 ? 'er' : 'e') + ' sur ' + d.total) : 'hors des ' + esc(d.total || 20) + ' premiers')}</small></h3>
        <ol class="vg-res">${d.top.map(p => `<li class="${p.nous ? 'nous' : ''}"><span class="p">${p.rang}</span><span class="t">${esc(p.nom)}</span>
          <span class="m">${p.note != null ? esc(fr(p.note)) + ' · ' + esc(p.avis) + ' avis' : 'pas de note'}</span><span class="s">${esc(p.type || '')}${p.nous ? ' · votre magasin' : ''}</span></li>`).join('')}</ol>
      </section>` : ''}
      <section class="card"><h3 class="vg-h">Comment ce rang est mesuré</h3>
        <p class="vg-note">Recherche Google Places depuis la position de la fiche du magasin, 20 résultats, en français (en néerlandais à Halle).</p>
        <p class="vg-note">C’est proche du classement de Google Maps, sans être exactement ce que voit un client : son résultat dépend de l’endroit où il est et de son historique.</p>
        <div class="vg-ligne"><span>Recherches par relecture</span><span>${v.cout.recherches}</span></div>
        <div class="vg-ligne"><span>Fiches relues</span><span>${v.cout.fiches}</span></div>
      </section>
    </div>
  </div>`;
}

export function tplVisibilite(c, x){
  const { esc } = x, v = c.vg || {};
  if (v.chargement) { return `<div data-screen="visibilite" class="vg-attente">Lecture du relevé…</div>`; }
  if (v.erreur) { return `<div data-screen="visibilite" class="card vg-attente">${esc(v.erreur)}</div>`; }
  return `<div data-screen="visibilite">
  <div class="page-tabs" role="tablist">${v.onglets.map(o => `<button class="page-tabs__tab${o.on ? ' is-on' : ''}" ${x.A(o.choisir)}>${esc(o.nom)}${o.n ? ` <span class="page-tabs__count">${o.n}</span>` : ''}</button>`).join('')}</div>
  <div class="vg-bandeau">
    <span>${esc(v.luLe)}</span><span class="vg-sep">·</span><span class="vg-muet">${esc(v.siteTxt)}</span><span class="vg-sp"></span>
    ${v.configure
      ? `<button class="btn btn--primary" ${x.A(v.relire)}${v.busy ? ' disabled' : ''}>${esc(v.busy || 'Relire Google maintenant')}</button>`
      : `<span class="tag-warn">Aucune clé Google : renseignez-la dans Paramètres</span>`}
  </div>
  ${v.vide
    ? `<section class="card vg-attente">Aucun relevé encore. « Relire Google maintenant » lit la fiche de chaque magasin et lance ses recherches : une fiche et sept recherches par magasin.</section>`
    : (v.surFiches ? tplFiches(v, x) : tplRang(v, x))}
  </div>`;
}
