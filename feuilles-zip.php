<?php
/**
 * feuilles-zip.php — GREFFE : archive ZIP des feuilles de marque complétées, un PDF par club ou par archer.
 *
 * Construite par petites étapes (lib/scorecards.php) :
 *   POST do=start  (options de la page) → identifiant et nombre de fichiers ;
 *   POST do=step   job=…                → quelques secondes de dessin, avancement ;
 *   POST do=finish job=…                → archive rangée, adresse de téléchargement ;
 *   GET  dl=…                           → téléchargement, puis suppression des fichiers.
 * Les POST portent le jeton anti-CSRF de la session. Lecture seule en base.
 */
define('ENG_ACCESS', 'read');
require_once __DIR__ . '/lib/boot.php';
require_once __DIR__ . '/lib/scorecards.php';

/** Secondes de dessin par étape : bien en dessous des limites de temps habituelles. */
const ENG_SC_STEP_SECONDS = 5;

/** Réponse JSON et fin. */
function eng_sc_json(array $data)
{
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data);
    exit;
}

if (!eng_sc_available()) eng_sc_json(['error' => 1, 'msg' => 'ScoreKeeper n\'est pas activé sur la compétition, ou la lecture des qualifications n\'est pas permise.']);

// ── Téléchargement ──
if (isset($_GET['dl'])) {
    $job  = (string) $_GET['dl'];
    $data = eng_sc_job_load($job);
    $zip  = $data ? eng_sc_job_dir($job) . DIRECTORY_SEPARATOR . 'archive.zip' : '';
    if (!$data || !is_file($zip)) exit('Archive introuvable ou déjà téléchargée : relancez-la depuis la page Feuilles de marque.');
    unset($_SESSION['ENG_SC_JOBS'][$job]);
    session_write_close();
    ignore_user_abort(true);
    @set_time_limit(0);
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $data['zip'] . '"');
    header('Content-Length: ' . filesize($zip));
    header('Cache-Control: no-store');
    readfile($zip);
    eng_sc_job_remove($job);
    exit;
}

eng_check_token();
$do = (string) ($_POST['do'] ?? '');

if ($do === 'start') {
    $comp = eng_sc_competition();
    if ($comp['field']) eng_sc_json(['error' => 1, 'msg' => 'Pas pour les parcours (campagne, nature, 3D).']);
    if (!class_exists('ZipArchive')) eng_sc_json(['error' => 1, 'msg' => 'L\'extension « zip » de PHP manque sur ce serveur.']);
    $opts  = eng_sc_options($_POST, eng_sc_sessions());
    $mode  = ($_POST['Mode'] ?? '') === 'archer' ? 'archer' : 'club';
    $files = eng_sc_split(eng_sc_read_cards($opts['sessions']), $mode);
    if (!$files) eng_sc_json(['error' => 1, 'msg' => 'Aucune feuille de marque sur les départs choisis.']);
    $zipName = 'Feuilles-' . (eng_sc_ascii($_SESSION['TourCode'] ?? '') ?: 'competition') . '-' . ($mode === 'archer' ? 'archers' : 'clubs') . '.zip';
    $job = eng_sc_job_create(['opts' => $opts, 'dist' => $comp['distances'], 'names' => array_keys($files), 'zip' => $zipName], $files);
    if ($job === '') eng_sc_json(['error' => 1, 'msg' => 'Impossible d\'écrire dans le répertoire temporaire du serveur.']);
    eng_sc_json(['error' => 0, 'job' => $job, 'done' => 0, 'total' => count($files)]);
}

$job  = (string) ($_POST['job'] ?? '');
$data = eng_sc_job_load($job);
if (!$data) eng_sc_json(['error' => 1, 'msg' => 'Archive introuvable : relancez-la.']);
// Plus rien n'écrit dans la session : la libérer, pour ne pas bloquer les autres pages du même navigateur.
session_write_close();
@set_time_limit(120);
$total = count($data['names']);

if ($do === 'step') {
    $started = microtime(true);
    $files   = eng_sc_job_files($job);
    if ($files === null) eng_sc_json(['error' => 1, 'msg' => 'Archive introuvable : relancez-la.']);
    $dir = eng_sc_job_dir($job) . DIRECTORY_SEPARATOR . 'files' . DIRECTORY_SEPARATOR;
    // Un document réglé une fois puis copié pour chaque fichier (le réglage lit l'en-tête de la compétition).
    $model = eng_sc_new_pdf($data['opts']);
    while ($data['next'] < $total && microtime(true) - $started < ENG_SC_STEP_SECONDS) {
        $name = $data['names'][$data['next']];
        $pdf  = clone $model;
        eng_sc_draw($pdf, $files[$name], (int) $data['dist']);
        $pdf->Output($dir . $name . '.pdf', 'F');
        unset($pdf);
        $data['next']++;
    }
    if (!eng_sc_job_save($job, $data)) eng_sc_json(['error' => 1, 'msg' => 'Impossible d\'écrire dans le répertoire temporaire du serveur.']);
    eng_sc_json(['error' => 0, 'done' => $data['next'], 'total' => $total]);
}

if ($do === 'finish') {
    if ($data['next'] < $total) eng_sc_json(['error' => 1, 'msg' => 'Archive incomplète : relancez-la.']);
    if (eng_sc_job_zip($job) === '') eng_sc_json(['error' => 1, 'msg' => 'Impossible de construire l\'archive.']);
    eng_sc_json(['error' => 0, 'url' => $ENG_ROOT . 'feuilles-zip.php?dl=' . $job]);
}

eng_sc_json(['error' => 1, 'msg' => 'Demande inconnue.']);
