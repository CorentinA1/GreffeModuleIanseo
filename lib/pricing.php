<?php
/**
 * lib/pricing.php — moteur de calcul des frais d'inscription.
 *
 * Fonctions pures : ni base de données, ni session, ni sortie. Elles se testent
 * seules (tests/test_pricing.php) et servent aussi bien au calcul des comptes
 * qu'à l'aperçu en direct de la page des tarifs.
 *
 * Configuration normalisée (tableau PHP) :
 *
 *     [
 *       'sessions' => 3,                        // taille des grilles = ToNumSession
 *       'org'      => 'C86012',                 // code du club organisateur (ToCommitee), '' si inconnu
 *       'base'     => 8.0,                      // tarif de base, PAR départ
 *       'default'  => [1 => 10.0, 2 => 17.0, 3 => null],   // grille par défaut
 *       'rules'    => [
 *         ['label' => 'Jeunes',
 *          'div'   => ['CL', 'CO'],            // vide = toutes les divisions
 *          'cls'   => ['U11H', 'U11F'],        // vide = toutes les classes
 *          'club'  => 'all',                   // 'all' | 'org' | 'other' | 'list'
 *          'clubs' => [],                      // codes de club, pour 'list'
 *          'grid'  => [1 => 7.0, 2 => 12.0, 3 => 15.0]],
 *       ],
 *       'note'     => '',                       // mention en pied de reçu
 *     ]
 *
 * Une grille donne le prix TOTAL selon le nombre de départs (pas un prix par
 * départ). Une case vide vaut null.
 *
 * Critère de club d'une règle : tous les clubs, le club organisateur (code
 * ToCommitee de la compétition), les autres clubs, ou une liste de codes.
 * Sans club organisateur renseigné, « organisateur » ne retient personne et
 * « autres clubs » retient tout le monde.
 *
 * Ordre de résolution pour un archer :
 *   1. la première règle qui correspond à sa division, sa classe et son club,
 *      si sa grille sait tarifer ce nombre de départs ;
 *   2. sinon la grille par défaut, si elle est saisie ;
 *   3. sinon le tarif de base × nombre de départs.
 *
 * Grille trop courte (le nombre de sessions a augmenté après la saisie, ou une
 * case est restée vide) : on applique le dernier total saisi en dessous du
 * nombre de départs et on lève le drapeau 'short' pour signaler l'archer.
 */

/**
 * Lit un montant saisi par un humain : « 7,50 », « 7.5 », « 10 € ».
 *
 * @param mixed $value Saisie brute.
 * @return float|null Montant arrondi au centime ; null si vide, illisible ou négatif.
 */
function eng_parse_amount($value)
{
    if ($value === null || is_bool($value)) return null;
    if (is_int($value) || is_float($value)) {
        return ($value >= 0 && is_finite((float) $value)) ? round((float) $value, 2) : null;
    }
    $s = str_replace(["\xC2\xA0", ' ', '€'], '', trim((string) $value));
    $s = str_replace(',', '.', $s);
    if ($s === '' || !preg_match('/^\d{1,6}(\.\d{1,2})?$/', $s)) return null;
    return round((float) $s, 2);
}

/**
 * Ramène une grille à exactement $size cases numérotées de 1 à $size.
 *
 * Les cases au-delà de $size sont abandonnées : la grille suit le nombre de
 * sessions de la compétition.
 *
 * @param mixed $grid Tableau ou objet décodé du JSON ({"1":10,"2":17}).
 * @param int $size Nombre de sessions de la compétition (au moins 1).
 * @return array<int, float|null>
 */
function eng_clean_grid($grid, $size)
{
    $size = max(1, (int) $size);
    $grid = is_array($grid) ? $grid : (is_object($grid) ? (array) $grid : []);
    $out = [];
    for ($i = 1; $i <= $size; $i++) {
        // PHP convertit déjà la clé "1" en entier 1 : un seul test suffit.
        $out[$i] = array_key_exists($i, $grid) ? eng_parse_amount($grid[$i]) : null;
    }
    return $out;
}

