# Helpdesk des franchisés : les demandes au cockpit, reprises en visite, et le formulaire du dashboard

**Statut : maquettes, à valider.** Demande du 15/11/2026 : « dans le cockpit pilotage, il faut pouvoir lire les
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
