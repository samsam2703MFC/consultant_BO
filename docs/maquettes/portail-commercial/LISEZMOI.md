# Maquettes du portail Commercial (19/11/2026)

Demande : un nouveau portail pour le commercial B2B (tables `sm_`), au design system de la marque. Deux sections :

- **Bureaux · webshop** : les leads Meta par magasin, l'assistant qui crée le bureau, les offres, les bons et les catalogues
  envoyés depuis hello@atelierby.be, les échantillons commandés aux magasins, le tableau de bord des bureaux.
- **B2B** : le suivi des offres par secteur et par magasin.

À valider avant tout code.

| Écran | Ce qu'il montre |
|---|---|
| `1-tableau-de-bord` | Les bureaux du webshop : bureaux actifs, nouveaux et anciens clients, commandes, CA, cartes de fidélité office, bons utilisés, par magasin ; les commandes par semaine ; les bureaux à suivre. |
| `2-leads-meta` | Les leads Meta par magasin (à traiter, contactés, bureaux créés, connectés, perdus), la liste des leads avec « Créer le bureau ». |
| `3-nouveau-bureau` | L'assistant en cinq étapes (entreprise, magasin et tournée, compte webshop, bon et carte office, e-mail de bienvenue), à la dernière étape : le récapitulatif et l'e-mail depuis hello@atelierby.be. |
| `4-offres-bons-catalogues` | Les offres par magasin (types d'offre de Prospection, un prix par magasin), les bons et leur usage, les catalogues des magasins à envoyer. |
| `5-echantillons` | Commander un échantillon au magasin de la zone, par le système de commande ; le suivi jusqu'à la conversion. |
| `6-suivi-offres-b2b` | Les offres par secteur (lieux ciblés, contactés, offres, échantillons, clients, CA) et les clients par secteur et par magasin. |

**Réel** : les quatre magasins et leur zone (annexe de la feuille de mission), les statuts des leads de l'ERP, les types
d'offre de Prospection, la boîte hello@atelierby.be. **Illustré** : entreprises, contacts, montants et compteurs.

## À décider ou à obtenir avant le code

1. **Le préfixe `sm_`** : un cinquième domaine (dossier, registre `shared/src/reorg_tables.php`, `CLAUDE.md` racine et
   `docs/PORTAILS.md` à mettre à jour), ou un sous-préfixe d'un domaine existant.
2. **Créer le bureau** : les routes TF Buddy à définir avec Szymon. Il faut créer le bureau au webshop, le rattacher à
   une tournée et passer le lead à « bureau créé ».
3. **Échantillons** : la commande à 0 € marquée « échantillon » dans le système de commande, route à confirmer.
4. **E-mails** : l'accès SMTP de hello@atelierby.be dans `erp_cles`. Les modèles à écrire : bienvenue bureau, offre,
   catalogue, échantillon, relance ; en français et en néerlandais.
5. **Cartes office** : le programme de fidélité doit porter une carte « office » par bureau. Elle est illustrée ici.
6. **Lectures** : leads Meta et liste de Prospection par l'API du Marketing ; commandes, clients pro et bons par celle du
   panel. Le portail ne lit aucune table d'un autre portail.

    python3 docs/maquettes/portail-commercial/generer.py
    node docs/maquettes/portail-commercial/capturer.cjs