/** Une grille sans aucune case saisie ne sait rien tarifer. */
function eng_grid_is_empty(array $grid)
{
    foreach ($grid as $v) {
        if ($v !== null) return false;
    }
    return true;
}

/**
 * Normalise une liste de codes (divisions ou classes) : sans espaces, en
 * majuscules, sans doublons ni chaînes vides.
 *
 * @param mixed $codes
 * @return string[]
 */
function eng_clean_codes($codes)
{
    if (!is_array($codes)) return [];
    $out = [];
    foreach ($codes as $c) {
        if (!is_scalar($c)) continue;
        $c = strtoupper(trim((string) $c));
        if ($c !== '' && !in_array($c, $out, true)) $out[] = $c;
    }
    return $out;
}

/** Critères de club d'une règle : code => libellé. */
function eng_club_modes()
{
    return [
        'all'   => 'Tous les clubs',
        'org'   => 'Club organisateur',
        'other' => 'Autres clubs que l\'organisateur',
        'list'  => 'Clubs choisis',
    ];
}

/** Longueur maximale de la mention en pied de reçu. */
if (!defined('ENG_NOTE_MAX')) define('ENG_NOTE_MAX', 500);

/**
 * Mention en pied de reçu : texte libre, retours à la ligne conservés (au plus
 * trois lignes consécutives), espaces superflus retirés.
 */
function eng_clean_note($note)
{
    $s = str_replace(["\r\n", "\r"], "\n", is_scalar($note) ? (string) $note : '');
    $s = preg_replace('/[ \t]+/', ' ', $s);
    $s = preg_replace("/\n{3,}/", "\n\n", trim(implode("\n", array_map('trim', explode("\n", $s)))));
    return mb_substr($s, 0, ENG_NOTE_MAX);
}

/**
 * Normalise une configuration, quelle que soit sa provenance (formulaire,
 * JSON décodé, ligne de base, fichier de sauvegarde).
 *
 * @param array $raw Clés 'base', 'default', 'rules', 'note', 'org' ; toutes facultatives.
 * @param int $sessions Nombre de sessions de la compétition (ToNumSession).
 * @return array Configuration normalisée (voir en tête de fichier).
 */
function eng_normalize_config(array $raw, $sessions)
{
    $sessions = max(1, (int) $sessions);
    $base = eng_parse_amount($raw['base'] ?? null);

    $rules = [];
    foreach ((is_array($raw['rules'] ?? null) ? $raw['rules'] : []) as $r) {
        if (is_object($r)) $r = (array) $r;
        if (!is_array($r)) continue;
        $club = (string) ($r['club'] ?? 'all');
        $clubs = eng_clean_codes($r['clubs'] ?? []);
        if (!isset(eng_club_modes()[$club])) $club = 'all';
        if ($club !== 'list') $clubs = [];
        $rules[] = [
            'label' => trim((string) ($r['label'] ?? '')),
            'div'   => eng_clean_codes($r['div'] ?? []),
            'cls'   => eng_clean_codes($r['cls'] ?? []),
            'club'  => $club,
            'clubs' => $clubs,
            'grid'  => eng_clean_grid($r['grid'] ?? [], $sessions),
        ];
    }

    return [
        'sessions' => $sessions,
        'org'      => strtoupper(trim((string) ($raw['org'] ?? ''))),
        'base'     => $base === null ? 0.0 : $base,
        'default'  => eng_clean_grid($raw['default'] ?? [], $sessions),
        'rules'    => $rules,
        'note'     => eng_clean_note($raw['note'] ?? ''),
    ];
}

/**
 * Configuration à partir d'une ligne EngagementConfig (ou null si la
 * compétition n'a encore rien saisi).
 *
 * @param array|object|null $row Colonnes EcBase, EcDefault, EcRules, EcReceiptNote.
 * @param int $sessions Nombre de sessions de la compétition.
 * @param string $org Code du club organisateur de la compétition (ToCommitee).
 * @return array Configuration normalisée.
 */
