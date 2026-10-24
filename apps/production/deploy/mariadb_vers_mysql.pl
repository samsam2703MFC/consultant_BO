# Dump MariaDB -> MySQL 8 : perl -p mariadb_vers_mysql.pl dump.sql | mysql base
# Ne touche que les définitions de colonnes (lignes de CREATE TABLE), jamais les données.
#  - DATE DEFAULT current_timestamp()  -> DEFAULT (curdate())
#  - TEXT/BLOB DEFAULT 'littéral'      -> DEFAULT ('littéral')  (expression, MySQL >= 8.0.13)
if (/^\s+`/) {
  s/\b(date(?: NOT NULL| NULL)?) DEFAULT current_timestamp\(\)/$1 DEFAULT (curdate())/g;
  s/\b((?:tiny|medium|long)?(?:text|blob)(?: CHARACTER SET \w+)?(?: COLLATE \w+)?(?: NOT NULL| NULL)?) DEFAULT ('(?:[^'\\]|\\.)*')/$1 DEFAULT ($2)/g;
}
