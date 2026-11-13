<?php
/**
 * alphabetique.php — GREFFE : liste des participants par ordre alphabétique, aérée (PDF).
 *
 *   ?depart=<n>    seulement les archers de ce départ (défaut : tous les départs)
 *
 * Équivalent de l'état d'IANSEO Partecipants/PrnAlphabetical.php?tf=1 ; mise en page commune : lib/participants.php.
 * Un groupe par initiale du nom de famille (« A », « B »…), accents ignorés.
 */
define('ENG_ACCESS', 'read');
require_once __DIR__ . '/lib/boot.php';
require_once __DIR__ . '/lib/participants.php';

$tour  = eng_tournament();
$scope = eng_part_scope($tour);

$groups = [];
foreach (eng_part_load($ENG_TOUR, $scope) as $row) {
    // Initiale sans accent (« É » avec les « E » ; le nom est déjà en capitales) ; autre caractère : groupe « # ».
    $c = strtr(mb_substr($row['name'], 0, 1), ['À' => 'A', 'Â' => 'A', 'Ä' => 'A', 'Ç' => 'C', 'É' => 'E', 'È' => 'E', 'Ê' => 'E',
        'Ë' => 'E', 'Î' => 'I', 'Ï' => 'I', 'Ô' => 'O', 'Ö' => 'O', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U', 'Ÿ' => 'Y']);
    $letter = preg_match('/^[A-Z]$/', $c) ? $c : '#';
    $groups[$letter]['title'] = $letter;
    $groups[$letter]['rows'][] = $row;
}
ksort($groups, SORT_STRING);
foreach ($groups as &$g) eng_part_sort($g['rows']);
unset($g);

eng_part_pdf('Liste des participants par ordre alphabétique', array_values($groups),
    [['name', 'Archer', 28, 'L'], ['code', 'Licence', 11, 'L'], ['club', 'Club', 25, 'L'], ['cat', 'Catégorie', 11, 'L'],
     ['ses', 'Départ', 8, 'L'], ['target', 'Cible', 7, 'L'], ['face', 'Blason', 11, 'L']],
    ['scope' => $scope, 'sessions' => $tour->Sessions, 'perPage' => false, 'file' => 'participants-alphabetique']);
