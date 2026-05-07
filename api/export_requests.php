<?php
session_start();
require_once dirname(__DIR__) . '/config/conn.php';
require_once dirname(__DIR__) . '/classes/RequestManager.php';

if (!isset($_SESSION['id'])) {
    die("Acesso negado.");
}

$role = $_SESSION['role'] ?? 'solicitante';
$isAdmin = in_array($role, ['admin', 'adm', 'coord', 'gestor', 'adm_sub']);

// Filtros
$filter_sector = $_GET['sector'] ?? '';
$filter_status = $_GET['status'] ?? '';
$filter_start  = $_GET['start']  ?? '';
$filter_end    = $_GET['end']    ?? '';
$filter_search = $_GET['search'] ?? '';

$tables = ['mkt', 'shop', 'xerox', 'service', 'ti'];
$map = ['mkt' => 'Marketing', 'shop' => 'Compras', 'xerox' => 'Xerox', 'service' => 'Serviços', 'ti' => 'TI'];

$isAdmSub = ($role === 'adm_sub');
$managingId = (in_array($role, ['admin', 'adm', 'coord']) || $isAdmSub) ? null : $_SESSION['id'];
$subdivisionFilter = $isAdmSub ? ($_SESSION['subdivision_ids'] ?? null) : null;

// Usar o RequestManager para garantir consistência (incluindo nomes formatados)
$reqData = RequestManager::getRequests(
    $pdo, 
    null, 
    $managingId, 
    $filter_status, 
    $filter_sector ?: null, 
    $filter_search ?: null, 
    'DESC', 
    [], 
    $filter_start ?: null, 
    $filter_end ?: null,
    $subdivisionFilter
);

$data = $reqData['requests'];

// Nomes amigáveis para status e prioridade
$status_labels = ['P' => 'Pendente', 'Y' => 'Aprovado', 'N' => 'Recusado', 'W' => 'Em Andamento', 'C' => 'Concluída'];
$priority_labels = [1 => 'Baixa', 2 => 'Média', 3 => 'Alta', 4 => 'Crítica'];

// Gerar CSV
$filename = "relatorio_requisicoes_" . date('Ymd_His') . ".csv";

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$output = fopen('php://output', 'w');

// BOM para Excel reconhecer UTF-8
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Cabeçalho
fputcsv($output, ['ID', 'Setor', 'Título', 'Solicitante', 'Status', 'Prioridade', 'Data Criação'], ';');

foreach ($data as $row) {
    $st = strtoupper($row['status'] ?? 'P');
    if ($st == '') $st = 'P';
    
    fputcsv($output, [
        $row['id'],
        $map[$row['table']] ?? $row['table'],
        $row['title'],
        $row['solicitor_name_formatted'],
        $status_labels[$st] ?? $st,
        $priority_labels[$row['priority'] ?? 2] ?? 'Média',
        date('d/m/Y H:i', strtotime($row['created_at']))
    ], ';');
}

fclose($output);
exit;
