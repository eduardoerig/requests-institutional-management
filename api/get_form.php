<?php
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/conn.php';
require_once __DIR__ . '/../config/security.php';
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

if (strpos($formHtml, '<!--PRIORIDADE-->') !== false) {
    $formHtml = str_replace('<!--PRIORIDADE-->', $priorityHtml, $formHtml);
} else {
    $formHtml = str_replace('<button', $priorityHtml . '<button', $formHtml);
}

jsonResponse(['success' => true, 'form' => $formHtml]);
