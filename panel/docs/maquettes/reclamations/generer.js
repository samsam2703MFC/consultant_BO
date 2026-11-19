/* Trois propositions pour créer, documenter et suivre les réclamations
 * fournisseur depuis le dashboard magasin. Données RÉELLES lues en ligne le
 * 01/10/2026 : les 27 réclamations de Gosselies (/fournisseurs/reclamations),
 * les motifs, matières et livraisons du magasin (/fournisseurs/reclamation-refs).
 * L'exemple de saisie (pains au chocolat, 60 pièces) et ses deux photos sont une
 * ILLUSTRATION ; les photos sont des photos du comptoir de Gosselies.
 *
 *   node docs/maquettes/reclamations/generer.js   (serveur statique sur 8099 depuis la racine)
 */
const fs = require('fs'), path = require('path');
const { chromium } = require('/opt/node22/lib/node_modules/playwright');
const OUT = __dirname;
const D = require('./donnees.json');
const CTX = require('./contexte.json');
const esc = s => String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
const nf = (n, d) => Number(n).toLocaleString('fr-BE', { minimumFractionDigits: d, maximumFractionDigits: d });
const eur = n => nf(Math.round(n), 0) + ' €';
const fD = d => d ? d.slice(8, 10) + '/' + d.slice(5, 7) : '';
const AUJ = '2026-10-01';
const jours = d => Math.round((new Date(AUJ) - new Date(d)) / 86400000);
const MOTIF = { delivery_issue: 'Problème de livraison' };

/* --- L'état d'une réclamation, vu du magasin -------------------------------- */
function etat(l) {
  if (l.statut === 'REJECTED') { return { c: 'ko', lib: 'Refusée' }; }
  if (l.statut === 'ACCEPTED' && l.reponse) { return { c: 'ok', lib: 'Réglée' }; }
  if (l.statut === 'ACCEPTED') { return { c: 'sans', lib: 'Acceptée · sans suite' }; }
  return { c: 'att', lib: 'Envoyée · en attente' };
}
const R = D.reclamations.slice().sort((a, b) => b.le.localeCompare(a.le) || b.id - a.id);
const nb = c => R.filter(l => etat(l).c === c).length;
const enAttente = R.filter(l => l.ouverte);
const montantAttente = enAttente.reduce((a, l) => a + (l.montant || 0), 0);
const refusSansPhoto = R.find(l => l.statut === 'REJECTED');
const premiereLigne = t => String(t || '').split(/\r?\n/).find(x => x.trim()) || '';

function carte(l, court) {
  const e = etat(l);
  const titre = l.reference || (l.motif === 'delivery_issue' ? 'La livraison' : 'Réclamation');
  const rep = e.c === 'ok' || e.c === 'ko' ? `<div class="rep ${e.c === 'ko' ? 'ko' : ''}"><b>${esc(l.fournisseur)}${l.reponseLe ? ' · ' + fD(l.reponseLe) : ''} :</b> ${esc(premiereLigne(e.c === 'ko' ? l.reponse.replace(/^Bonjour,\s*/, '') : l.reponse).slice(0, 150))}</div>`
    : e.c === 'sans' ? `<div class="rep sans">Acceptée, aucune suite depuis ${jours(l.le)} jours — relancer ${esc(l.fournisseur)}</div>`
      : `<div class="rep sans" style="background:#eef4fa;color:#0b4a7a">Envoyée il y a ${jours(l.le)} jour${jours(l.le) > 1 ? 's' : ''} — en attente de ${esc(l.fournisseur)}</div>`;
  return `<div class="rc-carte"><div class="l1"><span class="rc-st ${e.c}">${esc(e.lib)}</span><b>${esc(titre)}</b><span class="d">${fD(l.le)} · n° ${l.id}</span></div>
    <div class="l2">${l.qte != null ? nf(l.qte, 0) + ' ' + esc(l.unite) + ' · ' : ''}${esc(MOTIF[l.motif] || l.motif)}${l.commande ? ' · ' + esc(l.commande.slice(0, 12)) + '…' : ''}${l.montant ? ' · ' + eur(l.montant) : ''}${l.pj ? ' · 📎 ' + l.pj + ' photo' + (l.pj > 1 ? 's' : '') : ' · sans photo'}</div>
    ${court || !l.texte ? '' : `<div class="tx">${esc(premiereLigne(l.texte))}</div>`}${rep}</div>`;
}