function eng_config_from_row($row, $sessions, $org = '')
{
    if (is_object($row)) $row = (array) $row;
    if (!is_array($row)) $row = [];
    $rules = json_decode((string) ($row['EcRules'] ?? ''), true);
    $default = json_decode((string) ($row['EcDefault'] ?? ''), true);
    return eng_normalize_config([
        'base'    => $row['EcBase'] ?? 0,
        'default' => is_array($default) ? $default : [],
        'rules'   => is_array($rules) ? $rules : [],
        'note'    => $row['EcReceiptNote'] ?? '',
        'org'     => $org,
    ], $sessions);
}

/**
 * JSON à stocker pour une grille : seules les cases saisies, clés en texte.
 *
 * @param array $grid Grille normalisée.
 * @return string Par exemple {"1":10,"2":17.5}.
 */
function eng_grid_to_json(array $grid)
{
    $out = [];
    foreach ($grid as $n => $v) {
        if ($v !== null) $out[(string) $n] = $v;
    }
    return json_encode((object) $out);
}

/**
 * JSON à stocker pour la liste des règles.
 *
 * @param array $rules Règles normalisées.
 * @return string
 */
function eng_rules_to_json(array $rules)
{
    $out = [];
    foreach ($rules as $r) {
        $out[] = [
            'label' => $r['label'],
            'div'   => array_values($r['div']),
            'cls'   => array_values($r['cls']),
            'club'  => $r['club'] ?? 'all',
            'clubs' => array_values($r['clubs'] ?? []),
            'grid'  => json_decode(eng_grid_to_json($r['grid'])),
        ];
    }
    return json_encode($out, JSON_UNESCAPED_UNICODE);
}

/**
 * La règle s'applique-t-elle à cette division, cette classe et ce club ?
 * Une liste vide accepte tout.
 *
 * @param string $club Code du club de l'archer (CoCode), '' si aucun.
 * @param string $org Code du club organisateur, '' si non renseigné.
 */
function eng_rule_matches(array $rule, $division, $class, $club = '', $org = '')
{
    $d = strtoupper(trim((string) $division));
    $c = strtoupper(trim((string) $class));
    if (!empty($rule['div']) && !in_array($d, $rule['div'], true)) return false;
    if (!empty($rule['cls']) && !in_array($c, $rule['cls'], true)) return false;
    return eng_club_matches($rule, $club, $org);
}

/** Le club de l'archer satisfait-il le critère de club de la règle ? */
function eng_club_matches(array $rule, $club, $org)
{
    $k = strtoupper(trim((string) $club));
    $o = strtoupper(trim((string) $org));
    $isOrg = $k !== '' && $o !== '' && $k === $o;
    switch ($rule['club'] ?? 'all') {
        case 'org':   return $isOrg;
        case 'other': return !$isOrg;
        case 'list':  return $k !== '' && in_array($k, $rule['clubs'] ?? [], true);
        default:      return true;
    }
}

/**
 * Total d'une grille pour $n départs.
 *
 * @param array $grid Grille normalisée.
 * @param int $n Nombre de départs (au moins 1).
 * @return array|null ['total' => float, 'short' => bool], ou null si aucune
 *                    case n'est saisie entre 1 et $n.
 */
function eng_grid_total(array $grid, $n)
{
    $n = (int) $n;
    if (isset($grid[$n])) return ['total' => $grid[$n], 'short' => false];
    for ($i = $n - 1; $i >= 1; $i--) {
        if (isset($grid[$i])) return ['total' => $grid[$i], 'short' => true];
    }
    return null;
}

/**
 * Montant dû pour une inscription.
 *
 * @param array $config Configuration normalisée.
 * @param string $division Division (arme), ex. « CL ».
 * @param string $class Classe (catégorie), ex. « U18H ».
 * @param int $n Nombre de départs.
 * @param string $club Code du club de l'archer (CoCode), pour le critère de club des règles.
 * @return array [
 *     'total'  => float,       // montant dû
 *     'source' => string,      // 'rule' | 'default' | 'base' | 'none' (0 départ)
 *     'rule'   => int|null,    // indice de la règle appliquée
 *     'label'  => string,      // libellé à afficher
 *     'short'  => bool,        // grille trop courte : dernier total appliqué
 * ]
 */
