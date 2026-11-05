<?php
/**
 * Lateral do pedido: guia do setor ("o que fazer agora") e "Quem está envolvido".
 */

require_once __DIR__ . '/../../classes/RequestForwardService.php';

/**
 * Próximo passo para quem trabalha no pedido (setor, coordenação, administração), em uma frase.
 * Nomeia o botão a usar; devolve HTML já escapado ('' quando não há nada a dizer).
 */
/**
 * $c: status, open (encaminhamento em aberto ou null), openIsForMe, openIsFromMe, canApprove, canWork,
 * canReopen, canForward, ownerLabel, sectorName, asAdmin (opcional).
 */
function requestStaffGuide(array $c): string
{
    [
        'status' => $status, 'open' => $open, 'openIsForMe' => $openIsForMe, 'openIsFromMe' => $openIsFromMe,
        'canApprove' => $canApprove, 'canWork' => $canWork, 'canReopen' => $canReopen, 'canForward' => $canForward,
        'ownerLabel' => $ownerLabel, 'sectorName' => $sectorName, 'asAdmin' => $asAdmin,
    ] = $c + ['asAdmin' => false];
    $b = fn(string $label) => '<strong>' . e($label) . '</strong>';
    // Setor parceiro: incluído pela coordenação, então a frase não diz que o setor responsável pediu
    if ($open && RequestForwardService::isPartner($open)) {
        $to = e($open['to_label']);
        $pending = $open['status'] === 'pending';
        if ($asAdmin) {
            return $pending
                ? "A coordenação chamou $to como setor parceiro. Como administração, você pode tocar em " . $b('Aceitar a parte') . " ou recusar no lugar de $to."
                : "$to está fazendo a parte dele. Como administração, você pode tocar em " . $b('Devolver com retorno') . " no lugar de $to.";
        }
        if ($openIsForMe) {
            return $pending
                ? 'A coordenação chamou vocês para uma parte deste pedido. Toque em ' . $b('Aceitar a parte') . ' ou recuse dizendo o motivo.'
                : 'Esta parte é de vocês. Quando terminar, toque em ' . $b('Devolver com retorno') . '.';
        }
        $state = $pending ? "$to ainda não aceitou a parte." : "$to está fazendo a parte dele.";
        if ($canWork && $status === 'Y') {
            return "$state Toque em " . $b('Começar atendimento') . ' quando iniciar; dá para concluir depois que devolverem.';
        }
        return $canWork ? "$state Sigam com o atendimento; dá para concluir depois que devolverem." : $state;
    }
    // Administração age no lugar dos setores: a frase diz quem é quem, sem "vocês"
    if ($open && $asAdmin) {
        $from = e($open['from_label']);
        $to = e($open['to_label']);
        if ($open['status'] === 'pending') {
            return $open['mode'] === 'transfer'
                ? "$from quer passar este pedido para $to. Como administração, você pode tocar em " . $b('Aceitar e assumir') . " ou recusar no lugar de $to."
                : "$from pediu ajuda a $to. Como administração, você pode tocar em " . $b('Aceitar ajuda') . " ou recusar no lugar de $to.";
        }
        if ($open['mode'] === 'help' && $open['status'] === 'accepted') {
            return "$to está ajudando $from. Como administração, você pode tocar em " . $b('Devolver com retorno') . " no lugar de $to.";
        }
    }
    if ($open && $openIsForMe && $open['status'] === 'pending') {
        return $open['mode'] === 'transfer'
            ? e($open['from_label']) . ' quer passar este pedido para vocês. Toque em ' . $b('Aceitar e assumir') . ' ou recuse dizendo o motivo.'
            : e($open['from_label']) . ' pediu a ajuda de vocês. Toque em ' . $b('Aceitar ajuda') . ' ou recuse dizendo o motivo.';
    }
    if ($open && $openIsForMe && $open['mode'] === 'help' && $open['status'] === 'accepted') {
        return 'Vocês estão ajudando ' . e($open['from_label']) . '. Quando terminar a sua parte, toque em ' . $b('Devolver com retorno') . '.';
    }
    if ($open && $openIsFromMe) {
        if ($open['mode'] === 'transfer') {
            return 'Esperando ' . e($open['to_label']) . ' aceitar a transferência. Nada a fazer agora; você recebe um aviso.';
        }
        return ($open['status'] === 'pending' ? 'Vocês pediram ajuda a ' . e($open['to_label']) . '.' : e($open['to_label']) . ' está ajudando.')
            . ' Sigam com o atendimento; dá para concluir depois que devolverem.';
    }
    if ($canApprove) {
        return 'Leia o pedido e decida. ' . $b('Aprovar') . ' envia para ' . e($sectorName) . '; ' . $b('Recusar') . ' pede um motivo para quem pediu.';
    }
    if ($canWork && $status === 'Y') {
        return 'Toque em ' . $b('Começar atendimento') . ' quando iniciar.'
            . ($canForward ? ' Se for de outro setor ou precisar de compra, use ' . $b('Encaminhar') . '.' : '')
            . ' Dúvida? Pergunte a quem pediu na Conversa.';
    }
    if ($canWork && $status === 'W') {
        return 'Quando terminar, toque em ' . $b('Concluir') . '. Quem pediu é avisado na hora.';
    }
    return match ($status) {
        'P' => 'Esperando a coordenação aprovar. Depois disso o pedido entra na Caixa de ' . e($ownerLabel) . '.',
        'Y' => 'Aprovado. Esperando ' . e($ownerLabel) . ' começar o atendimento.',
        'W' => e($ownerLabel) . ' está atendendo.',
        'C' => $canReopen ? 'Pedido concluído. Se precisar refazer, use ' . $b('Reabrir pedido') . ' em ••• (mais opções).' : '',
        default => '',
    };
}

