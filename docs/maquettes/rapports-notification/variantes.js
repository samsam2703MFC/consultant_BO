/* Les quatre variantes, greffées sur les écrans réels pendant la capture : le compositeur et la
 * liste de l'écran Reporting du cockpit, l'onglet Opérationnel du dashboard magasin.
 *
 * Ce qui est nouveau porte un liseré doré pointillé (`.rn-neuf`) : le reste est l'écran du jour.
 * Le contenu de la notification de Halle (les sept points) est écrit pour la maquette, dans la
 * forme des blocs du rapport franchisé (passage clients, ticket moyen, tâches sous le seuil,
 * stocks négatifs, rentabilité par jour). */

const LEV = {
  trafic: ['Trafic', '#6366f1'], xp: ['Expérience client', '#f59e0b'], 'food-cost': ['Food cost', '#10b981'],
  'overhead-cost': ['Overhead', '#64748b'],
};
const RAPPORT = {
  nom: 'Rapport franchisé — la semaine', recu: 'lundi 5 octobre à 07:00', recuC: 'lun. 05/10 · 07:00',
  periode: 'semaine du 28/09 au 04/10', resume: '7 points à traiter',
  points: [
    { lev: 'trafic', bloc: 'Passage clients', txt: '104 clients par jour, −7 % face à la même semaine de 2025' },
    { lev: 'xp', bloc: 'Ticket moyen', txt: '9,52 € de ticket moyen, sous le réseau (10,14 €)' },
    { lev: 'xp', bloc: 'Tâches sous le seuil', txt: 'Comptoir · Tartes — 2/5 le mercredi 30/09', det: 'vitrine incomplète à l’ouverture' },
    { lev: 'xp', bloc: 'Tâches sous le seuil', txt: 'Vérifier si le magasin est propre — non rendue le vendredi 02/10' },
    { lev: 'xp', bloc: 'Tâches sous le seuil', txt: 'Comptoir · Quiches — 3/5 le samedi 03/10', det: 'étiquettes de prix manquantes', fait: 'fait lun. 05/10 à 14:30' },
    { lev: 'food-cost', bloc: 'Stocks négatifs', txt: '2 références sous zéro : Farine T65 (−12 kg), Beurre de tourage (−4 kg)', fait: 'fait mar. 06/10 à 06:12' },
    { lev: 'overhead-cost', bloc: 'Rentabilité par jour', txt: '1 jour à résultat net négatif : lundi 28/09, −86 €' },
  ],
};
const parLevier = pts => Object.keys(LEV).map(k => [k, pts.filter(p => p.lev === k)]).filter(([, l]) => l.length);
const pastilles = pts => parLevier(pts).map(([k, l]) => `<span class="rn-lv"><i style="background:${LEV[k][1]}"></i>${LEV[k][0]} <b>${l.length}</b></span>`).join('');

const ICO = {
  doc: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5M9 13h6M9 17h4"/></svg>',
  cloche: '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 16V11a6 6 0 1 1 12 0v5l1.5 2h-15z"/><path d="M10 20a2 2 0 0 0 4 0"/></svg>',
  mail: '✉', notif: '🔔',
};

