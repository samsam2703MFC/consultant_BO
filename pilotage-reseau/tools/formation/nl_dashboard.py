#!/usr/bin/env python3
"""Pose la traduction néerlandaise (de travail, « à relire ») d'ART-PIL-01 et SOP-PIL-01 dans leurs fichiers.

Usage (depuis pilotage-reseau/) : python3 tools/formation/nl_dashboard.py
Chaque texte NL est rangé par numéro de partie ou d'étape ; une ligne = une ligne du texte FR. Le script refuse
d'écrire si le nombre de lignes NL diffère du FR (une action par ligne, des deux côtés).
"""
import json
import sys

D = 'data/formation/dashboard/'

ART = {
 'titre': 'Het winkeldashboard begrijpen',
 'objectif': 'Elk tabblad van het winkeldashboard kunnen lezen: waar elk cijfer vandaan komt, waarmee het vergeleken wordt, wat de kleuren zeggen, en waar je kijkt om een vraag over de winkel te beantwoorden.',
 'pour': 'Kandidaat-franchisenemer, franchisenemer, manager, adjunct-manager en afdelingschef verkoop (P4, P7 tot P12). Te lezen vóór de opening, in opleiding FO-05, vóór de eerste dag.',
 'duree': '45 min lezen, daarna 30 min op het dashboard van de eigen winkel',
 'sections': ['Voor je begint', 'Operationeel — de dag', 'Week en Maand — het resultaat', 'Kwartaal en Jaar — de koers', 'Promoties, Premies en telefoon'],
 'etapes': {
  '1': ('Het dashboard openen', [
    'Adres: de cockpit › Winkeldashboard, of de link die je voor je winkel kreeg (…/consulant_bo/dashboard/?shop=…).',
    'Het tabblad dat standaard opent: Operationeel, de lopende dag.',
    '« ↻ Herlezen » leest de cijfers opnieuw; de lopende dag wordt elke tien minuten vanzelf herlezen.',
    'Alles is alleen-lezen: het dashboard toont, het schrijft niets. Je corrigeert aan de bron (kassa, panel, cockpit).']),
  '2': ('De zeven tabbladen', [
    'Operationeel: vandaag, uur per uur.',
    'Week en Maand: het resultaat van de periode, dag per dag.',
    'Kwartaal en Jaar: de koers, tegenover het ontwikkelingsplan.',
    'Promoties: de acties in de kassa en wat ze opbrengen.',
    'Premies: waar elke verkoopster staat, premie per premie.',
    'Onder de tabbladen de tijdlijn: één vak per dag (of per maand, per kwartaal); ‹ en › wisselen van periode, een tik opent het vak.']),
  '3': ('Een cijfer lees je nooit alleen', [
    'Elk cijfer staat naast een referentie: de doelstelling, dezelfde dag vorige week, de mediaan van het netwerk of vorig jaar.',
    'Bordeaux of rood: achter op de referentie of boven een drempel.',
    'Oranje: in het oog houden. Groen: in orde. Goud: record of doelstelling overschreden.',
    'Gearceerd: de dag of de periode loopt nog, nog niet beoordeeld.']),
  '4': ('De waarde van de winkel', [
    'Rechtsboven, in goud: wat de handelszaak waard is aan het huidige ritme.',
    'De berekening: de omzet van de laatste twee jaar (730 dagen, of de geleefde dagen als de winkel jonger is), herleid tot één jaar, gedeeld door 6 — twee maanden omzet.',
    'De lopende maand telt niet mee: onvolledig zou ze de waarde naar beneden trekken.',
    'Een tik op « Waarde ▾ » opent het detail: de formule voluit en de waarde jaar per jaar.']),
  '5': ('Jouw plaats in het netwerk', [
    'Vijf cijfers tegenover de andere open winkels: omzet, klanten, gemiddeld ticket (zonder zakelijke klanten), brutomarge, B2B.',
    'Het bordeaux punt ben jij; de grijze punten de anderen; het zwarte streepje de mediaan.',
    'Onder elk cijfer: het verschil met de mediaan en het cijfer van de eerste.',
    'De rang (2e / 4) staat in het groen als je eerste bent.']),
  '6': ('De toonbankdoelstelling van de dag', [
    'De doelstelling van de dag komt uit het maandbudget, over de dagen verdeeld volgens de weging van het netwerk.',
    'Bordeaux balk: vandaag tot nu. Blauwe balk: dezelfde dag vorige week, op hetzelfde uur.',
    'Het gearceerde deel loopt naar de projectie voor het einde van de dag aan het huidige ritme.',
    'De B2B (zakelijke klanten) heeft een eigen kaart, eronder: die wordt niet met de toonbank vermengd.',
    '« Bestellingen van de dag »: de toonbank- en webshopbestellingen klaar te maken of af te halen.']),
  '7': ('Uur per uur', [
    'De curve van de gecumuleerde omzet van vandaag, tegenover die van dezelfde dag vorige week.',
    'De verticale stippellijn: nu. De lijn van de toonbankdoelstelling bovenaan.',
    'Schakelaar Omzet · Klanten · Ticket: om te zien of de achterstand van het aantal klanten of van het ticket komt.',
    '« Toonbank en zakelijk apart » of « Samen »: twee lezingen van hetzelfde cijfer.']),
  '8': ('Het duel, cijfer per cijfer', [
    'Drie lijnen: omzet, klanten, gemiddeld ticket.',
    'Vier kolommen: vandaag tot nu; dezelfde dag vorige week op hetzelfde uur (en het verschil in %); diezelfde dag over de hele dag; de referentie (gemiddelde van de zes laatste dezelfde dagen).',
    'Minder klanten: dat is de traffic (etalage, stoep, aanbod). Lager ticket: dat is de bijverkoop.']),
  '9': ('De korte W&V van de dag', [
    'Omzet − grondstofkost = brutomarge.',
    'Brutomarge − onverkocht en afval − personeelskost − algemene kosten = resultaat.',
    'De drempel van de grondstofkost is het grondstofpercentage van het netwerk, ingesteld in de cockpit, Budget › Parameters (categorie Grondstoffen & Verpakking): je wijzigt het daar, het dashboard volgt.',
    'Personeelskost en algemene kosten: de drempels van de KPI-tabel (33 % en 13,5 % van de omzet).',
    'De personeelskost is die van de planning van de dag; de uren van de franchisenemer staan apart vermeld.',
    '’s Ochtends is het resultaat altijd negatief: de kosten van de hele dag staan er al, de omzet nog niet. Je leest het ’s avonds.']),
  '10': ('Verkoop per categorie', [
    'Groep › categorie › product: een tik op ▸ klapt open.',
    'Voor elke lijn: omzet, aandeel in het totaal, brutomarge, margepercentage, coëfficiënt (verkoopprijs ÷ kost).',
    'De kleur van het percentage: onder 40 %, 40–50, 50–60, 60–70, 70 % en meer in goud; grijs = grondstofkost onbekend (recept aan te vullen in het panel).',
    'Andere lezingen: Treemap (het gewicht in één oogopslag), Promoties, Porties.']),
  '11': ('De dag', [
    'Een tijdlijn van 4 u tot 20 u: het team (de planning), de bakbeurten van de oven, de etalage, de bestellingen.',
    '« Te bevestigen » bij een bakbeurt: die is nog niet bevestigd.',
    'Rechts de toonbankverkoop uur per uur tegenover het gemiddelde: groen erboven, amber onder het gemiddelde, bordeaux het lopende uur.',
    '« Het team van de dag › » opent het detail van de planning.']),
  '12': ('De etalage', [
    'Wat tegen sluitingstijd zal ontbreken, aan het verkoopritme, en de euro’s verkoop die te redden zijn.',
    'Drie families: Short life (vers van de dag), Medium life, Long life.',
    '« +N te bakken »: wat bij de volgende bakbeurt moet; het uur van de volgende bakbeurt staat rechtsboven.',
    'Rood: leeg terwijl klanten ernaar vragen.']),
  '13': ('De controles met foto', [
    'De foto’s van de controles van de dag (opening, toonbanken, etikettering) die het team heeft opgeladen.',
    'Drie filters: Alles, Te controleren (opgeladen, nog niet beoordeeld), Niet ingediend (geen foto).',
    'Een foto opent groot met één tik.']),
  '14': ('De levende voorraad', [
    'De grondstoffeninventaris van de winkel, opnieuw gelezen met de pagina.',
    'Een referentie staat in alarm als haar voorraad negatief is (kassa- of telverschil) of onder het dagminimum.',
    'Nul alarmen is alleen goed nieuws als de inventaris recent is: na zeven dagen zonder telling wordt de balk oranje.',
    '« Verwittig mij »: een melding op de telefoon zodra een referentie onder haar minimum zakt (enkel op een https-pagina).']),
  '15': ('De taken van de periode', [
    'Uitgevoerd, niet uitgevoerd, en de dagen waarop minder dan 40 % van de taken gedaan werd.',
    'De dagenlijn: één vak per dag — groen 80 % en meer, lichtgroen 40 % en meer, roze onder 40 %, rood niets gedaan.',
    '« detail ▾ » opent de taken dag per dag; blokkerende taken lees je op de dag zelf.']),
  '16': ('Het resultaat van de periode', [
    'Omzet van de periode en doelstelling (gevalideerd budget), verwacht tot vandaag.',
    'Bereikt: het deel van het verwachte tot vandaag, en de ontbrekende klanten.',
    'Projectie: waar de periode eindigt aan het huidige ritme. Nog te doen: per resterende dag.',
    'Per open dag, brutomarge (en grondstofkost tegenover de drempel van Budget › Parameters), nettoresultaat, record van de periode.']),
  '17': ('Bestellingen en kanalen', [
    'Toonbank (kassatickets), zakelijke klanten (tickets van bedrijven), click & collect (webshop afgehaald in de winkel), levering (webshop geleverd).',
    'Voor elk: het cijfer, het aandeel in de omzet, het aantal tickets of bestellingen, het ticket.',
    'De tabel van de bestellingen: dag per dag, wat klaar te maken is en wat al genomen is voor de komende dagen.']),
  '18': ('Onverkocht en afval', [
    'De weggegooide stuks aangegeven in de kassa: hun productiekost gaat van het resultaat af.',
    'Verloren verkoopwaarde: wat ze aan verkoopprijs hadden opgebracht.',
    'Redenen (einde dag, breuk…) en « De ingaven één voor één »: het uur, de operator, de hoeveelheid.',
    'Niets aangegeven betekent niet niets weggegooid: afval dat niet ingegeven is, vervalst het resultaat.']),
  '19': ('De omzet van elke dag', [
    'De kalender van de periode: groot, de omzet van de dag.',
    'Kleur en meter tegenover de dagdoelstelling: 110 % en meer, 100–110, 90–100, 75–90, onder 75 %.',
    'Het streepje van de meter: 100 % van de doelstelling. Gearceerd: de lopende dag.',
    'In Maand, ernaast: het profiel van de dagen (de zwakste weekdag).']),
  '20': ('Twaalf weken en de uren', [
    'De twaalf laatste weken: klanten, gemiddeld ticket of artikelen per klant, tegenover vorig jaar.',
    'De uren: het uur met de meeste klanten, de meeste omzet, het meest rendabele, het minst rendabele.',
    'De typedag: voor elk uur verkoop, grondstofkost, verloning en nettomarge (groen = het uur verdient, rood = het verliest).',
    'Dat is de basis om de planning en de bakbeurten bij te sturen.']),
  '21': ('De zes hefbomen (tabblad Maand)', [
    'Traffic (klanten per dag), terugkeer (klanten die terugkomen), klantervaring (Google-score), food cost, labour cost, overhead cost.',
    'Elke hefboom tegenover vorig jaar en het netwerk; bolletje volgens de drempels van de KPI-tabel: onder het alarm, tussen alarm en kritiek, erboven.',
    '« De evolutie van het jaar » toont elke hefboom maand per maand.']),
  '22': ('De rendabiliteit dag per dag (tabblad Maand)', [
    'De W&V van de maand, daarna het nettoresultaat van elke dag in één rij vakjes.',
    'Eén vak: één dag; de kleur zegt of die dag geld verdiend of verloren heeft.',
    'Het profiel van de dagen zegt welke weekdag de maand draagt en welke hem afremt.']),
  '23': ('Het kwartaal', [
    'De vier kwartalen van het jaar tegenover de doelstelling van het ontwikkelingsplan.',
    'Het gekozen kwartaal: doelstelling, gerealiseerd, verwacht tot vandaag, verschil, ontbrekende klanten, nettomarge.',
    'De notities van het kwartaal: die van de franchisenemer, de consultant en het merk, en de genomen acties.',
    'Het kwartaalritueel: vijf stappen, te valideren met de consultant.']),
  '24': ('Het jaar', [
    'De jaardoelstelling (engagement van de franchisenemer), het gerealiseerde, het verwachte, het verschil en de projectie voor het einde van het jaar.',
    'De opbouw over vijf jaar: jaar 1, 2, 3 en daarna de maturiteit.',
    'Kwartaal per kwartaal, de traffic tegenover vorig jaar, daarna maand per maand.']),
  '25': ('De heatmap van de maanden', [
    'Eén vak per maand: het bereikte budget, of de omzet van de maand.',
    'Rood onder 80 %, oranje 80–95 %, groen 95–105 %, goud boven 105 %.',
    'De tabel eronder geeft maand per maand: omzet, budget, klanten, ticket, food cost, labour, overhead, nettomarge.']),
  '26': ('Promoties', [
    'Alleen-lezen: promoties worden aangemaakt en gewijzigd in de cockpit (Promoties › Bewerken).',
    '« Waar de promoties gebruikt worden »: hoe vaak, aan de toonbank of in de webshop, over 7 of 30 dagen.',
    'De coëfficiënt van de bundel: wat de verkoop met de promotie opbrengt; in het rood als hij laag is.',
    'Eronder: de actieve, komende, ontwerp- en afgelopen promoties.']),
  '27': ('Premies', [
    'Waar de verkoopsters staan: de winkelpremies (het hele team wint samen) en de individuele premies.',
    'Voor elke premie: het doel, waar elke persoon staat, « aan kop » of « gewonnen ».',
    'In aanmerking en « onder het minimum aantal uren »: wie de premie deze maand kan krijgen.',
    'Alleen-lezen: premies worden ingesteld en uitbetaald in de cockpit.']),
  '28': ('Op de telefoon', [
    'Onder 560 px breed lees je dezelfde pagina met de duim: Operationeel, Exploitatie, Controle, Week, Premies, onderaan het scherm.',
    'Het cijfer van de periode groot, het alarm van de blokkerende taken er net onder, daarna kaarten.',
    'De maand, het kwartaal en het jaar lees je op een computer of tablet.']),
 },
 'points_critiques': [
  'Een cijfer lees je altijd tegenover zijn referentie (doelstelling, dezelfde dag vorige week, mediaan van het netwerk, vorig jaar), nooit alleen.',
  'Het resultaat van de korte W&V lees je ’s avonds: ’s ochtends staan de kosten van de hele dag er al.',
  'Het dashboard is alleen-lezen: een fout corrigeer je aan de bron (kassa, panel, cockpit), daarna herlees je.',
  'Nul voorraadalarmen stelt alleen gerust als de inventaris minder dan zeven dagen oud is.',
 ],
 'quiz': [
  ('Om 9 u toont de korte W&V een negatief resultaat. Wat besluit je daaruit?', None),
  ('Hoe wordt de waarde van de winkel berekend?', None),
  ('In « Het duel » stijgt het gemiddelde ticket maar daalt de omzet tegenover vorige week. Waar zoek je?', None),
  ('De heatmap van het jaar toont een oranje vak. Wat betekent dat?', None),
  ('De voorraadbalk zegt « inventaris niet herteld sinds 12 dagen » in het oranje. Wat doe je?', None),
 ],
}
# Les choix du quiz, dans l'ordre du fichier FR (la bonne réponse n'est pas toujours à la même place).
ART_CHOIX = {
 'À 9 h': {'Rien encore : les coûts de toute la journée sont posés, le chiffre d’affaires ne l’est pas ; le résultat se lit le soir': 'Nog niets: de kosten van de hele dag staan er al, de omzet nog niet; het resultaat lees je ’s avonds',
           'La journée sera perdante, il faut réduire l’équipe': 'De dag wordt verlieslatend, het team moet kleiner',
           'Le dashboard est en panne': 'Het dashboard is stuk'},
 'Comment': {'Le CA des deux dernières années ramené à un an, divisé par 6 (deux mois de chiffre d’affaires)': 'De omzet van de laatste twee jaar herleid tot één jaar, gedeeld door 6 (twee maanden omzet)',
             'Le CA du mois en cours × 12': 'De omzet van de lopende maand × 12',
             'Le résultat net de l’année × 3': 'Het nettoresultaat van het jaar × 3'},
 'Dans': {'Le nombre de clients : c’est le trafic qui manque': 'Het aantal klanten: het is de traffic die ontbreekt',
          'La vente additionnelle': 'De bijverkoop', 'Le coût matière': 'De grondstofkost'},
 'La heatmap': {'Le mois a atteint entre 80 et 95 % de son budget': 'De maand haalde tussen 80 en 95 % van haar budget',
                'Le mois a dépassé son budget': 'De maand overschreed haar budget', 'Le mois n’a pas de budget': 'De maand heeft geen budget'},
 'Le bandeau': {'Planifier un comptage : sans inventaire récent, l’absence d’alerte ne prouve rien': 'Een telling plannen: zonder recente inventaris bewijst het ontbreken van alarmen niets',
                'Rien, il n’y a pas d’alerte': 'Niets, er is geen alarm', 'Modifier le stock dans le dashboard': 'De voorraad in het dashboard aanpassen'},
}

