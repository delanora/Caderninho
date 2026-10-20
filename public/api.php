<?php
declare(strict_types=1);

/**
 * API do Caderninho — todas as ações passam por aqui via ?a=acao.
 * Respostas sempre em JSON; erros com código HTTP significativo.
 */

/* --------------------------------------------------------- configuração */

/**
 * Lê um .env simples: KEY=valor, comentários só em linha inteira (#),
 * sem interpolação de variáveis e sem escapes — o hash bcrypt contém cifrões
 * e precisa chegar intacto.
 */
function env_parse(string $path): array
{
    $out = [];
    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return $out;
    }
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (str_starts_with($line, 'export ')) {
            $line = trim(substr($line, 7));
        }
        $pos = strpos($line, '=');
        if ($pos === false) {
            continue;
        }
        $key = trim(substr($line, 0, $pos));
        $val = trim(substr($line, $pos + 1));
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) !== 1) {
            continue;
        }
        $n = strlen($val);
        if ($n >= 2 && ($val[0] === '"' || $val[0] === "'") && $val[$n - 1] === $val[0]) {
            $val = substr($val, 1, -1);
        }
        $out[$key] = $val;
    }
    return $out;
}

$root = dirname(__DIR__);
$env = is_readable($root . '/.env') ? env_parse($root . '/.env') : [];

// Instalações anteriores à migração para .env continuam funcionando.
if (!$env && is_readable($root . '/config.php')) {
    require $root . '/config.php';
}

ini_set('display_errors', '0');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.use_trans_sid', '0');

// O cookie só é marcado Secure quando a requisição chegou por HTTPS.
$https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'httponly' => true,
    'secure'   => $https,
    'samesite' => 'Lax',
]);
session_start();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");

const MAX_UPLOAD_BYTES   = 10 * 1024 * 1024;
const MAX_BODY_BYTES     = 500000;
const TOPICS_LIMIT_MAX   = 200;
const LOGIN_MAX_ATTEMPTS = 8;
const LOGIN_WINDOW_MIN   = 15;

const ALLOWED_TAGS = ['p', 'br', 'b', 'strong', 'i', 'em', 'u', 'h2', 'h3', 'ul', 'ol', 'li', 'img', 'a'];
// Tags removidas junto com o conteúdo: nada aqui pode virar script/iframe/objeto.
const DROP_TAGS = [
    'script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet',
    'svg', 'math', 'template', 'noscript', 'link', 'meta', 'base', 'title', 'head',
    'body', 'html', 'form', 'input', 'button', 'select', 'textarea', 'option',
];
// Padrão (sem delimitadores) de uma imagem gerada pela ação upload.
const UPLOAD_PATH_PATTERN = 'uploads/[a-f0-9]{16}\.(?:jpe?g|png|webp|gif)';

