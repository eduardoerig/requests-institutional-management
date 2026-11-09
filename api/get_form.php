<?php
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/conn.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../classes/RequestForwardService.php';
requireLoginJson($pdo);
requirePostJson();

$id = (int)($_POST['id'] ?? 0);
if (($_POST['action'] ?? '') !== 'get' || $id <= 0) {
    jsonResponse(['success' => false, 'message' => 'Escolha um tipo de pedido.']);
}

$qr = $pdo->prepare('SELECT form_name FROM ctd_requests_models WHERE id = ? AND `status` = ?');
$qr->execute([$id, 'Y']);
$formName = basename((string)$qr->fetchColumn());
$formFile = dirname(__DIR__) . '/components/forms/' . $formName;

if ($formName === '' || !preg_match('/^form_[a-z_]+\.php$/', $formName) || !is_file($formFile)) {
    jsonResponse(['success' => false, 'message' => 'Este tipo de pedido não está disponível.']);
}

$formHtml = file_get_contents($formFile);

// Quem responde por mais de uma área diz de qual é o pedido: é isso que define quem aprova
$areaHtml = '';
$userAreaIds = $_SESSION['subdivision_ids'] ?? [];
if (count($userAreaIds) > 1) {
    $areaOptions = '';
    foreach ($userAreaIds as $i => $areaId) {
        $areaOptions .= '<option value="' . (int)$areaId . '">' . e((string)($_SESSION['subdivision_names'][$i] ?? '')) . '</option>';
    }
    $areaHtml = '
        <div class="form-group">
            <label for="subdivision_id">De qual área é este pedido? <span class="req">*</span></label>
            <select name="subdivision_id" id="subdivision_id" required><option value="">Escolha a área</option>' . $areaOptions . '</select>
            <small>A coordenação dessa área é quem aprova.</small>
        </div>
';
}
$formHtml = str_replace('<!--AREA-->', $areaHtml, $formHtml);

// Quem pede só diz se é urgente (e por quê); o nível de urgência fica com o setor
$priorityHtml = '
    <div class="form-group urgent-field">
        <label class="check-line urgent-switch" for="urgent">
            <span class="urgent-switch__text">
                <span class="urgent-switch__title">É urgente</span>
                <span class="urgent-switch__hint">Está parando uma aula ou o trabalho. Quem aprova e quem atende veem o pedido em destaque.</span>
            </span>
            <input type="checkbox" role="switch" name="urgent" value="1" id="urgent" data-urgent>
        </label>
        <div class="urgent-field__reason" data-urgent-reason hidden>
            <label for="urgent_reason">Por que é urgente? <span class="req">*</span></label>
            <input type="text" name="urgent_reason" id="urgent_reason" maxlength="255" placeholder="Ex.: a turma do 5º ano está sem projetor">
        </div>
    </div>
';

// Quem pede pode sugerir até 2 setores parceiros; a coordenação decide ao aprovar
$formSector = '';
foreach (sectorRegistry() as $slug => $s) {
    if ($s['form_file'] === $formName) {
        $formSector = $slug;
    }
}
$partnerOptions = '';
foreach (RequestForwardService::availableAreas($pdo, getAreaIdBySector($pdo, $formSector)) as $area) {
    $partnerOptions .= '<option value="' . (int)$area['id'] . '">' . e($area['label']) . '</option>';
}
if ($partnerOptions !== '') {
    $priorityHtml .= '
    <details class="form-more">
        <summary>Outro setor também precisa participar? <small>(opcional)</small></summary>
        <div class="form-group">
            <label for="partner_area_id">Qual setor?</label>
            <select name="partner_area_id" id="partner_area_id"><option value="">Não, só este setor</option>' . $partnerOptions . '</select>
            <small>A coordenação confirma ou muda quando aprovar o pedido.</small>
        </div>
        <div class="form-group" data-partner2-field hidden>
            <label for="partner2_area_id">Mais um setor? <small>(opcional)</small></label>
            <select name="partner2_area_id" id="partner2_area_id"><option value="">Não, só esse</option>' . $partnerOptions . '</select>
        </div>
    </details>
';
}

if (strpos($formHtml, '<!--PRIORIDADE-->') !== false) {
    $formHtml = str_replace('<!--PRIORIDADE-->', $priorityHtml, $formHtml);
} else {
    $formHtml = str_replace('<button', $priorityHtml . '<button', $formHtml);
}

jsonResponse(['success' => true, 'form' => $formHtml]);
