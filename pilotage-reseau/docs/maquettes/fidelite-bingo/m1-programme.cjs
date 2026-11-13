/* Maquette 1 — Cockpit › ERP franchisé › Fidélité, onglet Programme : UNE carte, la carte à points de l'ERP, avec le
 * bingo de saison fusionné dedans (Sam, 13/11/2026 : « on ne garderait que la carte à points fusionnée avec le
 * bingo »). La case de la semaine se coche avec les points de la carte (20 points gagnés dans la semaine) : le bingo
 * se lit dans l'ERP par carte et par période. Les cartes à tampons s'arrêtent.
 * Lecture : GET /admin/loyalty/cards (+ /{id}, /options, /preview), GET /admin/voucher-campaigns ; essai : POST
 * /admin/loyalty/simulate ; publication : POST /admin/loyalty/cards (v2, la v1 étant figée) et PATCH (fin de la v1,
 * fin des cartes à tampons), avec la revision. */
const { eur, ill, api, gap, SEMAINES, BONS, SUPER, SEUIL, page, ecrire } = require('./commun.cjs');

const J = ['L', 'M', 'M', 'J', 'V', 'S', 'D'];
const jours = on => `<span class="jours">${J.map((l, i) => `<i class="${on.includes(i) ? 'on' : ''}">${l}</i>`).join('')}</span>`;

const carte = `<div class="g"><h3>La carte ${api('GET /admin/loyalty/cards')}</h3>
  <div class="liste" style="display:grid;grid-template-columns:330px minmax(0,1fr);align-items:stretch">
    <div style="background:var(--accent);color:#fff;padding:16px 18px;display:flex;flex-direction:column;justify-content:space-between;gap:12px">
      <div style="display:flex;justify-content:space-between;font-size:10px;letter-spacing:.12em;text-transform:uppercase;opacity:.85"><span>L’Atelier by</span><span>Points · Bingo</span></div>
      <div style="display:flex;align-items:center;gap:12px"><div class="blanc" style="width:46px;height:46px">${ill('hiver')}</div>
        <div><div style="font-size:18px">Carte L’Atelier</div><div style="font-size:11.5px;opacity:.85">1 € = 1 point · une case par semaine</div></div></div>
      <div style="font-size:11px;opacity:.85">FID-POINTS · v2 dès le 23/11/2026 · réseau, 4 magasins</div></div>
    <div style="padding:12px 18px;display:grid;gap:8px;align-content:center;font-size:12.5px;color:var(--muted-2)">
      <div style="display:flex;justify-content:space-between;gap:10px"><span>La seule carte du programme : les points pour ce que le client dépense, le bingo pour chaque semaine où il revient.</span><span class="tag ok">active</span></div>
      <div style="display:flex;justify-content:space-between;gap:10px;color:var(--muted)"><span>Cartes à tampons Pain, Viennoiserie, Café, Tarte : arrêtées le 22/11 à minuit ; les récompenses déjà prêtes restent valables jusqu’à leur échéance.</span><span class="tag neu">4 à arrêter</span></div>
      <div class="src" style="margin:0">${api('PATCH /admin/loyalty/cards/{3,4,5,6} {ends_at, revision}')} · les tampons ne se changent pas en points : l’API n’a pas d’écriture de solde</div></div>
  </div></div>`;

const points = `<div class="g"><h3>Les points · ce que le client gagne ${api('points_config')}</h3><div class="liste">
  <div class="r"><span class="ic">€</span><div class="ph">Chaque <span class="v">1,00 €</span> dépensé rapporte <span class="v">1 point</span>, arrondi à l’inférieur, en boutique et au webshop.
    <small>${api('slice_cents: 100')} ${api('points_per_slice: 1')}</small></div><span class="dr">4 magasins</span></div>
  <div class="r"><span class="ic">×2</span><div class="ph">Les points comptent <span class="v">×2</span> ${jours([1, 2, 3])} de <span class="v">14:00</span> à <span class="v">17:00</span> — « Jours creux »
    <small>${api('bonuses[0] {days, from, to, coefficient: 2}')} · heure du magasin</small></div><span class="tog on"></span></div>
  <div class="r"><span class="ic">×2</span><div class="ph">Les points comptent <span class="v">×2</span> ${jours([0, 1, 2, 3, 4, 5])} de <span class="v">17:30</span> à <span class="v">19:00</span> — « Fin de journée »
    <small>${api('bonuses[1]')} · sur tout le ticket : un ×2 de l’API ne se limite pas au pain · deux ×2 à la fois : le plus fort l’emporte</small></div><span class="tog on"></span></div>
  <div class="r"><span class="ic">◎</span><div class="ph">Par magasin : Corbais <span class="v">×1</span> · Gosselies <span class="v">×1</span> · Halle <span class="v">×1</span> · Sombreffe <span class="v">×1,5</span>
    <small>${api('shop_coefficients')} · remplace « ×2 le lundi à Sombreffe » : l’API ne lie pas un créneau à un magasin</small></div><span class="dr att">modifié</span></div>
  <div class="r"><span class="ic">↗</span><div class="ph">Le client échange ses points dans l’app, au palier exact :
    <span class="v">150</span> → <span class="v">5 €</span> · <span class="v">300</span> → <span class="v">11 €</span> · <span class="v">600</span> → <span class="v">25 €</span>
    <small>${api('tiers [{points, voucher_cents}]')} · jamais automatique ${api('POST /webshop/loyalty/points/redeem')}</small></div><span class="dr att">2 paliers ajoutés</span></div>
  <div class="r"><span class="ic">60</span><div class="ph">Le bon vaut <span class="v">60 jours</span> ; chaque point vaut <span class="v">365 jours</span>, les plus anciens partent en premier.
    <small>${api('voucher_validity_days')} ${api('points_validity_days')}</small></div><span class="dr"></span></div>
</div></div>`;

