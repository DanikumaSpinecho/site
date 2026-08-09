<?php
/**
 * Synchronisation d'equipe pour Mitigoke.
 *
 * Le raid leader cree un salon, partage le lien, et son « Depart » lance le
 * chronometre de tout le monde. Aucun compte, aucun mot de passe.
 *
 * Ce qui circule tient en quelques champs : le salon ne memorise pas une horloge
 * qui tourne, mais l'INSTANT SERVEUR ou le combat atteint 00:00. Chaque client en
 * deduit sa propre position et devient autonome — on ne synchronise donc qu'une
 * fois par pull, pas soixante fois par seconde.
 *
 * Le piege, avec plusieurs machines, c'est la derive des horloges systeme : deux
 * joueurs peuvent etre a plusieurs secondes l'un de l'autre. On ne fait donc
 * jamais confiance a l'horloge du client. Chaque reponse porte « now », l'heure du
 * serveur, et le client en deduit son ecart par la methode NTP simplifiee :
 *
 *     ecart ~= now - (envoi + reception) / 2
 *
 * L'erreur est bornee par la moitie de l'aller-retour, soit moins de 100 ms en
 * pratique — invisible a l'echelle d'un preavis de cinq secondes.
 *
 *   GET  sync.php?now=1              -> juste l'heure serveur (sonde d'etalonnage)
 *   GET  sync.php?room=static-k7m2qp -> etat du salon
 *   POST sync.php  action=create     -> cree un salon, rend son id et la cle leader
 *   POST sync.php  action=state      -> ecrit l'etat (cle leader obligatoire)
 */

declare(strict_types=1);

const SYNC_DIR   = __DIR__ . '/.sync';
const ROOM_TTL   = 43200;   // 12 h : au-dela, un salon n'interesse plus personne
const MAX_ROOMS  = 500;     // garde-fou, largement au-dessus d'un usage normal
const MAX_BODY   = 4096;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function out(array $payload): never {
    // « now » accompagne CHAQUE reponse : c'est ce qui permet au client de se
    // recaler sur l'horloge du serveur sans endpoint dedie.
    $payload['now'] = round(microtime(true) * 1000);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function fail(int $code, string $message): never {
    http_response_code($code);
    out(['error' => $message]);
}

function roomFile(string $room): string {
    return SYNC_DIR . '/' . $room . '.json';
}

function readRoom(string $room): ?array {
    $f = roomFile($room);
    if (!is_file($f)) {
        return null;
    }
    $d = json_decode((string) file_get_contents($f), true);
    return is_array($d) ? $d : null;
}

/** Ecriture atomique : un lecteur ne doit jamais tomber sur un fichier a moitie ecrit. */
function writeRoom(string $room, array $data): bool {
    if (!is_dir(SYNC_DIR)) {
        @mkdir(SYNC_DIR, 0755, true);
    }
    $f   = roomFile($room);
    $tmp = $f . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (@file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE)) === false) {
        return false;
    }
    if (!@rename($tmp, $f)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

/** Balayage des salons perimes. Appele a la creation seulement : c'est assez. */
function sweep(): int {
    if (!is_dir(SYNC_DIR)) {
        return 0;
    }
    $n   = 0;
    $now = time();
    foreach (glob(SYNC_DIR . '/*.json') ?: [] as $f) {
        if ($now - filemtime($f) > ROOM_TTL) {
            @unlink($f);
        } else {
            $n++;
        }
    }
    foreach (glob(SYNC_DIR . '/*.tmp') ?: [] as $f) {
        if ($now - filemtime($f) > 60) {
            @unlink($f);   // residu d'une ecriture interrompue
        }
    }
    return $n;
}

/** Un identifiant de salon : « nomdequipe-k7m2qp ». */
function makeRoomId(string $team): string {
    $slug = strtolower($team);
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
    $slug = trim($slug, '-');
    if ($slug === '') {
        $slug = 'party';
    }
    $slug = substr($slug, 0, 24);

    // Le nom d'equipe se devine ; les six caracteres tires au sort, non. C'est eux
    // qui empechent un inconnu de tomber sur le salon et d'en perturber le chrono.
    $alphabet = 'abcdefghijkmnpqrstuvwxyz23456789';   // sans l, o, 0, 1 : ambigus a l'oral
    $suffix   = '';
    for ($i = 0; $i < 6; $i++) {
        $suffix .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $slug . '-' . $suffix;
}

function publicView(string $room, array $r): array {
    return [
        'room'     => $room,
        'team'     => $r['team'] ?? '',
        'plan'     => $r['plan'] ?? '',
        't0'       => $r['t0'] ?? null,        // instant serveur du 00:00, en ms
        'paused'   => (bool) ($r['paused'] ?? false),
        'pausedAt' => $r['pausedAt'] ?? null,  // temps de combat fige, en secondes
        'offset'   => $r['offset'] ?? 0,
        'rev'      => $r['rev'] ?? 0,
    ];
}

// --- Lecture -------------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_GET['now'])) {
        out(['ok' => true]);
    }
    if (isset($_GET['room'])) {
        $room = (string) $_GET['room'];
        if (!preg_match('/^[a-z0-9-]{3,32}$/', $room)) {
            fail(400, 'Invalid room identifier.');
        }
        $r = readRoom($room);
        if ($r === null) {
            fail(404, 'Room not found or expired.');
        }
        out(publicView($room, $r));
    }
    fail(400, 'Expected parameter: room or now.');
}

