# Bezpieczeństwo i uprawnienia

## P0

1. `.prod.env:11-14` zawiera jawne poświadczenie DB. Wartości nie są reprodukowane. Obrócić sekret, zbadać historię Git i logi użycia.
2. `App/Services/Auth/LoginService.php:21-25` porównuje hasła bez `password_verify`; `UserRepository.php:47-50` zapisuje cały rekord użytkownika w sesji.
3. `App/Core/App.php:20-57` nie stosuje globalnego middleware auth/permission. Kontrolery są uruchamiane bez polityki per route widocznej w dispatcherze.
4. Nie znaleziono tokenów CSRF. Operacje mutujące panelu używają POST/PATCH/DELETE.

## P1

- Brak potwierdzonej izolacji tenant/shop; identyfikatory zasobów są podawane w trasach.
- W `Agent` współistnieją nowe JWT/RBAC i stare `LoginToken`; niektóre wywołania autoryzacji są zakomentowane, np. `CourseCompletionByEmployee.php:26`, `InvoiceController.php:174`, `ProductController.php:267`.
- `App/Core/Web/BaseController.php:163-165` dołącza treść wyjątku do odpowiedzi `INTERNAL`, co może ujawnić dane.
- Sesja startuje bez widocznego ustawienia cookie secure/httponly/samesite i bez `session_regenerate_id` po loginie.
- Brak rate limiting, lockout i logu prób logowania.
- Uploady i presigned URLs w Agent wymagają testów MIME, rozmiaru i autoryzacji obiektowej.

## Pozytywne elementy

Agent używa `password_verify` w nowym `AuthService`, krótkiego pre-auth JWT, secure/httponly cookies i repozytoriów uprawnień. Webhook inbox ma claim/release i event ID. Te mechanizmy nie są jednak potwierdzone jako aktywne dla panelu.

## Minimalny plan

Rotacja sekretów → migracja haseł do Argon2id/bcrypt → centralny middleware auth/RBAC/tenant → CSRF → bezpieczne sesje → testy IDOR → audit log → rate limiting i monitoring.