const semaines = SEMAINES.map(s => `<div style="text-align:center;opacity:${s.n < 8 ? .7 : 1}">
  <div style="width:34px;height:34px;margin:0 auto;border-radius:8px;${s.n === 8 ? 'box-shadow:inset 0 0 0 1.5px var(--accent);' : ''}background:${s.fete ? 'var(--abricot-d)' : '#fff'};padding:3px">${ill(s.ill)}</div>
  <div style="font-size:9.5px;color:${s.n === 8 ? 'var(--accent)' : 'var(--muted)'};margin-top:2px">S${s.n}</div></div>`).join('');

const bingo = `<div class="g"><h3>Le bingo de la carte · la récurrence</h3><div class="liste">
  <div class="r"><span class="ic">20</span><div class="ph">Une case par semaine dès <span class="v">${SEUIL} points</span> gagnés sur la carte, du lundi au dimanche, tous magasins, en une ou plusieurs fois.
    <small>Les points de la semaine, carte par carte : ${api('GET /shops/{id}/loyalty/customers?card_id=12&from=lun&to=dim')} → ${api('programs[].shop_activity.points_earned')}, additionnés sur les 4 magasins · ×2 et coefficients compris</small></div><span class="dr">20 € · 10 € à ×2</span></div>
  <div class="r"><span class="ic">3</span><div class="ph">Toutes les <span class="v">3</span> cases, un bon de plus en plus gros :
    ${BONS.map(b => `<span class="v">${eur(b.v)}</span>`).join(' → ')}, valable <span class="v">30 jours</span>.
    <small>Une campagne par valeur ${api('code_type: UNIQUE · requires_customer')} ; le bon du client : ${api('POST /admin/voucher-campaigns/{id}/codes {id_customer, valid_to, usage_limit: 1}')}</small></div><span class="dr ok">4 campagnes reliées</span></div>
  <div class="r"><span class="ic">13</span><div class="ph">Carte pleine : le super bon de <span class="v">${eur(SUPER)}</span> et le tirage au sort d’<span class="v t">1 an de pain</span> le 22/02.
    <small>5e campagne · le tirage se fait sur la liste des cartes pleines, hors API</small></div><span class="dr att">montant à confirmer</span></div>
  <div class="r" style="grid-template-columns:30px minmax(0,1fr)"><span class="ic ill2">${ill('hiver')}</span><div>
    <div style="display:grid;grid-template-columns:repeat(13,minmax(0,1fr));gap:4px;padding:4px 0">${semaines}</div>
    <div style="font-size:11.5px;color:var(--muted)">Hiver 2026 – 2027 : 13 semaines, du 23/11 au 21/02. Fêtes et dessins se règlent dans l’onglet Bingo. Puis printemps, été, automne : la carte reste, la saison change.</div></div></div>
</div>
<div style="margin-top:8px">${gap('L’ERP ne coche pas de case : le cockpit lit les points de la semaine et n’écrit dans l’ERP que les bons. Rien n’est gardé de son côté ; la grille se recalcule à la demande. À demander à l’ERP pour la suite : un palier hebdomadaire dans points_config, et la grille vit dans la carte.', 'Manque à l’API')}</div></div>`;

const ensemble = `<div class="g"><h3>Points et bingo, une seule carte</h3><div class="liste">
  <div class="r"><span class="ic gris">1</span><div class="ph">Un ticket rapporte des points ; les points de la semaine cochent la case.<small>Pas de seconde règle à retenir pour le client : il ne compte que ses points</small></div><span class="dr"></span></div>
  <div class="r"><span class="ic gris">2</span><div class="ph">Les ×2 font avancer le bingo : aux heures creuses, 10 € suffisent pour la case.<small>Le bingo pousse vers les créneaux à remplir · Sombreffe ×1,5 : 13,40 €</small></div><span class="dr"></span></div>
  <div class="r"><span class="ic gris">3</span><div class="ph">Échanger des points ne décoche rien : la case compte les points gagnés, pas le solde.<small>${api('points_earned')} sur la semaine, jamais ${api('balance')}</small></div><span class="dr"></span></div>
  <div class="r"><span class="ic gris">4</span><div class="ph">Un bon à la fois en caisse : bon de points ou bon du bingo, le client choisit dans l’app.<small>${api('POST /webshop/vouchers/activation')} · un bon choisi par magasin, la caisse le lit</small></div><span class="dr">ERP</span></div>
</div><div class="src" style="margin-top:10px">Plus à l’écran, faute d’API : bonus panier, anniversaire ×3, bienvenue, 2e visite, parrainage, retour après 45 jours, plafond, jours exclus, budget. Le bingo reprend le rôle des déclencheurs de visite.</div></div>`;