/* --- Le formulaire, exemple de saisie ----------------------------------------- */
const LIV = D.livraisons.find(l => l.statut === 'livrée');
const PAC = D.matieres.find(m => m.nom === 'Pain au Chocolat');
const QTE = 60;
const motifs = (sel, mini) => `<div class="rc-puces">${D.motifs.map(m => `<span class="${m.code === sel ? 'on' : ''}">${esc(mini ? m.nom.replace('Quantité incorrecte de produits dans l’emballage', 'Quantité dans l’emballage').replace('Non-conformité avec la commande', 'Non conforme à la commande') : m.nom)}</span>`).join('')}</div>`;
const photos = (n, plus) => `<div class="rc-photos"><div class="ph"><img src="photos/viennoiseries.jpg"><i>✕</i></div>${n > 1 ? '<div class="ph"><img src="photos/vitrine.jpg"><i>✕</i></div>' : ''}${plus ? '<div class="plus"><b>＋</b>Prendre une photo</div>' : ''}</div>`;
const NOTE = 'Pains au chocolat trop petits et peu feuilletés à la sortie du four, sur tout le carton (60 pièces). Photos prises à 06:10, à côté d’un pain au chocolat de la livraison précédente.';
const AIDE = `<div class="rc-aide"><b>Photos et détails, sinon refus.</b> En mai, ${esc(refusSansPhoto.fournisseur)} a refusé 2 réclamations : « Merci d’ajouter des photos et de préciser en détail la nature du problème. »</div>`;
const valeur = `${QTE} × ${nf(PAC.prix, 2)} € = <b>${eur(QTE * PAC.prix)}</b> au prix d’achat`;
const FORM = `
  <div class="rc-champ"><label>La livraison</label><div class="rc-in"><span>📦</span><span><b>${esc(LIV.cle)}</b> <span class="mu">· ${esc(LIV.fournisseurNom)} · reçue le ${fD(LIV.le)}</span></span><span class="mu" style="margin-left:auto">▾</span></div></div>
  <div class="rc-champ"><label>Le produit</label><div class="rc-in ouvert"><span>🔍</span><span>pain au choc</span></div>
    <div class="rc-sugg"><div><span><b>Pain au Chocolat</b></span><small>SKU ${esc(PAC.sku)} · ${esc(PAC.unite)}</small></div>${D.matieres.filter(m => m !== PAC).slice(0, 2).map(m => `<div><span>${esc(m.nom)}</span><small>SKU ${esc(m.sku)}</small></div>`).join('')}</div></div>
  <div style="display:flex;gap:14px;flex-wrap:wrap"><div class="rc-champ"><label>Quantité concernée</label><div class="rc-qte"><b>−</b><span>${QTE}</span><b>＋</b></div></div>
    <div class="rc-champ" style="flex:1"><label>Ce que vous demandez</label><div class="rc-puces"><span class="on">Remplacement</span><span>Remboursement</span><span>Note de crédit</span></div></div></div>
  <div class="rc-champ"><label>Le problème</label>${motifs('size_or_weight_issue')}</div>
  <div class="rc-champ"><label>Photos · 2</label>${photos(2, true)}</div>
  <div class="rc-champ"><label>Note pour le fournisseur</label><div class="rc-note">${esc(NOTE)}</div></div>`;

