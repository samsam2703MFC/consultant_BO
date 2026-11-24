/* Dashboard magasin › Commandes (24/11/2026 ; maquettes panel/docs/maquettes/commandes-tournees/, A et B retenues sur un
 * interrupteur abricot : « A et B on toggle (apricot) »).
 *
 * Les commandes du jour, par canal (webshop, comptoir, bureau), liées aux tournées et aux bureaux à livrer. Deux vues,
 * un interrupteur à l'Abricot de la marque :
 *   - LISTE : le bandeau des canaux, toutes les commandes de la journée avec un filet « Maintenant », la tournée et
 *     l'arrêt de chacune (ou le retrait au magasin), les filtres ; à droite, les tournées du jour et demain (les bureaux
 *     qui n'ont pas encore commandé, avant 16:00) ;
 *   - TOURNÉES : la journée en frise (chaque véhicule, le retrait au magasin, l'heure qu'il est), puis une colonne par
 *     tournée — ses arrêts dans l'ordre, ses bureaux sans commande — et une colonne pour le retrait au magasin.
 * Un clic sur une commande l'ouvre : sa tournée se choisit (automatique : celle de son bureau ; une autre ; au magasin).
 * Les tournées se créent et se modifient ici (nom, départ, retour, livreur, véhicule, jours, bureaux dans l'ordre de
 * passage) : le panel n'en a pas.
 * Routes (panel/src/commandes_tournees.php) : GET /dashboard/commandes/jour, GET …/bureaux, POST|PUT|DELETE …/tournees,
 * POST …/tournees/bureaux, POST …/affecter.
 *
 * `window.DashCommandes.racine(shop, {apiBase, date, onCompte})` rend l'élément du module, gardé d'un rendu à l'autre (`date` :
 * le jour du dashboard, à la première ouverture) ;
 * `relire()` relit la journée. `onCompte(n)` reçoit les commandes à préparer aujourd'hui (le compteur du rail).
 */
