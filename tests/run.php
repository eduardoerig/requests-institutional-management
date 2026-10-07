<?php
/**
 * Testes de integração (HTTP real + banco), sem dependências.
 *
 *   php tests/run.php
 *
 * Requisitos: servidor rodando (TEST_BASE_URL, padrão http://localhost/requests2.0/),
 * contas de teste criadas por database/seed_test_users.php (senha em
 * database/test_accounts.local.txt ou TEST_PASSWORD). Use SOMENTE em banco de homologação.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/config/conn.php';

$baseUrl = rtrim(getenv('TEST_BASE_URL') ?: 'http://localhost/requests2.0/', '/') . '/';
$password = getenv('TEST_PASSWORD') ?: '';
if ($password === '') {
    $file = dirname(__DIR__) . '/database/test_accounts.local.txt';
    if (is_file($file) && preg_match('/:\s*(\S+)/', (string)file_get_contents($file), $m)) {
        $password = $m[1];
    }
}
if ($password === '') {
    fwrite(STDERR, "Senha das contas de teste não encontrada. Rode: php database/seed_test_users.php\n");
    exit(1);
}

// ------------------------------------------------------------------
// Infra mínima
// ------------------------------------------------------------------
final class Client
{
    private string $jar;
    public string $csrf = '';

    public function __construct(private string $base)
    {
        $this->jar = tempnam(sys_get_temp_dir(), 'sisreq');
    }

    public function request(string $method, string $path, array $data = [], array $headers = []): array
    {
        $ch = curl_init($this->base . ltrim($path, '/'));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEJAR => $this->jar,
            CURLOPT_COOKIEFILE => $this->jar,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => $method,
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        }
        $body = (string)curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $location = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        curl_close($ch);
        return ['status' => $status, 'body' => $body, 'json' => json_decode($body, true), 'headers' => $location];
    }

    public function get(string $path): array
    {
        return $this->request('GET', $path);
    }

    /** POST com o token CSRF da sessão */
    public function post(string $path, array $data = []): array
    {
        if ($this->csrf === '') {
            $this->refreshCsrf();
        }
        return $this->request('POST', $path, $data + ['csrf_token' => $this->csrf]);
    }

    public function refreshCsrf(): void
    {
        $page = $this->get('login');
        if (preg_match('/window\.CSRF_TOKEN = "([a-f0-9]+)"/', $page['body'], $m)) {
            $this->csrf = $m[1];
        }
    }

    public function login(string $login, string $password): array
    {
        $this->refreshCsrf();
        $res = $this->post('config/login.php', ['action' => 'get', 'login' => $login, 'password' => $password]);
        // Após login a sessão ganha novo id e novo token
        $page = $this->get('myAccount');
        if (preg_match('/window\.CSRF_TOKEN = "([a-f0-9]+)"/', $page['body'], $m)) {
            $this->csrf = $m[1];
        }
        return $res;
    }
}

$results = ['pass' => 0, 'fail' => 0];
$failures = [];

function check(string $name, bool $ok, string $detail = ''): void
{
    global $results, $failures;
    if ($ok) {
        $results['pass']++;
        echo "  ok   $name\n";
    } else {
        $results['fail']++;
        $failures[] = $name . ($detail !== '' ? " — $detail" : '');
        echo "  FALHA $name" . ($detail !== '' ? " — $detail" : '') . "\n";
    }
}

function section(string $title): void
{
    echo "\n$title\n";
}

function snippet(array $res): string
{
    return 'HTTP ' . $res['status'] . ' ' . mb_substr(trim(strip_tags($res['body'])), 0, 160);
}

$createdIds = [];
$future = date('Y-m-d', strtotime('+5 days'));

// ------------------------------------------------------------------
section('1. Acesso sem login');
$anon = new Client($baseUrl);
foreach (['api/request_view.php?id=1&table=ti', 'api/request_forwards.php?action=areas', 'api/get_notifications.php', 'api/get_request_details.php?id=1&table=ti', 'api/export_requests.php', 'print_request.php?id=1&table=ti'] as $path) {
    $res = $anon->get($path);
    check("sem login bloqueia $path", $res['status'] === 401, snippet($res));
}
$res = $anon->get('home');
check('página interna sem login mostra o login', str_contains($res['body'], 'frm_login'));