/* --- Les pages ------------------------------------------------------------------ */
const page = (titre, corps) => `<!DOCTYPE html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>${esc(titre)}</title>
<link rel="stylesheet" href="/public/assets/ds/global.css">
<link rel="stylesheet" href="/public/dashboard/dashboard.css">
<link rel="stylesheet" href="rc.css"></head>
<body>${corps}</body></html>`;
const [HD, NAV, NCBAR, BENCH, TACHES, STBAR, SEC, TUILES] = CTX.bureau;
const TOGGLE = `<span class="rc-toggle"><span class="sw"></span>Réclamations <em>${enAttente.length}</em></span>`;
// Le bouton prend place dans la barre, juste avant « Relire ».
const NAV_A = NAV.replace(/(<span class="db-lab">Date<\/span>)/i, TOGGLE + ' $1');
const bureau = (nav, apres) => `<div id="dash">${HD}${nav}${NCBAR}${BENCH}${TACHES}${STBAR}${SEC}${TUILES}</div>${apres || ''}`;
const TABS4 = on => `<div class="mb-tabs mb-tabs3" style="grid-template-columns:repeat(4,1fr)">${[['jour', 'Le jour', '◉'], ['semaine', 'La semaine', '▤'], ['rec', 'Réclamations', '📦'], ['actions', 'Plan d’action', '✓']]
  .map(o => `<button class="${o[0] === on ? 'on' : ''}" style="position:relative"><i>${o[2]}</i>${o[1]}${o[0] === 'rec' ? `<sup style="position:absolute;top:-2px;left:calc(50% + 8px);background:#D97706;color:#fff;border-radius:999px;font:700 9px var(--font-ui);padding:1px 5px">${enAttente.length}</sup>` : ''}</button>`).join('')}</div>`;
const mobile = (scInner, tabs, dessus) => `<div id="dash" class="mob">${CTX.mobile.hd}<div class="mb-sc">${scInner}</div>${tabs || CTX.mobile.tabs}</div>${dessus || ''}`;
const MUR = extra => CTX.mobile.rangs[0] + (extra || '') + '<div class="mb-mur">' + CTX.mobile.rangs.slice(1).join('') + '</div>';
const FILTRES = sel => `<div class="rc-filtres"><span class="${sel === 'tout' ? 'on' : ''}">Toutes<b>${R.length}</b></span><span class="${sel === 'att' ? 'on' : ''}">En attente<b>${nb('att')}</b></span><span class="${sel === 'sans' ? 'on' : ''}">Sans suite<b>${nb('sans')}</b></span><span>Réglées<b>${nb('ok')}</b></span><span>Refusées<b>${nb('ko')}</b></span></div>`;

/* A — un bouton dans la barre ouvre un tiroir à deux onglets ------------------- */
const tiroir = onglet => `<div class="rc-voile"></div><div class="rc-tiroir"><div class="hd"><div class="t"><h2>Réclamations fournisseur</h2><span class="x">✕</span></div>
  <div class="s">Gosselies · ${R.length} sur 12 mois · ${enAttente.length} sans suite, ${eur(montantAttente)} au prix d’achat</div>
  <div class="rc-onglets"><span class="${onglet === 'n' ? 'on' : ''}">＋ Nouvelle réclamation</span><span class="${onglet === 'h' ? 'on' : ''}">Mes réclamations <em>${enAttente.length}</em></span></div></div>
  <div class="corps">${onglet === 'n'
    ? `${AIDE}<div class="bloc">${FORM}<div class="rc-envoi"><span class="rc-mu" style="font-size:11px">${valeur}</span><span class="rc-btn">Envoyer à ${esc(LIV.fournisseurNom)}</span></div></div>`
    : `${FILTRES('tout')}<div class="bloc" style="padding:4px 15px">${R.slice(0, 7).map(l => carte(l)).join('')}</div>`}</div></div>`;
const A_MOB = `<div style="padding-top:6px">${FILTRES('tout')}<div class="rc-btn" style="text-align:center;margin:2px 0 10px">＋ Nouvelle réclamation · photo</div>${R.slice(0, 5).map(l => carte(l, true)).join('')}</div>`;

