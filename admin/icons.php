<?php
/**
 * Recherche d'icones, pour la page admin/ uniquement.
 *
 * Le navigateur ne sait pas lister un dossier : sans cet endpoint, choisir une
 * icone reviendrait a en taper le chemin de memoire. Lecture seule, resultats
 * bornes, et rien d'autre que les deux dossiers d'images du site n'est visible.
 *
 *   icons.php?q=reprisal        -> chemins contenant « reprisal »
 *   icons.php?q=holmgang&n=5    -> au plus 5 resultats
 */

declare(strict_types=1);

const ROOTS = ['icons', 'jobs-new'];
const MAX_HITS = 40;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$q = strtolower(trim((string) ($_GET['q'] ?? '')));
$n = (int) ($_GET['n'] ?? MAX_HITS);
$n = max(1, min(MAX_HITS, $n));

if (strlen($q) < 2) {
    echo json_encode(['results' => [], 'error' => 'Two characters minimum.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$hits = [];
foreach (ROOTS as $root) {
    // admin/ est un cran sous la racine du site depuis le 28 aout 2026.
    $dir = dirname(__DIR__) . '/' . $root;
    if (!is_dir($dir)) {
        continue;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $f) {
        if (!$f->isFile()) {
            continue;
        }
        if (!preg_match('/\.(png|jpg|webp|svg)$/i', $f->getFilename())) {
            continue;
        }
        // On cherche dans le nom de fichier, pas dans le chemin : sinon taper
        // « pld » renverrait les 90 icones du dossier 01_PLD.
        if (strpos(strtolower($f->getFilename()), $q) === false) {
            continue;
        }
        $hits[] = $root . '/' . str_replace('\\', '/', substr($f->getPathname(), strlen($dir) + 1));
        if (count($hits) >= $n) {
            break 2;
        }
    }
}

sort($hits);
echo json_encode(['results' => $hits], JSON_UNESCAPED_UNICODE);