/* ------------------------------------------------------------------------------------------- */
/* Le dashboard magasin                                                                          */
/* ------------------------------------------------------------------------------------------- */
const CSS_DASH = `
.rn-neuf{outline:2px dashed #d9a520;outline-offset:4px}
.rn-lv{display:inline-flex;align-items:center;gap:5px;font:600 10.5px var(--font-ui);border:.5px solid var(--color-border-tertiary);border-radius:999px;padding:3px 9px;color:#444;background:var(--color-surface);white-space:nowrap}
.rn-lv i{width:8px;height:8px;border-radius:2px;display:inline-block}
.rn-lv b{font-weight:700;color:var(--color-text)}
.rn-ic{width:36px;height:36px;border-radius:10px;background:var(--color-primary);color:#fff;display:grid;place-items:center;flex:none}
.rn-new{display:inline-block;font:700 9.5px var(--font-ui);letter-spacing:.06em;text-transform:uppercase;background:var(--color-primary);color:#fff;border-radius:999px;padding:2px 8px}
/* A — le bandeau */
.rn-band{display:flex;align-items:center;gap:14px;background:#fdf4f5;border:.5px solid #ebcdd2;border-left:4px solid var(--color-primary);border-radius:12px;padding:11px 16px;margin-bottom:12px}
.rn-band .k{font:600 10px var(--font-ui);letter-spacing:.07em;text-transform:uppercase;color:var(--color-primary)}
.rn-band .t{margin-top:3px;font-size:12.5px;color:#555}
.rn-band .t b{font:400 17px var(--font-display);color:var(--color-text);margin-right:6px}
.rn-band .lvs{display:flex;gap:5px;flex-wrap:wrap;margin-left:auto}
.rn-band .db-btn{white-space:nowrap}
/* B — la boîte des rapports reçus */
.rn-boite .ct .rn-f{font:600 11px var(--font-ui);border-radius:999px;padding:4px 11px;border:.5px solid var(--color-border-secondary);color:var(--color-text-muted)}
.rn-boite .ct .rn-f.on{background:var(--color-text);border-color:var(--color-text);color:#fff}
.rn-boite .ct .rn-f b{margin-left:4px}
.rn-l{display:grid;grid-template-columns:96px 132px minmax(0,1.1fr) minmax(0,1.3fr) 90px auto;gap:14px;align-items:center;padding:11px 16px;border-bottom:.5px solid var(--color-border-tertiary)}
.rn-l:last-child{border-bottom:none}
.rn-l.nouveau{background:#fdf4f5}
.rn-l .q{font-size:11.5px;color:#555}.rn-l .q b{display:block;font-size:12px;color:var(--color-text)}
.rn-l .n b{font:400 15px var(--font-display);display:block}.rn-l .n small{font-size:11px;color:var(--color-text-muted)}
.rn-l .lvs{display:flex;gap:5px;flex-wrap:wrap}
.rn-l .pt{font:400 18px var(--font-display);text-align:right}.rn-l .pt small{display:block;font:500 10px var(--font-ui);color:var(--color-text-muted)}
.rn-l .lu{font-size:11px;color:var(--color-text-muted)}
/* C — la liste à traiter */
.rn-todo .ct .chip{display:inline-flex;align-items:center;font:600 10.5px var(--font-ui);padding:3px 10px;border-radius:999px;background:var(--color-background-secondary);color:#444}
.rn-prog{display:inline-flex;align-items:center;gap:9px;margin-left:auto;font-size:12px;color:#555}
.rn-prog b{font:400 18px var(--font-display);color:var(--color-text)}
.rn-prog .bar{width:120px;height:8px;border-radius:5px;background:var(--color-background-secondary);overflow:hidden;position:relative}
.rn-prog .bar i{position:absolute;left:0;top:0;bottom:0;background:#2d7a3e;border-radius:5px}
.rn-cols{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:0 22px;padding:8px 16px 12px}
.rn-g{padding:6px 0}
.rn-g .h{display:flex;align-items:center;gap:7px;font:600 10px var(--font-ui);letter-spacing:.07em;text-transform:uppercase;color:var(--color-text-muted);margin:4px 0 4px}
.rn-g .h i{width:9px;height:9px;border-radius:2px}
.rn-pt{display:flex;align-items:flex-start;gap:10px;padding:7px 8px;border-radius:9px;border:.5px solid var(--color-border-tertiary);margin-bottom:5px;background:var(--color-surface)}
.rn-pt .cb{width:18px;height:18px;border-radius:5px;border:1.5px solid #b9afa3;flex:none;margin-top:1px;display:grid;place-items:center;font:700 11px var(--font-ui);color:#fff}
.rn-pt.fait{background:#f3f8f4;border-color:#d4e8d9}
.rn-pt.fait .cb{background:#2d7a3e;border-color:#2d7a3e}
.rn-pt.fait .tx{color:#6b7a6e;text-decoration:line-through;text-decoration-color:#9fbfa7}
.rn-pt .tx{flex:1;font-size:12.5px;line-height:1.35}
.rn-pt .tx small{display:block;font-size:10.5px;color:var(--color-text-muted);text-decoration:none}
.rn-pt .st{font-size:10.5px;white-space:nowrap;color:var(--color-text-muted);padding-top:2px}
.rn-pt.fait .st{color:#2d7a3e;font-weight:600}
.rn-pt .bt{font:600 10.5px var(--font-ui);border:.5px solid var(--color-border-secondary);border-radius:7px;padding:3px 9px;background:var(--color-surface);white-space:nowrap}
/* D — la cloche et son tiroir */
.rn-cloche{position:relative;display:inline-flex;align-items:center;gap:6px}
.rn-cloche .bd{position:absolute;top:-6px;right:-7px;min-width:18px;height:18px;border-radius:9px;background:#C0182B;color:#fff;font:700 10.5px/18px var(--font-ui);text-align:center;padding:0 5px;box-shadow:0 0 0 2px var(--color-bg,#EAE4DC)}
.rn-voile{position:fixed;inset:0;background:rgba(29,29,27,.28);z-index:50}
.rn-tiroir{position:fixed;top:0;right:0;bottom:0;width:440px;background:var(--color-bg,#EAE4DC);z-index:51;box-shadow:-12px 0 30px rgba(0,0,0,.18);display:flex;flex-direction:column}
.rn-tiroir .hd{display:flex;align-items:center;gap:10px;padding:16px 18px 10px}
.rn-tiroir .hd .t{font:400 22px var(--font-display)}
.rn-tiroir .hd .x{margin-left:auto;border:none;background:none;font-size:18px;color:#777}
.rn-tiroir .ongl{display:flex;gap:6px;padding:0 18px 10px}
.rn-tiroir .ongl span{font:600 11px var(--font-ui);border-radius:999px;padding:4px 11px;border:.5px solid var(--color-border-secondary);color:var(--color-text-muted);background:var(--color-surface)}
.rn-tiroir .ongl span.on{background:var(--color-text);border-color:var(--color-text);color:#fff}
.rn-tiroir .lst{overflow:auto;padding:0 18px 18px;display:flex;flex-direction:column;gap:9px}
.rn-m{background:var(--color-surface);border:.5px solid var(--color-border-tertiary);border-radius:12px;padding:12px 14px}
.rn-m.nouveau{border-left:4px solid var(--color-primary)}
.rn-m.att{border-left:4px solid #D97706}
.rn-m.lu{opacity:.78}
.rn-m .k{display:flex;align-items:center;gap:8px;font:600 10px var(--font-ui);letter-spacing:.06em;text-transform:uppercase;color:var(--color-text-muted)}
.rn-m .k .src{margin-left:auto;text-transform:none;letter-spacing:0;font-weight:500}
.rn-m .t{font:400 16px var(--font-display);margin:5px 0 3px}
.rn-m .s{font-size:12px;color:#555}
.rn-m .lvs{display:flex;gap:5px;flex-wrap:wrap;margin-top:8px}
.rn-m .pts{margin:9px 0 0;padding:0;list-style:none;border-top:.5px solid var(--color-border-tertiary)}
.rn-m .pts li{display:flex;gap:8px;align-items:flex-start;font-size:11.5px;padding:5px 0;border-bottom:.5px dashed var(--color-border-tertiary)}
.rn-m .pts li i{width:8px;height:8px;border-radius:2px;margin-top:4px;flex:none}
.rn-m .ac{display:flex;gap:7px;margin-top:10px}
.rn-tiroir .pied{margin-top:auto;padding:10px 18px 14px;font-size:10.5px;color:var(--color-text-muted)}
`;

