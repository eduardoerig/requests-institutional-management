<?php
require_once __DIR__ . '/../config/session.php';

session_unset();
session_destroy();

header('Content-Type: application/json; charset=UTF-8');
echo json_encode(['success' => true, 'message' => 'Logout efetuado com sucesso!']);
exit;
