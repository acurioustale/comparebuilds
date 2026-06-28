<?php

declare(strict_types=1);

// ─── Runtime hardening ─────────────────────────────────────────────────────────
// Never leak PHP errors/stack traces to clients; log them server-side instead.
ini_set('display_errors', '0');
error_reporting(E_ALL);

// Response headers are only emitted when share.php handles a request itself.
// When included by og.php (via SHARE_API_NO_MAIN) for helper functions only,
// skip them so og.php can set its own Content-Type.
if (!defined('SHARE_API_NO_MAIN')) {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('Cache-Control: no-store');
    // No CORS headers are sent on purpose: the browser will block cross-origin reads
    // and writes, so only the site itself (same origin) can use this API.
}

// ─── Limits / config ───────────────────────────────────────────────────────────
// MAX_BUILDS / MAX_BUILD_LEN are mirrored client-side in src/store/buildsStore.js.
// This file is the authority (the client only validates early for nicer errors);
// keep the two in sync if either limit changes.
const MAX_BODY_BYTES    = 16384; // raw POST body cap (5 builds * 2000 + overhead)
const MAX_BUILD_LEN     = 2000;
const MIN_BUILDS        = 2;
const MAX_BUILDS        = 5;
const MAX_LABEL_LEN     = 40;    // per-slot name cap; mirrors MAX_BUILD_NAME_LEN client-side
const MAX_NAME_LEN      = 64;    // class/spec display-name cap (used by the OG image)
const RATE_LIMIT_MAX    = 20;    // max shares one IP may create per window
const RATE_LIMIT_WINDOW = 3600;  // window length in seconds (1 hour)
const SHARE_TTL_DAYS    = 90;    // rows older than this are pruned
const ID_LEN            = 8;
const MAX_ID_LEN        = 16;   // max chars after collision extension
// Build strings are base64 (RFC 4648 alphabet, optional padding).
const BUILD_PATTERN     = '/^[A-Za-z0-9+\/]{1,2000}={0,2}$/';

/** Emit a JSON error and stop. */
function fail(int $code, string $msg): void
{
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}

/** The canonical site origin (overridable in config.php for staging). */
function site_origin(): string
{
    return defined('SITE_ORIGIN') ? SITE_ORIGIN : 'https://comparebuilds.app';
}

/**
 * Renders the HTML share page served at /s/<id>. Crawlers read the Open Graph
 * tags + generated image; humans are redirected to the SPA (#id) via a meta
 * refresh — no inline script, so it stays within the site's strict CSP.
 * `$data` may be null (share missing/expired) — then a generic card is shown.
 */
function render_share_page(string $id, ?array $data): void
{
    $origin = site_origin();
    $count  = is_array($data['builds'] ?? null) ? count($data['builds']) : 0;
    $class  = is_string($data['className'] ?? null) ? $data['className'] : '';
    $spec   = is_string($data['specName'] ?? null) ? $data['specName'] : '';

    $name  = trim("$spec $class");
    $title = $name !== '' ? "$name — Compare Builds" : 'Compare Builds — WoW talent build comparison';
    $desc  = $count >= 2
        ? "$count " . ($class !== '' ? "$class " : '') . 'talent builds compared on comparebuilds.app.'
        : 'A World of Warcraft talent build on comparebuilds.app.';
    $image   = "$origin/api/og.php?id=$id";
    $appUrl  = "$origin/#$id";
    $pageUrl = "$origin/s/$id";

    $e = fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: public, max-age=86400');
    echo "<!doctype html>\n<html lang=\"en\">\n<head>\n"
       . "<meta charset=\"utf-8\">\n"
       . "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n"
       . "<title>" . $e($title) . "</title>\n"
       . "<meta name=\"description\" content=\"" . $e($desc) . "\">\n"
       . "<link rel=\"canonical\" href=\"" . $e($pageUrl) . "\">\n"
       . "<meta property=\"og:type\" content=\"website\">\n"
       . "<meta property=\"og:site_name\" content=\"Compare Builds\">\n"
       . "<meta property=\"og:title\" content=\"" . $e($title) . "\">\n"
       . "<meta property=\"og:description\" content=\"" . $e($desc) . "\">\n"
       . "<meta property=\"og:url\" content=\"" . $e($pageUrl) . "\">\n"
       . "<meta property=\"og:image\" content=\"" . $e($image) . "\">\n"
       . "<meta property=\"og:image:width\" content=\"1200\">\n"
       . "<meta property=\"og:image:height\" content=\"630\">\n"
       . "<meta name=\"twitter:card\" content=\"summary_large_image\">\n"
       . "<meta name=\"twitter:title\" content=\"" . $e($title) . "\">\n"
       . "<meta name=\"twitter:description\" content=\"" . $e($desc) . "\">\n"
       . "<meta name=\"twitter:image\" content=\"" . $e($image) . "\">\n"
       . "<meta http-equiv=\"refresh\" content=\"0; url=" . $e($appUrl) . "\">\n"
       . "</head>\n<body>\n"
       . "<p>Opening this build in <a href=\"" . $e($appUrl) . "\">Compare Builds</a>…</p>\n"
       . "</body>\n</html>\n";
    exit;
}

