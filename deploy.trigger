Audit de fraicheur : la caisse en base contre l API.

Tout ecran qui lit la caisse en base affiche des ventes arretees a la derniere
journee encodee, sans le dire — un total de juillet reste plausible en aout.
La route /audit/fraicheur met les deux cote a cote : derniere date par source,
ce que l API sert pour AUJOURD HUI, et la liste des ecrans concernes avec la
route qui devrait les alimenter.