SOP = {
 'titre': 'Het winkeldashboard analyseren: de routine',
 'objectif': 'Elke dag, elke week en elke maand weten waar de winkel staat, en daar een genoteerde actie uit halen, met cijfers als bewijs.',
 'pour': 'De verantwoordelijke van de winkel: franchisenemer, manager, adjunct-manager of afdelingschef verkoop (P4, P7, P8, P9, P11, P12). Permanente vorming FO-05.',
 'duree': '10 min elke ochtend · 2 × 3 min overdag · 5 min ’s avonds · 30 min op maandag · 1 u op de eerste werkdag van de maand',
 'sections': ['Elke ochtend, bij de opening — 10 min', 'Overdag — 11 u en 15 u, 3 min', '’s Avonds, bij de sluiting — 5 min',
              'Elke maandag — de afgelopen week, 30 min', 'De eerste werkdag van de maand — de afgesloten maand, 1 u',
              'Elk kwartaal — de koers', 'Wie verwittigen'],
 'etapes': {
  '1.1': ('Het dashboard openen', ['Het dashboard van de winkel openen op het tabblad Operationeel.', 'Op « ↻ Herlezen » tikken om met verse cijfers te starten.']),
  '1.2': ('De vorige dag herlezen', ['In de tijdlijn op het vak van gisteren tikken.', 'Het % van de doelstelling, het resultaat (korte W&V) en het afval noteren.', 'Een oranje of rood vak: in « Het duel » zoeken waarom (klanten of ticket).']),
  '1.3': ('Controles met foto', ['Terug naar vandaag: zijn alle openingscontroles ingediend?', '« Niet ingediend »: de foto nu laten opladen.', '« Te controleren »: ze bekijken en ter plaatse corrigeren wat niet klopt.']),
  '1.4': ('Etalage en volgende bakbeurt', ['Lezen hoeveel producten zullen ontbreken en hoeveel euro te redden is.', 'De « +N te bakken » overnemen in de volgende bakbeurt.', 'Een product dat leeg is terwijl klanten ernaar vragen, gaat eerst.']),
  '1.5': ('Voorraad', ['Een alarm: de referentie nakijken (telling, ontvangst, kassaverschil).', 'Oranje balk « niet herteld sinds N dagen »: deze week een telling plannen.']),
  '1.6': ('Het team van de dag', ['In « De dag » de planning nakijken tegenover de drukke uren van gisteren.', 'Een rustig uur met veel personeel: het invullen (mise-en-place, poetsen, prospectie) of de planning bijsturen.']),
  '2.1': ('Toonbankdoelstelling', ['De projectie voor het einde van de dag lezen tegenover de toonbankdoelstelling.', 'Projectie onder 90 % van de doelstelling om 11 u: een actie voor de namiddag beslissen en aan het team zeggen.']),
  '2.2': ('Klanten of ticket?', ['In « Het duel » vergelijken met dezelfde uren van vorige week.', 'Minder klanten: volle etalage, deur en stoep, aanbod van het moment.', 'Lager ticket: aan de bijverkoop herinneren (drank, viennoiserie, formule).']),
  '2.3': ('Uur per uur', ['Kijken of de curve van vandaag die van vorige week inhaalt.', 'De etalage nakijken vóór elke bakbeurt (Middag, Namiddag).']),
  '3.1': ('Het resultaat van de dag', ['De korte W&V lezen: omzet, brutomarge, resultaat.', 'Grondstofkost boven zijn drempel (die van Budget › Parameters) of personeelskost boven de zijne: de waarschijnlijke oorzaak noteren.']),
  '3.2': ('Het afval is aangegeven', ['« Onverkocht en afval » moet de weggegooide stuks van de dag tonen.', '« Niets aangegeven in het panel » terwijl er weggegooid is: het onverkochte in de kassa laten ingeven vóór vertrek.']),
  '3.3': ('De taken van de dag', ['Nakijken dat er geen blokkerende taak openstaat; anders ze voor morgenvroeg toevertrouwen.']),
  '4.1': ('De voorbije week openen', ['Tabblad Week, daarna ‹ om naar de afgelopen week terug te gaan.']),
  '4.2': ('Het resultaat', ['Noteren: omzet, bereikte doelstelling, nettoresultaat, record.', 'Bereikt onder 90 %: de rode dagen opzoeken in « De omzet van elke dag ».']),
  '4.3': ('Tegenover het netwerk', ['De rang en het verschil met de mediaan lezen voor omzet, klanten, ticket en bereikte doelstelling.', 'Het cijfer waar je het verst van de mediaan zit, wordt de prioriteit van de week.']),
  '4.4': ('Taken en controles', ['Het % uitgevoerde taken en de dagen onder 40 % lezen.', 'Een dag onder 40 %: met het team van die dag bekijken wat blokkeerde.']),
  '4.5': ('Onverkocht', ['De weggegooide stuks, de productiekost en de verloren verkoopwaarde lezen.', 'De drie meest weggegooide referenties: hun productie verlagen of hun bakuur veranderen.']),
  '4.6': ('De uren en de planning', ['Het meest en het minst rendabele uur lezen, en de typedag.', 'Een uur in het rood (negatieve nettomarge) meerdere dagen: de planning van de volgende week bijsturen.']),
  '4.7': ('Beslissen', ['Eén tot drie acties voor de week kiezen, elk met een cijfer dat moet bewegen.', 'Ze in het actieplan van de winkel schrijven en ze op de teambriefing aankondigen.', 'De maandag erna nalezen of het cijfer bewogen heeft.']),
  '5.1': ('De zes hefbomen', ['Tabblad Maand, daarna ‹ om naar de afgesloten maand terug te gaan.', 'Eerst de bolletjes boven de kritieke drempel behandelen (food cost, labour cost, overhead cost, traffic, terugkeer, Google-score).']),
  '5.2': ('Het resultaat van de maand', ['Noteren: omzet tegenover het budget, bereikt, brutomarge, nettoresultaat.', 'De rendabiliteit dag per dag en het profiel van de dagen lezen: welke dag remt de maand af?']),
  '5.3': ('De omzet van elke dag', ['De dagen onder 75 % van de doelstelling opzoeken en de oorzaak zoeken (weer, breuk in de voorraad, team, werken).']),
  '5.4': ('Promoties en premies', ['Promoties: die houden waarvan de coëfficiënt standhoudt, de cockpit voorstellen de andere te stoppen.', 'Premies: met elke verkoopster overlopen wat haar nog te doen staat.']),
  '5.5': ('Het bezoek van de consultant voorbereiden', ['De cijfers van de maand verzamelen, de acties van elke maandag en hun effect.', 'Ze meebrengen naar het bezoek of het maandelijkse overleg met de consultant.']),
  '6.1': ('Het kwartaal', ['Tabblad Kwartaal: het verschil met het ontwikkelingsplan en de ontbrekende klanten lezen.', 'De notitie van de franchisenemer schrijven en het kwartaalritueel met de consultant volgen.']),
  '6.2': ('Het jaar', ['Tabblad Jaar: de projectie voor het einde van het jaar tegenover de doelstelling en de heatmap van de maanden lezen.', 'Een verschil dat twee kwartalen na elkaar groter wordt: het plan met de consultant herzien.']),
  '7.1': ('Een cijfer dat duidelijk fout is', ['0 € terwijl de kassa gedraaid heeft, grondstofkost onbekend, winkel afwezig in het netwerk: de consultant verwittigen, met een schermafbeelding.', 'Niet « uit het hoofd » corrigeren: de correctie gebeurt aan de bron (kassa, panel, cockpit).']),
  '7.2': ('Een afwijking die aanhoudt', ['Negatief resultaat drie avonden na elkaar, of bereikte doelstelling van de week onder 80 %: de consultant (business coach) verwittigen.', 'Negatieve voorraad op een referentie: de verantwoordelijke van de productie verwittigen en opnieuw tellen.']),
 },
 'points_critiques': [
  'Elke lezing eindigt met een genoteerde actie; een lezing zonder actie dient nergens toe.',
  'Het resultaat van de dag lees je ’s avonds, nooit ’s ochtends.',
  'Het onverkochte moet elke avond in de kassa aangegeven worden, anders is het resultaat fout.',
  'Het dashboard is alleen-lezen: je corrigeert aan de bron, daarna herlees je.',
 ],
 'quiz': [
  ('Om 11 u staat de projectie voor het einde van de dag op 82 % van de toonbankdoelstelling. Wat doe je?', None),
  ('’s Avonds toont « Onverkocht en afval » « niets aangegeven in het panel » terwijl het team stuks heeft weggegooid. Wat doe je?', None),
  ('Wat is op maandag de prioriteit van de week?', None),
  ('Het resultaat is drie avonden na elkaar negatief. Wie verwittig je?', None),
 ],
}
SOP_CHOIX = {
 'Décider une action pour l’après-midi (vitrine, offre, vente additionnelle) et la dire à l’équipe': 'Een actie voor de namiddag beslissen (etalage, aanbod, bijverkoop) en ze aan het team zeggen',
 'Attendre le soir pour voir': 'Wachten tot ’s avonds om te zien', 'Baisser l’objectif': 'De doelstelling verlagen',
 'Faire saisir les invendus en caisse avant de partir': 'Het onverkochte in de kassa laten ingeven vóór vertrek',
 'Rien, ce n’est pas grave': 'Niets, het is niet erg', 'Retirer le montant du résultat de tête': 'Het bedrag uit het hoofd van het resultaat aftrekken',
 'Le chiffre où le magasin est le plus loin de la médiane du réseau, avec une à trois actions notées': 'Het cijfer waar de winkel het verst van de mediaan van het netwerk zit, met één tot drie genoteerde acties',
 'Tous les chiffres à la fois': 'Alle cijfers tegelijk', 'Le chiffre le plus haut': 'Het hoogste cijfer',
 'Le consultant (business coach)': 'De consultant (business coach)', 'Personne': 'Niemand', 'Le fournisseur': 'De leverancier',
}


