# Boîte mail : sélection, gestes groupés, rangement par l'IA (21/11/2026)

Demande : « La boîte n'a pas de dossier d'archives » (et pas de corbeille) ; ajouter « tout sélectionner », effacer,
tout archiver, mettre en dossier avec l'aide de l'IA ; à la place des listes déroulantes de dossiers, des boutons
rectangulaires à coins arrondis avec une micro-icône.

Ces captures montrent le **vrai module** (`panel/public/dashboard/boite.js`, `boite.css`), branché par `maquette.html`
sur une fausse API et des e-mails illustrés. `capturer.cjs` clique comme le ferait un magasin.

| Écran | Ce qu'il montre |
|---|---|
| `1-archives-creees` | Une boîte qui n'avait pas de dossier d'archives : « Archiver » le crée dans la boîte (nommé `Archive` comme dans le webmail d'OVH, abonné), puis range. Les dossiers sont des boutons à micro-icône. |
| `2-selection` | Six e-mails cochés : à droite, les gestes (archiver, corbeille, lu, non lu, suivre) et « Déplacer vers » en boutons, avec « Nouveau dossier ». |
| `3-ranger-avec-ia` | « Tout sélectionner », puis « Proposer un rangement » : l'IA propose un dossier par e-mail (dossiers existants, nouveaux dossiers, archives, ou laisser en place ce qui attend une réponse). Chaque ligne se corrige (« Changer » ouvre les dossiers en boutons). Rien ne bouge avant « Ranger ». |
| `4-corbeille-tout-effacer` | La corbeille entière sélectionnée : « Effacer pour de bon », « Remettre en Réception ». |
| `5-mode-briefing` | La bascule « IA » (Classique · Briefing · Pilote · Radar) ; le briefing : les non-lus en cartes, la réponse rédigée (modifiable sur place, « [à compléter] » bloque l'envoi), Valider → / Plus tard ←, « Mains dans la farine » (le navigateur lit la carte et écoute « oui », « plus tard », « ouvre », « stop »), la file. |
| `6-mode-pilote` | Le pilote : trois crans (Éteint, Brouillons, Simples), le fil de ce qu'il a fait (« Annuler l'envoi » tant que la réponse n'est pas partie), ce qui vous attend et pourquoi, les garde-fous fixes. |
| `7-mode-radar` | Le radar : les demandes lues dans les e-mails des trois dernières semaines, sur les trois semaines à venir ; les montants estimés aux prix du catalogue ; les alertes ; l'humeur des clients. |

## Les modes IA (21/11/2026, « les 3 options futuristes en toggle, avec un bouton type futuriste »)

`panel/src/boite_ia.php`. Chaque e-mail de la réception est lu une fois par l'IA et la lecture est gardée
(`panel_boite_lecture`) : résumé, catégorie, ton, urgence, action, brouillon de réponse, demande repérée, et ce qui
interdit de répondre seul. Le pilote (`panel_boite_pilote`, `panel_boite_pilote_etat`) tourne avec l'horloge de la
boîte (10 min), éteint par défaut, et ne lit que ce qui arrive après son allumage. Il ne répond seul qu'à une réponse
« simple », sans « [à compléter] », à 85 % de confiance au moins, une fois par expéditeur et par jour, 30 par jour au
plus, jamais aux prix, remises, réclamations, allergènes, commandes, professionnels ni au personnel ; l'envoi part au
plus tôt 10 minutes après, annulable. Le reste devient un brouillon dans « Brouillons » ou attend le magasin.
Testé de bout en bout (vraie base, Dovecot, faux SMTP, faux modèle) : `panel/bin/tests_boite_pilote.php`.

**Réel** : le magasin et son adresse, les dossiers d'une boîte OVH. **Illustré** : expéditeurs, objets, propositions de l'IA.

## Côté serveur

- `panel/src/boite_protocole.php` : les rôles des dossiers reconnus aussi en néerlandais et en allemand (Prullenbak,
  Verzonden, Archief…), la création d'un dossier (`CREATE`, SPECIAL-USE si le serveur le sait, sous le préfixe
  `INBOX.` quand la boîte l'impose — ou à côté des dossiers de rôle qu'elle a déjà, « INBOX.INBOX.Trash » →
  « INBOX.INBOX.Archive » —, puis `SUBSCRIBE`), l'effacement définitif, les UID par lots et en plages.
- `panel/src/boite_mail.php` : `geste` accepte tout le dossier (`tout: {q, filtre}`), `effacer` (depuis la corbeille
  ou les indésirables seulement) et `deplacer` vers un `nouveau` dossier ; les archives, la corbeille, « Envoyés »
  et « Brouillons » (pour les brouillons du pilote) sont créés quand ils manquent. `POST /dashboard/boite/ranger` : la proposition de l'IA (50 e-mails au plus ; ce
  qui part au modèle : l'expéditeur, l'objet, la date, les drapeaux et les 300 premiers caractères du texte).
- Testé contre un vrai serveur IMAP (Dovecot), sur une boîte qui n'a que sa réception : `panel/bin/tests_boite_mail.php`.

    node docs/maquettes/boite-mail/capturer.cjs