const aside = `<aside>
  <div class="card"><h3>Simuler un ticket</h3><div class="src" style="margin:-6px 0 10px">${api('POST /admin/loyalty/simulate')}</div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;font-size:12px;color:var(--muted-2)">
      <span>Magasin <span class="v t">Corbais</span></span><span>Montant <span class="v t">22,40 €</span></span>
      <span style="grid-column:span 2">Le <span class="v t">jeudi 14/01/2027 à 15:10</span></span></div>
    <div class="kpi" style="margin:12px 0 4px"><b>44 points</b><span style="font-size:11.5px;color:var(--muted)">avec le barème en cours d’écriture</span></div>
    <table class="kv"><tr><td>Tranches de 1,00 €</td><td>22 × 1 point</td></tr><tr><td>Magasin</td><td>×1</td></tr>
      <tr><td>Bonus « Jours creux »</td><td>×2</td></tr><tr><td>Le même ticket à Sombreffe</td><td>66 points</td></tr>
      <tr><td>Bingo : 6 + 44 points cette semaine</td><td class="ok">S8 cochée, 7 / 13</td></tr></table>
    <div class="src" style="margin:8px 0 0">Rien n’est écrit : ni vente, ni solde, ni bon.</div></div>
  <div class="card"><h3>Publier dans l’ERP</h3>
    <table class="kv"><tr><td>Paliers 300 → 11 € et 600 → 25 €</td><td>barème</td></tr><tr><td>Sombreffe ×1,5</td><td>barème</td></tr><tr><td>Fin des 4 cartes à tampons</td><td>22/11</td></tr></table>
    <div style="margin:10px 0">${gap('La carte à points v1 a servi : son barème est figé (terms_locked). Les changements créent la v2, même code, dès le 23/11 ; la v1 s’arrête la veille à minuit.', 'Carte utilisée')}</div>
    <div style="display:flex;gap:8px;align-items:center"><span class="btn p">Publier dans l’ERP</span><span class="btn">Annuler</span></div>
    <div class="src" style="margin:10px 0 0;display:grid;gap:4px"><span>${api('POST /admin/loyalty/cards')} la v2</span><span>${api('PATCH /admin/loyalty/cards/{id}')} les fins de carte, avec leur revision</span><span>409 si quelqu’un a modifié entre-temps : on relit et on recommence.</span></div></div>
  <div class="card"><h3>D’où vient l’écran</h3><table class="kv">
    <tr><td>La carte, le barème</td><td>${api('GET /admin/loyalty/cards/12')}</td></tr>
    <tr><td>Magasins, produits</td><td>${api('GET /admin/loyalty/options')}</td></tr>
    <tr><td>Ce qui rapporte, par magasin</td><td>${api('GET /admin/loyalty/preview')}</td></tr>
    <tr><td>Les bons du bingo</td><td>${api('GET /admin/voucher-campaigns')}</td></tr>
    <tr><td>Les points de la semaine</td><td>${api('GET /shops/{id}/loyalty/customers')}</td></tr>
    <tr><td>Écrire</td><td>${api('POST · PATCH /admin/loyalty/cards')}</td></tr></table>
    <div class="src" style="margin:8px 0 0">Aucune table du cockpit : tout se relit dans l’ERP à chaque ouverture.</div></div>
</aside>`;

const corps = `<div class="hd"><div><div class="fil">ERP franchisé › Fidélité <code>/fidelity</code></div><h1>Fidélité</h1>
  <p class="intro">Une seule carte : la carte à points, avec le bingo de saison dedans. Les points récompensent ce que le client dépense ; les points de chaque semaine cochent une case du bingo et récompensent qu’il revienne. Tout se règle ici et se publie dans l’ERP, par son API.</p></div>
  <div class="dr"><span class="tag mq">Maquette 1 · le programme</span><span class="tag ill">chiffres illustrés · API de test lue sans jeton</span></div></div>
<div class="barre"><div class="seg"><span class="on">Programme</span><span>Bingo</span><span>Clients</span><span>En caisse</span></div>
  <span class="etat">Bingo d’hiver 2026 – 2027 · semaine 8 sur 13 · jeudi 14/01/2027</span></div>
${carte}
<div style="display:grid;grid-template-columns:minmax(0,1fr) 380px;gap:24px;align-items:start"><div>${points}${bingo}${ensemble}</div>${aside}</div>`;

ecrire('m1-programme.html', page('Fidélité — maquette 1 · le programme', corps));
