# Decyzje biznesowe wymagane

Poniżej znajdują się wyłącznie rozstrzygnięcia, których nie da się wiarygodnie wyprowadzić z audytu. Rekomendacje są propozycją roboczą, nie zastępują decyzji właściciela procesu.

## BD-01 — Moment uznania sprzedaży i źródło referencyjne

- **Kontekst:** zamówienie, wydanie, paragon/faktura i płatność są różnymi zdarzeniami.
- **Opcje:** A) sprzedaż przy wydaniu; B) przy dokumencie fiskalnym; C) zależnie od kanału.
- **Konsekwencje:** wpływa na raport sprzedaży, należności, koszty i korekty okresu.
- **Rekomendacja:** C, z jedną jawną polityką dla każdego kanału i bez utożsamiania płatności ze sprzedażą.
- **Zależne procesy:** SAL-001–005, DOC-001–002, COST-003.

## BD-02 — Dopuszczalność ujemnego stanu magazynowego

- **Kontekst:** system musi ustalić, czy brak zapasu blokuje zatwierdzenie wydania.
- **Opcje:** A) zawsze blokada; B) ostrzeżenie; C) wyjątek dla ról i magazynów.
- **Konsekwencje:** wpływa na wiarygodność sald, koszt i ciągłość sprzedaży.
- **Rekomendacja:** A dla produkcji i magazynu, C wyłącznie jako audytowany tryb awaryjny dla wskazanych kanałów.
- **Zależne procesy:** SAL-003–004, PRD-002–003, INV-002–004.

## BD-03 — Zakres obowiązkowej partii i terminu ważności

- **Kontekst:** nie każdy indeks musi podlegać tej samej identyfikowalności.
- **Opcje:** A) wszystkie towary; B) wskazane klasy produktów; C) tylko wyroby gotowe.
- **Konsekwencje:** wpływa na pracochłonność przyjęć, kompletacji, produkcji i wycofania.
- **Rekomendacja:** B, sterowane cechą kartoteki i wymogiem prawnym/jakościowym.
- **Zależne procesy:** MD-001, INV-001–005, PRD-002–004, PUR-003, LOG-001.

## BD-04 — Model realizacji produkcji

- **Kontekst:** zapotrzebowanie może pochodzić z zamówień klienta, planu na magazyn lub obu źródeł.
- **Opcje:** A) make-to-order; B) make-to-stock; C) model hybrydowy per produkt.
- **Konsekwencje:** zmienia priorytety, rezerwacje, planowanie i wskaźniki terminowości.
- **Rekomendacja:** C, z jawną strategią uzupełniania w kartotece produktu.
- **Zależne procesy:** PLN-001–002, PRD-001, INV-003, SAL-001–003.

## BD-05 — Kontrola jakości i zwolnienie partii

- **Kontekst:** audyt nie określa, które materiały i wyroby wymagają kwarantanny.
- **Opcje:** A) brak formalnego zwolnienia; B) kontrola dla klas ryzyka; C) kontrola wszystkich partii.
- **Konsekwencje:** wpływa na dostępność zapasu, role, czas realizacji i wycofanie.
- **Rekomendacja:** B, z blokadą użycia partii w kwarantannie.
- **Zależne procesy:** PUR-003, PRD-003–004, INV-001–003, LOG-001.

## BD-06 — Metoda wyceny zapasu i kosztu produkcji

- **Kontekst:** nie ustalono wiążącej metody księgowej ani zarządczej.
- **Opcje:** A) średnia ważona; B) FIFO; C) koszt standardowy z odchyleniami; D) różne metody księgowa i zarządcza.
- **Konsekwencje:** wpływa na koszt własny, marżę, korekty i zamknięcie okresu.
- **Rekomendacja:** wybór z księgowością; technicznie przechowywać dane źródłowe tak, aby kalkulację dało się odtworzyć.
- **Zależne procesy:** INV-001–005, COST-001–003, PRD-004.

## BD-07 — Zamknięcie okresu i korekty wstecz

- **Kontekst:** opóźnione dokumenty mogą zmienić stan i koszt zamkniętego okresu.
- **Opcje:** A) zakaz zmian i korekta w bieżącym okresie; B) kontrolowane ponowne otwarcie; C) oba warianty zależnie od progu.
- **Konsekwencje:** wpływa na stabilność raportów i obowiązki zatwierdzających.
- **Rekomendacja:** C, z prawem ponownego otwarcia ograniczonym do właściciela finansowego.
- **Zależne procesy:** COST-003, INV-005, DOC-002, HR-003.

## BD-08 — Częściowa realizacja, backorder i zamienniki

