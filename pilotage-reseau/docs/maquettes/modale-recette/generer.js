/* Maquette : la modale produit du dashboard magasin, onglet « Recette & marge » (demande du 08/10/2026).
 * Données réelles de Halle le 08/10/2026 (FlipFlap - Thon) dans donnees.json ; la répartition par ingrédient est
 * illustrative tant que la lecture des recettes du panel n'est pas branchée.
 *   node docs/maquettes/modale-recette/generer.js   →  a.html */
const fs = require('fs'), path = require('path');
const D = JSON.parse(fs.readFileSync(path.join(__dirname, 'donnees.json'), 'utf8'));
const esc = s => String(s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
const nf = (n, d) => (+n).toLocaleString('fr-BE', { minimumFractionDigits: d, maximumFractionDigits: d }).replace(/ /g, ' ');
const fE = n => nf(n, 2) + ' €', fP = n => nf(n, 0) + ' %', fP1 = n => nf(n, 1) + ' %', fX = n => '× ' + nf(n, 2);

const P = D.piece, prix = P.prix, mat = P.mat, mb = prix - mat;
const coef = prix / mat, taux = 100 * mb / prix;
const S = D.seuils, obj = 100 / S.food;                       // matière à 32 % ⇔ coefficient × 3,1
const lab = prix * S.labour / 100, oh = prix * S.overhead / 100, net = mb - lab - oh;
const zones = { ko: 100 / 60, att: 2.5 };                     // marge < 40 % ⇔ coef < 1,67 ; < 60 % ⇔ < 2,5 (échelle MARGES du dashboard)
const niv = c => c < zones.ko ? 'ko' : (c < zones.att ? 'att' : 'ok');
const NIV = { ko: 'sous le coût acceptable', att: 'à surveiller', ok: 'dans le vert' };
const prixPour = c => mat * c, matPour = c => prix / c;
const R = D.reseau, auReseau = R ? (R.med - mat) / R.med * 100 : null, coefReseau = R ? R.med / mat : null;
const total = D.recette.lignes.reduce((a, l) => a + l.cout, 0);
const top = D.recette.lignes.slice().sort((a, b) => b.cout - a.cout)[0];

// La jauge du coefficient : de × 1 à × 4, les zones de l'échelle du dashboard, les repères.
const G0 = 1, G1 = 4, pct = v => Math.max(0, Math.min(100, 100 * (v - G0) / (G1 - G0)));
const reperes = [
  { v: D.categorie.coef, lib: 'sa catégorie', sous: fX(D.categorie.coef), pos: 'haut' },
  { v: D.magasinJour.coef, lib: 'le magasin aujourd’hui', sous: fX(D.magasinJour.coef), pos: 'haut' },
  { v: obj, lib: 'objectif', sous: fX(obj) + ' · matière ' + fP(S.food), pos: 'haut', cls: 'obj' },
];
const jauge = `
  <section class="mr-card mr-jauge">
    <div class="mr-ct"><span class="mr-h">Le coefficient</span><span class="fi-note">ce que le prix fait de la matière : prix encaissé ÷ coût de recette</span></div>
    <div class="mr-jg">
      <div class="mr-jn ${niv(coef)}"><b>${fX(coef)}</b><span>${NIV[niv(coef)]}</span><small>marge brute ${fP(taux)} · matière ${fP(100 - taux)} du prix</small></div>
      <div class="mr-jb">
        <div class="mr-band"><i class="ko" style="width:${pct(zones.ko).toFixed(1)}%"></i><i class="att" style="width:${(pct(zones.att) - pct(zones.ko)).toFixed(1)}%"></i><i class="ok" style="width:${(100 - pct(zones.att)).toFixed(1)}%"></i>
          ${reperes.map(r => `<span class="mr-rep ${r.cls || ''}" style="left:${pct(r.v).toFixed(1)}%"><i></i><em>${esc(r.lib)}<small>${esc(r.sous)}</small></em></span>`).join('')}
          <span class="mr-moi ${niv(coef)}" style="left:${pct(coef).toFixed(1)}%"><i></i><em>ce produit<small>${fX(coef)}</small></em></span>
        </div>
        <div class="mr-axe"><span>× 1</span><span>× ${nf(zones.ko, 2)} · marge 40 %</span><span>× 2,5 · marge 60 %</span><span>× 4</span></div>
      </div>
    </div>
    <div class="mr-verdict ${niv(coef)}">
      <b>${niv(coef) === 'ok' ? 'Dans le vert.' : 'Sous l’objectif.'}</b> La matière prend ${fP(100 - taux)} du prix.
      ${niv(coef) !== 'ok' ? `Pour entrer dans le vert (× 2,5), il faudrait vendre la pièce <b>${fE(prixPour(2.5))}</b> au lieu de ${fE(prix)}, ou ramener la matière à <b>${fE(matPour(2.5))}</b> au lieu de ${fE(mat)}.` : ''}
      ${top ? ` ${esc(top.nom)} pèse <b>${fP(100 * top.cout / total)}</b> du coût : c’est là que ça se joue.` : ''}
    </div>
  </section>`;

const recette = `
  <section class="mr-card">
    <div class="mr-ct"><span class="mr-h">La recette, pièce par pièce</span><span class="fi-note">coût matière ${fE(mat)} · ${esc(D.recette.source)}</span></div>
    <table class="mr-tab"><thead><tr><th>Ingrédient</th><th>Quantité</th><th>Coût</th><th class="p">Part du coût</th></tr></thead><tbody>
      ${D.recette.lignes.map(l => `<tr${l === top ? ' class="top"' : ''}><td><b>${esc(l.nom)}</b>${l.sous ? `<small>${esc(l.sous)}</small>` : ''}</td><td>${esc(l.qte)}</td><td>${fE(l.cout)}</td><td class="p"><i style="width:${(100 * l.cout / total).toFixed(1)}%"></i><span>${fP(100 * l.cout / total)}</span></td></tr>`).join('')}
    </tbody><tfoot><tr><td>Coût de recette</td><td></td><td>${fE(total)}</td><td class="p">100 %</td></tr></tfoot></table>
    <div class="fi-note mr-note">${esc(D.recette.illustratif)}.</div>
  </section>`;

const wf = [
  { lib: 'Prix encaissé', v: prix, cls: 'prix', part: 100 },
  { lib: '− Matière', v: -mat, cls: 'mat', part: 100 * mat / prix },
  { lib: '= Marge brute', v: mb, cls: 'mb', part: taux, fort: true },
  { lib: `− Main-d’œuvre, au seuil de ${fP(S.labour)}`, v: -lab, cls: 'ch', part: S.labour },
  { lib: `− Frais généraux, au seuil de ${fP1(S.overhead)}`, v: -oh, cls: 'ch', part: S.overhead },
  { lib: '= Résultat par pièce', v: net, cls: net >= 0 ? 'net ok' : 'net ko', part: Math.abs(100 * net / prix), fort: true },
];
const marge = `
  <section class="mr-card">
    <div class="mr-ct"><span class="mr-h">La marge, pièce par pièce</span><span class="fi-note">main-d’œuvre et frais aux seuils du réseau, pas au réel du jour</span></div>
    <div class="mr-wf">${wf.map(r => `<div class="r ${r.cls}${r.fort ? ' fort' : ''}"><span class="l">${esc(r.lib)}</span><span class="b"><i style="width:${Math.min(100, r.part).toFixed(1)}%"></i></span><span class="v">${r.v < 0 ? '− ' : ''}${fE(Math.abs(r.v))}</span><span class="p">${fP(100 * r.v / prix)}</span></div>`).join('')}</div>
    <div class="mr-face"><div class="k">Face à</div>
      <div class="r"><span>${esc(D.categorie.nom)} aujourd’hui · ${D.categorie.refs} réf.</span><b>marge ${fP(D.categorie.taux)} · ${fX(D.categorie.coef)}</b></div>
      <div class="r"><span>Le magasin aujourd’hui</span><b>marge ${fP(D.magasinJour.taux)} · ${fX(D.magasinJour.coef)}</b></div>
      ${R ? `<div class="r"><span>Au prix réseau (${fE(R.med)}, ${R.magasins} magasins)</span><b>marge ${fP(auReseau)} · ${fX(coefReseau)}</b></div>` : ''}
      <div class="r"><span>Objectif du réseau · matière ${fP(S.food)}</span><b>marge ${fP(100 - S.food)} · ${fX(obj)}</b></div>
    </div>
  </section>`;

const J = D.jour, chips = [
  `<span class="fi-chip">aujourd’hui <b>${J.q} vendus</b> · ${fE(J.ca)}</span>`,
  `<span class="fi-chip">prix encaissé <b>${fE(prix)}</b></span>`,
  R ? `<span class="fi-chip">prix réseau <b>${fE(R.med)}</b></span>` : '',
  `<span class="fi-chip">marge brute <b>${fP(taux)}</b></span>`,
  `<span class="fi-chip mr-chip ${niv(coef)}">coefficient <b>${fX(coef)}</b></span>`].join('');

const html = `<!doctype html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Maquette — modale produit, recette & marge</title>
<link rel="stylesheet" href="../../../public/assets/ds/global.css"><link rel="stylesheet" href="../../../public/dashboard/dashboard.css"><link rel="stylesheet" href="mr.css">
</head><body class="mr-body">
<div class="mr-fond"><div class="mr-fond-t">Dashboard magasin · ${esc(D.magasin)} · vue Jour · ${esc(D.jourTxt)} — la modale s’ouvre d’un clic sur un produit de « Ventes par catégorie »</div></div>
<div id="db-fiche"><div class="fi-voile"></div><div class="fi-modale" role="dialog" aria-modal="true" aria-label="${esc(D.produit.nom)}">
  <div class="fi-hd"><div class="t"><h2>${esc(D.produit.nom)}</h2><div class="s">${esc(D.produit.cat)} · ${esc(D.produit.groupe)} · ${esc(D.magasin)} · ${esc(D.jourTxt)}</div><div class="fi-chips">${chips}</div></div><button type="button" class="fi-x" aria-label="fermer">✕</button></div>
  <div class="fi-ong"><button type="button">Ventes · 12 semaines</button><button type="button">Prix face au réseau <small>volume × prix</small></button><button type="button" class="on">Recette & marge <small>coût, marge, coefficient</small></button></div>
  <div class="fi-bd">
    <div class="fi-barre"><span class="fi-note">une pièce vendue aujourd’hui à ${esc(D.magasin.replace(/^.* - /, ''))} · coût de recette net du panel · seuils du réseau : matière ${fP(S.food)}, main-d’œuvre ${fP(S.labour)}, frais généraux ${fP1(S.overhead)}</span></div>
    ${jauge}
    <div class="mr-deux">${recette}${marge}</div>
    <div class="fi-note mr-pied">Le coefficient et la marge viennent de ce que le dashboard lit déjà : le coût de recette net gravé avec chaque ticket. La recette ligne par ligne demande une lecture de plus au panel, recette puis matières ; elle se gardera 24 h comme les allergènes de la tablette.</div>
  </div></div></div>
</body></html>`;
fs.writeFileSync(path.join(__dirname, 'a.html'), html);
console.log('a.html écrit · coef', coef.toFixed(2), '· marge', taux.toFixed(1), '% · net', net.toFixed(2), '€ · total recette', total.toFixed(2));
