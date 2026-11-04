<?php
/**
 * Copie para config/conn.local.php e preencha com suas credenciais locais.
 * Este arquivo de exemplo pode ser versionado sem segredo.
 */
return [
    'host' => '127.0.0.1',
    'dbname' => 'requests2',
    'user' => 'root',
    'pass' => '',
    // true reaproveita a conexão entre requisições (mais rápido com banco remoto).
    // Use só se o banco aceitar uma conexão aberta por processo do PHP.
    'persistent' => false,
    // Marca da instituição no login e na impressão (vazio = marca neutra do SisReq).
    // O logo precisa estar dentro de assets/.
    'org_name' => '',
    'org_logo' => '',
];
