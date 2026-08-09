<?php
/**
 * Enregistrement de la table de correspondance des capacites (mapping.json).
 *
 * La LECTURE ne passe pas par ici : le prompteur charge mapping.json directement,
 * en fichier statique, sans faire tourner PHP. Ce script ne sert qu'a l'ecriture
 * depuis la page admin/.
 *
 * ATTENTION — en developpement, cet endpoint est OUVERT : n'importe qui connaissant
 * son adresse peut reecrire la table. C'est un choix assume le temps de la mise au
 * point. Pour le fermer, proteger le dossier admin/ ET ce fichier par mot de passe
 * depuis hPanel (Avance -> Proteger les repertoires par mot de passe), ou definir
 * MAPPING_TOKEN ci-dessous.
 */

declare(strict_types=1);

const MAPPING_FILE  = __DIR__ . '/mapping.json';
const BACKUP_DIR    = __DIR__ . '/.mapping-backups';
const MAX_BODY      = 1048576;   // 1 Mo : la table en fait 30 ko
const KEEP_BACKUPS  = 10;
const MAPPING_TOKEN = '';        // vide = ouvert (developpement)

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function fail(int $code, string $message): never {
    http_response_code($code);
    echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail(405, 'POST expected.');
}

if (MAPPING_TOKEN !== '') {
    $given = (string) ($_SERVER['HTTP_X_MAPPING_TOKEN'] ?? '');
    if (!hash_equals(MAPPING_TOKEN, $given)) {
        fail(403, 'Invalid token.');
    }
}

$raw = file_get_contents('php://input');
if ($raw === false || $raw === '' || strlen($raw) > MAX_BODY) {
    fail(400, 'Invalid request body.');
}

$doc = json_decode($raw, true);
if (!is_array($doc) || !isset($doc['abilities']) || !is_array($doc['abilities'])) {
    fail(400, 'Expected structure: { version, langs, jobs, abilities }.');
}
if (count($doc['abilities']) === 0) {
    fail(400, 'Refusing to write an empty table.');
}

/**
 * Un chemin d'icone doit rester dans les dossiers d'images du site. On refuse tout
 * ce qui remonte l'arborescence ou sort des deux racines prevues : sans mot de
 * passe sur cet endpoint, c'est la seule barriere qui compte vraiment.
 */
function iconOk(?string $p): bool {
    if ($p === null || $p === '') {
        return true;      // pas d'icone : parfaitement legitime
    }
    if (strpos($p, '..') !== false || strpos($p, '\\') !== false || $p[0] === '/') {
        return false;
    }
    if (!preg_match('#^(icons/|jobs-new/)#', $p)) {
        return false;
    }
    return (bool) preg_match('#^[A-Za-z0-9 _()\'./-]+\.(png|jpg|webp|svg)$#', $p);
}

foreach ($doc['abilities'] as $id => $entry) {
    if (!is_string($id) || !preg_match('/^[a-z0-9_]{1,64}$/', $id)) {
        fail(400, "Invalid ability identifier: $id");
    }
    if (!is_array($entry)) {
        fail(400, "Invalid entry for $id.");
    }
    if (!iconOk(isset($entry['icon']) ? (string) $entry['icon'] : null)) {
        fail(400, "Icon path rejected for $id.");
    }
    foreach ((array) ($entry['byJob'] ?? []) as $job => $path) {
        if (!preg_match('/^[A-Z]{3}$/', (string) $job) || !iconOk((string) $path)) {
            fail(400, "Per-job icon rejected for $id.");
        }
    }
}

foreach ((array) ($doc['jobs'] ?? []) as $job => $j) {
    if (!preg_match('/^[A-Z]{3}$/', (string) $job)) {
        fail(400, "Invalid job code: $job");
    }
    if (!iconOk(isset($j['icon']) ? (string) $j['icon'] : null)) {
        fail(400, "Job icon path rejected for $job.");
    }
}

// Sauvegarde de la version precedente : l'endpoint est ouvert, une bevue doit
// rester rattrapable.
if (is_file(MAPPING_FILE)) {
    if (!is_dir(BACKUP_DIR)) {
        @mkdir(BACKUP_DIR, 0755, true);
    }
    @copy(MAPPING_FILE, BACKUP_DIR . '/mapping-' . date('Ymd-His') . '.json');
    $old = glob(BACKUP_DIR . '/mapping-*.json') ?: [];
    sort($old);
    foreach (array_slice($old, 0, max(0, count($old) - KEEP_BACKUPS)) as $f) {
        @unlink($f);
    }
}

$doc['version']   = 1;
$doc['generated'] = date('Y-m-d');

// Ecriture atomique : le prompteur peut lire mapping.json au meme instant.
$tmp = MAPPING_FILE . '.' . bin2hex(random_bytes(4)) . '.tmp';
if (@file_put_contents($tmp, json_encode($doc, JSON_UNESCAPED_UNICODE)) === false || !@rename($tmp, MAPPING_FILE)) {
    @unlink($tmp);
    fail(500, 'Could not write.');
}

echo json_encode([
    'ok'        => true,
    'abilities' => count($doc['abilities']),
    'generated' => $doc['generated'],
], JSON_UNESCAPED_UNICODE);
