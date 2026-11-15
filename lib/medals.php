<?php
/**
 * lib/medals.php — médailles à prévoir pour la remise des prix (medailles.php). Pur : ni base ni ianseo.
 *
 * Une catégorie (classe et arme, « S1HCL ») reçoit autant de médailles qu'elle a d'archers, trois au plus :
 * l'or dès un archer, l'argent dès deux, le bronze dès trois. Au choix, un archer seul dans sa catégorie n'a
 * pas de médaille. Les égalités au classement ne sont pas prévues.
 */

/**
 * Médailles d'une catégorie.
 *
 * @param int  $n    Nombre d'archers de la catégorie.
 * @param bool $solo Un archer seul dans sa catégorie reçoit l'or.
 * @return array [or, argent, bronze], 0 ou 1 chacun.
 */
function eng_medals_count($n, $solo = true)
{
    $n = max(0, intval($n));
    if ($n === 1 && !$solo) return [0, 0, 0];
    return [$n >= 1 ? 1 : 0, $n >= 2 ? 1 : 0, $n >= 3 ? 1 : 0];
}

/**
 * Médailles de toutes les catégories.
 *
 * @param array $rows Inscriptions : key (archer), cat (« S1HCL »), cat_label, cat_order (ordre d'IANSEO).
 *                    Un archer inscrit à plusieurs départs dans la même catégorie compte une fois.
 * @param bool  $solo Un archer seul dans sa catégorie reçoit l'or.
 * @return array ['cats' => [[cat, label, archers, or, argent, bronze]…] dans l'ordre d'IANSEO,
 *                'total' => [archers, or, argent, bronze]]
 */
function eng_medals_table(array $rows, $solo = true)
{
    $cats = [];
    foreach ($rows as $r) {
        $k = $r['cat_order'] . '|' . $r['cat'];
        if (!isset($cats[$k])) $cats[$k] = ['cat' => $r['cat'], 'label' => $r['cat_label'], 'keys' => []];
        $cats[$k]['keys'][$r['key']] = true;
    }
    ksort($cats, SORT_STRING);
    $out = [];
    $total = [0, 0, 0, 0];
    foreach ($cats as $c) {
        $n = count($c['keys']);
        $m = eng_medals_count($n, $solo);
        $out[] = array_merge([$c['cat'], $c['label'], $n], $m);
        $total = [$total[0] + $n, $total[1] + $m[0], $total[2] + $m[1], $total[3] + $m[2]];
    }
    return ['cats' => $out, 'total' => $total];
}