/* B — une bascule « Les chiffres | Les réclamations » ---------------------------- */
const BASC = `<div class="rc-bascule"><span class="sw"><span>📊 Les chiffres</span><span class="on">📦 Les réclamations <em>${enAttente.length} sans suite</em></span></span><span class="db-mini">créer, documenter et suivre ce qui a été réclamé aux fournisseurs — la bascule reste sur la page choisie</span></div>`;
const tuile = (k, v, s, cls) => `<div class="db-tui ${cls || ''}"><div class="k">${k}</div><div class="v">${v}</div><div class="s">${s}</div></div>`;
const B_TUILES = `<div class="db-tuiles">${tuile('Sur 12 mois', String(R.length), 'réclamations · ' + new Set(R.map(l => l.fournisseur)).size + ' fournisseur')}
  ${tuile('En attente', String(nb('att')), 'envoyées, pas encore traitées')}
  ${tuile('Acceptées sans suite', String(nb('sans')), 'la plus ancienne : ' + fD(R.filter(l => etat(l).c === 'sans').slice(-1)[0].le) + '/2025', 'vif')}
  ${tuile('Réglées', String(nb('ok')), 'expédition ou remboursement annoncés', 'bon')}
  ${tuile('Refusées', String(nb('ko')), 'faute de photos et de détails')}
  ${tuile('En jeu', eur(montantAttente), 'au prix d’achat, sur les ' + enAttente.length + ' sans suite')}</div>`;
const ETAPES = on => `<div class="rc-etapes">${['Livraison', 'Produit', 'Le problème', 'Envoyer'].map((n, i) => `<span class="${i + 1 < on ? 'fait' : (i + 1 === on ? 'on' : '')}"><i>${i + 1 < on ? '✓' : i + 1}</i>${n}</span>`).join('')}</div>`;
const B_FORM = `<div class="db-card" style="padding:14px 16px"><div class="db-lab" style="margin-bottom:10px">Nouvelle réclamation</div>${ETAPES(3)}
  <div class="rc-recap"><div><b>Livraison</b>${esc(LIV.cle.slice(0, 14))}… · ${esc(LIV.fournisseurNom)} · ${fD(LIV.le)}</div><div><b>Produit</b>Pain au Chocolat · SKU ${esc(PAC.sku)}</div></div>
  ${AIDE}
  <div style="display:flex;gap:14px"><div class="rc-champ"><label>Quantité</label><div class="rc-qte"><b>−</b><span>${QTE}</span><b>＋</b></div></div>
    <div class="rc-champ" style="flex:1"><label>Ce que vous demandez</label><div class="rc-puces"><span class="on">Remplacement</span><span>Remboursement</span><span>Note de crédit</span></div></div></div>
  <div class="rc-champ"><label>Le problème</label>${motifs('size_or_weight_issue', true)}</div>
  <div class="rc-champ"><label>Photos · 2</label>${photos(2, true)}</div>
  <div class="rc-champ"><label>Note pour le fournisseur</label><div class="rc-note">${esc(NOTE)}</div></div>
  <div class="rc-envoi"><span class="rc-btn sec">‹ Produit</span><span class="rc-btn">Vérifier et envoyer ›</span></div></div>`;
const B_HIST = `<div class="db-card"><div class="ct"><span class="db-lab">Mes réclamations — 12 mois</span>${FILTRES('tout')}</div>
  <table class="rc-t"><tr><th>État</th><th>Le</th><th>Produit · motif</th><th style="text-align:right">Qté</th><th style="text-align:right">Valeur</th><th>Réponse du fournisseur</th></tr>
  ${R.slice(0, 13).map(l => { const e = etat(l); return `<tr class="${l.ouverte ? 'ouv' : ''}"><td><span class="rc-st ${e.c}">${esc(e.lib)}</span></td><td class="n" style="text-align:left">${fD(l.le)}<div class="mu">n° ${l.id}</div></td>
    <td><b>${esc(l.reference || 'La livraison')}</b><div class="mu">${esc(MOTIF[l.motif] || l.motif)}${l.pj ? ' · 📎 ' + l.pj : ' · sans photo'}</div></td><td class="n">${l.qte != null ? nf(l.qte, 0) : '—'}</td><td class="n">${l.montant ? eur(l.montant) : '—'}</td>
    <td class="tx">${l.reponse ? esc(premiereLigne(l.reponse.replace(/^Bonjour,\s*/, '')).slice(0, 80)) : (e.c === 'sans' ? '<span style="color:#8a3d0b">aucune, depuis ' + jours(l.le) + ' j</span>' : 'en attente · ' + jours(l.le) + ' j')}</td></tr>`; }).join('')}</table>
  <div style="padding:8px 16px 12px;font-size:11px;color:var(--color-text-muted)">+ ${R.length - 13} autres · un clic ouvre la réclamation, ses photos et la réponse complète</div></div>`;
