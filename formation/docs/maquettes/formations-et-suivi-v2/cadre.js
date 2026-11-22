/* Le cadre du dashboard magasin (rail ≥ 1 024 px) des maquettes du coffre-fort : le lien « Mon coffre-fort » est
   épinglé TOUT EN BAS du rail. <body data-vue="formation" data-legende="…"> ; le contenu de la page est #page. */
(function () {
  'use strict';
  const vue = document.body.dataset.vue || 'coffre';
  const a = (t, on, x) => `<a class="${on ? 'on' : ''}"><span class="ic"></span>${t}${x || ''}</a>`;
  const rail = `<nav class="drail">
    <img class="lg" src="/shared/public/assets/img/logo.png" alt="L'Atelier">
    ${a('Boîte mail', false, '<span class="n">4</span>')}<a class="ia"><span class="ic"></span>Assistant IA</a>
    <div class="g">La journée</div>${a('Opérationnel', vue === 'ops')}${a('Tâches')}${a('Temps morts')}
    <div class="g">Les chiffres</div>${a('Semaine')}${a('Mois')}${a('Année')}${a('Trésorerie')}${a('Frais')}
    <div class="g">Vendre</div>${a('Analyse')}${a('Produits')}${a('Promotions')}${a('Primes')}
    <div class="g">Marketing</div>${a('Mon marketing')}${a('Fidélité')}${a('Mon catalogue')}
    <div class="g" style="color:var(--accent)">Se former</div>${a('Formations et suivi', vue === 'formation')}
    <div class="g">Paramètres</div>${a('Mails')}
    <div class="coffre-pied">
      <a class="coffre"><span class="cad">🔒</span><span><b>Mon coffre-fort</b><small>contrat, légal, formation, visites</small></span></a></div>
  </nav>`;
  const page = document.getElementById('page');
  const wrap = document.createElement('div');
  wrap.className = 'app';
  wrap.innerHTML = rail + '<main class="dmain"></main>';
  page.parentNode.insertBefore(wrap, page);
  wrap.querySelector('main').appendChild(page);
})();
