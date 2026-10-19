<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Mazer Admin Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@300;400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?=ROOT?>/assets/css/bootstrap.css">
    <link rel="stylesheet" href="<?=ROOT?>/assets/vendors/bootstrap-icons/bootstrap-icons.css">
    <link rel="stylesheet" href="<?=ROOT?>/assets/css/app.css">
    <link rel="stylesheet" href="<?=ROOT?>/assets/css/pages/auth.css">
</head>

<body>
<div id="auth">
    <div class="row h-100">
        <div class="col-lg-5 col-12">
            <div id="auth-left">
                <div class="auth-logo">
                    <a href="index.html"><img src="" alt="Logo"></a>
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
