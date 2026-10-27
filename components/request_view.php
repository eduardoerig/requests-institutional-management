<?php
/**
 * Pedido completo (pages/request_detail.php e api/request_view.php) — UI 2.0.
 *
 * renderRequestView($pdo, $req, $sector)
 *   Cabeçalho (nº · setor · prazo, título, situação, 4 etapas e as ações), a conversa
 *   (linha do tempo em balões) e, ao lado, "Quem está envolvido" e os detalhes.
 *   No celular as três partes viram abas (Conversa / Detalhes / Envolvidos) e as ações
 *   principais ficam numa barra fixa embaixo, junto da caixa de mensagem.
 *
 * renderRequestFeed($pdo, $req, $sector, $viewerId, $isStaff)
 *   Só a linha do tempo. api/get_comments.php usa a mesma função para atualizar a tela
 *   (o JS compara o HTML; a saída precisa ser a mesma da página).
 *
 * Os botões chamam funções globais de assets/js/script.js (requestAct, decideRequest,
 * forwardAct, openForwardWizard, printRequest) com data-id/data-table.
 * As abas do celular ficam em assets/js/pages/request_detail.js.
 */

require_once __DIR__ . '/../classes/RequestForwardService.php';
require_once __DIR__ . '/request_card.php';

if (!function_exists('renderRequestView')) {
    /** Evento do histórico contado por quem fez ("aprovou o pedido"); null = não mostrar */
    function requestEventText(string $action, ?string $old, ?string $new): ?string
    {
        if ($action === 'Prioridade alterada') {
            return 'mudou a urgência' . ($old && $new ? " de $old para $new" : '');
        }
        $rules = [
            '/^Requisição criada$/u' => null, // o próprio pedido abre a linha do tempo
            '/^Requisição aprovada$/iu' => 'aprovou o pedido',
            '/^Requisição recusada$/iu' => 'recusou o pedido',
            '/^Atendimento iniciado$/u' => 'começou o atendimento',
            '/^Requisição concluída via repasse \((.+)\)$/iu' => 'concluiu o pedido ($1)',
            '/^Requisição concluída$/iu' => 'concluiu o pedido',
            '/^Requisição reaberta.*$/iu' => 'reabriu o pedido',
            '/^Requisição repassada para (.+)$/iu' => 'encaminhou para $1',
            '/^Repasse aceito por .*?\(([^)]+)\).*$/u' => 'assumiu o pedido ($1)',
            '/^Setor (.+?) (assumiu o pedido|aceitou ajudar|recusou o encaminhamento|devolveu a ajuda)$/u' => '$2 ($1)',
        ];
        foreach ($rules as $pattern => $text) {
            if (preg_match($pattern, $action)) {
                return $text === null ? null : preg_replace($pattern, $text, $action);
            }
        }
        return mb_strtolower(mb_substr($action, 0, 1)) . mb_substr($action, 1);
    }

    /** Histórico do pedido, do mais antigo ao mais novo */
    function requestHistoryRows(PDO $pdo, int $reqId, string $sector): array
    {
        $st = $pdo->prepare('SELECT user_name, action, old_value, new_value, created_at FROM request_history WHERE request_id = ? AND request_table = ? ORDER BY created_at ASC, id ASC');
        $st->execute([$reqId, $sector]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Setor responsável agora: destino da última transferência aceita; sem ela, o setor do formulário */
    function requestOwnerFromForwards(PDO $pdo, array $forwards, string $sector): ?int
    {
        $owner = null;
        foreach ($forwards as $f) {
            if ($f['mode'] === 'transfer' && in_array($f['status'], ['accepted', 'completed'], true)) {
                $owner = (int)$f['to_area_id'];
            }
        }
        return $owner ?? RequestForwardService::originAreaId($pdo, $sector);
    }

    /**
     * Quem fala na conversa: [user_id => ['label' => 'TI', 'tone' => 'owner']].
     * tone: req (quem pediu), coord (coordenação), owner (setor responsável), help (outro setor), other.
     */
    function requestSpeakers(PDO $pdo, array $userIds, int $creatorId, ?int $ownerArea, array $forwards): array
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        $out = [];
        $others = [];
        foreach ($userIds as $id) {
            if ($id === $creatorId) {
                $out[$id] = ['label' => 'Quem pediu', 'tone' => 'req'];
            } else {
                $others[] = $id;
            }
        }
        if (!$others) {
            return $out;
        }
        $st = $pdo->prepare('SELECT id, role FROM ctd_users WHERE id IN (' . implode(',', array_fill(0, count($others), '?')) . ')');
        $st->execute($others);
        $roles = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $roles[(int)$row['id']] = (string)$row['role'];
        }
        // Setores que passaram pelo pedido (para escolher a área certa de quem tem várias)
        $involved = [];
        foreach ($forwards as $f) {
            $involved[(int)$f['to_area_id']] = true;
            $involved[(int)$f['from_area_id']] = true;
        }
        foreach ($others as $id) {
            $role = $roles[$id] ?? 'solicitante';
            if (isAdminRole($role)) {
                $out[$id] = ['label' => $role === 'admin' ? 'Administração' : 'Coordenação', 'tone' => 'coord'];
                continue;
            }
            if (!isGestorRole($role)) {
                $out[$id] = ['label' => 'Solicitante', 'tone' => 'other'];
                continue;
            }
            $areas = RequestForwardService::userAreaIds($pdo, $id);
            $area = null;
            if ($ownerArea !== null && in_array($ownerArea, $areas, true)) {
                $area = $ownerArea;
            } else {
                foreach ($areas as $a) {
                    if (isset($involved[$a])) { $area = $a; break; }
                }
                $area = $area ?? ($areas[0] ?? null);
            }
            $out[$id] = [
                'label' => $area ? RequestForwardService::areaLabel($pdo, $area) : 'Setor',
                'tone' => $area !== null && $area === $ownerArea ? 'owner' : 'help',
            ];
        }
        return $out;
    }

    /**
     * Conversas do pedido que esta pessoa vê, na ordem das abas (no máximo duas). Cada uma tem:
     * - key e label (nome da aba); events: mostra os eventos do pedido; empty: texto sem mensagens;
     * - sources: de onde vêm as mensagens, [canal, setor que ajuda, etiqueta, etiqueta só nas de quem pediu];
     * - target: para onde vai o que se escreve, [channel, area, hint, placeholder, two (vai a dois setores)];
     *   null = só leitura, com readHint explicando;
     * - unread: a última mensagem para mim é de outra pessoa; default: aba que abre.
     * Quem pediu tem uma conversa só e não escolhe destino: com ajuda aceita, escreve no helper, que o
     * responsável e quem ajuda recebem. Quem só ajuda tem "Com <responsável>" e "Com <quem pediu>" e nunca
     * vê a conversa de quem pediu com o responsável. Responsável, coordenação e admin: "Com <quem pediu>"
     * (lendo também o helper) e "Com <setor que ajuda>". O helper só recebe mensagem com a ajuda aceita.
     */
    function requestChatThreads(PDO $pdo, array $req, string $sector, int $viewerId, string $role, array $forwards, ?int $ownerArea): array
    {
        $ownerLabel = RequestForwardService::areaLabel($pdo, $ownerArea);
        $helperAreas = requestHelperAreaIds($forwards);
        $openAreas = requestOpenHelperAreaIds($forwards);
        $areaName = fn(int $a) => RequestForwardService::areaLabel($pdo, $a);
        // Abas com nomes de verdade: primeiro nome de quem pediu e nome do setor
        $who = explode(' ', trim((string)($req['creator_name'] ?? '')))[0] ?: 'quem pediu';
        $target = fn(string $channel, int $area, string $hint, string $placeholder, bool $two = false) => compact('channel', 'area', 'hint', 'placeholder', 'two');
        // O que quem pediu escreveu durante a ajuda: o setor que ajuda também recebeu
        $helperSources = array_map(fn($a) => ['helper', $a, $areaName($a) . ' também vê', true], $helperAreas);
        $threads = [];

        if ($role === 'solicitante') {
            $open = $openAreas[0] ?? null; // só um encaminhamento aberto por vez
            $both = $open ? $ownerLabel . ' e ' . $areaName($open) : '';
            $threads[] = ['key' => 'main', 'label' => 'Com ' . $ownerLabel, 'events' => true,
                'sources' => array_merge([['public', 0, '', false]], $helperSources),
                'target' => $open
                    ? $target('helper', $open, "$both recebem sua mensagem.", 'Mensagem para ' . $both, true)
                    : $target('public', 0, "$ownerLabel recebe sua mensagem.", 'Mensagem para ' . $ownerLabel),
                'empty' => 'Ninguém escreveu ainda. Use o campo abaixo para falar sobre o pedido.', 'readHint' => ''];
        } elseif (userSeesOnlyInternalChat($pdo, $req, $sector, $viewerId, $role)) {
            $threads[] = ['key' => 'internal', 'label' => 'Com ' . $ownerLabel, 'events' => true, 'sources' => [['internal', 0, '', false]],
                'target' => $target('internal', 0, "Conversa entre os setores. $who não vê.", 'Mensagem para ' . $ownerLabel),
                'empty' => "Nenhuma mensagem com $ownerLabel ainda. Use o campo abaixo para falar com o setor que pediu ajuda.", 'readHint' => ''];
            foreach (array_intersect($helperAreas, RequestForwardService::userAreaIds($pdo, $viewerId)) as $a) {
                $open = in_array($a, $openAreas, true);
                $threads[] = ['key' => 'helper-' . $a, 'label' => 'Com ' . $who, 'events' => false, 'sources' => [['helper', $a, '', false]],
                    'target' => $open ? $target('helper', $a, "$who recebe sua mensagem; $ownerLabel também acompanha.", 'Mensagem para ' . $who) : null,
                    'empty' => "Nenhuma mensagem com $who ainda." . ($open ? ' Use o campo abaixo para falar com essa pessoa.' : ''),
                    'readHint' => 'A ajuda já foi devolvida. Esta conversa fica só para consulta.'];
            }
        } else {
            $party = in_array($ownerArea, RequestForwardService::userAreaIds($pdo, $viewerId), true); // coordenação e admin só acompanham
            $helpers = []; // setores com quem o responsável conversa: chamados para ajudar ou com transferência pendente
            foreach ($forwards as $f) {
                if (($f['mode'] === 'help' && !in_array($f['status'], ['refused', 'cancelled'], true))
                    || ($f['mode'] === 'transfer' && $f['status'] === 'pending')) {
                    $helpers[(int)$f['to_area_id']] = (string)$f['to_label'];
                }
            }
            $threads[] = ['key' => 'main', 'label' => 'Com ' . $who, 'events' => true,
                'sources' => array_merge([['public', 0, '', false]], $helperSources),
                'target' => $target('public', 0, "$who (quem pediu) vê esta conversa. Dúvidas sobre o pedido, pergunte aqui.", 'Mensagem para ' . $who . ' (quem pediu)'),
                'empty' => 'Ninguém escreveu ainda. Use o campo abaixo para falar sobre o pedido.', 'readHint' => ''];
            if ($helpers) {
                $with = count($helpers) === 1 ? reset($helpers) : 'os setores';
                $threads[] = ['key' => 'internal', 'label' => 'Com ' . $with, 'events' => false, 'sources' => [['internal', 0, '', false]],
                    'target' => $target('internal', 0, "Só os setores veem. $who não vê esta mensagem.", 'Mensagem para ' . $with),
                    'empty' => "Nenhuma mensagem com $with ainda.", 'readHint' => ''];
            }
        }

        // Esperando resposta: a última mensagem para mim é de outra pessoa (pedido encerrado não pede
        // resposta). Para mim = no canal em que escrevo, ou de quem pediu em qualquer fonte da aba.
        // Só para quem participa da conversa: coordenação e admin acompanham sem bolinha
        $party = $party ?? true;
        $st = $pdo->prepare('SELECT user_id, channel, helper_area_id FROM request_comments WHERE request_id = ? AND request_table = ? ORDER BY created_at ASC, id ASC');
        $st->execute([(int)$req['id'], $sector]);
        $comments = $st->fetchAll(PDO::FETCH_ASSOC);
        $creatorId = (int)($req['created_by'] ?? 0);
        $closed = in_array(strtoupper((string)($req['status'] ?? '')), ['C', 'N'], true);
        $matches = fn(array $c, string $channel, int $area) => $c['channel'] === $channel && ($channel !== 'helper' || (int)$c['helper_area_id'] === $area);
        foreach ($threads as &$t) {
            $last = null;
            foreach ($comments as $c) {
                $forMe = $t['target'] && $matches($c, $t['target']['channel'], $t['target']['area']);
                foreach ($t['sources'] as [$channel, $area]) {
                    $forMe = $forMe || ((int)$c['user_id'] === $creatorId && $matches($c, $channel, $area));
                }
                if ($forMe) {
                    $last = $c;
                }
            }
            $t['unread'] = $party && !$closed && $last && (int)$last['user_id'] !== $viewerId;
            $t['default'] = false;
        }
        unset($t);
        // Abre a primeira aba esperando resposta; sem nenhuma, a primeira
        $pick = 0;
        foreach ($threads as $i => $t) {
            if ($t['unread']) { $pick = $i; break; }
        }
        $threads[$pick]['default'] = true;
        return $threads;
    }

    /**
     * Linha do tempo de uma conversa ($thread, de requestChatThreads): as mensagens são balões
     * (as minhas à direita) e, na conversa principal de cada pessoa, os eventos são pílulas no
     * centro. As mudanças automáticas de situação (sem texto) vêm marcadas com --sys e ficam
     * escondidas até alguém pedir o histórico completo.
     */
    function renderRequestFeed(PDO $pdo, array $req, string $sector, int $viewerId, bool $isStaff, array $thread, ?array $forwards = null, ?array $history = null): string
    {
        $reqId = (int)$req['id'];
        $creatorId = (int)($req['created_by'] ?? 0);
        $items = [];

        $allForwards = $forwards ?? RequestForwardService::forRequest($pdo, $reqId, $sector);
        $ownerArea = requestOwnerFromForwards($pdo, $allForwards, $sector);
        if ($thread['events']) {
            // Encaminhamentos com a mensagem: só para os setores
            $forwards = $isStaff ? $allForwards : [];
            $skip = '/^(Encaminhou|Pediu ajuda|Setor .* (assumiu|aceitou|recusou|devolveu)|Cancelou o encaminhamento|Assumiu o pedido|Aceitou ajudar|Recusou o encaminhamento|Devolveu a ajuda'
                . ($forwards ? '|Requisição repassada|Repasse aceito' : '') . ')/u';

            if ($history === null) {
                $history = requestHistoryRows($pdo, $reqId, $sector);
            }
            foreach ($history as $h) {
                if ($isStaff && preg_match($skip, (string)$h['action'])) {
                    continue; // vem dos encaminhamentos, com a mensagem
                }
                $text = requestEventText((string)$h['action'], $h['old_value'], $h['new_value']);
                if ($text !== null) {
                    $items[] = ['at' => (string)$h['created_at'], 'type' => 'event', 'who' => (string)$h['user_name'], 'text' => $text, 'note' => '', 'bad' => false];
                }
            }
            $responses = ['accepted' => 'aceitou', 'refused' => 'recusou', 'returned' => 'devolveu a ajuda', 'cancelled' => 'cancelou o envio', 'completed' => 'concluiu'];
            foreach ($forwards as $f) {
                $by = (string)($f['forwarded_by_name'] ?? '');
                $items[] = [
                    'at' => (string)$f['created_at'], 'type' => 'event',
                    'who' => $by !== '' ? $by . ' (' . $f['from_label'] . ')' : (string)$f['from_label'],
                    'text' => ($f['mode'] === 'help' ? 'pediu ajuda a ' : 'encaminhou para ') . $f['to_label'],
                    // Pendente: o recado está no cartão acima da conversa; não repete aqui
                    'note' => $f['status'] === 'pending' ? '' : (string)($f['observation'] ?? ''), 'bad' => false,
                ];
                if (!empty($f['responded_at']) && isset($responses[$f['status']])) {
                    $items[] = [
                        'at' => (string)$f['responded_at'], 'type' => 'event',
                        'who' => (string)(($f['responded_by_name'] ?? '') ?: $f['to_label']),
                        'text' => $responses[$f['status']] . ($f['status'] === 'cancelled' ? '' : ' (' . $f['to_label'] . ')'),
                        'note' => (string)($f['response_note'] ?? ''), 'bad' => $f['status'] === 'refused',
                    ];
                }
            }
        }

        // Só as fontes desta conversa (canal e, no helper, o setor que ajuda)
        $or = [];
        $params = [$reqId, $sector];
        foreach ($thread['sources'] as [$channel, $area]) {
            $or[] = $channel === 'helper' ? '(channel = ? AND helper_area_id = ?)' : 'channel = ?';
            array_push($params, ...($channel === 'helper' ? [$channel, $area] : [$channel]));
        }
        $st = $pdo->prepare('SELECT * FROM request_comments WHERE request_id = ? AND request_table = ? AND (' . implode(' OR ', $or) . ') ORDER BY created_at ASC, id ASC');
        $st->execute($params);
        $authorIds = [$creatorId];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $items[] = ['at' => (string)$c['created_at'], 'type' => 'message', 'c' => $c];
            $authorIds[] = (int)$c['user_id'];
        }
        $speakers = requestSpeakers($pdo, $authorIds, $creatorId, $ownerArea, $allForwards);

        // A conversa começa vazia: o pedido em si já está no topo da página
        $order = ['event' => 0, 'message' => 1];
        usort($items, fn($a, $b) => strcmp($a['at'], $b['at']) ?: ($order[$a['type']] <=> $order[$b['type']]));

        // Automático = evento sem texto escrito por alguém
        $isSys = fn(array $it) => $it['type'] === 'event' && trim($it['note']) === '' && !$it['bad'];
        $daySys = []; // dia => só tem automáticos?
        foreach ($items as $it) {
            $d = date('d/m/Y', strtotime($it['at']));
            $daySys[$d] = ($daySys[$d] ?? true) && $isSys($it);
        }

        $today = date('d/m/Y');
        $yesterday = date('d/m/Y', strtotime('-1 day'));
        $html = '';
        $lastDay = '';
        // Etiqueta de quem mais recebeu a mensagem, quando a conversa junta mais de uma fonte
        $tagOf = function (array $c) use ($thread, $creatorId): string {
            foreach ($thread['sources'] as [$channel, $area, $tag, $requesterOnly]) {
                if ($c['channel'] === $channel && ($channel !== 'helper' || (int)$c['helper_area_id'] === $area)) {
                    return $tag !== '' && (!$requesterOnly || (int)$c['user_id'] === $creatorId) ? $tag : '';
                }
            }
            return '';
        };
        $bubble = function (int $userId, string $name, string $time, string $body, string $tag = '') use ($viewerId, $speakers): string {
            $side = $userId === $viewerId ? 'mine' : 'theirs';
            $sp = $speakers[$userId] ?? ['label' => '', 'tone' => 'other'];
            return '<div class="chat-item ' . $side . '" data-tone="' . e($sp['tone']) . '">'
                . '<p class="chat-meta"><strong class="chat-user-name">' . e($name !== '' ? $name : 'Alguém') . '</strong>'
                . ($sp['label'] !== '' ? ' <span class="chat-role">· ' . e($sp['label']) . '</span>' : '')
                . ' <time class="chat-time">· ' . $time . '</time>'
                . ($tag !== '' ? ' <span class="chat-to-tag">' . e($tag) . '</span>' : '')
                . '</p>'
                . '<div class="chat-bubble ' . $side . '">' . $body . '</div>'
                . '</div>';
        };
        foreach ($items as $it) {
            $day = date('d/m/Y', strtotime($it['at']));
            if ($day !== $lastDay) {
                $html .= '<p class="feed-day' . ($daySys[$day] ? ' feed-day--sys' : '') . '">' . ($day === $today ? 'Hoje' : ($day === $yesterday ? 'Ontem' : $day)) . '</p>';
                $lastDay = $day;
            }
            $time = date('H:i', strtotime($it['at']));
            if ($it['type'] === 'event') {
                $html .= '<div class="feed-event' . ($it['bad'] ? ' feed-event--bad' : '') . ($isSys($it) ? ' feed-event--sys' : '') . '">'
                    . '<p class="feed-pill"><strong>' . e($it['who']) . '</strong> ' . e($it['text']) . ' <time>' . $time . '</time></p>'
                    . (trim($it['note']) !== '' ? '<blockquote>' . nl2br(e($it['note'])) . '</blockquote>' : '')
                    . '</div>';
            } else {
                $c = $it['c'];
                $html .= $bubble((int)$c['user_id'], (string)$c['user_name'], $time, nl2br(e($c['comment'])), $tagOf($c));
            }
        }
        if (count(array_filter($items, fn($it) => !$isSys($it))) === 0) {
            $html .= '<p class="feed-empty">' . e($thread['empty']) . '</p>';
        }
        return $html;
    }

    /**
     * Próximo passo para quem trabalha no pedido (setor, coordenação, administração), em uma frase.
     * Nomeia o botão a usar; devolve HTML já escapado ('' quando não há nada a dizer).
     */
    function requestStaffGuide(string $status, ?array $open, bool $openIsForMe, bool $openIsFromMe, bool $canApprove, bool $canWork, bool $canReopen, bool $canForward, string $ownerLabel, string $sectorName, bool $asAdmin = false): string
    {
        $b = fn(string $label) => '<strong>' . e($label) . '</strong>';
        // Administração age no lugar dos setores: a frase diz quem é quem, sem "vocês"
        if ($open && $asAdmin) {
            $from = e($open['from_label']);
            $to = e($open['to_label']);
            if ($open['status'] === 'pending') {
                return $open['mode'] === 'transfer'
                    ? "$from quer passar este pedido para $to. Como administração, você pode tocar em " . $b('Aceitar e assumir') . " ou recusar no lugar de $to."
                    : "$from pediu ajuda a $to. Como administração, você pode tocar em " . $b('Aceitar ajudar') . " ou recusar no lugar de $to.";
            }
            if ($open['mode'] === 'help' && $open['status'] === 'accepted') {
                return "$to está ajudando $from. Como administração, você pode tocar em " . $b('Devolver com retorno') . " no lugar de $to.";
            }
        }
        if ($open && $openIsForMe && $open['status'] === 'pending') {
            return $open['mode'] === 'transfer'
                ? e($open['from_label']) . ' quer passar este pedido para vocês. Toque em ' . $b('Aceitar e assumir') . ' ou recuse dizendo o motivo.'
                : e($open['from_label']) . ' pediu a ajuda de vocês. Toque em ' . $b('Aceitar ajudar') . ' ou recuse dizendo o motivo.';
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
    function requestPeople(array $req, string $status, string $subdivision, array $history, array $forwards, ?int $ownerArea, string $ownerLabel, string $worker): array
    {
        $day = fn($d) => $d ? date('d/m', strtotime((string)$d)) : '';
        $people = [];

        // Setor responsável agora
        $ownerRole = ['P' => 'Vai atender depois da aprovação', 'Y' => 'Responsável · ainda não começou', 'F' => 'Responsável agora',
            'W' => 'Responsável · atendendo agora', 'C' => 'Atendeu o pedido', 'N' => 'Setor do pedido'][$status] ?? 'Responsável agora';
        $people[] = ['name' => $ownerLabel . ($worker !== '' && in_array($status, ['W', 'C'], true) ? ' · ' . $worker : ''), 'role' => $ownerRole, 'tone' => 'owner'];

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
            if ($f['mode'] === 'help') {
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
                $areas[$to] = ['name' => $f['to_label'] . ($by !== '' && $f['status'] !== 'pending' ? ' · ' . $by : ''), 'role' => $role, 'tone' => 'help'];
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
                $coord = ['name' => 'Coordenação · ' . $h['user_name'], 'role' => (mb_strtolower($m[1]) === 'aprovada' ? 'Aprovou' : 'Recusou') . ' em ' . $day($h['created_at']), 'tone' => 'coord'];
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
            'name' => (string)($req['creator_name'] ?? 'Quem pediu'),
            'role' => 'Pediu' . ($subdivision !== '' ? ' · ' . $subdivision : '') . (!empty($req['created_at']) ? ' · ' . $day($req['created_at']) : ''),
            'tone' => 'req',
        ];
        return $people;
    }

    function renderRequestView(PDO $pdo, array $req, string $sector, string $context = 'page'): void
    {
        $viewerId = (int)($_SESSION['id'] ?? 0);
        $role = $_SESSION['role'] ?? 'solicitante';
        $reqId = (int)$req['id'];
        $status = strtoupper((string)($req['status'] ?? 'P')) ?: 'P';
        $isStaff = $role !== 'solicitante';
        $isRequester = (int)($req['created_by'] ?? 0) === $viewerId;
        $isGlobalAdmin = isGlobalAdminRole($role);
        $isGestor = isGestorRole($role);
        $sectorName = requestSectorName($sector);

        // Encaminhamentos lidos uma vez; deles saem o responsável atual e o encaminhamento em aberto
        $forwards = RequestForwardService::forRequest($pdo, $reqId, $sector);
        $open = null;
        foreach ($forwards as $f) { // do mais antigo ao mais novo: fica o último
            if ($f['status'] === 'pending' || ($f['mode'] === 'help' && $f['status'] === 'accepted')) {
                $open = $f;
            }
        }
        $ownerArea = requestOwnerFromForwards($pdo, $forwards, $sector);
        $req['owner_area_id'] = $ownerArea; // as checagens de permissão abaixo não precisam consultar de novo
        $ownerLabel = RequestForwardService::areaLabel($pdo, $ownerArea);
        $viewerAreas = RequestForwardService::userAreaIds($pdo, $viewerId);

        // O que esta pessoa pode fazer
        $isOwner = userIsRequestOwner($pdo, $req, $sector, $viewerId, $role);
        $canWork = $isGestor && $isOwner;
        $canApprove = $status === 'P' && isAdminRole($role) && userCanManageOriginalRequest($pdo, $req, $sector, $viewerId, $role);
        $canForward = ($canWork || $isGlobalAdmin) && in_array($status, ['Y', 'W'], true) && !$open;
        $canPriority = userCanActOnRequest($pdo, $req, $sector, $viewerId, $role) && !in_array($status, ['C', 'N'], true);
        $canReopen = $isOwner && $status === 'C';
        $canCancelRequest = $isRequester && $status === 'P';
        $openIsForMe = $open && ($isGlobalAdmin || in_array((int)$open['to_area_id'], $viewerAreas, true));
        $openIsFromMe = $open && ($isGlobalAdmin || (int)$open['forwarded_by'] === $viewerId || in_array((int)$open['from_area_id'], $viewerAreas, true));

        // Conversas separadas (abas): quem pediu × responsável, responsável × quem ajuda, quem pediu × quem ajuda
        $threads = requestChatThreads($pdo, $req, $sector, $viewerId, $role, $forwards, $ownerArea);

        // Sem encaminhamento aberto: mostra o último respondido (por que veio, o que voltou, por que recusaram)
        $lastDone = null;
        if (!$open && $isStaff && !in_array($status, ['C', 'N'], true)) {
            foreach (array_reverse($forwards) as $f) {
                if (in_array($f['status'], ['accepted', 'returned', 'refused'], true)) {
                    $lastDone = $f;
                }
                if ($f['status'] !== 'cancelled') {
                    break;
                }
            }
        }

        // Datas de cada situação e quem está atendendo (do histórico, lido uma vez para tudo)
        $history = requestHistoryRows($pdo, $reqId, $sector);
        $statusAt = [];
        $worker = '';
        $workerAt = '';
        foreach ($history as $h) {
            // Pelo nome da ação também: registros antigos podem ter outro texto em new_value
            $action = (string)$h['action'];
            if ($action === 'Atendimento iniciado') {
                $statusAt[statusLabel('W')] = $h['created_at'];
                $worker = (string)$h['user_name'];
                $workerAt = (string)$h['created_at'];
            } elseif (preg_match('/^Requisição concluída/iu', $action)) {
                $statusAt[statusLabel('C')] = $h['created_at'];
            } elseif (preg_match('/^Requisição (aprovada|recusada)$/iu', $action, $m)) {
                $statusAt[mb_strtolower($m[1]) === 'aprovada' ? 'Aprovada' : 'Recusada'] = $h['created_at'];
            }
            if ($h['new_value'] === null) {
                continue;
            }
            $statusAt[(string)$h['new_value']] = $h['created_at'];
            if ((string)$h['new_value'] === statusLabel('W')) {
                $worker = (string)$h['user_name'];
                $workerAt = (string)$h['created_at'];
            }
        }
        // Quem começou antes de uma transferência aceita era do setor anterior
        foreach ($forwards as $f) {
            if ($f['mode'] === 'transfer' && in_array($f['status'], ['accepted', 'completed'], true)
                && strcmp((string)($f['responded_at'] ?? $f['created_at']), $workerAt) > 0) {
                $worker = '';
            }
        }

        // Motivo da recusa fica à vista (vem da conversa)
        $refuseReason = '';
        if ($status === 'N') {
            $st = $pdo->prepare("SELECT comment FROM request_comments WHERE request_id = ? AND request_table = ? AND comment LIKE 'Motivo da recusa: %' ORDER BY id DESC LIMIT 1");
            $st->execute([$reqId, $sector]);
            $refuseReason = substr((string)$st->fetchColumn(), strlen('Motivo da recusa: '));
        }

        // Campos do tipo de pedido
        $extra = [];
        if ($sector === 'xerox') {
            $extra['Arquivo'] = $req['arquive'] ?? '';
            $extra['Cópias'] = $req['qtd'] ?? '';
            $extra['Tamanho do papel'] = $req['type'] ?? '';
        } elseif ($sector === 'mkt') {
            $extra['Onde será usada'] = $req['type'] ?? '';
        }

        $subdivision = (string)($req['subdivision_name'] ?? '');
        if ($subdivision === '' && !array_key_exists('subdivision_name', $req) && !empty($req['subdivision_id'])) {
            $st = $pdo->prepare('SELECT name FROM ctd_subdivision WHERE id = ?');
            $st->execute([$req['subdivision_id']]);
            $subdivision = (string)$st->fetchColumn();
        }

        $data = 'data-id="' . $reqId . '" data-table="' . e($sector) . '"';
        $fullTable = 'ctd_' . $sector . '_frm';
        $hasDeadline = !empty($req['date']) && strpos((string)$req['date'], '0000') !== 0;
        $priority = (int)($req['priority'] ?? 2);
        $isUrgent = $priority >= 3 || !empty($req['urgent']);
        $isClosed = in_array($status, ['C', 'N'], true);

        // As 4 etapas (Enviado · Aprovado · No setor · Concluído); recusado tem só duas
        $shortDate = fn($d) => $d ? date('d/m', strtotime((string)$d)) : '';
        $withDate = fn(string $label, string $d) => $label . ($d !== '' ? ' · ' . $d : '');
        if ($status === 'N') {
            $steps = [
                $withDate('Enviado', $shortDate($req['created_at'] ?? '')),
                $withDate('Recusado', $shortDate($statusAt['Recusada'] ?? '')),
            ];
            $stepNow = 1;
        } else {
            $stepNow = ['P' => 0, 'Y' => 1, 'F' => 1, 'W' => 2, 'C' => 3][$status] ?? 0;
            $workSince = $shortDate($statusAt[statusLabel('W')] ?? '');
            $steps = [
                $withDate('Enviado', $shortDate($req['created_at'] ?? '')),
                $withDate('Aprovado', $stepNow >= 1 ? $shortDate($statusAt['Aprovada'] ?? '') : ''),
                'Em atendimento' . ($stepNow >= 2 && $workSince !== '' ? ' · desde ' . $workSince : ''),
                $withDate('Concluído', $status === 'C' ? $shortDate($statusAt[statusLabel('C')] ?? '') : ''),
            ];
        }
        $now = [
            'P' => 'Seu pedido aguarda a aprovação da coordenação.',
            'Y' => "Seu pedido foi aprovado. O setor de $ownerLabel vai começar o atendimento.",
            'F' => $open && $open['mode'] === 'transfer'
                ? "Seu pedido está passando de {$open['from_label']} para {$open['to_label']}. Você recebe um aviso quando for aceito."
                : "Seu pedido foi aprovado. O setor de $ownerLabel vai começar o atendimento.",
            'W' => "O setor de $ownerLabel está atendendo.",
            'C' => "Seu pedido foi concluído pelo setor de $ownerLabel.",
            'N' => 'Pedido recusado.',
        ][$status] ?? '';
        $nowIcon = ['P' => 'fa-hourglass-half', 'Y' => 'fa-thumbs-up', 'F' => 'fa-thumbs-up', 'W' => 'fa-gears', 'C' => 'fa-circle-check', 'N' => 'fa-circle-xmark'][$status] ?? 'fa-circle-info';

        // Ações: uma principal (a próxima coisa a fazer, laranja) e as secundárias ao lado
        $jsSector = e($sector);
        $candidates = [];
        if ($openIsForMe && $open['status'] === 'pending') {
            $candidates[] = ['main', '<button type="button" class="btn %s" onclick="forwardAct(\'accept\', ' . (int)$open['id'] . ')"><i class="fa-solid fa-check" aria-hidden="true"></i> ' . ($open['mode'] === 'transfer' ? 'Aceitar e assumir' : 'Aceitar ajudar') . '</button>'];
            $candidates[] = ['side', '<button type="button" class="btn" onclick="forwardAct(\'refuse\', ' . (int)$open['id'] . ')">Recusar</button>'];
        }
        if ($openIsForMe && $open['mode'] === 'help' && $open['status'] === 'accepted') {
            $candidates[] = ['main', '<button type="button" class="btn %s" onclick="forwardAct(\'return\', ' . (int)$open['id'] . ')"><i class="fa-solid fa-reply" aria-hidden="true"></i> Devolver com retorno</button>'];
        }
        if ($canApprove) {
            $candidates[] = ['main', '<button type="button" class="btn %s" onclick="decideRequest(\'Y\', ' . $reqId . ', \'' . $jsSector . '\')"><i class="fa-solid fa-check" aria-hidden="true"></i> Aprovar</button>'];
            $candidates[] = ['side', '<button type="button" class="btn" onclick="decideRequest(\'N\', ' . $reqId . ', \'' . $jsSector . '\')">Recusar</button>'];
        }
        if ($canWork && $status === 'Y' && !($open && $open['mode'] === 'transfer')) {
            $candidates[] = ['main', '<button type="button" class="btn %s" onclick="requestAct(\'start_progress\', ' . $reqId . ', \'' . e($fullTable) . '\')"><i class="fa-solid fa-play" aria-hidden="true"></i> Começar atendimento</button>'];
        } elseif ($canWork && $status === 'W' && !$open) {
            $candidates[] = ['main', '<button type="button" class="btn %s" onclick="requestAct(\'finish\', ' . $reqId . ', \'' . e($fullTable) . '\')"><i class="fa-solid fa-check-double" aria-hidden="true"></i> Concluir</button>'];
        }
        if ($canForward) {
            $candidates[] = ['side', '<button type="button" class="btn" onclick="openForwardWizard(' . $reqId . ', \'' . $jsSector . '\')"><i class="fa-solid fa-share-from-square" aria-hidden="true"></i> Encaminhar</button>'];
        }
        // Só a primeira ação principal fica laranja e vai para a direita
        $mainButton = '';
        $sideButtons = [];
        foreach ($candidates as [$kind, $html]) {
            if ($kind === 'main' && $mainButton === '') {
                $mainButton = sprintf($html, 'btn--primary');
            } else {
                $sideButtons[] = sprintf($html, '');
            }
        }
        $actionsHtml = implode('', $sideButtons) . $mainButton;
        $canCancelForward = $openIsFromMe && $open['status'] === 'pending' && !$openIsForMe;
        $hasMenu = $canReopen || $canCancelForward || $canCancelRequest;
        $asAdmin = $open && $isGlobalAdmin && !in_array((int)$open['to_area_id'], $viewerAreas, true) && !in_array((int)$open['from_area_id'], $viewerAreas, true);
        $guide = $isStaff ? requestStaffGuide($status, $open, (bool)$openIsForMe, (bool)$openIsFromMe, $canApprove, $canWork, $canReopen, $canForward, $ownerLabel, $sectorName, $asAdmin) : '';

        // Detalhes
        $props = [];
        $props['Prazo'] = $hasDeadline
            ? e(date('d/m/Y', strtotime((string)$req['date']))) . ($isClosed ? '' : ' ' . requestDeadlineFlag((string)$req['date']))
            : 'Sem prazo';
        if ($canPriority) {
            $options = '';
            foreach (priorityLabels() as $v => $l) {
                $options .= '<option value="' . $v . '"' . ($priority === $v ? ' selected' : '') . '>' . e($l) . '</option>';
            }
            $props['Urgência'] = '<select class="rv-props__select" aria-label="Urgência" data-current="' . $priority . '" onchange="changePriority(this, ' . $reqId . ', \'' . e($fullTable) . '\')">' . $options . '</select>';
        } else {
            $props['Urgência'] = '<span class="' . ($priority >= 3 ? 'prio-tag prio-tag--' . $priority : '') . '">' . e(priorityLabel($priority)) . '</span>';
        }
        $workerNote = $worker !== '' && in_array($status, ['W', 'C'], true) ? ($status === 'C' ? 'atendido por ' : '') . $worker : '';
        $props['Atendimento'] = e($ownerLabel)
            . ($workerNote !== '' ? '<small>' . e($workerNote) . '</small>' : '')
            . ($ownerLabel !== $sectorName ? '<small>veio de ' . e($sectorName) . '</small>' : '');
        $props['Quem pediu'] = e($req['creator_name'] ?? '—') . ($subdivision !== '' ? '<small>' . e($subdivision) . '</small>' : '');
        foreach ($extra as $label => $value) { // campos do tipo de pedido
            $value = trim((string)$value);
            if ($value === '') {
                continue;
            }
            $props[$label] = preg_match('#^https?://#i', $value)
                ? '<a href="' . e($value) . '" target="_blank" rel="noopener noreferrer">Abrir arquivo</a>'
                : e($value);
        }
        $props['Aberto em'] = e(date('d/m/Y \à\s H:i', strtotime((string)$req['created_at'])));
        $desc = trim((string)($req['descp'] ?? ''));

        // O que foi pedido, no topo: a descrição; sem ela (reprografia), o essencial dos campos do tipo
        $ask = $desc;
        if ($ask === '' && $sector === 'xerox') {
            $ask = implode(' · ', array_filter([
                trim((string)($req['qtd'] ?? '')) !== '' ? (int)$req['qtd'] . ' cópias' : '',
                trim((string)($req['type'] ?? '')) !== '' ? 'papel ' . trim((string)$req['type']) : '',
            ]));
        }
        // Motivo da urgência: primeira linha das observações ("Urgente: ...", gravada por api/add_request.php)
        $obs = trim((string)($req['obs'] ?? ''));
        $urgentReason = '';
        if (preg_match('/^urgente:\s*(.+)$/iu', trim(explode("\n", $obs, 2)[0]), $m)) {
            $urgentReason = trim($m[1]);
            $obs = trim((string)(explode("\n", $obs, 2)[1] ?? ''));
        }
        $people = requestPeople($req, $status, $subdivision, $history, $forwards, $ownerArea, $ownerLabel, $worker);
        $metaLine = 'Nº ' . $reqId;
        $deadlineShort = $hasDeadline ? date('d/m', strtotime((string)$req['date'])) : '';
        $deadlineFlag = $hasDeadline && !$isClosed ? requestDeadlineFlag((string)$req['date']) : '';
        $tabId = 'rv' . $reqId . '-';
        ?>
<article class="request-view request-view--<?= e($context) ?> rv2" <?= $data ?> data-title="<?= e($req['title'] ?? '') ?>">
    <header class="rv-head card">
        <div class="rv-head__main">
            <div class="rv-head__top">
                <p class="rv-meta"><span class="rv-num"><?= e($metaLine) ?></span> · <span class="sector-tag" data-sector="<?= e($sector) ?>"><?= e($sectorName) ?></span><?php if ($deadlineShort !== ''): ?> · prazo <?= e($deadlineShort) ?><?php endif; ?><?= $deadlineFlag !== '' ? ' ' . $deadlineFlag : '' ?></p>
                <span class="rv-head__tools">
                    <?php if ($isStaff): ?>
                        <a class="icon-link" href="print_request.php?id=<?= $reqId ?>&table=<?= e($sector) ?>" onclick="return printRequest(event, this.href)" title="Imprimir" aria-label="Imprimir"><i class="fa-solid fa-print" aria-hidden="true"></i></a>
                    <?php endif; ?>
                    <?php if ($hasMenu): ?>
                        <details class="rv-more">
                            <summary class="icon-link" title="Mais opções" aria-label="Mais opções"><i class="fa-solid fa-ellipsis" aria-hidden="true"></i></summary>
                            <div class="rv-more__menu">
                                <?php if ($canReopen): ?>
                                    <button type="button" class="rv-menu-item" onclick="requestAct('reset_status', <?= $reqId ?>, '<?= e($fullTable) ?>')"><i class="fa-solid fa-rotate-left" aria-hidden="true"></i> Reabrir pedido</button>
                                <?php endif; ?>
                                <?php if ($canCancelForward): ?>
                                    <button type="button" class="rv-menu-item" onclick="forwardAct('cancel', <?= (int)$open['id'] ?>)"><i class="fa-solid fa-ban" aria-hidden="true"></i> Cancelar envio</button>
                                <?php endif; ?>
                                <?php if ($canCancelRequest): ?>
                                    <button type="button" class="rv-menu-item rv-menu-item--danger" onclick="requestAct('delete', <?= $reqId ?>, '<?= e($fullTable) ?>')"><i class="fa-solid fa-trash-can" aria-hidden="true"></i> Cancelar este pedido</button>
                                <?php endif; ?>
                            </div>
                        </details>
                    <?php endif; ?>
                </span>
            </div>
            <?php if (!$isStaff): ?>
                <div class="rv-status rv-status--<?= e($status) ?>" role="status">
                    <p class="rv-status__text"><i class="fa-solid <?= e($nowIcon) ?>" aria-hidden="true"></i> <span><?= e($now) ?></span></p>
                    <?php if ($refuseReason !== ''): ?><blockquote class="rv-status__note"><?= nl2br(e($refuseReason)) ?></blockquote><?php endif; ?>
                </div>
            <?php endif; ?>
            <?php $titleTag = $context === 'page' ? 'h1' : 'h2'; // na página do pedido, o título é o h1 ?>
            <<?= $titleTag ?> class="rv-title"><?= e($req['title'] ?? 'Sem título') ?></<?= $titleTag ?>>
            <?php if ($ask !== ''): ?>
                <div class="rv-ask" data-rv-ask>
                    <p class="rv-ask__who"><?= e(explode(' ', trim((string)($req['creator_name'] ?? '')))[0] ?: 'Quem pediu') ?> pediu:</p>
                    <p class="rv-ask__body"><?= nl2br(e($ask)) ?></p>
                    <button type="button" class="rv-ask__more" data-rv-ask-more hidden>Ver tudo</button>
                </div>
            <?php endif; ?>
            <?php if ($isUrgent): ?>
                <p class="rv-urgent"><i class="fa-solid fa-bolt" aria-hidden="true"></i> <strong>Urgente</strong><?= $urgentReason !== '' ? ': ' . e($urgentReason) : '' ?></p>
            <?php endif; ?>
            <?php if ($guide !== ''): ?>
                <p class="rv-guide"><i class="fa-solid fa-circle-arrow-right" aria-hidden="true"></i> <span><?= $guide ?></span></p>
            <?php endif; ?>
        </div>
        <?php if ($actionsHtml !== ''): ?>
            <div class="rv-actions rv-actions--head"><?= $actionsHtml ?></div>
        <?php endif; ?>
        <ol class="steps4 rv-steps4<?= $status === 'N' ? ' steps4--refused' : '' ?>" aria-label="Etapas do pedido">
            <?php foreach ($steps as $i => $label):
                $state = $i < $stepNow ? 'is-done' : ($i === $stepNow ? 'is-now' : '');
                $label = $i === $stepNow ? $label : preg_replace('/ · .*$/u', '', $label); // data só na etapa atual
            ?>
                <li<?= $state !== '' ? ' class="' . $state . '"' : '' ?><?= $i === $stepNow ? ' aria-current="step"' : '' ?>><span><?= e($label) ?></span></li>
            <?php endforeach; ?>
        </ol>
        <p class="rv-step-now" aria-hidden="true"><?= e($steps[$stepNow] ?? '') ?></p>
        <div class="rv-tabs" role="tablist" aria-label="Partes do pedido">
            <button type="button" role="tab" id="<?= $tabId ?>tab-chat" aria-controls="<?= $tabId ?>chat" aria-selected="true" data-rv-tab="chat">Conversa</button>
            <button type="button" role="tab" id="<?= $tabId ?>tab-info" aria-controls="<?= $tabId ?>info" aria-selected="false" tabindex="-1" data-rv-tab="info">Detalhes</button>
            <button type="button" role="tab" id="<?= $tabId ?>tab-people" aria-controls="<?= $tabId ?>people" aria-selected="false" tabindex="-1" data-rv-tab="people">Envolvidos</button>
        </div>
    </header>

    <div class="rv-body">
        <section class="rv-talk card" id="<?= $tabId ?>chat" role="tabpanel" aria-labelledby="<?= $tabId ?>tab-chat">
            <div class="rv-talk__head">
                <h3 class="rv-section__title" id="rv-feed-<?= $reqId ?>">Conversa</h3>
                <button type="button" class="btn btn--ghost btn--sm" data-history-toggle aria-pressed="false">Mostrar histórico completo</button>
            </div>

            <?php if (!$isStaff): // a situação de quem pediu fica em destaque no topo do pedido
            elseif ($open && $open['status'] === 'pending'): // cartão só enquanto há uma decisão pendente ?>
                <div class="rv-notice forward-box" role="note" aria-label="Encaminhamento em aberto">
                    <p class="forward-box__title">
                        <?php if ($open['mode'] === 'transfer'): ?>
                            <i class="fa-solid fa-right-left" aria-hidden="true"></i> <?= e($open['from_label']) ?> quer transferir para <?= e($open['to_label']) ?>
                        <?php elseif ($open['status'] === 'accepted'): ?>
                            <i class="fa-solid fa-handshake-angle" aria-hidden="true"></i> <?= e($open['to_label']) ?> está ajudando <?= e($open['from_label']) ?>
                        <?php else: ?>
                            <i class="fa-solid fa-handshake-angle" aria-hidden="true"></i> <?= e($open['from_label']) ?> pediu ajuda a <?= e($open['to_label']) ?>
                        <?php endif; ?>
                    </p>
                    <?php if (trim((string)$open['observation']) !== ''): ?>
                        <blockquote class="forward-box__msg"><?= nl2br(e($open['observation'])) ?></blockquote>
                    <?php endif; ?>
                    <p class="forward-box__meta"><?= e($open['forwarded_by_name'] ?? '') ?>, <?= e(date('d/m \à\s H:i', strtotime($open['created_at']))) ?></p>
                </div>
            <?php elseif ($lastDone && $lastDone['status'] === 'refused'): // aceito ou devolvido já está na conversa
                $doneText = [
                    'accepted' => $lastDone['mode'] === 'transfer'
                        ? 'Veio de ' . $lastDone['from_label'] . ' por transferência'
                        : $lastDone['to_label'] . ' está ajudando',
                    'returned' => $lastDone['to_label'] . ' devolveu a ajuda',
                    'refused' => $lastDone['to_label'] . ' recusou o encaminhamento',
                ][$lastDone['status']];
                $doneNote = $lastDone['status'] === 'accepted' ? (string)$lastDone['observation'] : (string)$lastDone['response_note'];
                if ($lastDone['status'] === 'accepted' && $lastDone['mode'] === 'help') { $doneNote = ''; }
            ?>
                <div class="rv-notice forward-box forward-box--done<?= $lastDone['status'] === 'refused' ? ' forward-box--bad' : '' ?>" role="note" aria-label="Último encaminhamento">
                    <p class="forward-box__title"><?= e($doneText) ?></p>
                    <?php if (trim($doneNote) !== ''): ?><blockquote class="forward-box__msg"><?= nl2br(e($doneNote)) ?></blockquote><?php endif; ?>
                    <p class="forward-box__meta"><?= e(($lastDone['status'] === 'accepted' ? ($lastDone['forwarded_by_name'] ?? '') : ($lastDone['responded_by_name'] ?? '')) ?: '') ?><?= !empty($lastDone['responded_at']) ? ', ' . e(date('d/m \à\s H:i', strtotime((string)$lastDone['responded_at']))) : '' ?></p>
                </div>
            <?php elseif ($refuseReason !== ''): ?>
                <div class="rv-notice forward-box forward-box--bad" role="note" aria-label="Motivo da recusa">
                    <p class="forward-box__title">Pedido recusado</p>
                    <blockquote class="forward-box__msg"><?= nl2br(e($refuseReason)) ?></blockquote>
                </div>
            <?php endif; ?>

            <?php if (count($threads) > 1): // uma aba por conversa; quem só tem uma não vê as abas ?>
                <div class="chat-threads" role="tablist" aria-label="Conversas do pedido">
                    <?php foreach ($threads as $t): ?>
                        <button type="button" role="tab" class="chat-threads__tab<?= $t['unread'] ? ' has-new' : '' ?>" id="<?= $tabId ?>th-<?= e($t['key']) ?>" aria-controls="<?= $tabId ?>feed-<?= e($t['key']) ?>" aria-selected="<?= $t['default'] ? 'true' : 'false' ?>"<?= $t['default'] ? '' : ' tabindex="-1"' ?> data-chat-thread="<?= e($t['key']) ?>"><?php if ($t['key'] === 'internal'): ?><i class="fa-solid fa-lock" aria-hidden="true"></i> <?php endif; ?><?= e($t['label']) ?><?php if ($t['unread']): ?><span class="visually-hidden"> (esperando resposta)</span><?php endif; ?></button>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php foreach ($threads as $t): ?>
                <div class="rv-feed" id="<?= $tabId ?>feed-<?= e($t['key']) ?>" data-chat-feed data-thread="<?= e($t['key']) ?>" <?= $data ?> role="log" aria-label="<?= e($t['label']) ?>"<?= $t['default'] ? '' : ' hidden' ?>><?= renderRequestFeed($pdo, $req, $sector, $viewerId, $isStaff, $t, $forwards, $history) ?></div>
            <?php endforeach; ?>

            <?php // Celular com botões de ação: o campo de mensagem fica recolhido atrás do botão de conversa ?>
            <div class="rv-dock<?= $actionsHtml !== '' ? ' has-actions' : '' ?>">
                <?php if ($actionsHtml !== ''):
                    $anyNew = (bool)array_filter($threads, fn($t) => $t['unread']); ?>
                    <div class="rv-actions rv-actions--dock">
                        <button type="button" class="btn rv-write-btn<?= $anyNew ? ' has-new' : '' ?>" data-write-toggle aria-expanded="false" aria-label="Escrever mensagem<?= $anyNew ? ' (mensagem esperando resposta)' : '' ?>"><i class="fa-regular fa-comment" aria-hidden="true"></i></button>
                        <?= $actionsHtml ?>
                    </div>
                <?php endif; ?>
                <?php foreach ($threads as $t):
                    $hide = $t['default'] ? '' : ' hidden';
                    if (!$t['target']): ?>
                    <p class="chat-readonly" data-thread="<?= e($t['key']) ?>"<?= $hide ?>><i class="fa-regular fa-eye" aria-hidden="true"></i> <span><?= e($t['readHint']) ?></span></p>
                    <?php continue; endif;
                    $to = $t['target'];
                ?>
                    <form class="chat-footer rv-composer<?= $to['channel'] === 'internal' ? ' rv-composer--internal' : '' ?><?= $to['two'] ? ' rv-composer--two' : '' ?>" data-chat-form data-thread="<?= e($t['key']) ?>" <?= $data ?><?= $hide ?>>
                        <input type="hidden" name="channel" value="<?= e($to['channel']) ?>">
                        <input type="hidden" name="area" value="<?= (int)$to['area'] ?>">
                        <div class="chat-input-area">
                            <button type="button" class="rv-write-close" data-write-toggle aria-label="Voltar para as ações"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
                            <textarea name="comment" class="chat-input" placeholder="<?= e($to['placeholder']) ?>" rows="1" required maxlength="3000" aria-label="Mensagem"></textarea>
                            <button type="submit" class="btn-send" aria-label="Enviar mensagem"><i class="fa-solid fa-paper-plane" aria-hidden="true"></i></button>
                        </div>
                        <p class="chat-hint" data-chat-hint><?= e($to['hint']) ?></p>
                    </form>
                <?php endforeach; ?>
            </div>
        </section>

        <aside class="rv-side" aria-label="Resumo do pedido">
            <section class="rv-people card" id="<?= $tabId ?>people" role="tabpanel" aria-labelledby="<?= $tabId ?>tab-people">
                <h3 class="rv-section__title">Quem está envolvido</h3>
                <ul class="rv-people__list">
                    <?php foreach ($people as $p): ?>
                        <li class="rv-person" data-tone="<?= e($p['tone']) ?>">
                            <span class="rv-person__dot" aria-hidden="true"></span>
                            <span class="rv-person__text"><strong><?= e($p['name']) ?></strong><span><?= e($p['role']) ?></span></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>

            <section class="rv-info card" id="<?= $tabId ?>info" role="tabpanel" aria-labelledby="<?= $tabId ?>tab-info">
                <h3 class="rv-section__title">Detalhes</h3>
                <dl class="rv-props">
                    <?php foreach ($props as $label => $value): ?>
                        <div><dt><?= e($label) ?></dt><dd><?= $value ?></dd></div>
                    <?php endforeach; ?>
                </dl>
                <?php if ($obs !== ''): // o motivo da urgência já está no topo ?>
                    <p class="rv-ask__obs"><strong>Observações:</strong> <?= nl2br(e($obs)) ?></p>
                <?php endif; ?>
            </section>
        </aside>
    </div>
</article>
        <?php
    }
}
