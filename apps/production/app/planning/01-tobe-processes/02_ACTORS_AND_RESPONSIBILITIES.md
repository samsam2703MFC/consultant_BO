# Aktorzy i odpowiedzialności

| Aktor | Może | Nie może bez dodatkowej akceptacji |
|---|---|---|
| Administrator bezpieczeństwa | konta, role, zakres dostępu, blokady | zmieniać sprzedaży, kosztów i stock |
| Administrator ERP | słowniki, numeracje, szablony, integracje | edytować zatwierdzonych dokumentów |
| Opiekun danych podstawowych | produkty, materiały, jednostki, opakowania | aktywować receptury technologiczne |
| Technolog | wersje receptur, normy, oczekiwany odpad | zmieniać aktywnej wersji w miejscu |
| Handlowiec | klient, oferta, zamówienie B2B, proforma | potwierdzać przekroczonego limitu kredytowego |
| Kasjer/POS | sprzedaż detaliczna i płatność | anulować zamkniętej sprzedaży bez kierownika |
| Kierownik sprzedaży | promocje, limity, anulacje/korekty | korygować księgi magazynowej ręcznie |
| Planista | prognoza, zapotrzebowanie, plan i zlecenie | zwalniać partii po QC |
| Operator produkcji | start, raport ilości, zużycie, odpad | zmieniać norm/receptury i zamykać odchyleń |
| Brygadzista | akceptacja wykonania i korekt produkcji | zatwierdzać własnej korekty ponad próg |
| Kontroler jakości | inspekcja, zwolnienie, odrzucenie/kwarantanna | zmieniać ilości bez ruchu/korekty |
| Magazynier | przyjęcie, wydanie, transfer, liczenie | księgować korekt różnic ponad próg |
| Kierownik magazynu | akceptacja różnic i korekt | zmieniać zamkniętego okresu |
| Kupiec | requisition, PO, dostawca, reklamacja | zatwierdzać PO ponad limit własny |
| Odbiorca dostawy | rejestracja dostawy i rozbieżności | zmieniać ceny PO po przyjęciu |
| Logistyk | shipment, przewoźnik, CMR, dispatch | uznawać wysyłki za sprzedaż finansową |
| Pracownik | własny czas, zadania, nieobecność | zatwierdzać własnego czasu |
| Kierownik zespołu | grafik, zadania, akceptacja czasu | zmieniać historycznych wpisów bez korekty |
| Controller/księgowość | koszty, okres, faktury, korekty finansowe | usuwać zatwierdzonych zapisów |
| Audytor | odczyt danych, audit trail, raporty | wykonywać komend mutujących |
| Operator integracji | retry, mapping, anulowanie błędnego eventu | zmieniać payloadu pochodzącego bez korekty źródłowej |
| System | automatyczne rezerwacje, kalkulacje, dokumenty i zdarzenia | omijać reguł uprawnień i idempotencji |

## Segregacja obowiązków w MVP

Pełna korporacyjna separacja ról nie jest potrzebna, lecz system musi wspierać zasadę „wykonujący ≠ zatwierdzający” dla:

- korekty magazynowej ponad konfigurowany próg;
- ponownego otwarcia okresu;
- korekty kosztu po zamknięciu;
- zwrotu/anulacji finansowej ponad limit;
- zmiany aktywnej receptury przez wydanie nowej wersji;
- ręcznej naprawy rekordu administracyjnego.

W małej organizacji jedna osoba może posiadać kilka ról, ale audit log zapisuje, w której roli wykonała daną akcję.

