/* Cockpit CEO — gabarit de l'écran Assistant · coachs (ERP franchisé, #/assistant). Valeurs : assistant-coachs-vals.js
 * (`c.asc`) ; style : fidelite.css (listes groupées fi-) et assistant-coachs.css (préfixe ac-). */

function tplCoachs(a, x){
  const { esc } = x;
  if (a.chargement) { return '<div class="fi-vide">Lecture des coachs…</div>'; }
  if (a.indispo) { return `<div class="fi-vide">${esc(a.indispo)}</div>`; }
  if (!a.coachs) { return ''; }
  const carte = c => `<section class="ac-coach">
    <header class="ac-tete">${c.avatar ? `<img src="${esc(c.avatar)}" width="56" height="56" alt="">` : '<span class="ac-sans"></span>'}
      <div><input type="text" class="fi-in t ac-nom" maxlength="40" value="${esc(c.nom)}" ${x.C(c.setNom)} aria-label="Nom du coach">${c.modifie ? '<i class="fi-pt" title="modifié, pas encore publié"></i>' : ''}
        <input type="text" class="fi-in t ac-dit" maxlength="80" value="${esc(c.dit)}" ${x.C(c.setDit)} aria-label="Accroche affichée au franchisé">
        <span class="ac-def">${c.defaut ? 'Coach proposé par défaut au franchisé' : 'Au choix du franchisé'}</span></div>
      <button class="fi-lien" ${x.A(c.departs)}>Valeurs de départ</button></header>
    <div class="fi-liste ac-bloc"><h4>Le ton</h4><textarea class="ac-txt" rows="4" maxlength="1200" ${x.C(c.setTon)}>${esc(c.ton)}</textarea></div>
    ${c.axes.map(ax => `<div class="fi-liste ac-bloc ac-axe ${esc(ax.niveau)}"><div class="ac-axe-h"><h4>${esc(ax.lib)}</h4>
      <span class="ac-seg">${ax.choisir.map(n => `<button class="${n.on ? 'on' : ''}" ${x.A(n.go)}>${esc(n.lib)}</button>`).join('')}</span></div>
      ${ax.niveau === 'non' ? '<p class="ac-non">Il n’en parle que si le franchisé le lui demande.</p>' : `<label class="ac-lab">Ce qu’il vérifie</label><textarea class="ac-txt" rows="3" maxlength="800" ${x.C(ax.setVerifie)}>${esc(ax.verifie)}</textarea>`}</div>`).join('')}
  </section>`;
  return `<p class="fi-pf-intro">Deux coachs business de pointe, au ton différent. Le franchisé choisit le sien dans son dashboard ; ici, on règle ce sur quoi chacun met l’accent et ce qu’il vérifie. Les chiffres viennent toujours de la fiche du magasin : le ton change la manière de dire, jamais les faits.</p>
    <div class="ac-grille">${a.coachs.map(carte).join('')}</div>
    <section class="fi-grp"><h3>Ce que lit l’assistant</h3><div class="fi-liste">
      <div class="fi-pf-opt"><b>Budget</b><span>L’objectif de CA du cockpit (Budget) : le mois, le jour, la semaine jour par jour ; le CA à l’heure qu’il est, la projection de fin de journée, le reste à faire et les jours restants</span></div>
      <div class="fi-pf-opt"><b>Trafic</b><span>Les tickets et le panier : aujourd’hui, hier, la semaine, les deux semaines d’avant, le mois ; 21 jours jour par jour ; la cible de trafic ; le panier moyen du réseau et le rang d’hier</span></div>
      <div class="fi-pf-opt"><b>Contrôles qualité</b><span>Les checklists du jour, faites ou non à l’heure qu’il est, et les sept derniers jours ; les pertes de la semaine, produit par produit</span></div>
      <div class="fi-pf-opt"><b>Toujours</b><span>Aucun chiffre inventé, aucune insulte ni humiliation, quel que soit le coach</span></div></div></section>`;
}

