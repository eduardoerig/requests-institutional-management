<?php
/**
 * Token CSRF da sessão atual (GET). A janela "Entrar de novo" do script.js chama antes de postar em
 * api/login.php: com a sessão expirada, o token que a página tem não vale mais.
 * Não exige login (o token sozinho não dá acesso a nada; ele só prova que o POST vem desta origem).
 */
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/security.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['success' => false, 'message' => 'Método não permitido.'], 405);
}
header('Cache-Control: no-store');
jsonResponse(['success' => true, 'csrf_token' => ensureCsrfToken()]);