const dashboard = {
  /* A — un bandeau en tête de l'onglet, entre la frise des jours et l'objectif. */
  A: () => {
    const h = `<div class="rn-band rn-neuf">
      <span class="rn-ic">${ICO.doc}</span>
      <div><div class="k">Nouveau rapport · reçu ${RAPPORT.recu}</div>
        <div class="t"><b>${RAPPORT.nom}</b>${RAPPORT.resume} — ${RAPPORT.periode}</div></div>
      <div class="lvs">${pastilles(RAPPORT.points)}</div>
      <button class="db-btn prim">Ouvrir le rapport ›</button><button class="db-btn">Lu ✓</button>
    </div>`;
    document.querySelector('.db-frise').insertAdjacentHTML('afterend', h);
    return '.rn-band';
  },
  /* B — une carte « Rapports reçus » dans le fil, après le duel chiffre par chiffre. */
  B: (R) => {
    const L = [
      { cls: 'nouveau', etat: '<span class="rn-new">Nouveau</span>', q: '<b>lun. 05/10</b>07:00', nom: R.nom, per: R.periode, lvs: pastilles(R.points), n: 7, lu: '' },
      { cls: '', etat: '<span class="lu">lu ven. 02/10<br>à 11:12</span>', q: '<b>ven. 02/10</b>09:00', nom: 'Tâches sous le seuil — la veille', per: 'jeudi 01/10', lvs: pastilles([{ lev: 'xp' }]), n: 1 },
      { cls: '', etat: '<span class="lu">lu lun. 28/09<br>à 08:40</span>', q: '<b>lun. 28/09</b>07:00', nom: R.nom, per: 'semaine du 21/09 au 27/09', lvs: pastilles([{ lev: 'trafic' }, { lev: 'xp' }, { lev: 'xp' }, { lev: 'food-cost' }, { lev: 'food-cost' }]), n: 5 },
    ];
    const h = `<div class="db-card op-bloc rn-boite rn-neuf"><div class="ct"><span class="op-h2">Rapports reçus</span>
        <span class="rn-f on">Nouveaux <b>1</b></span><span class="rn-f">Tous <b>3</b></span>
        <span class="db-mini">envoyés par le cockpit au magasin · les 30 derniers jours · un clic ouvre le PDF</span></div>
      ${L.map(x => `<div class="rn-l ${x.cls}"><div>${x.etat}</div><div class="q">${x.q}</div>
        <div class="n"><b>${x.nom}</b><small>${x.per}</small></div><div class="lvs">${x.lvs}</div>
        <div class="pt">${x.n}<small>point${x.n > 1 ? 's' : ''} à traiter</small></div>
        <button class="db-btn${x.cls ? ' prim' : ''}">Ouvrir ›</button></div>`).join('')}
    </div>`;
    const duel = [...document.querySelectorAll('.db-card')].find(c => /Le duel, chiffre par chiffre/.test(c.textContent));
    duel.insertAdjacentHTML('afterend', h);
    return '.rn-boite';
  },
  /* C — le rapport devient une liste à cocher, juste sous les tuiles du moment. */
  C: (R) => {
    const faits = R.points.filter(p => p.fait).length;
    const groupe = ([k, l]) => `<div class="rn-g"><div class="h"><i style="background:${LEV[k][1]}"></i>${LEV[k][0]}</div>
      ${l.map(p => `<div class="rn-pt${p.fait ? ' fait' : ''}"><span class="cb">${p.fait ? '✓' : ''}</span>
        <span class="tx">${p.txt}<small>${p.bloc}${p.det ? ' · ' + p.det : ''}</small></span>
        ${p.fait ? `<span class="st">${p.fait}</span>` : '<span class="bt">Fait</span>'}</div>`).join('')}</div>`;
    const G = parLevier(R.points);
    // deux colonnes équilibrées : l'expérience client (3 points de tâches) d'un côté
    const gauche = G.filter(([k]) => k === 'xp'), droite = G.filter(([k]) => k !== 'xp');
    const h = `<div class="db-card op-bloc rn-todo rn-neuf"><div class="ct"><span class="op-h2">À traiter cette semaine</span>
        <span class="chip">${R.nom} · ${R.periode} · reçu ${R.recuC}</span>
        <span class="rn-prog"><b>${faits}</b> / ${R.points.length} traités <span class="bar"><i style="width:${(100 * faits / R.points.length).toFixed(0)}%"></i></span></span>
        <a class="db-lien" href="#">rapport complet (PDF) ›</a></div>
      <div class="rn-cols"><div>${droite.map(groupe).join('')}</div><div>${gauche.map(groupe).join('')}</div></div>
    </div>`;
    document.querySelector('.op-mnt').insertAdjacentHTML('afterend', h);
    return '.rn-todo';
  },
  /* D — une cloche dans la barre du haut ; le tiroir ouvert sur la droite. */
  D: (R) => {
    const relire = [...document.querySelectorAll('.db-nav .db-btn')].find(b => /Relire/.test(b.textContent));
    relire.insertAdjacentHTML('beforebegin', `<button class="db-btn rn-cloche rn-neuf">${ICO.cloche}Messages<span class="bd">2</span></button>`);
    const h = `<div class="rn-voile"></div><aside class="rn-tiroir">
      <div class="hd"><span class="t">Messages du magasin</span><button class="x">✕</button></div>
      <div class="ongl"><span class="on">Tous 3</span><span>Rapports 2</span><span>Panel 1</span><span>Non lus 2</span></div>
      <div class="lst">
        <div class="rn-m nouveau"><div class="k"><span class="rn-new">Nouveau</span>Rapport · ${R.recuC}<span class="src">cockpit</span></div>
          <div class="t">${R.nom}</div><div class="s">${R.resume} — ${R.periode}</div>
          <div class="lvs">${pastilles(R.points)}</div>
          <ul class="pts">${R.points.slice(0, 4).map(p => `<li><i style="background:${LEV[p.lev][1]}"></i><span>${p.txt}</span></li>`).join('')}<li style="color:var(--color-text-muted)"><i style="background:transparent"></i>+ 3 autres points dans le rapport</li></ul>
          <div class="ac"><button class="db-btn prim">Ouvrir le PDF ›</button><button class="db-btn">Lu ✓</button></div></div>
        <div class="rn-m att"><div class="k"><span class="rn-new" style="background:#D97706">Nouveau</span>Commande · mar. 06/10 · 06:30<span class="src">panel</span></div>
          <div class="t">Commande #4821 — à valider</div><div class="s">Votre commande #4821 du 05/10 (412,60 €) n’est pas encore validée. Merci de la finaliser pour qu’elle parte chez votre fournisseur.</div>
          <div class="ac"><button class="db-btn">Voir mes commandes ›</button></div></div>
        <div class="rn-m lu"><div class="k">Rapport · ven. 02/10 · 09:00<span class="src">lu ven. 02/10 à 11:12</span></div>
          <div class="t">Tâches sous le seuil — la veille</div><div class="s">1 point à traiter — jeudi 01/10</div>
          <div class="lvs">${pastilles([{ lev: 'xp' }])}</div></div>
      </div>
      <div class="pied">Les messages viennent du panel (/shops/4/notifications) : ce sont ceux de la cloche du panel, lus ici sans quitter le dashboard.</div>
    </aside>`;
    document.body.insertAdjacentHTML('beforeend', h);
    return '.rn-cloche';
  },
};