function eng_price(array $config, $division, $class, $n, $club = '')
{
    $n = (int) $n;
    if ($n <= 0) {
        return ['total' => 0.0, 'source' => 'none', 'rule' => null, 'label' => '', 'short' => false];
    }

    foreach ($config['rules'] as $i => $rule) {
        if (!eng_rule_matches($rule, $division, $class, $club, $config['org'] ?? '')) continue;
        $t = eng_grid_total($rule['grid'], $n);
        if ($t === null) continue;   // règle sans prix pour ce nombre de départs
        $label = $rule['label'] !== '' ? $rule['label'] : 'Règle ' . ($i + 1);
        return ['total' => $t['total'], 'source' => 'rule', 'rule' => $i, 'label' => $label, 'short' => $t['short']];
    }

    $t = eng_grid_total($config['default'], $n);
    if ($t !== null) {
        return ['total' => $t['total'], 'source' => 'default', 'rule' => null, 'label' => 'Grille par défaut', 'short' => $t['short']];
    }

    return ['total' => round($config['base'] * $n, 2), 'source' => 'base', 'rule' => null, 'label' => 'Tarif de base', 'short' => false];
}

/**
 * Configuration à partir du formulaire de la page des tarifs.
 *
 * Les règles laissées entièrement vides (ni libellé, ni case cochée, ni prix)
 * sont ignorées : c'est le cas d'une règle ajoutée puis abandonnée. L'ordre
 * des règles est celui du formulaire.
 *
 * @param array $post $_POST : 'base', 'default'[n], 'note',
 *                    'rules'[i]['label'|'div'|'cls'|'club'|'clubs'|'grid'].
 * @param int $sessions Nombre de sessions de la compétition.
 * @param string $org Code du club organisateur de la compétition.
 * @return array Configuration normalisée.
 */
function eng_config_from_post(array $post, $sessions, $org = '')
{
    return eng_normalize_config([
        'base'    => $post['base'] ?? '',
        'default' => $post['default'] ?? [],
        'rules'   => eng_posted_rules($post),
        'note'    => $post['note'] ?? '',
        'org'     => $org,
    ], $sessions);
}

/**
 * Règles saisies dans le formulaire, sans celles laissées entièrement vides.
 *
 * @param array $post $_POST de la page des tarifs.
 * @return array Règles brutes, avec leurs clés d'origine : l'aperçu s'en sert
 *               pour désigner la règle appliquée dans la page.
 */
function eng_posted_rules(array $post)
{
    $rules = [];
    foreach ((is_array($post['rules'] ?? null) ? $post['rules'] : []) as $k => $r) {
        if (!is_array($r)) continue;
        $grid = is_array($r['grid'] ?? null) ? $r['grid'] : [];
        $blank = trim((string) ($r['label'] ?? '')) === ''
              && empty($r['div']) && empty($r['cls'])
              && in_array((string) ($r['club'] ?? 'all'), ['', 'all'], true)
              && trim(implode('', array_map('strval', $grid))) === '';
        if (!$blank) $rules[$k] = $r;
    }
    return $rules;
}

/**
 * Contrôle d'une configuration avant enregistrement.
 *
 * Erreurs (bloquantes) : une grille de règle doit être remplie dans toutes ses
 * cases ; la grille par défaut est soit vide, soit complète. Une saisie
 * illisible est aussi une erreur : eng_normalize_config la réduit à une case
 * vide, d'où la comparaison avec la saisie brute ($raw).
 *
 * Avertissements : règle qui accepte tout placée avant d'autres (celles-ci ne
 * s'appliqueront jamais), total qui baisse quand le nombre de départs monte.
 *
 * @param array $config Configuration normalisée.
 * @param array $raw Saisie brute correspondante (même forme que $_POST), facultative.
 * @return array ['errors' => string[], 'warnings' => string[]]
 */