/**
 * "Quem está envolvido": quem pediu, a coordenação (quem aprovou ou recusou), o setor
 * responsável agora e os setores chamados para ajudar ou que já tiveram o pedido.
 * Cada item: ['name' => 'TI · Carlos', 'role' => 'Responsável agora', 'tone' => 'owner']
 */
/**
 * $c: req, status, subdivision, history, forwards, ownerArea, ownerLabel, worker,
 * viewerId e viewerName (opcionais).
 */
function requestPeople(array $c): array
{
    [
        'req' => $req, 'status' => $status, 'subdivision' => $subdivision, 'history' => $history,
        'forwards' => $forwards, 'ownerArea' => $ownerArea, 'ownerLabel' => $ownerLabel, 'worker' => $worker,
        'viewerId' => $viewerId, 'viewerName' => $viewerName,
    ] = $c + ['viewerId' => 0, 'viewerName' => ''];
    $me = fn(string $name) => $viewerName !== '' && $name === $viewerName ? ' (você)' : '';
    $day = fn($d) => $d ? date('d/m', strtotime((string)$d)) : '';
    $people = [];

    // Setor responsável agora
    $ownerRole = ['P' => 'Vai atender depois da aprovação', 'Y' => 'Responsável · ainda não começou', 'F' => 'Responsável agora',
        'W' => 'Responsável · atendendo agora', 'C' => 'Atendeu o pedido', 'N' => 'Setor do pedido'][$status] ?? 'Responsável agora';
    $people[] = ['name' => $ownerLabel . ($worker !== '' && in_array($status, ['W', 'C'], true) ? ' · ' . $worker . $me($worker) : ''), 'role' => $ownerRole, 'tone' => 'owner'];

    // Outros setores: o último encaminhamento de cada um conta a história
    $areas = [];
    foreach ($forwards as $f) {
        if ($f['status'] === 'cancelled') {
            continue;
        }
        $to = (int)$f['to_area_id'];
        $from = (int)$f['from_area_id'];
        $by = (string)($f['responded_by_name'] ?? '');
        $when = $day($f['responded_at'] ?? null);
        if (RequestForwardService::isPartner($f)) {
            $due = $day($f['due_date'] ?? null);
            $role = [
                'pending' => 'Setor parceiro · aguardando aceite' . ($due ? ' · prazo ' . $due : ''),
                'accepted' => 'Setor parceiro · fazendo a parte' . ($due ? ' · prazo ' . $due : ''),
                'returned' => 'Setor parceiro · entregou' . ($when ? ' em ' . $when : ''),
                'refused' => 'Recusou ser parceiro',
            ][$f['status']] ?? '';
        } elseif ($f['mode'] === 'help') {
            $role = [
                'pending' => 'Chamado para ajudar · aguardando resposta',
                'accepted' => 'Ajudando agora',
                'returned' => 'Ajudou' . ($when ? ' · devolveu em ' . $when : ''),
                'completed' => 'Ajudou',
                'refused' => 'Recusou ajudar',
            ][$f['status']] ?? '';
        } else {
            $role = [
                'pending' => 'Transferência aguardando aceite',
                'accepted' => 'Assumiu o pedido' . ($when ? ' em ' . $when : ''),
                'completed' => 'Assumiu o pedido',
                'refused' => 'Recusou a transferência',
            ][$f['status']] ?? '';
            if (in_array($f['status'], ['accepted', 'completed'], true) && $from !== (int)$ownerArea) {
                $areas[$from] = ['name' => (string)$f['from_label'], 'role' => 'Responsável anterior · transferiu', 'tone' => 'help'];
            }
        }
        if ($role !== '' && $to !== (int)$ownerArea) {
            $areas[$to] = ['name' => $f['to_label'] . ($by !== '' && $f['status'] !== 'pending' ? ' · ' . $by . $me($by) : ''), 'role' => $role, 'tone' => 'help'];
        }
    }
    unset($areas[(int)$ownerArea]);
    foreach ($areas as $p) {
        $people[] = $p;
    }

    // Coordenação: quem aprovou ou recusou (do histórico)
    $coord = null;
    foreach ($history as $h) {
        if (preg_match('/^Requisição (aprovada|recusada)$/iu', (string)$h['action'], $m)) {
            $coord = ['name' => 'Coordenação · ' . $h['user_name'] . $me((string)$h['user_name']), 'role' => (mb_strtolower($m[1]) === 'aprovada' ? 'Aprovou' : 'Recusou') . ' em ' . $day($h['created_at']), 'tone' => 'coord'];
        }
    }
    if (!$coord && $status === 'P') {
        $coord = ['name' => 'Coordenação', 'role' => 'Precisa aprovar o pedido', 'tone' => 'coord'];
    }
    if ($coord) {
        $people[] = $coord;
    }

    // Quem pediu
    $people[] = [
        'name' => (string)($req['creator_name'] ?? 'Quem pediu') . ((int)($req['created_by'] ?? 0) === $viewerId && $viewerId > 0 ? ' (você)' : ''),
        'role' => 'Pediu' . ($subdivision !== '' ? ' · ' . $subdivision : '') . (!empty($req['created_at']) ? ' · ' . $day($req['created_at']) : ''),
        'tone' => 'req',
    ];
    // Iniciais para o avatar: da pessoa quando há ("TI · Carlos Mendes" → CM), senão do setor
    foreach ($people as &$p) {
        $base = preg_replace('/ \(você\)$/u', '', (string)$p['name']);
        $base = str_contains($base, ' · ') ? substr($base, strrpos($base, ' · ') + 4) : $base;
        $words = preg_split('/\s+/u', trim($base)) ?: [''];
        $first = mb_substr($words[0], 0, 1);
        $last = count($words) > 1 ? mb_substr(end($words), 0, 1) : mb_substr($words[0], 1, 1);
        $p['initials'] = mb_strtoupper($first . $last);
    }
    unset($p);
    return $people;
}