const B_BUREAU = `<div id="dash">${HD}${NAV}${BASC}${B_TUILES}<div class="rc-p2">${B_FORM}${B_HIST}</div></div>`;
const B_MOB = `<div style="padding-top:4px"><div class="rc-bascule" style="margin:4px 0 10px"><span class="sw" style="width:100%"><span style="flex:1;justify-content:center;padding:8px 0">Les chiffres</span><span class="on" style="flex:1;justify-content:center;padding:8px 0">Réclamations <em>${enAttente.length}</em></span></span></div>
  <div class="db-card" style="padding:12px 13px">${ETAPES(3).replace('margin:-2px -16px 14px', '')}<div class="rc-recap" style="grid-template-columns:1fr"><div><b>Livraison · produit</b>${esc(LIV.fournisseurNom)} ${fD(LIV.le)} · Pain au Chocolat</div></div>
  <div class="rc-champ"><label>Le problème</label>${motifs('size_or_weight_issue', true)}</div><div class="rc-champ"><label>Photos · 2</label>${photos(2, true)}</div>
  <div class="rc-champ"><label>Note</label><div class="rc-note" style="min-height:48px">${esc(NOTE.split('.')[0])}.</div></div><div class="rc-btn" style="text-align:center">Vérifier et envoyer ›</div></div></div>`;

/* C — la photo d'abord, au téléphone -------------------------------------------- */
const C_CARTE = `<div class="rc-mcarte"><div class="k">Réclamations fournisseur · <em>${enAttente.length} sans suite · ${eur(montantAttente)}</em></div>${R.slice(0, 2).map(l => carte(l, true)).join('')}<div style="font-size:10.5px;color:var(--color-primary);font-weight:600;margin-top:4px">Voir les ${R.length} réclamations ›</div></div>`;
const C_FAB = `<div class="rc-fab"><b>📷</b>Réclamer</div>`;
const C1 = mobile(MUR(C_CARTE), CTX.mobile.tabs, C_FAB);
const ecran = (etape, titre, corps, pied) => `<div class="rc-ecran"><div class="hd"><b>${titre}</b><span class="mu" style="font-size:11px">${etape} / 3</span><span class="x">✕</span></div>
  <div class="prog">${[1, 2, 3].map(i => `<i class="${i <= etape ? 'f' : ''}"></i>`).join('')}</div><div class="corps">${corps}</div><div class="pied">${pied}</div></div>`;
const C2 = mobile(MUR(), null, ecran(1, 'Les photos', `<div class="rc-viseur"><img src="photos/viennoiseries.jpg"><span class="cadre"></span><span class="n">2 photos</span><span class="dec"></span></div>
  <div style="margin-top:10px">${photos(2, true)}</div><div class="rc-aide" style="margin-top:10px">Le produit en entier, l’étiquette du carton, et un repère de taille : ${esc(refusSansPhoto.fournisseur)} refuse les réclamations sans photo.</div>`,
  '<span class="rc-btn">Continuer ›</span>'));