/* ------------------------------------------------------------------------------------------- */
/* Le cockpit — écran Reporting                                                                  */
/* ------------------------------------------------------------------------------------------- */
/* Les mêmes styles en ligne que templates.js : la pilule segmentée, l'étiquette de ligne. */
const COCKPIT_LIB = `
window.RN = {
  seg: (libs, on, petit) => '<span style="display:inline-flex;border:0.5px solid var(--color-border-secondary);border-radius:999px;overflow:hidden;font-size:' + (petit ? '10.5' : '11.5') + 'px;font-weight:600;flex:none">'
    + libs.map((l, i) => '<button style="border:none;cursor:pointer;padding:' + (petit ? '4px 10px' : '6px 13px') + ';font-family:var(--font-ui);white-space:nowrap;' + (i === on ? 'background:var(--color-primary);color:#fff' : 'background:transparent;color:var(--color-text-muted)') + '">' + l + '</button>').join('') + '</span>',
  lab: (t, prim) => '<span style="font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;color:' + (prim ? 'var(--color-primary)' : 'var(--color-text-muted)') + ';width:110px;flex:none">' + t + '</span>',
  aide: t => '<span style="font-size:10.5px;color:var(--color-text-muted)">' + t + '</span>',
  ligne: (h, neuf) => '<div class="' + (neuf ? 'rn-neuf' : '') + '" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:9px">' + h + '</div>',
  puce: (t, on) => '<span style="display:inline-flex;align-items:center;gap:6px;border-radius:999px;padding:4px 11px;font-size:11px;font-weight:500;' + (on ? 'background:rgba(45,122,62,0.10);color:#2d7a3e;border:0.5px solid rgba(45,122,62,0.25)' : 'background:var(--color-surface);border:0.5px solid var(--color-border-secondary);color:var(--color-text-muted)') + '">' + t + '</span>',
  cases: (libs) => libs.map(([t, on]) => '<span style="display:inline-flex;align-items:center;gap:7px;border-radius:8px;padding:6px 11px;font-size:11.5px;font-weight:500;' + (on ? 'border:1.5px solid var(--color-primary);background:var(--color-surface)' : 'border:0.5px solid var(--color-border-secondary);background:var(--color-surface);color:var(--color-text-muted)') + '"><span style="width:14px;height:14px;border-radius:4px;display:inline-grid;place-items:center;font-size:10px;color:#fff;' + (on ? 'background:var(--color-primary)' : 'border:1px solid var(--color-border-secondary)') + '">' + (on ? '✓' : '') + '</span>' + t + '</span>').join(''),
  trouve: (sel, re) => [...document.querySelectorAll(sel)].find(e => re.test(e.textContent.trim())),
};`;
const CSS_COCKPIT = `.rn-neuf{outline:2px dashed #d9a520;outline-offset:4px;border-radius:6px}`;

