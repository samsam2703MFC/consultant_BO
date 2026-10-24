<?php $this->view("page_components/header"); ?>

<div class="page-heading">
    <div class="page-title">
        <div class="row align-items-center">
            <div class="col-12 col-md-6">
                <h3>Sieć franczyzowa klientów</h3>
                <p class="text-subtitle text-muted">Przypisani klienci do tej sieci franczyzowej.</p>
            </div>
        </div>
    </div>

    <section class="section">
        <div class="card">
            <div class="card-header">
                <h4 class="card-title">Lista przypisanych franczyz</h4>
            </div>
            <div class="card-body">
                <div class="row mt-4">
                    <div class="col-12 text-center">
                        <h4><?= $network['name'] ?></h4>
                    </div>
                    <div class="col-12 text-end">
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#assignClientModal">Przypisz klienta</button>
                    </div>
                    <div class="col-12">
                        <table class="table table-hover">
                            <thead>
                            <tr>
                                <th style="width: 30%">Nazwa klienta</th>
                                <th style="width: 20%">Email</th>
                                <th style="width: 15%">Telefon</th>
                                <th style="width: 15%">Miasto</th>
                                <th style="width: 10%"></th>
                                <th style="width: 10%"></th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php if (!empty($franchises)): ?>
                                <?php foreach ($franchises as $franchise): ?>
                                    <tr>
                                        <td><?= $franchise['name'] ?></td>
                                        <td><?= $franchise['email_head'] ?? '-' ?></td>
                                        <td><?= $franchise['phone_head'] ?? '-' ?></td>
                                        <td><?= $franchise['fac_town'] ?? '-' ?></td>
                                        <td>
                                            <button class="btn btn-danger" onclick="deatachClient(<?= $network['id'] ?>, <?= $franchise['id'] ?>)" type="button">Usuń</button>
                                        </td>
                                        <td>
                                            <?php if(is_null($franchise['franchisee_uid'])): ?>
                                                <button class="btn btn-success" type="button" onclick="runActivationLinkModal(<?=$franchise['id']?>)">Aktywuj</button>
                                            <?php else: ?>
                                                Zintegrowany
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="5" class="text-center">Brak przypisanych klientów.</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="col-12">
                        <a href="<?=ROOT?>/client-franchise-networks" type="button" class="btn btn-secondary">Powrót</a>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<!-- Modal -->
<div class="modal fade" id="assignClientModal" tabindex="-1" aria-labelledby="assignClientModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form id="assign-client-form">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="assignClientModalLabel">Przypisz klienta do sieci</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
                </div>
                <div class="modal-body">
                    <label for="client_id" class="form-label">Wybierz klienta</label>
                    <select id="client_id" name="client_id" class="form-select" required>
                        <option value="">-- Wybierz klienta --</option>
                        <?php
                        $alreadyAssignedIds = array_column($franchises, 'id');
                        foreach ($clients as $client):
                            if (in_array($client['id'], $alreadyAssignedIds)) continue;
                            ?>
                            <option value="<?= $client['id'] ?>">
                                <?= htmlspecialchars($client['company']) ?> (<?= $client['name'] . " " . $client['surname'] ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <input type="hidden" name="network_id" value="<?= $network['id'] ?>">
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-primary">Przypisz</button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Anuluj</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!--<div class="modal fade" id="invitationCodeModal" tabindex="-1" aria-labelledby="invitationCodeModalLabel" aria-hidden="true">-->
<!--    <div class="modal-dialog modal-lg">-->
<!--        <div class="modal-content">-->
<!--            <div class="modal-header">-->
<!--                <h5 class="modal-title" id="invitationCodeModalLabel">Kod zaproszenia</h5>-->
<!--                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>-->
<!--            </div>-->
<!--            <div class="modal-body">-->
<!--                <label class="form-label" for="invitation-code-text">Kod zaproszenia</label>-->
<!--                <div class="input-group mb-3">-->
<!--                    <div class="form-control" readonly id="invitation-code-text" aria-describedby="button-addon1"></div>-->
<!--                </div>-->
<!--            </div>-->
<!--            <div class="modal-footer">-->
<!--                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Zamknij</button>-->
<!--            </div>-->
<!--        </div>-->
<!--    </div>-->
<!--</div>-->


<div class="modal fade" id="activationLinkModal" tabindex="-1" aria-labelledby="activationLinkModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="activationLinkModalLabel">Link aktywacyjny</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="activated_client_id">
                <label class="form-label" for="invitation-code-text">Wklej link aktywacyjny i potwierdź integrację</label>
                <input type="text" class="form-control" id="activation-link" placeholder="Wklej link aktywacyjny tutaj">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" onclick="activateClient(this)">Aktywuj</button>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Zamknij</button>
            </div>
        </div>
    </div>
</div>


<script>
    // Obsługa formularza przypisania klienta (AJAX – sam zaimplementujesz)
    document.getElementById('assign-client-form').addEventListener('submit', function (e) {
        e.preventDefault();

        const formData = new FormData(this);
        const networkId = formData.get('network_id');
        const clientId = formData.get('client_id');

        api(`/ajax/client-franchise-networks/${networkId}/assign-client`, {
            method : "POST",
            data : {
                client_id : clientId
            }
        })
            .then(response => {
                if(response.ok){
                    showSnack(true);
                    setTimeout(() => window.location.reload(), 500);
                } else {
                    showSnack(false, response.error || 'Wystąpił błąd');
                }
            });
    });


    function deatachClient(networkId, clientId) {
        api(`/ajax/client-franchise-networks/${networkId}/deatach-client/${clientId}`, {
            method : "POST",
            data : {
                client_id : clientId
            }
        })
            .then(response => {
                if(response.ok){
                    showSnack(true);
                    setTimeout(() => window.location.reload(), 500);
                } else {
                    showSnack(false, response.error || 'Wystąpił błąd');
                }
            });
    }

    function runActivationLinkModal(idClient){
        const activationLinkModal = new bootstrap.Modal(document.getElementById('activationLinkModal'));
        document.getElementById('activated_client_id').value = idClient;
        activationLinkModal.show();
    }

    function activateClient(btn) {
        const link = document.getElementById('activation-link').value;
        const clientId = document.getElementById('activated_client_id').value;

        btn.disabled = true;

        api(`/ajax/clients/${clientId}/activate-integration`, {
            method : "POST",
            data : {
                link
            }
        })
            .then(response => {
                if(response.ok){
                    Swal.fire({
                        icon: "success",
                        title: "Sukces",
                        text: "Integracja powiodła się" || "Nie udało się przeprowadzić integracji"
                    }).then(() => {
                        setTimeout(() => window.location.reload(), 500);
                    });


                } else {
                    showSnack(false, response.error || 'Wystąpił błąd');
                }
            });
    }


    function runInvitationCodeGenerator(clientId){

        api(`/ajax/clients/${clientId}/integration-invitation-code`, {
            method : "GET"
        })
            .then(response => {
                if(response.ok){
                    const code = response.data.token;
                    document.getElementById('invitation-code-text').innerText = `${code}`;
                    const invitationCodeModal = new bootstrap.Modal(document.getElementById('invitationCodeModal'));
                    invitationCodeModal.show();
                } else {
                    showSnack(false, response.error || 'Wystąpił błąd podczas generowania kodu zaproszenia');
                }
            })
    }



</script>

<?php $this->view("page_components/footer"); ?>
