-- ============================================================
-- Migracja 024: Przykładowe dane – kompetencje recepturowe
-- Data: 2026-03-12
-- ============================================================

INSERT INTO `recipe_competency` (`name`, `description`) VALUES

('Obsługa pieca konwekcyjnego',
 'Pracownik potrafi ustawić i kontrolować parametry wypieku (temperatura, czas, nawilżanie pary). Zna tryby pracy pieca oraz procedury czyszczenia.'),

('Obsługa kotła warzelnego',
 'Pracownik potrafi obsługiwać kocioł warzelny przy produkcji mas, kremów i sosów. Zna zagrożenia wynikające z pracy z gorącymi cieczami pod ciśnieniem.'),

('Obsługa miksera spiralnego / planetarnego',
 'Pracownik zna różnicę między mikserem spiralnym a planetarnym, potrafi dobrać odpowiednie narzędzie (hak, trzepaczka, miesiadło) oraz czas mieszania do rodzaju ciasta/masy.'),

('Obsługa walcarki / sheeter',
 'Pracownik potrafi regulować grubość wałkowania ciasta, dbać o równomierność arkuszy oraz bezpiecznie obsługiwać i czyścić urządzenie.'),

('Obsługa krajalnic i form do wykrawania',
 'Pracownik zna zasady bezpiecznej obsługi ostrzy, potrafi ustawić wymiary porcji oraz dbać o czystość i ostrość narzędzi tnących.'),

('Przygotowanie i kontrola temperowania czekolady',
 'Pracownik rozumie krzywe temperowania dla różnych rodzajów czekolady (ciemna, mleczna, biała). Potrafi temperować ręcznie i maszynowo oraz ocenić poprawność temperowania wizualnie i dotykowo.'),

('Dozowanie i ważenie składników wg receptury',
 'Pracownik potrafi precyzyjnie ważyć i dozować składniki zgodnie z recepturą z dokładnością do 1 g. Zna konsekwencje odchyleń wagowych na jakość produktu finalnego.'),

('Obsługa urządzeń chłodniczych i szokowego schładzania',
 'Pracownik zna zasady pracy szafy chłodniczej i tunelu szokowego, potrafi ustawić programy schładzania oraz monitorować temperatury produktów.'),

('Dekorowanie i wykańczanie produktów',
 'Pracownik potrafi aplikować dekoracje (polewy, posypki, wzory kremem) zgodnie ze standardem wizualnym produktu. Zna zasady pracy z rękawem cukierniczym i szablonami.'),

('Znajomość alergenów i zasad cross-contamination',
 'Pracownik identyfikuje 14 głównych alergenów wg rozporządzenia UE 1169/2011, zna procedury mycia linii produkcyjnej i zapobiegania zanieczyszczeniu krzyżowemu.');