const C3 = mobile(MUR(), null, ecran(2, 'Le produit et le problème', `<div class="rc-champ"><label>Reçu le ${fD(LIV.le)} · ${esc(LIV.fournisseurNom)}</label><div class="rc-puces">${['Pain au Chocolat', 'Croissant', 'Baguette Tradition 500 g.', 'Riz Croustillant – 28 Ø'].map((n, i) => `<span class="${i === 0 ? 'on' : ''}">${esc(n)}</span>`).join('')}<span>🔍 autre…</span></div></div>
  <div class="rc-champ"><label>Combien</label><div class="rc-qte"><b>−</b><span>${QTE}</span><b>＋</b></div></div>
  <div class="rc-champ"><label>Le problème</label>${motifs('size_or_weight_issue', true)}</div>
  <div class="rc-champ"><label>Note</label><div class="rc-note" style="min-height:52px">${esc(NOTE.split('.')[0])}. <span class="mu">🎤</span></div></div>`,
  '<span class="rc-btn sec">‹</span><span class="rc-btn">Envoyer à ' + esc(LIV.fournisseurNom) + '</span>'));
const C4 = mobile(MUR(), null, ecran(3, 'Envoyée', `<div class="rc-ok"><span class="rond">✓</span><h3>Réclamation envoyée à ${esc(LIV.fournisseurNom)}</h3><div class="mu" style="font-size:12px">Pain au Chocolat · ${QTE} pcs · ${eur(QTE * PAC.prix)} · 2 photos</div></div>
  <div class="db-lab" style="margin:12px 0 4px">Vos dernières réclamations</div>${R.slice(0, 3).map(l => carte(l, true)).join('')}`,
  '<span class="rc-btn">Terminé</span>'));

/* --- Écriture et captures ---------------------------------------------------- */
const ECRANS = [
  ['a-nouvelle', page('A — tiroir, nouvelle', bureau(NAV_A, tiroir('n'))), 'b'],
  ['a-historique', page('A — tiroir, historique', bureau(NAV_A, tiroir('h'))), 'b'],
  ['a-mobile', page('A — téléphone', mobile(A_MOB, TABS4('rec'))), 'm'],
  ['b-bureau', page('B — page Réclamations', B_BUREAU), 'b'],
  ['b-mobile', page('B — téléphone', mobile(B_MOB)), 'm'],
  ['c-mur', page('C — mur + bouton', C1), 'm'],
  ['c-photos', page('C — photos', C2), 'm'],
  ['c-probleme', page('C — produit et problème', C3), 'm'],
  ['c-envoyee', page('C — envoyée', C4), 'm'],
];
const PLANCHES = [
  { id: 'a', titre: 'A — Un interrupteur « Réclamations » qui ouvre un tiroir',
    acc: 'Dans la barre du dashboard, un interrupteur « Réclamations » avec le nombre de réclamations sans suite. Il ouvre un tiroir à droite, deux onglets : « Nouvelle réclamation » (livraison, produit, quantité, motif, photos, note, envoi) et « Mes réclamations » (état, réponse du fournisseur, photos). Au téléphone : un quatrième onglet en bas.',
    ecrans: [['a-nouvelle', 'bureau — nouvelle réclamation'], ['a-historique', 'bureau — mes réclamations'], ['a-mobile', 'téléphone — l’onglet Réclamations']],
    plus: ['Toujours à un clic, sans quitter les chiffres.', 'Tout le formulaire sur un écran : rapide pour qui connaît.', 'Le compteur « sans suite » reste visible en permanence.'],
    moins: ['Un formulaire long dans un tiroir étroit.', 'Au téléphone, la photo passe après le formulaire.'] },
  { id: 'b', titre: 'B — Une bascule « Les chiffres | Les réclamations »',
    acc: 'Comme pour les contrôles : une bascule en tête de page. La page Réclamations pose les compteurs (en attente, sans suite, réglées, refusées, ce qui est en jeu), un formulaire en quatre étapes à gauche et l’historique complet à droite, avec la réponse de chaque fournisseur.',
    ecrans: [['b-bureau', 'bureau — la page Réclamations'], ['b-mobile', 'téléphone — étape 3, le problème']],
    plus: ['Le suivi a sa place : les 14 « acceptées sans suite » sautent aux yeux, à relancer.', 'Les étapes guident : impossible d’envoyer sans photo ni note.', 'Prêt pour la visite consultant : tout l’historique sur une page.'],
    moins: ['Une page de plus à tenir.', 'Plus de clics pour une réclamation simple.'] },
  { id: 'c', titre: 'C — La photo d’abord, au téléphone',
    acc: 'Pensé pour la réception de la marchandise : un bouton « Réclamer » flottant sur le mur du téléphone. On photographie d’abord, puis on touche le produit parmi ceux de la dernière livraison, la quantité, le motif, une note (dictée possible), et on envoie. L’historique reste en carte sur le mur.',
    ecrans: [['c-mur', 'téléphone — le mur et le bouton'], ['c-photos', '1 — les photos'], ['c-probleme', '2 — le produit et le problème'], ['c-envoyee', '3 — envoyée']],
    plus: ['Au geste de la réception : la photo est prise tout de suite, le reste en trente secondes.', 'Les produits de la dernière livraison proposés d’office.', 'Le moins de saisie au clavier.'],
    moins: ['Pensé téléphone d’abord : au bureau, il faut une version tiroir (A) ou page (B).', 'Les produits hors dernière livraison passent par la recherche.'] },
];