// ------------------------------------------------------------------
section('2. Arquivos internos não são servidos');
foreach (['scratch/', 'database/schema.sql', 'database/migrate.php', 'classes/RequestManager.php', 'pages/home.php', 'README.md', 'config/conn.local.php', 'config/conn.local.example.php', '.git/config', 'tests/run.php'] as $path) {
    $res = $anon->get($path);
    check("bloqueia $path", in_array($res['status'], [403, 404], true), 'HTTP ' . $res['status']);
}

// ------------------------------------------------------------------
section('3. Login');
$c = new Client($baseUrl);
$res = $c->login('teste_solicitante', 'senha-errada-123');
check('senha errada recusada com mensagem genérica', ($res['json']['success'] ?? true) === false && str_contains($res['json']['message'] ?? '', 'incorretos'), snippet($res));
$res = $c->login('usuario_que_nao_existe', 'x');
check('usuário inexistente recebe a MESMA mensagem', ($res['json']['message'] ?? '') === 'Usuário ou senha incorretos.', snippet($res));
$pdo->prepare('DELETE FROM login_attempts WHERE login IN (?, ?)')->execute(['teste_solicitante', 'usuario_que_nao_existe']);

$sol = new Client($baseUrl);
$res = $sol->login('teste_solicitante', $password);
check('solicitante entra', ($res['json']['success'] ?? false) === true, snippet($res));
$apr = new Client($baseUrl);
check('coordenação entra', ($apr->login('teste_aprovador', $password)['json']['success'] ?? false) === true);
$ti = new Client($baseUrl);
check('gestor TI entra', ($ti->login('teste_gestor_ti', $password)['json']['success'] ?? false) === true);
$shop = new Client($baseUrl);
check('gestor Compras entra', ($shop->login('teste_gestor_compras', $password)['json']['success'] ?? false) === true);
$adm = new Client($baseUrl);
check('admin entra', ($adm->login('teste_admin', $password)['json']['success'] ?? false) === true);

// ------------------------------------------------------------------
section('4. Proteções de segurança');
$res = $sol->request('POST', 'api/request_actions.php', ['id' => 1, 'table' => 'ti', 'action' => 'finish']);
check('POST sem token CSRF é recusado', $res['status'] === 403, snippet($res));

$solId = (int)$pdo->query("SELECT id FROM ctd_users WHERE login = 'teste_solicitante'")->fetchColumn();
$sol->post('api/update_user_admin.php?self=1', ['role' => 'admin', 'status' => 1, 'login' => 'hackeado']);
$row = $pdo->query("SELECT role, login FROM ctd_users WHERE id = $solId")->fetch();
check('solicitante NÃO vira admin pelo modo self', $row['role'] === 'solicitante' && $row['login'] === 'teste_solicitante', json_encode($row));

$res = $sol->post('api/update_user_admin.php', ['id' => $solId, 'role' => 'admin']);
check('solicitante não acessa edição de usuários', ($res['json']['success'] ?? true) === false);

$res = $sol->post('api/create_user.php', ['new_name' => 'X', 'new_login' => 'x_hack', 'new_password' => '12345678']);
check('solicitante não cria usuários', ($res['json']['success'] ?? true) === false);

$res = $sol->get('home?status=' . urlencode("';alert(1)//"));
check('parâmetro status não é refletido sem escape', !str_contains($res['body'], "';alert(1)//"));

$res = $sol->post('config/aprovRequest.php', ['action' => 'post', 'id' => 1, 'table' => "ti_frm WHERE 1=1; --", 'value' => 'Y']);
check('solicitante não aprova (e tabela maliciosa não passa)', ($res['json']['success'] ?? true) === false, snippet($res));

