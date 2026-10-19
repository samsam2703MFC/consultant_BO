# Wizualizacja nowego interfejsu edycji zamówienia

## Layout strony

```
╔════════════════════════════════════════════════════════════════════════╗
║                    EDYCJA ZAMÓWIENIA                                    ║
║                    Edytuj szczegóły zamówienia i produkty              ║
╠════════════════════════════════════════════════════════════════════════╣
║  Dashboard > Zamówienia > Edycja                                       ║
╠════════════════════════════════════════════════════════════════════════╣
║                                                                         ║
║  ┌─────────────────┐ ┌─────────────────┐ ┌─────────────────┐         ║
║  │ 👤 Klient       │ │ 📋 Zamówienie   │ │ 🟡 Status        │         ║
║  │ ABC Company     │ │ #12345          │ │ Oczekujące       │         ║
║  │ ID: 789         │ │ Ref: ORD-2026   │ │                  │         ║
║  └─────────────────┘ └─────────────────┘ └─────────────────┘         ║
║                                                                         ║
║  ┌────────────────────────────────────┬─────────────────────────────┐ ║
║  │ 📅 Data wysyłki *                  │ ☑ Standardowy cennik        │ ║
║  │ [2026-03-15__________]             │                              │ ║
║  └────────────────────────────────────┴─────────────────────────────┘ ║
║                                                                         ║
║  ┌────────────────────────────────────────────────────────────────┐   ║
║  │ 💬 Komentarz do zamówienia                                      │   ║
║  │ [_____________________________________________]                  │   ║
║  │ [_____________________________________________]                  │   ║
║  └────────────────────────────────────────────────────────────────┘   ║
║                                                                         ║
║  ╔═══════════════════════════════════════════════════════════════╗   ║
║  ║ 📦 PRODUKTY W ZAMÓWIENIU                                       ║   ║
║  ╠════╤═══════════════════════╤════════╤═══════════╤═══════════╤═╣   ║
║  ║SKU │ Nazwa produktu        │ Ilość  │ Cena      │ Wartość   │⚙║   ║
║  ╠════╪═══════════════════════╪════════╪═══════════╪═══════════╪═╣   ║
║  ║1001│ Baguette Tradition    │ [_5__] │ 31,12 PLN │ 155,60 PLN│🗑║   ║
║  ║1002│ Croissant Classic     │ [_10_] │ 42,50 PLN │ 425,00 PLN│🗑║   ║
║  ║1003│ Pain au Chocolat      │ [_3__] │ 38,00 PLN │ 114,00 PLN│🗑║   ║
║  ╠════╧═══════════════════════╧════════╧═══════════╧═══════════╧═╣   ║
║  ║                            SUMA NETTO: 694,60 PLN              ║   ║
║  ╚═══════════════════════════════════════════════════════════════╝   ║
║                                                                         ║
║  ┌────────────────────────────────────────────────────────────────┐   ║
║  │ Dodaj produkt do zamówienia                                     │   ║
║  ├────────────────────────────────────────────────────────────────┤   ║
║  │ Wybierz produkt           Ilość    Cena         [➕ Dodaj]     │   ║
║  │ [▼ 1004 - Pain ...]      [_1__]  [45,00 PLN]                   │   ║
║  └────────────────────────────────────────────────────────────────┘   ║
║                                                                         ║
║  [💾 Zapisz zmiany]  [⬅ Anuluj]                                       ║
║                                                                         ║
╚════════════════════════════════════════════════════════════════════════╝
```

## Kolorystyka

