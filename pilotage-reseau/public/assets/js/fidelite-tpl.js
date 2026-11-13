/* Cockpit CEO — gabarit de l'écran Fidélité (ERP franchisé › Fidélité, #/fidelity), réécrit le 13/11/2026 : un mix
 * bingo + points, une seule carte, lue et publiée dans l'ERP. Valeurs : fidelite-vals.js (`c.fid`) ; style :
 * fidelite.css (préfixe fi-). Maquettes : docs/maquettes/fidelite-bingo (1 : le programme, 3 : les clients). */

const IMG = 'assets/img/bingo/';
const ill = (n, cls) => n ? `<img class="${cls || 'fi-ill'}" src="${IMG}${n}.png" alt="" draggable="false">` : '';
const api = (t, esc) => `<code class="fi-api">${esc(t)}</code>`;
const gap = (titre, t, esc) => `<div class="fi-gap"><span>${esc(titre)}</span>${esc(t)}</div>`;

function tplProgramme(f, x){
  const { esc } = x;
  if (f.chargement) { return '<div class="fi-vide">Lecture de la carte dans l’ERP…</div>'; }
  if (!f.erp) { return ''; }
  const erp = f.erp.ok ? '' : gap('ERP injoignable', 'La carte ne se lit pas : ' + (f.erp.erreur || 'sans réponse') + '. Le compte admin ERP se règle dans Paramètres.', esc);
  const C = f.carte;
  const annee = C ? `<div class="fi-annee">${f.annee.map(a => `<button class="fi-sa${a.on ? ' on' : ''} ${a.etat}" ${x.A(a.choisir)}><span class="fi-sa-i">${ill(a.ill)}</span>
      <b>${esc(a.nom)}</b><small>${esc(a.du)} → ${esc(a.au)}</small><em class="fi-tag ${a.etat === 'cours' ? 'ok' : 'neu'}">${esc(a.etatTxt)}</em>${a.aValider ? '<em class="fi-tag av">thèmes à valider</em>' : ''}</button>`).join('')}</div>` : '';
  const carte = C ? `<section class="fi-grp"><h3>Les cartes de l’année · une carte à points, quatre cartes de bingo ${api('GET /admin/loyalty/cards', esc)}</h3><div class="fi-an"><div class="fi-liste fi-carte">
    <div class="fi-carte-v"><div class="fi-carte-h"><span>L’Atelier by</span><span>Points · Bingo</span></div>
      <div class="fi-carte-m"><span class="fi-blanc">${ill('hiver')}</span><div><b>${esc(C.nom)}</b><small>${esc(C.regle || '')}</small></div></div>
      <div class="fi-carte-p">${esc(C.code)} · v${C.version} · réseau, ${C.nMag} magasins${C.debut ? ' · depuis le ' + esc(C.debut) : ''}</div></div>
    <div class="fi-carte-t"><div class="fi-l"><span>La carte à points, permanente : ce que le client dépense.</span><span class="fi-tag ok">active</span></div>
      ${f.autres.length ? `<div class="fi-l mu"><span>${f.autres.map(a => `<i class="fi-pt" style="background:${esc(a.couleur)}"></i>${esc(a.nom)}`).join(', ')} : ${f.arreter ? 'arrêtées à la publication' : 'gardées'} ; leurs récompenses prêtes restent valables jusqu’à leur échéance.</span>
        <button class="fi-tog${f.arreter ? ' on' : ''}" ${x.A(f.basculerArreter)} role="switch" aria-checked="${f.arreter}" title="Arrêter les cartes à tampons"></button></div>` : ''}
      <div class="fi-src">revision ${C.revision}${C.verrou ? ' · carte utilisée : barème figé, il se change par une nouvelle version' : ''}</div></div>
  </div>${annee}</div><p class="fi-pied">La carte à points est permanente ; ses points de chaque semaine remplissent la carte de bingo de la saison. Une saison = 13 semaines, la suivante arrive le lundi d’après. Cliquez une saison pour la voir.</p></section>` : (f.erp.ok ? gap('Pas de carte', 'Aucune carte à points active dans l’ERP : créez-la d’abord (POST /admin/loyalty/cards).', esc) : '');
  const M = f.marques || {};
  const mod = k => M[k] ? '<span class="fi-mod">modifié</span>' : '';
  const points = f.base ? `<section class="fi-grp"><h3>Les points · ce que le client gagne ${api('points_config', esc)}</h3><div class="fi-liste">
    <div class="fi-r"><span class="fi-ic">€</span><div class="fi-ph">Chaque <input class="fi-in" value="${esc(f.base.euros)}" ${x.C(f.base.setEuros)} style="width:58px"> € dépensé rapporte <input class="fi-in" type="number" min="1" value="${f.base.points}" ${x.C(f.base.setPoints)} style="width:44px"> point${f.base.points > 1 ? 's' : ''}, arrondi à l’inférieur, en boutique et au webshop.
      <small>${api('slice_cents', esc)} ${api('points_per_slice', esc)}</small></div>${mod('base')}</div>
    ${f.bonus.map(b => `<div class="fi-r"><span class="fi-ic">×${esc(String(b.coef).replace('.', ','))}</span><div class="fi-ph">Les points comptent <select class="fi-in s" ${x.C(b.setCoef)}>${[1.5, 2, 3].map(v => `<option value="${v}"${v === b.coef ? ' selected' : ''}>×${String(v).replace('.', ',')}</option>`).join('')}</select>
      <span class="fi-jours">${b.jours.map(j => `<button ${x.A(j.basculer)} class="${j.on ? 'on' : ''}" title="${esc(j.titre)}">${j.l}</button>`).join('')}</span> de <input class="fi-in h" value="${esc(b.de)}" ${x.C(b.setDe)}> à <input class="fi-in h" value="${esc(b.a)}" ${x.C(b.setA)}> — <input class="fi-in t" value="${esc(b.nom)}" ${x.C(b.setNom)} style="width:130px">
      <small>${api('bonuses[]', esc)} heure du magasin · sur tout le ticket · deux ×2 à la fois : le plus fort l’emporte</small></div><button class="fi-x" ${x.A(b.retirer)} title="Retirer">×</button></div>`).join('')}
    <button class="fi-add" ${x.A(f.ajouterBonus)}><span>+</span>Ajouter un créneau ×2</button>
    <div class="fi-r"><span class="fi-ic">◎</span><div class="fi-ph">Par magasin : ${f.coefs.map(m => `${esc(m.nom)} ×<input class="fi-in${m.modif ? ' mod' : ''}" value="${esc(m.v)}" ${x.C(m.set)} style="width:50px">`).join(' · ')}
      <small>${api('shop_coefficients', esc)} un coefficient vaut toute la semaine : l’API ne lie pas un créneau à un magasin</small></div>${mod('coefs')}</div>
  </div></section>
  <section class="fi-grp"><h3>Les points · ce qu’il en fait</h3><div class="fi-liste">
    <div class="fi-r"><span class="fi-ic">↗</span><div class="fi-ph">Il échange ses points dans l’app, au palier exact :
      ${f.paliers.map(p => `<span class="fi-pal"><input class="fi-in" type="number" min="1" value="${p.points}" ${x.C(p.setPoints)} style="width:58px"> → <input class="fi-in" value="${esc(p.euros)}" ${x.C(p.setEuros)} style="width:54px"> € <em>${esc(p.taux)} %</em>${p.retirer ? `<button class="fi-x" ${x.A(p.retirer)} title="Retirer">×</button>` : ''}</span>`).join('')}
      <small>${api('tiers[]', esc)} jusqu’à 19 paliers · % : ce que le palier rend sur les achats · jamais automatique ${api('POST /webshop/loyalty/points/redeem', esc)}</small></div>${mod('paliers')}</div>
    ${f.ajouterPalier ? `<button class="fi-add" ${x.A(f.ajouterPalier)}><span>+</span>Ajouter un palier</button>` : ''}
    <div class="fi-r"><span class="fi-ic">j</span><div class="fi-ph">Le bon vaut <input class="fi-in" type="number" min="0" value="${f.validites.bon}" ${x.C(f.validites.setBon)} style="width:52px"> jours ; chaque point vaut <input class="fi-in" type="number" min="0" value="${f.validites.points}" ${x.C(f.validites.setPoints)} style="width:58px"> jours, les plus anciens partent en premier.
      <small>${api('voucher_validity_days', esc)} ${api('points_validity_days', esc)} · 0 = sans fin</small></div>${mod('validites')}</div>
  </div></section>` : '';
  const B = f.bingo;
  const bingo = B ? `<section class="fi-grp"><h3>La carte de bingo · ${esc(f.saison.nom)}${f.saison.aValider ? ' <span class="fi-tag av">thèmes à valider</span>' : ''}</h3><div class="fi-liste">
    <div class="fi-r"><span class="fi-ic">€</span><div class="fi-ph">Une case par semaine dès <span class="fi-v">${esc(B.seuilTxt)}</span> d’achats, du lundi au dimanche, tous magasins, en une ou plusieurs fois. Une case par semaine au plus ; une semaine sans case est manquée pour toujours.
      <small>Les achats de la semaine : ${api('GET /shops/{id}/loyalty/customers?card_id&from&to', esc)} → ${api('shop_activity.gross_sales_before_corrections', esc)}, additionnés sur les magasins</small></div><span class="fi-st">fait revenir</span></div>
    <div class="fi-r"><span class="fi-ic">${B.tousLes}</span><div class="fi-ph">Toutes les <span class="fi-v">${B.tousLes}</span> cases cochées, un bon, valable <span class="fi-v">${B.validite} jours</span> dès le lendemain :
      <span class="fi-bons">${B.bons.map(b => `<span class="fi-bon${b.camp ? '' : ' manque'}" title="${esc(b.camp ? b.camp.nom + ' · ' + b.camp.statut : 'pas de campagne « ' + B.prefixe + ' … ' + b.v + ' » dans l’ERP')}"><span class="fi-blanc">${ill(b.motif)}</span>${esc(b.v)}<em>${b.k}e case</em></span>`).join('')}</span>
      <small>Une campagne par valeur pour la saison, nommée « ${esc(B.prefixe)} · … € » ${api('GET /admin/voucher-campaigns', esc)} ; le bon du client ${api('POST …/codes {id_customer, valid_to, usage_limit: 1}', esc)}</small></div>
      <span class="fi-st ${f.nbCamp === B.bons.length ? 'ok' : 'att'}">${f.nbCamp === B.bons.length ? 'campagne reliée' : 'campagne à créer'}</span></div>
    ${B.sup ? `<div class="fi-r"><span class="fi-ic">${B.semaines.length}</span><div class="fi-ph">Carte pleine (13 / 13) : le super bon cadeau de <span class="fi-v">${esc(B.sup.v)}</span>${B.tirage ? ` et le tirage au sort d’<span class="fi-v t">${esc(B.tirage.lot)}</span>${B.tirage.le ? ' le ' + esc(B.tirage.le) : ''}` : ''}.
      <small>${B.sup.camp ? 'campagne ' + esc(B.sup.camp.statut) + (B.sup.camp.actif ? '' : ' : rien ne part tant qu’elle n’est pas active') : 'pas encore de campagne'} · une semaine manquée le rend impossible pour la saison</small></div><span class="fi-st ${B.sup.provisoire ? 'att' : ''}">${B.sup.provisoire ? 'montant à confirmer' : ''}</span></div>` : ''}
    <div class="fi-r fi-sem"><span class="fi-ic clair">${ill('hiver')}</span><div><div class="fi-sem-g">${B.semaines.map(w => `<div class="${w.etat}${w.fete ? ' fete' : ''}" title="S${w.n} · ${esc(w.titre)} · ${esc(w.du)}"><span>${ill(w.ill)}</span><em>S${w.n}</em></div>`).join('')}</div>
      <small>${esc(f.saison.nom)} : ${f.saison.n} semaines, du ${esc(f.saison.du)} au ${esc(f.saison.au)}${f.saison.enCours ? ' · S' + f.saison.enCours + ' en cours' : ''}. Le calendrier, les fêtes et les dessins : ${api('src/data/fidelite_bingo.json', esc)}</small></div></div>
  </div>${gap('Manque à l’API', 'L’ERP ne coche pas de case : le cockpit lit les achats de chaque semaine et n’écrit dans l’ERP que les bons. Rien n’est gardé de son côté. À demander à l’ERP : la case de la semaine (seuil d’achats) dans la carte, pour que l’app et la caisse la voient sans le cockpit.', esc)}</section>` : '';
  const ensemble = B ? `<section class="fi-grp"><h3>Deux moteurs, une seule carte · le handoff</h3><div class="fi-liste">
    <div class="fi-r"><span class="fi-ic">B</span><div class="fi-ph">Le bingo fait revenir : il ne récompense qu’une visite qualifiante par semaine. Dépenser 40 € d’un coup ne coche qu’une case.<small>une case par semaine, des bons toutes les ${B.tousLes} cases, le super bon à 13 / 13</small></div><span></span></div>
    <div class="fi-r"><span class="fi-ic">×${esc(B.multTxt)}</span><div class="fi-ph">Les points font consommer : une fois la case cochée, les tickets suivants de la semaine comptent ×${esc(B.multTxt)} jusqu’au dimanche 23:59. Le ticket qui coche reste à ×1.
      <small>Le relais quand le bingo n’a plus rien à offrir jusqu’à lundi</small></div><span class="fi-st att">pas dans l’API</span></div>
    <div class="fi-r"><span class="fi-ic">${esc(B.palierTxt.split(' ')[0])}</span><div class="fi-ph">Tous les ${esc(B.palierTxt)} : un bon, de lui-même, et le reste des points est gardé. Les points ne s’arrêtent pas avec la saison.
      <small>Le palier de la carte ${api('tiers[]', esc)} ; l’ERP n’échange que si le client le demande ${api('POST /webshop/loyalty/points/redeem', esc)}</small></div><span class="fi-st att">échange manuel dans l’ERP</span></div>
    <div class="fi-r"><span class="fi-ic gris">1</span><div class="fi-ph">Un bon à la fois en caisse : bon de points ou bon du bingo, le client choisit dans l’app.<small>${api('POST /webshop/vouchers/activation', esc)} · la caisse le lit</small></div><span class="fi-st">ERP</span></div>
  </div>${gap('À demander à l’ERP', 'Le ×' + B.multTxt + ' d’un client une fois sa case cochée et l’échange automatique au palier n’existent pas dans l’API (les ×2 de l’ERP sont des créneaux pour tous ; le cockpit ne peut pas créditer de points). Le cockpit ne les simule pas.', esc)}
  <p class="fi-pied">Plus à l’écran, faute d’API : bonus panier, anniversaire, bienvenue, 2e visite, parrainage, retour après absence, plafond, jours exclus, budget. Le bingo reprend le rôle des déclencheurs de visite.</p></section>` : '';
  const coefs = f.coefs7 && f.coefs7.length ? `<section class="fi-grp"><h3>Les coefficients de la semaine · chaque client les siens</h3><div class="fi-liste">
    ${f.coefs7.map(([k, t, d]) => `<div class="fi-r"><span class="fi-ic${k === '×1' ? ' gris' : ''}">${esc(k)}</span><div class="fi-ph">${esc(t)}<small>${esc(d)}</small></div><span class="fi-st att">${k === '×1' ? '' : 'pas dans l’API'}</span></div>`).join('')}
    <div class="fi-r"><span class="fi-ic gris">max</span><div class="fi-ph">Ils ne s’additionnent pas : sur chaque ligne du ticket, le plus fort s’applique. Recalculés chaque lundi d’après le profil d’achat du client.
      <small>coefLigne = max(case cochée, jour perso, offre perso ou produit de la semaine) · le profil : jour et créneau habituels, catégories prises et jamais prises, panier moyen, produits délaissés</small></div><span></span></div>
  </div>${gap('À demander à l’ERP', 'L’API ne connaît qu’un ×2 par créneau horaire, pour tous et sur tout le ticket. Ces coefficients demandent à l’ERP un bonus par client et par produit, appliqué au calcul des points, et les lignes des tickets pour le profil. La demande est écrite : docs/maquettes/fidelite-bingo/DEMANDE-ERP.md.', esc)}</section>` : '';
  const S = f.sim, R = S.r;
  const sim = `<section class="fi-card"><h3>Simuler un ticket</h3><div class="fi-src">${api('POST /admin/loyalty/simulate', esc)} · barème en cours d’écriture</div>
    <div class="fi-sim-f"><label>Magasin <select class="fi-in s t" ${x.C(S.setShop)}>${S.shops.map(m => `<option value="${esc(m.id)}"${m.on ? ' selected' : ''}>${esc(m.nom)}</option>`).join('')}</select></label>
      <label>Montant <input class="fi-in t" value="${esc(S.montant)}" ${x.C(S.setMontant)} style="width:64px"> €</label>
      <label class="l2">Le <input class="fi-in t" type="datetime-local" value="${esc(S.quand)}" ${x.C(S.setQuand)}></label></div>
    <button class="fi-btn" ${x.A(S.lancer)}${S.envoi ? ' disabled' : ''}>${S.envoi ? 'Calcul…' : 'Simuler'}</button>
    ${R ? (R.error ? `<p class="fi-ko">${esc(R.error)}</p>` : `<div class="fi-kpi"><b>${R.points} points</b></div><table class="fi-kv">${S.lignes.map(([k, v], i) => `<tr><td>${esc(k)}</td><td class="${i === 3 && R.coche ? 'ok' : ''}">${esc(v)}</td></tr>`).join('')}</table>`) : ''}
    <div class="fi-src">Rien n’est écrit : ni vente, ni solde, ni bon.</div></section>`;
  const pub = C ? `<section class="fi-card"><h3>Publier dans l’ERP</h3>
    ${f.aPublier ? `<table class="fi-kv">${f.modifs.map(m => `<tr><td>${esc(m)}</td><td>barème</td></tr>`).join('')}${f.arreter ? f.autres.map(a => `<tr><td>Fin de ${esc(a.nom)}</td><td>${f.debut ? 'la veille' : 'ce soir'}</td></tr>`).join('') : ''}</table>` : '<p class="fi-mu">Rien de modifié.</p>'}
    ${C.verrou && f.modifs.length ? `${gap('Carte utilisée', 'Le barème de la v' + C.version + ' est figé : la publication crée la v' + (C.version + 1) + ' (même code) et arrête la v' + C.version + ' la veille à minuit.', esc)}
      <label class="fi-debut">La v${C.version + 1} commence le <input class="fi-in t" type="date" value="${esc(f.debut)}" ${x.C(f.setDebut)}></label>` : ''}
    <div class="fi-act"><button class="fi-cta" ${x.A(f.publier || (() => {}))}${!f.publier || f.envoi ? ' disabled' : ''}>${f.envoi ? 'Publication…' : 'Publier dans l’ERP'}</button>${f.annuler ? `<button class="fi-btn" ${x.A(f.annuler)}>Annuler</button>` : ''}</div>
    <div class="fi-src col"><span>${api(C.verrou ? 'POST /admin/loyalty/cards' : 'PATCH /admin/loyalty/cards/' + C.id, esc)} ${C.verrou ? 'la version suivante' : 'avec la revision ' + C.revision}</span><span>${api('PATCH /admin/loyalty/cards/{id}', esc)} les fins de carte</span><span>409 si quelqu’un a modifié entre-temps : on relit et on recommence.</span></div></section>` : '';
  const src = `<section class="fi-card"><h3>D’où vient l’écran</h3><table class="fi-kv">
    ${[['La carte, le barème', 'GET /admin/loyalty/cards'], ['Les magasins', 'GET /admin/loyalty/options'], ['Les bons du bingo', 'GET /admin/voucher-campaigns'], ['Les points de la semaine', 'GET /shops/{id}/loyalty/customers'], ['Écrire', 'POST · PATCH /admin/loyalty/cards']]
      .map(([k, v]) => `<tr><td>${esc(k)}</td><td>${api(v, esc)}</td></tr>`).join('')}</table><div class="fi-src">Aucune table du cockpit : tout se relit dans l’ERP à l’ouverture.</div></section>`;
  return `${erp}${carte}<div class="fi-g21"><div class="fi-col">${points}${bingo}${ensemble}${coefs}</div><aside class="fi-col fi-aside">${sim}${pub}${src}</aside></div>`;
}

