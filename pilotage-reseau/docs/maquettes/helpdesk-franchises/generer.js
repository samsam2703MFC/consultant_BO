/* Maquettes « Helpdesk des franchisés » (demande du 15/11/2026 : « dans le cockpit pilotage, il faut pouvoir lire les
 * demandes et les reprendre dans les missions de visite (contrôle) + faire le formulaire dans le dashboard franchisé »).
 *
 *   node docs/maquettes/helpdesk-franchises/generer.js    → a.html (cockpit : les demandes), b.html (cockpit : la visite
 *                                                          et ses missions), c.html (dashboard franchisé : le formulaire)
 *   node docs/maquettes/helpdesk-franchises/capturer.js   → a.png, b.png, c.png
 *
 * Données : donnees.json, lu le 15/11/2026 en lecture seule — les 24 demandes du helpdesk du panel (GET /cases, compte
 * administrateur du cockpit), les six domaines (/consultant/tasks/helpdesk), les visites et leurs missions du cockpit
 * (/consultants/gestion). Les courriels des demandeurs ne sont pas repris. */
const fs = require('fs');
const path = require('path');
const D = __dirname;
const CHROME = fs.readFileSync(path.join(D, 'chrome.html'), 'utf8');
const DATA = JSON.parse(fs.readFileSync(path.join(D, 'donnees.json'), 'utf8'));
const esc = s => String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
const AUJ = '2026-11-15';
const jours = d => Math.round((new Date(AUJ) - new Date(String(d).slice(0, 10))) / 864e5);
const dm = d => d ? String(d).slice(8, 10) + '/' + String(d).slice(5, 7) + (String(d).slice(0, 4) !== '2026' ? '/' + String(d).slice(2, 4) : '') : '';
const court = n => String(n || '').replace(/^Atelier by( - |\s+)/, '').replace(/^.* - /, '');
const DOM = { strategy: 'Stratégie', product: 'Produit', operational_activities: 'Activités opérationnelles', sales: 'Ventes', app_development: 'Application', social_media: 'Réseaux sociaux' };
const ST = { NEW: ['Nouvelle', 'ko'], IN_PROGRESS: ['En cours', 'att'], RESOLVED: ['Résolue', 'ok'], CLOSED: ['Fermée', 'mu'], CANCELLED: ['Annulée', 'mu'] };
const C = DATA.cas.slice().sort((a, b) => b.created_at.localeCompare(a.created_at));

function page(contenu, titre, actif){
  let h = CHROME;
  h = h.replace('<link rel="stylesheet" href="../../../public/assets/css/controles-photo.css">', '<link rel="stylesheet" href="../../../public/assets/css/verification.css">\n<link rel="stylesheet" href="hd.css">');
  h = h.replace(/<title>[^<]*<\/title>/, '<title>' + esc(titre) + ' — Cockpit CEO (maquette)</title>');
  h = h.replace('class="sidebar__item is-active" aria-current="page"', 'class="sidebar__item"');
  const ico = 'M4 5h16v11H8l-4 4zM8 9h8M8 12h5';
  h = h.replace(/(<div class="sidebar__group-label">Franchisés<\/div>)/i, `$1<button class="sidebar__item${actif === 'demandes' ? ' is-active" aria-current="page' : ''}"><svg class="ico" viewBox="0 0 24 24"><path d="${ico}"></path></svg><span class="sidebar__item-label">Demandes des franchisés</span><span class="hd-badge">11</span></button>`);
  h = h.replace(/<h1>Résultat<\/h1>/, '<h1>' + esc(titre) + '</h1>');
  h = h.replace(/<div class="topbar__actions">/, '<div class="topbar__actions"><span class="hd-pill">Maquette · demandes lues au panel le 15/11 · rien n’est écrit</span>');
  h = h.replace(/<p class="shell-sub page-intro">[\s\S]*?<\/p>/, '');
  h = h.replace('class="app__body"', 'class="app__body app__body--wide"');
  return h.replace(/<div data-screen="resultat"[\s\S]*?<div id="mq-contenu"><\/div><\/div>/, `<div class="hd">${contenu}</div>`);
}