(function () {
  'use strict';
  const esc = v => String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  const fE = n => (Number(n) || 0).toLocaleString('fr-BE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).replace(/\s/g, ' ') + ' €';
  const mn = t => { const m = /^(\d{1,2}):(\d{2})/.exec(String(t || '')); return m ? 60 * +m[1] + +m[2] : null; };
  const ymd = d => d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
  const dt = s => new Date(s + 'T12:00:00');
  const plus = (s, n) => { const d = dt(s); d.setDate(d.getDate() + n); return ymd(d); };
  const JOURS = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
  const MOIS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
  const long = s => { const d = dt(s); const j = JOURS[d.getDay()]; return j[0].toUpperCase() + j.slice(1) + ' ' + d.getDate() + ' ' + MOIS[d.getMonth()] + ' ' + d.getFullYear(); };
  const court = s => { const d = dt(s); return JOURS[d.getDay()].slice(0, 3) + '. ' + String(d.getDate()).padStart(2, '0') + '/' + String(d.getMonth() + 1).padStart(2, '0'); };
  const duree = m => m >= 60 ? Math.floor(m / 60) + ' h ' + String(m % 60).padStart(2, '0') : m + ' min';
  const AUJ = () => ymd(new Date());
  const SVG = d => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="${d}"/></svg>`;
  const ICO = {
    liste: 'M8 6h13M8 12h13M8 18h13M3.5 6h.01M3.5 12h.01M3.5 18h.01',
    camion: 'M1 5h13v11H1zM14 9h4l4 4v3h-8M5.5 18.5a1.8 1.8 0 1 0 0-.01M17.5 18.5a1.8 1.8 0 1 0 0-.01',
    magasin: 'M3 9l1.5-5h15L21 9M3 9h18v11H3zM9 20v-6h6v6',
    horloge: 'M12 7v5l3 3M3 12a9 9 0 1 0 9-9 9 9 0 0 0-9 9',
    g: 'M15 18l-6-6 6-6', d: 'M9 18l6-6-6-6', plus: 'M12 5v14M5 12h14', haut: 'M18 15l-6-6-6 6', bas: 'M6 9l6 6 6-6', x: 'M18 6L6 18M6 6l12 12',
  };
  const CANAUX = { webshop: ['Webshop', '#1B6FA8'], comptoir: ['Comptoir', '#8D1D2C'], bureau: ['Bureau', '#3f7a52'] };
  const JL = [['1', 'L'], ['2', 'M'], ['3', 'M'], ['4', 'J'], ['5', 'V'], ['6', 'S'], ['7', 'D']];

  const st = { shop: null, api: '../api/cockpit', el: null, onCompte: null, date: null, vue: 'liste', canal: '', mode: '', q: '',
    D: null, err: null, lecture: false, mo: null, ed: null, minuterie: null };

  const json = r => r.json().catch(() => ({})).then(j => { if (!r.ok) { const e = new Error(j.error || ('erreur ' + r.status)); e.code = r.status; throw e; } return j; });
  const lire = (p, prm) => fetch(st.api + p + '?' + new URLSearchParams(Object.assign({ shop: st.shop }, prm || {})).toString(), { headers: { Accept: 'application/json' }, credentials: 'same-origin' }).then(json);
  const envoyer = (m, p, corps) => fetch(st.api + p, { method: m, credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
    body: JSON.stringify(Object.assign({ shop: st.shop }, corps || {})) }).then(json);

  /* ── La lecture ─────────────────────────────────────────────────────────────────────────────────────────── */
  function charger() {
    const date = st.date; st.lecture = true; rendre();
    return lire('/dashboard/commandes/jour', { date }).then(D => {
      if (date !== st.date) { return; }
      st.D = D; st.err = null; st.lecture = false;
      if (st.onCompte && D.date === AUJ()) { st.onCompte(D.totaux ? D.totaux.aPreparer : 0); }
      rendre();
    }).catch(e => { if (date !== st.date) { return; } st.err = e.message; st.lecture = false; rendre(); });
  }
  const tourneeDe = id => st.D && st.D.tournees.find(t => t.id === id);
  const cmdDe = id => st.D && st.D.commandes.find(c => c.id === id);
  const maintenant = () => st.D && st.D.maintenant;

  /* ── Petits morceaux ────────────────────────────────────────────────────────────────────────────────────── */
  const canal = k => `<span class="pco-canal"><i class="pco-pt" style="background:${CANAUX[k][1]}"></i>${CANAUX[k][0]}</span>`;
  function statut(c) {
    const now = maintenant(), passe = st.D.date < AUJ() || (now && c.heure < now);
    if (c.statut === 'fait') { return `<span class="chip pco-st-ok">${c.tournee || c.livraison ? 'Livrée' : 'Remise'}${c.fait ? ' ' + c.fait : ''}</span>`; }
    if (c.statut === 'prete') { return '<span class="chip pco-st-pret">Prête</span>'; }
    if (c.statut === 'prep') { return '<span class="chip pco-st-prep">En préparation</span>'; }
    if (c.statut === 'nonretiree') { return '<span class="tag-warn">Pas retirée</span>'; }
    return passe ? '<span class="tag-warn">Pas encore remise</span>' : '<span class="chip pco-st-neutre">À préparer</span>';
  }
  function livraison(c) {
    if (c.sansTournee) { return `<span class="tag-warn">${c.mode === 'hors' ? 'Hors de ses tournées' : 'Livraison sans tournée'}</span> <button type="button" class="linklike pco-acc" data-act="cmd" data-id="${c.id}">Placer</button>`; }
    if (!c.tournee) { return `<span class="pco-canal">${SVG(ICO.magasin)}Retrait au magasin</span>`; }
    const t = tourneeDe(c.tournee);
    return `<span class="pco-tchip" title="${esc(t ? t.nom : '')}">${SVG(ICO.camion)}<span class="n">${esc(t ? t.nom : 'Tournée')}</span><span class="a">· arrêt ${c.arret}</span></span>`;
  }
  function etatTournee(t) {
    const now = maintenant();
    if (t.etat === 'terminee') { return `<span class="chip pco-st-ok">Terminée</span>`; }
    if (t.etat === 'route') { return `<span class="chip pco-st-prep">En route</span>`; }
    if (t.etat === 'vide') { return `<span class="chip pco-st-neutre">Pas de commande</span>`; }
    const dans = now ? mn(t.depart) - mn(now) : null;
    const lib = dans != null && dans >= 0 ? 'Départ dans ' + duree(dans) : 'Départ ' + t.depart;
    return `<span class="chip ${t.etat === 'prep' ? 'pco-st-prep' : 'pco-st-neutre'}">${lib}</span>`;
  }
  const rond = c => `<span class="pco-rond${c.statut === 'fait' ? ' is-fait' : c.statut === 'prete' ? ' is-pret' : c.statut === 'prep' ? ' is-prep' : ''}">${c.arret}</span>`;
  const somme = L => L.reduce((a, c) => a + c.montant, 0);

  function bascule() {
    const D = st.D, nT = D ? D.tournees.length : 0, nC = D ? D.commandes.length : 0;
    return `<div class="pco-bascule" role="tablist" aria-label="Vue des commandes">
      <button type="button" role="tab" aria-selected="${st.vue === 'liste'}" class="${st.vue === 'liste' ? 'is-on' : ''}" data-act="vue" data-v="liste">${SVG(ICO.liste)}Liste${D ? ` <em>${nC}</em>` : ''}</button>
      <button type="button" role="tab" aria-selected="${st.vue === 'tournees'}" class="${st.vue === 'tournees' ? 'is-on' : ''}" data-act="vue" data-v="tournees">${SVG(ICO.camion)}Tournées${D ? ` <em>${nT}</em>` : ''}</button></div>`;
  }
  function barre() {
    const auj = st.date === AUJ(), now = maintenant();
    return `<div class="pco-barre"><button type="button" class="btn btn--sm btn--icon" data-act="pas" data-pas="-1" aria-label="Jour d’avant">${SVG(ICO.g)}</button>
      <span class="pco-date">${long(st.date)}</span><button type="button" class="btn btn--sm btn--icon" data-act="pas" data-pas="1" aria-label="Jour d’après">${SVG(ICO.d)}</button>
      ${auj ? `<span class="pco-ici">Aujourd’hui${now ? ' · ' + now : ''}</span>` : '<button type="button" class="btn btn--sm" data-act="auj">Aujourd’hui</button>'}
      <span class="pco-sp"></span><button type="button" class="btn btn--sm" data-act="gerer">Gérer les tournées</button>${bascule()}</div>`;
  }

  /* ── La vue Liste (A) ───────────────────────────────────────────────────────────────────────────────────── */
  function vueListe() {
    const D = st.D, T = D.totaux, tot = T.total.ca || 0;
    const kpi = (k, val, lib, note, coul) => `<div class="kpi"${coul ? ` style="border-left-color:${coul}"` : ''}><div class="kpi__value">${val}</div><div class="kpi__label">${lib}</div><div class="kpi__note">${note}</div></div>`;
    const bandeau = `<div class="card pco-kpis"><div class="kpi-row">
      ${kpi('', T.total.n, 'Commandes du jour', fE(T.total.ca))}
      ${['webshop', 'comptoir', 'bureau'].map(k => kpi(k, T[k].n, CANAUX[k][0], fE(T[k].ca), CANAUX[k][1])).join('')}
      ${D.date >= AUJ() ? kpi('', T.aPreparer, 'À préparer', T.livrer.n + ' à livrer · ' + T.retirer.n + ' à retirer') : kpi('', T.livrer.n, 'Livrées', T.retirer.n + ' retirées au magasin')}</div>
      ${tot > 0 ? `<div class="pco-part">${['webshop', 'comptoir', 'bureau'].filter(k => T[k].ca > 0).map(k => `<i style="width:${(100 * T[k].ca / tot).toFixed(1)}%;background:${CANAUX[k][1]}"></i>`).join('')}</div>
      <div class="pco-part-leg">${['webshop', 'comptoir', 'bureau'].map(k => `<span>${CANAUX[k][0]} ${Math.round(100 * T[k].ca / tot)} %</span>`).join('')}<span class="pco-sp"></span><span>part du montant des commandes</span></div>` : ''}</div>`;

    const q = st.q.trim().toLowerCase();
    const L = D.commandes.filter(c => (!st.canal || c.canal === st.canal)
      && (!st.mode || (st.mode === 'livrer' ? (c.tournee || c.sansTournee) : !(c.tournee || c.sansTournee)))
      && (!q || (c.client.nom + ' ' + c.note + ' ' + c.id).toLowerCase().includes(q)));
    const now = maintenant();
    let lignes = '', avant = true;
    for (const c of L) {
      if (now && avant && c.heure > now) { lignes += `<tr class="pco-now"><td colspan="6"><div>Maintenant · ${now}</div></td></tr>`; avant = false; }
      lignes += `<tr class="pco-ligne${c.statut === 'fait' ? ' is-passe' : ''}${c.sansTournee ? ' is-alerte' : ''}" data-act="cmd" data-id="${c.id}">
        <td>${c.heure}</td><td>${canal(c.canal)}<span class="sub">n° ${c.id}</span></td>
        <td><span class="pco-nom">${esc(c.client.nom)}</span>${c.note ? `<span class="sub pco-note" title="${esc(c.note)}">${esc(c.note)}</span>` : ''}</td>
        <td>${livraison(c)}</td><td class="num">${fE(c.montant)}<span class="sub">${esc(c.paiement.lib)}</span></td><td>${statut(c)}</td></tr>`;
    }
    if (!L.length) { lignes = `<tr><td colspan="6" class="pco-vide">${D.commandes.length ? 'Aucune commande ne correspond à ces filtres.' : 'Aucune commande au panel pour ce jour.'}</td></tr>`; }
    else { lignes += `<tr class="pco-total"><td></td><td colspan="3">${L.length} commande${L.length > 1 ? 's' : ''}</td><td class="num">${fE(somme(L))}</td><td></td></tr>`; }
    const seg = (act, v, lib, n, coul) => `<button type="button" class="${(act === 'canal' ? st.canal : st.mode) === v ? 'is-on' : ''}" data-act="${act}" data-v="${v}">${coul ? `<i class="pco-pt" style="background:${coul}"></i>` : ''}${lib}${n != null ? ` <em>${n}</em>` : ''}</button>`;
    const table = `<section class="card pco-tab"><div class="toolbar pco-outils">
        <span class="seg">${seg('canal', '', 'Toutes', D.commandes.length)}${['webshop', 'comptoir', 'bureau'].map(k => seg('canal', k, CANAUX[k][0], T[k].n, CANAUX[k][1])).join('')}</span>
        <span class="seg">${seg('mode', '', 'Tout')}${seg('mode', 'livrer', 'À livrer', T.livrer.n)}${seg('mode', 'retirer', 'À retirer', T.retirer.n)}</span>
        <input type="search" id="pco-q" placeholder="Chercher un client, un bureau, un n°" value="${esc(st.q)}"></div>
      <div class="pco-scroll"><table class="pco-table"><thead><tr><th>Heure</th><th>Canal</th><th>Client ou bureau</th><th>Livraison</th><th class="num">Montant</th><th>Statut</th></tr></thead><tbody>${lignes}</tbody></table></div></section>`;
    return bandeau + `<div class="pco-grille">${table}<aside class="pco-volet">${voletTournees()}${carteDemain()}</aside></div>`;
  }
  function voletTournees() {
    const D = st.D;
    if (!D.tournees.length) {
      return `<div class="card pco-tcarte"><div class="pco-tnom">Aucune tournée ce jour</div><p class="pco-aide">Une tournée regroupe les bureaux livrés ensemble : un départ, un livreur, des bureaux dans l’ordre de passage. Leurs commandes s’y rangent d’elles-mêmes.</p>
        <button type="button" class="btn btn--sm" data-act="t-nouvelle">${SVG(ICO.plus)}Créer une tournée</button></div>`;
    }
    return `<h2 class="pco-h2">Tournées du jour</h2>` + D.tournees.map(t => {
      const C = t.commandes.map(cmdDe).filter(Boolean), pretes = C.filter(c => c.statut === 'prete').length;
      return `<div class="card pco-tcarte"><div class="pco-tnom">${esc(t.nom)}</div>
        <div class="pco-tmeta">${etatTournee(t)}<span>${t.depart} → ${t.retour}${t.livreur ? ' · ' + esc(t.livreur) : ''}</span></div>
        <div class="pco-arrets">${C.map(rond).join('')}${Array.from({ length: t.sansCommande }, () => '<span class="pco-rond is-vide">–</span>').join('')}
          <span class="pco-compte">${t.etat === 'terminee' ? 'tout est livré' : C.length ? `${pretes} prête${pretes > 1 ? 's' : ''} sur ${C.length}` : ''}${t.sansCommande ? ` · ${t.sansCommande} sans commande` : ''}</span></div>
        <div class="pco-tpied"><span>${C.length} arrêt${C.length > 1 ? 's' : ''} · ${fE(t.total.ca)}</span><button type="button" class="linklike" data-act="vue" data-v="tournees">Voir la tournée</button></div></div>`;
    }).join('');
  }
  function carteDemain() {
    const M = st.D.demain;
    if (!M || !M.tournees.length) { return ''; }
    const tous = M.tournees.flatMap(t => t.bureaux.map(b => Object.assign({ tournee: t.nom }, b)));
    const oui = tous.filter(b => b.commande).length, non = tous.filter(b => !b.commande);
    const now = maintenant(), reste = now ? mn(M.coupure) - mn(now) : null;
    return `<div class="card pco-demain"><h2 class="pco-h2">Demain · ${court(M.date)}</h2>
      <div class="pco-demain-l">${M.ouvert ? `Commandes jusqu’à ${M.coupure}${reste != null && reste > 0 ? ' · encore ' + duree(reste) : ''}` : `Commandes fermées depuis ${M.coupure}`}.
        ${oui} bureau${oui > 1 ? 'x' : ''} sur ${tous.length} ${oui > 1 ? 'ont' : 'a'} commandé.</div>
      ${non.length ? `<div class="pco-aide">Pas encore commandé :</div><ul class="pco-dliste">${non.map(b => `<li><span>${esc(b.nom)}</span><span class="sub">${esc(b.tournee)}</span></li>`).join('')}</ul>` : ''}</div>`;
  }

  /* ── La vue Tournées (B) ────────────────────────────────────────────────────────────────────────────────── */
  function vueTournees() {
    const D = st.D;
    const retraits = D.commandes.filter(c => !c.tournee && !c.sansTournee), sans = D.commandes.filter(c => c.sansTournee);
    const heures = D.commandes.map(c => mn(c.heure)).concat(D.tournees.flatMap(t => [mn(t.depart), mn(t.retour)])).filter(x => x != null);
    const H0 = Math.min(6, ...heures.map(x => Math.floor(x / 60))), H1 = Math.max(18, ...heures.map(x => Math.ceil(x / 60)));
    const x = m => (100 * (m - H0 * 60) / ((H1 - H0) * 60)).toFixed(2) + '%';
    const pistes = {};
    D.tournees.forEach(t => { const k = t.vehicule || t.livreur || t.nom; (pistes[k] = pistes[k] || []).push(t); });
    const bloc = t => `<button type="button" class="pco-fbloc is-${t.etat}" style="left:${x(mn(t.depart))};width:calc(${x(mn(t.retour))} - ${x(mn(t.depart))})" data-act="col" data-id="${t.id}" title="${esc(t.nom)} · ${t.depart} → ${t.retour}">${esc(t.nom)}</button>`;
    const piste = (lib, inner) => `<div class="pco-fl"><div class="pco-flib">${esc(lib)}</div><div class="pco-fp">${inner}</div></div>`;
    const pt = c => `<button type="button" class="pco-fpt${c.statut === 'fait' ? ' is-passe' : ''}${c.sansTournee ? ' is-sans' : ''}" style="left:${x(mn(c.heure))};background:${CANAUX[c.canal][1]}" data-act="cmd" data-id="${c.id}" title="${c.heure} · ${esc(c.client.nom)} · ${fE(c.montant)}"></button>`;
    const now = maintenant();
    const frise = `<div class="card pco-frise"><h2 class="pco-h2">La journée<small>${String(H0).padStart(2, '0')}:00 – ${H1}:00</small></h2>
      <div class="pco-faxe">${Array.from({ length: H1 - H0 + 1 }, (_, i) => `<span style="left:${(100 * i / (H1 - H0)).toFixed(2)}%">${String(H0 + i).padStart(2, '0')}:00</span>`).join('')}</div>
      <div class="pco-fcorps">${Object.keys(pistes).map(k => piste(k, pistes[k].map(bloc).join(''))).join('')}
        ${piste('Retrait au magasin', retraits.map(pt).join(''))}${sans.length ? piste('Sans tournée', sans.map(pt).join('')) : ''}
        ${now && mn(now) >= H0 * 60 && mn(now) <= H1 * 60 ? `<div class="pco-fnow" style="left:calc(var(--pco-lib) + (100% - var(--pco-lib)) * ${((mn(now) - H0 * 60) / ((H1 - H0) * 60)).toFixed(4)})"><span>${now}</span></div>` : ''}</div>
      <div class="pco-fleg">${Object.keys(CANAUX).map(k => `<span><i class="pco-pt" style="background:${CANAUX[k][1]}"></i>${CANAUX[k][0]}</span>`).join('')}<span><i class="pco-pt pco-pt-fait"></i>Terminée</span><span><i class="pco-pt pco-pt-prep"></i>En préparation, en route</span></div></div>`;
    const alerte = sans.map(c => `<div class="pco-alerte">${SVG(ICO.camion)}<div class="txt">${c.mode === 'hors' ? 'Un bureau commande hors de ses tournées' : 'Une livraison n’est dans aucune tournée'} : n° ${c.id} · ${CANAUX[c.canal][0]} · ${c.heure} · ${esc(c.client.nom)} · ${fE(c.montant)}${c.note ? ` <span class="muted">· ${esc(c.note)}</span>` : ''}</div><button type="button" class="btn btn--sm" data-act="cmd" data-id="${c.id}">Placer</button></div>`).join('');

    const carte = c => { const t = tourneeDe(c.tournee), b = t && t.bureaux.find(x => x.client === c.client.id);
      return `<div class="pco-arret${c.statut === 'fait' ? ' is-passe' : ''}" data-act="cmd" data-id="${c.id}" role="button" tabindex="0">
        <div class="pco-a1">${rond(c)}<span class="pco-anom">${esc(c.client.nom)}</span><span class="pco-ah">${c.heure}</span></div>
        ${b && b.consigne ? `<div class="pco-alieu">${esc(b.consigne)}</div>` : ''}
        <div class="pco-a2">${canal(c.canal)}<span class="pco-am">${fE(c.montant)}</span></div>
        ${c.note ? `<div class="pco-anote">${esc(c.note)}</div>` : ''}
        <div class="pco-a3">${statut(c)}<span class="pco-compte">${esc(c.paiement.lib)}</span></div></div>`; };
    const progres = C => `<div class="pco-prog">${C.map(c => `<i class="is-${c.statut === 'fait' ? 'fait' : c.statut === 'prete' ? 'pret' : c.statut === 'prep' ? 'prep' : 'afaire'}"></i>`).join('')}</div>`;
    const colonne = t => {
      const C = t.commandes.map(cmdDe).filter(Boolean), vides = t.bureaux.filter(b => b.commande == null && !C.some(c => c.client.id === b.client));
      return `<div class="card pco-col" id="pco-col-${t.id}"><div class="pco-coltete"><div class="pco-colnom"><span>${esc(t.nom)}</span>${etatTournee(t)}</div>
        <div class="pco-colmeta">${SVG(ICO.horloge)}<span>${t.depart} → ${t.retour}${t.livreur ? ' · ' + esc(t.livreur) : ''}${t.vehicule ? ' · ' + esc(t.vehicule) : ''}</span></div>${C.length ? progres(C) : ''}</div>
        <div class="pco-colcorps">${C.map(carte).join('')}${vides.map(b => `<div class="pco-arret is-vide"><div class="pco-a1"><span class="pco-rond is-vide">–</span><span class="pco-anom">${esc(b.nom)}</span></div><div class="pco-alieu">Pas de commande ce jour</div></div>`).join('')}
          ${!C.length && !vides.length ? '<div class="pco-aide">Aucun bureau sur cette tournée.</div>' : ''}</div>
        <div class="pco-colpied"><span>${C.length} arrêt${C.length > 1 ? 's' : ''} · ${fE(t.total.ca)}</span><button type="button" class="linklike" data-act="t-modifier" data-id="${t.id}">Modifier</button></div></div>`;
    };
    const colRetrait = `<div class="card pco-col"><div class="pco-coltete"><div class="pco-colnom"><span>Retrait au magasin</span><span class="chip pco-st-neutre">${retraits.filter(c => c.statut !== 'fait').length} à venir</span></div>
      <div class="pco-colmeta">${SVG(ICO.magasin)}<span>${['comptoir', 'webshop', 'bureau'].map(k => CANAUX[k][0] + ' ' + retraits.filter(c => c.canal === k).length).join(' · ')}</span></div>${retraits.length ? progres(retraits) : ''}</div>
      <div class="pco-colcorps">${retraits.map(c => `<div class="pco-arret${c.statut === 'fait' ? ' is-passe' : ''}" data-act="cmd" data-id="${c.id}" role="button" tabindex="0">
        <div class="pco-a1">${canal(c.canal)}<span class="pco-ah">${c.heure}</span></div><div class="pco-anom pco-anom-r">${esc(c.client.nom)}</div>
        ${c.note ? `<div class="pco-anote">${esc(c.note)}</div>` : ''}<div class="pco-a2 pco-a2-r"><span class="pco-compte">${esc(c.paiement.lib)}</span><span class="pco-am">${fE(c.montant)}</span></div>
        <div class="pco-a3 pco-a2-r">${statut(c)}</div></div>`).join('') || '<div class="pco-aide">Aucun retrait au magasin ce jour.</div>'}</div>
      <div class="pco-colpied"><span>${retraits.length} commande${retraits.length > 1 ? 's' : ''} · ${fE(somme(retraits))}</span></div></div>`;
    const vide = !D.toutes.length ? `<div class="card pco-vide-t"><div class="pco-tnom">Pas encore de tournée</div><p class="pco-aide">Une tournée regroupe les bureaux livrés ensemble : un départ, un retour, un livreur, un véhicule, les jours où elle roule et ses bureaux dans l’ordre de passage. Le panel n’en a pas : elles se tiennent ici, et les commandes de leurs bureaux s’y rangent d’elles-mêmes.</p><button type="button" class="btn btn--primary" data-act="t-nouvelle">${SVG(ICO.plus)}Créer une tournée</button></div>`
      : !D.tournees.length ? `<div class="card pco-vide-t"><div class="pco-tnom">Aucune tournée ne roule le ${JOURS[dt(D.date).getDay()]}</div><p class="pco-aide">Les jours d’une tournée se règlent dans « Gérer les tournées ».</p></div>` : '';
    return frise + alerte + vide + `<div class="pco-colonnes">${D.tournees.map(colonne).join('')}${colRetrait}</div>`;
  }

  /* ── Les fenêtres : une commande, la liste des tournées, une tournée ─────────────────────────────────────── */
  function fenetre(titre, corps, pied, large) {
    return `<div class="confirm pco-fen" data-act="fermer-fond"><div class="confirm__box card${large ? ' pco-fen-l' : ''}" role="dialog" aria-modal="true" aria-label="${esc(titre)}">
      <div class="pco-fen-tete"><h3>${titre}</h3><button type="button" class="btn btn--sm btn--icon" data-act="fermer" aria-label="Fermer">${SVG(ICO.x)}</button></div>${corps}
      ${pied ? `<div class="confirm__actions">${pied}</div>` : ''}</div></div>`;
  }
  function fenCommande() {
    const c = cmdDe(st.mo.id);
    if (!c) { return ''; }
    const val = c.mode === 'manuel' ? String(c.tournee || 0) : '';
    const ligne = (k, v) => `<div class="pco-fic"><span class="k">${k}</span><span>${v}</span></div>`;
    const choix = `<label class="pco-champ"><span>Changer la livraison</span><select id="pco-aff">
      <option value=""${val === '' ? ' selected' : ''}>Automatique : la tournée de son bureau</option>
      ${st.D.tournees.map(t => `<option value="${t.id}"${val === String(t.id) ? ' selected' : ''}>${esc(t.nom)} · ${t.depart}</option>`).join('')}
      <option value="0"${val === '0' ? ' selected' : ''}>Retrait au magasin (pas de tournée)</option></select></label>`;
    const corps = `${ligne('Client', esc(c.client.nom) + (c.client.pro ? ' <span class="pco-compte">· client pro</span>' : ''))}${ligne('Canal', canal(c.canal))}
      ${ligne('Montant', fE(c.montant) + ' <span class="pco-compte">· ' + esc(c.paiement.lib) + '</span>')}${ligne('Statut', statut(c))}${c.note ? ligne('Note', esc(c.note)) : ''}
      ${ligne('Livraison', c.sansTournee ? `<span class="tag-warn">${c.mode === 'hors' ? 'Hors de ses tournées : à placer, ou à garder au magasin' : 'Livraison sans tournée'}</span>` : livraison(c) + (c.mode === 'manuel' ? ' <span class="pco-compte">· choisi au magasin</span>' : c.mode === 'auto' ? ' <span class="pco-compte">· la tournée de son bureau</span>' : ''))}
      ${st.D.tournees.length ? choix : '<p class="pco-aide">Aucune tournée ne roule ce jour : créez-en une dans « Gérer les tournées ».</p>'}
      ${st.mo.err ? `<p class="error">${esc(st.mo.err)}</p>` : ''}`;
    return fenetre(`Commande n° ${c.id} · ${c.heure}`, corps, `<button type="button" class="btn btn--ghost" data-act="fermer">Annuler</button>${st.D.tournees.length ? `<button type="button" class="btn btn--primary${st.mo.busy ? ' is-busy' : ''}" data-act="aff-ok">Enregistrer</button>` : ''}`);
  }
  function fenGerer() {
    const T = st.D.toutes;
    const jours = j => JL.map(([n, l]) => `<i class="${j.includes(n) ? 'is-on' : ''}">${l}</i>`).join('');
    const corps = T.length ? `<table class="pco-table pco-gerer"><thead><tr><th>Tournée</th><th>Horaire</th><th>Jours</th><th class="num">Bureaux</th><th></th></tr></thead><tbody>
      ${T.map(t => `<tr><td>${esc(t.nom)}<span class="sub">${esc([t.livreur, t.vehicule].filter(Boolean).join(' · '))}</span></td><td>${t.depart} → ${t.retour}</td><td><span class="pco-jours">${jours(t.jours)}</span></td>
        <td class="num">${t.bureaux.length}</td><td class="num"><button type="button" class="btn btn--sm" data-act="t-modifier" data-id="${t.id}">Modifier</button></td></tr>`).join('')}</tbody></table>`
      : '<p class="pco-aide">Pas encore de tournée pour ce magasin.</p>';
    return fenetre('Les tournées du magasin', corps, `<button type="button" class="btn btn--ghost" data-act="fermer">Fermer</button><button type="button" class="btn btn--primary" data-act="t-nouvelle">${SVG(ICO.plus)}Nouvelle tournée</button>`, true);
  }
  function fenTournee() {
    const e = st.ed;
    if (e.confirmer) {
      return fenetre('Retirer la tournée ?', `<p>« ${esc(e.nom)} » et ses ${e.bureaux.length} bureau${e.bureaux.length > 1 ? 'x' : ''} disparaissent ; les commandes de ces bureaux ne seront plus rangées dans une tournée. Les commandes elles-mêmes ne changent pas au panel.</p>${e.err ? `<p class="error">${esc(e.err)}</p>` : ''}`,
        `<button type="button" class="btn btn--ghost" data-act="t-retour">Annuler</button><button type="button" class="btn confirm__danger${e.busy ? ' is-busy' : ''}" data-act="t-suppr-ok">Retirer la tournée</button>`);
    }
    const champ = (lib, f, type, extra) => `<label class="pco-champ"><span>${lib}</span><input type="${type}" data-f="${f}" value="${esc(e[f])}"${extra || ''}></label>`;
    const corps = `<div class="pco-form">${champ('Nom', 'nom', 'text', ' maxlength="80" placeholder="Midi · Gosselies – Charleroi"')}${champ('Départ', 'depart', 'time')}${champ('Retour', 'retour', 'time')}
        ${champ('Livreur', 'livreur', 'text', ' maxlength="60"')}${champ('Véhicule', 'vehicule', 'text', ' maxlength="40" placeholder="Camionnette 1"')}
        <div class="pco-champ"><span>Jours</span><span class="pco-jsel">${JL.map(([n, l], i) => `<button type="button" class="choice-pill${e.jours.includes(n) ? ' is-on' : ''}" data-act="t-jour" data-j="${n}" title="${JOURS[(i + 1) % 7]}">${l}</button>`).join('')}</span></div></div>
      <h4 class="pco-h4">Bureaux, dans l’ordre de passage <span class="pco-compte">${e.bureaux.length}</span></h4>
      <ol class="pco-blist">${e.bureaux.map((b, i) => `<li><span class="pco-rond">${i + 1}</span><span class="pco-bnom">${esc(b.nom)}</span>
        <input type="text" data-bc="${i}" value="${esc(b.consigne)}" maxlength="200" placeholder="Consigne : étage, accueil, badge…">
        <button type="button" class="btn btn--sm btn--icon" data-act="b-haut" data-i="${i}" aria-label="Monter"${i === 0 ? ' disabled' : ''}>${SVG(ICO.haut)}</button>
        <button type="button" class="btn btn--sm btn--icon" data-act="b-bas" data-i="${i}" aria-label="Descendre"${i === e.bureaux.length - 1 ? ' disabled' : ''}>${SVG(ICO.bas)}</button>
        <button type="button" class="btn btn--sm btn--icon btn--danger" data-act="b-retirer" data-i="${i}" aria-label="Retirer">${SVG(ICO.x)}</button></li>`).join('') || '<li class="pco-aide">Aucun bureau. Cherchez un client pro du magasin ci-dessous.</li>'}</ol>
      <label class="pco-champ pco-bcherche"><span>Ajouter un bureau</span><input type="search" id="pco-bq" placeholder="Chercher un client pro : société, ville, code postal" value="${esc(e.q)}"></label>
      <div id="pco-bres">${resultats()}</div>${e.err ? `<p class="error">${esc(e.err)}</p>` : ''}`;
    return fenetre(e.id ? 'Modifier la tournée' : 'Nouvelle tournée', corps,
      `${e.id ? '<button type="button" class="btn btn--danger" data-act="t-suppr">Retirer la tournée</button><span class="pco-sp"></span>' : ''}<button type="button" class="btn btn--ghost" data-act="${st.mo && st.mo.depuisGerer ? 'gerer' : 'fermer'}">Annuler</button><button type="button" class="btn btn--primary${e.busy ? ' is-busy' : ''}" data-act="t-ok">Enregistrer</button>`, true);
  }
  function resultats() {
    const e = st.ed;
    if (e.resErr) { return `<p class="error">${esc(e.resErr)}</p>`; }
    if (e.res == null) { return e.q.trim() ? '<p class="pco-aide">Recherche…</p>' : ''; }
    const pris = new Set(e.bureaux.map(b => b.client));
    const L = e.res.filter(b => !pris.has(b.id));
    if (!L.length && e.res.length) { return '<p class="pco-aide">Les clients pro trouvés sont déjà sur la tournée.</p>'; }
    return L.length ? `<div class="pco-bres">${L.map(b => `<button type="button" data-act="b-ajouter" data-cli="${b.id}" data-nom="${esc(b.nom)}">${SVG(ICO.plus)}<span>${esc(b.nom)}</span><span class="pco-compte">${esc([b.cp, b.ville].filter(Boolean).join(' '))}</span></button>`).join('')}</div>`
      : '<p class="pco-aide">Aucun client pro ne correspond.</p>';
  }
  let minuterieRecherche = null;
  function chercherBureaux() {
    const e = st.ed, q = e.q.trim();
    clearTimeout(minuterieRecherche);
    if (!q) { e.res = null; e.resErr = null; majResultats(); return; }
    minuterieRecherche = setTimeout(() => {
      lire('/dashboard/commandes/bureaux', { q }).then(r => { if (st.ed === e && e.q.trim() === q) { e.res = r.bureaux || []; e.resErr = null; majResultats(); } })
        .catch(err => { if (st.ed === e) { e.resErr = err.message; majResultats(); } });
    }, 250);
  }
  function majResultats() { const r = st.el && st.el.querySelector('#pco-bres'); if (r) { r.innerHTML = resultats(); } }

  function ouvrirTournee(t, depuisGerer) {
    st.ed = t ? { id: t.id, nom: t.nom, depart: t.depart, retour: t.retour, livreur: t.livreur, vehicule: t.vehicule, jours: t.jours,
      bureaux: t.bureaux.map(b => ({ client: b.client, nom: b.nom, consigne: b.consigne || '' })), q: '', res: null }
      : { id: null, nom: '', depart: '11:15', retour: '12:30', livreur: '', vehicule: '', jours: '12345', bureaux: [], q: '', res: null };
    st.mo = { type: 'tournee', depuisGerer: !!depuisGerer }; rendre();
  }
  function enregistrerTournee() {
    const e = st.ed;
    e.busy = true; e.err = null; rendre();
    const champs = { nom: e.nom, depart: e.depart, retour: e.retour, livreur: e.livreur, vehicule: e.vehicule, jours: e.jours };
    (e.id ? envoyer('PUT', '/dashboard/commandes/tournees', Object.assign({ id: e.id }, champs)) : envoyer('POST', '/dashboard/commandes/tournees', champs))
      .then(r => envoyer('POST', '/dashboard/commandes/tournees/bureaux', { tournee: r.id, bureaux: e.bureaux }))
      .then(() => { st.ed = null; st.mo = null; return charger(); })
      .catch(err => { e.busy = false; e.err = err.message; rendre(); });
  }

  /* ── Le rendu et les gestes ─────────────────────────────────────────────────────────────────────────────── */
  function rendre() {
    if (!st.el) { return; }
    const garde = document.activeElement && st.el.contains(document.activeElement) && document.activeElement.id === 'pco-q' ? document.activeElement.selectionStart : null;
    let corps;
    if (st.err && !st.D) { corps = `<div class="pco-err">Les commandes ne se lisent pas : ${esc(st.err)}</div>`; }
    else if (!st.D || st.D.date !== st.date) { corps = '<div class="pco-attente">Lecture des commandes…</div>'; }
    else {
      corps = (st.D.panel ? '' : `<div class="pco-err">Les commandes du panel ne se lisent pas${st.D.motif ? ' : ' + esc(st.D.motif) : ''}. Les tournées restent visibles.</div>`)
        + (st.err ? `<div class="pco-err">${esc(st.err)}</div>` : '') + (st.vue === 'tournees' ? vueTournees() : vueListe());
    }
    const mo = !st.mo || !st.D ? '' : st.mo.type === 'cmd' ? fenCommande() : st.mo.type === 'gerer' ? fenGerer() : st.mo.type === 'tournee' && st.ed ? fenTournee() : '';
    st.el.innerHTML = barre() + corps + mo;
    if (garde != null) { const i = st.el.querySelector('#pco-q'); if (i) { i.focus(); i.setSelectionRange(garde, garde); } }
  }
  function geste(ev) {
    const a = ev.target.closest('[data-act]');
    if (!a || !st.el.contains(a)) { return; }
    const act = a.dataset.act, id = a.dataset.id ? +a.dataset.id : null;
    if (act === 'fermer-fond') { if (ev.target === a) { st.mo = null; st.ed = null; rendre(); } return; }
    ev.preventDefault();
    if (act === 'fermer') { st.mo = null; st.ed = null; rendre(); }
    else if (act === 'pas') { st.date = plus(st.date, +a.dataset.pas); st.D = null; charger(); }
    else if (act === 'auj') { st.date = AUJ(); st.D = null; charger(); }
    else if (act === 'vue') { st.vue = a.dataset.v; try { localStorage.setItem('pco-vue:' + st.shop, st.vue); } catch (e) { /* sans mémoire */ } rendre(); if (a.closest('.pco-tcarte')) { window.scrollTo(0, 0); } }
    else if (act === 'canal') { st.canal = a.dataset.v; rendre(); }
    else if (act === 'mode') { st.mode = a.dataset.v; rendre(); }
    else if (act === 'col') { const c = st.el.querySelector('#pco-col-' + id); if (c) { c.scrollIntoView({ behavior: 'smooth', block: 'start' }); } }
    else if (act === 'cmd') { st.mo = { type: 'cmd', id }; rendre(); }
    else if (act === 'aff-ok') {
      const c = cmdDe(st.mo.id), v = st.el.querySelector('#pco-aff').value;
      st.mo.busy = true; rendre();
      envoyer('POST', '/dashboard/commandes/affecter', { commande: c.id, date: st.D.date, tournee: v === '' ? null : +v })
        .then(() => { st.mo = null; return charger(); }).catch(err => { if (st.mo) { st.mo.busy = false; st.mo.err = err.message; } rendre(); });
    }
    else if (act === 'gerer') { st.ed = null; st.mo = { type: 'gerer' }; rendre(); }
    else if (act === 't-nouvelle') { ouvrirTournee(null, st.mo && st.mo.type === 'gerer'); }
    else if (act === 't-modifier') { const t = st.D.toutes.find(x => x.id === id); if (t) { ouvrirTournee(t, st.mo && st.mo.type === 'gerer'); } }
    else if (act === 't-jour') { const j = a.dataset.j, e = st.ed; e.jours = e.jours.includes(j) ? e.jours.replace(j, '') : (e.jours + j).split('').sort().join(''); rendre(); }
    else if (act === 'b-haut' || act === 'b-bas') { const i = +a.dataset.i, j = act === 'b-haut' ? i - 1 : i + 1, B = st.ed.bureaux; if (j >= 0 && j < B.length) { [B[i], B[j]] = [B[j], B[i]]; rendre(); } }
    else if (act === 'b-retirer') { st.ed.bureaux.splice(+a.dataset.i, 1); rendre(); }
    else if (act === 'b-ajouter') { st.ed.bureaux.push({ client: +a.dataset.cli, nom: a.dataset.nom, consigne: '' }); rendre(); const q = st.el.querySelector('#pco-bq'); if (q) { q.focus(); } }
    else if (act === 't-ok') { enregistrerTournee(); }
    else if (act === 't-suppr') { st.ed.confirmer = true; st.ed.err = null; rendre(); }
    else if (act === 't-retour') { st.ed.confirmer = false; rendre(); }
    else if (act === 't-suppr-ok') {
      const e = st.ed; e.busy = true; rendre();
      envoyer('DELETE', '/dashboard/commandes/tournees', { id: e.id }).then(() => { st.ed = null; st.mo = null; return charger(); })
        .catch(err => { e.busy = false; e.err = err.message; rendre(); });
    }
  }
  function saisie(ev) {
    const t = ev.target;
    if (t.id === 'pco-q') { st.q = t.value; rendre(); return; }
    if (!st.ed) { return; }
    if (t.dataset.f) { st.ed[t.dataset.f] = t.value; return; }
    if (t.dataset.bc != null) { const b = st.ed.bureaux[+t.dataset.bc]; if (b) { b.consigne = t.value; } return; }
    if (t.id === 'pco-bq') { st.ed.q = t.value; st.ed.res = null; st.ed.resErr = null; chercherBureaux(); majResultats(); }
  }
  function clavier(ev) {
    if ((ev.key === 'Enter' || ev.key === ' ') && ev.target.matches && ev.target.matches('.pco-arret[data-act]')) { geste(ev); }
  }
  // Échap ferme la fenêtre ouverte, où que soit le focus (écouté une fois, sur le document).
  document.addEventListener('keydown', ev => {
    if (ev.key === 'Escape' && st.mo && st.el && document.body.contains(st.el)) { st.mo = null; st.ed = null; rendre(); }
  });

  window.DashCommandes = {
    racine(shop, o) {
      o = o || {};
      if (!st.el || st.shop !== String(shop)) {
        Object.assign(st, { shop: String(shop), D: null, err: null, mo: null, ed: null, canal: '', mode: '', q: '', date: /^\d{4}-\d{2}-\d{2}$/.test(o.date || '') ? o.date : AUJ() });
        try { st.vue = localStorage.getItem('pco-vue:' + st.shop) === 'tournees' ? 'tournees' : 'liste'; } catch (e) { st.vue = 'liste'; }
        st.el = document.createElement('div'); st.el.className = 'pco';
        st.el.addEventListener('click', geste); st.el.addEventListener('input', saisie); st.el.addEventListener('keydown', clavier);
        st.api = o.apiBase || st.api; st.onCompte = o.onCompte || null;
        rendre(); charger();
        // La journée se relit toute seule toutes les deux minutes, tant que la page est ouverte sur aujourd'hui.
        clearInterval(st.minuterie);
        st.minuterie = setInterval(() => { if (st.el && document.body.contains(st.el) && !document.hidden && !st.mo && st.date === AUJ() && !st.lecture) { charger(); } }, 120000);
      } else { st.onCompte = o.onCompte || st.onCompte; rendre(); }
      return st.el;
    },
    relire() { if (st.el) { charger(); } },
  };
})();
