/* La coque du module /formation/ (v2, décisions du 05/11/2026) : l'en-tête avec la langue FR | NL, les onglets, le pied.
   Les SOP (titres, versions, étapes, points critiques, quiz) sont celles reprises du Drive « SOP — dernières versions
   retrouvées » (donnees.js). Nos bases deviennent la source ; toutes les SOP existent en FR et en NL ; les standards valent
   pour tout le réseau. L'équipe de Halle, sa progression et les chiffres des autres magasins sont FICTIFS. */
const esc = s => String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
const FAM = { '01': { c: 'f1', nom: 'Magasin et vente' }, '02': { c: 'f2', nom: 'Cuisson et production' }, '03': { c: 'f3', nom: 'Nettoyage et fermeture' }, '04': { c: 'f4', nom: 'Fiches de référence' } };
const fam = d => `<span class="fam ${FAM[d].c}">${FAM[d].nom}</span>`;
// Les deux langues d'une SOP : l'original, la traduction relue, la traduction à relire.
const trad = s => ['FR', 'NL'].map(l => { const t = s.trad[l]; return `<span class="lg ${t === 'à relire' ? 'ar' : 'on'}" title="${l} · ${t}">${l}${t === 'à relire' ? ' ·' : ''}</span>`; }).join(' ');
const langues = (l, on) => String(l).split('-').map(x => `<span class="lg ${on === x ? 'on' : ''}">${x}</span>`).join(' ');

// L'équipe de Halle — FICTIVE. Les employés et leur PIN viennent du panel (/shops/{id}/employees), comme pour les
// checklists de l'app Cuisine : sur la tablette du poste, on choisit son nom et on tape son PIN.
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
  const aRelire = FO.sops.filter(s => s.trad.FR === 'à relire' || s.trad.NL === 'à relire').length;
  const tabs = [['bib', 'Standards', String(FO.sops.length)], ['competences', 'Compétences', String(FO.competences.length)], ['manuel', 'Manuel opératoire', 'FR · NL'], ['redaction', 'Rédaction et versions', String(aRelire)]];
  const hd = `<div class="hd"><img src="/public/assets/img/logo.png" alt="L'Atelier"><div class="mod">Formation<small>les standards du réseau · SOP · compétences</small></div>
    <span class="sp"></span><span class="q">🔍 Rechercher une SOP, une étape, une compétence…</span>
    <span class="bascule-s"><span class="${opts.langue === 'NL' ? '' : 'on'}">FR</span><span class="${opts.langue === 'NL' ? 'on' : ''}">NL</span></span>
    <select><option>${opts.magasin || 'Réseau · 4 magasins'}</option></select>
    ${(opts.boutons || []).map((b, i) => `<span class="b ${i === (opts.prim == null ? 99 : opts.prim) ? 'p' : ''}">${b}</span>`).join('')}
    <span class="qui"><i>SV</i>Sam V.</span></div>`;
  const tb = `<div class="tabs">${tabs.map(t => `<span class="${t[0] === onglet ? 'on' : ''}">${t[1]}${t[2] ? '<em>' + t[2] + '</em>' : ''}</span>`).join('')}</div>`;
  document.body.innerHTML = `<div class="page">${hd}${tb}${corps}
    <div class="pied">Maquette v2 · /consulant_bo/formation/ · SOP reprises du Drive « L’Atelier Halle — SOP — dernières versions retrouvées » (05/11/2026 : 44 fichiers → ${FO.sops.length} SOP) et rangées dans nos bases, qui deviennent la source · chaque SOP en FR et en NL (la langue d’origine est notée, l’autre est traduite à la reprise puis relue) · les mêmes SOP dans le Manuel opératoire · standards valables pour tout le réseau · noms de compétences proposés, à caler sur celles du panel · équipe de Halle, progression et chiffres des autres magasins fictifs.</div></div>`;
}