// ------------------------------------------------------------------
section('5. Criar requisição');
$base = ['type' => 'ti', 'title' => 'Teste automático', 'end' => $future, 'details' => 'Detalhes', 'priority' => 2];
$res = $sol->post('config/addRequest.php', ['title' => ''] + $base);
check('sem título é recusado', ($res['json']['success'] ?? true) === false && str_contains($res['json']['message'] ?? '', 'título'));
$res = $sol->post('config/addRequest.php', ['end' => '2020-01-01'] + $base);
check('data no passado é recusada', ($res['json']['success'] ?? true) === false && str_contains($res['json']['message'] ?? '', 'passado'));
$res = $sol->post('config/addRequest.php', ['type' => 'colorPrint', 'qtd' => '0', 'model' => 'A4', 'file' => 'a.pdf'] + $base);
check('impressão com 0 cópias é recusada', ($res['json']['success'] ?? true) === false);
$res = $sol->post('config/addRequest.php', ['type' => 'mkt', 'model' => ''] + $base);
check('marketing sem aplicação é recusado', ($res['json']['success'] ?? true) === false);

$res = $sol->post('config/addRequest.php', ['title' => 'Teste automático <script>x</script>'] + $base);
$reqId = (int)($res['json']['id'] ?? 0);
check('requisição válida é criada', ($res['json']['success'] ?? false) === true && $reqId > 0, snippet($res));
$createdIds[] = ['ti', $reqId];
$hist = $pdo->query("SELECT COUNT(*) FROM request_history WHERE request_id = $reqId AND request_table = 'ti' AND action = 'Requisição criada'")->fetchColumn();
check('histórico registra a criação', (int)$hist === 1);
$notif = $pdo->query("SELECT COUNT(*) FROM notifications WHERE link = 'request_detail?id=$reqId&table=ti' AND title = 'Nova requisição para aprovar'")->fetchColumn();
check('aprovadores são avisados', (int)$notif >= 1);

$res = $sol->get("request_detail?id=$reqId&table=ti");
check('título com HTML aparece escapado no detalhe', !str_contains($res['body'], '<script>x</script>') && str_contains($res['body'], '&lt;script&gt;'));

// ------------------------------------------------------------------
section('6. Quem pode ver');
$res = $shop->get("api/get_request_details.php?id=$reqId&table=ti");
check('gestor de outro setor não vê requisição de TI', ($res['json']['success'] ?? true) === false, snippet($res));
$res = $ti->get("api/request_view.php?id=$reqId&table=ti");
check('gestor TI vê a requisição no painel', $res['status'] === 200 && str_contains($res['body'], 'request-view'), 'HTTP ' . $res['status']);
check('painel não contém hash de senha', !str_contains($res['body'], 'password') && !str_contains($res['body'], '$2y$'));
$res = $shop->get("api/request_view.php?id=$reqId&table=ti");
check('painel bloqueado para outro setor', $res['status'] === 403, 'HTTP ' . $res['status']);
$res = $shop->get("print_request.php?id=$reqId&table=ti");
check('impressão bloqueada para quem não pode ver', $res['status'] === 403, 'HTTP ' . $res['status']);

// ------------------------------------------------------------------
section('7. Aprovação');
$res = $ti->post('config/aprovRequest.php', ['action' => 'post', 'id' => $reqId, 'table' => 'ti', 'value' => 'Y']);
check('gestor não aprova', ($res['json']['success'] ?? true) === false);
$res = $apr->post('config/aprovRequest.php', ['action' => 'post', 'id' => $reqId, 'table' => 'ti', 'value' => 'N', 'reason' => '']);
check('recusar sem motivo é bloqueado', ($res['json']['success'] ?? true) === false && str_contains($res['json']['message'] ?? '', 'motivo'));
$res = $ti->post('api/request_actions.php', ['id' => $reqId, 'table' => 'ctd_ti_frm', 'action' => 'start_progress']);
check('não inicia atendimento antes da aprovação', ($res['json']['success'] ?? true) === false);
$res = $apr->post('config/aprovRequest.php', ['action' => 'post', 'id' => $reqId, 'table' => 'ti', 'value' => 'Y']);
check('coordenação aprova', ($res['json']['success'] ?? false) === true, snippet($res));
$res = $adm->post('config/aprovRequest.php', ['action' => 'post', 'id' => $reqId, 'table' => 'ti', 'value' => 'N', 'reason' => 'dup']);
check('segunda decisão sobre o mesmo pedido é bloqueada', ($res['json']['success'] ?? true) === false);
$res = $sol->post('api/request_actions.php', ['id' => $reqId, 'table' => 'ctd_ti_frm', 'action' => 'delete']);
check('solicitante não exclui pedido já aprovado', ($res['json']['success'] ?? true) === false);

