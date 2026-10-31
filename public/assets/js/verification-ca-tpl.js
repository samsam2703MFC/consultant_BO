/* Cockpit CEO — gabarit de Vérification › Chiffre d'affaires (lot 5, 31/10/2026).
 * Valeurs : verification-ca-vals.js (`c.vca`) ; style : verification.css (vx-, vca-) ; maquette d.png. */

const pill = (ton, txt) => `<span class="vx-p ${ton}">${txt}</span>`;
const kpi = (k, val, s, ton) => `<div class="vx-kpi ${ton || ''}"><div class="k">${k}</div><div class="v">${val}</div><div class="s">${s}</div></div>`;
const cellEcart = (e, sous, x) => e ? `${pill(e.ton, e.pct)}<small>${e.valeur} · ${e.ecart}</small>${sous ? `<small>${x.esc(sous)}</small>` : ''}` : `<span class="vr-mu">—</span>${sous ? `<small>${x.esc(sous)}</small>` : ''}`;

function tplHaut(v, x){
  const { esc } = x;
  return `<div class="vr-haut">
    <div class="vx-nav"><button ${x.A(v.avant)}>‹</button><span>${esc(v.periode)}${v.sousPeriode ? ` <small class="vr-lu">${esc(v.sousPeriode)}</small>` : ''}</span><button ${x.A(v.apres)}${v.apresOff ? ' disabled' : ''}>›</button></div>
    <span class="vr-sp"></span>${v.aLire ? `<span class="vr-lu">${v.aLire} journée${v.aLire > 1 ? 's' : ''} de tickets encore à lire (le cron les lit chaque heure)</span>` : ''}
    <button class="vr-btn" ${x.A(v.rafraichir)}${v.relecture ? ' disabled' : ''}>${v.relecture ? 'Lecture…' : 'Relire'}</button></div>`;
}

