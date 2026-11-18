<?php
/**
 * feuilles-pdf.php — GREFFE : feuilles de marque complétées, une page par archer avec toutes ses distances.
 *
 *   ?archer=EnId   la feuille d'un archer ;
 *   ?club=CODE     les feuilles des archers d'un club (code vide : archers sans club) ;
 *   ?all=1         toutes les feuilles, rangées par club.
 * Départs (Sessions[]) et mise en page (ScorePageHeaderFooter, ScoreHeader, ScoreLogos, ScoreFlags) : ceux de la page
 * Feuilles de marque. Lecture seule.
 */
define('ENG_ACCESS', 'read');
require_once __DIR__ . '/lib/boot.php';
require_once __DIR__ . '/lib/scorecards.php';

if (!eng_sc_available()) {
    http_response_code(403);
    exit('Feuilles de marque : ScoreKeeper n\'est pas activé sur la compétition, ou la lecture des qualifications n\'est pas permise.');
}
$comp = eng_sc_competition();
if ($comp['field']) exit('Feuilles de marque : pas pour les parcours (campagne, nature, 3D).');

// Seulement les départs saisis flèche par flèche, et seulement quand plus aucun départ n'est en cours.
$ready = eng_sc_ready_sessions(eng_sc_sessions(), $comp['distances']);
if (!$ready) exit('Feuilles de marque : pas encore disponibles (saisie des flèches en cours, ou aucun départ saisi par ScoreKeeper).');
$opts = eng_sc_options($_GET, $ready);
if (isset($_GET['archer'])) {
    $filter = ['archer' => (int) $_GET['archer']];
} elseif (isset($_GET['club'])) {
    $filter = ['club' => trim((string) $_GET['club'])];
} else {
    $filter = [];
}
$cards = eng_sc_read_cards($opts['sessions'], $filter);
if (!$cards) exit('Aucune feuille de marque sur les départs choisis.');

if (isset($filter['archer'])) {
    $name = eng_sc_ascii($cards[0]['Ath']) . '_' . eng_sc_ascii($cards[0]['EnCode']);
} elseif (isset($filter['club'])) {
    $name = eng_sc_file_name($cards[0], 'club');   // « 9000001_Club_Exemple », comme dans l'archive
} else {
    $name = eng_sc_ascii($_SESSION['TourCode'] ?? '') ?: 'competition';
}

@set_time_limit(300);
$pdf = eng_sc_new_pdf($opts);
eng_sc_draw($pdf, $cards, $comp['distances']);
$pdf->Output('Feuilles-' . trim($name, '_') . '.pdf', 'I');