// ------------------------------------------------------------------
section('8. Atendimento');
$res = $shop->post('api/request_actions.php', ['id' => $reqId, 'table' => 'ctd_ti_frm', 'action' => 'start_progress']);
check('gestor de Compras não atende pedido de TI', ($res['json']['success'] ?? true) === false);
$res = $ti->post('api/request_actions.php', ['id' => $reqId, 'table' => 'ctd_ti_frm', 'action' => 'start_progress']);
check('gestor TI começa atendimento', ($res['json']['success'] ?? false) === true, snippet($res));
$res = $ti->post('api/request_actions.php', ['id' => $reqId, 'table' => 'ctd_ti_frm', 'action' => 'start_progress']);
check('começar duas vezes é bloqueado', ($res['json']['success'] ?? true) === false);
$res = $ti->post('api/request_actions.php', ['id' => $reqId, 'table' => 'ctd_ti_frm', 'action' => 'finish']);
check('gestor TI conclui', ($res['json']['success'] ?? false) === true, snippet($res));
$status = $pdo->query("SELECT status FROM ctd_ti_frm WHERE id = $reqId")->fetchColumn();
check('banco registra Concluída', $status === 'C', (string)$status);
$steps = $pdo->query("SELECT new_value FROM request_history WHERE request_id = $reqId AND request_table = 'ti' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
check('histórico completo (Pendente→Aprovada→Em atendimento→Concluída)', $steps === ['Pendente', 'Aprovada', 'Em atendimento', 'Concluída'], json_encode($steps, JSON_UNESCAPED_UNICODE));

// ------------------------------------------------------------------
section('9. Conversa');
$res = $sol->post('api/add_comment.php', ['id' => $reqId, 'table' => 'ti', 'comment' => '<img src=x onerror=alert(1)>']);
check('solicitante envia mensagem', ($res['json']['success'] ?? false) === true, snippet($res));
$res = $sol->get("api/get_comments.php?id=$reqId&table=ti");
check('mensagem aparece escapada', !str_contains($res['body'], '<img src=x') && str_contains($res['body'], '&lt;img'));
$res = $shop->post('api/add_comment.php', ['id' => $reqId, 'table' => 'ti', 'comment' => 'intruso']);
check('quem não vê o pedido não comenta', ($res['json']['success'] ?? true) === false);

// ------------------------------------------------------------------
section('10. Cancelar pedido pendente');
$res = $sol->post('config/addRequest.php', ['title' => 'Para cancelar'] + $base);
$cancelId = (int)($res['json']['id'] ?? 0);
$createdIds[] = ['ti', $cancelId];
$res = $sol->post('api/request_actions.php', ['id' => $cancelId, 'table' => 'ctd_ti_frm', 'action' => 'delete']);
check('solicitante cancela o próprio pedido pendente', ($res['json']['success'] ?? false) === true, snippet($res));
$left = $pdo->query("SELECT COUNT(*) FROM request_history WHERE request_id = $cancelId AND request_table = 'ti'")->fetchColumn();
check('cancelamento não deixa histórico órfão', (int)$left === 0);

// ------------------------------------------------------------------
section('11. Encaminhar: transferir');
$res = $sol->post('config/addRequest.php', ['title' => 'Teste repasse'] + $base);
$fwdReqId = (int)($res['json']['id'] ?? 0);
$createdIds[] = ['ti', $fwdReqId];
$apr->post('config/aprovRequest.php', ['action' => 'post', 'id' => $fwdReqId, 'table' => 'ti', 'value' => 'Y']);
$ti->post('api/request_actions.php', ['id' => $fwdReqId, 'table' => 'ctd_ti_frm', 'action' => 'start_progress']);
$shopAreaId = (int)$pdo->query("SELECT id FROM ctd_area WHERE LOWER(title) IN ('shop', 'compras') LIMIT 1")->fetchColumn();
$lastFwd = fn() => (int)$pdo->query("SELECT id FROM request_forwards WHERE request_id = $fwdReqId AND request_table = 'ti' ORDER BY id DESC LIMIT 1")->fetchColumn();
$reqStatus = fn() => (string)$pdo->query("SELECT status FROM ctd_ti_frm WHERE id = $fwdReqId")->fetchColumn();
$fwd = fn(array $extra) => $ti->post('api/request_forwards.php', $extra + ['action' => 'forward', 'id' => $fwdReqId, 'table' => 'ti', 'to_area_id' => $shopAreaId, 'mode' => 'transfer', 'message' => 'Isso é com Compras, precisa comprar a peça.']);

$res = $fwd(['message' => 'curto']);
check('encaminhar sem explicar é bloqueado', ($res['json']['success'] ?? true) === false);
$res = $fwd(['mode' => 'qualquer']);
check('modo inválido é bloqueado', ($res['json']['success'] ?? true) === false);
$res = $shop->post('api/request_forwards.php', ['action' => 'forward', 'id' => $fwdReqId, 'table' => 'ti', 'to_area_id' => $shopAreaId, 'mode' => 'transfer', 'message' => 'Compras tentando encaminhar algo que não é dela']);
check('setor que não é responsável não encaminha', ($res['json']['success'] ?? true) === false);

$res = $fwd([]);
check('TI transfere para Compras', ($res['json']['success'] ?? false) === true, snippet($res));
$fwdId = $lastFwd();
check('pedido fica Encaminhada enquanto aguarda aceite', $reqStatus() === 'F', $reqStatus());
$notif = $pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id = $solId AND link = 'request_detail?id=$fwdReqId&table=ti'")->fetchColumn();
check('solicitante é avisado da transferência', (int)$notif >= 1);
$res = $fwd(['message' => 'Segundo envio com o primeiro ainda aberto']);
check('encaminhar de novo com um aberto é bloqueado', ($res['json']['success'] ?? true) === false);
$res = $sol->post('api/request_forwards.php', ['action' => 'accept', 'forward_id' => $fwdId]);
check('solicitante não aceita encaminhamento', ($res['json']['success'] ?? true) === false);
$res = $shop->post('api/request_forwards.php', ['action' => 'refuse', 'forward_id' => $fwdId, 'reason' => '']);
check('recusar sem motivo é bloqueado', ($res['json']['success'] ?? true) === false);
$res = $shop->post('api/request_forwards.php', ['action' => 'refuse', 'forward_id' => $fwdId, 'reason' => 'Não é com Compras']);
check('Compras recusa com motivo', ($res['json']['success'] ?? false) === true, snippet($res));
check('recusa devolve para Em atendimento', $reqStatus() === 'W', $reqStatus());
$note = $pdo->query("SELECT response_note FROM request_forwards WHERE id = $fwdId")->fetchColumn();
check('motivo da recusa fica registrado', $note === 'Não é com Compras', (string)$note);
$res = $shop->post('api/request_forwards.php', ['action' => 'refuse', 'forward_id' => $fwdId, 'reason' => 'De novo não']);
check('recusar duas vezes é bloqueado', ($res['json']['success'] ?? true) === false);

$fwd(['message' => 'Mandando de novo para cancelar']);
$fwdId = $lastFwd();
$res = $shop->post('api/request_forwards.php', ['action' => 'cancel', 'forward_id' => $fwdId]);
check('destino não cancela o envio', ($res['json']['success'] ?? true) === false);
$res = $ti->post('api/request_forwards.php', ['action' => 'cancel', 'forward_id' => $fwdId]);
check('TI cancela envio pendente', ($res['json']['success'] ?? false) === true, snippet($res));
check('cancelar devolve para Em atendimento', $reqStatus() === 'W', $reqStatus());

$fwd(['message' => 'Agora sim, comprar a peça para a sala 12']);
$fwdId = $lastFwd();
$res = $shop->get("api/request_view.php?id=$fwdReqId&table=ti");
check('Compras vê o pedido que chegou', $res['status'] === 200 && str_contains($res['body'], 'Agora sim'), 'HTTP ' . $res['status']);
$res = $shop->post('api/request_forwards.php', ['action' => 'accept', 'forward_id' => $fwdId]);
check('Compras aceita a transferência', ($res['json']['success'] ?? false) === true, snippet($res));
check('aceite volta o pedido para Em atendimento', $reqStatus() === 'W', $reqStatus());
$res = $ti->post('api/request_actions.php', ['id' => $fwdReqId, 'table' => 'ctd_ti_frm', 'action' => 'finish']);
check('TI não conclui depois de transferir', ($res['json']['success'] ?? true) === false);
$res = $sol->get("request_detail?id=$fwdReqId&table=ti");
check('solicitante não vê a mensagem interna', $res['status'] === 200 && !str_contains($res['body'], 'Agora sim, comprar'));
$res = $shop->post('api/request_actions.php', ['id' => $fwdReqId, 'table' => 'ctd_ti_frm', 'action' => 'finish']);
check('Compras conclui', ($res['json']['success'] ?? false) === true, snippet($res));
check('pedido transferido termina Concluída', $reqStatus() === 'C', $reqStatus());

// ------------------------------------------------------------------
section('11b. Encaminhar: pedir ajuda');
$res = $sol->post('config/addRequest.php', ['title' => 'Teste ajuda'] + $base);
$helpReqId = (int)($res['json']['id'] ?? 0);
$createdIds[] = ['ti', $helpReqId];
$apr->post('config/aprovRequest.php', ['action' => 'post', 'id' => $helpReqId, 'table' => 'ti', 'value' => 'Y']);
$ti->post('api/request_actions.php', ['id' => $helpReqId, 'table' => 'ctd_ti_frm', 'action' => 'start_progress']);
$res = $ti->post('api/request_forwards.php', ['action' => 'forward', 'id' => $helpReqId, 'table' => 'ti', 'to_area_id' => $shopAreaId, 'mode' => 'help', 'message' => 'Precisamos de 2 cabos HDMI para terminar.']);
check('TI pede ajuda a Compras', ($res['json']['success'] ?? false) === true, snippet($res));
$helpFwd = (int)$pdo->query("SELECT id FROM request_forwards WHERE request_id = $helpReqId AND request_table = 'ti' ORDER BY id DESC LIMIT 1")->fetchColumn();
$helpStatus = fn() => (string)$pdo->query("SELECT status FROM ctd_ti_frm WHERE id = $helpReqId")->fetchColumn();
check('pedir ajuda não muda a situação', $helpStatus() === 'W', $helpStatus());
$res = $ti->post('api/request_actions.php', ['id' => $helpReqId, 'table' => 'ctd_ti_frm', 'action' => 'finish']);
check('não conclui com ajuda em aberto', ($res['json']['success'] ?? true) === false);
$res = $shop->post('api/request_forwards.php', ['action' => 'accept', 'forward_id' => $helpFwd]);
check('Compras aceita ajudar', ($res['json']['success'] ?? false) === true, snippet($res));
$res = $shop->post('api/request_actions.php', ['id' => $helpReqId, 'table' => 'ctd_ti_frm', 'action' => 'finish']);
check('quem ajuda não conclui o pedido', ($res['json']['success'] ?? true) === false);
$res = $shop->post('api/request_forwards.php', ['action' => 'return', 'forward_id' => $helpFwd, 'note' => '']);
check('devolver sem retorno é bloqueado', ($res['json']['success'] ?? true) === false);
$res = $shop->post('api/request_forwards.php', ['action' => 'return', 'forward_id' => $helpFwd, 'note' => 'Cabos comprados, estão na secretaria.']);
check('Compras devolve com retorno', ($res['json']['success'] ?? false) === true, snippet($res));
$res = $ti->post('api/request_actions.php', ['id' => $helpReqId, 'table' => 'ctd_ti_frm', 'action' => 'finish']);
check('TI conclui depois do retorno', ($res['json']['success'] ?? false) === true, snippet($res));

// ------------------------------------------------------------------
section('11c. Caixa do setor');
require_once dirname(__DIR__) . '/classes/RequestInbox.php';
$tiAreaId = (int)$pdo->query("SELECT id FROM ctd_area WHERE LOWER(title) = 'ti' LIMIT 1")->fetchColumn();
$inbox = RequestInbox::build($pdo, [$tiAreaId]);
$mismatch = [];
foreach ($inbox['tabs'] as $tab => $rows) {
    if (count($rows) !== $inbox['counts'][$tab]) $mismatch[] = $tab;
}
check('contadores das abas batem com as listas', !$mismatch, implode(', ', $mismatch));
$res = $ti->get('inbox?tab=enviados');
check('aba Enviados mostra a transferência da TI', $res['status'] === 200 && str_contains($res['body'], 'Teste repasse'), 'HTTP ' . $res['status']);
$res = $ti->get('approved');
check('rota antiga leva para a Caixa', $res['status'] === 302 && str_contains($res['headers'] ?? '', 'inbox'), 'HTTP ' . $res['status']);

// ------------------------------------------------------------------
section('12. Todas as páginas abrem para cada perfil');
$pages = ['home', 'new_request', 'myRequests', 'approve', 'inbox', 'inbox?tab=concluidos', 'myAccount', 'accounts', 'ajuda', "request_detail?id=$reqId&table=ti", 'home?status=C', 'home?q=teste'];
foreach (['solicitante' => $sol, 'coordenação' => $apr, 'gestor TI' => $ti, 'admin' => $adm] as $who => $client) {
    $broken = [];
    foreach ($pages as $page) {
        $res = $client->get($page);
        $complete = str_contains($res['body'], '</html>');
        $phpError = preg_match('/(Fatal error|Warning|Notice|Deprecated|Uncaught)/', $res['body']);
        if (!in_array($res['status'], [200, 302], true) || ($res['status'] === 200 && (!$complete || $phpError))) {
            $broken[] = "$page (HTTP {$res['status']})";
        }
    }
    check("$who: páginas carregam completas e sem erro de PHP", !$broken, implode(', ', $broken));
}

// ------------------------------------------------------------------
section('13. Limpeza');
// O banco remoto fecha conexões ociosas: reconecta antes de limpar
require dirname(__DIR__) . '/config/conn.php';
// Inclui sobras de rodadas anteriores interrompidas
$leftovers = $pdo->query("SELECT 'ti' AS t, id FROM ctd_ti_frm WHERE created_by = $solId AND title IN ('Teste automático <script>x</script>', 'Para cancelar', 'Teste repasse', 'Teste ajuda')")->fetchAll(PDO::FETCH_NUM);
foreach ($leftovers as $row) {
    $createdIds[] = [$row[0], (int)$row[1]];
}
foreach ($createdIds as [$t, $id]) {
    if ($id <= 0) {
        continue;
    }
    $pdo->prepare("DELETE FROM ctd_{$t}_frm WHERE id = ?")->execute([$id]);
    foreach (['request_comments', 'request_history', 'request_forwards'] as $tbl) {
        $pdo->prepare("DELETE FROM $tbl WHERE request_id = ? AND request_table = ?")->execute([$id, $t]);
    }
    $pdo->prepare('DELETE FROM notifications WHERE link = ?')->execute(["request_detail?id=$id&table=$t"]);
}
echo "  dados de teste removidos\n";

echo "\nResultado: {$results['pass']} ok, {$results['fail']} falha(s)\n";
if ($failures) {
    echo "Falhas:\n - " . implode("\n - ", $failures) . "\n";
}
exit($results['fail'] > 0 ? 1 : 0);
