<?php $this->view("page_components/header"); ?>

    <div class="page-heading">
        <div class="page-title">
            <div class="row">
                <div class="col-12 col-md-6 order-md-1 order-last">
                    <h3>Pracownik</h3>
                </div>
                <div class="col-12 col-md-6 order-md-2 order-first">
                    <nav aria-label="breadcrumb" class="breadcrumb-header float-start float-lg-end">
                        <ol class="breadcrumb">
                            <li class="breadcrumb-item"><a href="<?=ROOT?>/dashboard">Dashboard</a></li>
                            <li class="breadcrumb-item active" aria-current="page">Pracownik
                            </li>
                        </ol>
                    </nav>
                </div>
            </div>
        </div>
        <section class="section">
            <div class="card">
                <div class="card-header">
                    <h4 class="card-title">Nowy pracownik produkcji</h4>
                </div>
                <div class="card-body">

                    <?php if(!empty($errors)):?>
                        <div class="alert alert-danger"><?= implode("<br>", array_map('htmlspecialchars', $errors)) ?></div>
                    <?php endif; ?>
                    <?php $steps_error = ''; foreach (['name', 'surname', 'id_section', 'cost'] as $f) { if (!empty($errors[$f])) { $steps_error = $f; break; } } ?>
                    <form method="POST" data-at-steps data-at-steps-error="<?= htmlspecialchars($steps_error) ?>" class="at-form-steps" autocomplete="off">
                        <section data-step="Osoba i dział">
                            <h4 class="at-step-title">Osoba, dział i grupa</h4>
                            <p class="at-step-lead">Dział rozlicza koszt (Rentowność), grupa to zespół na tablecie i w planie.</p>
                            <div class="row g-3">
                                <div class="col-12 col-md-6"><label class="form-label" for="name">Imię *</label>
                                    <input value="<?= old_value('name') ?>" type="text" class="form-control" id="name" name="name" placeholder="Imię" required></div>
                                <div class="col-12 col-md-6"><label class="form-label" for="surname">Nazwisko *</label>
                                    <input value="<?= old_value('surname') ?>" type="text" class="form-control" id="surname" name="surname" placeholder="Nazwisko" required></div>
                                <div class="col-12 col-md-6"><label class="form-label" for="id_section">Dział produkcyjny *</label>
                                    <select class="form-select" name="id_section" id="id_section" required>
                                        <?php foreach ($production_sections ?? [] as $section): ?>
                                            <option value="<?= $section['id'] ?>" <?= old_select('id_section', $section['id']) ?>><?= htmlspecialchars((string) $section['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select></div>
                                <div class="col-12 col-md-6"><label class="form-label" for="primary_group_id">Grupa (zespół)</label>
                                    <select class="form-select" name="primary_group_id" id="primary_group_id">
                                        <option value="">— bez grupy —</option>
                                        <?php foreach ($groups ?? [] as $g): ?>
                                            <option value="<?= (int) $g['id'] ?>" <?= old_select('primary_group_id', $g['id']) ?>><?= htmlspecialchars((string) $g['name_pl']) ?></option>
                                        <?php endforeach; ?>
                                    </select></div>
                            </div>
                        </section>

                        <section data-step="Koszt i płaca" hidden>
                            <h4 class="at-step-title">Koszt i płaca</h4>
                            <p class="at-step-lead">Koszt godziny liczy koszt produkcji ; podstawa i premia to Budżet personelu (jedno miejsce — nie wpisujesz ich drugi raz w kalkulatorze).</p>
                            <div class="row g-3">
                                <div class="col-6 col-md-4"><label class="form-label" for="cost">Koszt godziny [zł] *</label>
                                    <input value="<?= old_value('cost') ?>" type="number" min="0.01" step="0.01" class="form-control" id="cost" name="cost" placeholder="np. 38,50" required></div>
                                <div class="col-6 col-md-4"><label class="form-label" for="base_monthly">Podstawa miesięczna [zł]</label>
                                    <input value="<?= old_value('base_monthly') ?>" type="number" min="0" step="0.01" class="form-control" id="base_monthly" name="base_monthly" placeholder="minimum"></div>
                                <div class="col-12 col-md-4 d-flex align-items-end">
                                    <div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" role="switch" id="bonus_eligible" name="bonus_eligible" value="1" <?= old_checkbox('bonus_eligible', 1) ?>>
                                        <label class="form-check-label" for="bonus_eligible">Uprawniony do premii grupy</label></div></div>
                            </div>
                        </section>

                        <section data-step="Stanowisko" hidden>
                            <h4 class="at-step-title">Stanowisko i poziom</h4>
                            <p class="at-step-lead">Stanowisko daje kartę stanowiska i zadania ; poziom (np. junior, samodzielny) do matrycy kompetencji.</p>
                            <div class="row g-3">
                                <div class="col-12 col-md-6"><label class="form-label" for="position_id">Stanowisko</label>
                                    <select class="form-select" name="position_id" id="position_id">
                                        <option value="">— bez stanowiska —</option>
                                        <?php foreach ($positions ?? [] as $pos): ?>
                                            <option value="<?= (int) $pos['id'] ?>" <?= old_select('position_id', $pos['id']) ?>><?= htmlspecialchars((string) $pos['name_pl']) ?></option>
                                        <?php endforeach; ?>
                                    </select></div>
                                <div class="col-8 col-md-4"><label class="form-label" for="level_name">Poziom</label>
                                    <input value="<?= old_value('level_name') ?>" type="text" maxlength="100" class="form-control" id="level_name" name="level_name" placeholder="np. samodzielny"></div>
                                <div class="col-4 col-md-2"><label class="form-label" for="level_order">Nr poziomu</label>
                                    <input value="<?= old_value('level_order') ?>" type="number" min="0" max="100" class="form-control" id="level_order" name="level_order"></div>
                            </div>
                        </section>

                        <section data-step="Tablet" hidden>
                            <h4 class="at-step-title">Tablet : PIN i badge</h4>
                            <p class="at-step-lead">PIN 4–6 cyfr do logowania na tablecie grupy ; badge opcjonalnie. Zapisywane tylko jako skrót — nikt ich potem nie odczyta.</p>
                            <div class="row g-3">
                                <div class="col-6 col-md-3"><label class="form-label" for="pin">PIN</label>
                                    <input type="password" inputmode="numeric" pattern="\d{4,6}" maxlength="6" class="form-control" id="pin" name="pin" placeholder="4–6 cyfr" autocomplete="new-password" data-summary-label="PIN"></div>
                                <div class="col-6 col-md-5"><label class="form-label" for="badge">Numer badge</label>
                                    <input type="password" class="form-control" id="badge" name="badge" maxlength="64" placeholder="opcjonalnie" autocomplete="off" data-summary-label="Badge"></div>
                            </div>
                        </section>

                        <section data-step="Podsumowanie" data-at-steps-summary hidden>
                            <h4 class="at-step-title">Podsumowanie</h4>
                            <p class="at-step-lead">Sprawdź i utwórz pracownika. Następnie otworzy się jego karta.</p>
                            <div data-at-steps-summary-body></div>
                        </section>

                        <a href="<?= ROOT ?>/production-employees" class="btn btn-link px-0">Anuluj</a>
                        <button class="btn btn-success" data-at-steps-submit><i class="bi bi-check2"></i> Utwórz pracownika</button>
                    </form>
                </div>
            </div>
    </section>
</div>

<?php $this->view("page_components/footer")?>
<script src="<?= ROOT ?>/assets/js/at-form-steps.js?v=<?= \App\Core\Twig\AppExtension::assetVersion() ?>"></script>
