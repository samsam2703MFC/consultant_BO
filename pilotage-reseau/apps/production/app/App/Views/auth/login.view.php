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
                <section class="lq" id="loginQr" data-root="<?=ROOT?>"<?= (!empty($showPassword) || !empty($errors)) ? ' hidden' : '' ?>>
                    <p class="lq-lead">Zeskanuj kod swoim telefonem — ERP otworzy Twoją sesję i zapisze wejście do pracy.</p>
                    <div class="lq-qr"><canvas data-lq-qr aria-label="Kod QR logowania"></canvas><div class="lq-done" data-lq-done hidden><span>✓</span><b>Otwieram ERP…</b></div></div>
                    <p class="lq-meta" data-lq-meta>Wczytywanie kodu…</p>
                    <ol class="lq-steps"><li><b>1</b>Aparat telefonu → kod</li><li><b>2</b>Na telefonie : pytania i « Wchodzę »</li><li><b>3</b>Ten ekran otwiera Twoją sesję</li></ol>
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
                <script src="<?=ROOT?>/assets/js/login-qr.js?v=<?= \App\Core\Twig\AppExtension::assetVersion() ?>"></script>
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
        <div class="col-lg-7 d-none d-lg-block">
            <div id="auth-right">

            </div>
        </div>
    </div>

</div>
</body>

</html>
