# Suivi des franchisés (24/10/2026)

Le contrôle des franchisés sur le terrain, par tablette, réuni dans le rail du cockpit : **Franchisés · évaluation et
suivi › Suivi des franchisés**. Sur l'ordinateur, le bureau prépare les visites, adapte et envoie les rapports ; la
visite elle-même se fait sur la tablette ; le franchisé lit ses rapports et envoie ses corrections sur sa page.
Maquette d'origine : `docs/maquettes/suivi-franchises/`. Routes : `docs/contrat-api.md`, « Le suivi des franchisés ».

## Le circuit d'une visite

| Temps | Où | Ce qui se passe |
|---|---|---|
| 1. Préparer | cockpit, Tableau de bord › Préparer | le dossier s'assemble tout seul (santé du magasin, points ouverts, tâches et contrôles photo, scoring, client mystère et comptoir, dernière météo en interne) ; le bureau coche ce qui part, choisit le type, annoncée ou inopinée, ajoute ses points (ils ouvrent la checklist) et « Envoie sur la tablette » |
| 2. Ordre du jour | courriel | visite **annoncée** : il part tout seul à J−2 (réglable), dans la langue du magasin, avec les points cochés « ordre du jour ». Visite **inopinée** : rien ne part et le franchisé ne la voit pas sur sa page |
| 3. Visiter | tablette, `visites/?role=consultant` | photo du jour, dossier du bureau, chiffres et alertes, contrôles photo, modules de la checklist (« Demandé par le bureau » en tête), checklist du consultant, vu sur place, points à travailler, météo du franchisé (interne), fin de visite et signature. Hors ligne, tout attend dans la file |
| 4. Brouillon | automatique | « Terminer » envoie le brouillon au bureau ; les points sont **proposés**, le franchisé ne voit encore rien |
| 5. Adapter et envoyer | cockpit, Tableau de bord › Adapter et envoyer | le bureau relit, corrige, choisit les points et les photos qui partent (les autres restent **internes**), voit l'aperçu, envoie : courriel avec copies, PDF joint quand le moteur PDF répond, notification, page du franchisé. Délai : 48 h après la visite (réglable) ; dépassé, un rappel au bureau ou l'envoi tel quel |
| 6. Corriger | page du franchisé, puis Points et corrections | le franchisé envoie la photo de sa correction ; le bureau l'accepte ou la renvoie « à reprendre » avec un mot et un nouveau délai ; le consultant ferme le point sur place |

Un changement de date ou une annulation d'une visite annoncée dont l'ordre du jour est parti envoie le courriel
« visite déplacée » ou « visite annulée ».

## Les cinq écrans

| Écran | Adresse | Pour quoi |
|---|---|---|
| Tableau de bord | `#/suivi-tableau` | vue d'ensemble (les visites en quatre colonnes, le tableau des franchisés), Préparer, Adapter et envoyer, Points et corrections. « Planifier une visite » ouvre Gestion consultant |
| Visite sur tablette | `#/suivi-tablette` | l'application du consultant choisi, son lien d'installation, le circuit |
| Client mystère | `#/suivi-client-mystere` | les passages magasin par magasin, l'encodage d'un passage et ce qui le suit (points, contrôle à la visite suivante, tâche au responsable) |
| Page du franchisé | `#/suivi-page-franchise` | l'aperçu de sa page, son lien (copier, envoyer), ce qu'il voit et ne voit pas |
| Qui fait quoi | `#/suivi-qui-fait-quoi` | par magasin : qui prépare, adapte, envoie, valide les corrections, qui est en copie, l'adresse et la langue du franchisé ; les types annoncés ou inopinés ; les personnes et leurs droits ; les délais ; ce que voit le franchisé ; les courriels dans les quatre langues (aperçu, essai, remettre le texte livré) |

Le sous-menu **Budget** (Suivi budget, Encodage du budget, Paramètres du budget) a quitté Magasins. Le sous-menu
« Suivi de terrain » est retiré : Gestion consultant reste atteignable par le tableau de bord (planifier) et par le
cadre de visite ; l'app Visites est la Visite sur tablette ; le client mystère a son écran ; la conformité du comptoir
est dans le dossier de visite et dans Catalogue › Comptoir.

## Annoncée ou inopinée

Chaque type de visite le porte (Qui fait quoi, ou Gestion consultant › Cadre de visite) : régulière, production,
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
