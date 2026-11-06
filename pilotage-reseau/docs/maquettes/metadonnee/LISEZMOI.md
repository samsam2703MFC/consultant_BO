# Maquettes « MétaDonnée » (28/10/2026)

Demande : un sous-menu du rail, **MétaDonnée**, qui fait pour les franchisés ce que le CRM
(`atelier-espace-candidat`, écran Leads) fait pour les candidats. Il lit les formulaires Meta
(Facebook et Instagram), laisse choisir les formulaires suivis et récupère leurs leads. Il place
chaque réponse dans un champ de l'ERP (atelierby.tfbuddy.com) et y envoie le lead. Il relit ensuite
l'ERP à intervalles réguliers pour voir si le franchisé l'a traité, et prévient le franchisé par SMS
à chaque nouveau lead : un bureau qui veut se faire livrer, et qui doit finir connecté au webshop.

**Choix du 28/10/2026 : la maquette A**, implémentée (`src/metadonnee.php`, `public/assets/js/metadonnee.js`) ;
les écrans réels, capturés contre le kit d'essai (`tools/metadonnee-essai/`), sont dans `ecrans/` :
`1-formulaires.png` (avec « Tester les connexions » ouvert), `2-mapping.png`, `2b-lead-essai.png`,
`3-suivi-leads.png` (un lead déplié), `4-alertes-sms.png`.

Les trois façons d'organiser le même module qui avaient été proposées :

| Fichier | Variante | Sous-menu | L'écran montré |
|---|---|---|---|
| `a-assistant.html` | A — assistant en 4 étapes | 1 · Formulaires · 2 · Mapping ERP · 3 · Suivi des leads · 4 · Alertes SMS | l'étape 2 : choix du formulaire, mapping champ par champ, ce qui partira (JSON + essai), la suite (cycle, SMS), correspondance des réponses, affectation au magasin |
| `b-flux.html` | B — le flux des leads | Leads · Formulaires et mapping · SMS et cycle | une ligne par lead de bout en bout (Meta → ERP → SMS → franchisé, avec les 6 points de la suite), le lead choisi à droite (son fil, Meta → ERP, gestes), le journal du cycle |
| `c-franchises.html` | C — contrôle par franchisé | Suivi franchisés · Réglages | une carte par magasin (leads, vus en 2 h, 1er contact, bureaux connectés, SMS), une colonne par état relu dans l'ERP, les réglages en quatre cartes |

Ce qui est commun aux trois :

- **Meta** : la lecture du CRM, déjà éprouvée (`meta_graph.php` : Graph API v25.0, webhook
  `leadgen`, jeton d'utilisateur système, permission `leads_retrieval`, rattrapage horaire sur
  3 jours, réparation des adresses e-mail). Un formulaire non suivi ne crée rien dans l'ERP.
  Mise en œuvre (29/10/2026) : le flux du CRM est recopié dans le Cockpit, avec les mêmes réglages ;
  l'adresse de rappel de l'application reste au CRM, et le Cockpit relève ses formulaires toutes les 2 min.
- **ERP** : deux routes à écrire avec l'équipe du panel (`docs/spec-api-panel.md`) :
  `POST /api/v1/consultant/leads`, idempotente sur le n° de lead Meta, et
  `GET /api/v1/consultant/leads?since=`, qui rend les statuts. Les statuts proposés sont
  nouveau · vu · contacté · bureau créé · bureau connecté · perdu.
- **Cycle** : le webhook à la seconde, l'envoi à l'ERP tout de suite, la relecture des statuts
  toutes les 10 min sur 30 jours et un filet Meta toutes les heures.
- **SMS** : par SMSAPI, comme le CRM, au gérant du magasin. Les envois partent entre 8:00 et 20:00
  (sinon à 8:00). Un lead qui n'est pas vu après 2 h déclenche une relance par SMS ; à 24 h, le
  consultant responsable est prévenu.

Données : magasins réels, entreprises, contacts et numéros fictifs.

Pour régénérer les captures : un serveur à la racine du dépôt (`php -S 127.0.0.1:8099 -t .`), puis
`node docs/maquettes/metadonnee/capturer.cjs` (ou le nom d'une maquette en argument).