function tplClients(f, x){
  const { esc } = x;
  if (f.chargementC && !f.erpC) { return '<div class="fi-vide">Lecture des clients dans l’ERP, semaine par semaine…</div>'; }
  if (f.erpC && !f.erpC.ok) { return gap('ERP injoignable', 'Les clients ne se lisent pas : ' + (f.erpC.erreur || 'sans réponse') + '.', esc); }
  if (!f.cl) { return ''; }
  const L = f.cl;
  const tuiles = `<div class="fi-tuiles">${f.tuiles.map(([k, v, s, r]) => `<div><span>${esc(k)}</span><b>${esc(v)}</b><small>${esc(s)}</small>${api(r, esc)}</div>`).join('')}</div>`;
  const graphe = `<section class="fi-card"><h3>Semaine après semaine · part des membres qui cochent <span class="fi-mu">${esc(L.seuilTxt)} d’achats dans la semaine</span></h3>
    <div class="fi-bars">${f.semaines.map(w => `<div class="fi-bar"><div class="fi-bar-c">
      ${w.avant != null ? `<div class="a"><span>${w.avant}</span><i style="height:${w.hA}px"></i></div>` : ''}<div class="p${w.cours ? ' cours' : ''}"><span>${w.part ?? ''}</span><i style="height:${w.hP}px"></i></div></div>
      <div class="fi-bar-l">${ill(w.ill)}<span class="${w.cours ? 'acc' : ''}">S${w.n} · ${esc(w.titre)}</span><em>${w.cours ? 'en cours' : esc(w.ecart)}</em></div></div>`).join('')}</div>
    <div class="fi-leg"><span><i class="p"></i>${esc(L.saison)}, avec le bingo</span><span><i class="a"></i>Mêmes semaines un an plus tôt, points seuls</span><span class="d">en % des membres venus dans la saison · en vert, l’écart</span></div>
    <div class="fi-src">Une semaine = un appel par magasin ${api('GET /shops/{id}/loyalty/customers?card_id&from=lundi&to=dimanche', esc)}</div></section>`;
  const rep = `<section class="fi-card"><h3>Où en sont les membres <span class="fi-mu">semaines closes</span></h3>
    ${f.repartition.map(r => `<div class="fi-rep"><span>${r.k} case${r.k > 1 ? 's' : ''}${r.bon ? ' <em class="fi-tag plein">bon</em>' : ''}</span><i class="${r.k === 0 ? 'z' : (r.plein ? 'f' : '')}" style="width:${r.l}%"></i><b>${esc(r.n)}</b></div>`).join('')}
    <p class="fi-mu">Toutes les cases jusqu’ici : <span class="acc">${esc(f.enCourse)} membres</span> peuvent encore remplir la carte. Sans case : ${esc(f.sansCase)}.</p></section>`;
  const table = `<section class="fi-grp"><h3>Les membres · ${esc(L.mag)} ${api('GET /shops/{id}/loyalty/customers', esc)}
      <input class="fi-in t fi-q" type="search" placeholder="Chercher un prénom" value="${esc(f.q)}" ${x.C(f.chercher)}></h3>
    <div class="fi-liste"><div class="fi-tr h"><span>Client</span><span>Points · palier</span><span>Bingo</span><span>Cette semaine</span><span>Dernier passage</span><span>À faire</span></div>
    ${f.membres.map(m => `<div class="fi-tr"><span>${esc(m.nom)}</span><span>${esc(m.solde)}<small>${esc(m.palier)}</small></span>
      <span><span class="fi-cases">${m.coches.map(c => `<i class="${c ? 'on' : ''}"></i>`).join('')}</span><small>${m.cases} / ${m.n}</small></span>
      <span class="${m.ok ? 'ok' : ''}">${esc(m.semaine)}${m.ok ? ' · cochée' : ''}<small>${esc(m.pts)}</small></span><span class="mu">${esc(m.dernier)}</span><span class="mu">${esc(m.afaire)}</span></div>`).join('') || '<div class="fi-tr vide">Aucun membre.</div>'}
    </div><p class="fi-pied">${esc(f.nbMembres)} membres${f.q ? '' : ' · ici, quelques-uns de chaque cas : cherchez un prénom pour les voir tous'} · solde et paliers ${api('global_progress.points', esc)} · cette semaine ${api('shop_activity.points_earned', esc)} · dernier passage ${api('last_transaction_at', esc)} · prénom et initiale : l’API ne donne ni téléphone ni e-mail.</p></section>`;
  const B = f.bons;
  const bons = `<section class="fi-card"><h3>Les bons du bingo <span class="fi-mu">tout le réseau</span></h3>
    ${B.r && B.lignes ? `<table class="fi-kv">${B.lignes.map(l => `<tr><td>${esc(l.v)} · ${l.k}e case<small>${esc(l.ayants)} y ont droit · ${esc(l.emis)} émis · campagne ${esc(l.camp)}</small></td><td class="${l.a && l.actif ? 'acc' : ''}">${esc(l.aTxt)} à émettre</td></tr>`).join('')}</table>
      ${B.crees != null ? `<p class="fi-ok">${B.crees} bon${B.crees > 1 ? 's' : ''} créé${B.crees > 1 ? 's' : ''} dans l’ERP.</p>` : `<div class="fi-act"><button class="fi-cta" ${x.A(B.emettre)}${!B.total || B.envoi ? ' disabled' : ''}>${B.envoi ? 'Émission…' : 'Émettre ' + B.total + ' bon' + (B.total > 1 ? 's' : '')}</button></div>`}`
      : `<p class="fi-mu">Qui a gagné quel bon sur les semaines closes, et ce qui reste à émettre. Seules les campagnes actives dans l’ERP émettent ; chaque lundi, le cron le fait seul.</p><button class="fi-btn" ${x.A(B.voir)}${B.chargement ? ' disabled' : ''}>${B.chargement ? 'Calcul…' : 'Voir les bons dus'}</button>`}
    <div class="fi-src">${api('POST /admin/voucher-campaigns/{id}/codes', esc)} un code par client, une utilisation</div></section>`;
  const relances = `<section class="fi-grp"><h3>À relancer cette semaine</h3><div class="fi-liste">${f.relances.map(r => `<div class="fi-rel"><b>${esc(r.n)}</b><div>${esc(r.titre)}<small>${esc(r.detail)}</small></div></div>`).join('')}</div>
    <p class="fi-pied">Les listes partent par la newsletter ou la notification de l’app ; rien ne part d’ici.</p></section>`;
  const lire = `<section class="fi-card"><h3>Lire l’API sans se tromper</h3><div class="fi-notes">
    <p>Le solde et les paliers sont ceux du réseau : ils se répètent dans chaque magasin, le cockpit ne les additionne jamais.</p>
    <p>Les points gagnés et les ventes sont ceux du magasin, sur la période : ceux-là s’additionnent.</p>
    <p>Une carte reconnue n’est ni un scan prouvé ni un achat : une demande de la caisse, répétitions comprises.</p>
    <p>${esc(String(L.appels))} appel${L.appels > 1 ? 's' : ''} à l’ERP pour cette lecture ; les semaines closes se gardent un jour en cache.</p></div></section>`;
  return `<div class="fi-col">${tuiles}<div class="fi-g21">${graphe}${rep}</div><div class="fi-g21"><div class="fi-col">${table}</div><aside class="fi-col">${relances}${bons}${lire}</aside></div></div>`;
}