(async () => {
  for (const [id, html] of ECRANS) { fs.writeFileSync(path.join(OUT, id + '.html'), html); }
  const b = await chromium.launch({ args: ['--proxy-bypass-list=127.0.0.1;localhost'] });
  for (const [id, , k] of ECRANS) {
    const p = await b.newPage(k === 'b' ? { viewport: { width: 1348, height: 900 }, deviceScaleFactor: 1.25 } : { viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true });
    const err = []; p.on('pageerror', e => err.push(e.message)); p.on('requestfailed', r => err.push('échec ' + r.url()));
    await p.goto('http://127.0.0.1:8099/docs/maquettes/reclamations/' + id + '.html', { waitUntil: 'load' });
    await p.evaluate(() => document.fonts.ready); await p.waitForTimeout(300);
    await p.screenshot({ path: path.join(OUT, id + '.jpg'), type: 'jpeg', quality: 86, fullPage: k === 'b' && id === 'b-bureau' });
    await p.close(); console.log('✓', id, err.length ? err : '');
  }
  for (const P of PLANCHES) {
    const imgs = P.ecrans.map(([id, leg]) => { const mob = !/^(a-nouvelle|a-historique|b-bureau)$/.test(id);
      const nbB = P.ecrans.filter(e => /^(a-nouvelle|a-historique|b-bureau)$/.test(e[0])).length;
      return `<div><img src="${id}.jpg" style="width:${mob ? 280 : (nbB > 1 ? 640 : 960)}px"><div class="leg">${esc(leg)}</div></div>`; }).join('');
    const html = page('Planche ' + P.id.toUpperCase(), `<div class="pl"><span class="u">maquette · dashboard magasin · Gosselies · 27 réclamations réelles sur 12 mois (panel) · l’exemple de saisie est une illustration</span>
      <h1>${esc(P.titre)}</h1><p class="acc">${esc(P.acc)}</p>
      <div class="g"><div class="col"><h4>Pour</h4><ul class="pl-p">${P.plus.map(x => `<li>${esc(x)}</li>`).join('')}</ul><h4>Contre</h4><ul class="pl-m">${P.moins.map(x => `<li>${esc(x)}</li>`).join('')}</ul></div>
      <div class="g" style="flex-wrap:wrap">${imgs}</div></div></div>`);
    fs.writeFileSync(path.join(OUT, 'planche-' + P.id + '.html'), html);
    const p = await b.newPage({ viewport: { width: 1720, height: 1000 }, deviceScaleFactor: 1 });
    await p.goto('http://127.0.0.1:8099/docs/maquettes/reclamations/planche-' + P.id + '.html', { waitUntil: 'load' });
    await p.evaluate(() => document.fonts.ready); await p.waitForTimeout(300);
    await p.screenshot({ path: path.join(OUT, 'planche-' + P.id + '.jpg'), type: 'jpeg', quality: 86, fullPage: true });
    await p.close(); console.log('✓ planche', P.id);
  }
  await b.close();
})();
