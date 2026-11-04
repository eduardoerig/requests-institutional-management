-- 008_historico_codigo: o histórico passa a guardar o QUE aconteceu como código.
-- event_code: created, approved, refused, started, finished, reopened, priority, forward_transfer,
--   forward_help, accepted_transfer, accepted_help, forward_refused, forward_cancelled, help_returned,
--   status (legado "Status alterado") e legacy_* (textos antigos do "repasse").
-- old_value/new_value: situação em código (P/Y/W/C/N/F), prioridade em número (1-4) e, nos
--   encaminhamentos, o nome do setor. A frase da linha do tempo sai de um mapa no código
--   (requestEventText); action continua com o texto, para registros que não forem reconhecidos.
-- Só funções do MySQL 5.7 (sem REGEXP_REPLACE).

ALTER TABLE request_history
    ADD COLUMN event_code VARCHAR(30) NULL DEFAULT NULL AFTER action,
    ADD KEY idx_request_event (request_id, request_table, event_code);

-- 1) Código pelo texto gravado (a collation já ignora maiúsculas e acentos)
UPDATE request_history SET event_code = 'created' WHERE event_code IS NULL AND action = 'Requisição criada';
UPDATE request_history SET event_code = 'approved' WHERE event_code IS NULL AND action = 'Requisição aprovada';
UPDATE request_history SET event_code = 'refused' WHERE event_code IS NULL AND action = 'Requisição recusada';
UPDATE request_history SET event_code = 'started' WHERE event_code IS NULL AND action = 'Atendimento iniciado';
UPDATE request_history SET event_code = 'legacy_finished_forward' WHERE event_code IS NULL AND action LIKE 'Requisição concluída via repasse%';
UPDATE request_history SET event_code = 'finished' WHERE event_code IS NULL AND action = 'Requisição concluída';
UPDATE request_history SET event_code = 'reopened' WHERE event_code IS NULL AND action LIKE 'Requisição reaberta%';
UPDATE request_history SET event_code = 'priority' WHERE event_code IS NULL AND action = 'Prioridade alterada';
UPDATE request_history SET event_code = 'status' WHERE event_code IS NULL AND action = 'Status alterado';
UPDATE request_history SET event_code = 'forward_transfer' WHERE event_code IS NULL AND action LIKE 'Encaminhou para o setor %';
UPDATE request_history SET event_code = 'forward_help' WHERE event_code IS NULL AND action LIKE 'Pediu ajuda ao setor %';
UPDATE request_history SET event_code = 'accepted_transfer' WHERE event_code IS NULL AND (action LIKE 'Assumiu o pedido (%)' OR action LIKE 'Setor % assumiu o pedido');
UPDATE request_history SET event_code = 'accepted_help' WHERE event_code IS NULL AND (action LIKE 'Aceitou ajudar (%)' OR action LIKE 'Setor % aceitou ajudar');
UPDATE request_history SET event_code = 'forward_refused' WHERE event_code IS NULL AND (action LIKE 'Recusou o encaminhamento (%)' OR action LIKE 'Setor % recusou o encaminhamento');
UPDATE request_history SET event_code = 'forward_cancelled' WHERE event_code IS NULL AND action LIKE 'Cancelou o encaminhamento para %';
UPDATE request_history SET event_code = 'help_returned' WHERE event_code IS NULL AND (action LIKE 'Devolveu a ajuda (%)' OR action LIKE 'Setor % devolveu a ajuda');
UPDATE request_history SET event_code = 'legacy_forward' WHERE event_code IS NULL AND action LIKE 'Requisição repassada para %';
UPDATE request_history SET event_code = 'legacy_forward_accepted' WHERE event_code IS NULL AND action LIKE 'Repasse aceito por %';

-- 2) Situações: rótulo gravado -> código
UPDATE request_history SET
    old_value = CASE old_value
        WHEN 'Pendente' THEN 'P' WHEN 'Aguardando aprovação' THEN 'P'
        WHEN 'Aprovada' THEN 'Y' WHEN 'Aprovado' THEN 'Y'
        WHEN 'Em atendimento' THEN 'W' WHEN 'Em andamento' THEN 'W'
        WHEN 'Concluída' THEN 'C' WHEN 'Concluído' THEN 'C'
        WHEN 'Recusada' THEN 'N' WHEN 'Recusado' THEN 'N'
        WHEN 'Repassada' THEN 'F' WHEN 'Encaminhada a outro setor' THEN 'F'
        ELSE old_value END,
    new_value = CASE new_value
        WHEN 'Pendente' THEN 'P' WHEN 'Aguardando aprovação' THEN 'P'
        WHEN 'Aprovada' THEN 'Y' WHEN 'Aprovado' THEN 'Y'
        WHEN 'Em atendimento' THEN 'W' WHEN 'Em andamento' THEN 'W'
        WHEN 'Concluída' THEN 'C' WHEN 'Concluído' THEN 'C'
        WHEN 'Recusada' THEN 'N' WHEN 'Recusado' THEN 'N'
        WHEN 'Repassada' THEN 'F' WHEN 'Encaminhada a outro setor' THEN 'F'
        ELSE new_value END
WHERE event_code IN ('created', 'approved', 'refused', 'started', 'finished', 'reopened', 'status', 'legacy_finished_forward');

-- 3) Prioridade: rótulo -> número
UPDATE request_history SET
    old_value = CASE old_value WHEN 'Baixa' THEN '1' WHEN 'Normal' THEN '2' WHEN 'Alta' THEN '3' WHEN 'Crítica' THEN '4' ELSE old_value END,
    new_value = CASE new_value WHEN 'Baixa' THEN '1' WHEN 'Normal' THEN '2' WHEN 'Alta' THEN '3' WHEN 'Crítica' THEN '4' ELSE new_value END
WHERE event_code = 'priority';

-- 4) Encaminhamentos sem o setor em new_value: tira do texto "(Setor)" ou "... para Setor"
UPDATE request_history SET new_value = SUBSTRING_INDEX(SUBSTRING_INDEX(action, '(', -1), ')', 1)
WHERE new_value IS NULL AND event_code IN ('accepted_transfer', 'accepted_help', 'forward_refused', 'help_returned') AND action LIKE '%(%)';
UPDATE request_history SET new_value = SUBSTRING(action, CHAR_LENGTH('Cancelou o encaminhamento para ') + 1)
WHERE new_value IS NULL AND event_code = 'forward_cancelled';
