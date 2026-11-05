/* La coque du module /formation/, partagée par les maquettes A, B, E, F : l'en-tête, les onglets, le pied.
   Les SOP (titres, versions, langues, étapes, points critiques) sont celles du Drive « SOP — dernières versions
   retrouvées » lu le 05/11/2026 (donnees.js). L'équipe de Halle et sa progression sont FICTIVES. */
const esc = s => String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
const FAM = { '01': { c: 'f1', nom: 'Magasin et vente' }, '02': { c: 'f2', nom: 'Cuisson et production' }, '03': { c: 'f3', nom: 'Nettoyage et fermeture' }, '04': { c: 'f4', nom: 'Fiches de référence' } };
const fam = d => `<span class="fam ${FAM[d].c}">${FAM[d].nom}</span>`;
const langues = (l, on) => String(l).split('-').map(x => `<span class="lg ${on === x ? 'on' : ''}">${x}</span>`).join(' ');

// L'équipe de Halle — FICTIVE. Les employés viennent du panel (/shops/{id}/employees, rôle et planning) ;
// la connexion est celle de l'App worker (POST /employees/authenticate).
const EQUIPE = [
  { id: 'ev', nom: 'Elke V.', role: 'Responsable magasin', poste: 'vente', langue: 'NL', depuis: 'mars 2026' },
  { id: 'sm', nom: 'Sofie M.', role: 'Vendeuse', poste: 'vente', langue: 'NL', depuis: 'mars 2026' },
  { id: 'ib', nom: 'Inès B.', role: 'Vendeuse', poste: 'vente', langue: 'FR', depuis: 'avril 2026' },
  { id: 'jp', nom: 'Jana P.', role: 'Vendeuse · week-end', poste: 'vente', langue: 'NL', depuis: 'juin 2026' },
  { id: 'ka', nom: 'Karim A.', role: 'Cuisson · matin', poste: 'cuisson', langue: 'FR', depuis: 'mars 2026' },
  { id: 'tw', nom: 'Tom W.', role: 'Cuisson · après-midi', poste: 'cuisson', langue: 'NL', depuis: 'mai 2026' },
  { id: 'mr', nom: 'Mila R.', role: 'Production · nouvelle', poste: 'production', langue: 'FR', depuis: '03/11/2026' }
];

function coque(onglet, corps, opts) {
  opts = opts || {};
  const tabs = [['bib', 'Bibliothèque', String(FO.sops.length)], ['parcours', 'Parcours', '4'], ['equipes', 'Équipes', '3'], ['sources', 'Sources et versions', '2']];
  const hd = `<div class="hd"><img src="/public/assets/img/logo.png" alt="L'Atelier"><div class="mod">Formation<small>SOP · checklists · fiches de référence</small></div>
    <span class="sp"></span><span class="q">🔍 Rechercher une SOP, une étape, un produit…</span>
    <select><option>${opts.magasin || 'Halle'}</option></select>
    ${(opts.boutons || ['Ouvrir sur la tablette']).map((b, i) => `<span class="b ${i === (opts.prim == null ? 99 : opts.prim) ? 'p' : ''}">${b}</span>`).join('')}
    <span class="qui"><i>SV</i>Sam V. · consultant</span></div>`;
  const tb = `<div class="tabs">${tabs.map(t => `<span class="${t[0] === onglet ? 'on' : ''}">${t[1]}${t[2] ? '<em>' + t[2] + '</em>' : ''}</span>`).join('')}</div>`;
  document.body.innerHTML = `<div class="page">${hd}${tb}${corps}
    <div class="pied">Maquette · /consulant_bo/formation/ · SOP réelles : dossier Drive « L’Atelier Halle — SOP — dernières versions retrouvées », index du 05/11/2026 (44 fichiers → ${FO.sops.length} SOP, PDF et DOCX réunis) · titres, versions, langues, étapes et points critiques lus dans les documents ; les traductions FR des documents NL sont des traductions de travail · l’équipe de Halle, sa progression et les dates de lecture sont fictives · les employés et leur connexion sont ceux du panel et de l’App worker.</div></div>`;
}