function out(array $d, int $c = 200): void
{
    http_response_code($c);
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function q(string $sql, array $params = []): PDOStatement
{
    global $pdo;
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st;
}

/* ---------------------------------------------------------------- sessão */

function csrf(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['csrf'];
}

function rotate_csrf(): string
{
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return (string) $_SESSION['csrf'];
}

/** Toda escrita (POST) exige o token da sessão no header X-CSRF. */
function require_csrf(): void
{
    $sent = $_SERVER['HTTP_X_CSRF'] ?? '';
    $have = $_SESSION['csrf'] ?? '';
    if (!is_string($sent) || !is_string($have) || $have === '' || !hash_equals($have, $sent)) {
        out(['erro' => 'csrf'], 403);
    }
}

function client_ip(): string
{
    // Atrás de proxy reverso, REMOTE_ADDR é o proxy — ver README.
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

function login_failures(string $ip): int
{
    $sql = 'select count(*) from login_attempts where ip = ? and at > now() - interval \'' . LOGIN_WINDOW_MIN . ' minutes\'';
    return (int) q($sql, [$ip])->fetchColumn();
}

function record_login_failure(string $ip): void
{
    q('insert into login_attempts (ip) values (?)', [$ip]);
    // Limpeza oportunista para a tabela não crescer sem fim.
    if (random_int(1, 100) === 1) {
        q("delete from login_attempts where at < now() - interval '1 day'");
    }
}

/* ------------------------------------------------------------ validação */

function str_field(array $in, string $key, int $max, bool $required = true): string
{
    $v = $in[$key] ?? '';
    if (!is_string($v)) {
        $v = is_int($v) ? (string) $v : '';
    }
    $v = trim($v);
    if ($required && $v === '') {
        out(['erro' => 'campo_obrigatorio', 'campo' => $key], 400);
    }
    if (strlen($v) > $max) {
        out(['erro' => 'campo_longo', 'campo' => $key], 400);
    }
    return $v;
}

function int_field(array $in, string $key, bool $required = true): int
{
    $v = $in[$key] ?? null;
    if ($v === null || $v === '' || $v === 0 || $v === '0') {
        if ($required) {
            out(['erro' => 'campo_obrigatorio', 'campo' => $key], 400);
        }
        return 0;
    }
    if (!is_int($v) && !(is_string($v) && ctype_digit($v))) {
        out(['erro' => 'campo_invalido', 'campo' => $key], 400);
    }
    $n = (int) $v;
    if ($n < 1) {
        out(['erro' => 'campo_invalido', 'campo' => $key], 400);
    }
    return $n;
}



/** Emoji omitido usa o padrão; emoji com HTML/aspas é recusado. */
function valid_emoji(string $e): string
{
    $e = trim($e);
    if ($e === '') {
        return '🌸';
    }
    // Um emoji pode ter vários code points (ZWJ), por isso o limite em bytes.
    if (strlen($e) > 32 || strpbrk($e, '<>&"\'') !== false) {
        out(['erro' => 'campo_invalido', 'campo' => 'emoji'], 400);
    }
    return $e;
}

function category_exists(int $id): bool
{
    return (int) q('select count(*) from categories where id = ?', [$id])->fetchColumn() > 0;
}

/* -------------------------------------------------------- HTML allowlist */

/**
 * Sanitiza o HTML do corpo por allowlist de tags e atributos.
 * Não existe atributo permitido além de <img src> apontando para uploads/.
 */
function clean(?string $html): string
{
    $html = (string) $html;
    if (trim($html) === '') {
        return '';
    }
    if (strlen($html) > MAX_BODY_BYTES) {
        out(['erro' => 'corpo_grande'], 413);
    }
    if (!class_exists('DOMDocument')) {
        throw new RuntimeException('extensão php-xml (DOMDocument) é obrigatória');
    }

    $doc = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $doc->loadHTML(
        '<?xml encoding="UTF-8"?><div id="cad-root">' . $html . '</div>',
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
    );
    libxml_clear_errors();
    libxml_use_internal_errors($prev);

    $root = $doc->getElementById('cad-root');
    if (!$root instanceof DOMElement) {
        $first = $doc->getElementsByTagName('div')->item(0);
        if (!$first instanceof DOMElement) {
            return '';
        }
        $root = $first;
    }

    sanitize_children($root);

    $out = '';
    foreach ($root->childNodes as $child) {
        $out .= (string) $doc->saveHTML($child);
    }
    return trim($out);
}

/**
 * Destino de link aceitável: http(s) ou mailto, sem espaços, controles, aspas
 * ou barras invertidas. Qualquer outro esquema (javascript:, data:, file:...)
 * devolve null e o <a> é desembrulhado para texto.
 */
function safe_link_href(string $href): ?string
{
    $href = trim($href);
    if ($href === '' || strlen($href) > 2000) {
        return null;
    }
    if (preg_match('#[\x00-\x20\x7f"<>`\\\\]#', $href) === 1) {
        return null;
    }
    if (preg_match('#^https?://[^/]#i', $href) === 1) {
        return $href;
    }
    if (preg_match('#^mailto:[^\s@]+@[^\s@]+(\?.*)?$#i', $href) === 1) {
        return $href;
    }
    return null;
}

function sanitize_children(DOMNode $node): void
{
    do {
        $changed = false;
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMText) {
                continue;
            }
            if (!$child instanceof DOMElement) {
                $node->removeChild($child); // comentários, instruções, CDATA
                $changed = true;
                continue;
            }
            $name = strtolower($child->nodeName);

            if (in_array($name, DROP_TAGS, true)) {
                $node->removeChild($child);
                $changed = true;
                continue;
            }

            if (!in_array($name, ALLOWED_TAGS, true)) {
                // Tag neutra (div, span, a, font...): mantém o texto, descarta a tag.
                while ($child->firstChild) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);
                $changed = true;
                continue;
            }

            if ($name === 'a') {
                $href = (string) $child->getAttribute('href');
                foreach (iterator_to_array($child->attributes) as $attr) {
                    $child->removeAttribute($attr->nodeName);
                }
                $safe = safe_link_href($href);
                if ($safe === null) {
                    // endereço inválido: mantém o texto, descarta o link
                    while ($child->firstChild) {
                        $node->insertBefore($child->firstChild, $child);
                    }
                    $node->removeChild($child);
                    $changed = true;
                    continue;
                }
                $child->setAttribute('href', $safe);
                $child->setAttribute('target', '_blank');
                $child->setAttribute('rel', 'noopener noreferrer nofollow');
            } else {
                foreach (iterator_to_array($child->attributes) as $attr) {
                    $keep = $name === 'img'
                        && $attr->nodeName === 'src'
                        && preg_match('#^' . UPLOAD_PATH_PATTERN . '$#', (string) $attr->nodeValue) === 1;
                    if (!$keep) {
                        $child->removeAttribute($attr->nodeName);
                    }
                }
            }
            sanitize_children($child);
        }
    } while ($changed);
}

