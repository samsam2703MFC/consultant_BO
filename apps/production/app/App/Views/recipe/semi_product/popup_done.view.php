<?php /** Półprodukt utworzony w oknie kreatora produktu : przekazanie do kreatora (postMessage) i zamknięcie okna. */ ?>
<!doctype html>
<html lang="pl">
<head><meta charset="utf-8"><title>Półprodukt utworzony</title></head>
<body style="font-family: system-ui, sans-serif; padding: 24px;">
<p>Półprodukt « <?= htmlspecialchars($semi['name']) ?> » utworzony — wraca do kreatora produktu.</p>
<script>
    (function () {
        const semi = <?= json_encode($semi, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
        if (window.opener && !window.opener.closed) {
            window.opener.postMessage({ type: 'semi-created', semi: semi }, window.location.origin);
            window.close();
        } else {
            window.location.href = <?= json_encode(ROOT . '/semi-products/') ?> + semi.id + '/calculation';
        }
    })();
</script>
</body>
</html>
