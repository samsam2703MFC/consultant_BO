# Suivi des franchisés (24/10/2026)

Le contrôle des franchisés sur le terrain, par tablette, réuni dans le rail du cockpit : **Franchisés · évaluation et
suivi › Suivi des franchisés**. Sur l'ordinateur, le bureau prépare les visites, adapte et envoie les rapports ; la
visite elle-même se fait sur la tablette ; le franchisé lit ses rapports et envoie ses corrections sur sa page.
Maquette d'origine : `docs/maquettes/suivi-franchises/`. Routes : `docs/contrat-api.md`, « Le suivi des franchisés ».

## Le circuit d'une visite

| Temps | Où | Ce qui se passe |
|---|---|---|
| 1. Préparer | cockpit, Visites (l'assistant : étapes 1 à 4) | le dossier s'assemble tout seul (santé du magasin, points ouverts, tâches et contrôles photo, scoring, client mystère et comptoir, dernière météo en interne) ; le bureau coche ce qui part, choisit le type, annoncée ou inopinée, ajoute ses points (ils ouvrent la checklist) et « Envoie sur la tablette » |
| 2. Ordre du jour | courriel | visite **annoncée** : il part tout seul à J−2 (réglable), dans la langue du magasin, avec les points cochés « ordre du jour ». Visite **inopinée** : rien ne part et le franchisé ne la voit pas sur sa page |
| 3. Visiter | tablette, `controle/#controle/{id}` (l'app Visites en mode tablette ; le QR code de la fiche de visite, dans Visites, y mène directement — une visite pas encore démarrée l'annonce en tête, « ▶ Démarrer la visite » d'un toucher) | **la visite en six temps** (26/10/2026, ordre validé par Sam : on fait le tour debout, on vérifie sur place ce qui avait été décidé, puis on s'assoit avec le franchisé pour les chiffres, qui mènent aux décisions). La barre du bas montre les six temps (étapes faites sur le total, « ! » quand quelque chose bloque) ; en tête, les étapes du temps en cours en assistant horizontal (numérotées, reliées, la faite en vert). En bas à droite, « Suivant » : tant que l'étape n'est pas remplie, il refuse et dit ce qui reste (réglage « Suivant bloqué ») ; les photos, la checklist et les contrôles du jour passent d'eux-mêmes à l'étape suivante une seconde après le dernier point (réglage « passe d'elle-même », un toucher annule) ; les étapes à lire ne bloquent jamais, la barre du bas et les étapes en tête restent libres. Réglages › Contrôle guidé : le sens du contrôle (l'ordre des six temps, par glisser-déposer ou ↑ ↓, réglage `ordreTemps`) et, étape par étape, bloquante oui ou non (`etapesBloquantes`) — une étape bloquante pas remplie arrête tout ce qui la suit, y compris la barre du bas et les étapes en tête ; revenir en arrière reste libre. Le module Produit liste les obligatoires du magasin une par une, Oui / Non (avec « vue en caisse »), et « Toutes les autres sont là ». **1 · Extérieur** : la façade et l'arrière en photo, « L'extérieur » (les points de checklist qui se regardent dehors : façade, enseigne, terrasse…). **2 · Intérieur** : la photo de l'intérieur, l'expérience client (présente, cuisson, accueil), les modules de la checklist (Produit qui commence par « Les produits obligatoires sont là », Hygiène, Visuel, Planogramme, Assortiment…), les contrôles en photo du jour. **3 · Suivi de projet** : le dossier du bureau (seulement s'il a été envoyé), « Demandé par le bureau », les points décidés aux visites précédentes encore ouverts (le plus en retard d'abord ; photo de la correction, confirmer, escalader), les non-conformes d'une revisite, la checklist du consultant. **4 · Analyse**, assis avec le franchisé : KPI et santé du magasin (les quatre chiffres clés, le CA face au budget en cinq onglets — jour, semaine, mois, trimestre, année, arrêtés à la veille —, chacun avec son mini P&L (CA, coût matière, marge brute, invendus, personnel, frais généraux, résultat net face aux seuils de la Table KPI), le CA par ETP, l'année face à l'an passé, la structure des ventes et la structure du CA par canal — comptoir, B2B face à son plafond de 40 %, click & collect, livraison bureau —, le comptoir et les alertes ; `GET /visites/kpi/{shop}`, `GET /visites/budget/{shop}`), les six leviers, le trafic et Google, les tâches des derniers mois (faites sur demandées, les tâches notées sous 4 avec leur photo et les repères du contrôleur ; `GET /visites/taches/{shop}`), le client mystère. **5 · Développement** : le CA sous le budget — plus de 10 % sous l'attendu (réglage `caOrange`), le consultant note avec le franchisé **pourquoi**, le franchisé donne **trois pistes** et l'horizon (le mois prochain ou la fin du trimestre) ; l'étape reste marquée et « Terminer » y ramène tant que ce n'est pas noté ; les pistes deviennent des points à travailler à cette échéance (`budgetCa` sur la visite) —, vu sur place et recommandation, points à travailler. **6 · Météo et fin** : la météo du franchisé (le temps en un toucher, puis le détail facultatif ; interne), les deux signatures (le consultant et le franchisé, côte à côte dans le rapport), terminer. Hors ligne, tout attend dans la file |
| 4. Brouillon | automatique | « Terminer » envoie le brouillon au bureau ; les points sont **proposés**, le franchisé ne voit encore rien |
| 5. Adapter et envoyer | cockpit, Visites (l'assistant : étape 5) | le bureau relit, corrige, choisit les points et les photos qui partent (les autres restent **internes**), voit l'aperçu, envoie : courriel avec copies, PDF joint quand le moteur PDF répond, notification, page du franchisé. Délai : 48 h après la visite (réglable) ; dépassé, un rappel au bureau ou l'envoi tel quel |
| 6. Corriger | page du franchisé, puis Points et corrections | le franchisé envoie la photo de sa correction ; le bureau l'accepte ou la renvoie « à reprendre » avec un mot et un nouveau délai ; le consultant ferme le point sur place |

Un changement de date ou une annulation d'une visite annoncée dont l'ordre du jour est parti envoie le courriel
« visite déplacée » ou « visite annulée ».

## Les cinq écrans

| Écran | Adresse | Pour quoi |
|---|---|---|
| Visites | `#/franchises` | l'assistant (26/10/2026, maquette C de `docs/maquettes/franchises-wizard/`), une seule page, sans tableau de bord : on arrive sur « Créer une visite ». À gauche, les étapes en accordéon — avant la visite (1 le magasin, 2 la visite : type, annoncée ou inopinée, date, heure, durée, consultant ; la visite est créée au bout de l'étape 2), 3 le dossier (blocs, demandes au consultant en ordre du jour ou internes, son mot), 4 l'envoi (tablette, ordre du jour maintenant ou tout seul) ; pendant (la tablette) ; après (5 le rapport, 6 les corrections et les points ouverts du magasin). Une étape faite se replie sur une ligne et se rouvre d'un clic (« Modifier » à l'étape 2 change le jour, le consultant, le type : PUT /visites/{id}). À droite, la fiche de la visite se remplit à chaque choix : qui reçoit quoi (consultant, franchisé, bureau seulement), l'ordre du jour tel qu'il partira (les demandes surlignées), la suite. Chaque visite qui attend le bureau a son onglet : rapport à adapter, corrections, dossier à finir ou en retard, visite pas encore faite ou en cours. La fiche porte le **QR code de la visite** (n° unique) : scanné avec la tablette, il ouvre directement son contrôle (`controle/#controle/{id}`, « Copier le lien » à côté ; généré dans la page par `assets/vendor/qrcode`, MIT). L'onglet **Points décidés** suit tout ce qui a été envoyé au franchisé et n'est pas fermé (ouvert, à reprendre, escaladé, photo reçue), groupé par échéance — en retard, d'ici dimanche, d'ici la fin du mois, plus tard —, filtré par magasin, « En retard », « D'ici dimanche » ou « Pistes CA » (les pistes de développement du CA sous le budget) ; une ligne ouvre sa visite à l'étape des corrections. L'étape 5 montre aussi « Le chiffre face au budget » tel que noté à la tablette |
| Visite sur tablette | `#/franchises/tablette` | l'application `controle/` du consultant choisi, son lien d'installation, le circuit |
| Client mystère | `#/franchises/mystere` | les passages magasin par magasin, l'encodage d'un passage et ce qui le suit (points, contrôle à la visite suivante, tâche au responsable) |
| Page du franchisé | `#/franchises/page` | l'aperçu de sa page, son lien (copier, envoyer), ce qu'il voit et ne voit pas |
| Qui fait quoi | `#/franchises/quifaitquoi` | par magasin : qui prépare, adapte, envoie, valide les corrections, qui est en copie, l'adresse et la langue du franchisé ; les types annoncés ou inopinés ; les personnes et leurs droits ; les délais ; ce que voit le franchisé ; les courriels dans les quatre langues (aperçu, essai, remettre le texte livré) |

Le sous-menu **Budget** (Suivi budget, Encodage du budget, Paramètres du budget) a quitté Magasins. Le sous-menu
« Suivi de terrain » est retiré : la conformité du comptoir est dans Produits › Comptoir · planogramme. Gestion
consultant ayant quitté la console le 18/10/2026, la planification se fait dans l'assistant Visites et le cadre de visite
de chaque magasin se lit dans Qui fait quoi. Paramètres › Client mystère encode dans la même table
(`ceo_scoring_msp`) ; l'écran Client mystère du suivi y ajoute les suites (points, contrôle, tâche).

## Annoncée ou inopinée

Chaque type de visite le porte (Qui fait quoi) : régulière, production,
bilan, ouverture et suivi de plan d'action sont annoncés ; hygiène et qualité, client mystère et revisite sont
inopinés. Le bureau peut changer une visite précise en la préparant. Une inopinée n'envoie aucun courriel, n'apparaît
ni sur la page du franchisé ni dans son application, et le rappel de la veille ne part pas.

## Les courriels

Six modèles, chacun en français, néerlandais, anglais et allemand : annonce de visite, visite déplacée, visite
annulée, rapport de visite, relance (rapport pas lu après 3 jours), lien de la page. La langue suit le magasin
(Qui fait quoi), sinon sa région : Flandre en néerlandais, Cantons de l'Est en allemand. Dans un texte, une ligne vide
sépare les paragraphes ; `{sujets}` et `{points}` seuls sur leur ligne deviennent une liste (vide, elle dit pourquoi) ;
une variable vide seule sur sa ligne disparaît. Sans SMTP configuré, rien ne part : le rapport reste sur la page.

## La page du franchisé

`suivi/?shop=&jeton=` : sans mot de passe, le jeton est propre au magasin (changer le réglage `visitesJeton` invalide
tous les liens). C'est la seule page du franchisé : elle reprend l'ancienne « Mon suivi » (`dashboard/suivi.html`).
Elle parle la langue du magasin et montre, selon les réglages de Qui fait quoi :

- sa prochaine visite annoncée et ses sujets ;
- ses points à travailler, avec l'envoi de la photo de correction (réduite à 1600 px avant l'envoi) ;
- **à surveiller** : obligatoires manquées, contrôles majeurs ou critiques, infractions mineures, invendus au-dessus
  de la cible, réclamations sans réponse, avis faibles ;
- **chaque jour** : tâches sur 30 jours (jour par jour), notes du consultant, infractions, invendus, réclamations
  fournisseur, objectifs, remarques à l'équipe, note Google, CA de la semaine, comptoir — chaque tuile se déplie,
  « Tout voir » ouvre la liste complète. Les lectures sont celles de la fiche franchisé, gardées cinq minutes ;
- ses rapports de visite (marqués lus à l'ouverture), ses passages client mystère montrés, son scoring ;
- le journal des 60 jours, sans ce qui reste interne (points proposés ou gardés au bureau, rapports encore en
  brouillon, passages client mystère non montrés).

`&apercu=1` est la vue du bureau : rien n'est envoyé ni marqué lu. `dashboard/suivi.html?shop=` (le bouton « La page
du franchisé » de la fiche franchisé, les anciens signets) redirige vers cet aperçu quand une session du cockpit est
ouverte ; sans session, elle dit au franchisé de demander son lien personnel.

## Vérifier

- `php bin/tests_suivi.php` : rendu des courriels, modèles des quatre langues, dates longues, notes du client
  mystère, langue d'un magasin, jeton (sans base).
- Le parcours complet se rejoue sur une base locale : planifier, préparer, ordre du jour, visite sur tablette,
  brouillon, adapter, envoyer, correction du franchisé, validation, client mystère et ses suites, horloge.
