<?php
/**
 * Linha de um pedido em Minhas requisições (padrão clean):
 * em cima o título; embaixo "Nº X · Setor · onde está agora"; à direita prazo e situação.
 * Clicar abre a gaveta do pedido (components/request_peek.php) com as etapas e a conversa.
 * Usa as linhas de RequestInbox::forRequester (context, subline, st_raw...).
 */
require_once __DIR__ . '/request_card.php';

if (!function_exists('renderMyRequestRow')) {
    /** Índice da etapa atual (0 Enviado, 1 Aprovado, 2 No setor, 3 Concluído); -1 para recusado */
    function myRequestStep(string $st): int
    {
        return match ($st) {
            'Y' => 1,
            'W', 'F' => 2,
            'C' => 3,
            'N' => -1,
            default => 0,
        };
    }

    /**
     * $opt:
     *   tab  nome da aba (resultados de busca juntam as duas)
     */
    function renderMyRequestRow(array $row, array $opt = []): string
    {
        $id = (int)($row['id'] ?? 0);
        $table = requestSlug((string)($row['table'] ?? ''));
        $st = strtoupper(trim((string)($row['st_raw'] ?? 'P')));
        $st = match ($st) { '' => 'P', 'A' => 'Y', default => $st };
        $sector = trim((string)($row['subline'] ?? '')) ?: requestSectorName($table);
        $urgent = !empty($row['urgent']) || (int)($row['priority'] ?? 2) >= 3;
        [$context, $kind] = ($row['context'] ?? []) + ['', ''];
        $open = !in_array($st, ['C', 'N'], true);
        $title = (string)($row['title'] ?? '') ?: 'Sem título';
        $status = statusLabel($st);

        // Prazo só enquanto aberto
        [$due, $dueKind] = $open ? deadlineLabel((string)($row['date'] ?? '')) : ['', 'muted'];
        $showDue = $open && $dueKind !== 'muted';
        $dueText = $dueKind === '' ? 'Prazo ' . $due : $due;

        $href = 'request_detail?id=' . $id . '&table=' . urlencode($table);
        $data = [
            'id' => 'mr-' . $table . '-' . $id,
            'href' => $href,
            'data-peek' => '',
            'data-id' => $id,
            'data-table' => $table,
            'data-href' => $href,
            'data-title' => $title,
            'data-sector' => $sector,
            'data-desc' => trim((string)($row['description'] ?? '')),
            'data-status' => $status,
            'data-status-code' => $st,
            'data-urgent' => $urgent ? '1' : '0',
            'data-step' => myRequestStep($st),
            'data-where' => $context,
            'data-where-kind' => $kind,
        ];
        // Opcionais na gaveta: prazo só aberto; prioridade só quando é urgente
        if ($open) {
            $data['data-prazo'] = $showDue ? $dueText : 'Sem prazo';
            $data['data-prazo-kind'] = $showDue ? $dueKind : '';
        }
        if ($urgent && $open) {
            $data['data-prio'] = 'Urgente';
        }
        $attrs = '';
        foreach ($data as $k => $v) {
            $attrs .= ' ' . $k . '="' . e((string)$v) . '"';
        }

        $whereCls = $kind !== '' ? ' mr-where--' . e($kind) : '';
        return '<a class="mr-row' . ($open ? '' : ' is-done') . '"' . $attrs . '>'
            .   '<span class="mr-main">'
            .     '<span class="mr-ttl">' . e($title) . '</span>'
            .     '<span class="mr-meta">'
            .       (!empty($opt['tab']) ? '<span class="mr-meta__tab">' . e($opt['tab']) . '</span> · ' : '')
            .       'Nº ' . $id . ' · ' . e($sector)
            .       ($context !== '' ? ' · <span class="mr-where' . $whereCls . '">' . e($context) . '</span>' : '')
            .     '</span>'
            .   '</span>'
            .   '<span class="mr-side">'
            .     ($urgent && $open ? '<span class="mr-urgent">Urgente</span>' : '')
            .     ($showDue ? '<span class="mr-due' . ($dueKind !== '' ? ' mr-due--' . e($dueKind) : '') . '"><i class="fa-regular fa-clock" aria-hidden="true"></i>' . e($dueText) . '</span>' : '')
            .     '<span class="status-pill status-pill--' . e($st) . '">' . e($status) . '</span>'
            .   '</span>'
            .   '<span class="mr-open" aria-hidden="true"><i class="fa-solid fa-chevron-right"></i></span>'
            . '</a>';
    }
}
