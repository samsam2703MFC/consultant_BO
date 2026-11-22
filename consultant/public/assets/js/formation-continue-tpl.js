/* Cockpit — Franchisés › Formations continues (22/11/2026) : le gabarit. Valeurs : formation-continue-vals.js
 * (common.fcc) ; style : formation-continue.css (fcc-). Maquette : formation/docs/maquettes/formation-continue/02. */
const ST = { en_cours: 'brou', a_valider: 'aval', validee: 'val' };
const CAT = { technique: 'tech', connaissance: 'conn', nouveau_produit: 'prod', nouvelle_technique: 'ntech' };
const PH = { conforme: 'ok', a_refaire: 'ko', en_attente: 'att', manquante: 'manq', a_faire: 'faire', a_venir: 'venir' };

export function tplFCC(c, x){
  const { esc } = x, F = c.fcc || {};
  const statut = d => `<span class="fcc-st ${d.retard ? 'ret' : (ST[d.statut] || 'brou')}">${esc(d.statutLib)}</span>`;
  const tag = d => `<span class="fcc-tag ${CAT[d.categorie] || ''}"><i></i>${esc(d.categorieLib)}</span>`;
  const barre = p => `<span class="fcc-av"><span class="b"><i class="${p >= 100 ? '' : 'o'}" style="width:${Math.max(0, Math.min(100, p || 0))}%"></i></span><span>${p == null ? '—' : p + ' %'}</span></span>`;
  const tete = `<p class="mko-mu fcc-intro">${F.consNom ? 'Les franchisés de <b>' + esc(F.consNom) + '</b>' : 'Tout le réseau'} — vérifier, adapter pour une boutique, valider : chaque geste est tracé (qui, quand) ; la validation archive le dossier au coffre-fort du franchisé.</p>
    <div class="fcc-barre"><label class="fcc-cons"><span class="mko-lab">Consultant</span><select class="mko-sel" ${x.C(F.setCons)}>${(F.consultants || []).map(o => `<option value="${esc(o.v)}"${o.on ? ' selected' : ''}>${esc(o.nom)}</option>`).join('')}</select></label>
      <span class="mko-mu">« mes franchisés » : ceux dont il est le consultant dans le cadre de visite</span><span style="flex:1"></span><button ${x.A(F.relire)} class="mko-pied-btn">Relire</button></div>`;
  if (F.erreur) { return `<div data-screen="formations-continues">${tete}<div class="mko-carte" style="padding:18px">${esc(F.erreur)}</div></div>`; }
  if (F.chargement) { return `<div data-screen="formations-continues">${tete}<div class="mko-carte" style="padding:18px;color:var(--color-text-muted)">Lecture des formations…</div></div>`; }
  const R = F.reperes || {};
  const kpis = `<div class="fcc-kpis"><div class="fcc-kpi or"><span>À valider</span><b>${R.aValider || 0}</b><small>terminées par le franchisé, en attente de vous</small></div>
    <div class="fcc-kpi ac"><span>En retard</span><b>${R.enRetard || 0}</b><small>échéance passée, pas terminée</small></div>
    <div class="fcc-kpi"><span>Avancement moyen</span><b>${R.avancement == null ? '—' : R.avancement + ' %'}</b><small>formations en cours, ${F.nbFranchises} boutique(s)</small></div>
    <div class="fcc-kpi ok"><span>Validées ce mois</span><b>${R.valideesMois || 0}</b><small>tracées au journal</small></div></div>`;
  const msg = F.msg ? `<div class="ffd-msg ${F.msg.ok ? 'ok' : 'ko'}" style="margin:0 0 12px">${esc(F.msg.txt)}</div>` : '';
  const franchises = `<div class="fcc-fr">${(F.franchises || []).map(f => `<button ${x.A(f.choisir)} class="fcc-frc${f.on ? ' on' : ''}"><b>${esc(f.court || f.nom)}</b>
      <small>${f.consultants.length ? esc(f.consultants.map(k => k.nom).join(', ')) : 'sans consultant au cadre'} · ${f.formations} formation(s)</small>
      <span class="m">${f.aValider ? `<span class="fcc-st aval">${f.aValider} à valider</span>` : ''}${f.enRetard ? `<span class="fcc-st ret">${f.enRetard} en retard</span>` : ''}</span>${barre(f.avancement)}</button>`).join('')
      || '<div class="mko-mu" style="padding:12px">Aucune boutique pour ce consultant au cadre de visite.</div>'}</div>`;
  const liste = `<section class="mko-carte fcc-liste"><div class="fcc-lh"><b>${esc(F.shopNom || '')}</b><span class="mko-mu">${(F.dossiers || []).length} formation(s)</span></div>
    <div class="fcc-cats">${(F.cats || []).map(o => `<button ${x.A(o.choisir)} class="${o.on ? 'on' : ''}">${esc(o.nom)} <i>${o.n}</i></button>`).join('')}</div>
    ${F.chargementD ? '<div class="mko-mu" style="padding:14px">Lecture…</div>' : (F.dossiers || []).map(d => `<button ${x.A(d.choisir)} class="fcc-l${d.on ? ' on' : ''}"><span class="c"><b>${esc(d.code)} · ${esc(d.titre)}</b><small>${tag(d)}${d.adapte ? ' <span class="fcc-tag adapt">adaptée</span>' : ''}${d.photos.jours ? ' · photos ' + d.photos.conformes + '/' + d.photos.jours : ''}</small></span>
      <span class="s">${statut(d)}</span>${barre(d.avancement.pct)}<span class="e${d.retard ? ' ret' : ''}">${esc(d.echeanceTxt)}</span></button>`).join('') || '<div class="mko-mu" style="padding:14px">Aucune formation continue diffusée à cette boutique.</div>'}
    ${(F.aVenir || []).length ? `<div class="fcc-avenir">Pas encore diffusées ici : ${F.aVenir.map(a => esc(a.code + ' ' + a.titre) + ' (' + esc(a.statutLib.toLowerCase()) + ')').join(' · ')} — la diffusion se fait dans le module Formation.</div>` : ''}</section>`;
  const X = F.x, A = F.ad;
  let tiroir = '<aside class="mko-carte fcc-tiroir"><div class="mko-mu" style="padding:16px">' + (F.chargementX ? 'Lecture du dossier…' : 'Choisissez une formation.') + '</div></aside>';
  if (X) {
    const verifier = `<div class="fcc-sec"><div class="fcc-st-t"><span class="n">1</span><b>Vérifier</b><i>contenu, équipe, photos</i></div>
      ${X.adapte ? `<div class="fcc-diff">Version de la boutique : ${esc(X.adapteTxt)}${X.ecarts.map(e => `<div>Étape ${esc(e.numero)} : <s>${esc(e.avant)}</s> → <b>${esc(e.apres)}</b></div>`).join('')}</div>` : ''}
      <details class="fcc-cont"><summary>Le contenu tel que la boutique le lit (${X.etapes.length} étape(s))</summary>${X.etapes.map(e => `<div class="fcc-et${e.critique ? ' crit' : ''}"><span>${esc(e.numero)}</span><div>${e.titre ? `<b>${esc(e.titre)}</b>` : ''}${esc(e.texte)}</div></div>`).join('')}</details>
      <table class="fcc-eq"><tr><th>Personne</th><th>Lu</th><th>Quiz</th><th>Vu faire</th></tr>${X.equipe.map(p => `<tr><td>${esc(p.personne)}</td>${['lu', 'quiz', 'vu'].map(k => `<td>${p[k] ? '<span class="ok">✓</span>' : '<span class="non">—</span>'}</td>`).join('')}</tr>`).join('') || '<tr><td colspan="4" class="mko-mu">Personne de nommé.</td></tr>'}
        <tr><td>Mise en place en magasin</td><td colspan="3">${X.miseEnPlace ? '<span class="ok">✓</span> ' + esc(X.miseEnPlace.par || '') : '<span class="non">pas encore</span>'}</td></tr></table>
      ${X.photos.jours ? `<div class="fcc-k">Photos qualité du produit <i>${X.photos.conformes}/${X.photos.jours} conformes · du ${esc(X.grille[0] ? X.grille[0].jm : '')} au ${esc(X.grille.length ? X.grille[X.grille.length - 1].jm : '')}</i></div>
        <div class="fcc-grille">${X.grille.map(g => `<div class="fcc-j ${PH[g.etat] || ''}" title="${esc(g.jm + (g.commentaire ? ' · « ' + g.commentaire + ' »' : ''))}">${g.src ? `<a href="${esc(g.src)}" target="_blank" rel="noopener"><img src="${esc(g.src)}" alt="" loading="lazy"></a>` : ''}<small>${esc(g.jm)}</small>
          ${g.photo && g.etat !== 'conforme' && X.statut !== 'validee' ? `<button ${x.A(g.conforme)} class="ok" title="conforme">✓</button>` : ''}${g.photo && g.etat !== 'a_refaire' && X.statut !== 'validee' ? `<button ${x.A(g.refaire)} class="ko" title="à refaire">✗</button>` : ''}</div>`).join('')}</div>
        <p class="mko-mu fcc-n">Une photo par jour, photo obligatoire ; un jour manqué ou une photo à refaire ajoute un jour. ✓ conforme · ✗ à refaire (le franchisé lit votre mot).</p>` : '<p class="mko-mu fcc-n">Cette formation ne demande pas de photo.</p>'}</div>`;
    const adapter = `<div class="fcc-sec"><div class="fcc-st-t"><span class="n">2</span><b>Adapter</b><i>pour ${esc(F.shopNom)} seulement</i><button ${x.A(A.ouvrir)} class="mko-pied-btn" style="margin-left:8px">${A.ouvert ? 'Fermer' : 'Modifier'}</button></div>
      ${A.ouvert && X.statut !== 'validee' ? `<div class="fcc-ad">${X.etapes.map((e, i) => `<label><span class="mko-lab">Étape ${esc(e.numero)}${e.titre ? ' · ' + esc(e.titre) : ''}</span><textarea class="mko-inp" rows="2" ${x.I(A.setEtape(i))}>${esc(A.etapes[i] == null ? e.texte : A.etapes[i])}</textarea></label>`).join('')}
        <label><span class="mko-lab">Qui la suit (noms séparés par des virgules)</span><input class="mko-inp" value="${esc(A.assignes)}" ${x.I(A.setAssignes)}></label>
        <label><span class="mko-lab">Échéance</span><input class="mko-inp" type="date" value="${esc(A.echeance)}" ${x.C(A.setEcheance)}></label>
        <label><span class="mko-lab">Pourquoi (le franchisé le lit)</span><input class="mko-inp" value="${esc(A.note)}" placeholder="ex. pas de four à sole au traiteur" ${x.I(A.setNote)}></label>
        <div class="fcc-bts"><button ${x.A(A.enregistrer)} class="cx-btn prim"${F.occ ? ' disabled' : ''}>Enregistrer l’adaptation</button>${X.adaptation.contenu ? `<button ${x.A(A.modele)} class="mko-pied-btn">Revenir au modèle du réseau</button>` : ''}</div></div>`
        : `<p class="mko-mu fcc-n">${X.adapte ? esc(X.adapteTxt) : 'Le modèle du réseau, sans adaptation.'} · qui la suit : ${esc(X.assignes.join(', ') || 'personne de nommé')} · échéance ${esc(X.echeance ? X.echeance.slice(8, 10) + '/' + X.echeance.slice(5, 7) + '/' + X.echeance.slice(0, 4) : '—')}</p>`}</div>`;
    const valider = `<div class="fcc-sec"><div class="fcc-st-t"><span class="n">3</span><b>Valider</b><i>tracé : qui, quand</i></div>
      ${X.validation ? `<div class="fcc-ok">✓ ${esc(X.validationTxt)}${X.validation.mot ? ' — « ' + esc(X.validation.mot) + ' »' : ''}${X.validation.archive ? ' · archivée au coffre-fort (onglet Formation)' : ''}</div><div class="fcc-bts"><button ${x.A(F.renvoyer)} class="mko-pied-btn">Renvoyer en cours</button></div>`
        : `<input class="mko-inp" value="${esc(F.mot)}" placeholder="Un mot pour le franchisé (facultatif)" ${x.I(F.setMot)}>
        <div class="fcc-note">En validant : votre nom et l’heure entrent au journal ; le dossier (version, équipe, photos) est archivé dans le document de formation du franchisé, au coffre-fort.</div>
        <div class="fcc-bts"><button ${x.A(F.valider)} class="cx-btn prim fcc-v"${F.occ ? ' disabled' : ''}>✓ Valider pour ${esc(F.shopNom)}${X.statut !== 'a_valider' ? ' (avant la fin)' : ''}</button></div>`}
      <div class="fcc-k" style="margin-top:14px">Journal de cette formation</div>
      ${X.journal.map(j => `<div class="fcc-tr"><span>${esc(j.quand)}</span><span>${esc(j.detail)}<small>${esc(j.par)}${j.role ? ' · ' + esc(j.role === 'siege' ? 'siège / consultant' : (j.role === 'franchise' ? 'franchisé' : j.role)) : ''}</small></span></div>`).join('')}</div>`;
    tiroir = `<aside class="mko-carte fcc-tiroir"><div class="fcc-th"><div class="mko-lab">${esc(F.shopNom)} · ${esc(X.code)}</div><div class="fcc-tt">${esc(X.titre)}</div><div>${tag(X)} ${statut(X)} ${barre(X.avancement.pct)}</div></div>${verifier}${adapter}${valider}</aside>`;
  }
  return `<div data-screen="formations-continues">${tete}${kpis}${msg}<div class="fcc-lay">${franchises}${liste}${tiroir}</div></div>`;
}
