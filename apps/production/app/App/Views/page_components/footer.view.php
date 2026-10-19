<div id="snackbar"></div>
<footer>
    <div class="footer clearfix mb-0 text-muted">
        <div class="float-start">
            <p>2021 &copy; Mazer</p>
        </div>
        <div class="float-end">
            <p>Crafted with <span class="text-danger"><i class="bi bi-heart-fill icon-mid"></i></span>
                by <a href="https://ahmadsaugi.com">Saugi</a></p>
        </div>
    </div>
</footer>
</div>
</div>
</div>

<?php $this->view("page_components/app_notifications"); ?>

<script src="<?=ROOT?>/assets/vendors/perfect-scrollbar/perfect-scrollbar.min.js"></script>
<script src="<?=ROOT?>/assets/js/bootstrap.bundle.min.js"></script>
<script src="<?=ROOT?>/assets/vendors/choices.js/choices.min.js"></script>
<script src="<?=ROOT?>/assets/js/main.js"></script>
<script src="<?=ROOT?>/assets/js/mazer.js"></script>
<script src="<?=ROOT?>/assets/vendors/sweetalert2/sweetalert2.all.min.js"></script>
<script type="module" src="<?=ROOT?>/assets/js/modules.js"></script>

<script>
    function showSnack(resp, error_message = "Coś poszło nie tak") {

        var x = document.getElementById("snackbar");

        if(resp){
            x.innerText = "Sukces";
            x.style.backgroundColor = '#4cd038';
        } else {
            x.innerText = error_message;
            x.style.backgroundColor = '#ff6666';
        }
        x.className = "show";

        // After 3 seconds, remove the show class from DIV
        setTimeout(function(){ x.className = x.className.replace("show", ""); }, 3000);
    }

    (function initChoicesGlobalObserver() {
        function init(root = document) {
            if (typeof Choices !== 'function') return;

            root.querySelectorAll('select.choices').forEach((el) => {
                if (el.dataset.choicesInited) return;

                new Choices(el, {
                    searchEnabled: true,
                    shouldSort: false,
                    itemSelectText: '',
                    searchResultLimit: 20,
                });

                el.dataset.choicesInited = '1';
            });
        }

        document.addEventListener('DOMContentLoaded', () => {
            init();

            const obs = new MutationObserver((mutations) => {
                for (const m of mutations) {
                    for (const node of m.addedNodes) {
                        if (!(node instanceof Element)) continue;

                        if (node.matches?.('select.choices')) init(node.parentNode || document);
                        if (node.querySelector?.('select.choices')) init(node);
                    }
                }
            });

            obs.observe(document.body, { childList: true, subtree: true });
        });

        window.initChoices = init; // opcjonalnie, gdybyś chciał ręcznie wywołać po ajaxie
    })();
</script>
</body>

</html>
