# Espace franchisé · Fidélité — la fidélité vue de la caisse

Demande du 12/11/2026 : une maquette du dashboard du franchisé, après la vérification de la fidélité à
Halle (sonde `tools/sonde_fidelite_halle.php`). L'écran répond à une seule question : **les ventes du
magasin sont-elles rattachées à des membres fidélité ?**

`index.html` (1440 × 1000, design system commun `docs/DESIGN_SYSTEM.md`), capture `fidelite-franchise.jpg`.

## Ce qu'on voit

1. **Le verdict** en une phrase, sous les onglets En caisse / Membres / Récompenses.
2. **Cinq chiffres** : tickets particuliers, tickets rattachés à un client, ventes au comptoir identifiées
   (sans commande derrière : la seule mesure de la fidélité), membres actifs, bons fidélité utilisés.
3. **Jour par jour** sur cinq semaines, la semaine en cours en couleur.
4. **D'où viennent les liens** : commandes webshop, commandes encaissées en caisse, ventes au comptoir.
5. **Semaine après semaine**, et **le réseau** cette semaine (le magasin du franchisé surligné).
6. **Trois étapes** pour que la fidélité compte en caisse, avec leur état (bloquant, à suivre, conseil).

## Les chiffres sont réels

L'Atelier by Halle (magasin 4), lus le 12/11/2026 à 10 h par l'API du panel, sans aucune table locale :

| Lecture | Route |
|---|---|
| Tickets du jour : `id_client`, `is_client_b2b`, `source` (pos / webshop), `order_ref`, `id_voucher`, montant | `/shops/{id}/transactions?date=` |
| Fiche client : `fidelity_active`, `fidelity_linked_at`, `member_since`, `loyalty_scan_code`, `source_channel` | `/shops/{id}/clients`, `/shops/{id}/clients/{id}`, `/clients?type=b2c` |

« Comptoir » : ticket de caisse rattaché à un client, ni webshop ni `order_ref`.

## À trancher

- L'objectif de l'étape 2 (1 vente au comptoir sur 10) est une proposition.
- « Inscrire un client » suppose une route d'activation de la fidélité : le Swagger du panel n'en a pas
  aujourd'hui (aucune route `loyalty` / `fidelity`). À créer côté ERP, ou à renvoyer vers l'app.
- Les onglets Membres et Récompenses ne sont pas dessinés : ils n'auront rien à montrer tant qu'aucun
  client n'a la fidélité active.