export function tplVerifCa(c, x){
  const { esc } = x, v = c.vca;
  if (!v) { return ''; }
  const haut = tplHaut(v, x);
  if (v.chargement) { return `<div data-screen="verification-ca" class="vr">${haut}<div class="vr-card vr-etat"><span class="vr-roue"></span>Lecture des tickets, du P&L et des redevances du mois… La première fois, jusqu’à vingt secondes.</div></div>`; }
  if (v.erreur) { return `<div data-screen="verification-ca" class="vr">${haut}<div class="vr-card vr-etat ko">${esc(v.erreur)}</div></div>`; }
  if (!v.kpis) { return `<div data-screen="verification-ca" class="vr">${haut}</div>`; }
  const K = v.kpis, R = v.reseau;
  const kpis = `<div class="vx-kpis">
    ${kpi('CA caisse', K.caisse, 'les tickets du panel, TTC après remise, tout le réseau')}
    ${kpi('P&L ÷ caisse', K.pnl, esc(K.pnlS), K.pnlTon)}
    ${kpi('Redevances ÷ caisse', K.red, esc(K.redS), K.redTon)}
    ${kpi('Caisse ÷ CA attendu', K.att, esc(K.attS), K.attTon)}
    ${kpi('Lignes sans produit', K.sp, esc(K.spS), 'mu')}</div>`;
  const table = `<div class="vr-card"><div class="vx-ct"><h3>Le chiffre d’affaires, quatre façons</h3><span class="vr-lu">la caisse fait référence ; chaque autre chiffre se lit en écart · un clic choisit le magasin des jours à regarder</span></div>
    <div class="vx-lect">${esc(v.phrase)}</div>
    <div class="vr-tw"><table class="vr-t vca-t"><thead>
      <tr class="vx-grp"><th></th><th><span>Référence</span></th><th colspan="3"><span>Écart à la caisse</span></th><th colspan="${v.avecCorr ? 2 : 1}"><span>En caisse, à part</span></th><th></th></tr>
      <tr><th class="g">Magasin</th><th>Caisse<small>tickets, TTC</small></th><th>P&L du panel<small>CA du mois</small></th><th>Redevances<small>base de calcul</small></th><th>Attendu par la matière<small>inventaires ÷ recettes</small></th><th>Sans produit<small>lignes « divers »</small></th>${v.avecCorr ? '<th>Corrections<small>après encaissement</small></th>' : ''}<th class="g">Verdict</th></tr></thead><tbody>
    ${v.lignes.map(l => `<tr class="vx-clic${l.on ? ' vca-on' : ''}" ${x.A(l.choisir)}><td class="g nom"><b>${esc(l.nom)}</b>${l.n ? `<small>${l.n} jour${l.n > 1 ? 's' : ''} à regarder</small>` : ''}</td>
      <td><b>${l.caisse}</b><small>${esc(l.ht)}</small>${l.incomplet ? `<small class="vm-rt">${esc(l.incomplet)}</small>` : ''}</td>
      <td>${cellEcart(l.pnl, '', x)}</td><td>${cellEcart(l.red, l.redDu, x)}</td><td>${cellEcart(l.att, l.attPourquoi + (l.recettes ? ' · ' + l.recettes : ''), x)}</td>
      <td>${l.sp}<small>${esc(l.spN)}${l.rem ? ' · ' + esc(l.rem) : ''}</small></td>${v.avecCorr ? `<td>${esc(l.corr)}${l.ano ? `<small>${esc(l.ano)}</small>` : ''}</td>` : ''}
      <td class="g"><span class="vx-v ${l.verdict.ton}"><i></i>${esc(l.verdict.nom)}</span><span class="vx-why">${esc(l.verdict.pourquoi)}</span></td></tr>`).join('')}
    <tr class="tot"><td class="g">Réseau</td><td>${R.caisse}</td><td>${cellEcart(R.pnl, '', x)}</td><td>${cellEcart(R.red, '', x)}</td><td>${cellEcart(R.att, '', x)}</td><td>${R.sp}<small>${esc(R.spN)}</small></td>${v.avecCorr ? `<td>${esc(R.corr)}</td>` : ''}<td></td></tr></tbody></table></div>
    <div class="vr-pied">Caisse : les tickets du panel du mois (<code>/shops/{id}/transactions</code>, TTC après remise), lus jour par jour et gardés. P&L : <code>/consultant/shops/{id}/pnl/daily</code> sommé sur les jours lus. Redevances : la base de calcul du Fonds (<code>/consultant/shops/sales-kpis</code> × les taux de la fiche boutique), une fois le mois complet. Attendu : sur les semaines d’inventaire du mois (onglet Matières), la matière consommée hors poubelle ÷ celle que les ventes expliquent, appliquée à la caisse — il ne vaut que si les entrées sont validées et les recettes fiables. Sans produit : lignes de ticket sans référence (rien ne sort du stock pour elles). Corrections après encaissement et anomalies du panel : <code>/shops/{id}/sales-corrections</code>, <code>/panel/sales-anomalies</code>, montrées dès que ces routes répondent (sonde : <code>/verification/ca/sonde</code>). Seuils : vert ≤ 1 %, orange ≤ 4 %.</div></div>`;
  const J = v.jours;
  const jours = `<div class="vr-card"><div class="vx-ct"><h3>${J ? esc(J.nom) + ' — les jours à regarder' : 'Les jours à regarder'}</h3><span class="vr-lu">${J ? J.n + ' jour' + (J.n > 1 ? 's' : '') + ' · les plus gros montants d’abord' : ''}</span><span class="vr-sp"></span>
      <select class="vr-sel" ${x.C(e => v.choisirShop(e.target.value))}>${v.magasins.map(m => `<option value="${esc(m.id)}"${m.on ? ' selected' : ''}>${esc(m.nom)}</option>`).join('')}</select></div>
    ${J && J.liste.length ? `<div class="vca-jours">${J.liste.map(j => `<div class="vca-j"><span class="d">${esc(j.date)}</span><span class="vx-v ${j.ton}"><i></i></span><span class="t">${esc(j.texte)}</span></div>`).join('')}</div>` : '<div class="vr-vide ok">Rien à regarder ce mois : la caisse, le P&L et les tickets concordent jour par jour.</div>'}
    <div class="vr-pied">Un jour est à regarder quand le P&L (la base des redevances) et la caisse diffèrent de 20 € ou plus, quand des lignes sans produit dépassent 30 € (ou trois lignes), ou quand des remises sur le ticket entier dépassent 30 €.</div></div>`;
  const histo = `<div class="vr-card"><div class="vx-ct"><h3>Mois par mois</h3><span class="vr-lu">le réseau · caisse face au P&L et à l’attendu</span></div>
    <div class="vr-tw"><table class="vr-t"><thead><tr><th class="g">Mois</th><th>Caisse</th><th>P&L</th><th>Attendu</th><th>Recettes fiables</th></tr></thead><tbody>
    ${v.historique.map(h => `<tr><td class="g nom"><b>${esc(h.mois)}</b>${h.enCours ? '<small>en cours</small>' : ''}${h.lu ? `<small>${esc(h.lu)}</small>` : ''}</td><td>${h.caisse}</td><td>${h.pnl ? pill(h.pnl.ton, h.pnl.pct) : '—'}</td><td>${h.att ? pill(h.att.ton, h.att.pct) + `<small>${h.att.valeur}</small>` : '<span class="vr-mu">—</span>'}</td><td>${pill(h.tonR, h.recettes)}</td></tr>`).join('')}
    </tbody></table></div>
    <div class="vr-pied">Un mois « non lu » n’a pas encore ses tickets dans le cockpit : le cron lit le mois en cours chaque heure (et le précédent les cinq premiers jours) ; « Relire » sur ce mois les lit.</div></div>`;
  return `<div data-screen="verification-ca" class="vr">${haut}${kpis}${table}<div class="vca-deux">${jours}${histo}</div></div>`;
}
