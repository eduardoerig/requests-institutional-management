<?php
session_start();
include 'conn.php';
require_once __DIR__ . '/security.php';

if (!isset($_SESSION['id'])) {
    header('home');
    exit();
}

if ($_POST['action'] == 'get') {
    $id = $_POST['id'];
    $table = $_POST['table'];
    $normalizedTable = normalizeRequestTable((string)$table);

    if ($id && $normalizedTable) {
        $qr = $pdo->prepare('SELECT * FROM ctd_' . $normalizedTable . '_frm WHERE id = ?');
        $qr->execute([$id]);
        $row = $qr->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $role = $_SESSION['role'] ?? 'solicitante';
            if (!userCanViewRequest($pdo, $row, $normalizedTable, (int)$_SESSION['id'], $role)) {
                echo json_encode(['success' => false, 'message' => 'Acesso negado.']);
                exit();
            }

            $map = [
                'mkt' => 'gray',
                'xerox' => 'blue',
                'shop' => 'green',
                'service' => 'yellow',
                'ti' => 'red',
            ];

            $labels = [
                'descp' => 'Descrição',
                'type' => 'Categoria/Modelo',
                'arquive' => 'Arquivo',
                'qtd' => 'Quantidade',
                'priority' => 'Prioridade',
                'subdivision_id' => 'Subdivisão'
            ];
            
            $priorityLabels = [
                1 => 'Baixa',
                2 => 'Média',
                3 => 'Alta',
                4 => 'Crítica'
            ];

            // Buscar nome da subdivisão considerando o contexto do usuário logado
            $subName = '';
            $sessionRole = $_SESSION['role'] ?? '';
            $sessionUserId = (int)($_SESSION['id'] ?? 0);

            if ($sessionRole === 'adm_sub' && $sessionUserId) {
                // Para adm_sub: mostrar as subdivisões do SOLICITANTE que o adm_sub gerencia
                $stSub = $pdo->prepare("
                    SELECT GROUP_CONCAT(s.name ORDER BY s.id SEPARATOR ', ')
                    FROM cfg_user_subdivision cus_sol
                    JOIN ctd_subdivision s ON cus_sol.id_subdivision = s.id
                    WHERE cus_sol.id_user = ?
                      AND cus_sol.id_subdivision IN (
                          SELECT id_subdivision FROM cfg_user_subdivision WHERE id_user = ?
                      )
                ");
                $stSub->execute([$row['created_by'], $sessionUserId]);
                $subName = (string)$stSub->fetchColumn();

                // Fallback: se não achou match, usa a subdivisão da requisição
                if (!$subName && !empty($row['subdivision_id'])) {
                    $stSub2 = $pdo->prepare("SELECT name FROM ctd_subdivision WHERE id = ?");
                    $stSub2->execute([$row['subdivision_id']]);
                    $subName = (string)$stSub2->fetchColumn();
                }
            } elseif (!empty($row['subdivision_id'])) {
                $stSub = $pdo->prepare("SELECT name FROM ctd_subdivision WHERE id = ?");
                $stSub->execute([$row['subdivision_id']]);
                $subName = (string)$stSub->fetchColumn();
            }

            // Buscar quem aprovou/recusou no histórico
            $approverName = null;
            $histStmt = $pdo->prepare("SELECT user_name FROM request_history WHERE request_id = ? AND request_table = ? AND action LIKE 'Requisição %' AND (new_value = 'Aprovada' OR new_value = 'Rejeitada') ORDER BY id DESC LIMIT 1");
            $histStmt->execute([$id, $normalizedTable]);
            $approverName = $histStmt->fetchColumn();
            
            $exclude = ['id', 'created_by', 'status', 'created_at', 'date', 'title', 'table', 'obs', 'urgent'];
            
            $details_html = '';
            foreach ($row as $key => $value) {
                if (!in_array($key, $exclude) && $value !== null && $value !== '') {
                    $labelName = isset($labels[$key]) ? $labels[$key] : ucfirst($key);
                    $displayValue = htmlspecialchars($value);

                    if ($key === 'priority' && isset($priorityLabels[$value])) {
                        $displayValue = $priorityLabels[$value];
                    } elseif ($key === 'subdivision_id' && $subName) {
                        $displayValue = htmlspecialchars($subName);
                    }

                    $details_html .= "<b>{$labelName}:</b> " . nl2br($displayValue) . "<br><br>";
                }
            }

            if ($approverName) {
                $statusLabel = ($row['status'] === 'N') ? 'Recusada por' : 'Aprovada por';
                $details_html .= "<b>{$statusLabel}:</b> " . htmlspecialchars($approverName) . "<br><br>";
            }

            $descp = $details_html;

            $stmt = $pdo->prepare("SELECT * FROM ctd_users WHERE id = ?");
            $stmt->execute([(int)$row['created_by']]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            $return = ['id' => $row['id'], 'user' => $user, 'title' => $row['title'], 'descp' => $descp, 'date' => $row['date'], 'created_at' => $row['created_at'], 'obs' => $row['obs'], 'urgent' => $row['urgent'] ?? '', 'color' => $map[$normalizedTable], 'table' => $normalizedTable];

            echo json_encode(['success' => true, 'message' => 'ok!', 'data' => $return]);
            exit();
        } else {
            echo json_encode(['success' => false, 'message' => 'Requisição nao encontrada ou invalida!']);
            exit();
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Indentificação invalida!']);
        exit();
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Requisição invalida!']);
    exit();
}
