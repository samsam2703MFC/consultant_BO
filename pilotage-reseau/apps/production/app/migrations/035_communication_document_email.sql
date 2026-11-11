-- Link the durable communication audit to regular invoice documents.
ALTER TABLE communication_message
    ADD COLUMN id_invoice INT UNSIGNED NULL AFTER id_proforma,
    ADD KEY idx_communication_message_invoice (id_invoice);
