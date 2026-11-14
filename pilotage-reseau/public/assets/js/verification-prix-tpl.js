/* Cockpit CEO — Recettes à corriger › Matières premières (14/11/2026) : le gabarit. Valeurs : verification-prix-vals.js ;
 * style : verification.css (vm-). Maquette : docs/maquettes/matieres-reference. */

export function tplPrix(P, x){
  const { esc } = x;
  if (!P) { return ''; }
  if (P.chargement) { return `<div class="vr-card vr-etat"><span class="vr-roue"></span>Lecture des matières des recettes, de leur prix dans chaque magasin et des listes de prix des fournisseurs…</div>`; }
  if (P.erreur) { return `<div class="vr-card vr-etat ko">${esc(P.erreur)}</div>`; }
  const kpis = `<div class="vm-kpis">${P.kpis.map(k => `<div class="vm-kpi ${k[3]}"><div class="k">${esc(k[0])}</div><div class="v">${k[1] == null ? '—' : k[1]}</div><div class="s">${esc(k[2])}</div></div>`).join('')}</div>`;
  const chips = `<div class="vr-chips">${P.chips.map(c => `<button class="${c.on ? 'on' : ''}" ${x.A(c.choisir)}>${c.classe ? `<i class="vr-pt ${c.classe}"></i>` : ''}${esc(c.nom)} <b>${c.n}</b></button>`).join('')}</div>`;
  const fil = `<div class="vr-fil"><input id="vm-q" class="vm-q" type="search" placeholder="Chercher une matière…" value="${esc(P.q)}" ${x.I(P.poserQ)}><span class="vr-sp"></span><span class="vr-lu">${P.n} matière${P.n > 1 ? 's' : ''}${P.n !== P.total ? ' sur ' + P.total : ''}${P.lu ? ' · ' + esc(P.lu) : ''}</span><button class="vr-btn" ${x.A(P.rafraichir)}${P.relecture ? ' disabled' : ''}>${P.relecture ? 'Lecture…' : 'Relire'}</button></div>`;
  const cell = c => `<td class="vm-px"><span class="vm-p ${c.ton}">${esc(c.txt)}${c.sous ? `<small>${esc(c.sous)}</small>` : ''}</span></td>`;
  const ligne = l => {
    const extra = (l.conversion ? `<div class="vm-x">Poids d’une pièce <input id="${esc(l.conversion.id)}" class="rc-in" inputmode="decimal" value="${esc(l.conversion.val)}" ${x.I(l.conversion.poser)}> ${esc(l.conversion.unite)} <span class="rc-ap">${esc(l.conversion.resultat)}</span></div>` : '')
      + (l.saisie ? `<div class="vm-x"><input id="${esc(l.saisie.id)}" class="rc-in" inputmode="decimal" placeholder="prix" value="${esc(l.saisie.val)}" ${x.I(l.saisie.poser)}> € / ${esc(l.saisie.par)}</div>` : '');
    const tr = `<tr class="${l.ouvert ? 'vm-on' : ''}"><td class="g nom vr-ouvre" ${x.A(l.fiche)} title="La fiche de la matière : ses paramètres et ses prix, modifiables"><b>${esc(l.nom)}</b><small>${esc(l.cat)}<span class="vm-src${l.centrale ? ' c' : ''}">${l.centrale ? 'centrale' : 'libre'}</span> · € / ${esc(l.par)}</small></td>
      <td class="g vm-emploi">${esc(l.emploi)}</td>${l.magasins.map(cell).join('')}
      <td class="g vm-ref"><select class="vm-sel${l.choisi ? ' choisi' : ''}" ${x.C(l.choisir)}>${l.options.map(o => `<option value="${esc(o.id)}"${o.on ? ' selected' : ''}${o.off ? ' disabled' : ''}>${esc(o.nom)}</option>`).join('')}</select>${l.sous ? `<small class="vm-sous">${esc(l.sous)}</small>` : ''}${extra}</td>
      <td>${l.choisi ? `<select class="vm-a" ${x.C(l.poserA)}>${l.appliquerA.map(o => `<option value="${esc(o.id)}"${o.on ? ' selected' : ''}>${esc(o.nom)}</option>`).join('')}</select>${l.nPlan ? `<small class="vm-sous">${l.nPlan} écriture${l.nPlan > 1 ? 's' : ''} · <a href="#" class="rc-ln" ${x.A(l.ouvrir)}>${l.ouvert ? 'masquer l’effet' : 'voir l’effet'}</a></small>` : '<small class="vm-sous">rien à changer</small>'}` : '<span class="vr-mu">—</span>'}</td></tr>`;
    if (!l.impact) { return tr; }
    return tr + `<tr class="vm-imp"><td colspan="${4 + l.magasins.length}"><div class="vm-bloc"><h4>L’effet du prix choisi sur les recettes${l.impactMagasin ? ' — ' + esc(l.impactMagasin) : ''}</h4>
      <table class="vr-t"><thead><tr><th class="g">Produit</th><th>CA<small>30 jours</small></th><th>Quantité<small>par pièce</small></th><th>Coût de la pièce<small>avant</small></th><th>Après</th></tr></thead><tbody>
      ${l.impact.map(r => `<tr><td class="g nom"><b>${esc(r.nom)}</b></td><td>${esc(r.ca)}</td><td>${esc(r.q)}</td><td><span class="rc-av">${esc(r.avant)}</span></td><td><b class="rc-ap">${esc(r.apres)}</b></td></tr>`).join('')}
      </tbody></table></div></td></tr>`;
  };
  const table = P.lignes.length ? `<div class="vr-tw"><table class="vr-t vm-t"><thead><tr><th class="g">Matière première</th><th class="g">Employée dans<small>recettes vendues, CA 30 jours</small></th>${P.mags.map(m => `<th>${esc(m)}<small>prix du magasin</small></th>`).join('')}<th class="g">Prix de référence<small>fournisseur, panel, réseau ou saisi</small></th><th>Appliquer à</th></tr></thead><tbody>
    ${P.lignes.map(ligne).join('')}</tbody></table></div>${P.plus ? `<div class="vr-fil"><button class="vr-btn" ${x.A(P.plus.voir)}>Voir les ${P.plus.n} autres</button></div>` : ''}` : `<div class="vr-vide">Rien pour ce filtre.</div>`;
  const B = P.barre;
  const barre = B ? `<div class="vm-barre"><div><b>${B.n} prix choisi${B.n > 1 ? 's' : ''} — ${B.nEcr} écriture${B.nEcr > 1 ? 's' : ''}</b> (une par matière et par magasin)${B.resume ? ' : ' + esc(B.resume) : ''}
      <small>Rien ne part sans « Vérifier » puis « Appliquer au panel » : chaque prix est écrit avec le compte consultant (${esc(P.route)}), relu, et noté au registre des corrections.</small>
      ${B.apercu ? `<div class="rc-apercu">${B.apercu.map(a => a.ok ? `<div><b>${esc(a.resume)}</b>${a.ecritures.map(e => `<div class="vr-mu">${esc(e.resume)} <span class="rc-code">${esc(e.route)}</span> <span class="rc-code">${esc(e.corps)}</span>${e.annulable ? '' : ' · premier prix, sans retour'}</div>`).join('')}</div>` : `<div class="rc-ko">✕ ${esc(a.resume)}</div>`).join('')}</div>` : ''}</div>
    <button class="rc-btn gris" ${x.A(B.annuler)}>Annuler les choix</button>
    ${B.apercu ? `<button class="rc-btn ok" ${x.A(B.appliquer)}${!B.appliquer || B.enCours ? ' disabled' : ''}>${B.enCours ? 'Écriture…' : 'Appliquer au panel'}</button>`
      : `<button class="rc-btn" ${x.A(B.verifier)}${!B.verifier || B.enCours ? ' disabled' : ''}>${B.enCours ? 'Préparation…' : 'Vérifier ce qui sera écrit'}</button>`}</div>` : '';
  const res = P.resultat ? `<div class="rc-res ${P.resultat.ok ? 'ok' : 'ko'}" style="padding:10px 18px">${P.resultat.lignes.map(t => esc(t)).join('<br>')} <button class="rc-btn gris" ${x.A(P.resultat.fermer)}>OK</button></div>` : '';
  const pied = `<div class="vr-pied"><b>Le prix de référence</b>, au choix pour chaque matière : la liste de prix <b>en vigueur</b> d’un fournisseur lié à la matière au panel (prix du colis ÷ taille, pour le magasin), le prix conseillé ou de référence du panel, la médiane des autres magasins, ou un prix saisi. Un colis en pièces pour une matière en grammes demande le poids d’une pièce. <b>Appliquer à</b> : les magasins sans prix, tous, ou un seul. Un clic sur une matière ouvre sa fiche : tous ses paramètres et ses prix, modifiables ; « voir l’effet » montre le coût de ses recettes. <span class="vr-mu">${esc(P.source)}</span></div>`;
  return `<div class="vr-card">${kpis}${chips}${fil}${res}${table}${barre}${pied}</div>`;
}