export function tplFidelite(c, x){
  const { esc } = x, f = c.fid;
  if (!f) { return ''; }
  const haut = `<div class="fi-haut"><div class="fi-segc"><button ${x.A(f.ongletP)} class="${f.onglet === 'p' ? 'on' : ''}">Programme</button><button ${x.A(f.ongletC)} class="${f.onglet === 'c' ? 'on' : ''}">Clients</button></div>
    ${f.onglet === 'c' ? `<div class="fi-segc p">${f.portees.map(p => `<button ${x.A(p.choisir)} class="${p.on ? 'on' : ''}">${esc(p.nom)}</button>`).join('')}</div>` : ''}
    <span class="fi-etat">${f.saison ? esc(f.saison.nom) + (f.saison.enCours ? ' · semaine ' + f.saison.enCours + ' sur ' + f.saison.n : '') : ''}${f.cl ? ' · ' + esc(f.cl.aujourdhui) : ''}</span></div>`;
  const msg = f.msg ? `<div class="fi-toast${f.msg.ko ? ' ko' : ''}">${esc(f.msg.txt)}<button ${x.A(f.fermerMsg)} aria-label="Fermer">×</button></div>` : '';
  return `<div data-screen="fidelite" class="fi">${haut}${msg}${f.onglet === 'c' ? tplClients(f, x) : tplProgramme(f, x)}</div>`;
}