/**
 * The client IP used for rate limiting.
 *
 * Direct hosting: REMOTE_ADDR is the real client. Behind a reverse proxy or CDN
 * (Cloudflare, etc.) REMOTE_ADDR is the proxy, collapsing every visitor into one
 * rate-limit bucket — so the real client is the first hop in X-Forwarded-For.
 * That header is client-spoofable, so it is ONLY trusted when the operator opts
 * in by defining TRUST_PROXY truthy in config.php (i.e. you know a trusted proxy
 * always sets it). Defaults to REMOTE_ADDR.
 */
function client_ip(): string
{
    if (defined('TRUST_PROXY') && TRUST_PROXY && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $first = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        if (filter_var($first, FILTER_VALIDATE_IP) !== false) {
            return $first;
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '';
}

/** Salted hash of the client IP, used only for rate limiting (not reversible to an IP in practice). */
function client_ip_hash(): string
{
    $salt = defined('SHARE_IP_SALT') ? SHARE_IP_SALT : 'comparebuilds-default-salt';
    return hash('sha256', $salt . '|' . client_ip());
}

/**
 * Whether a string is a well-formed share id (8–16 alphanumeric chars). The
 * pattern is mirrored in src/lib/route.js and api/og.php; shareIdParity.test.js
 * keeps the three copies in sync across the two languages.
 */
function valid_share_id(string $id): bool
{
    return preg_match('/^[A-Za-z0-9]{8,16}$/', $id) === 1;
}

/**
 * Validates a decoded POST body for share creation. Pure: no DB, no superglobals,
 * no output — so it is unit-testable. Returns ['error' => string] on the first
 * failure (always a 400-class client error), or ['payload' => array] with the
 * normalised, storable payload on success.
 */
function validate_share_input(mixed $body): array
{
    if (!is_array($body)) {
        return ['error' => 'Expected a JSON object'];
    }

    $classId = $body['classId'] ?? null;
    $specId  = $body['specId']  ?? null;
    $builds  = $body['builds']  ?? null;

    if (
        !is_int($classId) || $classId <= 0 || $classId > 1000000 ||
        !is_int($specId) || $specId <= 0 || $specId > 1000000
    ) {
        return ['error' => 'classId and specId must be positive integers'];
    }
    if (!is_array($builds)) {
        return ['error' => 'builds must be a JSON array'];
    }
    if (count($builds) < MIN_BUILDS || count($builds) > MAX_BUILDS) {
        return ['error' => 'builds must contain ' . MIN_BUILDS . '–' . MAX_BUILDS . ' entries'];
    }
    foreach ($builds as $b) {
        if (!is_string($b) || !preg_match(BUILD_PATTERN, $b)) {
            return ['error' => 'Each build must be a base64 build string ≤ ' . MAX_BUILD_LEN . ' chars'];
        }
    }

    // Optional per-slot labels: must parallel builds, each a short string.
    $labels = $body['labels'] ?? null;
    if ($labels !== null) {
        if (!is_array($labels) || count($labels) !== count($builds)) {
            return ['error' => 'labels, when present, must be an array parallel to builds'];
        }
        foreach ($labels as $l) {
            if (!is_string($l) || mb_strlen($l) > MAX_LABEL_LEN) {
                return ['error' => 'Each label must be a string ≤ ' . MAX_LABEL_LEN . ' chars'];
            }
        }
        // Drop an all-empty labels array so we never store noise.
        if (count(array_filter($labels, fn ($l) => $l !== '')) === 0) {
            $labels = null;
        }
    }

    // Optional class/spec display names (used by the OG image so it needs no
    // class index of its own). Validated as short plain strings.
    $className = $body['className'] ?? null;
    $specName  = $body['specName']  ?? null;
    foreach (['className' => $className, 'specName' => $specName] as $k => $v) {
        if ($v !== null && (!is_string($v) || mb_strlen($v) > MAX_NAME_LEN)) {
            return ['error' => "$k, when present, must be a string ≤ " . MAX_NAME_LEN . ' chars'];
        }
    }

    $layoutHash = $body['layoutHash'] ?? null;
    if ($layoutHash !== null && (!is_string($layoutHash) || mb_strlen($layoutHash) > 16)) {
        return ['error' => 'layoutHash, when present, must be a string ≤ 16 chars'];
    }

    $payload = ['classId' => $classId, 'specId' => $specId, 'builds' => $builds];
    if ($labels !== null) {
        $payload['labels'] = $labels;
    }
    if ($className !== null) {
        $payload['className'] = $className;
    }
    if ($specName !== null) {
        $payload['specName'] = $specName;
    }
    if ($layoutHash !== null) {
        $payload['layoutHash'] = $layoutHash;
    }

    return ['payload' => $payload];
}

/**
 * Base62 encodes the SHA-256 hash of a string. Uses GMP if available,
 * with a standalone pure-PHP fallback for arbitrary precision division.
 */
function base62_encode_sha256(string $input): string
{
    $hex = hash('sha256', $input);
    $alphabet = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
    if (function_exists('gmp_init')) {
        $num = gmp_init($hex, 16);
        $base62 = '';
        while (gmp_cmp($num, 0) > 0) {
            list($num, $rem) = gmp_div_qr($num, 62);
            $base62 = $alphabet[gmp_intval($rem)] . $base62;
        }
        return str_pad($base62, 8, '0', STR_PAD_LEFT);
    }
    $val = $hex;
    $base62 = '';
    while ($val !== '' && $val !== '0') {
        $remainder = 0;
        $newVal = '';
        foreach (str_split($val, 4) as $chunk) {
            $current = $remainder * 65536 + hexdec($chunk);
            $quotient = (int) ($current / 62);
            $remainder = $current % 62;
            if ($newVal !== '' || $quotient > 0) {
                if ($newVal !== '') {
                    $newVal .= str_pad(dechex($quotient), 4, '0', STR_PAD_LEFT);
                } else {
                    $newVal .= dechex($quotient);
                }
            }
        }
        $base62 = $alphabet[$remainder] . $base62;
        $val = $newVal;
    }
    return str_pad($base62, 8, '0', STR_PAD_LEFT);
}

/**
 * Deterministically canonicalizes the payload for content-addressing.
 */
function canonicalize_payload(array $payload): string
{
    $ordered = [];
    foreach (['classId', 'specId', 'className', 'specName', 'layoutHash', 'builds', 'labels'] as $key) {
        if (isset($payload[$key])) {
            $ordered[$key] = $payload[$key];
        }
    }
    return json_encode($ordered, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * Gets or creates the database connection.
 */
function get_db_connection(): PDO
{
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ],
    );

    // Create table on first run — cheap no-op afterwards.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS comparebuilds_shares (
            id         VARCHAR(32) NOT NULL PRIMARY KEY,
            data       MEDIUMTEXT  NOT NULL,
            ip_hash    CHAR(64)    NULL,
            created_at TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_accessed TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_created (created_at),
            INDEX idx_ip_created (ip_hash, created_at),
            INDEX idx_last_accessed (last_accessed)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    return $pdo;
}

/**
 * Stores a share payload with deduplication and collision retry logic,
 * enforcing rate limits and pruning expired shares.
 */
function store_share(PDO $pdo, array $payload, string $ipHash): string
{
    $lockName = 'cb_share_' . substr($ipHash, 0, 48);
    $lk = $pdo->prepare('SELECT GET_LOCK(?, 5)');
    $lk->execute([$lockName]);
    if ((int) $lk->fetchColumn() !== 1) {
        throw new RuntimeException('Server busy');
    }

    try {
        // ── Per-IP rate limit ────────────────────────────────────────────────
        $rl = $pdo->prepare(
            'SELECT COUNT(*) AS c FROM comparebuilds_shares '
            . 'WHERE ip_hash = ? AND created_at > NOW() - INTERVAL ' . RATE_LIMIT_WINDOW . ' SECOND'
        );
        $rl->execute([$ipHash]);
        if ((int) $rl->fetch()['c'] >= RATE_LIMIT_MAX) {
            $rel = $pdo->prepare('SELECT RELEASE_LOCK(?)');
            $rel->execute([$lockName]);
            throw new RuntimeException('Rate limit exceeded');
        }

        // ── Prune expired rows (best-effort) ─────────────────────────────────
        try {
            $prune = $pdo->prepare(
                'DELETE FROM comparebuilds_shares '
                . 'WHERE created_at < NOW() - INTERVAL ' . (SHARE_TTL_DAYS * 86400) . ' SECOND'
            );
            $prune->execute();
        } catch (Throwable $e) {
            // Non-fatal — proceed even if cleanup fails.
        }

        // ── Content-addressing & deduplication loop ──────────────────────────
        $canonical = canonicalize_payload($payload);
        $baseId = base62_encode_sha256($canonical);
        $stored = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $check = $pdo->prepare('SELECT data FROM comparebuilds_shares WHERE id = ?');
        $insert = $pdo->prepare('INSERT INTO comparebuilds_shares (id, data, ip_hash) VALUES (?, ?, ?)');
        $update = $pdo->prepare('UPDATE comparebuilds_shares SET last_accessed = NOW() WHERE id = ?');

        $id = null;
        $len = ID_LEN;
        $maxLen = min(strlen($baseId), MAX_ID_LEN);

        while ($len <= $maxLen) {
            $candidate = substr($baseId, 0, $len);
            $check->execute([$candidate]);
            $row = $check->fetch();

            if (!$row) {
                $insert->execute([$candidate, $stored, $ipHash]);
                $id = $candidate;
                break;
            }

            // Collision check: compare stored JSON directly (same payload
            // always serialises to the same string via json_encode flags).
            if ($row['data'] === $stored) {
                // Exact match -> deduplicate! Update last_accessed for LRU tracking.
                $update->execute([$candidate]);
                $id = $candidate;
                break;
            }

            // Collision with different payload -> extend length by 2 and retry
            $len += 2;
        }

        $rel = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $rel->execute([$lockName]);
    } catch (Throwable $e) {
        try {
            $rel = $pdo->prepare('SELECT RELEASE_LOCK(?)');
            $rel->execute([$lockName]);
        } catch (Throwable $ignored) {}
        throw $e;
    }

    if ($id === null) {
        throw new RuntimeException('Could not generate a unique share ID — please retry');
    }

    return $id;
}

/**
 * Retrieves the raw JSON data for a share ID, or null if not found.
 */
function get_share(PDO $pdo, string $id): ?string
{
    $stmt = $pdo->prepare('SELECT data FROM comparebuilds_shares WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ? $row['data'] : null;
}

// When this file is included for unit testing (with SHARE_API_NO_MAIN defined),
// stop here: everything above is pure and testable, everything below opens a DB
// connection and handles the live request.
if (defined('SHARE_API_NO_MAIN')) {
    return;
}

// ─── DB connection ────────────────────────────────────────────────────────────
require_once __DIR__ . '/../../config.php';

try {
    $pdo = get_db_connection();
} catch (Throwable $e) {
    fail(500, 'Database unavailable');
}

// ─── Route ────────────────────────────────────────────────────────────────────

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// ── GET ?id=xxxxxx ────────────────────────────────────────────────────────────
if ($method === 'GET') {
    $id = $_GET['id'] ?? '';
    $pageMode = isset($_GET['page']);

    if (!is_string($id) || !valid_share_id($id)) {
        if ($pageMode) {
            http_response_code(400);
            render_share_page('', null);
        }
        fail(400, 'Invalid ID format');
    }

    try {
        $data = get_share($pdo, $id);
    } catch (Throwable $e) {
        if ($pageMode) {
            http_response_code(500);
            render_share_page($id, null);
        }
        fail(500, 'Database error');
    }

    if (!$data) {
        if ($pageMode) {
            http_response_code(404);
            render_share_page($id, null);
        }
        fail(404, 'Share not found or has expired');
    }

    if ($pageMode) {
        render_share_page($id, json_decode($data, true) ?: null);
    }

    header('Cache-Control: public, max-age=86400');
    echo $data;
    exit;
}

// ── POST ──────────────────────────────────────────────────────────────────────
if ($method === 'POST') {
    $declaredLen = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($declaredLen > MAX_BODY_BYTES) {
        fail(413, 'Payload too large');
    }

    $raw = file_get_contents('php://input', false, null, 0, MAX_BODY_BYTES + 1);
    if ($raw === false || strlen($raw) > MAX_BODY_BYTES) {
        fail(413, 'Payload too large');
    }

    try {
        $body = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        fail(400, 'Expected a valid JSON body');
    }
    $result = validate_share_input($body);
    if (isset($result['error'])) {
        fail(400, $result['error']);
    }
    $payload = $result['payload'];

    $ipHash = client_ip_hash();

    try {
        $id = store_share($pdo, $payload, $ipHash);
    } catch (RuntimeException $e) {
        if ($e->getMessage() === 'Rate limit exceeded') {
            header('Retry-After: ' . RATE_LIMIT_WINDOW);
            fail(429, 'Too many shares created — please try again later');
        }
        if ($e->getMessage() === 'Server busy') {
            header('Retry-After: 5');
            fail(503, 'Server busy — please try again');
        }
        fail(500, $e->getMessage());
    } catch (Throwable $e) {
        fail(500, 'Database error');
    }

    echo json_encode(['id' => $id]); // nosemgrep: php.lang.security.injection.echoed-request.echoed-request
    exit;
}

// ── Method not allowed ────────────────────────────────────────────────────────
fail(405, 'Method not allowed');
