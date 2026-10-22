<?php
/**
 * lib/boot.php — amorçage commun des pages du module GREFFE.
 *
 * La page appelante dit si elle écrit ou lit seulement, puis inclut ce fichier :
 *
 *     define('ENG_ACCESS', 'write');   // ou 'read' (reporting, reçus)
 *     require_once __DIR__ . '/lib/boot.php';
 *
 * Ensuite : configuration ianseo, compétition ouverte, droits, tables.
 * Une chaîne plutôt qu'une constante Acl* : celles-ci n'existent qu'une fois
 * config.php chargé, donc pas encore au moment où la page fait son define().
 */

if (!defined('HTDOCS')) define('HTDOCS', dirname(__DIR__, 4));
require_once HTDOCS . '/config.php';

CheckTourSession(true);
checkFullACL(AclParticipants, 'pEntries',
    (defined('ENG_ACCESS') && ENG_ACCESS === 'read') ? AclReadOnly : AclReadWrite);

require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/pricing.php';

eng_schema();

$ENG_TOUR = intval($_SESSION['TourId']);
$ENG_ROOT = $CFG->ROOT_DIR . 'Modules/Custom/ENGAGEMENT/';

/** Échappement HTML de toute valeur affichée. */
function eng_h($v)
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

/** Jeton anti-CSRF de la session. */
function eng_token()
{
    if (empty($_SESSION['ENG_TOKEN'])) $_SESSION['ENG_TOKEN'] = bin2hex(random_bytes(16));
    return $_SESSION['ENG_TOKEN'];
}

/** Champ caché portant le jeton, à placer dans chaque formulaire POST. */
function eng_token_field()
{
    return '<input type="hidden" name="eng_token" value="' . eng_h(eng_token()) . '">';
}

/** Arrête la requête si le jeton POST est absent ou faux. */
function eng_check_token()
{
    $t = (string) ($_POST['eng_token'] ?? '');
    if (!hash_equals(eng_token(), $t)) {
        http_response_code(403);
        exit('Jeton de sécurité invalide : rechargez la page.');
    }
}

/**
 * Compétition ouverte : nom, nombre de sessions, devise.
 *
 * @return object ToCode, ToName, ToComDescr, ToCommitee, ToWhere, ToWhenFrom, ToWhenTo,
 *                Sessions (au moins 1), Currency (« € » par défaut).
 */
