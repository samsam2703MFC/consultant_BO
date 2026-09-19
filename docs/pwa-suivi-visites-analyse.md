# PWA Suivi franchisé & consultation — analyse avant construction

Analyse du brief « Projet PWA — Suivi Franchisé & Consultation (Tablet/Mobile) » face au cockpit
existant, avec dix écrans maquettés dans `docs/maquettes/pwa-visites/` (un fichier `index.html`,
`?ecran=…`, captures PNG à côté, régénérées par `generer.js`).

Rien n'est construit : ce document et les maquettes servent à trancher avant d'écrire le code.

## 1. Verdict en cinq lignes

- **Une bonne moitié du brief existe déjà côté données** : CA et objectifs, note et avis Google,
  tâches et non-conformités du panel, planogramme et photos, notifications push, mail, consultants.
  Il manque l'orchestration « visite » par-dessus.
- **Ce qui n'existe pas du tout** : l'agenda des visites, la checklist par visite, les photos de
  visite, le plan d'action à trois acteurs avec ses statuts, le rapport mystery shopper, l'équipe
  (effectif, turnover), la synthèse quotidienne, le mode hors-ligne.
- **La pile recommandée par le brief (React ou Flutter, GraphQL, Netlify, SQLite) est à écarter** :
  le cockpit est une application PHP 8.4 + JavaScript sans framework, déployée par git sur le VPS
  OVH, avec ses propres pages autonomes (`dashboard/`, `prospection/`, `newsletter/`). Une PWA
  `visites/` dans ce dépôt réutilise tout ; une pile à part refait tout.
- **Le feu tricolore et les seuils planogramme du brief sont calculables**, à condition de fixer
  qui saisit quoi : la déviation planogramme et l'effectif n'ont pas de source automatique
  aujourd'hui, ils seront « 🔴 local » au sens du brief.
- **Phase 1 réaliste : 5 lots, environ 20 à 25 jours** en gardant l'API et les vues dans ce dépôt.
  Détail en section 9. Six questions à trancher avant de commencer (section 11).

## 2. Les maquettes

