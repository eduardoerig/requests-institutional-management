<?php
session_start();
include 'conn.php';

$login = $_POST['login'];
$password = $_POST['password'];

if ($_POST['action'] === "get") {
    if ($password && $login) {
        $stmt = $pdo->prepare('SELECT * FROM ctd_users WHERE `login` = ?');
        $stmt->execute([$login]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            if ((int)($user['status'] ?? 1) !== 1) {
                echo json_encode(['success' => false, 'message' => "Usuario inativo. Contate o administrador."]);
                exit;
            }

            if (password_verify($password, $user['password'])) {
                $_SESSION['id'] = $user['id'];
                $_SESSION['name'] = $user['name'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['force_reset'] = (int)($user['force_reset'] ?? 0);

                // Carregar subdivisões do usuário (Múltiplas)
                $_SESSION['subdivision_ids'] = [];
                $_SESSION['subdivision_names'] = [];
                
                $subStmt = $pdo->prepare("
                    SELECT s.id, s.name 
                    FROM ctd_subdivision s
                    JOIN cfg_user_subdivision cus ON s.id = cus.id_subdivision
                    WHERE cus.id_user = ?
                ");
                $subStmt->execute([$user['id']]);
                $subs = $subStmt->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($subs as $s) {
                    $_SESSION['subdivision_ids'][] = $s['id'];
                    $_SESSION['subdivision_names'][] = $s['name'];
                }

                // Manter campos antigos para compatibilidade
                $_SESSION['subdivision_id'] = $_SESSION['subdivision_ids'][0] ?? null;
                $_SESSION['subdivision_name'] = $_SESSION['subdivision_names'][0] ?? null;

                echo json_encode(['success' => true, 'message' => "Login realizado com sucesso!"]);
                exit;
            } else {
                echo json_encode(['success' => false, 'message' => "Senha invalida"]);
                exit;
            }
        } else {
            echo json_encode(['success' => false, 'message' => "Usuario nao enconrado"]);
            exit;
        }
    } else {
        echo json_encode(['success' => false, 'message' => "Credenciais incompletas"]);
        exit;
    }
}