/* ── A. Les demandes des franchisés ─────────────────────────────────────────── */
const ouvertes = C.filter(c => ['NEW', 'IN_PROGRESS'].includes(c.status));
const sansRep = ouvertes.filter(c => !c.admin_reply);
const plusVieille = ouvertes.reduce((m, c) => Math.max(m, jours(c.created_at)), 0);
const kpi = (k, v, s, ton) => `<div class="vm-kpi ${ton}"><div class="k">${esc(k)}</div><div class="v">${v}</div><div class="s">${esc(s)}</div></div>`;
const SEL = 5;
const ligne = c => {
  const [st, ton] = ST[c.status];
  const age = jours(c.created_at);
  return `<tr class="${c.id === SEL ? 'hd-on' : ''}"><td class="hd-lis ${ton}"></td>
    <td class="g"><span class="hd-ref">${esc(c.ref.replace('CASE-', ''))}</span><small>${dm(c.created_at)}</small></td>
    <td class="g">${esc(court(c.shop_name))}</td>
    <td class="g"><span class="hd-dom">${esc(DOM[c.area_key])}</span></td>
    <td class="g hd-titre"><b>${esc(c.title.replace(/^ombreffe/, 'Sombreffe'))}</b><small>${esc(String(c.description || '').slice(0, 110))}${String(c.description || '').length > 110 ? '…' : ''}</small></td>
    <td class="g">${esc(c.consultant_name || '—')}</td>
    <td><span class="hd-st ${ton}">${st}</span>${c.admin_reply ? '<small>répondue</small>' : (['NEW', 'IN_PROGRESS'].includes(c.status) ? '<small class="ko">sans réponse</small>' : '')}</td>
    <td class="${age > 60 && ['NEW', 'IN_PROGRESS'].includes(c.status) ? 'hd-vieux' : ''}">${age} j</td>
    <td class="g">${c.id === 5 ? '<span class="hd-vis">visite 26/10</span>' : (c.id === 15 ? '<span class="hd-vis">à reprendre</span>' : '')}</td></tr>`;
};
const S5 = C.find(c => c.id === SEL);
const fiche = `<aside class="vr-card hd-fiche"><div class="hd-ft"><div><span class="hd-ref">${esc(S5.ref)}</span><h3>${esc(S5.title.replace(/^ombreffe/, 'Sombreffe'))}</h3>
    <small>${esc(court(S5.shop_name))} · ${esc(DOM[S5.area_key])} · créée le ${dm(S5.created_at)} (${jours(S5.created_at)} j)</small></div><span class="hd-st ${ST[S5.status][1]}">${ST[S5.status][0]}</span></div>
  <div class="hd-desc">${esc(S5.description)}</div>
  <div class="hd-grille"><label>Statut<select class="pp-in"><option>Nouvelle</option><option selected>En cours</option><option>Résolue</option><option>Fermée</option><option>Annulée</option></select></label>
    <label>Consultant<select class="pp-in"><option selected>Loic V.</option><option>Sam V.</option><option>Szymon M.</option></select><small>ceux du domaine « Activités opérationnelles »</small></label></div>
  <div class="hd-bloc"><h4>Reprendre dans une visite <span>— la demande devient une mission de la visite, à contrôler sur place</span></h4>
    <label>Visite<select class="pp-in"><option selected>Régulière · Sombreffe · 26/10 · Loic V. (en cours)</option><option>Planifier une visite de Sombreffe…</option></select></label>
    <div class="hd-seg"><span>Quand</span><button>avant</button><button class="on">pendant</button><button>après</button></div>
    <label>Ce qu’il faut vérifier sur place<textarea class="pp-in" rows="3">Verrière : les deux variantes chiffrées (bardage / verrière haute), le devis et le plan d’implantation de Didier. Réagencement : le croquis et la liste du mobilier de Stefano, posés en boutique. Affichage : les gabarits A4 / A3 / vitrine de Mato en place, l’ardoise réservée au daily.</textarea></label>
    <div class="hd-act"><button class="rc-btn">Ajouter à la visite</button><span class="vr-mu">la demande passe « En cours » ; le constat de la visite repart en réponse au franchisé</span></div></div>
  <div class="hd-bloc"><h4>Répondre au franchisé <span>— PATCH /cases/{id}/reply : il la lit dans son dashboard</span></h4>
    <textarea class="pp-in" rows="3" placeholder="La réponse du bureau…">Bonjour Harmonie, les trois chantiers sont repris dans la visite de Loïc : il passe avec le devis de la verrière, le plan de réagencement et les gabarits d’affichage.</textarea>
    <div class="hd-act"><label class="hd-ck"><input type="checkbox" checked> passer à « En cours »</label><span class="vr-sp"></span><button class="rc-btn gris">Annuler</button><button class="rc-btn ok">Envoyer la réponse</button></div></div>
  <div class="hd-hist"><b>Historique</b><div>19/05 · créée par le magasin</div><div>19/05 · attribuée à Loic V.</div><div>— · aucune réponse envoyée</div></div></aside>`;
