-- [241] Maszyna wspólna : maszyna należy do jednej grupy (n_prod_workstation.id_group), a inne grupy mogą z niej
--   korzystać (np. piec Piekarni, w którym piecze też Tarty). Skutki : maszyna pokazana w każdej grupie (Dane maszyn,
--   tablet TRS grupy), pracuje na zmianach każdej z tych grup, a praca przy produkcie liczy się zespołowi grupy produktu,
--   gdy ta grupa korzysta z maszyny. Rejouable.

CREATE TABLE IF NOT EXISTS n_prod_workstation_share (
    id_workstation INT NOT NULL,
    id_group INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_workstation, id_group),
    KEY ix_ws_share_group (id_group),
    CONSTRAINT fk_ws_share_ws FOREIGN KEY (id_workstation) REFERENCES n_prod_workstation (id) ON DELETE CASCADE,
    CONSTRAINT fk_ws_share_group FOREIGN KEY (id_group) REFERENCES n_prod_group (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='[241] grupy korzystające z maszyny innej grupy (maszyna wspólna)';
