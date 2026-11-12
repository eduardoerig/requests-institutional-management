<?php
/**
 * Anexos do pedido (tabela request_attachments, migração 015). Sempre opcionais.
 * - Perfil "pdf": cópia colorida da Reprografia, só PDF.
 * - Perfil "geral": os outros pedidos (PDF, fotos e documentos do Office). Nunca executável nem HTML.
 * O arquivo fica em uploadDir() com nome aleatório; só sai por api/attachment.php, que confere
 * se a pessoa pode ver o pedido. O nome original é guardado só para mostrar.
 */
class RequestAttachments
{
    public const MAX_FILES = 5;
    public const MAX_FILE_BYTES = 20 * 1024 * 1024;
    public const MAX_TOTAL_BYTES = 50 * 1024 * 1024;

    /** extensão => [tipos aceitos pelo finfo, início do arquivo, tipo enviado no download, abre no navegador] */
    private const TYPES = [
        'pdf' => [['application/pdf'], ["%PDF-"], 'application/pdf', true],
        'jpg' => [['image/jpeg'], ["\xFF\xD8\xFF"], 'image/jpeg', true],
        'jpeg' => [['image/jpeg'], ["\xFF\xD8\xFF"], 'image/jpeg', true],
        'png' => [['image/png'], ["\x89PNG"], 'image/png', true],
        'webp' => [['image/webp'], ["RIFF"], 'image/webp', true],
        'doc' => [self::OLE, ["\xD0\xCF\x11\xE0"], 'application/msword', false],
        'xls' => [self::OLE, ["\xD0\xCF\x11\xE0"], 'application/vnd.ms-excel', false],
        'ppt' => [self::OLE, ["\xD0\xCF\x11\xE0"], 'application/vnd.ms-powerpoint', false],
        'docx' => [['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'], ["PK\x03\x04"], 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', false],
        'xlsx' => [['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'], ["PK\x03\x04"], 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', false],
        'pptx' => [['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip'], ["PK\x03\x04"], 'application/vnd.openxmlformats-officedocument.presentationml.presentation', false],
    ];
    private const OLE = ['application/msword', 'application/vnd.ms-excel', 'application/vnd.ms-powerpoint', 'application/vnd.ms-office', 'application/CDFV2', 'application/x-ole-storage'];

    /** Perfis: extensões aceitas e como a tela descreve */
    public static function profiles(): array
    {
        return [
            'pdf' => ['ext' => ['pdf'], 'label' => 'Só PDF'],
            'geral' => ['ext' => array_keys(self::TYPES), 'label' => 'PDF, foto (JPG, PNG), Word, Excel ou PowerPoint'],
        ];
    }

    /** Limite por arquivo: o menor entre o do sistema e o do servidor (upload_max_filesize) */
    public static function maxFileBytes(): int
    {
        $ini = iniBytes((string)ini_get('upload_max_filesize'));
        return $ini > 0 ? min(self::MAX_FILE_BYTES, $ini) : self::MAX_FILE_BYTES;
    }

    /** Limite do envio todo: o menor entre o do sistema e o do servidor (post_max_size, com folga para o resto do formulário) */
    public static function maxTotalBytes(): int
    {
        $ini = iniBytes((string)ini_get('post_max_size'));
        return $ini > 0 ? max(1, min(self::MAX_TOTAL_BYTES, $ini - 256 * 1024)) : self::MAX_TOTAL_BYTES;
    }

    public static function sizeLabel(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            $mb = $bytes / (1024 * 1024);
            return str_replace('.', ',', (string)round($mb, $mb >= 10 ? 0 : 1)) . ' MB';
        }
        return max(1, (int)round($bytes / 1024)) . ' KB';
    }

    /** O envio passou do post_max_size? (PHP descarta $_POST e $_FILES inteiros) */
    public static function postTooLarge(): bool
    {
        $len = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
        $max = iniBytes((string)ini_get('post_max_size'));
        return $max > 0 && $len > $max;
    }

    /**
     * Confere os arquivos de $_FILES['attachments'] e devolve os que podem ser gravados.
     * Problema vira UserMessage com frase para a pessoa.
     * @return list<array{tmp: string, name: string, ext: string, mime: string, size: int}>
     */
    public static function fromUpload($files, string $profile): array
    {
        if (!is_array($files) || !isset($files['name'])) {
            return [];
        }
        $allowed = self::profiles()[$profile]['ext'] ?? [];
        $maxFile = self::maxFileBytes();
        $names = (array)$files['name'];
        $out = [];
        $total = 0;
        foreach (array_keys($names) as $i) {
            $error = (int)(((array)$files['error'])[$i] ?? UPLOAD_ERR_NO_FILE);
            if ($error === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $name = self::cleanName((string)$names[$i]);
            if (in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
                throw new UserMessage("\"$name\" passa do limite de " . self::sizeLabel($maxFile) . ' por arquivo.');
            }
            if ($error === UPLOAD_ERR_PARTIAL) {
                throw new UserMessage("O envio de \"$name\" não terminou. Tente de novo.");
            }
            $tmp = (string)(((array)$files['tmp_name'])[$i] ?? '');
            if ($error !== UPLOAD_ERR_OK || $tmp === '' || !is_uploaded_file($tmp)) {
                throw new UserMessage("Não foi possível receber \"$name\". Tente de novo.");
            }
            if (count($out) >= self::MAX_FILES) {
                throw new UserMessage('Cabem até ' . self::MAX_FILES . ' arquivos por pedido.');
            }
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed, true)) {
                throw new UserMessage($profile === 'pdf'
                    ? "Na cópia colorida, anexe só PDF. \"$name\" não é PDF."
                    : "O tipo de \"$name\" não é aceito. Use " . self::profiles()['geral']['label'] . '.');
            }
            $size = (int)filesize($tmp);
            if ($size <= 0) {
                throw new UserMessage("\"$name\" está vazio.");
            }
            if ($size > $maxFile) {
                throw new UserMessage("\"$name\" passa do limite de " . self::sizeLabel($maxFile) . ' por arquivo.');
            }
            if (!self::contentMatches($tmp, $ext)) {
                throw new UserMessage("\"$name\" não parece ser um arquivo " . strtoupper($ext) . ' de verdade. Salve de novo e anexe outra vez.');
            }
            $total += $size;
            if ($total > self::maxTotalBytes()) {
                throw new UserMessage('Os arquivos juntos passam de ' . self::sizeLabel(self::maxTotalBytes()) . '. Anexe menos ou arquivos menores.');
            }
            $out[] = ['tmp' => $tmp, 'name' => $name, 'ext' => $ext, 'mime' => self::TYPES[$ext][2], 'size' => $size];
        }
        return $out;
    }