/* --------------------------------------------------------------- uploads */

/** URLs de imagem referenciadas num corpo HTML. */
function body_uploads(string $body): array
{
    preg_match_all('#' . UPLOAD_PATH_PATTERN . '#', $body, $m);
    return array_values(array_unique($m[0]));
}

/** Apaga do disco as imagens que não estão mais referenciadas por nenhum tópico. */
function purge_orphans(array $urls): void
{
    foreach ($urls as $url) {
        $still = (int) q('select count(*) from topics where body like ?', ['%' . $url . '%'])->fetchColumn();
        if ($still > 0) {
            continue;
        }
        $path = __DIR__ . '/' . $url;
        if (is_file($path)) {
            @unlink($path);
        }
    }
}

function ini_bytes(string $v): int
{
    $v = trim($v);
    if ($v === '') {
        return 0;
    }
    $unit = strtolower(substr($v, -1));
    $n = (int) $v;
    return match ($unit) {
        'g' => $n * 1024 * 1024 * 1024,
        'm' => $n * 1024 * 1024,
        'k' => $n * 1024,
        default => $n,
    };
}

/* ------------------------------------------------------------- bootstrap */

$dsn = defined('DSN') ? (string) DSN : (string) ($env['DB_DSN'] ?? '');
if ($dsn === '' && ($env['DB_NAME'] ?? '') !== '') {
    $dsn = sprintf(
        'pgsql:host=%s;port=%s;dbname=%s',
        $env['DB_HOST'] ?? 'localhost',
        $env['DB_PORT'] ?? '5432',
        $env['DB_NAME']
    );
}

$cfg = [
    'dsn'  => $dsn,
    'user' => defined('DB_USER') ? (string) DB_USER : (string) ($env['DB_USER'] ?? ''),
    'pass' => defined('DB_PASS') ? (string) DB_PASS : (string) ($env['DB_PASS'] ?? ''),
    'hash' => defined('APP_HASH') ? (string) APP_HASH : (string) ($env['APP_PASSWORD_HASH'] ?? $env['APP_HASH'] ?? ''),
];

$faltando = [];
foreach ($cfg as $chave => $valor) {
    if ($valor === '') {
        $faltando[] = $chave;
    }
}
if ($faltando) {
    error_log('caderninho: configuração ausente em .env/config.php: ' . implode(', ', $faltando));
    out(['erro' => 'config', 'faltando' => $faltando], 500);
}