/* Le compositeur, en modification du rapport franchisé : la ligne « Mode d'envoi » et ce qui la
 * suit (carnet d'adresses par magasin, annuaire, copie réseau). */
function compoAncres() {
  const lab = RN.trouve('span', /^Mode d'envoi$/);
  const mode = lab.parentElement;
  const carnet = mode.nextElementSibling;                       // un champ email par magasin
  const annuaire = carnet.nextElementSibling;                   // l'annuaire des consultants
  const dest = annuaire.nextElementSibling;                     // la copie réseau + enregistrer
  const envoyer = RN.trouve('button', /^Envoyer par email$/);
  return { mode, carnet, annuaire, dest, envoyer };
}
const MAGS = ['Atelier by Berlo - Corbais', 'Atelier by Max & Sandra - Gosselies', 'Atelier by - Halle', 'Atelier by Harmonie - Sombreffe'];

const cockpit = {
  /* A — un interrupteur Mail / Notification ; en notification, le carnet d'adresses disparaît. */
  A: (MAGS) => {
    const a = compoAncres();
    a.mode.insertAdjacentHTML('beforebegin', RN.ligne(RN.lab('Envoyer par', true) + RN.seg(['✉ Mail', '🔔 Notification'], 1)
      + RN.aide('le magasin la voit sur son dashboard opérationnel et dans la cloche du panel — pas de boîte mail à ouvrir'), true));
    a.mode.querySelectorAll('button')[0].textContent = 'Une seule, à tous';
    a.mode.querySelectorAll('button')[1].textContent = 'Une par magasin';
    a.mode.lastElementChild.textContent = 'chaque magasin reçoit SA version ; sans matière, il ne reçoit rien';
    a.carnet.outerHTML = '<div class="rn-neuf" style="margin-bottom:11px">'
      + RN.ligne(RN.lab('Magasins') + MAGS.map(m => RN.puce('✓ ' + m.replace(/^Atelier by( [^-]+)? - /, ''), true)).join('') + RN.aide('le périmètre choisi en « 2 · Les magasins »'))
      + RN.ligne(RN.lab('Visible') + RN.seg(['3 jours', '7 jours', 'jusqu’au rapport suivant'], 1) + RN.aide('puis la notification s’efface du dashboard'))
      + RN.ligne(RN.lab('Priorité') + RN.seg(['Info', 'Attention', 'Urgent'], 1) + RN.aide('la couleur de la notification dans le panel'))
      + '</div>';
    a.annuaire.insertAdjacentHTML('beforebegin', RN.ligne(RN.lab('Copie email') + RN.aide('la version complète, pour le consultant : elle part toujours par email'), true));
    a.envoyer.textContent = '🔔 Notifier les magasins';
    a.envoyer.classList.add('rn-neuf');
  },
  /* C — trois choix (Mail, Notification, Les deux) et l'aperçu de ce que le magasin reçoit. */
  C: (MAGS) => {
    const a = compoAncres();
    a.mode.insertAdjacentHTML('beforebegin', RN.ligne(RN.lab('Envoyer par', true) + RN.seg(['✉ Mail', '🔔 Notification', '✉ + 🔔 Les deux'], 2)
      + RN.aide('les deux : le PDF par email au franchisé, et la liste à cocher sur son dashboard'), true));
    a.mode.querySelectorAll('button')[1].textContent = 'Un envoi par magasin';
    const apercu = `<div class="rn-neuf" style="display:grid;grid-template-columns:minmax(0,1fr) 380px;gap:18px;margin:4px 0 12px;align-items:start">
      <div>${RN.ligne(RN.lab('Sur le dashboard') + RN.aide('chaque point du rapport devient une ligne à cocher « Fait » ; le cockpit voit qui a traité quoi, et quand'))}
        ${RN.ligne(RN.lab('Visible') + RN.seg(['jusqu’à tout traité', '7 jours'], 0))}
        ${RN.ligne(RN.lab('Relance') + RN.seg(['aucune', 'J+2 si rien n’est coché'], 1) + RN.aide('une seconde notification, une seule'))}</div>
      <div style="background:var(--color-surface);border:0.5px solid var(--color-border-secondary);border-radius:12px;padding:12px 14px">
        <div style="font-size:9.5px;font-weight:600;text-transform:uppercase;letter-spacing:0.07em;color:var(--color-text-muted);margin-bottom:8px">Aperçu — ce que reçoit Atelier by - Halle</div>
        <div style="border-left:4px solid #D97706;background:#fffaf2;border-radius:8px;padding:9px 11px">
          <div style="font-size:12.5px;font-weight:700">Rapport de la semaine — 7 points à traiter</div>
          <div style="font-size:11.5px;color:#555;margin-top:3px">Semaine du 28/09 au 04/10 : passage clients, ticket moyen, 3 tâches sous le seuil, stocks négatifs, rentabilité. Cochez chaque point une fois traité.</div>
          <div style="margin-top:8px"><span style="display:inline-block;border-radius:7px;padding:4px 10px;font-size:11px;font-weight:600;background:var(--color-primary);color:#fff">Ouvrir la liste</span></div>
        </div>
        <div style="font-size:10.5px;color:var(--color-text-muted);margin-top:7px">priorité attention · panel et dashboard · le PDF reste joint au mail</div>
      </div></div>`;
    a.mode.insertAdjacentHTML('afterend', apercu);
    a.envoyer.textContent = '✉ + 🔔 Envoyer et notifier';
    a.envoyer.classList.add('rn-neuf');
  },
  /* D — l'interrupteur, et le choix des écrans : dashboard, panel, téléphone (push). */
  D: (MAGS) => {
    const a = compoAncres();
    a.mode.insertAdjacentHTML('beforebegin', RN.ligne(RN.lab('Envoyer par', true) + RN.seg(['✉ Mail', '🔔 Notification'], 1), true));
    a.mode.querySelectorAll('button')[0].textContent = 'Une seule, à tous';
    a.mode.querySelectorAll('button')[1].textContent = 'Une par magasin';
    a.mode.lastElementChild.textContent = 'chaque magasin reçoit SA version ; sans matière, il ne reçoit rien';
    a.carnet.outerHTML = '<div class="rn-neuf" style="margin-bottom:11px">'
      + RN.ligne(RN.lab('Où') + RN.cases([['Cloche du dashboard', true], ['Cloche du panel', true], ['Téléphone (push)', true]])
        + RN.aide('push : les téléphones et tablettes abonnés du magasin, le même envoi que l’alerte de stock'))
      + RN.ligne(RN.lab('Magasins') + MAGS.map(m => RN.puce('✓ ' + m.replace(/^Atelier by( [^-]+)? - /, ''), true)).join('') + RN.aide('le périmètre choisi en « 2 · Les magasins »'))
      + '</div>';
    a.annuaire.insertAdjacentHTML('beforebegin', RN.ligne(RN.lab('Copie email') + RN.aide('la version complète, pour le consultant'), true));
    a.envoyer.textContent = '🔔 Notifier les magasins';
    a.envoyer.classList.add('rn-neuf');
  },
  /* B — l'interrupteur sur chaque ligne de la liste des rapports récurrents, au début de la ligne
   * des destinataires : en notification, les adresses laissent la place aux magasins notifiés. */
  B: (MAGS) => {
    const lignes = [...document.querySelectorAll('span')].filter(s => s.style.fontSize === '13px' && s.style.fontWeight === '500')
      .map(s => s.closest('div[style*="flex-direction:column"], div[style*="flex-direction: column"]')).filter(Boolean);
    const notifie = { 'Rapport franchisé — la semaine': true, 'Tâches sous le seuil — la veille': true };
    const envoi = on => '<span class="rn-neuf" style="display:inline-flex;align-items:center;gap:8px"><span style="font-size:10px;font-weight:500;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted)">Envoi</span>'
      + RN.seg(['✉ Mail', '🔔 Notification'], on ? 1 : 0, true) + '</span>';
    lignes.forEach(L => {
      const nom = L.querySelector('span').textContent.trim();
      const on = !!notifie[nom];
      const a = [...L.children].find(c => /^À/.test(c.textContent.trim()) && c.querySelector('input'));
      if (!a) { return; }
      if (!on) { a.insertAdjacentHTML('afterbegin', envoi(false)); return; }
      const actif = [...L.querySelectorAll('span')].find(s => /^(Actif|Inactif)$/.test(s.textContent.trim()));
      const freq = actif.previousElementSibling;
      freq.textContent = freq.textContent.replace('un email par magasin', 'un envoi par magasin');
      const env = [...L.querySelectorAll('button')].find(b => b.textContent.trim() === 'Envoyer');
      if (env) { env.textContent = '🔔 Notifier'; env.classList.add('rn-neuf'); }
      const copie = a.querySelector('input').value;
      a.outerHTML = '<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">' + envoi(true)
        + '<span style="font-size:10px;font-weight:500;text-transform:uppercase;letter-spacing:0.06em;color:var(--color-text-muted);margin-left:6px">Notifie</span>'
        + MAGS.map(m => RN.puce('✓ ' + m.replace(/^Atelier by( [^-]+)? - /, ''), true)).join('')
        + '<span style="font-size:10.5px;color:var(--color-text-muted)">dashboard + panel · 7 jours'
        + (copie ? ' · copie : ' + copie : '') + '</span></div>';
    });
  },
};

/** Ce qui doit vivre dans la page : les données, les aides et les greffes, en un seul script. */
function prelude() {
  return `const LEV = ${JSON.stringify(LEV)}; const RAPPORT = ${JSON.stringify(RAPPORT)}; const ICO = ${JSON.stringify(ICO)};
    const parLevier = ${parLevier}; const pastilles = ${pastilles}; ${compoAncres}
    ${COCKPIT_LIB}
    window.V_DASH = { ${Object.entries(dashboard).map(([k, f]) => k + ": " + f).join(", ")} };
    window.V_COCKPIT = { ${Object.entries(cockpit).map(([k, f]) => k + ": " + f).join(", ")} };
    window.MAGS = ${JSON.stringify(MAGS)};`;
}

module.exports = { RAPPORT, CSS_DASH, CSS_COCKPIT, prelude };
