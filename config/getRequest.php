<?php
session_start();
include 'conn.php';

if (!isset($_SESSION['id'])) {
    header('home');
    exit();
}

if ($_POST['action'] == 'get') {
    $id = $_POST['id'];
    $table = $_POST['table'];

    if ($id && $table) {
        $qr = $pdo->prepare('SELECT * FROM ctd_' . $table . '_frm WHERE id = ?');
        $qr->execute([$id]);
        $row = $qr->fetch(PDO::FETCH_ASSOC);
        if ($row) {
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

            // Buscar nome da subdivisão se existir
            $subName = '';
            if (!empty($row['subdivision_id'])) {
                $stSub = $pdo->prepare("SELECT name FROM ctd_subdivision WHERE id = ?");
                $stSub->execute([$row['subdivision_id']]);
                $subName = $stSub->fetchColumn();
            }

            // Buscar quem aprovou/recusou no histórico
            $approverName = null;
            $histStmt = $pdo->prepare("SELECT user_name FROM request_history WHERE request_id = ? AND request_table = ? AND action LIKE 'Requisição %' AND (new_value = 'Aprovada' OR new_value = 'Rejeitada') ORDER BY id DESC LIMIT 1");
            $histStmt->execute([$id, $table]);
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
                        $displayValue = $subName;
                    }

                    $details_html .= "<b>{$labelName}:</b> " . nl2br($displayValue) . "<br><br>";
                }
            }

            if ($approverName) {
                $statusLabel = ($row['status'] === 'N') ? 'Recusada por' : 'Aprovada por';
                $details_html .= "<b>{$statusLabel}:</b> " . htmlspecialchars($approverName) . "<br><br>";
            }

            $descp = $details_html;

            $stmt = $pdo->query("SELECT * FROM ctd_users WHERE id=" . $row['created_by'] . "");
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            $return = ['id' => $row['id'], 'user' => $user, 'title' => $row['title'], 'descp' => $descp, 'date' => $row['date'], 'created_at' => $row['created_at'], 'obs' => $row['obs'], 'urgent' => $row['urgent'] ?? '', 'color' => $map[$table], 'table' => $table];

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