function eng_tournament()
{
    global $ENG_TOUR;
    static $t = null;
    if ($t !== null) return $t;
    $rs = safe_r_sql("SELECT ToCode, ToName, ToComDescr, ToCommitee, ToWhere, ToWhenFrom, ToWhenTo, ToNumSession, ToCurrency
        FROM Tournament WHERE ToId = " . StrSafe_DB($ENG_TOUR));
    $t = safe_fetch($rs) ?: new stdClass();
    $t->Sessions = max(1, intval($t->ToNumSession ?? 1));
    $t->Currency = trim((string) ($t->ToCurrency ?? '')) !== '' ? trim($t->ToCurrency) : '€';
    return $t;
}

/** Code du club organisateur de la compétition ouverte (ToCommitee), '' si non renseigné. */
function eng_org_code()
{
    return strtoupper(trim((string) (eng_tournament()->ToCommitee ?? '')));
}

/** Configuration tarifaire de la compétition ouverte, normalisée. */
function eng_load_config()
{
    global $ENG_TOUR;
    $rs = safe_r_sql("SELECT EcBase, EcDefault, EcRules, EcReceiptNote FROM EngagementConfig
        WHERE EcTournament = " . StrSafe_DB($ENG_TOUR));
    return eng_config_from_row(safe_fetch_assoc($rs), eng_tournament()->Sessions, eng_org_code());
}

/** Enregistre une configuration normalisée pour la compétition ouverte. */
function eng_save_config(array $config)
{
    global $ENG_TOUR;
    $base    = StrSafe_DB(number_format($config['base'], 2, '.', ''));
    $default = StrSafe_DB(eng_grid_to_json($config['default']));
    $rules   = StrSafe_DB(eng_rules_to_json($config['rules']));
    $note    = StrSafe_DB($config['note'] ?? '');
    safe_w_sql("INSERT INTO EngagementConfig (EcTournament, EcBase, EcDefault, EcRules, EcReceiptNote)
        VALUES (" . StrSafe_DB($ENG_TOUR) . ", $base, $default, $rules, $note)
        ON DUPLICATE KEY UPDATE EcBase = VALUES(EcBase), EcDefault = VALUES(EcDefault), EcRules = VALUES(EcRules),
            EcReceiptNote = VALUES(EcReceiptNote)");
}

/**
 * Divisions (armes), classes (catégories) ou clubs de la compétition.
 *
 * @param string $what 'div', 'cls' ou 'club'.
 * @return array<string, string> code => libellé, dans l'ordre d'ianseo (clubs : par code).
 */
function eng_codes($what)
{
    global $ENG_TOUR;
    if ($what === 'club') {
        $out = [];
        $rs = safe_r_sql("SELECT CoCode AS Code, CoName AS Descr FROM Countries
            WHERE CoTournament = " . StrSafe_DB($ENG_TOUR) . " AND CoCode <> '' ORDER BY CoCode");
        while ($r = safe_fetch($rs)) $out[strtoupper(trim($r->Code))] = (string) $r->Descr;
        return $out;
    }
    $sql = $what === 'div'
        ? "SELECT DivId AS Code, DivDescription AS Descr FROM Divisions
           WHERE DivTournament = " . StrSafe_DB($ENG_TOUR) . " AND DivAthlete = '1' ORDER BY DivViewOrder, DivId"
        : "SELECT ClId AS Code, ClDescription AS Descr FROM Classes
           WHERE ClTournament = " . StrSafe_DB($ENG_TOUR) . " AND ClAthlete = '1' ORDER BY ClViewOrder, ClId";
    $out = [];
    $rs = safe_r_sql($sql);
    while ($r = safe_fetch($rs)) $out[strtoupper(trim($r->Code))] = (string) $r->Descr;
    return $out;
}

/** Montant affiché : « 7,50 € ». */
function eng_money($v)
{
    return number_format((float) $v, 2, ',', "\u{202F}") . "\u{00A0}" . eng_tournament()->Currency;
}

/** Montant pré-rempli dans un champ de saisie : « 10 », « 7,50 », ou vide. */
function eng_amount_input($v)
{
    if ($v === null) return '';
    return floor($v) == $v ? (string) intval($v) : number_format($v, 2, ',', '');
}

/**
 * Mise en forme d'une date enregistrée par NOW().
 *
 * Aucune conversion : ianseo règle le fuseau de chaque connexion MySQL sur
 * celui de la compétition (Common/Globals.inc.php, SET session time_zone =
 * TourTimezone), donc NOW() stocke déjà l'heure locale de la compétition.
 * PHP, lui, est forcé en UTC : ne jamais dater avec date(), utiliser eng_db_now().
 *
 * @param string $sql Date SQL « 2026-10-09 12:32:34 ».
 * @param string $format Format date() du résultat.
 * @return string Vide si la date est vide ou illisible.
 */
function eng_local_time($sql, $format = 'd/m H:i')
{
    $sql = trim((string) $sql);
    if ($sql === '' || strpos($sql, '0000') === 0) return '';
    $d = DateTime::createFromFormat('Y-m-d H:i:s', $sql);
    return $d ? $d->format($format) : '';
}

/** Date et heure courantes de la compétition, au format SQL (via MySQL). */
function eng_db_now()
{
    $r = safe_fetch(safe_r_sql("SELECT NOW() AS Now"));
    return $r ? (string) $r->Now : '';
}

/**
 * En-tête commun des pages : « GREFFE » suivi des onglets de navigation.
 *
 * Les onglets suivent les droits : Tarifs, Paiements et Sauvegarde demandent
 * l'écriture sur les participants, Reporting la lecture (comme menu.php).
 *
 * @param string $current Page affichée : 'tarifs' | 'paiements' | 'reporting' | 'sauvegarde'.
 * @return string HTML (styles compris), à placer en tête du conteneur #eng.
 */
function eng_nav($current)
{
    global $ENG_ROOT;
    $write = hasFullACL(AclParticipants, 'pEntries', AclReadWrite);
    $pages = [
        'tarifs'    => ['Tarifs', $write],
        'paiements' => ['Paiements', $write],
        'reporting' => ['Rapport - Bilan', true],
        'sauvegarde' => ['Sauvegarde', $write],
    ];
    $h = '<style>
#eng .eng-head { display:flex; flex-wrap:wrap; align-items:center; gap:6px 18px; margin:6px 0 14px; }
#eng .eng-head h2 { margin:0; color:var(--bleu-fonce); font-size:18px; }
#eng .eng-nav { display:flex; gap:4px; flex-wrap:wrap; }
#eng .eng-nav a { padding:5px 14px; border-radius:16px; border:1px solid var(--bleu); color:var(--lien);
                  background:var(--carte); font-weight:600; font-size:13px; text-decoration:none; }
#eng .eng-nav a:hover { background:var(--bleu-clair); }
#eng .eng-nav a.on { background:var(--bleu); color:var(--sur-bleu); cursor:default; }
#eng .eng-nav a.eng-help { border:none; background:none; font-weight:400; text-decoration:underline; }
#eng .eng-foot { margin-top:22px; padding-top:8px; border-top:1px solid var(--input-border); color:var(--text-muted); font-size:12px; }
</style><div class="eng-head"><h2>Greffe</h2><nav class="eng-nav">';
    foreach ($pages as $key => $p) {
        if (!$p[1]) continue;
        $h .= '<a href="' . eng_h($ENG_ROOT . $key . '.php') . '"' . ($key === $current ? ' class="on" aria-current="page"' : '')
            . '>' . eng_h($p[0]) . '</a>';
    }
    $h .= '<a class="eng-help" href="' . eng_h($ENG_ROOT . 'docs/GUIDE-UTILISATEUR.pdf') . '" target="_blank" rel="noopener">Mode d\'emploi</a>';
    return $h . '</nav></div>';
}

/**
 * Pied de page commun : l'avertissement, sur une ligne discrète (même présentation que les autres modules).
 *
 * @return string HTML, à placer à la fin du conteneur #eng.
 */
function eng_footer()
{
    global $ENG_ROOT;
    $write = hasFullACL(AclParticipants, 'pEntries', AclReadWrite);
    return '<p class="eng-foot">Module indépendant d\'IANSEO, sans garantie ni SAV : vérifiez les montants. <b>Sauvegardez les tarifs et paiements</b>'
        . ($write ? ' (<a href="' . eng_h($ENG_ROOT . 'sauvegarde.php') . '">page Sauvegarde</a>)' : '')
        . ' : ils ne sont pas dans l\'export de la compétition. '
        . '<a href="https://github.com/CorentinA1/GreffeModuleIanseo/blob/main/docs/AVERTISSEMENT.md" target="_blank" rel="noopener">À lire avant d\'utiliser le module</a></p>';
}
