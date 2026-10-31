-- Distinguishes original PPWR shipment snapshots from evidence reconstructed
-- later from the current recipe and current PPWR master data.
ALTER TABLE shipment_packaging_record
    ADD COLUMN ppwr_evidence_basis ENUM('SHIPMENT_SNAPSHOT', 'CURRENT_DATA_RECONSTRUCTION') NULL AFTER evidence_basis,
    ADD COLUMN ppwr_generated_at TIMESTAMP NULL DEFAULT NULL AFTER ppwr_evidence_basis;
