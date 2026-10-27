<?php
require_once __DIR__ . '/../config/security.php';

// Só POST com o token da sessão: um link de outro site não consegue deslogar ninguém
requirePostJson();

session_unset();
session_destroy();

jsonResponse(['success' => true, 'message' => 'Logout efetuado com sucesso!']);
