/* Dashboard magasin › Paramètres › Mails et automatisations (16/11/2026, maquette v3.1 validée : docs/maquettes/parametres-mails/).
 *
 * Ce que le magasin envoie tout seul. Première automatisation en service : le RAPPEL LA VEILLE DU RETRAIT — chaque
 * commande client du panel à retirer demain, son client reçoit à l'heure choisie un e-mail (boîte du magasin) et/ou un
 * SMS (API SMS du cockpit). À venir, en gris : le dossier comptable du mois ; « Commande prête » (via la tablette pour
 * le moment). Les réponses automatiques de la boîte (accusé, relance d'offre) restent dans Boîte mail.
 *   Routes : …/dashboard/boite (l'état), …/dashboard/boite/automatisations, …/automatisations/essai
 *   (src/franchise/automatisations.php) — réglables boîte ouverte sur l'appareil, comme la boîte.
 *
 * `window.DashAuto.racine(shop, {apiBase, aller})` rend l'élément du module, gardé d'un rendu à l'autre.
 */
(function () {
  'use strict';
  const esc = v => String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  const eur = v => new Intl.NumberFormat('fr-BE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(v || 0).replace(/[  ]/g, ' ') + ' €';
  const hm = s => s ? String(s).slice(11, 16) : '';
  const jm = s => s ? String(s).slice(8, 10) + '/' + String(s).slice(5, 7) : '';
  const CANAUX = [['email', 'E-mail'], ['sms', 'SMS'], ['les2', 'Les deux']];
  const CHAMPS = ['canal', 'heure', 'recent', 'objet', 'corps', 'sms'];

  const st = { shop: null, api: '../api/cockpit', el: null, aller: null, etat: null, d: null, err: null, form: null, occupe: false, flash: null, tel: '', eq: { nom: '', adresse: '' } };

  /* --- lectures et écritures ------------------------------------------------------------------------------------ */
  const url = (p, prm) => st.api + p + '?' + new URLSearchParams(Object.assign({ shop: st.shop }, prm || {})).toString();
  function repondre(r) {
    return r.json().catch(() => ({})).then(j => {
      if (r.ok) { return j; }
      if ((r.status === 401 || r.status === 409) && j && j.etat && st.etat) { st.etat.ouverte = false; if (j.etat === 'non_reliee') { st.etat.reliee = false; } }
      return Promise.reject(new Error((j && j.error) || ('HTTP ' + r.status)));
    });
  }
  const lire = p => fetch(url(p), { headers: { Accept: 'application/json' }, credentials: 'same-origin' }).then(repondre);
  const ecrire = (p, corps) => fetch(st.api + p, { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
    body: JSON.stringify(Object.assign({ shop: st.shop }, corps)) }).then(repondre);
  function flash(ok, texte) { st.flash = { ok, texte }; rendre(); }

  function chargerEtat() {
    return lire('/dashboard/boite').then(e => { st.etat = e; st.err = null; if (e.ouverte) { return charger(); } })
      .catch(e => { st.err = e.message; }).finally(rendre);
  }
  function charger() {
    // « Modifié » se juge AVANT de prendre le nouvel état (26/11/2026 : comparé au nouvel état, un réglage changé ailleurs —
    // la fenêtre des Paramètres — gardait l'ancien formulaire et affichait « Modifié · à enregistrer »).
    return lire('/dashboard/boite/automatisations').then(d => { const touche = !!st.form && modifie(); st.d = d; st.err = null; if (!touche) { st.form = Object.assign({}, d.rappel.reglage); } })
      .catch(e => { st.err = e.message; }).finally(rendre);
  }
  const R = () => (st.d && st.d.rappel) || null;
  function modifie() { const g = R() && R().reglage; return !!(g && st.form && CHAMPS.some(k => String(st.form[k]) !== String(g[k]))); }
  function enregistrer(plus) {
    st.occupe = true; rendre();
    const corps = Object.assign({ auto: 'rappel_retrait' }, plus || {});
    if (!plus) { CHAMPS.forEach(k => { corps[k] = st.form[k]; }); }
    return ecrire('/dashboard/boite/automatisations', corps).then(d => {
      st.d = d;
      st.form = plus ? Object.assign({}, st.form, { actif: d.rappel.reglage.actif }) : Object.assign({}, d.rappel.reglage);
      flash(true, d.note || (plus ? (d.rappel.reglage.actif ? 'Rappel allumé : il part chaque jour à ' + d.rappel.reglage.heure + '.' : 'Rappel éteint.') : 'Enregistré.'));
    }).catch(e => flash(false, e.message)).finally(() => { st.occupe = false; rendre(); });
  }
  function essai() {
    st.occupe = true; rendre();
    const corps = { auto: 'rappel_retrait', tel: st.tel };
    CHAMPS.forEach(k => { corps[k] = st.form[k]; });
    ecrire('/dashboard/boite/automatisations/essai', corps).then(r => {
      const t = [r.email && 'E-mail : ' + r.email, r.sms && 'SMS : ' + r.sms].filter(Boolean).join(' · ');
      flash(r.ok, t || (st.form.canal !== 'email' ? 'Donnez un numéro pour l’essai du SMS.' : 'Rien n’est parti.'));
    }).catch(e => flash(false, e.message)).finally(() => { st.occupe = false; rendre(); });
  }

  /* L'équipe (17/11/2026) : les personnes du magasin qui reçoivent la fiche d'une promotion lancée (Promotions › bundles). */
  function equipe(action, plus) {
    st.occupe = true; rendre();
    return ecrire('/dashboard/boite/equipe', Object.assign({ action }, plus)).then(d => {
      st.d = Object.assign({}, st.d, { equipe: d.equipe });
      if (action === 'ajouter') { st.eq = { nom: '', adresse: '' }; }
      flash(true, action === 'ajouter' ? 'Ajouté à l’équipe.' : 'Retiré de l’équipe.');
    }).catch(e => flash(false, e.message)).finally(() => { st.occupe = false; rendre(); });
  }

  /* --- les messages, comme le serveur les remplit ---------------------------------------------------------------- */
  function remplir(t, v) {
    let s = String(t || '').replace(/\{(prenom|nom|magasin|jour|heure|total|paiement|commande)\}/g, (m, k) => v[k] == null ? '' : v[k]);
    s = s.replace(/[ \t]{2,}/g, ' ').replace(/ +([,.])/g, '$1');
    return s.trim();
  }
  function exemple() {
    const d = R() && R().demain, c = d && d.commandes && d.commandes[0];
    const v = { prenom: 'Camille', nom: 'Exemple', magasin: (st.d && st.d.magasin) || 'L’Atelier By', jour: (d && d.lib) || 'demain', heure: '08:30', total: eur(68.4), paiement: 'Reste à payer au retrait : ' + eur(68.4) + '.', commande: '2041' };
    if (c) { Object.assign(v, { prenom: c.client.split(' ')[0] || v.prenom, heure: c.heure, total: eur(c.total), paiement: c.paye ? 'Elle est déjà réglée.' : 'Reste à payer au retrait : ' + eur(c.total) + '.', commande: String(c.id) }); }
    return v;
  }
  const nbSms = n => n <= 160 ? 1 : Math.ceil(n / 153);

  /* --- ce qui se voit ------------------------------------------------------------------------------------------- */
  function porte(titre, texte) {
    return `<div class="au-porte db-card"><b>${esc(titre)}</b><p>${esc(texte)}</p><button type="button" class="db-btn prim" data-au="boite">Aller à la boîte mail</button></div>`;
  }
  const bascule = (on, attr) => `<label class="bx-bascule"><input type="checkbox" ${attr}${on ? ' checked' : ''}${st.occupe ? ' disabled' : ''}><i></i></label>`;
  const chip = (t, cl) => `<span class="au-chip ${cl || ''}">${esc(t)}</span>`;
  const canauxChips = c => c === 'les2' ? chip('e-mail', 'm') + chip('SMS', 's') : c === 'sms' ? chip('SMS', 's') : chip('e-mail', 'm');

  function tuiles() {
    const r = R(), g = r.reglage, C = r.demain.commandes || [];
    const prevus = C.filter(c => c.etat === 'prevu' || c.etat === 'envoye').length, sans = C.filter(c => c.etat === 'injoignable').length;
    const sms = st.d.sms === 'smsapi' ? 'SMSAPI' : st.d.sms === 'twilio' ? 'Twilio' : null;
    const t = (k, v, s, cl) => `<div class="au-tui${cl ? ' ' + cl : ''}"><span class="k">${esc(k)}</span><b>${v}</b><span class="s">${s}</span></div>`;
    return `<div class="au-tuiles">
      ${t('Rappel la veille', g.actif ? 'Allumé' : 'Éteint', g.actif ? 'chaque jour à ' + esc(g.heure) : 'à allumer quand il est prêt', g.actif ? 'on' : '')}
      ${t('Demain, ' + r.demain.lib, r.demain.lu ? C.length + ' commande' + (C.length > 1 ? 's' : '') : '—', r.demain.lu ? prevus + ' rappel' + (prevus > 1 ? 's' : '') + (sans ? ' · ' + sans + ' sans e-mail ni téléphone' : '') : 'le panel ne répond pas')}
      ${t('Par où', esc(CANAUX.find(x => x[0] === g.canal)[1]), 'SMS : ' + (sms ? esc(sms) : '<span class="au-ko">aucun fournisseur réglé</span>'))}
      ${t('L’horloge', 'toutes les 10 min', st.d.horloge ? 'dernier passage ' + esc(jm(st.d.horloge.replace('T', ' ')) + ' ' + hm(st.d.horloge.replace('T', ' '))) : 'pas encore de passage')}
    </div>`;
  }
  function liste() {
    const g = R().reglage;
    const ligne = (nom, sous, qui, quand, canal, etat, cl) => `<tr class="${cl || ''}"><td><b>${esc(nom)}</b><small>${esc(sous)}</small></td><td>${esc(qui)}</td><td>${esc(quand)}</td><td>${canal}</td><td>${etat}</td></tr>`;
    return `<div class="db-card au-liste"><div class="ct"><span class="db-lab">Les automatisations du magasin</span><span class="db-mini">depuis ${esc(st.d.boite)}</span></div>
      <table class="au-t"><thead><tr><th>Envoi</th><th>À qui</th><th>Quand</th><th>Canal</th><th>État</th></tr></thead><tbody>
      ${ligne('Rappel la veille du retrait', 'le jour, l’heure, le montant et ce qui reste à payer', 'le client de chaque commande', 'la veille · ' + g.heure, canauxChips(g.canal), bascule(g.actif, 'data-au-actif="1"') + `<span class="au-etat">${g.actif ? 'allumé' : 'éteint'}</span>`, 'sel')}
      ${ligne('Fiche d’une promotion', 'le bundle, les dates, la caisse, comment le proposer (PDF)', 'l’équipe du magasin', 'au lancement d’un bundle (Promotions)', chip('e-mail', 'm') + chip('PDF', 'p'), `<span class="au-etat">${(st.d.equipe || []).length ? (st.d.equipe || []).length + ' personne' + ((st.d.equipe || []).length > 1 ? 's' : '') : 'aucune adresse'}</span>`)}
      ${ligne('Dossier comptable du mois', 'les revenus du mois et les factures du mois en PDF', 'le comptable', 'dès que les factures du mois sont faites', chip('e-mail', 'm') + chip('PDF', 'p'), chip('à venir', 'g'), 'gris')}
      ${ligne('Commande prête', 'un SMS dès que la commande est marquée prête', 'le client de la commande', 'via la tablette pour le moment', chip('SMS', 's'), chip('à venir', 'g'), 'gris')}
      <tr><td><b>Accusé de réception, relance d’une offre</b><small>les réponses automatiques de la boîte</small></td><td>qui écrit au magasin</td><td colspan="2">réglés dans la boîte mail</td><td><button type="button" class="bx-lien" data-au="auto-boite">Messages automatiques ▸</button></td></tr>
      </tbody></table></div>`;
  }
  function reglage() {
    const r = R(), f = st.form, [h0, h1] = r.heures;
    const heures = []; for (let m = +h0.slice(0, 2) * 60; m <= +h1.slice(0, 2) * 60; m += 30) { heures.push(String(Math.floor(m / 60)).padStart(2, '0') + ':' + String(m % 60).padStart(2, '0')); }
    if (!heures.includes(f.heure)) { heures.push(f.heure); heures.sort(); }
    const n = remplir(f.sms, exemple()).length;
    const mail = f.canal !== 'sms', sms = f.canal !== 'email';
    return `<div class="db-card"><div class="ct"><span class="db-lab">Quand</span></div><div class="au-f">
        <div class="au-row"><span>La veille du retrait, à</span><select class="bx-in" data-au-f="heure">${heures.map(x => `<option${x === f.heure ? ' selected' : ''}>${x}</option>`).join('')}</select></div>
        <div class="au-row">${bascule(f.recent, 'data-au-f="recent"')}<span>Pas de rappel pour une commande passée moins de 24 h avant le retrait (la confirmation suffit)</span></div>
        <p class="bx-mu">Seules les commandes ni remises ni annulées. Chaque rappel part une seule fois, et se lit dans « Ce qui est parti ».</p></div></div>
      <div class="db-card"><div class="ct"><span class="db-lab">Par où</span></div><div class="au-f">
        <div class="au-seg">${CANAUX.map(([k, l]) => `<button type="button" class="${f.canal === k ? 'on' : ''}" data-au-canal="${k}">${l}</button>`).join('')}</div>
        <p class="bx-mu">${f.canal === 'les2' ? 'Un client sans e-mail reçoit le SMS seul, et l’inverse.' : f.canal === 'email' ? 'Un client sans e-mail reçoit le SMS à la place.' : 'Un client sans téléphone reçoit l’e-mail à la place.'} E-mail depuis ${esc(st.d.boite)} (les réponses y arrivent) ; SMS par l’API SMS du cockpit.</p></div></div>
      <div class="db-card${mail ? '' : ' au-off'}"><div class="ct"><span class="db-lab">L’e-mail</span></div><div class="au-f">
        <label class="au-lab">Objet<input class="bx-in" data-au-f="objet" value="${esc(f.objet)}" maxlength="200"></label>
        <label class="au-lab">Texte<textarea class="bx-in" data-au-f="corps" rows="8" maxlength="5000">${esc(f.corps)}</textarea></label>
        <div class="au-vars">${Object.entries(r.variables).map(([k, t]) => `<span class="au-var" title="${esc(t)}">${esc(k)}</span>`).join('')}</div></div></div>
      <div class="db-card${sms ? '' : ' au-off'}"><div class="ct"><span class="db-lab">Le SMS</span><span class="db-mini${n > r.smsMax ? ' au-ko' : ''}">${n} signes · ${nbSms(n)} SMS</span></div><div class="au-f">
        <textarea class="bx-in" data-au-f="sms" rows="3" maxlength="${r.smsMax}">${esc(f.sms)}</textarea></div></div>
      <div class="au-actions">${sms ? `<input class="bx-in au-tel" data-au-tel="1" type="tel" placeholder="Votre GSM pour l’essai du SMS" value="${esc(st.tel)}">` : ''}
        <button type="button" class="db-btn" data-au="essai"${st.occupe ? ' disabled' : ''}>Envoyer un essai</button>
        <button type="button" class="db-btn prim" data-au="enregistrer"${st.occupe || !modifie() ? ' disabled' : ''}>Enregistrer</button></div>
      ${modifie() ? '<p class="au-modif">Modifié · à enregistrer</p>' : ''}`;
  }
  function apercu() {
    const f = st.form, v = exemple(), r = R();
    const mail = f.canal !== 'sms', sms = f.canal !== 'email';
    const etat = c => ({ prevu: '<span class="au-ok">prévu</span>', envoye: '<span class="au-ok">✓ parti</span>', erreur: '<span class="au-ko">échec</span>', injoignable: '<span class="au-ko">ni e-mail ni téléphone</span>', recente: '<span class="mu">trop récente</span>' })[c.etat] || esc(c.etat);
    const C = r.demain.commandes || [], J = r.journal || [];
    return `<div class="db-card"><div class="ct"><span class="db-lab">Aperçu</span><span class="db-mini">${C.length ? 'avec la première commande de demain' : 'avec une commande d’exemple'}</span></div>
        ${mail ? `<div class="au-mail"><div class="h">Objet <b>${esc(remplir(f.objet, v))}</b></div><div class="c">${esc(remplir(f.corps, v)).replace(/\n/g, '<br>')}</div></div>` : ''}
        ${sms ? `<div class="au-sms"><div class="h">SMS</div><div class="b">${esc(remplir(f.sms, v))}</div></div>` : ''}</div>
      <div class="db-card"><div class="ct"><span class="db-lab">Demain, ${esc(r.demain.lib)}</span><span class="db-mini">les commandes à retirer · rappel à ${esc(r.reglage.heure)}</span></div>
        ${!r.demain.lu ? '<p class="au-vide au-ko">Les commandes ne se lisent pas au panel pour l’instant.</p>' : C.length ? `<div class="au-defile"><table class="au-t"><thead><tr><th>Retrait</th><th>Client</th><th class="n">Commande</th><th>Rappel</th><th>État</th></tr></thead><tbody>${C.map(c => `<tr>
          <td>${esc(c.heure)}</td><td>${esc(c.client || '—')}<small>n° ${c.id}${c.webshop ? ' · webshop' : ''}</small></td><td class="n">${eur(c.total)}<small>${c.paye ? 'réglée' : 'à payer au retrait'}</small></td>
          <td>${(c.canaux.email ? chip('e-mail', 'm') : '') + (c.canaux.sms ? chip('SMS', 's') : '') || '<span class="mu">—</span>'}</td><td>${etat(c)}</td></tr>`).join('')}</tbody></table></div>` : '<p class="au-vide">Aucune commande à retirer demain.</p>'}</div>
      <div class="db-card"><div class="ct"><span class="db-lab">Ce qui est parti</span><span class="db-mini">les 30 derniers rappels</span></div>
        ${J.length ? `<div class="au-defile"><table class="au-t"><tbody>${J.map(x => `<tr><td>${esc(jm(x.le))} ${esc(hm(x.le))}</td><td>${x.canal === 'sms' ? chip('SMS', 's') : chip('e-mail', 'm')}</td><td>commande n° ${x.commande}</td><td>${x.erreur ? `<span class="au-ko" title="${esc(x.erreur)}">échec</span>` : '<span class="au-ok">parti</span>'}</td></tr>`).join('')}</tbody></table></div>` : '<p class="au-vide">Rien n’est encore parti.</p>'}</div>`;
  }
  function carteEquipe() {
    const E = st.d.equipe || [];
    return `<div class="db-sec">L’équipe</div><div class="db-card au-eq"><div class="ct"><span class="db-lab">Qui reçoit les fiches des promotions</span><span class="db-mini">${E.length} personne${E.length > 1 ? 's' : ''} · depuis ${esc(st.d.boite)}</span></div>
      <p class="bx-mu" style="margin:10px 16px">Quand un bundle du Coach Vente est lancé (Promotions), sa fiche PDF part à ces adresses : le bundle, les dates, la caisse et les phrases pour le proposer. Ni marge ni coefficient.</p>
      ${E.length ? `<table class="au-t"><tbody>${E.map(x => `<tr><td><b>${esc(x.nom)}</b></td><td>${esc(x.adresse)}</td><td style="text-align:right"><button type="button" class="bx-lien" data-au-eqx="${x.id}"${st.occupe ? ' disabled' : ''}>Retirer</button></td></tr>`).join('')}</tbody></table>` : '<p class="au-vide">Personne pour l’instant.</p>'}
      <div class="au-eq-f"><input class="bx-in" data-au-eq="nom" placeholder="Prénom et initiale (Lina K.)" maxlength="80" value="${esc(st.eq.nom)}">
        <input class="bx-in" data-au-eq="adresse" type="email" placeholder="adresse e-mail" maxlength="190" value="${esc(st.eq.adresse)}">
        <button type="button" class="db-btn prim" data-au="eq-ajouter"${st.occupe || !st.eq.nom.trim() || !st.eq.adresse.trim() ? ' disabled' : ''}>Ajouter</button></div></div>`;
  }
  function html() {
    if (st.err && !st.etat) { return `<div class="db-err">${esc(st.err)}</div>`; }
    if (!st.etat) { return '<div class="db-sk" style="height:320px"></div>'; }
    if (!st.etat.reliee) { return porte('La boîte mail du magasin n’est pas encore reliée.', 'Les e-mails automatiques partent de la boîte du magasin : reliez-la d’abord (Boîte mail), puis revenez ici.'); }
    if (!st.etat.ouverte) { return porte('Ouvrez la boîte mail sur cet appareil.', 'Les envois automatiques se règlent boîte ouverte, avec son mot de passe : personne d’autre ne peut changer ce qui part aux clients.'); }
    if (st.err && !st.d) { return `<div class="db-err">${esc(st.err)}</div>`; }
    if (!st.d || !st.form) { return '<div class="db-sk" style="height:320px"></div>'; }
    const fl = st.flash ? `<div class="au-flash ${st.flash.ok ? 'ok' : 'ko'}">${esc(st.flash.texte)}</div>` : '';
    return `<div class="au">
      <p class="au-intro">Ce que le magasin envoie tout seul à ses clients, et bientôt à son comptable : depuis sa boîte mail et par SMS, à l’heure dite.</p>
      ${fl}${tuiles()}${liste()}
      ${carteEquipe()}
      <div class="db-sec">Rappel la veille du retrait</div>
      <div class="au-g2"><div class="au-col">${reglage()}</div><div class="au-col">${apercu()}</div></div></div>`;
  }
  function brancher() {
    const on = (sel, ev, fn) => st.el.querySelectorAll(sel).forEach(x => x.addEventListener(ev, fn));
    on('[data-au="boite"]', 'click', () => st.aller && st.aller('boite'));
    on('[data-au="auto-boite"]', 'click', () => st.aller && st.aller('boite'));
    on('[data-au-actif]', 'change', e => enregistrer({ actif: e.currentTarget.checked }));
    on('[data-au-canal]', 'click', e => { st.form.canal = e.currentTarget.dataset.auCanal; rendre(); });
    on('[data-au-f]', 'input', e => {
      const k = e.currentTarget.dataset.auF, x = e.currentTarget;
      st.form[k] = x.type === 'checkbox' ? x.checked : x.value;
      if (x.type === 'checkbox' || x.tagName === 'SELECT') { rendre(); } else { majVivante(); }
    });
    on('[data-au-f="heure"]', 'change', e => { st.form.heure = e.currentTarget.value; rendre(); });
    on('[data-au-tel]', 'input', e => { st.tel = e.currentTarget.value; });
    on('[data-au="enregistrer"]', 'click', () => enregistrer());
    on('[data-au="essai"]', 'click', essai);
    on('[data-au-eq]', 'input', e => { st.eq[e.currentTarget.dataset.auEq] = e.currentTarget.value; const b = st.el.querySelector('[data-au="eq-ajouter"]'); if (b) { b.disabled = st.occupe || !st.eq.nom.trim() || !st.eq.adresse.trim(); } });
    on('[data-au="eq-ajouter"]', 'click', () => equipe('ajouter', { nom: st.eq.nom, adresse: st.eq.adresse }));
    on('[data-au-eqx]', 'click', e => equipe('retirer', { id: +e.currentTarget.dataset.auEqx }));
  }
  /* En tapant : l'aperçu, le compteur et le bouton suivent sans redessiner le champ (le curseur reste où il est). */
  let tVivant = null;
  function majVivante() { clearTimeout(tVivant); tVivant = setTimeout(rendre, 250); }
  function rendre() {
    if (!st.el) { return; }
    const a = document.activeElement, dans = a && st.el.contains(a) ? (a.dataset.auF || (a.dataset.auTel ? 'tel' : null)) : null;
    const pos = dans && a.selectionStart != null ? [a.selectionStart, a.selectionEnd] : null;
    st.el.innerHTML = html();
    brancher();
    if (dans) {
      const b = st.el.querySelector(dans === 'tel' ? '[data-au-tel]' : '[data-au-f="' + dans + '"]');
      if (b) { b.focus(); if (pos && b.setSelectionRange) { try { b.setSelectionRange(pos[0], pos[1]); } catch (e) { /* sans curseur */ } } }
    }
  }

  window.DashAuto = {
    racine(shop, o) {
      o = o || {};
      if (o.apiBase) { st.api = o.apiBase; }
      if (o.aller) { st.aller = o.aller; }
      if (!st.el || String(st.shop) !== String(shop)) {
        st.shop = String(shop); st.el = document.createElement('div'); st.el.className = 'au-racine';
        Object.assign(st, { etat: null, d: null, err: null, form: null, flash: null });
        chargerEtat();
      }
      return st.el;
    },
    relire() { if (st.el) { chargerEtat(); } },
  };
})();
