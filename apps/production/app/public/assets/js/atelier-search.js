/* ==========================================================================
   ERP — recherche globale de la barre du haut (GET /ajax/search?q=) + modules et
   entrées du menu (lues dans la barre latérale, mots français acceptés).
   « / » place le curseur dans le champ ; flèches haut/bas pour choisir,
   Entrée pour ouvrir, Échap pour fermer. Minimum 2 caractères.
   ========================================================================== */
(function () {
    'use strict';

    var box = document.getElementById('atSearch');
    if (!box) return;

    var root = (box.getAttribute('data-root') || '').replace(/\/$/, '');
    var input = box.querySelector('.at-search-input');
    var panel = box.querySelector('.at-search-panel');
    var MIN = 2;
    var timer = null, controller = null, seq = 0, active = -1, lastQuery = '';

    function esc(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    // Surlignage sans casser l'échappement : on découpe le texte brut.
    function mark(text, q) {
        text = String(text == null ? '' : text);
        var needle = q.replace(/^#/, '').toLowerCase();
        if (!needle) return esc(text);
        var lower = text.toLowerCase(), out = '', from = 0, i;
        while ((i = lower.indexOf(needle, from)) !== -1) {
            out += esc(text.slice(from, i)) + '<mark>' + esc(text.slice(i, i + needle.length)) + '</mark>';
            from = i + needle.length;
        }
        return out + esc(text.slice(from));
    }

    // ---- Moduły i pozycje menu : indeks z paska bocznego (te same pozycje, które widzi użytkownik). ----
    function fold(value) {
        return String(value == null ? '' : value).toLowerCase().replace(/ł/g, 'l')
            .normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    }

    // Słowa francuskie i potoczne → słowa z menu (UI po polsku).
    var ALIASES = {
        'marge': 'marza marzy', 'prix': 'ceny cennik cena cen wzrosty', 'cout': 'koszt koszty kosztu', 'couts': 'koszty', 'vente': 'sprzedaz', 'ventes': 'sprzedaz',
        'commande': 'zamowienia zamowienie zamowione', 'commandes': 'zamowienia zamowione', 'client': 'klienci klient', 'clients': 'klienci klient', 'franchise': 'franczyza',
        'produit': 'produkt produkty', 'produits': 'produkty produkt', 'fiche': 'karty karta karcie technologiczna', 'recette': 'receptura receptury', 'recettes': 'receptury',
        'matiere': 'surowce surowca surowiec', 'matieres': 'surowce surowcow surowca', 'fournisseur': 'dostawcy dostawy dostawca dostawcow', 'fournisseurs': 'dostawcy dostawy dostawca dostawcow', 'achat': 'zakupy zakupowe zakupowa zakup', 'achats': 'zakupy zakup',
        'facture': 'faktury proforma faktura', 'factures': 'faktury faktura zakupowe', 'livraison': 'wysylka dostawy dostawa dostaw', 'livraisons': 'dostawy wysylka', 'arrivage': 'dostawy', 'arrivages': 'dostawy', 'approvisionnement': 'dostawy zaopatrzenie', 'expedition': 'wysylka', 'camion': 'ciezarowki pojazdy ciezarowka',
        'camions': 'ciezarowki pojazdy ciezarowka', 'tournee': 'trasy', 'tournees': 'trasy', 'chargement': 'zaladunek zaladunku', 'transporteur': 'przewoznicy przewoznik ciezarowki',
        'stock': 'magazynu magazynowe stan magazyn', 'stocks': 'magazynu magazynowe', 'inventaire': 'magazynu inwentaryzacja', 'couverture': 'magazynu pokrycie', 'emballage': 'opakowania pakowanie pakowania kartony', 'emballages': 'opakowania opakowan kartony', 'production': 'produkcja', 'planning': 'planowanie gantt plan',
        'equipe': 'zespol team grupa', 'personnel': 'pracownicy personelu osobisty', 'employe': 'pracownicy', 'employes': 'pracownicy', 'tablette': 'tablet tablety',
        'pointage': 'czas pracy wejscie wyjscie', 'competences': 'kompetencje', 'prime': 'premie premia zespolow progi', 'primes': 'premie premia zespolow progi', 'tache': 'zadania', 'taches': 'zadania',
        'reclamation': 'reklamacje reklamacja', 'reclamations': 'reklamacje reklamacja', 'retour': 'zwroty zwrot', 'retours': 'zwroty', 'parametres': 'ustawienia parametry',
        'reglages': 'ustawienia parametry', 'utilisateur': 'uzytkownicy', 'utilisateurs': 'uzytkownicy', 'compteur': 'licznik', 'appareil': 'urzadzenia',
        'appareils': 'urzadzenia', 'rentabilite': 'rentownosc wydajnosc', 'tableau': 'dashboard tabela', 'aujourdhui': 'dzisiaj', 'logistique': 'logistyka logistyki',
        'finances': 'finanse faktury koszty', 'analyse': 'analiza', 'langues': 'jezyki', 'poids': 'wagi', 'nutrition': 'odzywcze', 'archives': 'archiwum',
        'question': 'pytania', 'questions': 'pytania', 'tenue': 'pytania odziez', 'hygiene': 'higiena pytania', 'heures': 'godziny',
        'badge': 'identyfikatory', 'badges': 'identyfikatory', 'telephone': 'telefony telefonem telefon', 'distribution': 'dystrybucja',
        'document': 'dokumenty', 'documents': 'dokumenty', 'calcul': 'kalkulacja kalkulator', 'cmr': 'cmr dokumenty', 'imprimer': 'drukuj dokumenty druk wydruk',
        'mail': 'poczta skrzynka stopki', 'mails': 'poczta skrzynka e-maile', 'email': 'poczta skrzynka e-maile', 'courriel': 'poczta', 'boite': 'poczta skrzynka', 'reception': 'poczta dostawy przyjecie', 'envoyes': 'wyslane niewyslane',
        'messagerie': 'poczta', 'groupe': 'grupy grup grupa zespol team', 'groupes': 'grupy grup', 'semaine': 'tydzien tygodni tygodnia', 'qui': 'kto', 'livre': 'dostarcza dostawy dokad', 'livrent': 'dostarcza dostawy', 'quoi': 'co', 'quand': 'kiedy', 'hebdomadaire': 'tydzien tygodnia cotygodniowe', 'hebdo': 'tydzien tygodnia cotygodniowe', 'cobot': 'coboty cobot cobotyzowalny', 'cobots': 'coboty cobot', 'photo': 'zdjecia zdjecie', 'photos': 'zdjecia zdjecie',
        'remplir': 'wypelnienia', 'importer': 'import importuj pobierz wgraj', 'xlsx': 'excel', 'donnees': 'dane tabela', 'capacite': 'pojemnosc pojemnosci', 'capacites': 'pojemnosci',
        'machine': 'stanowiska maszyny', 'machines': 'stanowiska maszyny', 'poste': 'stanowiska', 'postes': 'stanowiska', 'four': 'stanowiska piece', 'fours': 'stanowiska piece',
        'horaire': 'zmiany godziny', 'horaires': 'zmiany godziny', 'shift': 'zmiany trasy w tygodniu', 'shifts': 'zmiany trasy w tygodniu', 'effectif': 'obsada', 'effectifs': 'obsada', 'equipes': 'zespol zespolow obsada',
        'fiches': 'karty', 'mission': 'cel karty', 'missions': 'cel karty', 'responsabilites': 'obowiazki karty', 'competence': 'kompetencje', 'organigramme': 'stanowiska przelozony', 'boulanger': 'piekarz piekarnia', 'patissier': 'cukiernik cukiernia', 'chauffeur': 'kierowca kierowcy', 'chauffeurs': 'kierowcy kierowca', 'preuve': 'potwierdzenie potwierdzenia', 'confirmation': 'potwierdzenie potwierdzenia', 'signature': 'podpis podpisy podpisu stopka', 'probleme': 'problemy problem', 'incident': 'problemy problem', 'palette': 'palety paletyzacji', 'palettes': 'palety paletyzacji', 'surgelateur': 'zamrazarki', 'congelateur': 'zamrazarki zamrazarka', 'surgelation': 'zamrazarki', 'depart': 'wyjazdu wyjazd', 'departs': 'wyjazdu',
        'rapport': 'raport', 'rapports': 'raport', 'mensuel': 'miesieczny', 'mensuelle': 'miesieczny', 'mois': 'miesieczny miesiac miesiaca', 'bilan': 'raport wynik',
        'synthese': 'raport', 'resultat': 'wynik', 'resultats': 'wynik', 'marges': 'marza marze', 'direction': 'zarzadu raport',
        'alarme': 'alarm instrukcje', 'cle': 'klucz instrukcje anthropic', 'cles': 'klucz instrukcje', 'dechargement': 'rozladunku rozladunek', 'consignes': 'instrukcje',
        'consigne': 'instrukcje', 'procedure': 'procedura', 'livreur': 'kierowca kierowcy',
        'developpement': 'rozwoju', 'nouveau': 'nowy nowe dodaj', 'nouveaux': 'nowe nowy', 'nouveaute': 'nowe rozwoju', 'nouveautes': 'nowe rozwoju',
        'annuel': 'roku rok', 'annee': 'roku rok', 'lancement': 'wprowadzone nowe wprowadzenie',
        'crm': 'nowe produkty etapy crm', 'essai': 'test testy', 'essais': 'testy test', 'etape': 'etapy etap', 'etapes': 'etapy', 'suivi': 'etapy sledzenie',
        'valider': 'zatwierdzenie zatwierdzona zatwierdz zatwierdzone', 'validation': 'zatwierdzenie zatwierdzona zatwierdzenia', 'lance': 'wprowadzone wprowadzony', 'lances': 'wprowadzone',
        'pipeline': 'etapy', 'prototype': 'test nowe', 'degustation': 'test', 'accueil': 'ekran glowny', 'ipad': 'tablet ekran',
        'alerte': 'alerty dzwonek', 'alertes': 'alerty dzwonek', 'echeance': 'terminy termin', 'echeances': 'terminy', 'retard': 'zalegle terminy termin alerty',
        'retards': 'zalegle terminy termin', 'rappel': 'alerty terminy przypomnienie wycofanie', 'rappels': 'alerty terminy przypomnienia', 'cloche': 'dzwonek alerty', 'notification': 'dzwonek alerty', 'notifications': 'dzwonek alerty',
        'historique': 'historia wersje', 'historiques': 'historia', 'version': 'wersje wersja', 'versions': 'wersje', 'evolution': 'historia koszt miesiac wykres',
        'vigueur': 'obowiazuje', 'depuis': 'obowiazuje', 'changement': 'zmiany zmiana', 'changements': 'zmiany', 'modification': 'zmiany edycja',
        'modifications': 'zmiany', 'composition': 'sklad kompozycje kompozycja kompozycji', 'compositions': 'kompozycje sklad kompozycji',
        'ecart': 'roznice roznica', 'ecarts': 'roznice', 'difference': 'roznice roznica', 'differences': 'roznice', 'remboursement': 'zwrot roznice',
        'rembourser': 'zwrot roznice', 'manquant': 'brak roznice problem brakujacych brakuje brakujace', 'manquants': 'brak roznice brakujacych brakuje brakujace', 'litige': 'roznice problem', 'litiges': 'roznice problemy',
        'decision': 'decyzja decyzji', 'decisions': 'decyzja', 'responsable': 'odpowiedzialny', 'qr': 'kod qr', 'pin': 'pin', 'scanner': 'skanuj', 'scan': 'skanuj',
        'mouvement': 'ruchy magazynowe', 'mouvements': 'ruchy magazynowe', 'registre': 'ruchy magazynowe', 'entree': 'przyjecia magazynowe wejscie', 'entrees': 'przyjecia magazynowe',
        'sortie': 'wydania magazynowe wyjscie', 'sorties': 'wydania magazynowe', 'perime': 'przeterminowane', 'perimes': 'przeterminowane', 'peremption': 'przeterminowane daty waznosci',
        'dlc': 'daty waznosci przeterminowane', 'lot': 'partie partia partii', 'lots': 'partie', 'fefo': 'fefo partie', 'carton': 'kartony karton', 'cartons': 'kartony karton',
        'magasin': 'magazyn magazynowe sklepie', 'solde': 'stan saldo', 'valeur': 'wartosc', 'fini': 'gotowe gotowych', 'finis': 'gotowe gotowych', 'finies': 'gotowe gotowych',
        'envoi': 'wysylki wysylka', 'envois': 'wysylki wysylek', 'erreur': 'bledy blad', 'erreurs': 'bledy blad', 'echec': 'bledy nie wyszly', 'echecs': 'bledy nie wyszly',
        'attente': 'czeka kolejka', 'bloque': 'czeka kolejka', 'bloques': 'czeka kolejka', 'file': 'kolejka', 'webhook': 'wysylki sklepow', 'webhooks': 'wysylki sklepow',
        'discord': 'monitoring', 'surveillance': 'monitoring', 'relancer': 'ponow przypomnij przypomnienie', 'renvoyer': 'ponow', 'retry': 'ponow',
        'traiter': 'obsluzenia sprawy', 'urgent': 'obsluzenia terminie', 'urgence': 'obsluzenia terminie', 'urgences': 'obsluzenia terminie',
        'impaye': 'nieoplacona przypomnienie platnosci', 'impayes': 'nieoplacone przypomnienie platnosci', 'impayee': 'nieoplacona przypomnienie platnosci', 'impayees': 'nieoplacone przypomnienie platnosci',
        'paiement': 'platnosci oplacona platnosc', 'relance': 'przypomnienie', 'relances': 'przypomnienia przypomnienie', 'afaire': 'obsluzenia zadania',
        'envoye': 'wyslane wyslana niewyslana', 'envoyee': 'wyslana niewyslana', 'envoyees': 'wyslane niewyslane',
        'journee': 'dnia dzien', 'fin': 'koniec', 'boutique': 'sklep sklepy sklepow', 'boutiques': 'sklepy sklepow sklep', 'perte': 'straty strata', 'pertes': 'straty', 'controle': 'kontrola kontroli', 'qualite': 'jakosci jakosc', 'conservation': 'przydatnosc', 'delai': 'termin zamawianie wyprzedzenie przed', 'complement': 'domowienie domowienia', 'complements': 'domowienia',
        'entrepot': 'magazyn magazynu', 'livree': 'dostarczono dostarczonych zrealizowane', 'livrees': 'dostarczonych zrealizowane', 'transport': 'transporcie',
        'statut': 'status statusy', 'statuts': 'status statusy', 'avis': 'powiadomienia powiadomienie', 'expeditions': 'wysylki wysylek', 'etat': 'stan',
        'doublon': 'ponownie jedno', 'realisee': 'zrealizowane', 'realisees': 'zrealizowane', 'emballeur': 'opakowania pakowanie pakowania',
        'assistant': 'kreator', 'assistants': 'kreator', 'wizard': 'kreator', 'creer': 'nowy kreator dodaj utworz', 'creation': 'nowy kreator', 'ajouter': 'dodaj nowy',
        'nouvelle': 'nowy nowe', 'nouvel': 'nowy', 'vehicule': 'pojazd pojazdy', 'vehicules': 'pojazdy pojazd', 'salarie': 'pracownik pracownicy', 'salaries': 'pracownicy pracownik',
        'proforma': 'proforma proformy', 'gratuit': 'gratis', 'conformite': 'zgodnosc', 'belgique': 'belgii belgia', 'onglet': 'zakladki', 'onglets': 'zakladki',
        'instruction': 'instrukcja kroki', 'instructions': 'instrukcja kroki', 'fenetre': 'oknie okno', 'lancer': 'wprowadz wprowadzony', 'introduire': 'wprowadz',
        'compte': 'uzytkownik konto logowania', 'comptes': 'uzytkownicy', 'identifiant': 'login', 'acces': 'uprawnienia dostep', 'droits': 'uprawnienia',
        'receptionner': 'przyjecie przyjmij dostawy', 'receptionne': 'przyjecie przyjete', 'nonconformite': 'niezgodnosc nc', 'nonconformites': 'niezgodnosci nc', 'abime': 'problem niezgodnosc uszkodzony', 'casse': 'problem uszkodzony',
        'envoyer': 'wyslij wyslane', 'tout': 'wszystkie', 'tous': 'wszystkie', 'toutes': 'wszystkie', 'habituel': 'zwykly zwykle', 'langue': 'jezyk tlumacz',
        'commander': 'zamow zamowienia', 'apres': 'po', 'planification': 'planowanie planowaniu plan',
        'feuille': 'arkusz', 'semaines': 'tyg tygodni', 'clic': 'kliknieciem', 'masse': 'wszystkie', 'compter': 'policzone liczenia', 'max': 'maks maksymalne', 'salaire': 'wynagrodzenie koszt pracodawcy', 'salaires': 'wynagrodzenie koszt pracodawcy', 'paie': 'wynagrodzenie placy', 'verrouiller': 'zamkniety zamknij', 'verrouille': 'zamkniety zamrozony', 'fige': 'zamrozony', 'modifier': 'zmien zmiana edycja korekta', 'calculateur': 'kalkulator kosztow', 'budget': 'budzet budzetu budzecie', 'liste': 'lista listy', 'listes': 'listy lista', 'jour': 'dnia dzien dzis', 'besoin': 'zapotrzebowanie potrzeba', 'besoins': 'zapotrzebowanie potrzeba',
        'cloture': 'zamkniecie miesiaca', 'cloturer': 'zamknij zamkniecie miesiaca', 'clore': 'zamknij zamkniecie', 'fermer': 'zamknij zamkniecie', 'fermeture': 'zamkniecie miesiaca',
        'limite': 'limit', 'lourd': 'waga najciezsza', 'haute': 'wysokosc najwyzsza', 'remise': 'rabat rabatu', 'tarif': 'cennik ceny cena', 'tarifs': 'cennik ceny', 'plafond': 'limit', 'arrondi': 'zaokraglenie',
        'medias': 'media', 'media': 'media', 'electricite': 'media prad', 'fixes': 'stale', 'fixe': 'stale staly biurowy', 'copier': 'kopiuj', 'precedent': 'poprzedniego', 'inventorier': 'inwentaryzacja arkusz', 'automatique': 'automatyczne automat automatycznie', 'automatiques': 'automatyczne automat', 'paye': 'oplacona oplacone', 'payee': 'oplacona oplacone', 'payees': 'oplacone', 'remplacement': 'zastepcze', 'remplacements': 'zastepcze', 'relivrer': 'ponowna dostawa', 'frise': 'os statusu', 'rebut': 'straty strata odpady', 'rebuts': 'straty strata odpady', 'dechet': 'straty odpady', 'dechets': 'straty odpady', 'encoder': 'zakoduj przypisz faktura', 'encodage': 'zakoduj', 'correction': 'poprawka poprawki', 'corriger': 'popraw poprawka', 'maximum': 'maks maksimum najwyzsza', 'autorise': 'wolno maksimum', 'reglage': 'ustawienia parametry', 'parametre': 'parametry parametr ustawienia', 'configuration': 'ustawienia parametry', 'tracabilite': 'identyfikowalnosc partie dokad', 'retrait': 'wycofanie', 'source': 'zrodlo', 'origine': 'zrodlo', 'lecture': 'odczytu', 'offre': 'oferta oferte oferty ofertowe', 'ou': 'dokad', 'destination': 'dokad klienci', 'expedie': 'wyslane dokad', 'parti': 'dokad wyslane partie', 'partis': 'dokad wyslane', 'bureau': 'biurowy biuro biura biurowego', 'pointer': 'wejscie wyjscie czas pracy', 'badger': 'wejscie wyjscie identyfikatory', 'badgeuse': 'wejscie wyjscie tablet biurowy', 'pointeuse': 'wejscie wyjscie tablet biurowy', 'service': 'dzial dzialy uslugi uslug usluga', 'presence': 'wejscie obecnosc obecnosci lista', 'arrivee': 'wejscie', 'nom': 'nazwisko', 'administration': 'administracja', 'validite': 'waznosc', 'signer': 'podpis podpisy podpisu', 'signe': 'podpis', 'tablettes': 'tablety tablet tabletow', 'plan': 'plan', 'obligatoire': 'obowiazkowy obowiazkowe', 'mapping': 'przypisz przypisanie przypisania', 'mapper': 'przypisz przypisanie surowce przypisania', 'correspondance': 'przypisanie przypisania', 'relier': 'przypisz przypisanie', 'kilo': 'kg', 'kg': 'kg', 'graphique': 'wykres', 'courbe': 'wykres historia', 'moins': 'najtaniej mniej', 'cher': 'najtaniej najdrozej najtansza taniej', 'comparer': 'porownanie', 'comparaison': 'porownanie', 'hausse': 'skok podwyzka podwyzki wzrosty wzrost', 'recuperer': 'pobierz', 'chercher': 'wyszukiwarka szukaj', 'recherche': 'wyszukiwarka szukaj', 'commandee': 'zamowione', 'portable': 'telefon telefonu telefonem', 'mobile': 'telefon telefonu', 'smartphone': 'telefon telefonu', 'sans': 'bez', 'changer': 'zmiana zmien', 'type': 'rodzaj', 'entreprise': 'firmy firma', 'personne': 'osoba osobisty', 'empreinte': 'podpis telefon', 'augmentation': 'podwyzka podwyzki skok', 'base': 'bazowa bazowej', 'inchange': 'bez zmian', 'garder': 'bez zmian', 'tolerance': 'tolerancja', 'revient': 'koszt wlasny koszt receptur kosztu', 'haut': 'najwyzsza najwyzszej', 'eleve': 'najwyzsza', 'recent': 'ostatnia ostatniej', 'valide': 'zatwierdzona zatwierdzone zatwierdzonej zatwierdz', 'validee': 'zatwierdzona zatwierdzonej przyjeta', 'valides': 'zatwierdzone zatwierdzonej', 'dernier': 'ostatnia ostatniej ostatni', 'personnes': 'osoby osob wszyscy', 'redirige': 'przekierowuje', 'autre': 'inna inny inne', 'adresse': 'adres adresy', 'lien': 'link linki', 'team': 'team zespol grupa', 'toucher': 'dotknij dotyk', 'present': 'obecne obecni obecna obecny', 'unite': 'jednostka jednostki jednostke', 'piece': 'szt sztuka sztuki', 'convertisseur': 'przelicznik', 'sac': 'worek worka', 'palier': 'progi prog premie', 'paliers': 'progi prog premie', 'bonus': 'premia premie', 'seuil': 'prog progi minimum', 'seuils': 'progi minimum', 'flux': 'lista produkcji', 'ingredient': 'skladnik skladniki', 'ingredients': 'skladniki', 'operateur': 'tablet operator operatora', 'rendement': 'wydajnosc', 'efficacite': 'wydajnosc', 'nettoyage': 'sprzatanie', 'pause': 'przerwa', 'pauses': 'przerwa', 'n-1': 'n-1 rok wczesniej', 'precedente': 'wczesniej n-1', 'fabrique': 'robi grupa', 'fait': 'robi', 'semi': 'polprodukt polprodukty', 'nomenclature': 'receptura sklad straty', 'generique': 'ogolny wszystkie grupy', 'finance': 'finanse', 'services': 'uslugi uslug usluga', 'rejeter': 'odrzuc odrzucone odrzucona', 'rejetee': 'odrzucona odrzucone', 'rejetees': 'odrzucone', 'regle': 'regula reguly', 'regles': 'reguly regula', 'carburant': 'paliwo paliwa orlen', 'essence': 'paliwo paliwa orlen', 'orlen': 'orlen paliwo', 'hausses': 'wzrosty podwyzki podwyzka', 'validees': 'zatwierdzone przyjete', 'loyer': 'czynsz najem', 'energie': 'prad media', 'affecter': 'przypisz przypisanie', 'traduction': 'tlumacz tlumaczenie przetlumacz deepl', 'traduire': 'tlumacz przetlumacz deepl', 'traducteur': 'tlumacz deepl', 'deepl': 'tlumacz deepl', 'pied': 'stopka stopki', 'footer': 'stopka stopki', 'footers': 'stopka stopki', 'page': 'strona strony stron stopka', 'pages': 'strona strony stron', 'offres': 'oferty oferta ofertowe', 'minimum': 'minimum min', 'demande': 'zapytanie', 'appel': 'zapytanie ofertowe', 'gain': 'oszczednosc', 'gains': 'oszczednosc', 'economie': 'oszczednosc', 'economies': 'oszczednosc', 'roi': 'zwrot roi', 'premieres': 'surowcow surowce', 'jours': 'dni', 'enlevement': 'odbior', 'directeur': 'dyrektor', 'trimestre': 'kwartal kwartaly', 'acceptee': 'przyjeta przyjete', 'acceptees': 'przyjete przyjeta', 'informatif': 'wiadomosci', 'information': 'wiadomosci informacje', 'reponse': 'odpowiedz', 'palettisation': 'paletyzacji paletyzacja palety', 'palettiser': 'paletyzacji palety', 'palettise': 'paletyzacji paletach', 'emballer': 'pakowanie spakowania', 'conditionnement': 'pakowanie pakowania', 'charger': 'zaladunek', 'chargee': 'zaladowana zaladowane', 'chargees': 'zaladowane zaladowana', 'remorque': 'naczepa naczepy', 'glisser': 'przeciagnij', 'deposer': 'przeciagnij', 'manque': 'brakuje brak', 'direct': 'na zywo', 'quai': 'rampie rampa', 'ecran': 'ekran', 'temps': 'czas', 'ordre': 'kolejnosc', 'emballeuse': 'pakowanie pakowania', 'emballes': 'spakowanych spakowane', 'emballe': 'spakowane spakowanych', 'emballees': 'spakowane', 'pave': 'klawiatura', 'numerique': 'klawiatura', 'annuler': 'cofnij', 'video': 'film wideo', 'videos': 'film wideo', 'reel': 'reela film', 'technique': 'technologiczna', 'carte': 'karta karty', 'ia': 'ai asystent', 'intelligence': 'ai asystent', 'artificielle': 'ai', 'modele': 'model', 'saison': 'sezon sezonowy', 'saisonnier': 'sezonowy', 'trimestres': 'kwartaly kwartal', 'prevision': 'prognoza', 'previsions': 'prognoza', 'images': 'zdjecia klatki', 'image': 'zdjecia klatki', 'word': 'docx', 'import': 'wgraj import', 'generer': 'asystent ai', 'cuisson': 'pieczenie', 'capture': 'klatki zdjecia', 'impression': 'drukuj druk wydruk', 'papier': 'druk wydruk', 'idee': 'pomysl', 'etiquette': 'etykieta', 'categorie': 'kategoria', 'coefficient': 'wspolczynnik', 'multiplicateur': 'mnoznik', 'cobotisable': 'cobotyzowalny cobot', 'usine': 'fabryczna', 'mappage': 'surowce przypisania', 'allemagne': 'niemcy de przejazd swieta', 'transit': 'przejazd', 'reinitialiser': 'wszystko do rozplanowania', 'desaffecter': 'do rozplanowania', 'couche': 'warstwa warstwy', 'couches': 'warstwa warstwy', 'hauteur': 'wysokosc', 'gerbage': 'pietrowanie', 'trajet': 'trasy trasa kalendarz', 'trajets': 'trasy kalendarz', 'repos': 'odpoczynek przerwa', 'ferie': 'swieta swieto', 'feries': 'swieta swieto', 'conge': 'urlopy urlop', 'conges': 'urlopy urlop', 'noel': 'boze narodzenie swieta', 'sachet': 'torebka opakowanie', 'sachets': 'torebka opakowanie opakowan', 'barquette': 'tacka opakowanie', 'alternatif': 'alternatywne', 'alternatifs': 'alternatywne stanowiska', 'suggestion': 'podpowiedz', 'suggere': 'podpowiedz', 'suggerer': 'podpowiedz', 'termine': 'gotowe zatwierdzona', 'pret': 'gotowe gotowy', 'prete': 'gotowe gotowa', 'unitaire': 'sztuka sztuke', 'verifier': 'sprawdzenia sprawdz', 'modale': 'okno', 'test': 'testowy testowego test', 'mixte': 'mieszana mieszane mieszanej', 'mixtes': 'mieszane mieszana', 'empiler': 'jedna na drugiej warstwy ukladaj', 'empilement': 'warstwy jedna na drugiej kolejnosc', 'superposer': 'jedna na drugiej warstwy', 'superposees': 'jedna na drugiej', 'combiner': 'polaczenie polaczyc', 'fusionner': 'polaczenie polaczyc', 'regrouper': 'polaczenie razem', 'calendrier': 'kalendarz data', 'date': 'data dzien', 'ligne': 'pozycja pozycje', 'lignes': 'pozycje pozycji', 'positions': 'pozycje pozycji', 'position': 'pozycja', 'litigieuse': 'sporna sporne', 'litigieuses': 'sporne', 'contester': 'sporna', 'suivante': 'nastepna jedna po drugiej', 'wizard': 'kreator', 'exception': 'wyjatkowe wyjatkowy okres', 'exceptionnel': 'wyjatkowe wyjatkowy', 'exceptionnels': 'wyjatkowe', 'periode': 'okres okresy', 'periodes': 'okresy okres', 'necessaire': 'potrzebny potrzebne', 'blocage': 'blokady blokada', 'projete': 'prognoza projekcja', 'projetee': 'prognoza', 'projetees': 'prognoza', 'projection': 'prognoza projekcja', 'niveau': 'stany stan', 'niveaux': 'stany stan', 'normale': 'normalne', 'normal': 'normalne', 'congelateurs': 'zamrazarka zamrazarki', 'publier': 'publikacja opublikuj kreator cen', 'publication': 'publikacja publikacje', 'publications': 'publikacje publikacja', 'site': 'strona strony franczyzy', 'recalculer': 'przelicz przeliczenie', 'recalcul': 'przelicz przeliczenie', 'adapter': 'nowe ceny kreator cen', 'annonce': 'e-mail cennik', 'preavis': 'wyprzedzenie dni przed', 'planifier': 'zaplanuj plan',
        'moyenne': 'srednia'
    };

    var menu = null;
    // Słowa łączące (FR, PL) pomijane w zapytaniu : « preuve de livraison », « plan na tydzień ».
    var STOP = { 'a': 1, 'non': 1, 'de': 1, 'du': 1, 'des': 1, 'la': 1, 'le': 1, 'les': 1, 'et': 1, 'au': 1, 'aux': 1, 'en': 1, 'pour': 1, 'par': 1, 'un': 1, 'une': 1, 'na': 1, 'do': 1, 'od': 1, 'ze': 1, 'est': 1, 'sont': 1, 'plus': 1, 'ce': 1, 'qui': 1, 'que': 1, 'quoi': 1, 'il': 1 };

    function menuIndex() {
        if (menu) return menu;
        menu = [];
        var section = '';
        document.querySelectorAll('#sidebar .menu > li').forEach(function (li) {
            if (li.classList.contains('sidebar-title')) { section = li.textContent.trim(); return; }
            var top = li.querySelector(':scope > .sidebar-link');
            var group = li.classList.contains('has-sub') && top ? clean(top) : '';
            var links = li.classList.contains('has-sub') ? li.querySelectorAll('.submenu a[href]') : (top ? [top] : []);
            Array.prototype.forEach.call(links, function (a) {
                if (a.getAttribute('href') === '#') return;
                var label = clean(a);
                var icon = a.querySelector('i.bi');
                var where = [section, group].filter(Boolean).join(' › ');
                menu.push({ title: label, sub: where, href: a.href, icon: icon ? icon.className.replace(/^.*?(bi-[\w-]+).*$/, '$1') : '',
                            label: fold(label), where: fold(where) });
            });
        });
        // Strony bez pozycji w railu (zakładki grup, przyciski) : ukryta lista w menu — bez stron już obecnych w railu.
        var seen = {};
        menu.forEach(function (e) { seen[e.href + '|' + e.label] = 1; });
        document.querySelectorAll('#sidebar [data-search-extra] a[href]').forEach(function (a) {
            var label = clean(a);
            if (seen[a.href + '|' + fold(label)]) return;
            seen[a.href + '|' + fold(label)] = 1;
            var where = a.dataset.where || '';
            var icon = a.querySelector('i.bi');
            menu.push({ title: label, sub: where, href: a.href, icon: icon ? icon.className.replace(/^.*?(bi-[\w-]+).*$/, '$1') : '',
                        label: fold(label), where: fold(where) });
        });
        return menu;
    }

    function clean(el) {
        var copy = el.cloneNode(true);
        copy.querySelectorAll('[data-count], .badge').forEach(function (b) { b.remove(); });
        return copy.textContent.replace(/\s+/g, ' ').trim();
    }

    // Każde słowo zapytania musi trafić w nazwę, sekcję/grupę albo w alias. Wynik : najpierw trafienia w nazwę.
    function menuGroup(q) {
        var words = fold(q).replace(/\b[dl]'/g, ' ').split(/\s+/).filter(function (w) { return w.length >= 2 && !STOP[w]; });
        if (!words.length) return null;
        var hits = [];
        menuIndex().forEach(function (e, order) {
            var score = 0;
            for (var i = 0; i < words.length; i++) {
                var w = words[i], alias = ALIASES[w] || '';
                var aliases = alias ? alias.split(' ') : [];
                if (e.label.indexOf(w) === 0 || e.label.indexOf(' ' + w) >= 0) score += 4;
                else if (e.label.indexOf(w) >= 0) score += 3;
                else if (aliases.some(function (a) { return e.label.indexOf(a) === 0; })) score += 2.7;
                else if (aliases.some(function (a) { return e.label.indexOf(' ' + a) >= 0; })) score += 2.5;
                else if (e.where.indexOf(w) >= 0 || aliases.some(function (a) { return e.label.indexOf(a) >= 0; })) score += 2;
                else if (aliases.some(function (a) { return e.where.indexOf(a) >= 0; })) score += 1;
                else return;
            }
            hits.push({ e: e, score: score, order: order });
        });
        if (!hits.length) return null;
        hits.sort(function (a, b) { return b.score - a.score || a.order - b.order; });
        // Une page = un résultat : entre l'entrée du menu et ses descriptions (fonctions de la page), le meilleur score l'emporte.
        var pages = {};
        hits = hits.filter(function (h) { if (pages[h.e.href]) return false; pages[h.e.href] = 1; return true; });
        return { type: 'menu', label: 'Moduły i menu', icon: 'bi-compass', items: hits.slice(0, 8).map(function (h) {
            return { title: h.e.title, sub: h.e.sub, href: h.e.href };
        }) };
    }

    function options() { return panel.querySelectorAll('[role="option"]'); }

    function open() {
        panel.hidden = false;
        input.setAttribute('aria-expanded', 'true');
        box.classList.add('is-open');
    }

    function close() {
        panel.hidden = true;
        input.setAttribute('aria-expanded', 'false');
        input.removeAttribute('aria-activedescendant');
        box.classList.remove('is-open');
        active = -1;
    }

    function setActive(i) {
        var list = options();
        if (!list.length) return;
        if (i < 0) i = list.length - 1;
        if (i >= list.length) i = 0;
        list.forEach(function (o, k) {
            o.classList.toggle('is-active', k === i);
            o.setAttribute('aria-selected', k === i ? 'true' : 'false');
        });
        active = i;
        input.setAttribute('aria-activedescendant', list[i].id);
        list[i].scrollIntoView({ block: 'nearest' });
    }

    function message(html) {
        panel.innerHTML = '<p class="at-search-msg">' + html + '</p>';
        active = -1;
        open();
    }

    function render(data, q, pending) {
        var menuHits = menuGroup(q);
        data.groups = (menuHits ? [menuHits] : []).concat(data.groups || []);
        if (!data.groups.length) {
            message(pending ? 'Szukam…' : 'Brak wyników dla „' + esc(q) + '”.');
            return;
        }
        var n = 0, html = '';
        data.groups.forEach(function (g) {
            html += '<div class="at-search-group" role="group" aria-label="' + esc(g.label) + '">' +
                '<div class="at-search-head"><i class="bi ' + esc(g.icon) + '" aria-hidden="true"></i>' + esc(g.label) + '</div>';
            g.items.forEach(function (it) {
                html += '<a class="at-search-item" role="option" aria-selected="false" id="atSearchOpt' + (n++) + '" href="' + esc(it.href || root + it.url) + '">' +
                    '<span class="at-search-title">' + mark(it.title, q) + '</span>' +
                    (it.sub ? '<span class="at-search-sub">' + mark(it.sub, q) + '</span>' : '') + '</a>';
            });
            html += '</div>';
        });
        if (pending) html += '<p class="at-search-msg">Szukam w zamówieniach, klientach, produktach…</p>';
        html += '<div class="at-search-foot"><span><kbd>↑</kbd><kbd>↓</kbd> wybór</span><span><kbd>Enter</kbd> otwórz</span><span><kbd>Esc</kbd> zamknij</span></div>';
        panel.innerHTML = html;
        active = -1;
        open();
        setActive(0);
    }

    function search(q) {
        if (controller) controller.abort();
        controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
        var mine = ++seq;
        box.classList.add('is-loading');
        fetch(root + '/ajax/search?q=' + encodeURIComponent(q), {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' },
            signal: controller ? controller.signal : undefined
        }).then(function (r) {
            return r.json().catch(function () { return {}; }).then(function (body) { body.ok = r.ok; return body; });
        }).then(function (data) {
            if (mine !== seq) return;
            box.classList.remove('is-loading');
            if (!data.ok) {
                if (menuGroup(q)) { render({ groups: [] }, q); return; }
                message(esc(data.message || 'Wyszukiwanie chwilowo nie działa.'));
                return;
            }
            if (document.activeElement === input) render(data, q);
        }).catch(function (err) {
            if (err && err.name === 'AbortError') return;
            box.classList.remove('is-loading');
            message('Wyszukiwanie chwilowo nie działa.');
        });
    }

    input.addEventListener('input', function () {
        clearTimeout(timer);
        var q = input.value.trim();
        lastQuery = q;
        if (!q) { close(); return; }
        if (q.replace(/^#/, '').length < MIN && !/^#?\d$/.test(q)) {
            message('Wpisz co najmniej ' + MIN + ' znaki.');
            return;
        }
        // Moduły i menu od razu (bez serwera), dane po chwili.
        if (menuGroup(q)) render({ groups: [] }, q, true);
        timer = setTimeout(function () { search(q); }, 180);
    });

    input.addEventListener('focus', function () {
        if (panel.innerHTML && lastQuery.length >= MIN) open();
    });

    input.addEventListener('keydown', function (e) {
        if (e.key === 'ArrowDown') { e.preventDefault(); if (panel.hidden && lastQuery) open(); setActive(active + 1); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); setActive(active - 1); }
        else if (e.key === 'Enter') {
            // Le gestionnaire global de base.twig bloque Entrée dans les champs texte : celui-ci est de type « search ».
            var list = options();
            if (!list.length) return;
            e.preventDefault();
            window.location.href = list[active >= 0 ? active : 0].href;
        }
        else if (e.key === 'Escape') {
            e.preventDefault();
            if (!panel.hidden) close();
            else { input.value = ''; lastQuery = ''; input.blur(); }
        }
        else if (e.key === 'Tab') close();
    });

    panel.addEventListener('mousemove', function (e) {
        var o = e.target.closest('[role="option"]');
        if (!o) return;
        var list = Array.prototype.indexOf.call(options(), o);
        if (list !== active) setActive(list);
    });
    // Le clic sur un résultat ne doit pas fermer le panneau avant la navigation.
    panel.addEventListener('mousedown', function (e) { e.preventDefault(); });

    document.addEventListener('click', function (e) {
        if (!box.contains(e.target)) close();
    });

    // « / » : aller à la recherche, sauf pendant une saisie.
    document.addEventListener('keydown', function (e) {
        if (e.key !== '/' || e.ctrlKey || e.metaKey || e.altKey) return;
        var t = e.target;
        if (t && (t.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName))) return;
        e.preventDefault();
        input.focus();
        input.select();
    });
})();
