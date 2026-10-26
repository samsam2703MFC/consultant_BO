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
        'marge': 'marza', 'prix': 'ceny cennik', 'cout': 'koszt', 'couts': 'koszty', 'vente': 'sprzedaz', 'ventes': 'sprzedaz',
        'commande': 'zamowienia', 'commandes': 'zamowienia', 'client': 'klienci klient', 'clients': 'klienci klient', 'franchise': 'franczyza',
        'produit': 'produkt produkty', 'produits': 'produkty produkt', 'fiche': 'karty', 'recette': 'receptura receptury', 'recettes': 'receptury',
        'matiere': 'surowce', 'matieres': 'surowce', 'fournisseur': 'dostawcy dostawy', 'fournisseurs': 'dostawcy dostawy', 'achat': 'zakupy', 'achats': 'zakupy',
        'facture': 'faktury proforma', 'factures': 'faktury', 'livraison': 'wysylka dostawy', 'livraisons': 'dostawy wysylka', 'arrivage': 'dostawy', 'arrivages': 'dostawy', 'approvisionnement': 'dostawy zaopatrzenie', 'expedition': 'wysylka', 'camion': 'ciezarowki pojazdy',
        'camions': 'ciezarowki pojazdy', 'tournee': 'trasy', 'tournees': 'trasy', 'chargement': 'zaladunek', 'transporteur': 'przewoznicy',
        'stock': 'magazynu magazynowe', 'stocks': 'magazynu magazynowe', 'inventaire': 'magazynu inwentaryzacja', 'couverture': 'magazynu pokrycie', 'emballage': 'opakowania', 'emballages': 'opakowania', 'production': 'produkcja', 'planning': 'planowanie gantt',
        'equipe': 'zespol', 'personnel': 'pracownicy personelu', 'employe': 'pracownicy', 'employes': 'pracownicy', 'tablette': 'tablet tablety',
        'pointage': 'czas pracy', 'competences': 'kompetencje', 'prime': 'premie', 'primes': 'premie', 'tache': 'zadania', 'taches': 'zadania',
        'reclamation': 'reklamacje', 'reclamations': 'reklamacje', 'retour': 'zwroty', 'retours': 'zwroty', 'parametres': 'ustawienia',
        'reglages': 'ustawienia', 'utilisateur': 'uzytkownicy', 'utilisateurs': 'uzytkownicy', 'compteur': 'licznik', 'appareil': 'urzadzenia',
        'appareils': 'urzadzenia', 'rentabilite': 'rentownosc', 'tableau': 'dashboard tabela', 'aujourdhui': 'dzisiaj', 'logistique': 'logistyka',
        'finances': 'finanse', 'analyse': 'analiza', 'langues': 'jezyki', 'poids': 'wagi', 'nutrition': 'odzywcze', 'archives': 'archiwum',
        'question': 'pytania', 'questions': 'pytania', 'tenue': 'pytania odziez', 'hygiene': 'higiena pytania', 'heures': 'godziny',
        'badge': 'identyfikatory', 'badges': 'identyfikatory', 'telephone': 'telefony', 'distribution': 'dystrybucja',
        'document': 'dokumenty', 'documents': 'dokumenty', 'calcul': 'kalkulacja kalkulator', 'cmr': 'cmr dokumenty', 'imprimer': 'drukuj dokumenty',
        'mail': 'poczta skrzynka', 'mails': 'poczta skrzynka e-maile', 'email': 'poczta skrzynka e-maile', 'courriel': 'poczta', 'boite': 'poczta skrzynka', 'reception': 'poczta dostawy przyjecie', 'envoyes': 'wyslane niewyslane',
        'messagerie': 'poczta', 'groupe': 'grupy grup', 'groupes': 'grupy grup', 'semaine': 'tydzien tygodni', 'qui': 'kto', 'livre': 'dostarcza dostawy', 'livrent': 'dostarcza dostawy', 'quoi': 'co', 'quand': 'kiedy', 'hebdomadaire': 'tydzien', 'hebdo': 'tydzien', 'cobot': 'coboty cobot', 'cobots': 'coboty cobot', 'photo': 'zdjecia', 'photos': 'zdjecia',
        'remplir': 'wypelnienia', 'importer': 'import', 'xlsx': 'excel', 'donnees': 'dane tabela', 'capacite': 'pojemnosc pojemnosci', 'capacites': 'pojemnosci',
        'machine': 'stanowiska maszyny', 'machines': 'stanowiska maszyny', 'poste': 'stanowiska', 'postes': 'stanowiska', 'four': 'stanowiska piece', 'fours': 'stanowiska piece',
        'horaire': 'zmiany godziny', 'horaires': 'zmiany godziny', 'shift': 'zmiany', 'shifts': 'zmiany', 'effectif': 'obsada', 'effectifs': 'obsada', 'equipes': 'zespol zespolow obsada',
        'fiches': 'karty', 'mission': 'cel karty', 'missions': 'cel karty', 'responsabilites': 'obowiazki karty', 'competence': 'kompetencje', 'organigramme': 'stanowiska przelozony', 'boulanger': 'piekarz piekarnia', 'patissier': 'cukiernik cukiernia', 'chauffeur': 'kierowca kierowcy', 'chauffeurs': 'kierowcy kierowca', 'preuve': 'potwierdzenie potwierdzenia', 'confirmation': 'potwierdzenie potwierdzenia', 'signature': 'podpis', 'probleme': 'problemy problem', 'incident': 'problemy problem', 'palette': 'palety', 'palettes': 'palety', 'surgelateur': 'zamrazarki', 'congelateur': 'zamrazarki', 'surgelation': 'zamrazarki', 'depart': 'wyjazdu', 'departs': 'wyjazdu',
        'rapport': 'raport', 'rapports': 'raport', 'mensuel': 'miesieczny', 'mensuelle': 'miesieczny', 'mois': 'miesieczny miesiac', 'bilan': 'raport wynik',
        'synthese': 'raport', 'resultat': 'wynik', 'resultats': 'wynik', 'marges': 'marza marze', 'direction': 'zarzadu raport',
        'alarme': 'alarm instrukcje', 'cle': 'klucz instrukcje', 'cles': 'klucz instrukcje', 'dechargement': 'rozladunku rozladunek', 'consignes': 'instrukcje',
        'consigne': 'instrukcje', 'procedure': 'procedura', 'livreur': 'kierowca kierowcy',
        'developpement': 'rozwoju', 'nouveau': 'nowy nowe', 'nouveaux': 'nowe nowy', 'nouveaute': 'nowe rozwoju', 'nouveautes': 'nowe rozwoju',
        'annuel': 'roku rok', 'annee': 'roku rok', 'lancement': 'wprowadzone nowe wprowadzenie',
        'crm': 'nowe produkty etapy crm', 'essai': 'test testy', 'essais': 'testy test', 'etape': 'etapy etap', 'etapes': 'etapy', 'suivi': 'etapy sledzenie',
        'valider': 'zatwierdzenie zatwierdzona', 'validation': 'zatwierdzenie zatwierdzona', 'lance': 'wprowadzone wprowadzony', 'lances': 'wprowadzone',
        'pipeline': 'etapy', 'prototype': 'test nowe', 'degustation': 'test', 'accueil': 'ekran glowny', 'ipad': 'tablet ekran',
        'alerte': 'alerty dzwonek', 'alertes': 'alerty dzwonek', 'echeance': 'terminy termin', 'echeances': 'terminy', 'retard': 'zalegle terminy termin alerty',
        'retards': 'zalegle terminy termin', 'rappel': 'alerty terminy', 'rappels': 'alerty terminy', 'cloche': 'dzwonek alerty', 'notification': 'dzwonek alerty', 'notifications': 'dzwonek alerty',
        'historique': 'historia wersje', 'historiques': 'historia', 'version': 'wersje wersja', 'versions': 'wersje', 'evolution': 'historia koszt miesiac',
        'vigueur': 'obowiazuje', 'depuis': 'obowiazuje', 'changement': 'zmiany zmiana', 'changements': 'zmiany', 'modification': 'zmiany edycja',
        'modifications': 'zmiany', 'composition': 'sklad kompozycje', 'compositions': 'kompozycje sklad',
        'ecart': 'roznice roznica', 'ecarts': 'roznice', 'difference': 'roznice roznica', 'differences': 'roznice', 'remboursement': 'zwrot roznice',
        'rembourser': 'zwrot roznice', 'manquant': 'brak roznice problem', 'manquants': 'brak roznice', 'litige': 'roznice problem', 'litiges': 'roznice problemy',
        'decision': 'decyzja decyzji', 'decisions': 'decyzja', 'responsable': 'odpowiedzialny', 'qr': 'kod qr', 'pin': 'pin', 'scanner': 'skanuj', 'scan': 'skanuj',
        'mouvement': 'ruchy magazynowe', 'mouvements': 'ruchy magazynowe', 'registre': 'ruchy magazynowe', 'entree': 'przyjecia magazynowe', 'entrees': 'przyjecia magazynowe',
        'sortie': 'wydania magazynowe', 'sorties': 'wydania magazynowe', 'perime': 'przeterminowane', 'perimes': 'przeterminowane', 'peremption': 'przeterminowane daty waznosci',
        'dlc': 'daty waznosci przeterminowane', 'lot': 'partie partia partii', 'lots': 'partie', 'fefo': 'fefo partie', 'carton': 'kartony', 'cartons': 'kartony',
        'magasin': 'magazyn magazynowe', 'solde': 'stan saldo', 'valeur': 'wartosc', 'fini': 'gotowe gotowych', 'finis': 'gotowe gotowych', 'finies': 'gotowe gotowych',
        'envoi': 'wysylki wysylka', 'envois': 'wysylki wysylek', 'erreur': 'bledy blad', 'erreurs': 'bledy blad', 'echec': 'bledy nie wyszly', 'echecs': 'bledy nie wyszly',
        'attente': 'czeka kolejka', 'bloque': 'czeka kolejka', 'bloques': 'czeka kolejka', 'file': 'kolejka', 'webhook': 'wysylki sklepow', 'webhooks': 'wysylki sklepow',
        'discord': 'monitoring', 'surveillance': 'monitoring', 'relancer': 'ponow', 'renvoyer': 'ponow', 'retry': 'ponow',
        'traiter': 'obsluzenia sprawy', 'urgent': 'obsluzenia terminie', 'urgence': 'obsluzenia terminie', 'urgences': 'obsluzenia terminie',
        'impaye': 'nieoplacona przypomnienie platnosci', 'impayes': 'nieoplacone przypomnienie platnosci', 'impayee': 'nieoplacona przypomnienie platnosci', 'impayees': 'nieoplacone przypomnienie platnosci',
        'paiement': 'platnosci oplacona', 'relance': 'przypomnienie', 'relances': 'przypomnienia przypomnienie', 'afaire': 'obsluzenia zadania',
        'envoye': 'wyslane wyslana niewyslana', 'envoyee': 'wyslana niewyslana', 'envoyees': 'wyslane niewyslane',
        'journee': 'dnia dzien', 'fin': 'koniec', 'boutique': 'sklep sklepy sklepow', 'boutiques': 'sklepy sklepow sklep', 'perte': 'straty strata', 'pertes': 'straty', 'controle': 'kontrola kontroli', 'qualite': 'jakosci jakosc', 'conservation': 'przydatnosc', 'delai': 'termin', 'complement': 'domowienie domowienia', 'complements': 'domowienia',
        'entrepot': 'magazyn magazynu', 'livree': 'dostarczono dostarczonych zrealizowane', 'livrees': 'dostarczonych zrealizowane', 'transport': 'transporcie',
        'statut': 'status statusy', 'statuts': 'status statusy', 'avis': 'powiadomienia powiadomienie', 'expeditions': 'wysylki wysylek', 'etat': 'stan',
        'doublon': 'ponownie jedno', 'realisee': 'zrealizowane', 'realisees': 'zrealizowane', 'emballeur': 'opakowania',
        'assistant': 'kreator', 'assistants': 'kreator', 'wizard': 'kreator', 'creer': 'nowy kreator dodaj', 'creation': 'nowy kreator', 'ajouter': 'dodaj nowy',
        'nouvelle': 'nowy nowe', 'nouvel': 'nowy', 'vehicule': 'pojazd pojazdy', 'vehicules': 'pojazdy pojazd', 'salarie': 'pracownik pracownicy', 'salaries': 'pracownicy pracownik',
        'proforma': 'proforma proformy', 'gratuit': 'gratis', 'conformite': 'zgodnosc', 'belgique': 'belgii belgia', 'onglet': 'zakladki', 'onglets': 'zakladki',
        'instruction': 'instrukcja kroki', 'instructions': 'instrukcja kroki', 'fenetre': 'oknie okno', 'lancer': 'wprowadz wprowadzony', 'introduire': 'wprowadz',
        'compte': 'uzytkownik konto', 'comptes': 'uzytkownicy', 'identifiant': 'login', 'acces': 'uprawnienia dostep', 'droits': 'uprawnienia',
        'receptionner': 'przyjecie przyjmij dostawy', 'receptionne': 'przyjecie przyjete', 'nonconformite': 'niezgodnosc nc', 'nonconformites': 'niezgodnosci nc', 'abime': 'problem niezgodnosc uszkodzony', 'casse': 'problem uszkodzony',
        'envoyer': 'wyslij wyslane', 'tout': 'wszystkie', 'tous': 'wszystkie', 'toutes': 'wszystkie', 'habituel': 'zwykly zwykle', 'langue': 'jezyk',
        'commander': 'zamow zamowienia', 'apres': 'po', 'planification': 'planowanie planowaniu plan'
    };

    var menu = null;
    // Słowa łączące (FR, PL) pomijane w zapytaniu : « preuve de livraison », « plan na tydzień ».
    var STOP = { 'a': 1, 'non': 1, 'de': 1, 'du': 1, 'des': 1, 'la': 1, 'le': 1, 'les': 1, 'et': 1, 'au': 1, 'aux': 1, 'en': 1, 'pour': 1, 'par': 1, 'un': 1, 'une': 1, 'na': 1, 'do': 1, 'od': 1, 'ze': 1 };

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
