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
require_once __DIR__ . '/../classes/RequestChat.php';
require_once __DIR__ . '/request_card.php';
require_once __DIR__ . '/partner_picker.php';
require_once __DIR__ . '/request_view/timeline.php';
require_once __DIR__ . '/request_view/people.php';

/** Conversas que esta pessoa vê (abas). A regra mora em RequestChat (vale também para gravar). */
function requestChatThreads(PDO $pdo, array $req, string $sector, int $viewerId, string $role, array $forwards, ?int $ownerArea): array
{
    return RequestChat::threads($pdo, $req, $sector, $viewerId, $role, $forwards, $ownerArea);
}

/** Setor responsável agora: destino da última transferência aceita; sem ela, o setor do formulário */
function requestOwnerFromForwards(PDO $pdo, array $forwards, string $sector): ?int
{
    return RequestForwardService::ownerFromForwards($pdo, $forwards, $sector);
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
    $partner = RequestForwardService::isPartner($f);
    $to = (string)$f['to_label'];
    $from = (string)$f['from_label'];
    $tag = $partner ? 'Parte de ' . $to : ($help ? 'Ajuda de ' . $to : 'Transferência para ' . $to);
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

    $late = RequestForwardService::partnerIsLate($f);
    $dueHtml = $partner && !empty($f['due_date'])
        ? ' <span class="rv-fwd__state' . ($late ? ' rv-late' : '') . '">Prazo da parte ' . e(date('d/m', strtotime((string)$f['due_date']))) . ($late ? ' · atrasada' : '') . '</span>'
        : '';

    return '<section class="rv-fwd card' . ($f['status'] === 'refused' ? ' rv-fwd--bad' : '') . '" aria-label="' . e($tag) . '">'
        . '<p class="rv-fwd__top"><span class="rv-fwd__tag"><i class="fa-solid ' . ($help ? 'fa-handshake-angle' : 'fa-right-left') . '" aria-hidden="true"></i> '
        . e($tag) . '</span>'
        . ($state !== '' ? ' <span class="rv-fwd__state">' . e($state) . '</span>' : '') . $dueHtml . '</p>'
        . '<div class="rv-fwd__cols">'
        . '<div><p class="rv-fwd__label">' . e($partner ? 'A coordenação pediu' : ($mine ? 'Você pediu' : $from . ' pediu')) . '</p>'
        . '<p class="rv-fwd__text">' . ($obs !== '' ? nl2br(e($obs)) : '<span class="rv-fwd__muted">Sem recado.</span>') . '</p></div>'
        . '<div><p class="rv-fwd__label">' . e($answerLabel) . '</p><p class="rv-fwd__text">' . $answer . '</p></div>'
        . '</div></section>';
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
        if (RequestForwardService::isOpen($f)) {
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
    // Setor parceiro: decisão da coordenação (o setor responsável não inclui nem tira)
    $openIsPartner = $open && RequestForwardService::isPartner($open);
    $canManagePartner = userCanManageOriginalRequest($pdo, $req, $sector, $viewerId, $role);
    $canAddPartner = $canManagePartner && in_array($status, ['Y', 'W'], true) && !$open;

    // Conversas separadas (abas): quem pediu × responsável, responsável × quem ajuda, quem pediu × quem ajuda
    $threads = requestChatThreads($pdo, $req, $sector, $viewerId, $chatRole, $forwards, $ownerArea);

    // Datas de cada situação e quem está atendendo (do histórico, lido uma vez para tudo)
    $history = requestHistoryRows($pdo, $reqId, $sector);
    $statusAt = [];
    $worker = '';
    $workerAt = '';
    // Situação => quando chegou nela, pelo event_code (migração 008); valores em código (P/Y/W/C/N/F)
    $statusEvents = ['approved' => 'Y', 'refused' => 'N', 'started' => 'W', 'reopened' => 'W', 'finished' => 'C', 'legacy_finished_forward' => 'C'];
    foreach ($history as $h) {
        $code = (string)($h['event_code'] ?? '');
        $reached = $statusEvents[$code] ?? ($code === 'status' ? (string)$h['new_value'] : null);
        if ($reached === null || $reached === '') {
            continue;
        }
        $statusAt[$reached] = $h['created_at'];
        if ($reached === 'W') {
            $worker = (string)$h['user_name'];
            $workerAt = (string)$h['created_at'];
        }
    }
    // Quem começou antes de uma transferência aceita era do setor anterior
    foreach ($forwards as $f) {
        if (RequestForwardService::isEffectiveTransfer($f)
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
        $acceptMode = $openIsPartner ? 'partner' : ($open['mode'] === 'transfer' ? 'transfer' : 'help');
        $candidates[] = ['main', '<button type="button" class="btn %s" onclick="forwardAct(\'accept\', ' . (int)$open['id'] . ', \'' . $acceptMode . '\')"><i class="fa-solid fa-check" aria-hidden="true"></i> ' . ['partner' => 'Aceitar a parte', 'transfer' => 'Aceitar e assumir', 'help' => 'Aceitar ajuda'][$acceptMode] . '</button>'];
        $candidates[] = ['side', '<button type="button" class="btn" onclick="forwardAct(\'refuse\', ' . (int)$open['id'] . ', \'' . $acceptMode . '\')">Recusar</button>'];
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
    $canCancelForward = $open && ($openIsPartner ? $canManagePartner : ($open['status'] === 'pending' && $openIsFromMe && !$openIsForMe));
    $hasMenu = $canReopen || $canCancelForward || $canCancelRequest || $isStaff;
    $asAdmin = $open && $isGlobalAdmin && !in_array((int)$open['to_area_id'], $viewerAreas, true) && !in_array((int)$open['from_area_id'], $viewerAreas, true);
    $guide = $isStaff ? requestStaffGuide([
        'status' => $status, 'open' => $open, 'openIsForMe' => (bool)$openIsForMe, 'openIsFromMe' => (bool)$openIsFromMe,
        'canApprove' => $canApprove, 'canWork' => $canWork, 'canReopen' => $canReopen, 'canForward' => $canForward,
        'ownerLabel' => $ownerLabel, 'sectorName' => $sectorName, 'asAdmin' => (bool)$asAdmin,
    ]) : '';

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
    // Setor parceiro: a parte em andamento (ou a última entregue) e, antes da aprovação, a sugestão
    $partnerFwd = null;
    foreach ($forwards as $f) {
        if (RequestForwardService::isPartner($f) && !in_array($f['status'], ['refused', 'cancelled'], true)) {
            $partnerFwd = $f;
        }
    }
    if ($partnerFwd && !empty($partnerFwd['due_date'])) {
        $partnerLate = RequestForwardService::partnerIsLate($partnerFwd);
        $props['Prazo de ' . $partnerFwd['to_label']] = '<span class="' . ($partnerLate ? 'rv-late' : '') . '">' . e(date('d/m', strtotime((string)$partnerFwd['due_date'])))
            . ($partnerLate ? ' · atrasada' : ($partnerFwd['status'] === 'returned' ? ' · entregue' : '')) . '</span>';
    }
    $suggestedPartner = $status === 'P' ? RequestForwardService::suggestedPartner($pdo, $sector, $reqId) : null;
    if ($suggestedPartner) {
        $props['Parceiro sugerido'] = e(RequestForwardService::areaLabel($pdo, $suggestedPartner));
    }
    $props['Aberto em'] = e(date('d/m, H\hi', strtotime((string)$req['created_at'])));
    if (!empty($statusAt['Y'])) {
        $props['Aprovado em'] = e(date('d/m, H\hi', strtotime((string)$statusAt['Y'])));
    } elseif (!empty($statusAt['N'])) {
        $props['Recusado em'] = e(date('d/m, H\hi', strtotime((string)$statusAt['N'])));
    }
    if (!empty($statusAt['C'])) {
        $props['Concluído em'] = e(date('d/m, H\hi', strtotime((string)$statusAt['C'])));
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
    $people = requestPeople([
        'req' => $req, 'status' => $status, 'subdivision' => $subdivision, 'history' => $history,
        'forwards' => $forwards, 'ownerArea' => $ownerArea, 'ownerLabel' => $ownerLabel, 'worker' => $worker,
        'viewerId' => $viewerId, 'viewerName' => (string)($_SESSION['name'] ?? ''),
    ]);
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
    // Coordenação: escolher o parceiro ao aprovar (o botão Aprovar envia) ou incluir durante o atendimento
    $partnerSection = '';
    if ($canApprove || $canAddPartner) {
        $partnerSection = '<section class="rv-partner card" data-partner-section aria-label="Setor parceiro"><details' . ($suggestedPartner ? ' open' : '') . '>'
            . '<summary>' . e($canApprove ? ($suggestedPartner ? 'Quem pediu sugeriu um setor parceiro' : 'Outro setor também participa?') : 'Incluir setor parceiro') . '</summary>'
            . renderPartnerPicker(RequestForwardService::availableAreas($pdo, $ownerArea), 'rv' . $reqId, $suggestedPartner, RequestForwardService::defaultPartnerDue($req), $canApprove ? 'Sem setor parceiro' : 'Escolha o setor')
            . ($canAddPartner ? '<button type="button" class="btn rv-partner__add" onclick="addPartner(' . $reqId . ', \'' . $jsSector . '\', this)"><i class="fa-solid fa-handshake-angle" aria-hidden="true"></i> Incluir setor parceiro</button>' : '')
            . '</details></section>';
    }
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
                            <button type="button" class="rv-menu-item" onclick="forwardAct('cancel', <?= (int)$open['id'] ?><?= $openIsPartner ? ", 'partner'" : '' ?>)"><i class="fa-solid fa-ban" aria-hidden="true"></i> <?= $openIsPartner ? 'Tirar setor parceiro' : 'Cancelar envio' ?></button>
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
<?= $partnerSection ?>

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
