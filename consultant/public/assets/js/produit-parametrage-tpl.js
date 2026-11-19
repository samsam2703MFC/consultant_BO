/* Cockpit CEO — Produits › Paramétrage (14/11/2026) : le gabarit, tout sur une page. Valeurs : produit-parametrage-vals.js ;
 * style : produit-parametrage.css (pp-). Maquette : docs/maquettes/parametrage-produits. */

export function tplParamProduits(c, x){
  const { esc } = x;
  const V = c.pp;
  if (!V) { return ''; }
  if (V.chargement) { return `<div data-screen="param-produits" class="vr pp"><div class="vr-card vr-etat"><span class="vr-roue"></span>Lecture des produits du panel, des magasins et des gammes…</div></div>`; }
  if (V.erreur) { return `<div data-screen="param-produits" class="vr pp"><div class="vr-card vr-etat ko">${esc(V.erreur)}</div></div>`; }
  const Li = V.liste;
  const liste = `<aside class="pp-liste"><input id="pp-q" class="vm-q" type="search" placeholder="Chercher un produit…" value="${esc(Li.q)}" ${x.I(Li.poserQ)}>
    <select class="vr-sel" ${x.C(Li.poserGamme)}>${Li.gammes.map(o => `<option value="${esc(o.id)}"${o.on ? ' selected' : ''}>${esc(o.nom)}</option>`).join('')}</select>
    <select class="vr-sel" ${x.C(Li.poserCat)}>${Li.cats.map(o => `<option value="${esc(o.id)}"${o.on ? ' selected' : ''}>${esc(o.nom)}</option>`).join('')}</select>
    <div class="vr-chips pp-chips">${Li.chips.map(ch => `<button class="${ch.on ? 'on' : ''}" ${x.A(ch.choisir)}>${esc(ch.nom)} <b>${ch.n}</b></button>`).join('')}</div>
    <div class="pp-compte"><span>${Li.n} produit${Li.n > 1 ? 's' : ''}${Li.n !== Li.total ? ' sur ' + Li.total : ''}</span><button class="vx-lien" ${x.A(Li.relire)}${Li.relecture ? ' disabled' : ''}>${Li.relecture ? 'Lecture…' : 'Relire'}</button></div>
    <div class="pp-items">${Li.items.map(p => `<div class="pp-it${p.on ? ' on' : ''}" ${x.A(p.choisir)}><i class="pp-dot ${p.ton}"></i><div><b>${esc(p.nom)}</b><small>${esc(p.sous)}</small></div></div>`).join('')}
    ${Li.plus ? `<button class="vx-lien pp-plus" ${x.A(Li.plus.voir)}>Voir les ${Li.plus.n} autres</button>` : ''}</div></aside>`;
  return `<div data-screen="param-produits" class="vr pp"><div class="pp-cols">${liste}<section class="vr-card pp-fiche">${fiche(V.fiche, V.barre, x)}</section></div></div>`;
}