// --- Ecriture ------------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail(405, 'Method not allowed.');
}

$raw = file_get_contents('php://input');
if ($raw === false || strlen($raw) > MAX_BODY) {
    fail(400, 'Invalid request body.');
}
$in = json_decode($raw, true);
if (!is_array($in)) {
    fail(400, 'JSON expected.');
}
$action = (string) ($in['action'] ?? '');

if ($action === 'create') {
    $team = trim((string) ($in['team'] ?? ''));
    $plan = strtoupper(trim((string) ($in['plan'] ?? '')));
    if ($team === '' || mb_strlen($team) > 40) {
        fail(400, 'Invalid party name.');
    }
    if (!preg_match('/^[A-Z0-9]{1,12}-[A-Z0-9]{1,16}$/', $plan)) {
        fail(400, 'Invalid plan code.');
    }
    if (sweep() >= MAX_ROOMS) {
        fail(503, 'Too many open rooms, try again later.');
    }

    // La cle leader n'est rendue qu'ici, une seule fois. Le salon n'en garde que
    // l'empreinte : meme en lisant le fichier on ne peut pas reprendre la main.
    $key  = bin2hex(random_bytes(16));
    $room = makeRoomId($team);
    for ($try = 0; is_file(roomFile($room)) && $try < 5; $try++) {
        $room = makeRoomId($team);
    }

    $data = [
        'team'     => $team,
        'plan'     => $plan,
        't0'       => null,
        'paused'   => false,
        'pausedAt' => null,
        'offset'   => 0,
        'rev'      => 1,
        'keyHash'  => hash('sha256', $key),
        'created'  => time(),
    ];
    if (!writeRoom($room, $data)) {
        fail(500, 'Could not create the room.');
    }
    out(['room' => $room, 'leaderKey' => $key] + publicView($room, $data));
}

if ($action === 'state') {
    $room = (string) ($in['room'] ?? '');
    $key  = (string) ($in['key'] ?? '');
    if (!preg_match('/^[a-z0-9-]{3,32}$/', $room)) {
        fail(400, 'Invalid room identifier.');
    }
    $r = readRoom($room);
    if ($r === null) {
        fail(404, 'Room not found or expired.');
    }
    if (!hash_equals((string) ($r['keyHash'] ?? ''), hash('sha256', $key))) {
        fail(403, 'Only the party leader can drive the clock.');
    }

    // Le client envoie un delai, jamais un horodatage : son horloge n'a pas
    // autorite. C'est le serveur qui date le depart, donc tout le monde partage
    // la meme reference, quelles que soient les montres des uns et des autres.
    if (array_key_exists('startIn', $in)) {
        $startIn = (float) $in['startIn'];
        if ($startIn < -3600 || $startIn > 3600) {
            fail(400, 'Start delay out of range.');
        }
        $r['t0']       = round(microtime(true) * 1000) + round($startIn * 1000);
        $r['paused']   = false;
        $r['pausedAt'] = null;
    }
    if (array_key_exists('pausedAt', $in)) {
        $r['pausedAt'] = $in['pausedAt'] === null ? null : (float) $in['pausedAt'];
        $r['paused']   = $r['pausedAt'] !== null;
    }
    if (array_key_exists('stop', $in) && $in['stop']) {
        $r['t0']       = null;
        $r['paused']   = false;
        $r['pausedAt'] = null;
    }
    if (array_key_exists('offset', $in)) {
        $off = (float) $in['offset'];
        $r['offset'] = max(-60, min(60, $off));
    }

    $r['rev'] = (int) ($r['rev'] ?? 0) + 1;
    if (!writeRoom($room, $r)) {
        fail(500, 'Could not save.');
    }
    out(publicView($room, $r));
}

fail(400, 'Unknown action.');
