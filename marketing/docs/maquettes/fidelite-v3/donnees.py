# Données réelles de L'Atelier by Halle, lues le 12/11/2026 par tools/sonde_profils_halle.php (API du panel, aucune
# table locale). Les clients sont anonymisés : un rang, jamais un identifiant ni un nom.

# Les profils de fréquence (passages par mois sur les 90 derniers jours, du 14/08 au 11/11/2026).
PROFILS = [
    # clé, nom proposé, condition, équivalent mensuel, seuil (passages par mois), couleur, clients, tickets, €, en commande
    ('fanbase', 'Fanbase', '3 fois par semaine', '12 passages par mois ou plus', 12, '#8D1D2C', 0, 0, 0.0, 0),
    ('2sem', 'Fidèle', '2 fois par semaine', '8 à 11 passages par mois', 8, '#B03048', 0, 0, 0.0, 0),
    ('1sem', 'Hebdo', '1 fois par semaine', '4 à 7 passages par mois', 4, '#B0792A', 1, 16, 145.00, 15),
    ('2a3mois', 'Régulier', '2 à 3 fois par mois', '2 à 3 passages par mois', 2, '#3f7a52', 1, 7, 161.00, 3),
    ('1mois', 'Occasionnel', '1 fois par mois', '1 passage par mois', 1, '#1B6FA8', 4, 12, 408.54, 6),
    ('moins', 'Découverte', 'moins d’1 fois par mois', 'moins d’1 passage par mois', 0, '#9a948b', 203, 218, 10182.26, 80),
]
SCANNES = 209
TICKETS_PART = 13173
TICKETS_IDENT = 253

# Moyenne des 209 clients scannés (radars), pondérée par profil — calculée sur la sortie de la sonde.
MOY = {'comp': dict(frequence=10, recence=58, anciennete=4, panier=64, diversite=27, comptoir=63),
       'fam': dict(pain=26, vienno=18, antigaspi=5, tartes=38, gouter=8, sale=5)}

# Les six clients qui reviennent (rang de la sonde → lettre).
CLIENTS = [
    dict(n='Client A', profil='1sem', passages=16, tk=16, eur=145.00, cmd=15, rec=0,
         comp=dict(frequence=100, recence=100, anciennete=57, panier=15, diversite=67, comptoir=6),
         fam=dict(pain=78, vienno=10, antigaspi=0, tartes=10, gouter=2, sale=0)),
    dict(n='Client B', profil='2a3mois', passages=7, tk=7, eur=161.00, cmd=3, rec=0,
         comp=dict(frequence=58, recence=100, anciennete=100, panier=38, diversite=33, comptoir=57),
         fam=dict(pain=72, vienno=28, antigaspi=0, tartes=0, gouter=0, sale=0)),
    dict(n='Client C', profil='1mois', passages=3, tk=3, eur=131.10, cmd=1, rec=3,
         comp=dict(frequence=25, recence=97, anciennete=91, panier=73, diversite=100, comptoir=67),
         fam=dict(pain=56, vienno=7, antigaspi=20, tartes=5, gouter=2, sale=10)),
    dict(n='Client D', profil='1mois', passages=3, tk=3, eur=114.20, cmd=1, rec=8,
         comp=dict(frequence=25, recence=91, anciennete=57, panier=63, diversite=17, comptoir=67),
         fam=dict(pain=0, vienno=0, antigaspi=0, tartes=100, gouter=0, sale=0)),
    dict(n='Client E', profil='1mois', passages=3, tk=3, eur=107.50, cmd=1, rec=14,
         comp=dict(frequence=25, recence=84, anciennete=73, panier=60, diversite=33, comptoir=67),
         fam=dict(pain=0, vienno=0, antigaspi=0, tartes=20, gouter=0, sale=80)),
    dict(n='Client F', profil='1mois', passages=3, tk=3, eur=55.74, cmd=3, rec=2,
         comp=dict(frequence=25, recence=98, anciennete=11, panier=31, diversite=67, comptoir=0),
         fam=dict(pain=41, vienno=7, antigaspi=33, tartes=19, gouter=0, sale=0)),
]

# L'historique du client A, ticket par ticket (180 jours lus : aucun ticket identifié avant le 21/09/2026).
# date heure, €, commande (1) ou comptoir (0), articles.
HIST_A = [
    ('2026-09-21 17:32', 5.80, 1, 'Pain Demi-Gris ×1, Pain Doré ×1'),
    ('2026-09-26 10:09', 5.80, 1, 'Pain Demi-Gris ×1, Pain Doré ×1'),
    ('2026-09-28 20:54', 9.40, 1, 'Pain Demi-Gris ×1, Pain Doré ×1, Pain Tradition Noix et Raisins ×1'),
    ('2026-10-01 18:16', 5.80, 1, 'Pain Demi-Gris ×1, Pain Doré ×1'),
    ('2026-10-09 18:59', 9.70, 1, 'Pain Demi-Gris ×1, Pain Doré ×1, Pain Tradition Noix et Raisins ×1'),
    ('2026-10-16 14:20', 9.70, 1, 'Pain Doré ×1, Pain Demi-Gris ×1, Pain Tradition Noix et Raisins ×1'),
    ('2026-10-17 17:00', 5.80, 1, 'Pain Doré ×1, Pain Demi-Gris ×1'),
    ('2026-10-21 12:58', 5.80, 1, 'Pain Doré ×1, Pain Demi-Gris ×1'),
    ('2026-10-24 09:51', 17.60, 1, 'Pain Doré ×1, Pain Demi-Gris ×1, Huit ×1, Pain au Chocolat ×2, Croissant ×2, Baguette Tradition ×1'),
    ('2026-10-27 16:26', 11.90, 1, 'Pain 10 Céréales ×1, Pain Demi-Gris ×2, Pain Doré ×1'),
    ('2026-10-30 18:40', 5.80, 1, 'Pain Doré ×1, Pain Demi-Gris ×1'),
    ('2026-10-31 13:27', 17.40, 1, 'Pain Doré ×1, Pain Demi-Gris ×1, Cougnou Sucre ×5'),
    ('2026-11-03 18:11', 9.70, 1, 'Pain Doré ×1, Pain Demi-Gris ×1, Pain Tradition Noix et Raisins ×1'),
    ('2026-11-06 12:41', 13.20, 1, 'Pain Doré ×1, Pain Demi-Gris ×2, Merveilleux ×1'),
    ('2026-11-10 10:22', 5.80, 0, 'Pain Demi-Gris ×1, Pain Doré ×1'),
    ('2026-11-11 12:42', 5.80, 1, 'Pain Doré ×1, Pain Demi-Gris ×1'),
]
# Articles du client A, cumulés sur les 16 tickets (« Halfgrijs / Goud Brood » = Pain Demi-Gris / Pain Doré en néerlandais).
ARTICLES_A = [('Pain Doré', 16), ('Pain Demi-Gris', 18), ('Pain Tradition Noix et Raisins', 4), ('Cougnou Sucre', 5),
              ('Pain au Chocolat', 2), ('Croissant', 2), ('Pain 10 Céréales', 1), ('Huit', 1), ('Baguette Tradition', 1), ('Merveilleux', 1)]
