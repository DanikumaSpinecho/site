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
 *   api.php?plan=…&refresh=1   -> le meme, en forcant la relecture en amont
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

// --- Plans les plus demandes ---------------------------------------------------
// Les plans reellement utilises ici sont une poignee, toujours les memes : ceux
// que les equipes collent semaine apres semaine. Leur donner un cache long divise
// d'autant les appels chez xivmit — et, accessoirement, garde une copie a jour de
// ce qui compte le jour ou l'amont serait injoignable.
//
// Le prix de ce cache long, c'est qu'une modification met jusqu'a une heure a
// apparaitre : d'ou le « refresh », qui existe pour cette raison precise.
const TTL_PLAN_HOT = 3600;      // 1 h pour les plans du classement
const HOT_COUNT    = 5;         // combien en beneficient
const POP_FILE     = CACHE_DIR . '/popular.json';
const POP_KEEP     = 200;       // entrees conservees dans le classement
const POP_TTL      = 2592000;   // 30 j sans demande : le plan en sort

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

function cacheFile(string $key): string {
    return CACHE_DIR . '/' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $key) . '.json';
}

/**
 * Cache disque : rend la valeur memorisee si elle est encore fraiche.
 * $force saute la lecture du cache mais pas son ecriture — et le repli sur le
 * cache perime reste actif, sinon un « refresh » pendant une panne amont
 * renverrait une erreur la ou on avait encore une copie utilisable.
 */
function cached(string $key, int $ttl, callable $producer, bool $force = false): ?array {
    if (!is_dir(CACHE_DIR)) {
        @mkdir(CACHE_DIR, 0755, true);
    }
    $file = cacheFile($key);

    if (!$force && is_file($file) && (time() - filemtime($file)) < $ttl) {
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

// --- Classement des plans ------------------------------------------------------

function popRead(): array {
    if (!is_file(POP_FILE)) {
        return [];
    }
    $d = json_decode((string) file_get_contents(POP_FILE), true);
    return is_array($d) ? $d : [];
}

function popWrite(array $pop): void {
    if (!is_dir(CACHE_DIR)) {
        @mkdir(CACHE_DIR, 0755, true);
    }
    $tmp = POP_FILE . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (@file_put_contents($tmp, json_encode($pop, JSON_UNESCAPED_UNICODE)) === false) {
        return;
    }
    if (!@rename($tmp, POP_FILE)) {
        @unlink($tmp);
    }
}

/** Du plus demande au moins demande. */
function popSorted(array $pop): array {
    uasort($pop, fn(array $a, array $b): int => ($b['n'] ?? 0) <=> ($a['n'] ?? 0));
    return $pop;
}

/**
 * Enregistre une demande et rend le classement a jour.
 *
 * Lecture-modification-ecriture sans verrou : deux requetes simultanees peuvent
 * se perdre un increment. C'est assume — on cherche un ordre de grandeur, pas une
 * comptabilite, et un verrou couterait plus cher que ce qu'il protegerait.
 */
function popBump(string $code): array {
    $pop = popRead();
    $now = time();
    $e   = $pop[$code] ?? ['n' => 0, 'first' => $now];
    $e['n']    = (int) ($e['n'] ?? 0) + 1;
    $e['last'] = $now;
    $pop[$code] = $e;

    foreach ($pop as $k => $v) {
        if ($now - (int) ($v['last'] ?? 0) > POP_TTL) {
            unset($pop[$k]);   // plus demande depuis un mois : hors classement
        }
    }
    if (count($pop) > POP_KEEP) {
        $pop = array_slice(popSorted($pop), 0, POP_KEEP, true);
    }
    popWrite($pop);
    return $pop;
}

/** @return string[] les HOT_COUNT codes les plus demandes. */
function hotCodes(array $pop): array {
    return array_slice(array_keys(popSorted($pop)), 0, HOT_COUNT);
}

// --- Routage -----------------------------------------------------------------

if (isset($_GET['plan'])) {
    $code = strtoupper(trim((string) $_GET['plan']));
    if (!preg_match('/^[A-Z0-9]{1,12}-[A-Z0-9]{1,16}$/', $code)) {
        fail(400, 'Invalid plan code.');
    }
    $hot = in_array($code, hotCodes(popRead()), true);
    header('X-Plan-Cache: ' . ($hot ? 'hot' : 'normal'));

    $data = cached(
        "plan_$code",
        $hot ? TTL_PLAN_HOT : TTL_PLAN,
        fn() => fetchUpstream("/api/plans/$code"),
        isset($_GET['refresh'])
    );
    if ($data === null) {
        fail(404, "Plan $code not found.");
    }
    // Le classement ne s'incremente qu'ici : une faute de frappe dans un code
    // occuperait sinon une des cinq places, sans jamais rien avoir a mettre en
    // cache. Le rang est donc lu avant, et ecrit apres.
    popBump($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// Le classement reste INTERNE : il pilote le cache long, il ne se consulte pas.
// L'ancien point d'entree « ?popular=1 » rendait les codes de plan en clair.
// Ces codes sont deja publics sur xivmit.app et ne designent personne, mais ils
// sont indevinables : les publier sur un point d'entree ouvert revenait a les
// rendre devinables, et ce sont les plans d'autres gens. Retire le 28 aout 2026,
// au moment de rendre le site public.

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
