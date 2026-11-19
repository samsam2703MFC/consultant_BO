/* Cockpit CEO — gabarit de l'écran Assistant · coachs (ERP franchisé, #/assistant). Valeurs : assistant-coachs-vals.js
 * (`c.asc`) ; style : fidelite.css (listes groupées fi-) et assistant-coachs.css (préfixe ac-). Onglets : Coachs, KPI
 * (le tableau des KPI lus par assistant, 19/11/2026), Questions posées. */

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
    <div class="fi-liste ac-bloc ac-obj"><div class="ac-axe-h"><h4>Objectifs 2026</h4><span class="ac-lab">vide : il ne le suit pas</span></div>
      <div class="ac-obj-g">${c.objectifs.map(o => `<label class="ac-obj-l${o.valeur !== '' ? ' on' : ''}"><span>${esc(o.lib)}<small>${esc(o.mesure)}</small></span>
        <span class="ac-obj-v"><em>${o.sens === 'max' ? 'au plus' : 'au moins'}</em><input type="text" inputmode="decimal" class="fi-in" value="${esc(o.valeur)}" placeholder="—" ${x.C(o.set)} aria-label="${esc(o.lib)}"><b>${esc(o.unite)}</b></span></label>`).join('')}</div>
      ${c.levierPrix ? '<p class="ac-non">Levier du food cost : les produits vendus sous la médiane des boulangeries concurrentes montent à la médiane, avec un lot qui garde le prix actuel pour qui prend plus.</p>' : ''}</div>
    ${c.axes.map(ax => `<div class="fi-liste ac-bloc ac-axe ${esc(ax.niveau)}"><div class="ac-axe-h"><h4>${esc(ax.lib)}</h4>
      <span class="ac-seg">${ax.choisir.map(n => `<button class="${n.on ? 'on' : ''}" ${x.A(n.go)}>${esc(n.lib)}</button>`).join('')}</span></div>
      ${ax.niveau === 'non' ? `<p class="ac-non">Il n’en parle que si le franchisé le lui demande${ax.cle === 'controles' ? ', sauf les notes des photos de contrôle : tous les coachs les reprennent dans leur bilan' : ''}.</p>` : `<label class="ac-lab">Ce qu’il vérifie</label><textarea class="ac-txt" rows="3" maxlength="800" ${x.C(ax.setVerifie)}>${esc(ax.verifie)}</textarea>`}</div>`).join('')}
  </section>`;
  return `<p class="fi-pf-intro">Trois coachs business de pointe, au ton différent. Le franchisé choisit le sien dans son dashboard ; ici, on règle ce sur quoi chacun met l’accent, ce qu’il vérifie et les objectifs 2026 qu’il suit. Les chiffres viennent toujours de la fiche du magasin : le ton change la manière de dire, jamais les faits.</p>
    <div class="ac-grille">${a.coachs.map(carte).join('')}</div>
    <p class="ac-non">Ce que lit chaque assistant, KPI par KPI, et ce qu’il en fait : l’onglet <button class="fi-lien" ${x.A(a.ongletK)}>KPI</button>.</p>`;
}

/** L'onglet KPI : le catalogue des KPI lus, groupé, une colonne par assistant (son modèle en tête). */
function tplKpi(a, x){
  const { esc } = x;
  if (a.chargement) { return '<div class="fi-vide">Lecture des coachs…</div>'; }
  if (a.indispo) { return `<div class="fi-vide">${esc(a.indispo)}</div>`; }
  const k = a.kpi;
  if (!k) { return ''; }
  if (!k.groupes.length) { return '<div class="fi-vide">Le serveur ne rend pas encore le catalogue des KPI (GET /assistant/coachs, champ kpis).</div>'; }
  const n = 2 + k.assistants.length;
  // L'interrupteur d'un outil (design system §7.11, celui de Structure de gamme) ; le point bleu : pas encore publié.
  const tog = c => `<span class="ac-tog"><button type="button" class="sg-tog${c.tog.on ? ' is-on' : ''}" role="switch" aria-checked="${c.tog.on}" aria-label="${esc(c.tog.lib)}" ${x.A(c.tog.go)}><span class="sg-tog__piste"></span><span class="sg-tog__mot">${esc(c.txt)}</span></button>${c.tog.modifie ? '<i class="fi-pt" title="modifié, pas encore publié"></i>' : ''}</span>`;
  const tete = k.assistants.map(s => `<th><span class="ac-kt-qui">${s.avatar ? `<img src="${esc(s.avatar)}" width="22" height="22" alt="">` : ''}${esc(s.nom)}</span><small>${esc(s.modele)}</small></th>`).join('');
  const lignes = k.groupes.map(g => `<tr class="ac-kt-g"><td colspan="${n}">${esc(g.groupe)}</td></tr>` + g.lignes.map(l => `<tr>
      <td><b>${esc(l.kpi)}</b><small>${esc(l.detail)}</small></td>
      <td class="ac-kt-src">${esc(l.source)}${l.outil ? `<code>${esc(l.outil)}</code>` : ''}</td>
      ${l.cases.map(c => `<td>${c.tog ? tog(c) : `<span class="ac-k ${esc(c.ton)}">${esc(c.txt)}</span>`}</td>`).join('')}</tr>`).join('')).join('');
  return `<p class="fi-pf-intro">Tout ce que lisent les assistants, groupé : la fiche du magasin, lue d’office à chaque question (les KPI sondés au panel et au cockpit), puis les outils qu’ils appellent à la demande. Chaque case dit ce que l’assistant en fait : l’accent de son axe et sa cible 2026, réglés dans l’onglet Coachs (les brouillons s’y voient avant publication), ou « lu ». Le modèle de chaque assistant est sous son nom. Les outils à la demande se coupent assistant par assistant ; le Professeur garde le manuel.</p>
    <div class="ac-kt-leg"><span class="ac-k fort">Accent fort</span> dans chaque bilan <span class="ac-k suivi">Suivi</span> quand les chiffres le justifient <span class="ac-k non">Si on le demande</span> seulement sur question <span class="ac-k lu">Lu</span> dans la fiche, sans consigne <span class="ac-k dispo">À la demande</span> un outil qu’il appelle s’il en a besoin : l’interrupteur le lui coupe, à publier</div>
    <div class="fi-liste ac-kt-wrap"><table class="ac-kt"><thead><tr><th>KPI</th><th>Lu où</th>${tete}</tr></thead><tbody>${lignes}</tbody></table></div>
    <p class="ac-non">Toujours, quel que soit l’assistant : aucun chiffre inventé, aucune insulte ni humiliation. Le Professeur ne lit pas la fiche : il part du sommaire du manuel et des photos de contrôle des sept derniers jours.</p>`;
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
  const haut = `<div class="fi-haut"><div class="fi-segc"><button ${x.A(a.ongletC)} class="${a.onglet === 'c' ? 'on' : ''}">Coachs</button><button ${x.A(a.ongletK)} class="${a.onglet === 'k' ? 'on' : ''}">KPI</button><button ${x.A(a.ongletQ)} class="${a.onglet === 'q' ? 'on' : ''}">Questions posées</button></div></div>`;
  const msg = a.msg ? `<div class="fi-toast${a.msg.ko ? ' ko' : ''}">${esc(a.msg.txt)}<button ${x.A(a.fermerMsg)} aria-label="Fermer">×</button></div>` : '';
  const dock = a.onglet !== 'q' && a.nbModifs ? `<div class="fi-dock"><span>${a.nbModifs} assistant${a.nbModifs > 1 ? 's' : ''} modifié${a.nbModifs > 1 ? 's' : ''}, non publié${a.nbModifs > 1 ? 's' : ''}</span><button class="fi-lien" ${x.A(a.annuler)}>Annuler</button><button class="fi-cta" ${x.A(a.publier)}${a.envoi ? ' disabled' : ''}>${a.envoi ? 'Publication…' : 'Publier dans les magasins'}</button></div>` : '';
  return `<div data-screen="assistantCoachs" class="fi ac">${haut}${msg}<div class="fi-col">${a.onglet === 'q' ? tplQuestions(a, x) : (a.onglet === 'k' ? tplKpi(a, x) : tplCoachs(a, x))}</div>${dock}</div>`;
}