    /**
     * Move os arquivos para uploadDir() e grava as linhas (chamar dentro da transação do pedido).
     * $moved recebe os caminhos já gravados, para apagar se a transação desfizer.
     */
    public static function store(PDO $pdo, string $sector, int $requestId, array $files, int $userId, array &$moved): void
    {
        if (!$files) {
            return;
        }
        $sub = date('Y') . '/' . date('m');
        $dir = uploadDir() . '/' . $sub;
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException("Não foi possível criar a pasta de anexos ($dir).");
        }
        self::protectRoot();
        $st = $pdo->prepare('INSERT INTO request_attachments (request_id, request_table, original_name, stored_name, mime, size_bytes, uploaded_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
        foreach ($files as $f) {
            $stored = $sub . '/' . bin2hex(random_bytes(16)) . '.' . $f['ext'];
            $path = uploadDir() . '/' . $stored;
            if (!move_uploaded_file($f['tmp'], $path)) {
                throw new RuntimeException("Não foi possível gravar o anexo em $path.");
            }
            $moved[] = $path;
            $st->execute([$requestId, $sector, $f['name'], $stored, $f['mime'], $f['size'], $userId]);
        }
    }

    /** @return list<array{id: int, original_name: string, mime: string, size_bytes: int}> */
    public static function forRequest(PDO $pdo, string $sector, int $requestId): array
    {
        $st = $pdo->prepare('SELECT id, original_name, mime, size_bytes FROM request_attachments WHERE request_table = ? AND request_id = ? ORDER BY id');
        $st->execute([$sector, $requestId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Anexos de vários pedidos numa consulta só (fila da Aprovar).
     * @param list<array{0: string, 1: int}> $keys pares [setor, id]
     * @return array<string, list<array>> chave "setor:id"
     */
    public static function forMany(PDO $pdo, array $keys): array
    {
        $out = [];
        $bySector = [];
        foreach ($keys as [$sector, $id]) {
            $bySector[$sector][] = (int)$id;
        }
        foreach ($bySector as $sector => $ids) {
            $ids = array_values(array_unique($ids));
            $marks = implode(',', array_fill(0, count($ids), '?'));
            $st = $pdo->prepare("SELECT id, request_id, original_name, mime, size_bytes FROM request_attachments WHERE request_table = ? AND request_id IN ($marks) ORDER BY id");
            $st->execute(array_merge([$sector], $ids));
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[$sector . ':' . (int)$r['request_id']][] = $r;
            }
        }
        return $out;
    }

    /** Um anexo pelo id (para o download), com o caminho do arquivo */
    public static function find(PDO $pdo, int $id): ?array
    {
        $st = $pdo->prepare('SELECT id, request_id, request_table, original_name, stored_name, mime, size_bytes FROM request_attachments WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row || !preg_match('#^\d{4}/\d{2}/[a-f0-9]{32}\.[a-z]{3,4}$#', (string)$row['stored_name'])) {
            return null;
        }
        $row['path'] = uploadDir() . '/' . $row['stored_name'];
        return $row;
    }

    /** Abre no navegador (PDF, foto) ou baixa (Office)? */
    public static function opensInline(string $storedName): bool
    {
        $ext = strtolower(pathinfo($storedName, PATHINFO_EXTENSION));
        return (bool)(self::TYPES[$ext][3] ?? false);
    }

    /**
     * Apaga as linhas do pedido (dentro da transação) e devolve os caminhos dos arquivos,
     * para apagar com unlinkAll() só depois do commit.
     * @return list<string>
     */
    public static function deleteForRequest(PDO $pdo, string $sector, int $requestId): array
    {
        $st = $pdo->prepare('SELECT stored_name FROM request_attachments WHERE request_table = ? AND request_id = ?');
        $st->execute([$sector, $requestId]);
        $paths = array_map(fn($n) => uploadDir() . '/' . $n, $st->fetchAll(PDO::FETCH_COLUMN));
        $pdo->prepare('DELETE FROM request_attachments WHERE request_table = ? AND request_id = ?')->execute([$sector, $requestId]);
        return $paths;
    }

    public static function unlinkAll(array $paths): void
    {
        foreach ($paths as $p) {
            if (is_file($p) && !@unlink($p)) {
                error_log("RequestAttachments: não apagou $p");
            }
        }
    }

    /** Lista para a tela: nome, tamanho e link de download */
    public static function html(array $attachments, string $class = 'req-files'): string
    {
        if (!$attachments) {
            return '';
        }
        $html = '<ul class="' . e($class) . '">';
        foreach ($attachments as $a) {
            $isPdf = ($a['mime'] ?? '') === 'application/pdf';
            $icon = $isPdf ? 'fa-file-pdf' : (str_starts_with((string)($a['mime'] ?? ''), 'image/') ? 'fa-file-image' : 'fa-file-lines');
            $html .= '<li><a href="api/attachment.php?id=' . (int)$a['id'] . '" target="_blank" rel="noopener">'
                . '<i class="fa-solid ' . $icon . '" aria-hidden="true"></i> ' . e((string)$a['original_name']) . '</a>'
                . ' <small>' . e(self::sizeLabel((int)$a['size_bytes'])) . '</small></li>';
        }
        return $html . '</ul>';
    }

    /** Nome só para mostrar: sem pasta, sem caracteres de controle, até 255 letras */
    private static function cleanName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = trim(preg_replace('/[\x00-\x1F\x7F]+/u', '', $name) ?? '');
        if ($name === '') {
            $name = 'arquivo';
        }
        return mb_substr($name, -255);
    }

    private static function contentMatches(string $path, string $ext): bool
    {
        [$mimes, $magics] = self::TYPES[$ext];
        $head = (string)file_get_contents($path, false, null, 0, 16);
        $magicOk = false;
        foreach ($magics as $m) {
            if (str_starts_with($head, $m)) {
                $magicOk = true;
            }
        }
        if ($ext === 'webp') {
            $magicOk = $magicOk && substr($head, 8, 4) === 'WEBP';
        }
        if (!$magicOk) {
            return false;
        }
        if (class_exists('finfo')) {
            $mime = (string)(new finfo(FILEINFO_MIME_TYPE))->file($path);
            return in_array($mime, $mimes, true);
        }
        return true;
    }

    /** Garante que a pasta padrão não é servida mesmo se a regra do .htaccess da raiz faltar */
    private static function protectRoot(): void
    {
        $guard = uploadDir() . '/.htaccess';
        if (!is_file($guard)) {
            @file_put_contents($guard, "Require all denied\n");
        }
    }
}
