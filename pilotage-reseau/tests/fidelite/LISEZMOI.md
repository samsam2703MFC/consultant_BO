# Banc d'essai · Fidélité › Profils clients

`src/fidelite_profils.php` contre une **vraie base MariaDB locale** et un **faux panel** (`lib.php`) dont chaque
client passe à un rythme connu (tous les 2 jours, 3,5 jours, 4 jours, 7 jours, 12 jours, 30 jours, une seule fois…).
Aucun appel au panel réel.

```bash
# une base de test (MariaDB ou MySQL)
mysql -e "CREATE DATABASE cockpit_test; CREATE USER 't'@'localhost' IDENTIFIED BY 't'; GRANT ALL ON cockpit_test.* TO 't'@'localhost';"
export COCKPIT_DB_USER=t COCKPIT_DB_PASSWORD=t COCKPIT_DB_NAME=cockpit_test
# le serveur qui reçoit les POST du banc
php -S 127.0.0.1:8765 tests/fidelite/post.php &
php tests/fidelite/banc.php
```

`banc.php` vérifie les profils de départ, le rangement des clients, la relecture (seuls le jour en cours et la veille
se relisent), la fiche d'un client et la publication (seuils en double, profil de base, mémoire trop courte).

Pour voir l'onglet du cockpit avec le vrai gabarit : `php -S 127.0.0.1:8766 -t public tests/fidelite/routeur.php`,
puis `http://127.0.0.1:8766/__banc.html?shop=4`.
