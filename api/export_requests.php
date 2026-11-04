<?php
/**
 * api/export_requests.php
 * Gera um arquivo CSV real, formatado para Excel (ponto-e-vírgula e BOM UTF-8).
 */
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/conn.php';
require_once __DIR__ . '/../config/security.php';
requireLoginJson($pdo);
require_once dirname(__DIR__) . '/classes/RequestManager.php';

if (!isset($_SESSION['id'])) die("Acesso negado.");

$role     = $_SESSION['role'] ?? 'solicitante';
$isAdmin  = isAdminRole($role);
$isGestor = isGestorRole($role);
if (!$isAdmin && !$isGestor) die("Sem permissão.");

// --- Filtros ---
$filter_sector = $_GET['sector'] ?? '';
$filter_status = $_GET['status'] ?? '';
$filter_search = $_GET['search'] ?? '';
$filter_date   = $_GET['date']   ?? '';
$filter_start  = $_GET['start']  ?? '';
$filter_end    = $_GET['end']    ?? '';

// Datas só no formato AAAA-MM-DD; "date" (dia único do painel) vira início e fim
$validDate = fn($d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$d) ? $d : '';
$filter_date  = $validDate($filter_date);
$filter_start = $validDate($filter_start) ?: $filter_date;
$filter_end   = $validDate($filter_end) ?: $filter_date;
$filter_status = in_array($filter_status, ['P', 'Y', 'N', 'W', 'C', 'F'], true) ? $filter_status : '';

$map = [
    'mkt' => 'Marketing', 
    'shop' => 'Compras', 
    'xerox' => 'Reprografia', 
    'service' => 'Manutenção', 
    'ti' => 'TI'
];

$isAdmSub          = ($role === 'adm_sub');
$managingId        = (isGlobalAdminRole($role) || $isAdmSub) ? null : $_SESSION['id'];
$subdivisionFilter = $isAdmSub ? ($_SESSION['subdivision_ids'] ?? null) : null;

$reqData = RequestManager::getRequests(
    $pdo, null, $managingId,
    $filter_status ?: null,
    $filter_sector ?: null,
    $filter_search ?: null,
    // Setor só vê aprovados em diante (mesma regra do pages/home.php e de userCanViewRequest)
    'DESC', isGestorRole($role) ? ['P', 'N'] : [],
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
    
    fputcsv($output, array_map('csvSafe', $line), ';');
}

fclose($output);
exit;
