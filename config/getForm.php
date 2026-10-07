<?php
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/conn.php';
require_once __DIR__ . '/security.php';
requireLoginJson($pdo);
requirePostJson();

$id = (int)($_POST['id'] ?? 0);
if (($_POST['action'] ?? '') !== 'get' || $id <= 0) {
    jsonResponse(['success' => false, 'message' => 'Escolha um tipo de requisição.']);
}

$qr = $pdo->prepare('SELECT form_name FROM ctd_requests_models WHERE id = ? AND `status` = ?');
$qr->execute([$id, 'Y']);
$formName = basename((string)$qr->fetchColumn());
$formFile = dirname(__DIR__) . '/components/forms/' . $formName;

if ($formName === '' || !preg_match('/^form_[a-z_]+\.php$/', $formName) || !is_file($formFile)) {
    jsonResponse(['success' => false, 'message' => 'Este tipo de requisição não está disponível.']);
}

$formHtml = file_get_contents($formFile);

// Urgência em botões grandes (substitui o antigo campo "urgente")
$priorityHtml = '
    <fieldset class="form-group priority-picker">
        <legend>Qual a urgência? <span class="req">*</span></legend>
        <label class="priority-option"><input type="radio" name="priority" value="1"><span><strong>Baixa</strong><small>Sem pressa</small></span></label>
        <label class="priority-option"><input type="radio" name="priority" value="2" checked><span><strong>Normal</strong><small>No prazo pedido</small></span></label>
        <label class="priority-option"><input type="radio" name="priority" value="3"><span><strong>Alta</strong><small>Precisa ser rápido</small></span></label>
        <label class="priority-option"><input type="radio" name="priority" value="4"><span><strong>Crítica</strong><small>Está parando o trabalho</small></span></label>
    </fieldset>
';

if (strpos($formHtml, '<!--PRIORIDADE-->') !== false) {
    $formHtml = str_replace('<!--PRIORIDADE-->', $priorityHtml, $formHtml);
} else {
    $formHtml = str_replace('<button', $priorityHtml . '<button', $formHtml);
}

jsonResponse(['success' => true, 'form' => $formHtml]);
