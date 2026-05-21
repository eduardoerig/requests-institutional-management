<?php
session_start();
include 'conn.php';
require_once __DIR__ . '/security.php';

if (!isset($_SESSION['id'])) {
    header('home');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfTokenFromRequest();
}

$subdivision_id = $_SESSION['subdivision_id'] ?? null;
$role = $_SESSION['role'] ?? 'solicitante';
if (in_array($role, ['solicitante', 'adm_sub'], true) && empty($subdivision_id)) {
    echo json_encode(['success' => false, 'message' => 'Seu usuário precisa estar vinculado a uma subdivisão antes de abrir requisições.']);
    exit();
}

if (isset($_POST['type']) && $_POST['type'] === 'service') {
    $title = $_POST['title'];
    $end = $_POST['end'];
    $details = $_POST['details'];
    $urgent = $_POST['urgent'] ?? '';
    $obs = $_POST['obs'];
    $priority = $_POST['priority'] ?? 2;

    if ($title && $end && $details) {
        $stmt = $pdo->prepare('INSERT INTO ctd_service_frm (created_by, created_at, title, `date`, descp, urgent, obs, priority, subdivision_id) VALUES (?, NOW(), ?, ?, ?, ?, ?, ?, ?)');
        if ($stmt->execute([$_SESSION['id'], $title, $end, $details, $urgent, $obs, $priority, $subdivision_id])) {
            echo json_encode(['success' => true, 'message' => 'Requisição de serviço enviada com sucesso!']);
            exit();
        } else {
            echo json_encode(['success' => false, 'message' => 'Requisição nao encontrada ou invalida!']);
            exit();
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Informações invalida!']);
        exit();
    }
} else if (isset($_POST['type']) && $_POST['type'] === 'shopping') {
    $title = $_POST['title'];
    $end = $_POST['end'];
    $details = $_POST['details'];
    $obs = $_POST['obs'];
    $priority = $_POST['priority'] ?? 2;

    if ($title && $end && $details) {
        $stmt = $pdo->prepare('INSERT INTO ctd_shop_frm (created_by, created_at, title, `date`, descp, obs, priority, subdivision_id) VALUES (?, NOW(), ?, ?, ?, ?, ?, ?)');
        if ($stmt->execute([$_SESSION['id'], $title, $end, $details, $obs, $priority, $subdivision_id])) {
            echo json_encode(['success' => true, 'message' => 'Requisição de compra enviada com sucesso!']);
            exit();
        } else {
            echo json_encode(['success' => false, 'message' => 'Requisição nao encontrada ou invalida!']);
            exit();
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Informações invalida!']);
        exit();
    }
} else if (isset($_POST['type']) && $_POST['type'] === 'colorPrint') {
    $title = $_POST['title'];
    $end = $_POST['end'];
    $file = $_POST['file'];
    $qtd = $_POST['qtd'];
    $type = $_POST['model'];
    $obs = $_POST['obs'];
    $priority = $_POST['priority'] ?? 2;

    if ($title && $end) {
        $stmt = $pdo->prepare('INSERT INTO ctd_xerox_frm (created_by, created_at, title, `date`, arquive, qtd, `type`, obs, priority, subdivision_id) VALUES (?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?)');
        if ($stmt->execute([$_SESSION['id'], $title, $end, $file, $qtd, $type, $obs, $priority, $subdivision_id])) {
            echo json_encode(['success' => true, 'message' => 'Requisição de xerox enviada com sucesso!']);
            exit();
        } else {
            echo json_encode(['success' => false, 'message' => 'Requisição nao encontrada ou invalida!']);
            exit();
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Informações invalida!']);
        exit();
    }
} else if (isset($_POST['type']) && $_POST['type'] === 'mkt') {
    $title = $_POST['title'];
    $end = $_POST['end'];
    $descp = $_POST['descp'];
    $type = $_POST['model'] ? $_POST['model'] : $_POST['outros_texto'];
    $obs = $_POST['obs'];
    $priority = $_POST['priority'] ?? 2;

    if ($title && $end) {
        $stmt = $pdo->prepare('INSERT INTO ctd_mkt_frm (created_by, created_at, title, `date`, descp, `type`, obs, priority, subdivision_id) VALUES (?, NOW(), ?, ?, ?, ?, ?, ?, ?)');
        if ($stmt->execute([$_SESSION['id'], $title, $end, $descp, $type, $obs, $priority, $subdivision_id])) {
            echo json_encode(['success' => true, 'message' => 'Requisição de MKT enviada com sucesso!']);
            exit();
        } else {
            echo json_encode(['success' => false, 'message' => 'Requisição nao encontrada ou invalida!']);
            exit();
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Informações invalida!']);
        exit();
    }
} else if (isset($_POST['type']) && $_POST['type'] === 'ti') {
    $title = $_POST['title'];
    $end = $_POST['end'];
    $details = $_POST['details'];
    $urgent = $_POST['urgent'] ?? '';
    $obs = $_POST['obs'];
    $priority = $_POST['priority'] ?? 2;

    if ($title && $end && $details) {
        $stmt = $pdo->prepare('INSERT INTO ctd_ti_frm (created_by, created_at, title, `date`, descp, urgent, obs, priority, subdivision_id) VALUES (?, NOW(), ?, ?, ?, ?, ?, ?, ?)');
        if ($stmt->execute([$_SESSION['id'], $title, $end, $details, $urgent, $obs, $priority, $subdivision_id])) {
            echo json_encode(['success' => true, 'message' => 'Requisição de TI enviada com sucesso!']);
            exit();
        } else {
            echo json_encode(['success' => false, 'message' => 'Requisição nao encontrada ou invalida!']);
            exit();
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Informações invalida!']);
        exit();
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Requisição invalida!']);
    exit();
}

