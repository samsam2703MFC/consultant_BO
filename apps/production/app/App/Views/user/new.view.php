<?php $this->view("page_components/header"); ?>

    <div class="page-heading">
        <div class="page-title">
            <div class="row">
                <div class="col-12 col-md-6 order-md-1 order-last">
                    <h3>Nowy użytkownik</h3>
                </div>
                <div class="col-12 col-md-6 order-md-2 order-first">
                    <nav aria-label="breadcrumb" class="breadcrumb-header float-start float-lg-end">
                        <ol class="breadcrumb">
                            <li class="breadcrumb-item"><a href="<?=ROOT?>/dashboard">Dashboard</a></li>
                            <li class="breadcrumb-item active" aria-current="page">Nowy użytkownik
                            </li>
                        </ol>
                    </nav>
                </div>
            </div>
        </div>
    <section class="section">
        <div class="card">
            <div class="card-header">
                <h4 class="card-title">Nowy użytkownik ERP</h4>
            </div>
            <div class="card-body">

                <?php if(!empty($errors)):?>
                    <div class="alert alert-danger"><?= implode("<br>", array_map('htmlspecialchars', $errors)) ?></div>
                <?php endif; ?>
                <?php $steps_error = ''; foreach (['name', 'surname', 'displayed_name', 'email', 'phone', 'login', 'password'] as $f) { if (!empty($errors[$f])) { $steps_error = $f; break; } } ?>
                <form method="POST" data-at-steps data-at-steps-error="<?= htmlspecialchars($steps_error) ?>" class="at-form-steps" autocomplete="off">
                    <input type="hidden" name="permission" value="2">
                    <section data-step="Osoba">
                        <h4 class="at-step-title">Osoba</h4>
                        <p class="at-step-lead">Imię i nazwisko widać w ERP (zadania, historia zmian) ; e-mail i telefon do powiadomień.</p>
                        <div class="row g-3">
                            <div class="col-12 col-md-6"><label class="form-label" for="name">Imię *</label>
                                <input value="<?= old_value('name') ?>" type="text" id="name" class="form-control" name="name" required></div>
                            <div class="col-12 col-md-6"><label class="form-label" for="surname">Nazwisko *</label>
                                <input value="<?= old_value('surname') ?>" type="text" id="surname" class="form-control" name="surname" required></div>
                            <div class="col-12 col-md-6"><label class="form-label" for="displayed_name">Wyświetlana nazwa</label>
                                <input value="<?= old_value('displayed_name') ?>" type="text" id="displayed_name" class="form-control" name="displayed_name" placeholder="imię i nazwisko"></div>
                            <div class="col-12 col-md-6"><label class="form-label" for="email">E-mail *</label>
                                <input value="<?= old_value('email') ?>" type="email" id="email" class="form-control" name="email" required></div>
                            <div class="col-12 col-md-6"><label class="form-label" for="phone">Telefon *</label>
                                <input value="<?= old_value('phone') ?>" type="tel" id="phone" class="form-control" name="phone" required></div>
                        </div>
                    </section>

                    <section data-step="Logowanie" hidden>
                        <h4 class="at-step-title">Logowanie</h4>
                        <p class="at-step-lead">Login musi być unikalny ; hasło co najmniej 8 znaków (zapisywane tylko jako skrót).</p>
                        <div class="row g-3">
                            <div class="col-12 col-md-6"><label class="form-label" for="login">Login *</label>
                                <input value="<?= old_value('login') ?>" type="text" id="login" class="form-control" name="login" required autocomplete="off"></div>
                            <div class="col-12 col-md-6"><label class="form-label" for="password">Hasło *</label>
                                <input type="password" autocomplete="new-password" id="password" class="form-control" name="password" minlength="8" required></div>
                        </div>
                    </section>

                    <section data-step="Stanowisko i dostęp" hidden>
                        <h4 class="at-step-title">Stanowisko, rola i strona startowa</h4>
                        <p class="at-step-lead">Stanowisko decyduje, które strony osoba widzi, i daje jej zadania stanowiska. Podgląd poniżej zmienia się z wyborem.</p>
                        <div class="row g-3">
                            <div class="col-12 col-md-6"><label class="form-label" for="position_id">Stanowisko</label>
                                <select class="form-select" name="position_id" id="position_id">
                                    <option value="">— bez stanowiska —</option>
                                    <?php foreach ($positions ?? [] as $pos): ?>
                                        <option value="<?= (int) $pos['id'] ?>" <?= old_select('position_id', $pos['id']) ?>><?= htmlspecialchars((string) $pos['name']) ?></option>
                                    <?php endforeach; ?>
                                </select></div>
                            <div class="col-12 col-md-6"><label class="form-label" for="role_code">Rola w module produkcji</label>
                                <?php if (!empty($can_set_role)): ?>
                                    <select class="form-select" name="role_code" id="role_code">
                                        <option value="">— bez roli —</option>
                                        <?php foreach ($roles ?? [] as $r): ?>
                                            <option value="<?= htmlspecialchars((string) $r['code']) ?>" <?= old_select('role_code', $r['code']) ?>><?= htmlspecialchars((string) $r['name_pl']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php else: ?>
                                    <div class="form-text mt-2">Rolę nadaje administrator produkcji (karta użytkownika).</div>
                                <?php endif; ?></div>
                            <?php if (!empty($can_set_home)): ?>
                                <div class="col-12 col-md-6"><label class="form-label" for="home_page">Strona startowa stanowiska</label>
                                    <select class="form-select" name="home_page" id="home_page" data-summary-label="Strona startowa">
                                        <?php foreach ($home_pages ?? [] as $path => $label): ?>
                                            <option value="<?= htmlspecialchars((string) $path) ?>"><?= htmlspecialchars((string) $label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="form-text">Dotyczy wszystkich osób na tym stanowisku.</div></div>
                            <?php endif; ?>
                        </div>
                        <div class="at-access-preview mt-3" data-access-preview aria-live="polite"></div>
                    </section>

                    <section data-step="Podsumowanie" data-at-steps-summary hidden>
                        <h4 class="at-step-title">Podsumowanie</h4>
                        <p class="at-step-lead">Sprawdź i utwórz konto. Następnie otworzy się karta użytkownika.</p>
                        <div data-at-steps-summary-body></div>
                    </section>

                    <a href="<?= ROOT ?>/users" class="btn btn-link px-0">Anuluj</a>
                    <button class="btn btn-success" data-at-steps-submit><i class="bi bi-check2"></i> Utwórz użytkownika</button>
                </form>
            </div>
        </div>
    </section>
</div>

<?php $this->view("page_components/footer")?>
<script src="<?= ROOT ?>/assets/js/at-form-steps.js?v=<?= \App\Core\Twig\AppExtension::assetVersion() ?>"></script>
<script>
    // Podgląd dostępu : strony stanowiska według sekcji, strona startowa ; rola « administrator produkcji » widzi wszystko.
    (function () {
        const preview = <?= json_encode($preview ?? new stdClass(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
        const box = document.querySelector('[data-access-preview]');
        const pos = document.getElementById('position_id');
        const role = document.getElementById('role_code');
        const home = document.getElementById('home_page');
        const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
        function render(syncHome) {
            const p = preview[pos.value || '0'] || { home: 'Dzisiaj', home_path: '/today', sections: {} };
            if (home && syncHome) home.value = p.home_path;
            if (role && role.value === 'prod_admin') {
                box.innerHTML = '<div class="at-note at-note-safe"><i class="bi bi-shield-check"></i><div><strong>Administrator produkcji</strong> : widzi wszystkie strony, także Uprawnienia stanowisk i moduł kierownika ; strona startowa Rentowność.</div></div>';
                return;
            }
            const sections = Object.entries(p.sections);
            const count = sections.reduce((n, [, pages]) => n + pages.length, 0);
            box.innerHTML = '<h6 class="mb-1">Ta osoba zobaczy ' + count + ' stron(y) z menu + Dzisiaj i Moje zadania · start : ' + esc(home ? home.selectedOptions[0].textContent : p.home) + '</h6>'
                + (count ? '<dl class="at-steps-summary">' + sections.map(([s, pages]) => '<dt>' + esc(s) + '</dt><dd>' + pages.map(esc).join(' · ') + '</dd>').join('') + '</dl>'
                         : '<p class="text-muted mb-0">Stanowisko nie ma jeszcze stron — ustaw je w Uprawnieniach stanowisk.</p>');
        }
        pos.addEventListener('change', () => render(true));
        if (role) role.addEventListener('change', () => render(false));
        if (home) home.addEventListener('change', () => render(false));
        const name = document.getElementById('name'), surname = document.getElementById('surname'), shown = document.getElementById('displayed_name');
        const fill = () => { shown.placeholder = (name.value + ' ' + surname.value).trim() || 'imię i nazwisko'; };
        name.addEventListener('input', fill); surname.addEventListener('input', fill);
        fill();
        render(true);
    })();
</script>