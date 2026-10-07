-- 002_encaminhamento: modos de encaminhamento (transferir / pedir ajuda), resposta obrigatória
-- e estados explícitos (cancelado, devolvido).

ALTER TABLE request_forwards
    ADD COLUMN mode ENUM('transfer','help') NOT NULL DEFAULT 'transfer' AFTER to_area_id,
    ADD COLUMN response_note TEXT NULL AFTER observation,
    ADD COLUMN responded_by INT UNSIGNED NULL AFTER response_note,
    ADD COLUMN responded_at DATETIME NULL AFTER responded_by,
    MODIFY status ENUM('pending','accepted','refused','completed','cancelled','returned') NOT NULL DEFAULT 'pending',
    ADD INDEX idx_req_status (request_table, request_id, status);
