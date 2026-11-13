<?php
/**
 * clubs.php — GREFFE : liste des participants par clubs, aérée (PDF).
 *
 *   ?depart=<n>    seulement les archers de ce départ (défaut : tous les départs)
 *   ?page=1        une page par club (à remettre à chaque club)
 *
 * Équivalent de l'état d'IANSEO Partecipants/PrnCountry.php?tf=1 ; mise en page commune : lib/participants.php.
 * Bandeau de chaque club avec son logo (table Flags, comme les feuilles de marque). Clubs et archers par ordre
 * alphabétique.
 */
define('ENG_ACCESS', 'read');
require_once __DIR__ . '/lib/boot.php';
require_once __DIR__ . '/lib/participants.php';

$tour  = eng_tournament();
$scope = eng_part_scope($tour);

$groups = [];
foreach (eng_part_load($ENG_TOUR, $scope) as $row) {
    $k = $row['club_code'] . '|' . $row['club_name'];
    if (!isset($groups[$k])) {
        $title = ($row['club_name'] !== '' ? $row['club_name'] : 'Sans club') . ($row['club_code'] !== '' ? '  (' . $row['club_code'] . ')' : '');
        $groups[$k] = ['title' => $title, 'code' => $row['club_code'], 'name' => $row['club_name'], 'rows' => []];
    }
    $groups[$k]['rows'][] = $row;
}
uasort($groups, function ($x, $y) {
    return ($x['name'] === '') <=> ($y['name'] === '') ?: strcasecmp($x['name'], $y['name']) ?: strcmp($x['code'], $y['code']);
});
$logos = eng_part_logos($ENG_TOUR, array_column($groups, 'code'));
foreach ($groups as &$g) {
    eng_part_sort($g['rows']);
    $g['logo'] = $logos[$g['code']] ?? null;
}
unset($g);

$n = count($groups);
eng_part_pdf('Liste des participants par clubs', array_values($groups),
    [['name', 'Archer', 34, 'L'], ['code', 'Licence', 13, 'L'], ['cat', 'Catégorie', 13, 'L'], ['ses', 'Départ', 9, 'L'],
     ['target', 'Cible', 9, 'L'], ['face', 'Blason', 14, 'L']],
    ['scope' => $scope, 'sessions' => $tour->Sessions, 'perPage' => !empty($_GET['page']), 'file' => 'participants-par-clubs',
     'count' => $n . ' club' . ($n > 1 ? 's' : '') . ' · ']);
