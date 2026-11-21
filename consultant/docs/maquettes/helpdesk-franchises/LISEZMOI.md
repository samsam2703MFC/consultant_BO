# Helpdesk des franchisés : les demandes au cockpit, reprises en visite, et le formulaire du dashboard

**Statut : validées le 15/11/2026 (« ok tout »), et codées** — cockpit `#/franchises/demandes` (Franchisés › Demandes des franchisés), dashboard franchisé : la demande au bureau dans la fenêtre de l’assistant (son avatar la transmet), ouverte depuis l’onglet Contrôle ou l’entête ; serveur `src/helpdesk.php`. Demande du 15/11/2026 : « dans le cockpit pilotage, il faut pouvoir lire les
demandes et les reprendre dans les missions de visite (contrôle) + faire le formulaire dans le dashboard franchisé ».

| Capture | Écran | Ce qu'il montre |
|---|---|---|
| `a.png` | Cockpit › Franchisés › **Demandes des franchisés** | les demandes ouvertes du helpdesk du panel (statut, domaine, magasin, âge, réponse ou non), les filtres, et la fiche d'une demande : statut, consultant, **Reprendre dans une visite** (visite, quand, ce qu'il faut vérifier), **Répondre au franchisé** |
| `b.png` | Cockpit › **la visite** du magasin | la demande devenue mission : une tâche pendant (contrôler) et après (répondre), et un module « Demandes du franchisé » de la liste de contrôle (conforme, à suivre, non conforme, constat, photo) ; à la clôture, le constat repart en réponse |
| `c.png` | Dashboard franchisé (téléphone) › Contrôle › **Une demande au bureau** | le bouton à côté de la réclamation fournisseur, le formulaire (sujet, titre, demande, pièces, passage du consultant), « Mes demandes » avec le statut et la réponse du bureau |

## Données (lues le 15/11/2026, lecture seule)

- `GET /cases` (compte administrateur du cockpit) : **24 demandes** — 11 nouvelles, 8 en cours, 3 résolues, 1 fermée,
  1 annulée ; 17 des 19 ouvertes sans réponse du bureau ; la plus ancienne ouverte depuis le 11/05. Le compte consultant
  ne lit que celles d'un magasin (`GET /shops/{id}/cases`).
- `GET /consultant/tasks/helpdesk` : les six domaines (stratégie, produit, activités opérationnelles, ventes,
  application, réseaux sociaux).
- Les visites et leurs tâches : `GET /consultants/gestion` du cockpit (visite régulière de Sombreffe, 26/10, Loic V.).
- Les notifications aux franchisés (`/franchisee-notifications`) répondent 500 au panel : hors de ces maquettes.

Les courriels des demandeurs ne sont pas repris. La demande « tarte 22 Ø » du formulaire est un exemple.

## Écriture (contrat du swagger test.tfbuddy.com)

`POST /cases {area_key, title, description, shop_id}`, `POST /cases/{id}/attachments`, `PATCH /cases/{id}/reply
{admin_reply}`, `PATCH /cases/{id}/status {status}`, `PATCH /cases/{id}/consultant {consultant_id}`. La mission de visite
vit au cockpit (`con_consultant_tache`, source `helpdesk`, liée à la visite).

Générer : `node generer.js` ; capturer (serveur `php -S 127.0.0.1:8099 -t .` depuis `pilotage-reseau/`) : `node capturer.js`.

## Captures du code (15/11/2026)

Le dashboard franchisé tel qu'il est codé, avec les demandes de Sombreffe lues en ligne (envois simulés, rien
n'est écrit) : `code-dashboard-bureau.png` (la fenêtre de l'assistant, mode « Demande au bureau », Mr. Pierre),
`code-dashboard-tel-controle.png` (l'onglet Contrôle et son bouton), `code-dashboard-tel-formulaire.png`,
`code-dashboard-tel-mes-demandes.png`.

## Suites du 20/11/2026 (demandes de Sam)

- **Le SMS au bureau** (« fais en sorte que je reçoive un SMS et Pierre Boseret avec un résumé de la demande quand un
  franchisé en fait une ») : à chaque demande envoyée depuis le dashboard, un SMS part à chaque numéro du réglage
  `helpdeskSms`. Il contient le magasin, le sujet, le demandeur, le titre, les 220 premiers caractères de la demande, le
  passage souhaité, le nombre de photos et la référence, sans lien (« ne pas mettre le lien dans le SMS »). Le texte est ramené à
  l'alphabet SMS (160 caractères par SMS, pas 70). L'envoi passe par le fournisseur en place (`shared/src/sms.php`).
  Les numéros se règlent au cockpit, bloc « SMS au bureau » sous la liste (`GET` / `PUT /helpdesk/sms`). Au départ :
  Sam (+48725038049), et Pierre Boseret, dont le numéro reste à compléter. Un SMS qui ne part pas n'empêche jamais
  la demande : le journal et `helpdeskSmsDernier` disent à qui il est parti.
- **La date limite** (« lors de la réception, le consultant met une date limite 2, 5, 10, 30 j, +90 qu'il est censé
  respecter ») : sur la fiche d'une demande, 2, 5, 10, 30 ou 90 jours à partir du jour même
  (`POST /helpdesk/demandes/{id}/delai`, `con_hdk_demande.echeance`). Une demande « Nouvelle » passe alors « En cours ».
  Le cockpit montre la colonne Date limite, l'indicateur « En retard » et les filtres « En retard » et « Sans date
  limite ». Le franchisé voit « réponse prévue d'ici le … ».
- **Les réclamations fournisseur de tous les magasins** (« dans le cockpit consultant, mettre la vue sur toutes les
  réclamations de tous les magasins », ici) : second onglet de l'écran, en lecture seule (`GET /fournisseurs/reclamations?mois=`).
  On y trouve les indicateurs (ouvertes, montant ouvert, plus ancienne, réglées, refusées), les filtres (état, magasin,
  fournisseur, période, recherche) et la fiche de chaque réclamation.

- **Les relances** (« renvoyer un SMS à Sam ou Pierre tous les 7 jours ») : maquette `../helpdesk-relances`, validée et
  codée le 20/11/2026. Ce dossier en décrit la règle. Le 21/11 : relance tant que la demande n'est pas en cours, et la
  carte « Demandes à traiter » sur l'accueil du cockpit (écran Résultat).

Banc : `php consultant/bin/tests_helpdesk.php` (SMS, date limite, relances ; SQLite en mémoire, doublures du panel et de SMSAPI).
