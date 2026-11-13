<?php
/**
 * categories.php — GREFFE : liste des participants groupés par catégorie, aérée (PDF).
 *
 *   ?depart=<n>    seulement les archers de ce départ (défaut : tous les départs)
 *   ?page=1        une page par catégorie (à afficher)
 *
 * Équivalent de l'état d'IANSEO Partecipants/PrnCategory.php?tf=1 ; mise en page commune : lib/participants.php.
 * Catégories dans l'ordre de l'état d'IANSEO (code arme puis classe : BBS2H, CLS1F…), titrées « S1HCL — Arc Classique
 * Senior 1 Hommes » comme dans le reste du module ; archers par ordre alphabétique.
 */
define('ENG_ACCESS', 'read');
require_once __DIR__ . '/lib/boot.php';
require_once __DIR__ . '/lib/participants.php';

$tour  = eng_tournament();
$scope = eng_part_scope($tour);

$groups = [];
foreach (eng_part_load($ENG_TOUR, $scope) as $row) {
    $k = $row['cat_order'] . '|' . $row['cat'];
    if (!isset($groups[$k])) {
        $groups[$k] = ['title' => $row['cat'] . ($row['cat_label'] !== '' ? ' — ' . $row['cat_label'] : ''), 'rows' => []];
    }
    $groups[$k]['rows'][] = $row;
}
ksort($groups, SORT_STRING);
foreach ($groups as &$g) eng_part_sort($g['rows']);
unset($g);

$n = count($groups);
eng_part_pdf('Liste des participants par catégories', array_values($groups),
    [['name', 'Archer', 30, 'L'], ['code', 'Licence', 12, 'L'], ['club', 'Club', 28, 'L'], ['ses', 'Départ', 8, 'L'],
     ['target', 'Cible', 8, 'L'], ['face', 'Blason', 12, 'L']],
    ['scope' => $scope, 'sessions' => $tour->Sessions, 'perPage' => !empty($_GET['page']), 'file' => 'participants-par-categories',
     'count' => $n . ' catégorie' . ($n > 1 ? 's' : '') . ' · ']);
