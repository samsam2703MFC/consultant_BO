/* Coque commune des maquettes de fusion : l'en-tête du « Manuel du réseau » (un seul module pour les standards,
   les formations, les compétences et la pré-ouverture). Données réelles du 06/11/2026 : 25 SOP du Cockpit,
   13 chapitres et 3 routines du Manuel opératoire du CRM, 11 blocs / 45 compétences du Guide des formations,
   38 points du Guide pré-ouverture, FO-01…FO-53 du référentiel v25. Les avancements de magasins sont FICTIFS. */
function entete(onglet) {
  const t = [['phases', 'Par phase'], ['domaines', 'Par domaine'], ['postes', 'Par poste'], ['plan', 'Plan de fusion']];
  return `<div class="hd"><img src="/public/assets/img/logo.png" alt="L'Atelier"><div class="mod">Manuel du réseau<small>standards · formations · compétences · pré-ouverture</small></div>
    <span class="sp"></span><span class="q">🔍 Rechercher un standard, une formation, une compétence, un point…</span>
    <span class="bascule-s"><span class="on">FR</span><span>NL</span></span>
    <span class="qui"><i>SV</i>Franchiseur</span></div>
    <div class="tabs">${t.map(([k, l]) => `<span class="${k === onglet ? 'on' : ''}">${l}</span>`).join('')}</div>`;
}
const ph = (k) => `<span class="ph ${k}">${{ pre: 'Pré-ouverture', ouv: 'Ouverture', op: 'Opérationnel', con: 'Continu' }[k]}</span>`;