### Karty informacyjne (Header)
- **Tło**: Jasny szary (#f8f9fa)
- **Tekst nagłówka**: Szary przyciemniony (#6c757d)
- **Tekst główny**: Ciemny (#212529)

### Status Badge
- **Oczekujące**: 🟡 Żółty (#ffc107)
- **Zrealizowane**: 🟢 Zielony (#198754)
- **W transporcie**: 🔵 Niebieski (#0dcaf0)

### Tabela produktów
- **Header**: Jasny szary z obramowaniem
- **Hover**: Bardzo jasny szary (#f8f9fa)
- **Wartość liniowa**: Zielony (#198754)
- **Suma całkowita**: Niebieski primary (#0d6efd)

### Przyciski
- **Dodaj**: Zielony success (#198754)
- **Zapisz**: Niebieski primary (#0d6efd)
- **Usuń**: Czerwony danger (#dc3545)
- **Anuluj**: Szary secondary (#6c757d)

## Responsywność

### Desktop (>1200px)
```
┌────────┬────────┬────────┐
│ Klient │ Zamów. │ Status │
└────────┴────────┴────────┘

┌────────────┬────────────┐
│ Data       │ Cennik     │
└────────────┴────────────┘

┌──────────────────────────┐
│ Komentarz                │
└──────────────────────────┘

┌──────────────────────────┐
│ Tabela produktów (pełna) │
└──────────────────────────┘
```

### Tablet (768px - 1199px)
```
┌────────┬────────┬────────┐
│ Klient │ Zamów. │ Status │
└────────┴────────┴────────┘

┌────────────────────────┐
│ Data                   │
└────────────────────────┘
┌────────────────────────┐
│ Cennik                 │
└────────────────────────┘

┌────────────────────────┐
│ Komentarz              │
└────────────────────────┘

┌────────────────────────┐
│ Tabela produktów       │
│ (zmniejszone kolumny)  │
└────────────────────────┘
```

### Mobile (<768px)
```
┌──────────────┐
│ Klient       │
└──────────────┘
┌──────────────┐
│ Zamówienie   │
└──────────────┘
┌──────────────┐
│ Status       │
└──────────────┘

┌──────────────┐
│ Data         │
└──────────────┘

┌──────────────┐
│ Cennik       │
└──────────────┘

┌──────────────┐
│ Komentarz    │
└──────────────┘

┌──────────────┐
│ Produkty     │
│ (pionowo)    │
└──────────────┘
```

## Interakcje

### 1. Wybór produktu
```
[Kliknięcie w select] → [Lista produktów] → [Wybór] → [Auto-wypełnienie ceny]
```

### 2. Dodawanie produktu
```
[Wybór produktu] → [Wpisanie ilości] → [Kliknięcie "Dodaj"]
    ↓
[Walidacja]
    ↓
[Dodanie do tabeli] → [Przeliczenie sumy] → [Powiadomienie]
```

### 3. Zmiana ilości
```
[Kliknięcie w pole ilości] → [Wpisanie nowej wartości]
    ↓
[Real-time walidacja]
    ↓
[Przeliczenie wartości liniowej] → [Przeliczenie sumy]
```

### 4. Usuwanie produktu
```
[Kliknięcie 🗑] → [Potwierdzenie] → [Usunięcie wiersza] → [Przeliczenie sumy]
```

### 5. Zapisywanie
```
[Kliknięcie "Zapisz"] → [Walidacja formularza]
    ↓
    ├─→ [Błąd] → [Wyświetlenie komunikatów]
    │
    └─→ [OK] → [POST do serwera] → [Przekierowanie]
```

## Powiadomienia Toast

```
╔════════════════════════════════════╗  ← Prawy górny róg
║ ✅ Produkt dodany do zamówienia    ║
║                                 [×]║
╚════════════════════════════════════╝
```

**Typy:**
- ✅ **Success** (zielony): Operacja zakończona sukcesem
- ℹ️ **Info** (niebieski): Informacja
- ⚠️ **Warning** (żółty): Ostrzeżenie
- ❌ **Danger** (czerwony): Błąd

## Animacje

### Hover effects
- **Wiersze tabeli**: Zmiana tła na #f8f9fa (150ms)
- **Przyciski**: Zmiana koloru (200ms)

### Transitions
- **Dodawanie produktu**: Fade-in (300ms)
- **Usuwanie produktu**: Fade-out (200ms)
- **Toast notifications**: Slide-in z prawej (300ms)

### Focus states
- **Inputy**: Niebieskie obramowanie + cień
- **Select**: Niebieskie obramowanie + cień
- **Przyciski**: Podkreślenie + cień

## Walidacja wizualna

### Błędy
```
┌────────────────────────────┐
│ Ilość *                    │
│ [__0__] ← czerwone obramowanie + ikona ⚠️
│ ⚠️ Ilość musi być większa od 0
└────────────────────────────┘
```

### Sukces
```
┌────────────────────────────┐
│ Ilość *                    │
│ [__5__] ← zielone obramowanie + ikona ✓
└────────────────────────────┘
```

## Ikony Bootstrap Icons

- 📅 `bi-calendar-event` - Data
- 💬 `bi-chat-left-text` - Komentarz
- 📦 `bi-box-seam` - Produkty
- 🗑 `bi-trash` - Usuwanie
- ➕ `bi-plus-circle` - Dodawanie
- 💾 `bi-save` - Zapisywanie
- ⬅ `bi-arrow-left` - Powrót
- ⚠️ `bi-exclamation-triangle` - Błędy

## Skróty klawiaturowe

| Skrót | Akcja |
|-------|-------|
| `Ctrl + Enter` | Dodaj produkt |
| `Tab` | Nawigacja między polami |
| `Enter` (w select) | Potwierdź wybór |
| `Esc` (w dialogu) | Anuluj operację |

## Stany przycisków

### Normalny
```
[💾 Zapisz zmiany]
```

### Hover
```
[💾 Zapisz zmiany] ← jaśniejszy + cień
```

### Loading (opcjonalnie)
```
[⏳ Zapisywanie...]
```

### Disabled
```
[💾 Zapisz zmiany] ← szary + cursor: not-allowed
```

## Przykładowe scenariusze użycia

### Scenariusz 1: Dodanie nowego produktu
1. User otwiera listę produktów
2. Wybiera "1005 - Croissant Amande (48,00 PLN)"
3. Cena automatycznie się wypełnia
4. Wpisuje ilość: 8
5. Klika "Dodaj"
6. Produkt pojawia się w tabeli
7. Suma aktualizuje się: 694,60 PLN → 1078,60 PLN
8. Toast: "✅ Produkt dodany do zamówienia"

### Scenariusz 2: Zmiana ilości
1. User klika w pole ilości dla "Baguette Tradition"
2. Zmienia z 5 na 10
3. Wartość liniowa: 155,60 PLN → 311,20 PLN
4. Suma: 694,60 PLN → 850,20 PLN
5. Wszystko dzieje się w czasie rzeczywistym

### Scenariusz 3: Usuwanie produktu
1. User klika 🗑 przy "Pain au Chocolat"
2. Dialog: "Czy na pewno chcesz usunąć 'Pain au Chocolat' z zamówienia?"
3. User klika "OK"
4. Wiersz znika
5. Suma: 694,60 PLN → 580,60 PLN
6. Toast: "ℹ️ Produkt usunięty z zamówienia"

### Scenariusz 4: Błąd walidacji
1. User próbuje zapisać zamówienie bez produktów
2. System blokuje submit
3. Alert na górze: "❌ Zamówienie musi zawierać przynajmniej jeden produkt"
4. Toast: "❌ Zamówienie musi zawierać przynajmniej jeden produkt"

---

**Uwaga**: Ta wizualizacja używa znaków ASCII/Unicode. Rzeczywisty interfejs wykorzystuje Bootstrap 5 components i jest znacznie bardziej estetyczny.