const A = `<div class="vr-card"><div class="vm-kpis">${kpi('Ouvertes', ouvertes.length, 'nouvelles ou en cours', 'att')}${kpi('Sans réponse', sansRep.length, 'aucune réponse du bureau', 'ko')}${kpi('Plus ancienne', plusVieille + ' j', 'ouverte depuis le ' + dm(ouvertes.slice().sort((a, b) => a.created_at.localeCompare(b.created_at))[0].created_at), 'ko')}${kpi('Résolues', C.filter(c => c.status === 'RESOLVED').length, 'sur ' + C.length + ' demandes', 'ok')}${kpi('Dans une visite', 1, 'reprises en mission', '')}</div>
  <div class="vt-fil"><div class="vr-chips hd-chips">${[['Ouvertes', ouvertes.length, true], ['Nouvelles', C.filter(c => c.status === 'NEW').length], ['En cours', C.filter(c => c.status === 'IN_PROGRESS').length], ['Sans réponse', sansRep.length], ['Résolues', 3], ['Toutes', C.length]].map(([n, v, on]) => `<button class="${on ? 'on' : ''}">${n} <b>${v}</b></button>`).join('')}</div>
    <select class="vr-sel"><option>Tous les domaines</option>${Object.values(DOM).map(d => `<option>${d}</option>`).join('')}</select><select class="vr-sel"><option>Tous les magasins</option><option>Halle (13)</option><option>Sombreffe (6)</option><option>Corbais (3)</option><option>Gosselies (2)</option></select><input class="vm-q" placeholder="Chercher une demande…"></div>
  <div class="hd-cols"><div class="vr-tw"><table class="vr-t hd-t"><thead><tr><th></th><th class="g">Réf.</th><th class="g">Magasin</th><th class="g">Domaine</th><th class="g">Demande</th><th class="g">Consultant</th><th>Statut</th><th>Âge</th><th class="g">Visite</th></tr></thead>
  <tbody>${C.filter(c => ['NEW', 'IN_PROGRESS'].includes(c.status)).map(ligne).join('')}</tbody></table></div>${fiche}</div>
  <div class="vr-pied"><b>Les demandes des franchisés</b> : le helpdesk du panel (GET /cases, compte administrateur du cockpit), magasin par magasin. Une demande se lit, s’attribue, reçoit une réponse (le franchisé la lit dans son dashboard) et, quand elle demande un passage, devient une <b>mission de la visite</b> du magasin : elle s’ajoute aux tâches de la visite (avant, pendant, après) et à sa liste de contrôle ; le constat fait sur place repart en réponse, et la demande passe « Résolue ».</div></div>`;

