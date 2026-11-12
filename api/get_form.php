<?php
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/conn.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../classes/RequestForwardService.php';
require_once __DIR__ . '/../classes/RequestAttachments.php';
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

/**
 * Pergunta Sim/Não sem resposta marcada (a pessoa precisa responder). Sim abre $panelHtml;
 * Não fecha e mostra $noText em vermelho. Os campos do painel ficam desligados enquanto
 * não for Sim (não vão no envio); data-yesno-required vira obrigatório com Sim.
 * O comportamento é o bindYesNo() de assets/js/script.js.
 */
function yesNoField(string $name, string $question, string $noText, string $panelHtml): string
{
    $panelHtml = preg_replace('/<(input|select|textarea)\b/', '<$1 disabled', $panelHtml);
    return '
        <fieldset class="form-group yesno" data-yesno>
            <legend>' . e($question) . ' <span class="req">*</span></legend>
            <div class="aplication_select yesno__options">
                <label><input type="radio" name="' . e($name) . '" value="sim" required data-yesno-yes> Sim</label>
                <label><input type="radio" name="' . e($name) . '" value="nao" data-yesno-no> Não</label>
            </div>
            <p class="yesno__no" data-yesno-note hidden>' . e($noText) . '</p>
            <div class="yesno__panel" data-yesno-panel hidden>' . $panelHtml . '
            </div>
        </fieldset>
';
}

// Quem responde por mais de uma área escolhe a área no passo 1 (pages/new_request.php);
// o valor chega ao formulário por este campo escondido (assets/js/pages/new_request.js)
$areaHtml = count($_SESSION['subdivision_ids'] ?? []) > 1
    ? '<input type="hidden" name="subdivision_id" value="" data-area-hidden>'
    : '';
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
    $sectorTo = (string)(sectorRegistry()[$formSector]['label_to'] ?? 'este setor');
    $priorityHtml .= yesNoField('partner_choice', 'Outro setor também participa?', 'Só ' . $sectorTo . ' vai atender.', '
            <div class="form-group">
                <label for="partner_area_id">Qual setor?</label>
                <select name="partner_area_id" id="partner_area_id" data-yesno-required><option value="">Escolha o setor</option>' . $partnerOptions . '</select>
                <small>A coordenação confirma ou muda quando aprovar o pedido.</small>
            </div>
            <div class="form-group" data-partner2-field hidden>
                <label for="partner2_area_id">Mais um setor? <small>(opcional)</small></label>
                <select name="partner2_area_id" id="partner2_area_id"><option value="">Não, só esse</option>' . $partnerOptions . '</select>
            </div>');
}

if (strpos($formHtml, '<!--PRIORIDADE-->') !== false) {
    $formHtml = str_replace('<!--PRIORIDADE-->', $priorityHtml, $formHtml);
} else {
    $formHtml = str_replace('<button', $priorityHtml . '<button', $formHtml);
}

// Anexos (sempre opcionais): <!--ANEXOS:pdf--> na cópia colorida (só PDF), <!--ANEXOS--> nos outros
$formHtml = preg_replace_callback('/<!--ANEXOS(?::(pdf))?-->/', function (array $m): string {
    static $n = 0;
    $n++;
    $profile = ($m[1] ?? '') === 'pdf' ? 'pdf' : 'geral';
    $exts = RequestAttachments::profiles()[$profile]['ext'];
    $maxFile = RequestAttachments::maxFileBytes();
    $hint = ($profile === 'pdf' ? 'Só PDF' : 'PDF, foto, Word, Excel ou PowerPoint')
        . ', até ' . RequestAttachments::sizeLabel($maxFile) . ' cada (no máximo ' . RequestAttachments::MAX_FILES . ').';
    if ($profile === 'pdf') {
        $hint .= ' Com o PDF anexado, não precisa dizer onde está o arquivo.';
    }
    $accept = implode(',', array_map(fn($x) => '.' . $x, $exts));
    return '
            <div class="form-group attach" data-attach data-ext="' . e(implode(',', $exts)) . '" data-max-file="' . $maxFile . '"'
        . ' data-max-total="' . RequestAttachments::maxTotalBytes() . '" data-max-files="' . RequestAttachments::MAX_FILES . '"'
        . ' data-size-label="' . e(RequestAttachments::sizeLabel($maxFile)) . '"' . ($profile === 'pdf' ? ' data-attach-relaxes="file"' : '') . '>
                <label class="link-add attach__pick" for="attachments_' . $n . '"><i class="fa-solid fa-plus" aria-hidden="true"></i> '
        . ($profile === 'pdf' ? 'Anexar o PDF' : 'Anexar arquivo') . '</label>
                <input type="file" class="visually-hidden" name="attachments[]" id="attachments_' . $n . '" multiple accept="' . e($accept) . '" data-attach-input>
                <ul class="attach__list" data-attach-list></ul>
                <small class="form-hint" data-attach-hint hidden>' . e($hint) . '</small>
            </div>
';
}, $formHtml);

// Dias de compra do setor (config/sectors.php, purchase_weekdays), embaixo do prazo
$purchaseNotice = purchaseNotice(sectorRegistry()[$formSector]['purchase_weekdays'] ?? [], '');
$formHtml = str_replace('<!--DIAS_COMPRA-->', $purchaseNotice
    ? '<p class="form-hint purchase-days"><i class="fa-solid fa-calendar-day" aria-hidden="true"></i> '
        . e(sectorLabel($formSector) . ' faz compras às ' . $purchaseNotice['days'] . '.') . '</p>'
    : '', $formHtml);

// "Mais alguma observação?" com Sim/Não (o mesmo em todos os formulários)
$formHtml = str_replace('<!--OBS-->', yesNoField('obs_choice', 'Mais alguma observação?', 'Sem observações.', '
            <div class="form-group">
                <label for="obs" class="visually-hidden">Observações</label>
                <textarea name="obs" id="obs" rows="2" maxlength="2000" data-yesno-required placeholder="Sala, horário, contato ou algo que ajude quem vai aprovar ou atender"></textarea>
            </div>'), $formHtml);

jsonResponse(['success' => true, 'form' => $formHtml]);
