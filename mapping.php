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

/**
 * Champs numeriques connus, avec leur borne haute. Un champ absent de cette
 * table n'est PAS rejete : il est ecrit tel quel. La liste sert a valider ce
 * qu'on connait, pas a interdire ce qu'on ne connait pas encore.
 *
 *   dur, cd     secondes           mit, mitMagic, mitPhys  pourcentages
 *   charges     nombre de charges  shieldStacks            piles (Haima, Panhaima)
 */
const NUMERIC_FIELDS = [
    'dur'          => 3600,
    'cd'           => 3600,
    'mit'          => 100,
    'mitMagic'     => 100,
    'mitPhys'      => 100,
    'charges'      => 10,
    'shieldStacks' => 20,
];

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

    // Les champs numeriques doivent etre des NOMBRES : le prompteur calcule
    // t + dur pour savoir si un effet court encore, et une chaine "15" y
    // concatenerait au lieu d'additionner. On coupe le probleme a l'entree.
    //
    // Un champ vide est retire, jamais stocke a zero : c'est pour ca qu'un
    // bouclier sans reduction doit OMETTRE `mit`. Les bornes ne sont pas
    // decoratives, elles rattrapent une virgule mal placee.
    foreach (NUMERIC_FIELDS as $k => $range) {
        if (!isset($entry[$k]) || $entry[$k] === '' || $entry[$k] === null) {
            unset($doc['abilities'][$id][$k]);
            continue;
        }
        if (!is_numeric($entry[$k])) {
            fail(400, "\"$k\" must be a number for $id.");
        }
        $v = (float) $entry[$k];
        if ($v <= 0 || $v > $range) {
            fail(400, "\"$k\" out of range for $id (expected 0 < v <= $range).");
        }
        $doc['abilities'][$id][$k] = ($v == (int) $v) ? (int) $v : $v;
    }

    // `type` et `scope` reprennent le vocabulaire de l'amont — mitigation, heal,
    // shield, invuln, utility / party, self, single, enemy. On valide la FORME,
    // pas une liste fermee : le jour ou l'amont invente une valeur, elle doit
    // passer sans qu'on ait a redeployer ce fichier.
    foreach (['type', 'scope'] as $k) {
        if (!isset($entry[$k]) || $entry[$k] === '' || $entry[$k] === null) {
            unset($doc['abilities'][$id][$k]);
            continue;
        }
        $v = strtolower(trim((string) $entry[$k]));
        if (!preg_match('/^[a-z][a-z0-9_-]{0,23}$/', $v)) {
            fail(400, "\"$k\" is not a plain lowercase word for $id.");
        }
        $doc['abilities'][$id][$k] = $v;
    }

    // `components` : les sorts qu'une action GENERIQUE designe reellement.
    // « Kitchen Sink », « Party Mit », « Buddy Mit » ne sont pas des actions du
    // jeu mais des raccourcis de langage, et selon le job ce sont deux ou trois
    // boutons differents. Deux formes acceptees, parce que les deux s'ecrivent
    // naturellement a la main :
    //
    //   "components": ["war_rampart", "war_vengeance"]           tous jobs
    //   "components": { "WAR": [...], "*": [...] }               par job
    //
    // On ne verifie PAS que les identifiants vises existent : la table s'edite
    // entree par entree, et refuser une composition parce que son dernier
    // composant n'est pas encore saisi bloquerait le travail en cours.
    if (isset($entry['components'])) {
        $c = $entry['components'];
        if (!is_array($c) || count($c) === 0) {
            unset($doc['abilities'][$id]['components']);
        } elseif (array_is_list($c)) {
            foreach ($c as $cid) {
                if (!is_string($cid) || !preg_match('/^[a-z0-9_]{1,64}$/', $cid)) {
                    fail(400, "\"components\" holds an invalid ability id for $id.");
                }
            }
        } else {
            // Une ligne vide est un job que l'editeur vient d'ouvrir et qu'on
            // n'a pas rempli : on l'elague ici plutot que de la stocker.
            $kept = [];
            foreach ($c as $job => $list) {
                if (!preg_match('/^([A-Z]{3}|\*)$/', (string) $job)) {
                    fail(400, "\"components\" key \"$job\" must be a 3-letter job or \"*\" for $id.");
                }
                if (!is_array($list) || !array_is_list($list)) {
                    fail(400, "\"components\" for $id / $job must be a list of ability ids.");
                }
                foreach ($list as $cid) {
                    if (!is_string($cid) || !preg_match('/^[a-z0-9_]{1,64}$/', $cid)) {
                        fail(400, "\"components\" holds an invalid ability id for $id / $job.");
                    }
                }
                if (count($list) > 0) {
                    $kept[$job] = array_values($list);
                }
            }
            if (count($kept) === 0) {
                unset($doc['abilities'][$id]['components']);
            } elseif (count($kept) === 1 && isset($kept['*'])) {
                $doc['abilities'][$id]['components'] = $kept['*'];
            } else {
                $doc['abilities'][$id]['components'] = $kept;
            }
        }
    }

    // Tout autre champ passe intact. C'est deliberé : la table est partagee par
    // deux sessions, et rejeter une cle inconnue ferait echouer la sauvegarde de
    // l'une des deux des que l'autre ajoute quelque chose.
}

/**
 * `aliases` : deux noms pour la meme action.
 *
 * L'amont ecrit parfois « party_mitigation » la ou la table dit
 * « tank_party_mit », et un plan importe arrive avec celui qu'il a. Ce
 * dictionnaire au niveau du document rattache l'un a l'autre.
 *
 * La CIBLE doit exister — un alias qui pointe dans le vide ne rattraperait
 * rien et se decouvrirait par une pastille grise, sans un mot. La SOURCE, elle,
 * n'a aucune raison d'exister : c'est justement l'identifiant inconnu.
 */
if (isset($doc['aliases'])) {
    if (!is_array($doc['aliases'])) {
        fail(400, '"aliases" must be an object of { unknownId: knownId }.');
    }
    foreach ($doc['aliases'] as $from => $to) {
        if (!is_string($from) || !preg_match('/^[a-z0-9_]{1,64}$/', (string) $from)) {
            fail(400, "Invalid alias source: $from");
        }
        if (!is_string($to) || !preg_match('/^[a-z0-9_]{1,64}$/', $to)) {
            fail(400, "Invalid alias target for $from.");
        }
        if (!isset($doc['abilities'][$to])) {
            fail(400, "Alias \"$from\" points at unknown ability \"$to\".");
        }
    }
    if (count($doc['aliases']) === 0) {
        unset($doc['aliases']);
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
//
// Le nom porte des MILLISECONDES et non des secondes : deux enregistrements dans
// la meme seconde — deux onglets, ou un script — retombaient sur le meme nom et
// le second copy() ecrasait la sauvegarde du premier. On perdait alors
// exactement l'etat qu'on voulait pouvoir retrouver.
if (is_file(MAPPING_FILE)) {
    if (!is_dir(BACKUP_DIR)) {
        @mkdir(BACKUP_DIR, 0755, true);
    }
    $stamp = date('Ymd-His') . '-' . substr(sprintf('%03d', (int) (microtime(true) * 1000) % 1000), 0, 3);
    @copy(MAPPING_FILE, BACKUP_DIR . '/mapping-' . $stamp . '.json');
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