| Écran du brief | Fichier | Ce qu'on y voit |
|---|---|---|
| 0 · Agenda semaine (landing) | `agenda.png` | Semaine du consultant, une carte par visite avec feu, CA, Google, alerte du jour ; « à planifier » (ASAP après un P0, visite due) ; rappel J-1 |
| 1 · Portfolio | `portfolio.png` | Quatre boutiques avec feu, signal principal, prochaine visite, badges de source ; la règle du feu écrite |
| 1.3 · Planning de visite | `fiche.png` | Fiche avant d'y aller : adresse, durée, aperçu 4 KPI, priorités P0/P1/P2, « avant d'entrer », rappel franchisé, Démarrer |
| 2 · TB franchisé | `tb.png` | Photo du jour (façade, intérieur, arrière), 4 chiffres avec badge API / local / API + local, alertes, Checklist |
| 3 · Checklist modulaire | `checklist.png` | Produit, Hygiène, Visuel, Planogramme (référence vs aujourd'hui, chips de cause), Mystery shopper (rapport du mois, questions de confirmation) ; notes 1..5, photos, enregistrement local |
| 4 · Review post-visite | `review.png` | Écarts, plan d'action P0/P1/P2 avec assigné et délai, escalade, observé positif, sentiment équipe, sync des photos |
| 5 · Historique 3 mois | `historique.png` | Photos comparables, CA 8 semaines, Google vs MSP, NC par mois, planogramme, notes, face au réseau |
| Synthèse Sam (tablette) | `sam.png` | Compteurs, tableau des boutiques, escalades possibles, actions du jour, activité des consultants |
| Vue franchisé | `franchise.png` | Mon plan d'action par statut, bouton photo, retour admin, ma boutique cette semaine |
| Vue admin | `admin.png` | Corrections à valider : avant / photo du franchisé, Accepter / À reprendre |

Données réelles utilisées : les quatre boutiques ouvertes, leurs notes Google et nombre d'avis,
le CA de la semaine contre l'objectif tel que `/exploitation/jour` le donne le 19 septembre, les
cinq consultants. Tout le reste (visites, plans d'action, MSP, effectif) est inventé pour montrer
l'écran ; aucun nom de client n'apparaît.

Choix de maquette à valider : quatre onglets en bas (Agenda, Boutiques, Plans, Réglages) ;
badge de source sur chaque chiffre plutôt qu'un pictogramme seul ; escalade à Sam depuis la
review ; l'admin voit « avant » et « après » côte à côte.

## 3. Ce que le cockpit fournit déjà

Vocabulaire du brief : 🟢 API = vient d'une source automatique, 🔴 local = saisi dans l'app.

| Besoin du brief | Existe | Où | Source |
|---|---|---|---|
| CA jour / semaine vs objectif | oui | `GET /exploitation/jour` (7 jours par magasin, `ca` et `objectif`), `/exploitation/magasin` | 🟢 ERP |
| Note et avis Google, derniers avis | oui | `GET /reputation`, `POST /reputation/sync`, tables `ceo_shop_reputation`, `ceo_shop_review` | 🟢 Google Places |
| Tâches consultant, notes 1..5, validation | oui | `GET /pwa/tasks`, `POST /pwa/tasks/review`, `POST /pwa/tasks/validate`, `PUT /pwa/tasks/annotation`, journal `mac_task_review` | 🟢 panel |
| Non-conformités, récidive, seuil de signalement | oui | `GET /pwa/tasks/nc?shop=&date=` (ou `du/au`), seuil 4 | 🟢 panel |
| Planogramme de référence, photos produits | oui | `GET /planogramme`, `/planogramme/photos` (liens S3 signés 1 h), tables `pla_*`, `ceo_plano_photo` | 🟢 |
| Photo d'une cible planogramme | oui | `POST /planogramme/photo` : data-URL, 4 Mo max, type lu dans les octets, fichier sous `public/` | 🔴 |
| Notifications push | oui | `src/push.php` : VAPID, `POST /push/abonnements`, `pushDiffuser(shop, charge)`, service worker `dashboard/sw.js` | — |
| Mail | oui | `Smtp::envoyer`, expéditeur rapport@atelierby.be, déjà utilisé par la newsletter et les rapports | — |
| Consultants | oui | `GET /consultants` (cinq « Consultant réseau »), `ceo_consultant_visit` (consultant, date, magasin, sujet), `POST /consultants/note` | 🔴 |
| Magasins | oui | `ep_stores()` : Corbais, Gosselies, Sombreffe, Halle (Gembloux fermé) | 🟢 |
| Cron toutes les 5 min | oui | `/etc/cron.d/cockpit-rapports` écrit par `bin/deploy.sh` | — |
| Page autonome mobile par magasin | oui | modèle `dashboard/?shop=`, `prospection/?shop=`, `newsletter/?shop=` | — |
| Design system | oui | `assets/ds/global.css` (jetons, polices) | — |

Conclusion : les quatre KPI du TB franchisé se remplissent à trois quarts sans rien saisir. Seuls
« Personnel » et la partie MSP de « Client » sont à saisir.

## 4. Ce qui manque

| Manque | Poids | Remarque |
|---|---|---|
| Agenda des visites (planifiées, dues, ASAP) | moyen | `ceo_consultant_visit` ne porte que le passé ; pas de créneau, pas de statut, pas de fréquence par boutique |
| Visite comme objet (début, fin, consultant, checklist, review) | fort | rien |
| Référentiel de checklist (5 modules, points, cibles) | moyen | à définir avec Sam ; le brief donne les modules, pas les points |
| Photos de visite (du jour, par point, avant/après) | moyen | l'upload planogramme existe mais une photo par cible et 4 Mo ; il faut n photos par visite, compression, EXIF retiré |
| Plan d'action à trois acteurs avec statuts | fort | rien ; c'est le cœur du brief |
| Rapport mystery shopper | moyen | aucune source : import manuel (score par rubrique + PDF) |
| Équipe : effectif, turnover | faible | aucune source RH ; saisie par le consultant ou le franchisé |
| Déviation planogramme en % | moyen | pas de mesure automatique ; chips de cause + pourcentage saisi, ou comptage des emplacements vides |
| Comptes franchisé et admin | moyen | l'auth intégrée est désactivée (redirection depuis l'ERP) ; les pages `?shop=` ne vérifient rien. Un franchisé qui valide une correction doit être identifié |
| Hors-ligne | fort | `dashboard/sw.js` ne met rien en cache par choix ; il faut un cache applicatif + une file d'écritures |
| Synthèse quotidienne pour Sam | faible | tout est calculable une fois les objets ci-dessus en base ; envoi mail + page tablette |
| Rappels J-1 / jour J | faible | cron + push + mail existent ; il manque les règles |

## 5. Architecture recommandée

**Une PWA `public/visites/` dans ce dépôt, en JavaScript sans framework, sur l'API PHP existante.**

Pourquoi pas la pile du brief :

- React ou Flutter + GraphQL + Netlify = un deuxième dépôt, un deuxième déploiement, une couche
  GraphQL devant une API REST qui existe déjà, et un hébergeur de plus. Pour trois écrans
  d'écriture et une file hors-ligne, le coût dépasse le gain.
- Le cockpit a déjà le modèle « page autonome mobile + module partagé » (`prospection.js`,
  `newsletter.js`) ; la PWA en est la suite logique, et le rail du cockpit peut ouvrir les mêmes
  écrans sur grand écran (vue admin, synthèse).
- SQLite côté client n'apporte rien qu'IndexedDB ne fasse dans un navigateur ; pas de dépendance.

Découpage :

```
public/visites/index.html        page PWA (consultant, franchisé, admin selon le rôle)
public/visites/manifest.json     nom, icônes, start_url, display standalone
public/visites/sw.js             cache applicatif (coquille + dernières données) + push
public/assets/js/visites.js      module partagé : écrans, file hors-ligne, photos
src/visites.php                  tables, endpoints, feu tricolore, workflow, synthèse
bin/visites_cron.sh              rappels J-1 / jour J, escalades, synthèse 7h
```

Rôles : `?role=consultant&id=u8`, `?shop=3` (franchisé), `?role=admin`. Tant que l'auth intégrée
reste désactivée, le lien porte le rôle comme aujourd'hui pour `dashboard/?shop=`. Voir question 5.

## 6. Modèle de données

```
ceo_visite            id, shop_id, consultant_id, prevu_le, debut_h, duree_min, statut
                      (planifiee|confirmee|en_cours|terminee|annulee), motif (reguliere|asap|due),
                      commence_a, termine_a, sentiment_equipe 1..5, observe_positif, notes, synced_at
ceo_visite_point      id, visite_id, module (produit|hygiene|visuel|planogramme|msp), point_ref,
                      etat (ok|ko|na), note 1..5, commentaire, cause (chips, JSON)
ceo_visite_photo      id, visite_id, point_id NULL, genre (jour_facade|jour_interieur|jour_arriere|
                      point|avant|apres|correction), chemin, prise_a, lat, lng, largeur, hauteur, octets
ceo_plan_action       id, shop_id, visite_id NULL, point_id NULL, titre, detail, priorite (P0|P1|P2),
                      assigne (franchise|equipe|consultant|admin), echeance, statut (ouvert|attente|
                      valide|reprendre|ferme|escalade), cree_par, cree_le, valide_par, valide_le,
                      ferme_par, ferme_le, retour_admin, escalade_motif
ceo_plan_action_evt   id, plan_id, quand, qui, de_statut, vers_statut, commentaire, photo_id NULL
ceo_msp               id, shop_id, mois, score_total, rubriques JSON (accueil, produits, hygiene,
                      ambiance …), commentaires, fichier (PDF), televerse_par, televerse_le
ceo_equipe_releve     id, shop_id, releve_le, effectif, prevu, departs_mois, source (visite|franchise)
ceo_visite_frequence  shop_id, jours (7 par défaut), rappel_j1 (1), rappel_jour (1)
ceo_app_setting       visitesChecklist (référentiel des points par module), visitesSeuils (feu),
                      visitesPlanoSeuils (25 / 10, rupture 3 j)
```

Photos : sur le disque sous `public/uploads/visites/{shop}/{visite}/…` comme les photos
planogramme, sauvegardées avec le reste ; S3 seulement si le volume dépasse quelques Go
(une visite = 5 à 15 photos de 300 Ko, quatre boutiques par semaine : environ 250 Mo par an).

## 7. Endpoints

```
GET  /visites/agenda?consultant=&semaine=       visites de la semaine, dues, ASAP, feu par boutique
GET  /visites/portfolio?consultant=             boutiques avec feu, signal, prochaine visite
GET  /visites/boutique/{shop}                   TB franchisé : 4 KPI avec source, alertes, photos du jour
GET  /visites/boutique/{shop}/historique        3 mois : photos, CA, Google, MSP, NC, planogramme, notes
POST /visites                                   planifier (shop, consultant, date, heure, motif)
PUT  /visites/{id}                              confirmer, reprogrammer, démarrer, terminer, sentiment
PUT  /visites/{id}/points                       la checklist (lot de points, idempotent : rejouable hors-ligne)
POST /visites/{id}/photos                       une photo (data-URL JPEG ≤ 2 Mo), genre, point, prise_a
GET  /plans?shop=&statut=                       plans d'action (franchisé : les siens ; admin : en attente)
POST /plans                                     créer depuis la review (lot)
PUT  /plans/{id}/statut                         transition (voir section 8) + commentaire + photo
POST /msp                                       rapport mystery shopper (score, rubriques, PDF)
PUT  /equipe/{shop}                             relevé d'effectif
GET  /visites/synthese?date=                    la synthèse de Sam (aussi envoyée par mail à 7h)
GET  /visites/cron?jeton=                       rappels, escalades automatiques, synthèse
```

Toutes les écritures portent un `client_id` (UUID généré sur l'appareil) : la file hors-ligne peut
rejouer sans doublon.

## 8. Règles métier

**Feu tricolore par boutique** (calculé côté serveur, expliqué à l'écran) :

| Feu | Condition (la première qui s'applique) |
|---|---|
| 🔴 | P0 ouvert depuis plus de 3 jours, ou Google en baisse de plus de 0,5 sur 30 jours, ou MSP en alerte (rubrique < 12/20), ou planogramme < 60 % |
| 🟡 | CA sous l'objectif de 5 à 10 % (semaine), ou Google en baisse de 0,5, ou P1 ouvert, ou planogramme 60–79 %, ou visite due |
| 🟢 | sinon |

Remarque : au 19 septembre les quatre boutiques sont sous l'objectif de 14 à 37 %. Avec le seuil
« 5–10 % » du brief, tout serait orange ou rouge en permanence ; la maquette prend le CA comme
signal dans la carte, pas comme déclencheur seul du rouge. Seuils dans `visitesSeuils`, réglables.

**Planogramme** (brief) : 🔴 déviation > 25 % ou rupture 3 jours et plus ; 🟡 10–25 % ; 🟢 < 10 %.
Sans mesure automatique, la déviation = emplacements non conformes / emplacements du meuble,
comptés par le consultant sur la grille du planogramme de référence (`pla_slot`) ; les ruptures
viennent des tâches du panel quand elles y sont, sinon de la checklist.

**Plan d'action, machine à états :**

```
ouvert ──(franchisé : photo de correction)──▶ attente ──(admin : accepter)──▶ valide ──(consultant sur place : confirmer)──▶ ferme
   │                                              └──(admin : à reprendre, nouveau délai)──▶ reprendre ──(photo)──▶ attente
   └──(délai dépassé, ou consultant / admin)──▶ escalade ──(Sam)──▶ ouvert | ferme
```

Qui peut quoi : le franchisé ne voit que sa boutique et ne passe que ouvert/reprendre → attente ;
l'admin passe attente → valide/reprendre et peut escalader ; le consultant crée, ferme (après
validation, sur place) et escalade ; Sam voit tout.

**Notifications** : push via `pushDiffuser` (abonnement par boutique, déjà en place pour le
dashboard) + mail SMTP en secours. Rappel J-1 à 18 h (consultant et franchisé), jour J à 7 h ;
franchisé : nouveau plan d'action, retour « à reprendre » ; admin : photo reçue ; Sam : escalade,
P0 dépassé, synthèse 7 h.

**Synthèse Sam** : une requête serveur (`/visites/synthese`) rendue en page tablette et envoyée
par mail à 7 h via le cron : compteurs, tableau des boutiques, escalades possibles, actions du
jour, activité des consultants.

## 9. Hors-ligne et photos

- **Coquille en cache** (HTML, JS, CSS, polices, logo) par le service worker ; **dernières
  données** de l'agenda, du portfolio et des boutiques de la semaine mises en IndexedDB au
  chargement et à chaque sync, avec l'heure de sync affichée (« dernière sync 09:41 »).
- **File d'écritures** en IndexedDB : chaque action (point de checklist, photo, plan, statut) est
  un enregistrement avec `client_id`, rejoué dans l'ordre au retour du réseau (`Background Sync`
  quand disponible, sinon au prochain chargement). Un bouton « Synchroniser (n en attente) »
  montre l'état.
- **Conflits** : dernier écrit gagne par champ, sauf les transitions de plan d'action, rejetées
  si le statut serveur a bougé (le client affiche la version serveur).
- **Photos** : redimensionnées côté appareil (bord long 1600 px), ré-encodées en JPEG qualité 0,7
  par un canvas, ce qui retire l'EXIF ; refus au-delà de 2 Mo ; horodatage et position pris à la
  prise, envoyés comme champs (jamais dans l'image). Le serveur revalide type et taille comme
  `wr_plano_photo`.
- **Google** : rien de plus que ce que `/reputation` garde déjà (note, nombre, cinq derniers
  avis) ; les contenus ne sont pas recopiés dans l'historique au-delà de 30 jours.

## 10. Phase 1 : lots et estimation

| Lot | Contenu | Jours |
|---|---|---|
| A · Socle | `src/visites.php` (tables, agenda, portfolio, boutique, feu), page PWA, manifest, service worker, rôles par lien | 4 |
| B · Visite et checklist | Fiche, TB franchisé avec 4 KPI sourcés, photos du jour, checklist 5 modules (référentiel réglable), notes, photos, file hors-ligne | 6 |
| C · Review et plan d'action | Écarts, création du plan, statuts, vue franchisé, vue admin, escalade, historique 3 mois | 5 |
| D · MSP, équipe, notifications | Import MSP, relevé d'effectif, rappels J-1 / jour J, push + mail, cron | 3 |
| E · Synthèse et rail | Synthèse Sam (page + mail 7 h), entrée dans le rail du cockpit, contrat API, doc, tests | 3 |

Total : 21 jours de développement, plus le référentiel de checklist à écrire avec Sam.
Phase 2 (brief) : analytics, export, agenda mensuel enrichi, multi-langue, intégrations.

## 11. Questions à trancher avant de coder

1. **Référentiel de checklist** : qui écrit les points par module, avec quelle cible (note ≥ 4 ?) ;
   même liste pour toutes les boutiques ou variantes ?
2. **Mystery shopper** : format reçu (PDF, grille Excel, score par rubrique ?), fréquence, qui le
   téléverse (Sam, admin) ; l'app le lit, elle ne le produit pas.
3. **Planogramme** : la déviation en % se compte à la main sur la grille de référence, ou on se
   contente des chips de cause (déplacé, vide, surstock) et du feu ? Le comptage coûte du temps
   en boutique.
4. **Personnel** : effectif prévu par boutique et départs, saisis par qui ? Sans source RH le KPI
   reste déclaratif.
5. **Comptes** : le franchisé accède par un lien `?shop=` (comme le dashboard) ou par un
   identifiant ? Pour valider des corrections et être notifié, un code par boutique au minimum.
6. **Feu et CA** : garder la règle du brief (CA −5 à −10 % = orange) alors que toutes les
   boutiques sont aujourd'hui sous −14 % ? Proposition : le CA colore la carte, il ne déclenche
   le rouge qu'avec un autre signal.

Points secondaires : fréquence de visite par boutique (hebdo partout ?), durée de conservation
des photos (3 mois à l'écran, 12 mois en base ?), heures des rappels, langue (FR seul en phase 1).
