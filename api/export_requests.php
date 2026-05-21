<?php
/**
 * api/export_requests.php
 * Gera um arquivo CSV real, formatado para Excel (ponto-e-vírgula e BOM UTF-8).
 */
session_start();
require_once dirname(__DIR__) . '/config/conn.php';
require_once dirname(__DIR__) . '/classes/RequestManager.php';

if (!isset($_SESSION['id'])) die("Acesso negado.");

$role     = $_SESSION['role'] ?? 'solicitante';
$isAdmin  = in_array($role, ['admin', 'adm', 'coord', 'adm_sub']);
$isGestor = in_array($role, ['gestor', 'ti', 'xerox', 'service', 'shop', 'mkt', 'marketing']);
if (!$isAdmin && !$isGestor) die("Sem permissão.");

// --- Filtros ---
$filter_sector = $_GET['sector'] ?? '';
$filter_status = $_GET['status'] ?? '';
$filter_search = $_GET['search'] ?? '';
$filter_date   = $_GET['date']   ?? '';
$filter_start  = $_GET['start']  ?? '';
$filter_end    = $_GET['end']    ?? '';

$map = [
    'mkt' => 'Marketing', 
    'shop' => 'Compras', 
    'xerox' => 'Reprografia', 
    'service' => 'Manutenção', 
    'ti' => 'TI'
];

$isAdmSub          = ($role === 'adm_sub');
$managingId        = (in_array($role, ['admin', 'adm', 'coord']) || $isAdmSub) ? null : $_SESSION['id'];
$subdivisionFilter = $isAdmSub ? ($_SESSION['subdivision_ids'] ?? null) : null;

$reqData = RequestManager::getRequests(
    $pdo, null, $managingId,
    $filter_status ?: null,
    $filter_sector ?: null,
    $filter_search ?: null,
    'DESC', [],
    $filter_start ?: null,
    $filter_end   ?: null,
    $subdivisionFilter
);

$data = $reqData['requests'];

// --- Status e Prioridades ---
$status_labels   = ['P' => 'Pendente', 'Y' => 'Aprovada', 'N' => 'Recusada', 'W' => 'Em Andamento', 'C' => 'Concluída', 'F' => 'Repassada'];
$priority_labels = [1 => 'Baixa', 2 => 'Média', 3 => 'Alta', 4 => 'Crítica'];

// --- Helper: formata data ---
function fmtDate($value) {
    if (!$value || $value === '0000-00-00' || $value === '0000-00-00 00:00:00') return '';
    $ts = strtotime($value);
    return $ts ? date('d/m/Y', $ts) : '';
}

// --- Configuração do Arquivo ---
$filename = 'SisReq_Relatorio_' . date('Ymd_His') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

// Abre o output stream
$output = fopen('php://output', 'w');

// Envia o BOM UTF-8 para o Excel reconhecer os caracteres especiais (acentos)
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Cabeçalho do CSV
$header = [
    'ID', 
    'Título', 
    'Solicitante', 
    'Setor', 
    'Subdivisão', 
    'Status', 
    'Prioridade', 
    'Data Abertura', 
    'Prazo Entrega', 
    'Local/Sala', 
    'Urgente',
    'Descrição'
];

fputcsv($output, $header, ';');

// Dados
foreach ($data as $row) {
    $st  = strtoupper($row['status'] ?? 'P');
    $pri = (int)($row['priority'] ?? 2);
    
    $line = [
        $row['id'],
        $row['title'] ?? '',
        $row['solicitor_name_formatted'] ?? '',
        $map[$row['table']] ?? $row['table'],
        $row['subdivision_name'] ?? 'N/A',
        $status_labels[$st] ?? $st,
        $priority_labels[$pri] ?? 'Média',
        fmtDate($row['created_at']),
        fmtDate($row['date']),
        $row['sala'] ?? '',
        (!empty($row['urgent']) ? 'SIM' : 'Não'),
        // Limpa quebras de linha da descrição para não quebrar o CSV
        str_replace(["\r", "\n"], ' ', strip_tags($row['descp'] ?? ''))
    ];
    
    fputcsv($output, $line, ';');
}

fclose($output);
exit;
