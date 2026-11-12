<?php
/**
 * Itens do pedido (tabela request_items, migração 014).
 * - Compras: lista de itens (nome obrigatório, quantidade e link opcionais).
 * - Marketing: links de referência (link obrigatório, "sobre o link" opcional).
 * O pedido é o par (request_table, request_id). Os outros setores não têm itens.
 */
class RequestItems
{
    public const MAX_ROWS = 30;

    /** Setores com itens e o que cada linha exige */
    private const PROFILES = ['shop' => 'items', 'mkt' => 'links'];

    public static function sectorHasItems(string $sector): bool
    {
        return isset(self::PROFILES[$sector]);
    }

    /**
     * Lê $_POST['items'] (items[n][name|qty|url]) e devolve as linhas prontas para gravar.
     * Linha toda vazia é ignorada. Problema vira UserMessage com frase para a pessoa.
     * @return list<array{name: ?string, qty: ?string, url: ?string}>
     */
    public static function parse(string $sector, $raw): array
    {
        $profile = self::PROFILES[$sector] ?? null;
        if ($profile === null || !is_array($raw)) {
            return [];
        }
        $rows = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = self::text($row['name'] ?? '', 255, $profile === 'items' ? 'O nome do item' : 'O texto sobre o link');
            $qty = $profile === 'items' ? self::text($row['qty'] ?? '', 50, 'A quantidade') : '';
            $urlRaw = trim(is_string($row['url'] ?? null) ? $row['url'] : '');
            if ($name === '' && $qty === '' && $urlRaw === '') {
                continue;
            }
            $n = count($rows) + 1;
            if ($profile === 'items' && $name === '') {
                throw new UserMessage("Escreva o nome do item $n (ou tire a linha).");
            }
            if ($profile === 'links' && $urlRaw === '') {
                throw new UserMessage("Cole o link $n (ou tire a linha).");
            }
            $url = $urlRaw === '' ? null : self::url($urlRaw, $n);
            $rows[] = ['name' => $name !== '' ? $name : null, 'qty' => $qty !== '' ? $qty : null, 'url' => $url];
            if (count($rows) > self::MAX_ROWS) {
                throw new UserMessage('Cabem até ' . self::MAX_ROWS . ' linhas por pedido.');
            }
        }
        return $rows;
    }

    /** Grava as linhas (chamar dentro da transação que cria o pedido) */
    public static function save(PDO $pdo, string $sector, int $requestId, array $rows): void
    {
        $st = $pdo->prepare('INSERT INTO request_items (request_id, request_table, position, name, qty, url) VALUES (?, ?, ?, ?, ?, ?)');
        foreach (array_values($rows) as $i => $r) {
            $st->execute([$requestId, $sector, $i, $r['name'], $r['qty'], $r['url']]);
        }
    }

    /** @return list<array{name: ?string, qty: ?string, url: ?string}> */
    public static function forRequest(PDO $pdo, string $sector, int $requestId): array
    {
        if (!self::sectorHasItems($sector)) {
            return [];
        }
        $st = $pdo->prepare('SELECT name, qty, url FROM request_items WHERE request_table = ? AND request_id = ? ORDER BY position, id');
        $st->execute([$sector, $requestId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Itens de vários pedidos numa consulta só (fila da Aprovar).
     * @param list<array{0: string, 1: int}> $keys pares [setor, id]
     * @return array<string, list<array>> chave "setor:id"
     */
    public static function forMany(PDO $pdo, array $keys): array
    {
        $out = [];
        $bySector = [];
        foreach ($keys as [$sector, $id]) {
            if (self::sectorHasItems($sector)) {
                $bySector[$sector][] = (int)$id;
            }
        }
        foreach ($bySector as $sector => $ids) {
            $ids = array_values(array_unique($ids));
            $marks = implode(',', array_fill(0, count($ids), '?'));
            $st = $pdo->prepare("SELECT request_id, name, qty, url FROM request_items WHERE request_table = ? AND request_id IN ($marks) ORDER BY request_id, position, id");
            $st->execute(array_merge([$sector], $ids));
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[$sector . ':' . (int)$r['request_id']][] = $r;
            }
        }
        return $out;
    }

    public static function deleteForRequest(PDO $pdo, string $sector, int $requestId): void
    {
        $pdo->prepare('DELETE FROM request_items WHERE request_table = ? AND request_id = ?')->execute([$sector, $requestId]);
    }

    /** Título da lista na tela */
    public static function heading(string $sector): string
    {
        return $sector === 'mkt' ? 'Links de referência' : 'Itens';
    }

    /**
     * Lista para a tela (página do pedido, painel da Aprovar). Link abre em outra aba e
     * mostra o domínio, para a pessoa saber aonde vai.
     */
    public static function html(array $items, string $sector, string $class = 'req-items'): string
    {
        if (!$items) {
            return '';
        }
        $html = '<ul class="' . e($class) . '">';
        foreach ($items as $it) {
            $name = trim((string)($it['name'] ?? ''));
            $qty = trim((string)($it['qty'] ?? ''));
            $url = trim((string)($it['url'] ?? ''));
            $parts = [];
            if ($name !== '') {
                $parts[] = $sector === 'mkt' ? e($name) : '<strong>' . e($name) . '</strong>';
            }
            if ($qty !== '') {
                $parts[] = e($qty);
            }
            if ($url !== '' && preg_match('#^https?://#i', $url)) {
                $parts[] = '<a href="' . e($url) . '" target="_blank" rel="noopener noreferrer">'
                    . '<i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i> ' . e(self::domain($url)) . '</a>';
            }
            $html .= '<li>' . implode(' · ', $parts) . '</li>';
        }
        return $html . '</ul>';
    }

    /** "www.loja.com.br/produto" → "loja.com.br" */
    public static function domain(string $url): string
    {
        $host = (string)parse_url($url, PHP_URL_HOST);
        return preg_replace('/^www\./i', '', $host) ?: $url;
    }

    private static function text($value, int $max, string $what): string
    {
        $value = trim(is_string($value) ? $value : '');
        if (mb_strlen($value) > $max) {
            throw new UserMessage("$what pode ter até $max letras.");
        }
        return $value;
    }

    private static function url(string $url, int $n): string
    {
        if (preg_match('/^www\./i', $url)) {
            $url = 'https://' . $url;
        }
        if (mb_strlen($url) > 1000) {
            throw new UserMessage("O link $n é longo demais (até 1.000 letras).");
        }
        if (!preg_match('#^https?://#i', $url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new UserMessage("O link $n não parece um endereço de site. Copie da barra do navegador (começa com https://).");
        }
        return $url;
    }
}