function tplQuestions(a, x){
  const { esc } = x;
  const per = `<div class="ac-per">${a.periodes.map(p => `<button class="${p.on ? 'on' : ''}" ${x.A(p.go)}>${p.j} jours</button>`).join('')}<button class="fi-lien" ${x.A(a.rafraichir)}>Relire</button></div>`;
  if (a.qChargement) { return per + '<div class="fi-vide">Lecture des questions…</div>'; }
  if (a.qIndispo) { return per + `<div class="fi-vide">${esc(a.qIndispo)}</div>`; }
  const q = a.q;
  if (!q) { return per; }
  const barres = (L, cle) => L.length ? L.map(r => `<div class="ac-bar"><span>${esc(r[cle])}</span><b>${esc(r.n)}</b></div>`).join('') : '<p class="ac-non">Rien encore.</p>';
  return `${per}<p class="fi-pf-intro">Chaque question posée à l’assistant est gardée : le magasin, le coach, d’où elle vient, la réponse. C’est la matière pour décider, plus tard, quels agents spécialisés créer.</p>
    <div class="ac-kpis"><div><b>${esc(q.total)}</b><span>questions</span></div><div><b>${esc(q.magasins)}</b><span>magasins</span></div><div><b>${esc(q.duree)}</b><span>de réponse en moyenne</span></div><div><b>${esc(q.echecs)}</b><span>sans réponse</span></div></div>
    <div class="ac-trois"><section class="fi-grp"><h3>Par coach</h3><div class="fi-liste ac-pad">${barres(q.parCoach, 'coach')}</div></section>
      <section class="fi-grp"><h3>Par magasin</h3><div class="fi-liste ac-pad">${barres(q.parMagasin, 'magasin')}</div></section>
      <section class="fi-grp"><h3>D’où viennent les questions</h3><div class="fi-liste ac-pad">${barres(q.parOrigine, 'lib')}</div></section></div>
    <section class="fi-grp"><h3>Les plus posées</h3><div class="fi-liste">${q.top.length ? q.top.map(t => `<div class="ac-ligne"><span class="ac-q">${esc(t.question)}</span><span>${esc(t.magasins)} magasin${t.magasins > 1 ? 's' : ''}</span><b>${esc(t.n)}</b></div>`).join('') : '<p class="ac-non ac-pad">Aucune question sur la période.</p>'}</div></section>
    <section class="fi-grp"><h3>Les dernières</h3><div class="fi-liste">${q.dernieres.length ? q.dernieres.map(r => `<div class="ac-ligne"><span class="ac-quand">${esc(r.quand)}</span><span class="ac-q">${esc(r.question)}${r.echec ? ' <em class="ko">sans réponse</em>' : ''}</span><span>${esc(r.magasin)} · ${esc(r.coach)} · ${esc(r.orig)}</span></div>`).join('') : '<p class="ac-non ac-pad">Aucune question sur la période.</p>'}</div></section>`;
}

export function tplAssistantCoachs(c, x){
  const { esc } = x, a = c.asc;
  if (!a) { return ''; }
  const haut = `<div class="fi-haut"><div class="fi-segc"><button ${x.A(a.ongletC)} class="${a.onglet === 'c' ? 'on' : ''}">Coachs</button><button ${x.A(a.ongletQ)} class="${a.onglet === 'q' ? 'on' : ''}">Questions posées</button></div></div>`;
  const msg = a.msg ? `<div class="fi-toast${a.msg.ko ? ' ko' : ''}">${esc(a.msg.txt)}<button ${x.A(a.fermerMsg)} aria-label="Fermer">×</button></div>` : '';
  const dock = a.onglet === 'c' && a.nbModifs ? `<div class="fi-dock"><span>${a.nbModifs} coach${a.nbModifs > 1 ? 's' : ''} modifié${a.nbModifs > 1 ? 's' : ''}, non publié${a.nbModifs > 1 ? 's' : ''}</span><button class="fi-lien" ${x.A(a.annuler)}>Annuler</button><button class="fi-cta" ${x.A(a.publier)}${a.envoi ? ' disabled' : ''}>${a.envoi ? 'Publication…' : 'Publier dans les magasins'}</button></div>` : '';
  return `<div data-screen="assistantCoachs" class="fi ac">${haut}${msg}<div class="fi-col">${a.onglet === 'q' ? tplQuestions(a, x) : tplCoachs(a, x)}</div>${dock}</div>`;
}