- **Kontekst:** nie wiadomo, czy klient akceptuje dostawę częściową albo substytucję.
- **Opcje:** A) tylko kompletna realizacja; B) częściowa domyślnie; C) polityka per klient/zamówienie.
- **Konsekwencje:** wpływa na statusy zamówienia, rezerwacje, logistykę i komunikację.
- **Rekomendacja:** C; zamiennik zawsze jawny i zatwierdzony.
- **Zależne procesy:** SAL-001–003, INV-003, LOG-001, PLN-001.

## BD-09 — Progi zatwierdzeń i rozdział obowiązków

- **Kontekst:** audyt nie zawiera limitów kwotowych ani ilościowych.
- **Opcje:** A) stałe role; B) progi zależne od kwoty/odchylenia; C) brak drugiego zatwierdzenia.
- **Konsekwencje:** wpływa na zakupy, rabaty, korekty, inwentaryzacje i czas pracy.
- **Rekomendacja:** B dla operacji o skutku finansowym, z możliwie małą liczbą progów.
- **Zależne procesy:** ADM-002, SAL-002/005, INV-004–005, PUR-002, HR-003, COST-003.

## BD-10 — Struktura organizacyjna i izolacja danych

- **Kontekst:** nie potwierdzono liczby spółek, zakładów, punktów sprzedaży i magazynów.
- **Opcje:** A) jedna organizacja; B) wiele jednostek w jednej bazie; C) odrębne instancje.
- **Konsekwencje:** wpływa na numeracje, uprawnienia, raporty i rozliczenia między jednostkami.
- **Rekomendacja:** B, jeśli istnieje wspólna administracja i polityka danych; inaczej C.
- **Zależne procesy:** ADM-001–004 oraz wszystkie procesy transakcyjne.

## BD-11 — Wymogi fiskalne, fakturowe i kraje działania

- **Kontekst:** brak potwierdzonego zakresu jurysdykcji, urządzeń fiskalnych i wymiany e-faktur.
- **Opcje:** A) Polska i jeden kanał fiskalny; B) Polska, wiele urządzeń/operatorów; C) wiele krajów.
- **Konsekwencje:** determinuje numeracje, integracje, retencję i reguły korekt.
- **Rekomendacja:** jawnie ograniczyć pierwszy zakres do potwierdzonych krajów i kanałów.
- **Zależne procesy:** SAL-004–005, DOC-001–002, ADM-003, INT-001 (model integracyjny).

## BD-12 — Tryb offline sprzedaży

- **Kontekst:** nie wiadomo, czy sprzedaż musi działać bez połączenia z serwerem centralnym.
- **Opcje:** A) brak offline; B) kolejka lokalna z ograniczeniami; C) pełny oddział autonomiczny.
- **Konsekwencje:** wpływa na spójność numeracji, zapasu, płatności i duplikaty.
- **Rekomendacja:** B tylko wtedy, gdy potwierdzają to wymagania operacyjne; w przeciwnym razie A.
- **Zależne procesy:** SAL-004–005, INV-002, DOC-001, integracje.

## BD-13 — Zakres i prywatność ewidencji czasu

- **Kontekst:** nie ustalono, czy czas ma służyć płacom, kosztom produkcji, ocenie wydajności czy wszystkim celom.
- **Opcje:** A) obecność; B) czas per zlecenie; C) szczegółowa aktywność.
- **Konsekwencje:** wpływa na dane osobowe, koszt pracy, akceptację pracowników i raportowanie.
- **Rekomendacja:** B, jeśli koszt pracy ma zasilać kalkulację; ograniczyć dane do niezbędnego celu.
- **Zależne procesy:** HR-002–004, COST-002–003, PRD-004.

## BD-14 — Zasady rozbieżności dostawy i dopasowania dokumentów

- **Kontekst:** nie określono tolerancji ilości, ceny i jakości ani roli faktury zakupu.
- **Opcje:** A) blokada każdej różnicy; B) tolerancje; C) przyjęcie bez kontroli dokumentowej.
- **Konsekwencje:** wpływa na zapas, zobowiązania, ocenę dostawcy i czas obsługi.
- **Rekomendacja:** B, z progami zależnymi od typu różnicy.
- **Zależne procesy:** PUR-002–004, INV-001, COST-001.

## BD-15 — Retencja danych i usuwanie danych osobowych

- **Kontekst:** okresy prawne i polityka prywatności nie są opisane w audycie.
- **Opcje:** A) jedna retencja globalna; B) retencja per kategoria i jurysdykcja; C) bezterminowo.
- **Konsekwencje:** wpływa na audyt, RODO, dokumenty, HR i koszty utrzymania.
- **Rekomendacja:** B; anonimizacja tam, gdzie obowiązek audytowy nie pozwala usunąć zdarzenia.
- **Zależne procesy:** HR-001–004, SAL-001–005, DOC-001–002, ADM-004, audyt.