try {
    $pdo = new PDO($cfg['dsn'], $cfg['user'], $cfg['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (Throwable $e) {
    error_log('caderninho: banco indisponível: ' . $e->getMessage());
    out(['erro' => 'db'], 500);
}

$a = isset($_GET['a']) && is_string($_GET['a']) ? $_GET['a'] : '';

// Corpo maior que post_max_size: o PHP descarta $_POST e $_FILES silenciosamente.
$declared = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $declared > 0 && empty($_FILES) && $declared > ini_bytes((string) ini_get('post_max_size'))) {
    out(['erro' => 'grande', 'max' => ini_get('post_max_size')], 413);
}

$in = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    if (is_string($raw) && trim($raw) !== '') {
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            out(['erro' => 'json_invalido'], 400);
        }
        $in = $decoded;
    }
}

/* ----------------------------------------------------------------- ações */

try {
    if ($a === 'me') {
        out(['ok' => !empty($_SESSION['ok']), 'csrf' => csrf()]);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf();
    }

    if ($a === 'login') {
        $ip = client_ip();
        if (login_failures($ip) >= LOGIN_MAX_ATTEMPTS) {
            out(['erro' => 'muitas_tentativas', 'minutos' => LOGIN_WINDOW_MIN], 429);
        }
        $senha = str_field($in, 'senha', 200);
        if (password_verify($senha, $cfg['hash'])) {
            q('delete from login_attempts where ip = ?', [$ip]);
            session_regenerate_id(true);
            $_SESSION['ok'] = true;
            out(['ok' => 1, 'csrf' => rotate_csrf()]);
        }
        record_login_failure($ip);
        out(['erro' => 'senha'], 401);
    }

    if (empty($_SESSION['ok'])) {
        out(['erro' => 'auth'], 401);
    }

    switch ($a) {
        case 'cats':
            $rows = q(
                'select c.id, c.name, c.emoji, c.color, count(t.id) as n
                   from categories c
                   left join topics t on t.category_id = c.id
                  group by c.id
                  order by lower(c.name)'
            )->fetchAll();
            foreach ($rows as &$r) {
                $r['id'] = (int) $r['id'];
                $r['n']  = (int) $r['n'];
            }
            unset($r);
            out($rows);

        case 'cat_save':
            $name  = str_field($in, 'name', 80);
            $emoji = valid_emoji(str_field($in, 'emoji', 32, false));
            $id    = int_field($in, 'id', false);
            // A cor é fixa na paleta do app: o banco aplica o default e a
            // coluna não é mais escrita, então clientes antigos não a alteram.
            if ($id > 0) {
                if (!category_exists($id)) {
                    out(['erro' => 'nao_encontrado'], 404);
                }
                q('update categories set name = ?, emoji = ?, color = default where id = ?', [$name, $emoji, $id]);
            } else {
                $id = (int) q(
                    'insert into categories (name, emoji) values (?, ?) returning id',
                    [$name, $emoji]
                )->fetchColumn();
            }
            out(['ok' => 1, 'id' => $id]);

        case 'cat_del':
            $id = int_field($in, 'id');
            if (!category_exists($id)) {
                out(['erro' => 'nao_encontrado'], 404);
            }
            $bodies = q('select body from topics where category_id = ?', [$id])->fetchAll(PDO::FETCH_COLUMN);
            q('delete from categories where id = ?', [$id]);
            foreach ($bodies as $body) {
                purge_orphans(body_uploads((string) $body));
            }
            out(['ok' => 1]);

        case 'topics':
            $where = [];
            $params = [];
            $cat = isset($_GET['cat']) ? (int) $_GET['cat'] : 0;
            if ($cat > 0) {
                $where[] = 't.category_id = ?';
                $params[] = $cat;
            }
            $words = preg_split(
                '/\s+/u',
                trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', (string) ($_GET['q'] ?? ''))),
                -1,
                PREG_SPLIT_NO_EMPTY
            ) ?: [];
            if (count($words) > 12) {
                $words = array_slice($words, 0, 12);
            }
            if ($words) {
                $where[] = "t.tsv @@ to_tsquery('pt_unaccent', ?)";
                $params[] = implode(' & ', array_map(static fn(string $w): string => $w . ':*', $words));
            }
            $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 100;
            $limit = max(1, min(TOPICS_LIMIT_MAX, $limit));
            $offset = isset($_GET['offset']) ? max(0, (int) $_GET['offset']) : 0;
            $params[] = $limit;
            $params[] = $offset;
            out(q(
                'select t.id, t.title, t.created_at, t.updated_at, c.name as cat, c.emoji, c.color
                   from topics t
                   join categories c on c.id = t.category_id'
                . ($where ? ' where ' . implode(' and ', $where) : '')
                . ' order by t.updated_at desc limit ? offset ?',
                $params
            )->fetchAll());

        case 'topic':
            $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
            $row = $id > 0 ? q(
                'select t.id, t.title, t.body, t.category_id, t.created_at, t.updated_at, c.name as cat
                   from topics t join categories c on c.id = t.category_id
                  where t.id = ?',
                [$id]
            )->fetch() : false;
            if (!$row) {
                out(['erro' => 'nao_encontrado'], 404);
            }
            $row['id'] = (int) $row['id'];
            $row['category_id'] = (int) $row['category_id'];
            out($row);

        case 'topic_save':
            $title   = str_field($in, 'title', 200);
            $catId   = int_field($in, 'category_id');
            $id      = int_field($in, 'id', false);
            $body    = clean(isset($in['body']) && is_string($in['body']) ? $in['body'] : '');
            $oldBody = '';
            if (!category_exists($catId)) {
                out(['erro' => 'categoria_inexistente'], 400);
            }
            if ($id > 0) {
                $old = q('select body from topics where id = ?', [$id])->fetchColumn();
                if ($old === false) {
                    out(['erro' => 'nao_encontrado'], 404);
                }
                $oldBody = (string) $old;
                q(
                    'update topics set title = ?, body = ?, category_id = ? where id = ?',
                    [$title, $body, $catId, $id]
                );
            } else {
                $id = (int) q(
                    'insert into topics (title, body, category_id) values (?, ?, ?) returning id',
                    [$title, $body, $catId]
                )->fetchColumn();
            }
            // Imagens que saíram do corpo e não são usadas em outro lugar.
            purge_orphans(array_diff(body_uploads($oldBody), body_uploads($body)));
            out(['id' => $id]);

        case 'topic_del':
            $id = int_field($in, 'id');
            $body = q('select body from topics where id = ?', [$id])->fetchColumn();
            if ($body === false) {
                out(['erro' => 'nao_encontrado'], 404);
            }
            q('delete from topics where id = ?', [$id]);
            purge_orphans(body_uploads((string) $body));
            out(['ok' => 1]);

        case 'upload':
            $f = $_FILES['f'] ?? null;
            if (!is_array($f) || !isset($f['error'])) {
                out(['erro' => 'upload'], 400);
            }
            $err = (int) $f['error'];
            if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
                out(['erro' => 'grande', 'max' => ini_get('upload_max_filesize')], 413);
            }
            if ($err !== UPLOAD_ERR_OK) {
                out(['erro' => 'upload'], 400);
            }
            $tmp = (string) $f['tmp_name'];
            if (!is_uploaded_file($tmp)) {
                out(['erro' => 'upload'], 400);
            }
            if ((int) $f['size'] <= 0 || (int) $f['size'] > MAX_UPLOAD_BYTES) {
                out(['erro' => 'grande', 'max' => MAX_UPLOAD_BYTES], 413);
            }
            $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
            $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'][$mime] ?? null;
            if ($ext === null || @getimagesize($tmp) === false) {
                out(['erro' => 'tipo'], 415);
            }
            $dir = __DIR__ . '/uploads';
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                error_log('caderninho: não foi possível criar ' . $dir);
                out(['erro' => 'io'], 500);
            }
            $name = bin2hex(random_bytes(8)) . '.' . $ext;
            if (!move_uploaded_file($tmp, $dir . '/' . $name)) {
                error_log('caderninho: falha ao mover upload para ' . $dir);
                out(['erro' => 'io'], 500);
            }
            out(['url' => 'uploads/' . $name]);
    }
} catch (PDOException $e) {
    if ($e->getCode() === '23503') {
        out(['erro' => 'referencia_invalida'], 400);
    }
    error_log('caderninho: erro de banco: ' . $e->getMessage());
    out(['erro' => 'interno'], 500);
} catch (Throwable $e) {
    error_log('caderninho: ' . get_class($e) . ': ' . $e->getMessage());
    out(['erro' => 'interno'], 500);
}

out(['erro' => 'acao_desconhecida'], 404);
