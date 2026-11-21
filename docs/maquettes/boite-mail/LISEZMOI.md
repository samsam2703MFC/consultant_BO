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

**Réel** : le magasin et son adresse, les dossiers d'une boîte OVH. **Illustré** : expéditeurs, objets, propositions de l'IA.

## Côté serveur

- `panel/src/boite_protocole.php` : les rôles des dossiers reconnus aussi en néerlandais et en allemand (Prullenbak,
  Verzonden, Archief…), la création d'un dossier (`CREATE`, SPECIAL-USE si le serveur le sait, sous le préfixe
  `INBOX.` quand la boîte l'impose, puis `SUBSCRIBE`), l'effacement définitif, les UID par lots et en plages.
- `panel/src/boite_mail.php` : `geste` accepte tout le dossier (`tout: {q, filtre}`), `effacer` (depuis la corbeille
  ou les indésirables seulement) et `deplacer` vers un `nouveau` dossier ; les archives, la corbeille et « Envoyés »
  sont créées quand elles manquent. `POST /dashboard/boite/ranger` : la proposition de l'IA (50 e-mails au plus ; ce
  qui part au modèle : l'expéditeur, l'objet, la date, les drapeaux et les 300 premiers caractères du texte).
- Testé contre un vrai serveur IMAP (Dovecot), sur une boîte qui n'a que sa réception : `panel/bin/tests_boite_mail.php`.

    node docs/maquettes/boite-mail/capturer.cjs