function eng_validate_config(array $config, array $raw = [])
{
    $errors = [];
    $warnings = [];
    $name = function ($i, $r) {
        return $r['label'] !== '' ? '« ' . $r['label'] . ' »' : 'n° ' . ($i + 1);
    };

    $unreadable = function ($rawGrid) {
        $bad = [];
        foreach ((is_array($rawGrid) ? $rawGrid : []) as $n => $v) {
            if (trim((string) $v) !== '' && eng_parse_amount($v) === null) $bad[] = (int) $n;
        }
        return $bad;
    };
    $rawRules = array_values(eng_posted_rules($raw));

    $last = count($config['rules']) - 1;
    foreach ($config['rules'] as $i => $r) {
        $bad = $unreadable($rawRules[$i]['grid'] ?? []);
        if ($bad) {
            $errors[] = 'Règle ' . $name($i, $r) . ' : montant illisible pour ' . eng_departs_text($bad) . '.';
            continue;
        }
        $missing = array_keys(array_filter($r['grid'], 'is_null'));
        if ($missing) {
            $errors[] = 'Règle ' . $name($i, $r) . ' : prix manquant pour ' . eng_departs_text($missing) . '.';
        }
        $club = $r['club'] ?? 'all';
        if ($club === 'list' && empty($r['clubs'])) {
            $errors[] = 'Règle ' . $name($i, $r) . ' : « Clubs choisis » sans aucun club coché.';
        }
        if ($club === 'org' && ($config['org'] ?? '') === '') {
            $warnings[] = 'Règle ' . $name($i, $r) . ' : le club organisateur n\'est pas renseigné dans la compétition '
                . '(Compétition › Informations) ; la règle ne s\'appliquera à personne.';
        }
        if (empty($r['div']) && empty($r['cls']) && $club === 'all' && $i < $last) {
            $warnings[] = 'La règle ' . $name($i, $r) . ' accepte toutes les divisions, toutes les classes et tous les clubs : '
                . 'les règles placées après elle ne s\'appliqueront jamais.';
        }
        if (!$missing && eng_grid_decreases($r['grid'])) {
            $warnings[] = 'Règle ' . $name($i, $r) . ' : le total baisse quand le nombre de départs augmente.';
        }
    }

    $bad = $unreadable($raw['default'] ?? []);
    if ($bad) {
        $errors[] = 'Grille par défaut : montant illisible pour ' . eng_departs_text($bad) . '.';
    } elseif (!eng_grid_is_empty($config['default'])) {
        $missing = array_keys(array_filter($config['default'], 'is_null'));
        if ($missing) {
            $errors[] = 'Grille par défaut : prix manquant pour ' . eng_departs_text($missing)
                . ' (remplissez toute la grille, ou videz-la pour utiliser le tarif de base).';
        } elseif (eng_grid_decreases($config['default'])) {
            $warnings[] = 'Grille par défaut : le total baisse quand le nombre de départs augmente.';
        }
    }

    if (array_key_exists('base', $raw) && trim((string) $raw['base']) !== '' && eng_parse_amount($raw['base']) === null) {
        $errors[] = 'Tarif de base : montant illisible.';
    }

    return ['errors' => $errors, 'warnings' => $warnings];
}

/** « 1 départ », « 2 et 3 départs ». */
function eng_departs_text(array $ns)
{
    sort($ns);
    $last = array_pop($ns);
    return ($ns ? implode(', ', $ns) . ' et ' : '') . $last . ' départ' . (($ns || $last > 1) ? 's' : '');
}

/** Vrai si un total est plus bas que celui d'un nombre de départs inférieur. */
function eng_grid_decreases(array $grid)
{
    $prev = null;
    foreach ($grid as $v) {
        if ($v === null) continue;
        if ($prev !== null && $v < $prev) return true;
        $prev = $v;
    }
    return false;
}

/** Vrai si rien n'est configuré : aucune règle, pas de grille par défaut, base à 0. */
function eng_config_is_empty(array $config)
{
    return !$config['rules'] && eng_grid_is_empty($config['default']) && $config['base'] <= 0;
}