/* ── B. La visite et ses missions ───────────────────────────────────────────── */
const T = DATA.tachesSombreffe;
const tache = (t, extra) => `<div class="hd-tache${extra ? ' hd-new' : ''}"><span class="hd-cb${t.statut === 'fait' ? ' on' : ''}"></span><div><b>${esc(t.titre)}</b><small>${esc(extra ? extra : (t.source === 'type' ? 'tâche du type « Régulière »' : t.source))}${t.echeance ? ' · échéance ' + dm(t.echeance) : ''}</small></div></div>`;
const parQ = q => T.filter(t => t.quand === q);
const mission = `<div class="hd-mission"><div class="hd-mt"><span class="hd-src">Demande du franchisé</span><b>CASE-20260519-8B90A · Sombreffe : verrière cuisine, réagencement « étalement » &amp; cadrage affichage</b><span class="hd-st att">En cours</span></div>
  <div class="hd-pts">${[['Verrière cuisine-magasin', 'les deux variantes chiffrées (bardage / verrière haute), devis et plan d’implantation', 'att'], ['Réagencement « étalement »', 'croquis et liste du mobilier à déplacer ou ajouter, posés en boutique', ''], ['Affichage en charte', 'gabarits A4 / A3 / vitrine en place ; ardoise réservée au daily et au saisonnier', '']].map(([n, d, e]) =>
    `<div class="hd-pt"><div><b>${n}</b><small>${d}</small></div><div class="hd-ok3"><button class="${e === 'ok' ? 'on ok' : ''}">Conforme</button><button class="${e === 'att' ? 'on att' : ''}">À suivre</button><button>Non conforme</button></div><input class="pp-in" placeholder="Constat…" value="${e === 'att' ? 'Devis reçu pour la variante bardage seulement ; la verrière haute reste à chiffrer.' : ''}"><span class="hd-photo">＋ photo</span></div>`).join('')}</div>
  <div class="hd-act"><label class="hd-ck"><input type="checkbox" checked> à la clôture, envoyer le constat en réponse au franchisé</label><span class="vr-sp"></span><span class="vr-mu">Résolue si tout est conforme ; sinon « En cours », avec un plan d’action</span></div></div>`;
const B = `<div class="hd-visite vr-card"><div class="hd-vt"><div><h2>Visite régulière — Sombreffe</h2><small>dimanche 26/10/2026 · Loic V. · en cours · 4 missions avant, 1 pendant, 2 après</small></div><span class="vr-sp"></span><button class="rc-btn gris">Ouvrir la liste de contrôle</button><button class="rc-btn ok">Clôturer la visite</button></div>
  <div class="hd-3col">
    <div><h4>Avant la visite</h4>${parQ('avant').map(t => tache(t)).join('')}</div>
    <div><h4>Pendant la visite</h4>${parQ('pendant').map(t => tache(t)).join('')}${tache({ titre: 'Contrôler la demande du franchisé « verrière, réagencement, affichage »', echeance: '2026-10-26' }, 'mission du helpdesk · CASE-20260519-8B90A · ajoutée le 15/11')}</div>
    <div><h4>Après la visite</h4>${parQ('apres').map(t => tache(t)).join('')}${tache({ titre: 'Répondre au franchisé sur sa demande (constat de la visite)', echeance: '2026-10-27' }, 'mission du helpdesk · repart en réponse au panel')}</div>
  </div>
  <div class="hd-sec"><h4>La liste de contrôle de la visite <span>— modules du type « Régulière », et les missions reprises du helpdesk</span></h4>
    <div class="hd-mods">${['Accueil &amp; vitrine', 'Hygiène', 'Production', 'Affichage &amp; prix', 'Stock &amp; DLC'].map((m, i) => `<div class="hd-mod"><b>${m}</b><small>${[6, 8, 5, 4, 5][i]} points · ${[6, 7, 3, 2, 0][i]} faits</small></div>`).join('')}<div class="hd-mod on"><b>Demandes du franchisé</b><small>1 demande · 3 points</small></div></div>
    ${mission}</div>
  <div class="vr-pied">Une demande reprise dans une visite y entre de deux façons : une <b>tâche</b> du consultant (pendant : contrôler ; après : répondre), et un <b>module « Demandes du franchisé »</b> de la liste de contrôle, avec ses points (conforme, à suivre, non conforme, constat, photo). À la clôture, le constat repart en réponse au panel (PATCH /cases/{id}/reply) et le statut suit (PATCH /cases/{id}/status).</div></div>`;

