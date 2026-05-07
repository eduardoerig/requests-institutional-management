<?php
session_start();
include 'conn.php';

if (!isset($_SESSION['id'])) {
    header('home');
    exit();
}

if ($_POST['action'] == 'get') {
    $id = $_POST['id'];

    if ($id) {
        $qr = $pdo->prepare('SELECT form_name FROM ctd_requests_models WHERE id = ? AND `status` = ?');
        $qr->execute([$id, 'Y']);
        $row = $qr->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $form_html = file_get_contents('../components/forms/' . $row['form_name']);
            
            // Adicionar campo de prioridade antes do botão de submit
            $priority_html = '
                <div class="form-group">
                    <label>Prioridade</label>
                    <select name="priority">
                        <option value="1">Baixa</option>
                        <option value="2" selected>Média</option>
                        <option value="3">Alta</option>
                        <option value="4">Crítica</option>
                    </select>
                    <span>Defina o nível de urgência desta solicitação.</span>
                </div>
            ';
            
            // Inserir antes do botão de submit para ser mais intuitivo
            if (strpos($form_html, '<button') !== false) {
                $form_html = str_replace('<button', $priority_html . '<button', $form_html);
            } else {
                $form_html = str_replace('</form>', $priority_html . '</form>', $form_html);
            }

            echo json_encode(['success' => true, 'message' => 'ok!', 'form' => $form_html]);
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