function fiche(F, B, x){
  const { esc } = x;
  if (!F) { return `<div class="vr-etat">Choisissez un produit.</div>`; }
  if (F.chargement) { return `<div class="vr-etat"><span class="vr-roue"></span>Lecture du produit au panel, de ses gammes, de ses prix dans chaque magasin…</div>`; }
  if (F.erreur) { return `<div class="vr-etat ko">${esc(F.erreur)}</div>`; }
  const champ = o => {
    const tete = `<span>${esc(o.lib)} <code>${esc(o.cle)}</code></span>`;
    const pied = !o.modifiable ? '<small>non rendu par le panel pour ce produit</small>' : (o.aide ? `<small>${esc(o.aide)}</small>` : '');
    if (o.type === 'oui') { return `<div class="pp-ch oui${o.change ? ' mod' : ''}">${tete}<button class="vx-tog${o.on ? ' on' : ''}" ${x.A(o.basculer)}${o.basculer ? '' : ' disabled'}><i></i>${o.on ? 'oui' : 'non'}</button>${pied}</div>`; }
    if (o.select) { return `<label class="pp-ch">${tete}<select class="pp-in${o.change ? ' mod' : ''}" ${x.C(o.poser)}${o.modifiable ? '' : ' disabled'}>${o.options.map(p => `<option value="${esc(p.id)}"${p.on ? ' selected' : ''}>${esc(p.nom)}</option>`).join('')}</select>${pied}</label>`; }
    return `<label class="pp-ch">${tete}<input id="${esc(o.id)}" class="pp-in${o.change ? ' mod' : ''}" value="${esc(o.val)}" ${x.I(o.poser)}${o.modifiable ? '' : ' disabled'}>${pied}</label>`;
  };
  const sec = (titre, sous, route, corps, cls) => `<div class="vx-sec pp-sec${cls ? ' ' + cls : ''}"><h3>${esc(titre)} ${sous ? `<span>— ${esc(sous)}</span>` : ''}${route ? `<code class="pp-route">${esc(route)}</code>` : ''}</h3>${corps}</div>`;
  const sections = F.sections.map(s => sec(s.titre, '', s.route, `<div class="pp-grille">${s.champs.map(champ).join('')}</div>`
    + (s.cle === 'general' && F.groupesTxt ? `<div class="pp-note">Groupe${F.groupes.length > 1 ? 's' : ''} de la catégorie : ${esc(F.groupesTxt)}</div>` : '')
    + (s.cle === 'fabrication' ? `<div class="pp-note">Compétences requises : ${F.competences.length ? esc(F.competences.join(', ')) : 'aucune'} <span class="vr-mu">(POST /products/{id}/competences)</span></div>` : '')));
  const G = F.gammes;
  const gammes = sec('Saisons & gammes', 'cochées : le produit est en vente pendant ces périodes ; hors de toutes, il sort des caisses et du webshop', G.route,
    `<div class="pp-an"><div class="pp-an-h"><span></span><div class="pp-an-m">${G.mois.map(m => `<b>${m}</b>`).join('')}<em style="left:${G.auj}%">aujourd’hui</em></div><span></span></div>
    ${G.lignes.map(l => `<div class="pp-an-l${l.on ? ' on' : ''}${l.actif ? '' : ' off'}"><label><button class="vx-tog${l.on ? ' on' : ''}" ${x.A(l.basculer)}><i></i></button><span>${esc(l.nom)}</span></label>
      <div class="pp-an-b">${l.barres.map(b => `<i style="left:${b.g}%;width:${b.l}%"></i>`).join('')}</div><div class="pp-an-x">${esc(l.dates)}${l.alerte ? ` <span class="pp-al">${esc(l.alerte)}</span>` : ''}<small>${esc(l.sous)}</small></div></div>`).join('')}</div>
    <div class="pp-note${G.change ? ' mod' : ''}">${G.change ? '<b>À écrire</b> — ' : ''}${esc(G.resume)}</div>`);
  const mags = sec('Par magasin', 'le prix TTC se modifie ici ; le reste se lit au panel', 'PATCH /shops/{id}/products/{p}/price',
    `<div class="vr-tw"><table class="vr-t"><thead><tr><th class="g">Magasin</th><th>Proposé</th><th>Prix TTC<small>de la pièce</small></th><th>Prix HT</th><th>Coût<small>recette</small></th><th>Coefficient</th><th>Matière<small>% du prix HT</small></th><th>Prix max.<small>portion</small></th><th>Stock</th></tr></thead><tbody>
    ${F.magasins.map(m => `<tr><td class="g nom"><b>${esc(m.nom)}</b></td><td>${m.present ? '<span class="pp-ok">oui</span>' : '<span class="vr-mu">non</span>'}</td>
      <td>${m.present ? `<input id="${esc(m.id)}" class="pp-in n${m.change ? ' mod' : ''}" inputmode="decimal" value="${esc(m.val)}" ${x.I(m.poser)}> €` : '—'}</td><td>${esc(m.ht)}</td><td>${esc(m.cout)}</td><td>${esc(m.coef)}</td>
      <td>${m.foodTon ? `<span class="vx-p ${m.foodTon}">${esc(m.food)}</span>` : esc(m.food)}</td><td>${esc(m.max)}</td><td>${esc(m.stock)}</td></tr>`).join('')}</tbody></table></div>`);
  const autres = sec('Les autres données du panel', 'lues, sans écriture d’ici', 'GET /products/{id} · /shops/{id}/products/available',
    `<div class="pp-autres">${F.autres.map(a => `<div><code>${esc(a.cle)}</code><span>${esc(a.val)}</span></div>`).join('')}</div>`);
  const liaisons = sec('Les liaisons du produit dans l’API', 'chaque route lue avec le compte consultant ; mesure gardée un jour', '',
    `<div class="pp-legende"><span><i class="lisible"></i>lisible</span><span><i class="fiche"></i>lue produit par produit</span><span><i class="refusee"></i>refusée au compte consultant</span><span class="vr-sp"></span><button class="vx-lien" ${x.A(F.mesurer)}>Mesurer à nouveau</button></div>
    <div class="pp-liaisons">${F.liaisons.map(f => `<div><h4>${esc(f.titre)}</h4>${f.cartes.map(l => `<div class="pp-lien ${l.etat}"><b>${esc(l.nom)}</b><small>${esc(l.txt)}</small><code>${esc(l.routes)}</code></div>`).join('')}</div>`).join('')}</div>`);
  const tete = `<div class="pp-tete"><div class="pp-id"><h2>${esc(F.nom)}</h2><div class="pp-sous">produit ${F.pid} · ${esc(F.sous)} · ${F.actif ? '<span class="pp-ok">actif</span>' : '<span class="vr-mu">inactif</span>'}${F.lien ? ` <a class="vr-lien" href="${esc(F.lien)}" target="_blank" rel="noopener">Ouvrir au panel ↗</a>` : ''}</div></div><span class="vr-sp"></span>
    <div class="pp-score"><b>${esc(F.score)}</b><small>champs remplis</small></div></div>`;
  const pied = `<div class="pp-pied"><b>Tout se modifie ici</b> : un champ changé se cerne de vert. « Vérifier » montre l’envoi exact au panel (route et données) ; « Écrire au panel » l’écrit avec le compte consultant, le relit et le note au registre des corrections (« Annuler » y remet l’avant des champs). Les gammes et les prix des magasins n’ont pas de contrat au swagger : leur format se voit et se règle dans la vérification, la réponse brute du panel s’affiche, et un format accepté se garde. <span class="vr-mu">${esc(F.source)} · lu à ${esc(F.lu)}</span></div>`;
  return tete + `<div class="pp-corps">${sections[0]}${gammes}${sections.slice(1).join('')}${mags}${autres}${liaisons}${pied}</div>` + barre(B, x);
}

