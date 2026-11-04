<?php
/**
 * Cartão de um pedido em Minhas requisições (UI 2.0):
 * "Nº X · Setor", situação, título, as 4 etapas em barras e onde o pedido está agora.
 * Usa as linhas de RequestInbox::forRequester (context, subline, st_raw...).
 */
require_once __DIR__ . '/request_card.php';

if (!function_exists('renderMyRequestCard')) {
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
    function renderMyRequestCard(array $row, array $opt = []): string
    {
        $id = (int)($row['id'] ?? 0);
        $table = requestSlug((string)($row['table'] ?? ''));
        $st = strtoupper(trim((string)($row['st_raw'] ?? 'P')));
        $st = match ($st) { '' => 'P', 'A' => 'Y', default => $st };
        $sector = trim((string)($row['subline'] ?? '')) ?: requestSectorName($table);
        $urgent = !empty($row['urgent']) || (int)($row['priority'] ?? 2) >= 3;
        [$context, $kind] = ($row['context'] ?? []) + ['', ''];
        $open = !in_array($st, ['C', 'N'], true);

        // Etapas: as anteriores feitas, a atual marcada; concluído = todas feitas; recusado = só o envio
        $now = myRequestStep($st);
        $steps = '';
        foreach (['Enviado', 'Aprovado', 'No setor', 'Concluído'] as $i => $name) {
            $cls = match (true) {
                $now === -1 => $i === 0 ? 'is-done' : '',
                $now === 3 => 'is-done',
                $i < $now => 'is-done',
                $i === $now => 'is-now',
                default => '',
            };
            $steps .= '<li' . ($cls !== '' ? ' class="' . $cls . '"' : '') . ($i === $now ? ' aria-current="step"' : '') . '><span>' . e($name) . '</span></li>';
        }
        $stepsLabel = $now === -1 ? 'Recusado' : 'Etapa ' . ($now + 1) . ' de 4';

        // Onde está + prazo (só enquanto aberto)
        $note = '<span class="mr-card__where' . ($kind !== '' ? ' mr-card__where--' . e($kind) : '') . '">' . e($context) . '</span>';
        if ($open) {
            [$due, $dueKind] = deadlineLabel((string)($row['date'] ?? ''));
            if ($dueKind !== 'muted') {
                $note .= '<span class="mr-card__due' . ($dueKind !== '' ? ' mr-card__due--' . e($dueKind) : '') . '">'
                    . e($dueKind === '' ? 'prazo ' . $due : $due) . '</span>';
            }
        }

        $href = 'request_detail?id=' . $id . '&table=' . urlencode($table);
        return '<li class="mr-item">'
            . '<a class="mr-card card' . ($kind === 'in' ? ' mr-card--reply' : '') . '" href="' . e($href) . '">'
            .   '<span class="mr-card__head">'
            .     '<span class="mr-card__ref">Nº ' . $id . ' · ' . e($sector) . '</span>'
            .     '<span class="mr-card__tags">'
            .       (!empty($opt['tab']) ? '<span class="mr-card__tab">' . e($opt['tab']) . '</span>' : '')
            .       ($urgent ? '<span class="badge-urgent">Urgente</span>' : '')
            .       '<span class="status-pill status-pill--' . e($st) . '">' . e(statusLabel($st)) . '</span>'
            .     '</span>'
            .   '</span>'
            .   '<span class="mr-card__title">' . e($row['title'] ?? 'Sem título') . '</span>'
            .   '<ol class="steps4 steps4--bars" aria-label="' . e($stepsLabel) . '">' . $steps . '</ol>'
            .   '<span class="mr-card__note">' . $note . '</span>'
            . '</a></li>';
    }
}