/* ── C. Le formulaire dans le dashboard franchisé (téléphone) ───────────────── */
const SOM = C.filter(c => c.shop_name.indexOf('Sombreffe') >= 0);
const carte = c => {
  const [st, ton] = ST[c.status];
  const rep = c.admin_reply ? `<div class="rep ok"><b>Le bureau · ${dm(c.admin_reply_at)} :</b> ${esc(c.admin_reply)}</div>`
    : (c.id === 5 ? '<div class="rep att">Reprise dans la visite de Loic V. du 26/10 — réponse après la visite</div>' : `<div class="rep ${ton === 'ko' ? 'att' : 'sans'}">${ton === 'ko' ? 'Envoyée il y a ' + jours(c.created_at) + ' jours — pas encore lue par le bureau' : 'En cours chez ' + esc(c.consultant_name || 'le bureau')}</div>`);
  return `<div class="rc-carte"><div class="l1"><span class="rc-st ${ton === 'ko' ? 'att' : (ton === 'att' ? 'sans' : 'ok')}">${st}</span><span class="d">${dm(c.created_at)} · ${esc(c.ref.replace('CASE-', ''))}</span></div>
    <b class="ref">${esc(c.title.replace(/^ombreffe/, 'Sombreffe'))}</b><div class="l2">${esc(DOM[c.area_key])}</div>${rep}</div>`;
};
const ICO = { ops: '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>', exp: '<svg viewBox="0 0 24 24"><path d="M5 20V11M11 20V5M17 20v-8M2 20h20"/></svg>', ctrl: '<svg viewBox="0 0 24 24"><path d="M3 8h4l2-3h6l2 3h4v11H3z"/><circle cx="12" cy="13" r="3.6"/></svg>', tach: '<svg viewBox="0 0 24 24"><path d="M9 6h11M9 12h11M9 18h11"/></svg>', sem: '<svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18"/></svg>', prim: '<svg viewBox="0 0 24 24"><circle cx="12" cy="9" r="6"/></svg>' };
const onglets = `<div class="mb-tabs mb-tabs5 mb-tabs6 ma-tabs">${[['ops', 'Opérationnel'], ['exp', 'Exploitation'], ['ctrl', 'Contrôle'], ['tach', 'Tâches'], ['sem', 'Semaine'], ['prim', 'Primes']].map(([k, l]) => `<button class="${k === 'ctrl' ? 'on' : ''}"><i>${ICO[k]}</i>${l}</button>`).join('')}</div>`;
const tel = (contenu, sous) => `<div class="hd-tel"><div id="dash" class="mob"><div class="mb-hd"><img src="../../../public/assets/img/logo.png" alt=""><div><div class="t">Atelier by Harmonie - Sombreffe</div><div class="d">${sous}</div></div><span class="sp"></span><button class="mb-ic">↻</button></div>
  <div class="mb-sc"><div class="rc-vue">${contenu}</div></div>${onglets}</div></div>`;
const form = `<div class="rc-form"><div class="rc-champ"><label>Le sujet</label><div class="rc-puces">${Object.entries(DOM).map(([k, n]) => `<button class="${k === 'product' ? 'on' : ''}">${n}</button>`).join('')}</div></div>
  <div class="rc-champ"><label for="t">Le titre</label><input id="t" class="rc-in" value="Tarte aux pommes : ajouter une version 22 Ø"></div>
  <div class="rc-champ"><label>La demande</label><textarea class="rc-in" rows="4">Les clients demandent souvent une tarte aux pommes plus petite pour 4 personnes. Est-ce qu’on peut l’ajouter à la gamme, avec la recette et le prix conseillé ?</textarea></div>
  <div class="rc-champ"><label>Photos ou documents <em>facultatif</em></label><div class="rc-photos"><div class="ph att">📄</div><button class="plus"><b>＋</b>Ajouter</button></div></div>
  <div class="rc-champ"><label>Un passage du consultant ?</label><div class="rc-puces"><button>Oui, à la prochaine visite</button><button class="on">Non, une réponse suffit</button></div></div>
  <div class="rc-envoi"><button class="rc-prise hd-envoi"><span>Envoyer au bureau</span></button><div class="hd-mu">Votre demande arrive au bureau (helpdesk du panel) ; la réponse s’affiche ici.</div></div></div>`;
