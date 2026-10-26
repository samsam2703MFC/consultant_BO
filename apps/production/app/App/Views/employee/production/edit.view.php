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
                    <h4 class="card-title">Edycja pracownika produkcji</h4>
                </div>
                <div class="card-body">

                    <div class="row">
                        <div class="col-2"></div>
                        <div class="col-8">
                            <?php if(!empty($errors)):?>
                                <div class="alert alert-danger text-center"><?=implode("<br>", $errors)?></div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if (!empty($created)):
                        $todo = [['Telefon i identyfikator', ROOT . '/workshop/execution#people'], ['Godziny pracy', ROOT . '/workshop/execution#hours'], ['Karta stanowiska', ROOT . '/organization/positions'], ['Premie i budżet', ROOT . '/staff/budget']];
                        ?>
                        <div class="at-note at-note-safe mb-3" role="status">
                            <i class="bi bi-check-circle"></i>
                            <div><strong>Pracownik utworzony.</strong> Dział, koszt, zespół, płaca i dostęp do tabletu są zapisane ; karta poniżej.
                                <?php if (!empty($created['problems'])): ?><div class="text-danger mt-1"><?= implode('<br>', array_map('htmlspecialchars', $created['problems'])) ?></div><?php endif; ?>
                                <div class="mt-1">Do uzupełnienia : <?= implode(' · ', array_map(fn($t) => '<a href="' . htmlspecialchars($t[1]) . '">' . htmlspecialchars($t[0]) . '</a>', $todo)) ?></div>
                            </div>
                        </div>
                    <?php endif; ?>
                    <form method="POST">
                        <div class="row">
                            <div class="col-2"></div>
                            <div class="col-4">
                                <label class="form-label" for="name">
                                    Imię
                                </label>
                                <input value="<?=old_value('name', $row['name'])?>" type="text" class="form-control" id="name" name="name" placeholder="Imię">
                            </div>
                            <div class="col-4">
                                <label class="form-label" for="surname">
                                    Nazwisko
                                </label>
                                <input value="<?=old_value('surname', $row['surname'])?>" type="text" class="form-control" id="surname" name="surname" placeholder="Nazwisko">
                            </div>
                        </div>

                        <div class="row mt-4">
                            <div class="col-2"></div>
                            <div class="col-4">
                                <div class="row">
                                    <label class="form-label" for="id_section">
                                        Dział produkcyjny
                                    </label>
                                    <select class="form-select" name="id_section" id="id_section">
                                        <?php if(!empty($production_sections)): ?>
                                            <?php foreach($production_sections as $section): ?>
                                                <option value="<?=$section['id']?>" <?=old_select('id_section', $section['id'], $row['id_section'])?>><?=$section['name']?></option>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-4">
                                <label class="form-label" for="cost">
                                    Koszt [zł]
                                </label>
                                <input value="<?=old_value('cost', $row['cost'])?>" type="text" class="form-control" id="cost" name="cost" placeholder="Koszt">
                            </div>
                        </div>


                        <div class="row mt-4">
                            <div class="col-2"></div>
                            <div class="col-8">
                                <button class="btn btn-warning">Edytuj</button>
                                <a href="<?=ROOT?>/production-employees" role="button" class="btn btn-secondary float-end">Powrót</a>
                            </div>
                        </div>

                    </form>
                </div>
            </div>
        </section>
    </div>

<?php $this->view("page_components/footer"); ?>