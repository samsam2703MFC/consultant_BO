<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connexion · Production · L'Atelier</title>
    <link rel="stylesheet" href="<?=ROOT?>/assets/css/bootstrap.css">
    <link rel="stylesheet" href="<?=ROOT?>/assets/vendors/bootstrap-icons/bootstrap-icons.css?v=1.11.3">
    <link rel="stylesheet" href="<?=ROOT?>/assets/css/app.css">
    <link rel="stylesheet" href="<?=ROOT?>/assets/css/pages/auth.css">
    <link rel="stylesheet" href="<?=ROOT?>/assets/css/atelier.css?v=<?= \App\Core\Twig\AppExtension::assetVersion() ?>">
    <link rel="stylesheet" href="<?=ROOT?>/assets/css/ds.css?v=<?= \App\Core\Twig\AppExtension::assetVersion() ?>">
    <link rel="stylesheet" href="<?=ROOT?>/assets/css/login-qr.css?v=<?= \App\Core\Twig\AppExtension::assetVersion() ?>">
</head>

<body>
<div id="auth">
    <div class="row h-100">
        <div class="col-lg-5 col-12">
            <div id="auth-left">
                <div class="auth-logo">
                    <img src="<?=ROOT?>/assets/images/atelier-logo.png" alt="L'Atelier">
                </div>
                <h1 class="auth-title">Zaloguj się</h1>
                <p class="auth-subtitle mb-5"></p>

                <div class="row">
                    <div class="col-12">
                        <?php if(!empty($errors)):?>
                            <div class="alert alert-danger text-center"><?=implode("<br>", $errors)?></div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (!empty($entreeLibre)): ?>
                <form method="POST">
                    <p class="text-muted">Serveur de test : accès sans mot de passe.</p>
                    <button type="submit" class="btn btn-primary btn-block btn-lg shadow-lg mt-5">Entrer</button>
                </form>
                <?php else: ?>
                <?php if (!empty($qrLogin)): ?>
                <?php /* [300] Logowanie kodem QR (makieta 55) : telefon osoby otwiera jej sesję i zapisuje wejście. */ ?>
                <section class="lq" id="loginQr" data-root="<?=ROOT?>" data-choose="<?= !empty($qrSections) ? '1' : '' ?>"<?= (!empty($showPassword) || !empty($errors)) ? ' hidden' : '' ?>>
                    <p class="lq-lead"><?= !empty($qrSections) ? 'Wybierz po prawej swoją sekcję i nazwisko, potem zeskanuj swój kod telefonem — ERP otworzy Twoją sesję i zapisze wejście do pracy.' : 'Zeskanuj kod swoim telefonem — ERP otworzy Twoją sesję i zapisze wejście do pracy.' ?></p>
                    <p class="lq-who" data-lq-who hidden></p>
                    <div class="lq-qr"><div class="lq-empty" data-lq-empty<?= empty($qrSections) ? ' hidden' : '' ?>><i class="bi bi-arrow-right-circle"></i><span>Wybierz sekcję i nazwisko po prawej</span></div>
                        <canvas data-lq-qr aria-label="Kod QR logowania"></canvas><div class="lq-done" data-lq-done hidden><span>✓</span><b>Otwieram ERP…</b></div></div>
                    <p class="lq-meta" data-lq-meta><?= !empty($qrSections) ? 'Kod pojawi się po wyborze nazwiska.' : 'Wczytywanie kodu…' ?></p>
                    <ol class="lq-steps"><?php if (!empty($qrSections)): ?><li><b>1</b>Po prawej : sekcja → nazwisko</li><li><b>2</b>Aparat telefonu → kod</li><li><b>3</b>Na telefonie : pytania i « Wchodzę » — ten ekran otwiera Twoją sesję</li><?php else: ?><li><b>1</b>Aparat telefonu → kod</li><li><b>2</b>Na telefonie : pytania i « Wchodzę »</li><li><b>3</b>Ten ekran otwiera Twoją sesję</li><?php endif; ?></ol>
                    <button type="button" class="lq-link" data-lq-password>Nie mam telefonu — zaloguj hasłem</button>
                </section>
                <div id="loginPassword"<?= (!empty($showPassword) || !empty($errors)) ? '' : ' hidden' ?>>
                    <form method="POST">
                        <div class="form-group position-relative has-icon-left mb-4">
                            <input value="<?=old_value('login')?>" type="text" class="form-control form-control-xl" placeholder="Login" name="login">
                            <div class="form-control-icon">
                                <i class="bi bi-person"></i>
                            </div>
                        </div>
                        <div class="form-group position-relative has-icon-left mb-4">
                            <input value="<?=old_value('password')?>" type="password" class="form-control form-control-xl" placeholder="Hasło" name="password">
                            <div class="form-control-icon">
                                <i class="bi bi-shield-lock"></i>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary btn-block btn-lg shadow-lg mt-5">Zaloguj</button>
                    </form>
                    <button type="button" class="lq-link mt-4" data-lq-back>← Zaloguj kodem QR</button>
                </div>
                <script defer src="<?=ROOT?>/assets/js/login-qr.js?v=<?= \App\Core\Twig\AppExtension::assetVersion() ?>"></script>
                <?php else: ?>
                <form method="POST">
                    <div class="form-group position-relative has-icon-left mb-4">
                        <input value="<?=old_value('login')?>" type="text" class="form-control form-control-xl" placeholder="Login" name="login">
                        <div class="form-control-icon">
                            <i class="bi bi-person"></i>
                        </div>
                    </div>
                    <div class="form-group position-relative has-icon-left mb-4">
                        <input value="<?=old_value('password')?>" type="password" class="form-control form-control-xl" placeholder="Hasło" name="password">
                        <div class="form-control-icon">
                            <i class="bi bi-shield-lock"></i>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-block btn-lg shadow-lg mt-5">Zaloguj</button>
                </form>
                <?php endif; ?>
                <?php endif; ?>

            </div>
        </div>
        <div class="col-lg-7<?= !empty($qrLogin) && !empty($qrSections) ? '' : ' d-none d-lg-block' ?>">
            <div id="auth-right">
                <?php if (!empty($qrLogin) && !empty($qrSections)): ?>
                <?php /* [300] Ekran po prawej : sekcja → nazwisko → kod tej osoby po lewej. */ ?>
                <section class="lq-sections" id="loginSections">
                    <h2>Kto się loguje ?</h2>
                    <div class="lq-sec-tabs" role="tablist">
                        <?php foreach ($qrSections as $i => $sec): ?>
                        <button type="button" class="lq-sec<?= $i === 0 ? ' is-on' : '' ?>" data-lq-sec="<?= $i ?>" role="tab"><?= htmlspecialchars($sec['name']) ?> <small><?= count($sec['people']) ?></small></button>
                        <?php endforeach; ?>
                    </div>
                    <?php foreach ($qrSections as $i => $sec): ?>
                    <div class="lq-people" data-lq-people="<?= $i ?>"<?= $i === 0 ? '' : ' hidden' ?>>
                        <?php foreach ($sec['people'] as $p): ?>
                        <button type="button" class="lq-person" data-lq-person="<?= (int) $p['id'] ?>" data-name="<?= htmlspecialchars($p['name']) ?>" data-section="<?= htmlspecialchars($sec['name']) ?>">
                            <span class="lq-av"><?= htmlspecialchars(mb_strtoupper(mb_substr($p['name'], 0, 1))) ?></span><?= htmlspecialchars($p['name']) ?></button>
                        <?php endforeach; ?>
                    </div>
                    <?php endforeach; ?>
                </section>
                <?php endif; ?>
            </div>
        </div>
    </div>

</div>
</body>

</html>