const hist = `<div class="rc-hist"><div class="rc-lab">Mes demandes</div><div class="rc-resume"><b>${SOM.filter(c => ['NEW', 'IN_PROGRESS'].includes(c.status)).length}</b> ouvertes · ${SOM.filter(c => ['NEW', 'IN_PROGRESS'].includes(c.status) && !c.admin_reply && jours(c.created_at) > 30).length} sans réponse depuis plus de 30 jours</div>
  <div class="rc-filtres"><button class="on">Toutes<b>${SOM.length}</b></button><button>Ouvertes<b>${SOM.filter(c => ['NEW', 'IN_PROGRESS'].includes(c.status)).length}</b></button><button>Répondues<b>${SOM.filter(c => c.admin_reply).length}</b></button></div>${SOM.map(carte).join('')}</div>`;
const entree = `<div class="hd-ctrl"><div class="rc-lab">Contrôle</div><button class="ma-rc">✎ Réclamer un produit au fournisseur</button><button class="ma-rc hd-rc">✉ Une demande au bureau <span class="hd-b">${SOM.filter(c => ['NEW', 'IN_PROGRESS'].includes(c.status)).length} ouvertes</span></button></div>`;
const C1 = tel(entree + '<div class="hd-mu" style="margin:14px 2px">… le reste de l’onglet Contrôle (photos, scores) inchangé …</div>', 'Contrôle');
const C2 = tel(form, 'Une demande au bureau');
const C3 = tel(hist, 'Mes demandes');
const CC = `<div class="hd-tels">${[[C1, '1 · Contrôle : le bouton, à côté de la réclamation fournisseur'], [C2, '2 · Le formulaire : sujet, titre, demande, pièces, passage'], [C3, '3 · Mes demandes : statut et réponse du bureau']].map(([t, l]) => `<figure>${t}<figcaption>${l}</figcaption></figure>`).join('')}</div>
  <div class="hd-note">Dashboard franchisé (téléphone), onglet Contrôle. L’envoi crée la demande au panel (POST /cases : sujet = domaine, titre, description, magasin), les pièces jointes par POST /cases/{id}/attachments. « Un passage du consultant ? » range la demande à reprendre dans la prochaine visite. Les demandes du magasin se relisent par GET /shops/{magasin}/cases (compte consultant). Ici, les vraies demandes de Sombreffe ; la nouvelle (tarte 22 Ø) est un exemple.</div>`;
const pageTel = `<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><title>Dashboard franchisé — une demande au bureau (maquette)</title>
<link rel="stylesheet" href="../../../public/assets/ds/global.css"><link rel="stylesheet" href="../../../public/assets/css/controles-photo.css"><link rel="stylesheet" href="../../../public/dashboard/dashboard.css"><link rel="stylesheet" href="hd.css"></head>
<body class="hd-fond"><div class="hd-ttl"><span class="hd-pill">Maquette · demandes de Sombreffe lues au panel le 15/11 · rien n’est écrit</span><h1>Dashboard franchisé — « Une demande au bureau »</h1></div>${CC}</body></html>`;

fs.writeFileSync(path.join(D, 'a.html'), page(A, 'Demandes des franchisés', 'demandes'));
fs.writeFileSync(path.join(D, 'b.html'), page(B, 'Visite — Sombreffe', 'visite'));
fs.writeFileSync(path.join(D, 'c.html'), pageTel);
console.log('a, b, c —', C.length, 'demandes,', ouvertes.length, 'ouvertes');