def poser(fichier, T, choix):
    d = json.load(open(fichier, encoding='utf-8'))
    for k in ('titre', 'objectif', 'pour'):
        d[k]['nl'] = T[k]
    if len(d['sections']) != len(T['sections']):
        sys.exit(f'{fichier} : {len(d["sections"])} sections FR, {len(T["sections"])} NL')
    for s, nl in zip(d['sections'], T['sections']):
        s['titre']['nl'] = nl
        for e in s['etapes']:
            if e['numero'] not in T['etapes']:
                sys.exit(f'{fichier} : étape {e["numero"]} sans NL')
            titre, lignes = T['etapes'][e['numero']]
            if len(lignes) != len(e['texte']['fr'].split('\n')):
                sys.exit(f'{fichier} : étape {e["numero"]} — {len(e["texte"]["fr"].split(chr(10)))} lignes FR, {len(lignes)} NL')
            e['titre']['nl'] = titre
            e['texte']['nl'] = '\n'.join(lignes)
    if len(d['points_critiques']) != len(T['points_critiques']):
        sys.exit(f'{fichier} : points critiques FR et NL en nombre différent')
    for p, nl in zip(d['points_critiques'], T['points_critiques']):
        p['nl'] = nl
    for q, (nl, _) in zip(d['quiz'], T['quiz']):
        q['question']['nl'] = nl
        table = choix if isinstance(next(iter(choix.values())), str) else next(v for k, v in choix.items() if q['question']['fr'].startswith(k))
        q['choix']['nl'] = [table[c] for c in q['choix']['fr']]
    json.dump(d, open(fichier, 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
    print(fichier, 'NL posé')


poser(D + 'art-pil-01.json', ART, ART_CHOIX)
poser(D + 'sop-pil-01.json', SOP, SOP_CHOIX)