function barre(B, x){
  const { esc } = x;
  if (!B) { return ''; }
  const format = f => `<div class="pp-fmt"><h4>${esc(f.titre)}</h4><div class="vt-route"><select class="vr-sel" ${x.C(f.poserMethode)}>${f.methodes.map(m => `<option${m.on ? ' selected' : ''}>${m.m}</option>`).join('')}</select><input id="${esc(f.idR)}" class="rc-in" value="${esc(f.route)}" ${x.I(f.poserRoute)}></div>
    ${f.champs.map(c => `<div class="vt-ch"><input id="${esc(c.idK)}" class="rc-in vt-k" value="${esc(c.k)}" ${x.I(c.poserK)}><span>=</span><input id="${esc(c.idV)}" class="rc-in vt-v" value="${esc(c.v)}" ${x.I(c.poserV)}><button class="vt-x" ${x.A(c.retirer)}>✕</button></div>`).join('')}
    <button class="vx-lien" ${x.A(f.ajouter)}>+ Ajouter un champ</button><small class="vr-mu">${esc(f.aide)} — changé, « Vérifier » de nouveau.</small></div>`;
  return `<div class="pp-barre"><div class="pp-barre-t"><div><b>${B.n ? B.n + ' changement' + (B.n > 1 ? 's' : '') : 'Aucun changement'}</b>${B.quoi ? ' : ' + esc(B.quoi) : ''}</div><span class="vr-sp"></span>
      ${B.n ? `<button class="rc-btn gris" ${x.A(B.annuler)}>Annuler les changements</button>` : ''}
      ${B.apercu ? `<button class="vx-tog${B.garder ? ' on' : ''}" ${x.A(B.basculerGarder)} title="Si le panel accepte le format saisi, il devient celui de toutes les écritures"><i></i>Garder le format s’il est accepté</button><button class="rc-btn ok" ${x.A(B.ecrire)}${B.enCours ? ' disabled' : ''}>${B.enCours ? 'Écriture…' : 'Écrire au panel'}</button>`
        : (B.n ? `<button class="rc-btn" ${x.A(B.verifier)}${!B.verifier || B.enCours ? ' disabled' : ''}>${B.enCours ? 'Préparation…' : 'Vérifier ce qui sera écrit'}</button>` : '')}
      ${B.resultat ? `<button class="rc-btn gris" ${x.A(B.fermer)}>OK</button>` : ''}</div>
    ${B.refus ? `<div class="rc-ko">✕ ${esc(B.refus)}</div>` : ''}
    ${B.apercu ? `<div class="pp-envois">${B.apercu.map(a => `<div><div class="vr-mu">${esc(a.resume)}</div><pre>${esc(a.txt)}</pre></div>`).join('')}</div>${B.formats.length ? `<div class="pp-fmts">${B.formats.map(format).join('')}</div>` : ''}` : ''}
    ${B.resultat ? `<div class="pp-envois">${B.resultat.map(r => `<div class="vt-env ${r.ok ? 'ok' : 'ko'}"><div>${r.ok ? '✓' : '✕'} ${esc(r.txt)}${r.http ? ` <span class="rc-code">HTTP ${esc(String(r.http))}</span>` : ''}</div>${r.reponse ? `<pre>${esc(r.reponse)}</pre>` : ''}</div>`).join('')}</div>` : ''}</div>`;
}
