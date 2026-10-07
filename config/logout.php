<?php
require_once __DIR__ . '/session.php';

session_unset();
session_destroy();

echo json_encode(['success' => true, 'message' => 'Logout efetuado com sucesso!']);
exit;
