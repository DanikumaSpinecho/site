<?php
/**
 * Proxy minimal vers l'API publique de xivmit.app.
 *
 * Raison d'etre : xivmit.app ne renvoie pas d'en-tete CORS, donc un fetch
 * direct depuis le navigateur est bloque. On relaie cote serveur, ou la
 * politique d'origine ne s'applique pas.
 *
 * Trois actions seulement, sans chemin libre : aucune URL arbitraire ne peut
 * etre demandee au proxy (pas de surface SSRF).
 *
 *   api.php?plan=UMAD-G0FF1B   -> le plan partage
 *   api.php?fight=umad         -> le combat (phases, mecaniques, boss actions)
 *   api.php?abilities=1        -> toutes les capacites, fusionnees en un objet
 *
 * Les reponses sont mises en cache sur disque pour ne pas solliciter
 * inutilement leur serveur.
 */

declare(strict_types=1);

const UPSTREAM   = 'https://xivmit.app';
const CACHE_DIR  = __DIR__ . '/.cache';
const TTL_PLAN   = 300;    // 5 min : un plan peut etre modifie
const TTL_STATIC = 86400;  // 24 h : combats et capacites bougent rarement

header('Content-Type: application/json; charset=utf-8');

function fail(int $code, string $message): never {
    http_response_code($code);
    echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

/** Recupere une URL amont et renvoie le corps decode, ou null en cas d'echec. */
function fetchUpstream(string $path): ?array {
    $ch = curl_init(UPSTREAM . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_USERAGENT      => 'mitigoke/1.0 (+prompteur de mitigations, usage prive)',
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);
    $body   = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($body === false || $status !== 200) {
        return null;
    }
    $decoded = json_decode($body, true);
    return is_array($decoded) ? $decoded : null;
}

/** Cache disque : rend la valeur memorisee si elle est encore fraiche. */
function cached(string $key, int $ttl, callable $producer): ?array {
    if (!is_dir(CACHE_DIR)) {
        @mkdir(CACHE_DIR, 0755, true);
    }
    $file = CACHE_DIR . '/' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $key) . '.json';

    if (is_file($file) && (time() - filemtime($file)) < $ttl) {
        $hit = json_decode((string) file_get_contents($file), true);
        if (is_array($hit)) {
            header('X-Cache: HIT');
            return $hit;
        }
    }

    $fresh = $producer();
    if ($fresh !== null) {
        @file_put_contents($file, json_encode($fresh, JSON_UNESCAPED_UNICODE));
        header('X-Cache: MISS');
        return $fresh;
    }

    // Amont injoignable : on sert le cache perime plutot que rien.
    if (is_file($file)) {
        $stale = json_decode((string) file_get_contents($file), true);
        if (is_array($stale)) {
            header('X-Cache: STALE');
            return $stale;
        }
    }
    return null;
}

// --- Routage -----------------------------------------------------------------

if (isset($_GET['plan'])) {
    $code = strtoupper(trim((string) $_GET['plan']));
    if (!preg_match('/^[A-Z0-9]{1,12}-[A-Z0-9]{1,16}$/', $code)) {
        fail(400, 'Invalid plan code.');
    }
    $data = cached("plan_$code", TTL_PLAN, fn() => fetchUpstream("/api/plans/$code"));
    if ($data === null) {
        fail(404, "Plan $code not found.");
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if (isset($_GET['fight'])) {
    $id = strtolower(trim((string) $_GET['fight']));
    if (!preg_match('/^[a-z0-9_-]{1,32}$/', $id)) {
        fail(400, 'Invalid fight identifier.');
    }
    $data = cached("fight_$id", TTL_STATIC, fn() => fetchUpstream("/api/fights/$id"));
    if ($data === null) {
        fail(404, "Fight $id not found.");
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if (isset($_GET['abilities'])) {
    $data = cached('abilities', TTL_STATIC, function (): ?array {
        $jobs = fetchUpstream('/api/jobs');
        if ($jobs === null) {
            return null;
        }
        $map = [];
        foreach ($jobs as $job) {
            $abbr = strtolower((string) ($job['abbr'] ?? ''));
            if ($abbr === '') {
                continue;
            }
            foreach (fetchUpstream('/api/jobs/' . $abbr) ?? [] as $ability) {
                if (isset($ability['id'])) {
                    $map[$ability['id']] = $ability;
                }
            }
        }
        return $map ?: null;
    });
    if ($data === null) {
        fail(502, 'Ability catalogue unavailable.');
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

fail(400, 'Expected parameter: plan, fight or abilities.');
