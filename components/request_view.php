<?php
/**
 * Pedido completo (pages/request_detail.php e api/request_view.php) — UI 2.0.
 *
 * renderRequestView($pdo, $req, $sector)
 *   Cabeçalho (nº · setor · prazo, selo da situação, título, urgência, próximo passo e as ações),
 *   o cartão do encaminhamento, a conversa (linha do tempo em balões) e, ao lado, os detalhes
 *   e "Quem está envolvido".
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
                'target' => $target('internal', 0, "Quem lê: só os setores. $who não vê esta conversa.", 'Mensagem para ' . $ownerLabel),
                'empty' => "Nenhuma mensagem com $ownerLabel ainda. Use o campo abaixo para falar com o setor que pediu ajuda.", 'readHint' => ''];
            foreach (array_intersect($helperAreas, RequestForwardService::userAreaIds($pdo, $viewerId)) as $a) {
                $open = in_array($a, $openAreas, true);
                $threads[] = ['key' => 'helper-' . $a, 'label' => 'Com ' . $who, 'events' => false, 'sources' => [['helper', $a, '', false]],
                    'target' => $open ? $target('helper', $a, "$who recebe sua mensagem; $ownerLabel também acompanha.", 'Mensagem para ' . $who) : null,
                    'empty' => "Nenhuma mensagem com $who ainda." . ($open ? ' Use o campo abaixo para falar com essa pessoa.' : ''),
                    'readHint' => 'A ajuda já foi devolvida. Esta conversa fica só para consulta.'];
            }
        } else {
            $myAreas = RequestForwardService::userAreaIds($pdo, $viewerId);
            $party = in_array($ownerArea, $myAreas, true); // coordenação e admin só acompanham
            // Setores do outro lado da conversa entre setores: chamados para ajudar, com transferência
            // pendente ou que já passaram o pedido adiante. Sempre o setor que não é o de quem vê (depois
            // de uma transferência, quem assumiu fala "Com TI", não consigo mesmo)
            $helpers = [];
            $handedOver = false; // meu setor transferiu o pedido e não é mais o responsável
            foreach ($forwards as $f) {
                $live = ($f['mode'] === 'help' && !in_array($f['status'], ['refused', 'cancelled'], true))
                    || ($f['mode'] === 'transfer' && in_array($f['status'], ['pending', 'accepted', 'completed'], true));
                if (!$live) {
                    continue;
                }
                $to = (int)$f['to_area_id'];
                $from = (int)$f['from_area_id'];
                $other = match (true) {
                    in_array($to, $myAreas, true) => $from,
                    in_array($from, $myAreas, true) => $to,
                    default => $to === $ownerArea ? $from : $to, // coordenação e admin: o setor que não é o responsável
                };
                if (!in_array($other, $myAreas, true)) {
                    $helpers[$other] = $other === $to ? (string)$f['to_label'] : (string)$f['from_label'];
                }
                $handedOver = $handedOver || ($f['mode'] === 'transfer' && $f['status'] !== 'pending' && in_array($from, $myAreas, true) && !$party);
            }
            unset($helpers[0]);
            $threads[] = ['key' => 'main', 'label' => 'Com ' . $who, 'events' => true, 'fwdEvents' => !$helpers,
                'sources' => array_merge([['public', 0, '', false]], $helperSources),
                'target' => $handedOver ? null : $target('public', 0, "$who (quem pediu) vê esta conversa. Dúvidas sobre o pedido, pergunte aqui.", 'Mensagem para ' . $who . ' (quem pediu)'),
                'empty' => 'Ninguém escreveu ainda. Use o campo abaixo para falar sobre o pedido.',
                'readHint' => $handedOver ? "O pedido foi transferido para $ownerLabel. Esta conversa fica só para consulta; fale com $ownerLabel na aba ao lado." : ''];
            if ($helpers) {
                $with = count($helpers) === 1 ? reset($helpers) : 'os setores';
                // Os encaminhamentos (pediu ajuda, devolveu, transferiu) ficam aqui: $who não vê
                $threads[] = ['key' => 'internal', 'label' => 'Com ' . $with, 'events' => false, 'fwdEvents' => true, 'sources' => [['internal', 0, '', false]],
                    'target' => $target('internal', 0, 'Quem lê: ' . implode(' e ', array_unique([$ownerLabel, $with])) . ". $who não vê esta conversa.", 'Mensagem para ' . $with),
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
        // Encaminhamentos (pediu ajuda, devolveu, transferiu) com a mensagem: só para os setores e, quando há
        // conversa entre setores, só nela — na conversa com quem pediu ele não vê esses recados
        $fwdEvents = $thread['fwdEvents'] ?? $thread['events'];
        if ($thread['events'] || $fwdEvents) {
            $forwards = $isStaff && $fwdEvents ? $allForwards : [];
            $skip = '/^(Encaminhou|Pediu ajuda|Setor .* (assumiu|aceitou|recusou|devolveu)|Cancelou o encaminhamento|Assumiu o pedido|Aceitou ajudar|Recusou o encaminhamento|Devolveu a ajuda'
                . ($forwards ? '|Requisição repassada|Repasse aceito' : '') . ')/u';

            if ($history === null && $thread['events']) {
                $history = requestHistoryRows($pdo, $reqId, $sector);
            }
            foreach ($thread['events'] ? $history : [] as $h) {
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

    /** Situação curta para o selo (cabeçalho e Detalhes) */
    function requestStatusText(string $status, ?array $open = null): string
    {
        if ($open && $open['mode'] === 'transfer' && $open['status'] === 'pending') {
            return 'Transferindo';
        }
        return ['P' => 'Aguardando aprovação', 'Y' => 'Aprovado', 'F' => 'Aprovado', 'W' => 'Em atendimento', 'C' => 'Concluído', 'N' => 'Recusado'][$status] ?? statusLabel($status);
    }

    /**
     * Cartão do encaminhamento, entre o cabeçalho e a conversa: o mais recente (em aberto ou o último
     * respondido). Diz o tipo (ajuda/transferência), em que pé está, o que foi pedido e o que voltou.
     */
    function renderForwardCard(array $f, array $viewerAreas): string
    {
        $help = $f['mode'] === 'help';
        $to = (string)$f['to_label'];
        $from = (string)$f['from_label'];
        $by = (string)($f['responded_by_name'] ?? '');
        $when = !empty($f['responded_at']) ? date('d/m', strtotime((string)$f['responded_at'])) : '';
        $state = match ($f['status']) {
            'pending' => 'Esperando ' . $to . ' aceitar',
            'accepted' => ($help ? 'Aceita' : 'Assumida') . ($by !== '' ? ' por ' . $by : ''),
            'returned' => 'Devolvida' . ($by !== '' ? ' por ' . $by : '') . ($when !== '' ? ' · ' . $when : ''),
            'refused' => 'Recusada' . ($by !== '' ? ' por ' . $by : ''),
            'completed' => 'Concluída',
            default => '',
        };
        $mine = in_array((int)$f['from_area_id'], $viewerAreas, true) && !in_array((int)$f['to_area_id'], $viewerAreas, true);
        $note = trim((string)($f['response_note'] ?? ''));
        $answerLabel = $f['status'] === 'refused' ? 'Motivo da recusa' : ($help ? $to . ' respondeu' : $to . ' disse');
        $answer = $note !== '' ? nl2br(e($note))
            : '<span class="rv-fwd__muted">' . e($f['status'] === 'pending' ? 'Ainda não respondeu.' : ($help && $f['status'] === 'accepted' ? 'Ainda não devolveu. A conversa com ' . $to . ' fica na aba ao lado.' : 'Sem recado.')) . '</span>';
        $obs = trim((string)($f['observation'] ?? ''));

        return '<section class="rv-fwd card' . ($f['status'] === 'refused' ? ' rv-fwd--bad' : '') . '" aria-label="' . e($help ? 'Ajuda de ' . $to : 'Transferência para ' . $to) . '">'
            . '<p class="rv-fwd__top"><span class="rv-fwd__tag"><i class="fa-solid ' . ($help ? 'fa-handshake-angle' : 'fa-right-left') . '" aria-hidden="true"></i> '
            . e($help ? 'Ajuda de ' . $to : 'Transferência para ' . $to) . '</span>'
            . ($state !== '' ? ' <span class="rv-fwd__state">' . e($state) . '</span>' : '') . '</p>'
            . '<div class="rv-fwd__cols">'
            . '<div><p class="rv-fwd__label">' . e($mine ? 'Você pediu' : $from . ' pediu') . '</p>'
            . '<p class="rv-fwd__text">' . ($obs !== '' ? nl2br(e($obs)) : '<span class="rv-fwd__muted">Sem recado.</span>') . '</p></div>'
            . '<div><p class="rv-fwd__label">' . e($answerLabel) . '</p><p class="rv-fwd__text">' . $answer . '</p></div>'
            . '</div></section>';
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
    function requestPeople(array $req, string $status, string $subdivision, array $history, array $forwards, ?int $ownerArea, string $ownerLabel, string $worker, int $viewerId = 0, string $viewerName = ''): array
    {
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

    function renderRequestView(PDO $pdo, array $req, string $sector, string $context = 'page'): void
    {
        $viewerId = (int)($_SESSION['id'] ?? 0);
        $role = $_SESSION['role'] ?? 'solicitante';
        $reqId = (int)$req['id'];
        $status = strtoupper((string)($req['status'] ?? 'P')) ?: 'P';
        $isRequester = (int)($req['created_by'] ?? 0) === $viewerId;
        // Quem pediu conversa como quem pediu, qualquer que seja o perfil (não vê a conversa interna)
        $chatRole = requestChatRole($pdo, $req, $sector, $viewerId, $role);
        $isStaff = $chatRole !== 'solicitante';
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
        // Ninguém aprova o próprio pedido (api/approve_request.php recusa)
        $canApprove = $status === 'P' && !$isRequester && isAdminRole($role) && userCanManageOriginalRequest($pdo, $req, $sector, $viewerId, $role);
        $canForward = ($canWork || $isGlobalAdmin) && in_array($status, ['Y', 'W'], true) && !$open;
        $canPriority = userCanActOnRequest($pdo, $req, $sector, $viewerId, $role) && !in_array($status, ['C', 'N'], true);
        $canReopen = $isOwner && $status === 'C';
        $canCancelRequest = $isRequester && $status === 'P';
        $openIsForMe = $open && ($isGlobalAdmin || in_array((int)$open['to_area_id'], $viewerAreas, true));
        $openIsFromMe = $open && ($isGlobalAdmin || (int)$open['forwarded_by'] === $viewerId || in_array((int)$open['from_area_id'], $viewerAreas, true));

        // Conversas separadas (abas): quem pediu × responsável, responsável × quem ajuda, quem pediu × quem ajuda
        $threads = requestChatThreads($pdo, $req, $sector, $viewerId, $chatRole, $forwards, $ownerArea);

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
        $fullTable = requestTableName($sector);
        $hasDeadline = !empty($req['date']) && strpos((string)$req['date'], '0000') !== 0;
        $priority = (int)($req['priority'] ?? 2);
        $isUrgent = $priority >= 3 || !empty($req['urgent']);
        $isClosed = in_array($status, ['C', 'N'], true);

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
            $candidates[] = ['main', '<button type="button" class="btn %s" onclick="forwardAct(\'accept\', ' . (int)$open['id'] . ', \'' . ($open['mode'] === 'transfer' ? 'transfer' : 'help') . '\')"><i class="fa-solid fa-check" aria-hidden="true"></i> ' . ($open['mode'] === 'transfer' ? 'Aceitar e assumir' : 'Aceitar ajuda') . '</button>'];
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
        $actionsHtml = implode('', $sideButtons) . $mainButton; // barra do celular: a principal fica à direita, perto do polegar
        $headActionsHtml = $mainButton . implode('', $sideButtons); // cabeçalho (computador): a principal primeiro
        $canCancelForward = $openIsFromMe && $open['status'] === 'pending' && !$openIsForMe;
        $hasMenu = $canReopen || $canCancelForward || $canCancelRequest || $isStaff;
        $asAdmin = $open && $isGlobalAdmin && !in_array((int)$open['to_area_id'], $viewerAreas, true) && !in_array((int)$open['from_area_id'], $viewerAreas, true);
        $guide = $isStaff ? requestStaffGuide($status, $open, (bool)$openIsForMe, (bool)$openIsFromMe, $canApprove, $canWork, $canReopen, $canForward, $ownerLabel, $sectorName, $asAdmin) : '';

        // Detalhes: situação e urgência numa linha cada; o resto em duas colunas.
        // Quem pediu, quem atende e de onde veio ficam em "Quem está envolvido"
        $statusPill = '<span class="rv-pill rv-pill--' . e($status) . '">' . e(requestStatusText($status, $open)) . '</span>';
        $wide = [];
        $wide['Situação'] = $statusPill;
        if ($canPriority) {
            $options = '';
            foreach (priorityLabels() as $v => $l) {
                $options .= '<option value="' . $v . '"' . ($priority === $v ? ' selected' : '') . '>' . e($l) . '</option>';
            }
            $wide['Urgência'] = '<select class="rv-props__select" aria-label="Urgência" aria-describedby="' . 'rv' . $reqId . '-prio-hint" data-current="' . $priority . '" onchange="changePriority(this, ' . $reqId . ', \'' . e($fullTable) . '\')">' . $options . '</select>'
                . '<small id="rv' . $reqId . '-prio-hint">Mudar pede confirmação.</small>';
        } else {
            $wide['Urgência'] = '<span class="' . ($priority >= 3 ? 'prio-tag prio-tag--' . $priority : '') . '">' . e(priorityLabel($priority)) . '</span>';
        }
        $props = [];
        $weekdays = ['domingo', 'segunda', 'terça', 'quarta', 'quinta', 'sexta', 'sábado'];
        $lateDays = $hasDeadline && !$isClosed ? (int)floor((strtotime(date('Y-m-d')) - strtotime(date('Y-m-d', strtotime((string)$req['date'])))) / 86400) : 0;
        $props['Prazo'] = $hasDeadline
            ? '<span class="' . ($lateDays > 0 ? 'rv-late' : '') . '">' . e(date('d/m', strtotime((string)$req['date'])) . ', ' . $weekdays[(int)date('w', strtotime((string)$req['date']))]) . '</span>'
            : 'Sem prazo';
        $props['Aberto em'] = e(date('d/m, H\hi', strtotime((string)$req['created_at'])));
        if (!empty($statusAt['Aprovada'])) {
            $props['Aprovado em'] = e(date('d/m, H\hi', strtotime((string)$statusAt['Aprovada'])));
        } elseif (!empty($statusAt['Recusada'])) {
            $props['Recusado em'] = e(date('d/m, H\hi', strtotime((string)$statusAt['Recusada'])));
        }
        if (!empty($statusAt[statusLabel('C')])) {
            $props['Concluído em'] = e(date('d/m, H\hi', strtotime((string)$statusAt[statusLabel('C')])));
        }
        foreach ($extra as $label => $value) { // campos do tipo de pedido
            $value = trim((string)$value);
            if ($value === '') {
                continue;
            }
            $props[$label] = preg_match('#^https?://#i', $value)
                ? '<a href="' . e($value) . '" target="_blank" rel="noopener noreferrer">Abrir arquivo</a>'
                : e($value);
        }
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
        $people = requestPeople($req, $status, $subdivision, $history, $forwards, $ownerArea, $ownerLabel, $worker, $viewerId, (string)($_SESSION['name'] ?? ''));
        $metaLine = '#' . $reqId;
        $deadlineShort = $hasDeadline ? date('d/m', strtotime((string)$req['date'])) : '';
        // Atraso em dias no topo ("Atrasado há 2 dias"); hoje/amanhã usam o aviso de sempre
        $deadlineFlag = match (true) {
            $lateDays === 1 => '<span class="rv-late"><i class="fa-regular fa-clock" aria-hidden="true"></i> Atrasado há 1 dia</span>',
            $lateDays > 1 => '<span class="rv-late"><i class="fa-regular fa-clock" aria-hidden="true"></i> Atrasado há ' . $lateDays . ' dias</span>',
            $hasDeadline && !$isClosed => requestDeadlineFlag((string)$req['date']),
            default => '',
        };
        $urgentTitle = match (true) {
            $priority >= 4 => 'Urgência crítica',
            $priority === 3 => 'Urgência alta',
            default => 'Urgente',
        };
        // Cartão do encaminhamento: o aberto ou, sem ele, o último que não foi cancelado (só para os setores)
        $fwdCard = null;
        if ($isStaff) {
            $fwdCard = $open;
            foreach (array_reverse($forwards) as $f) {
                if ($fwdCard || $f['status'] === 'cancelled') {
                    continue;
                }
                $fwdCard = $f;
            }
        }
        $tabId = 'rv' . $reqId . '-';
        ?>
<article class="request-view request-view--<?= e($context) ?> rv2" <?= $data ?> data-title="<?= e($req['title'] ?? '') ?>">
    <header class="rv-head card">
        <div class="rv-head__main">
            <div class="rv-head__top">
                <p class="rv-meta"><span class="rv-num"><?= e($metaLine) ?></span> · <span class="sector-tag" data-sector="<?= e($sector) ?>"><?= e($sectorName) ?></span><?php if ($deadlineShort !== '' && $lateDays <= 0): ?> · prazo <?= e($deadlineShort) ?><?php endif; ?><?= $deadlineFlag !== '' ? ' · ' . $deadlineFlag : '' ?></p>
                <?php if ($isStaff): // quem pediu vê a situação por extenso logo abaixo ?>
                    <?= $statusPill ?>
                <?php endif; ?>
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
                    <p class="rv-ask__who"><?= e(trim((string)($req['creator_name'] ?? '')) ?: 'Quem pediu') ?> pediu:</p>
                    <p class="rv-ask__body"><?= nl2br(e($ask)) ?></p>
                    <button type="button" class="rv-ask__more" data-rv-ask-more hidden>Ver tudo</button>
                </div>
            <?php endif; ?>
            <?php if ($isUrgent): ?>
                <p class="rv-urgent"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i> <strong><?= e($urgentTitle) ?></strong><?= $urgentReason !== '' ? ' · ' . e($urgentReason) : '' ?></p>
            <?php endif; ?>
            <?php if ($guide !== ''): ?>
                <p class="rv-guide"><i class="fa-solid fa-arrow-right" aria-hidden="true"></i> <span><strong>Próximo passo:</strong> <?= $guide ?></span></p>
            <?php endif; ?>
        </div>
        <?php if ($headActionsHtml !== '' || $hasMenu): ?>
            <div class="rv-actions rv-actions--head">
                <?= $headActionsHtml ?>
                <?php if ($hasMenu): ?>
                    <details class="rv-more">
                        <summary class="btn rv-more__btn" title="Mais opções" aria-label="Mais opções"><i class="fa-solid fa-ellipsis" aria-hidden="true"></i></summary>
                        <div class="rv-more__menu">
                            <?php if ($canReopen): ?>
                                <button type="button" class="rv-menu-item" onclick="requestAct('reset_status', <?= $reqId ?>, '<?= e($fullTable) ?>')"><i class="fa-solid fa-rotate-left" aria-hidden="true"></i> Reabrir pedido</button>
                            <?php endif; ?>
                            <?php if ($canCancelForward): ?>
                                <button type="button" class="rv-menu-item" onclick="forwardAct('cancel', <?= (int)$open['id'] ?>)"><i class="fa-solid fa-ban" aria-hidden="true"></i> Cancelar envio</button>
                            <?php endif; ?>
                            <?php if ($isStaff): ?>
                                <a class="rv-menu-item" href="print_request.php?id=<?= $reqId ?>&table=<?= e($sector) ?>" onclick="return printRequest(event, this.href)"><i class="fa-solid fa-print" aria-hidden="true"></i> Imprimir</a>
                            <?php endif; ?>
                            <?php if ($canCancelRequest): ?>
                                <button type="button" class="rv-menu-item rv-menu-item--danger" onclick="requestAct('delete', <?= $reqId ?>, '<?= e($fullTable) ?>')"><i class="fa-solid fa-trash-can" aria-hidden="true"></i> Cancelar este pedido</button>
                            <?php endif; ?>
                        </div>
                    </details>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <div class="rv-tabs" role="tablist" aria-label="Partes do pedido">
            <button type="button" role="tab" id="<?= $tabId ?>tab-chat" aria-controls="<?= $tabId ?>chat" aria-selected="true" data-rv-tab="chat">Conversa</button>
            <button type="button" role="tab" id="<?= $tabId ?>tab-info" aria-controls="<?= $tabId ?>info" aria-selected="false" tabindex="-1" data-rv-tab="info">Detalhes</button>
            <button type="button" role="tab" id="<?= $tabId ?>tab-people" aria-controls="<?= $tabId ?>people" aria-selected="false" tabindex="-1" data-rv-tab="people">Envolvidos</button>
        </div>
    </header>

    <?php if ($fwdCard): ?>
        <?= renderForwardCard($fwdCard, $viewerAreas) ?>
    <?php elseif ($isStaff && $refuseReason !== ''): ?>
        <section class="rv-fwd rv-fwd--bad card" aria-label="Motivo da recusa">
            <p class="rv-fwd__top"><span class="rv-fwd__tag"><i class="fa-solid fa-circle-xmark" aria-hidden="true"></i> Pedido recusado</span></p>
            <p class="rv-fwd__text"><?= nl2br(e($refuseReason)) ?></p>
        </section>
    <?php endif; ?>

    <div class="rv-body">
        <section class="rv-talk card" id="<?= $tabId ?>chat" role="tabpanel" aria-labelledby="<?= $tabId ?>tab-chat">
            <div class="rv-talk__head">
                <h3 class="rv-section__title visually-hidden" id="rv-feed-<?= $reqId ?>">Conversa</h3>
                <?php if (count($threads) > 1): // uma aba por conversa; quem só tem uma não vê as abas ?>
                    <div class="chat-threads" role="tablist" aria-label="Conversas do pedido">
                        <?php foreach ($threads as $t): ?>
                            <button type="button" role="tab" class="chat-threads__tab<?= $t['unread'] ? ' has-new' : '' ?>" id="<?= $tabId ?>th-<?= e($t['key']) ?>" aria-controls="<?= $tabId ?>feed-<?= e($t['key']) ?>" aria-selected="<?= $t['default'] ? 'true' : 'false' ?>"<?= $t['default'] ? '' : ' tabindex="-1"' ?> data-chat-thread="<?= e($t['key']) ?>"><?php if ($t['key'] === 'internal'): ?><i class="fa-solid fa-lock" aria-hidden="true"></i> <?php endif; ?><?= e($t['label']) ?><?php if ($t['unread']): ?><span class="visually-hidden"> (esperando resposta)</span><?php endif; ?></button>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <button type="button" class="rv-history-link" data-history-toggle aria-pressed="false">Mostrar histórico completo</button>
            </div>

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
                            <button type="submit" class="btn-send" aria-label="Enviar mensagem"><i class="fa-solid fa-paper-plane" aria-hidden="true"></i><span class="btn-send__text" aria-hidden="true">Enviar</span></button>
                        </div>
                        <p class="chat-hint" data-chat-hint><i class="fa-regular fa-eye" aria-hidden="true"></i> <?= e($to['hint']) ?></p>
                    </form>
                <?php endforeach; ?>
            </div>
        </section>

        <aside class="rv-side" aria-label="Resumo do pedido">
            <section class="rv-info card" id="<?= $tabId ?>info" role="tabpanel" aria-labelledby="<?= $tabId ?>tab-info">
                <h3 class="rv-section__title">Detalhes</h3>
                <dl class="rv-props">
                    <?php foreach ($wide as $label => $value): ?>
                        <div class="rv-props__wide"><dt><?= e($label) ?></dt><dd><?= $value ?></dd></div>
                    <?php endforeach; ?>
                    <?php foreach ($props as $label => $value): ?>
                        <div><dt><?= e($label) ?></dt><dd><?= $value ?></dd></div>
                    <?php endforeach; ?>
                </dl>
                <?php if ($obs !== ''): // o motivo da urgência já está no topo ?>
                    <p class="rv-ask__obs"><strong>Observações:</strong> <?= nl2br(e($obs)) ?></p>
                <?php endif; ?>
            </section>

            <section class="rv-people card" id="<?= $tabId ?>people" role="tabpanel" aria-labelledby="<?= $tabId ?>tab-people">
                <h3 class="rv-section__title">Quem está envolvido</h3>
                <ul class="rv-people__list">
                    <?php foreach ($people as $p): ?>
                        <li class="rv-person" data-tone="<?= e($p['tone']) ?>">
                            <span class="rv-person__avatar" aria-hidden="true"><?= e($p['initials']) ?></span>
                            <span class="rv-person__text"><strong><?= e($p['name']) ?></strong><span><?= e($p['role']) ?></span></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
        </aside>
    </div>
</article>
        <?php
    }
}
