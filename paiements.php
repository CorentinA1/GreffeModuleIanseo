<?php
/**
 * paiements.php — GREFFE : pointage des paiements.
 *
 * Un compte par archer (licence). Payé en totalité ou non : le montant
 * enregistré est toujours le dû recalculé côté serveur au moment du clic.
 * Annuler date la ligne de paiement sans la supprimer.
 */
define('ENG_ACCESS', 'write');
require_once __DIR__ . '/lib/boot.php';
require_once __DIR__ . '/lib/accounts.php';

$tour     = eng_tournament();
$config   = eng_load_config();
$methods  = eng_methods();
$statuses = eng_statuses();
$self     = $ENG_ROOT . 'paiements.php';

// ── Filtres (GET) ────────────────────────────────────────────────────────────
$views = [
    ''       => 'Tous les archers',
    'unpaid' => 'Non payés',
    'paid'   => 'Payés',
    'diff'   => 'Payés — écart',
    'exempt' => 'Exonérés',
    'mixed'  => 'Catégories mixtes',
    'norule' => 'Sans règle (défaut / base)',
];
$f = [
    'club' => intval($_GET['club'] ?? 0),
    'rule' => (string) ($_GET['rule'] ?? ''),
    'view' => isset($views[$_GET['view'] ?? '']) ? (string) $_GET['view'] : '',
    'ses'  => intval($_GET['ses'] ?? 0),    // départ : 1…N, -1 = non placé, 0 = tous
    'nb'   => max(0, intval($_GET['nb'] ?? 0)),   // nombre de départs, 0 = tous
    'q'    => trim((string) ($_GET['q'] ?? '')),
    'sort' => isset(eng_sort_columns()[$_GET['sort'] ?? '']) && $_GET['sort'] !== 'name' ? (string) $_GET['sort'] : '',
    'dir'  => ($_GET['dir'] ?? '') === 'desc' ? 'desc' : '',
];
/** URL de la page avec les filtres et le tri courants (valeurs par défaut omises). */
function eng_self_url($self, array $f)
{
    $q = http_build_query(array_filter($f, function ($v) { return $v !== '' && $v !== 0; }));
    return $self . ($q !== '' ? '?' . $q : '');
}

/** En-tête de colonne triable : lien qui trie par cette colonne, ou inverse le sens. */
function eng_sort_th($self, array $f, $key, $label, $class = '')
{
    $cur = ($f['sort'] ?: 'name') === $key;
    $g = $f;
    $g['sort'] = $key === 'name' ? '' : $key;
    $g['dir'] = $cur && $f['dir'] !== 'desc' ? 'desc' : '';
    $arrow = $cur ? ($f['dir'] === 'desc' ? ' ▼' : ' ▲') : '';
    return '<th' . ($class !== '' ? ' class="' . $class . '"' : '') . ($cur ? ' aria-sort="' . ($f['dir'] === 'desc' ? 'descending' : 'ascending') . '"' : '')
        . '><a href="' . eng_h(eng_self_url($self, $g)) . '" title="Trier par ' . eng_h(mb_strtolower($label)) . '">'
        . eng_h($label) . $arrow . '</a></th>';
}

// ── Actions (POST) ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    eng_check_token();
    $accounts = eng_load_accounts($ENG_TOUR, $config);
    $act      = (string) ($_POST['action'] ?? '');
    $method   = (string) ($_POST['method'] ?? '');
    $note     = (string) ($_POST['note'] ?? '');
    $acc      = $accounts[(string) ($_POST['account'] ?? '')] ?? null;
    $who      = $acc ? trim($acc['first'] . ' ' . $acc['name']) : '';
    $msg      = ['err', 'Action impossible : la page n\'était plus à jour. Vérifiez la ligne.'];

    if (isset($methods[$method])) $_SESSION['ENG_LAST_METHOD'] = $method;

    if ($act === 'pay' && $acc && eng_pay($ENG_TOUR, $acc, $method, $note)) {
        $msg = ['ok', $who . ' : ' . eng_money($acc['due']) . ' — ' . $methods[$method] . '.'];
    } elseif ($act === 'cancel' && $acc && eng_cancel($ENG_TOUR, $acc)) {
        $msg = ['ok', $who . ' : paiement annulé.'];
    } elseif ($act === 'resync' && $acc && eng_resync($ENG_TOUR, $acc)) {
        $msg = ['ok', $who . ' : paiement régularisé à ' . eng_money($acc['due']) . '.'];
    } elseif ($act === 'payclub' && isset($methods[$method])) {
        $club = intval($_POST['club'] ?? 0);
        $n = 0;
        $sum = 0.0;
        safe_w_BeginTransaction();
        foreach ($accounts as $a) {
            if ($club > 0 && $a['club_id'] === $club && eng_pay($ENG_TOUR, $a, $method, $note)) {
                $n++;
                $sum += $a['due'];
            }
        }
        safe_w_Commit();
        $msg = $n ? ['ok', 'Club réglé : ' . $n . ' archer' . ($n > 1 ? 's' : '') . ', ' . eng_money($sum) . ' — ' . $methods[$method] . '.']
                  : ['err', 'Aucun archer non payé dans ce club.'];
    }

    $_SESSION['ENG_FLASH_PAY'] = $msg;
    header('Location: ' . eng_self_url($self, $f) . ($acc ? '#a-' . rawurlencode($acc['key']) : ''));
    exit;
}

// ── Données ──────────────────────────────────────────────────────────────────
$accounts = eng_load_accounts($ENG_TOUR, $config);
$flash = $_SESSION['ENG_FLASH_PAY'] ?? null;
unset($_SESSION['ENG_FLASH_PAY']);
$lastMethod = $_SESSION['ENG_LAST_METHOD'] ?? 'especes';

// Listes des filtres, et clubs ayant des comptes non payés (règlement groupé).
$clubs = $rulesSeen = $clubUnpaid = [];
$maxN = 1;
$unplaced = false;
foreach ($accounts as $a) {
    if ($a['n'] === 0) continue;
    $maxN = max($maxN, $a['n']);
    if (in_array(0, $a['sessions'], true)) $unplaced = true;
    $clubs[$a['club_id']] = trim($a['club_code'] . ' — ' . $a['club_name'], ' —');
    $rulesSeen[eng_rule_key($a['price'])] = $a['price']['label'];
    if ($a['status'] === 'unpaid') {
        $clubUnpaid[$a['club_id']]['n'] = ($clubUnpaid[$a['club_id']]['n'] ?? 0) + 1;
        $clubUnpaid[$a['club_id']]['sum'] = ($clubUnpaid[$a['club_id']]['sum'] ?? 0) + $a['due'];
    }
}
asort($clubs, SORT_FLAG_CASE | SORT_STRING);
ksort($rulesSeen, SORT_NATURAL);

$shown = array_filter($accounts, function ($a) use ($f) {
    if ($f['club'] && $a['club_id'] !== $f['club']) return false;
    if ($f['rule'] !== '' && eng_rule_key($a['price']) !== $f['rule']) return false;
    if (in_array($f['view'], ['unpaid', 'paid', 'diff'], true) && $a['status'] !== $f['view']) return false;
    if ($f['view'] === 'exempt' && !in_array($a['status'], ['waived', 'exempt'], true)) return false;
    if ($f['view'] === 'mixed' && !$a['mixed']) return false;
    if ($f['view'] === 'norule' && !in_array($a['price']['source'], ['default', 'base'], true)) return false;
    if ($f['ses'] && !in_array($f['ses'] > 0 ? $f['ses'] : 0, $a['sessions'], true)) return false;
    if ($f['nb'] && $a['n'] !== $f['nb']) return false;
    if ($f['q'] !== '') {
        $hay = mb_strtolower($a['key'] . ' ' . $a['name'] . ' ' . $a['first'] . ' ' . $a['first'] . ' ' . $a['name'] . ' ' . $a['club_name']);
        if (mb_strpos($hay, mb_strtolower($f['q'])) === false) return false;
    }
    return true;
});
$shown = eng_sort_accounts($shown, $f['sort'] ?: 'name', $f['dir'] ?: 'asc');

// Totaux de la sélection affichée.
$tot = ['n' => 0, 'due' => 0.0, 'paid' => 0.0, 'left' => 0.0, 'unpaid' => 0, 'waived' => 0.0, 'nwaived' => 0];
foreach ($shown as $a) {
    $m = eng_account_amounts($a);
    $tot['n']++;
    foreach (['due', 'paid', 'left', 'waived'] as $k) $tot[$k] += $m[$k];
    if ($a['status'] === 'unpaid') $tot['unpaid']++;
    if (in_array($a['status'], ['waived', 'exempt'], true)) $tot['nwaived']++;
}

/** Liste déroulante des moyens de paiement. */
function eng_method_select(array $methods, $selected)
{
    $h = '<select name="method">';
    foreach ($methods as $k => $l) {
        $h .= '<option value="' . eng_h($k) . '"' . ($k === $selected ? ' selected' : '') . '>' . eng_h($l) . '</option>';
    }
    return $h . '</select>';
}

$PAGE_TITLE = 'Greffe - Paiements';
include($CFG->DOCUMENT_PATH . 'Common/Templates/head.php');
?>
<style>
#eng {
    /* Couleurs : variables d'IANSEO (Common/Styles/colors.css), redéfinies par le module THEME pour chaque palette
       et pour le mode sombre. États : --text-success / --text-danger / --text-warning mêlées à --black (texte) ou à
       --row-background (fonds), lisibles dans les deux modes. */
    --bleu:var(--header-dark); --sur-bleu:var(--row-main-text); --bleu-fonce:var(--main-text); --bleu-clair:var(--table-background);
    --bleu-survol:color-mix(in srgb, var(--header-dark) 82%, var(--black)); --lien:var(--thm-link, var(--header-dark));
    --gris:var(--text-dark); --sous:var(--text-muted); --bord:var(--input-border); --fond:var(--header-light);
    --carte:var(--row-background); --champ:var(--text-white); --champ-texte:var(--black);
    --vert:color-mix(in srgb, var(--text-success) 60%, var(--black)); --ok-fond:color-mix(in srgb, var(--text-success) 14%, var(--row-background)); --ok-bord:color-mix(in srgb, var(--text-success) 45%, var(--row-background));
    --rouge:color-mix(in srgb, var(--text-danger) 70%, var(--black)); --err-fond:color-mix(in srgb, var(--text-danger) 14%, var(--row-background)); --err-bord:color-mix(in srgb, var(--text-danger) 45%, var(--row-background));
    --orange:color-mix(in srgb, var(--text-warning) 50%, var(--black)); --warn-fond:color-mix(in srgb, var(--text-warning) 16%, var(--row-background)); --warn-bord:color-mix(in srgb, var(--text-warning) 55%, var(--row-background));
    color:var(--gris); font-size:13px; }
#eng .msg { border-radius:6px; padding:10px 14px; margin-bottom:12px; }
#eng .msg.ok  { background:var(--ok-fond); border:1px solid var(--ok-bord); color:var(--vert); }
#eng .msg.err { background:var(--err-fond); border:1px solid var(--err-bord); color:var(--rouge); }
#eng .msg.warn { background:var(--warn-fond); border:1px solid var(--warn-bord); color:var(--orange); }
#eng .bar { display:flex; flex-wrap:wrap; gap:8px; align-items:center; background:var(--bleu-clair);
            border-left:4px solid var(--bleu); border-radius:0 6px 6px 0; padding:10px 14px; margin-bottom:12px; }
#eng select, #eng input[type=text], #eng input[type=search] { padding:5px 7px; border:1px solid var(--bord); background:var(--champ); color:var(--champ-texte);
            border-radius:5px; font-size:13px; }
#eng .kpis { display:flex; flex-wrap:wrap; gap:10px; margin-bottom:12px; }
#eng .kpi { background:var(--carte); border:1px solid var(--bord); border-radius:6px; padding:8px 14px; min-width:130px; }
#eng .kpi span { display:block; font-size:11px; color:var(--sous); }
#eng .kpi b { font-size:18px; color:var(--bleu-fonce); }
#eng .kpi.left b { color:var(--rouge); }
#eng .kpi.waived b { color:var(--gris); }
#eng table.acc { border-collapse:collapse; width:100%; background:var(--carte); }
#eng table.acc th { background:var(--bleu); color:var(--sur-bleu); text-align:left; padding:6px 8px; font-size:12px;
                    position:sticky; top:0; z-index:1; }
#eng table.acc td { border-bottom:1px solid var(--bleu-clair); padding:5px 8px; vertical-align:middle; }
#eng table.acc tr:target td { background:var(--warn-fond); }
#eng table.acc th a { color:inherit; text-decoration:none; display:block; }
#eng table.acc th a:hover { text-decoration:underline; }
#eng table.acc th.num { text-align:right; }
#eng td.num { text-align:right; white-space:nowrap; }
#eng small, #eng .sub { color:var(--sous); font-size:11px; }
#eng .flag { color:var(--orange); font-weight:bold; cursor:help; }
#eng .badge { display:inline-block; border-radius:10px; padding:1px 9px; font-size:11px; font-weight:600; white-space:nowrap; }
#eng .badge.unpaid { background:var(--err-fond); color:var(--rouge); }
#eng .badge.paid   { background:var(--ok-fond); color:var(--vert); }
#eng .badge.diff   { background:var(--warn-fond); color:var(--orange); }
#eng .badge.exempt, #eng .badge.waived { background:var(--fond); color:var(--gris); }
#eng form.inline { display:flex; gap:4px; align-items:center; margin:0; flex-wrap:wrap; }
#eng form.inline input[name=note] { width:110px; }
#eng button { border-radius:5px; cursor:pointer; padding:4px 10px; font-size:12px; font-weight:600; }
#eng button.pay { background:var(--bleu); border:1px solid var(--bleu); color:var(--sur-bleu); }
#eng button.pay:hover { background:var(--bleu-survol); }
#eng button.ghost { background:var(--carte); border:1px solid var(--bord); color:var(--gris); }
#eng button.warn { background:var(--carte); border:1px solid var(--orange); color:var(--orange); }
#eng .club { border:1px solid var(--bord); border-radius:6px; background:var(--carte); padding:10px 14px; margin:0 0 12px; }
#eng .club b { color:var(--bleu-fonce); }
#eng .empty { padding:20px; text-align:center; color:var(--sous); }
</style>

<div id="eng">
    <?= eng_nav('paiements') ?>

    <?php if (eng_config_is_empty($config)): ?>
        <div class="msg warn">Aucun tarif n'est configuré : tous les montants sont à 0 €.
            <a href="<?= eng_h($ENG_ROOT . 'tarifs.php') ?>">Configurer les tarifs</a></div>
    <?php endif; ?>

    <?php if ($flash): ?><div class="msg <?= eng_h($flash[0]) ?>"><?= eng_h($flash[1]) ?></div><?php endif; ?>

    <form class="bar" method="get" action="<?= eng_h($self) ?>" id="filters">
        <input type="search" name="q" value="<?= eng_h($f['q']) ?>" placeholder="Nom, licence, club…" style="min-width:200px">
        <select name="club"><option value="0">Tous les clubs</option>
            <?php foreach ($clubs as $id => $l): ?><option value="<?= $id ?>"<?= $f['club'] === $id ? ' selected' : '' ?>><?= eng_h($l) ?></option><?php endforeach; ?>
        </select>
        <select name="rule"><option value="">Toutes les règles</option>
            <?php foreach ($rulesSeen as $k => $l): ?><option value="<?= eng_h($k) ?>"<?= $f['rule'] === (string) $k ? ' selected' : '' ?>><?= eng_h($l) ?></option><?php endforeach; ?>
        </select>
        <select name="view">
            <?php foreach ($views as $k => $l): ?><option value="<?= eng_h($k) ?>"<?= $f['view'] === $k ? ' selected' : '' ?>><?= eng_h($l) ?></option><?php endforeach; ?>
        </select>
        <select name="ses" title="Archers inscrits à ce départ"><option value="0">Tous les départs</option>
            <?php for ($s = 1; $s <= $tour->Sessions; $s++): ?><option value="<?= $s ?>"<?= $f['ses'] === $s ? ' selected' : '' ?>>Départ <?= $s ?></option><?php endfor; ?>
            <?php if ($unplaced || $f['ses'] === -1): ?><option value="-1"<?= $f['ses'] === -1 ? ' selected' : '' ?>>Non placés</option><?php endif; ?>
        </select>
        <select name="nb" title="Nombre de départs de l'archer"><option value="0">Tout nombre de départs</option>
            <?php for ($n = 1; $n <= max($maxN, $f['nb']); $n++): ?><option value="<?= $n ?>"<?= $f['nb'] === $n ? ' selected' : '' ?>><?= $n ?> départ<?= $n > 1 ? 's' : '' ?></option><?php endfor; ?>
        </select>
        <?php if ($f['sort'] !== ''): ?><input type="hidden" name="sort" value="<?= eng_h($f['sort']) ?>"><?php endif; ?>
        <?php if ($f['dir'] !== ''): ?><input type="hidden" name="dir" value="<?= eng_h($f['dir']) ?>"><?php endif; ?>
        <button type="submit" class="ghost">Filtrer</button>
        <?php if (array_diff_key(array_filter($f, function ($v) { return $v !== '' && $v !== 0; }), ['sort' => 1, 'dir' => 1])): ?>
            <a href="<?= eng_h(eng_self_url($self, ['sort' => $f['sort'], 'dir' => $f['dir']])) ?>">Réinitialiser</a><?php endif; ?>
        <?php if ($f['club']): ?><a href="<?= eng_h($ENG_ROOT . 'recu.php?club=' . $f['club']) ?>" target="_blank">Récapitulatif du club (PDF)</a><?php endif; ?>
    </form>

    <div class="kpis">
        <div class="kpi"><span>Archers</span><b><?= $tot['n'] ?></b></div>
        <div class="kpi"><span>Total dû</span><b><?= eng_money($tot['due']) ?></b></div>
        <div class="kpi"><span>Encaissé</span><b><?= eng_money($tot['paid']) ?></b></div>
        <div class="kpi left"><span>Reste à encaisser · <?= $tot['unpaid'] ?> non payé<?= $tot['unpaid'] > 1 ? 's' : '' ?></span><b><?= eng_money($tot['left']) ?></b></div>
        <div class="kpi waived"><span>Exonéré · <?= $tot['nwaived'] ?> archer<?= $tot['nwaived'] > 1 ? 's' : '' ?></span><b><?= eng_money($tot['waived']) ?></b></div>
    </div>

    <form class="club print" method="get" action="<?= eng_h($ENG_ROOT . 'liste.php') ?>" target="_blank">
        <b>Liste papier du greffe (PDF)</b> — les archers et ce qu'ils doivent, avec une colonne pour pointer à la main.<br><br>
        <select name="depart">
            <option value="all">Tous les départs confondus</option>
            <option value="each">Par départ (une page par départ)</option>
            <?php for ($s = 1; $s <= $tour->Sessions; $s++): ?><option value="<?= $s ?>"<?= $f['ses'] === $s ? ' selected' : '' ?>>Départ <?= $s ?> seulement</option><?php endfor; ?>
        </select>
        <select name="only">
            <option value="all">Tous les archers</option>
            <option value="unpaid">Seulement ceux qui doivent encore</option>
        </select>
        <select name="sort">
            <option value="name">Tri par nom</option>
            <option value="club">Tri par club</option>
        </select>
        <button type="submit" class="pay">Éditer la liste</button>
        <div class="sub" style="margin-top:6px">Par départ, chaque archer figure au départ où il règle (son premier départ) ;
            ses départs suivants le rappellent, pour ne pas le faire payer deux fois.</div>
    </form>

    <?php if ($clubUnpaid): ?>
    <form class="club" method="post" action="<?= eng_h(eng_self_url($self, $f)) ?>" id="payclub">
        <?= eng_token_field() ?><input type="hidden" name="action" value="payclub">
        <b>Règlement d'un club en une fois</b> — tous ses archers non payés passent à payé avec le même moyen.<br><br>
        <select name="club">
            <?php foreach ($clubs as $id => $l): if (empty($clubUnpaid[$id])) continue; $u = $clubUnpaid[$id]; ?>
                <option value="<?= $id ?>"<?= $f['club'] === $id ? ' selected' : '' ?>
                    data-sum="<?= eng_h($u['n'] . ' archer' . ($u['n'] > 1 ? 's' : '') . ' pour ' . eng_money($u['sum'])) ?>">
                    <?= eng_h($l . ' (' . $u['n'] . ' · ' . eng_money($u['sum']) . ')') ?></option>
            <?php endforeach; ?>
        </select>
        <?= eng_method_select($methods, $lastMethod) ?>
        <input type="text" name="note" maxlength="160" placeholder="note (ex. n° de chèque)">
        <button type="submit" class="pay">Régler le club</button>
    </form>
    <?php endif; ?>

    <?php if (!$shown): ?>
        <div class="empty">Aucun archer ne correspond à ces filtres.</div>
    <?php else: ?>
    <table class="acc">
        <thead><tr>
            <?php foreach (eng_sort_columns() as $k => $l) echo eng_sort_th($self, $f, $k, $l, $k === 'due' ? 'num' : ''); ?>
        </tr></thead>
        <tbody>
        <?php foreach ($shown as $a):
            $p = $a['payment']; ?>
            <tr id="a-<?= eng_h($a['key']) ?>">
                <td><?php if ($a['n'] === 0): ?><i>Inscription supprimée</i>
                    <?php else: ?><b><?= eng_h(mb_strtoupper($a['name'])) ?></b> <?= eng_h($a['first']) ?><?php endif; ?>
                    <div class="sub"><?= eng_h($a['code'] !== '' ? $a['code'] : 'sans licence') ?></div></td>
                <td><?= eng_h($a['club_name']) ?><div class="sub"><?= eng_h($a['club_code']) ?></div></td>
                <td><?= eng_h(eng_sessions_text($a)) ?></td>
                <td><?= eng_h(implode(' / ', $a['cats'])) ?>
                    <?php if ($a['mixed']): ?><span class="flag" title="Catégories différentes selon les départs : la règle est celle du premier départ.">⚠</span><?php endif; ?></td>
                <td><?= $a['price']['source'] === 'rule' ? eng_h($a['price']['label']) : '<i>' . eng_h($a['price']['label']) . '</i>' ?>
                    <?php if ($a['price']['short']): ?><span class="flag" title="Grille plus courte que le nombre de départs : dernier total saisi appliqué.">⚠</span><?php endif; ?>
                    <?php if ($a['pays'] === 'partial'): ?><span class="flag" title="« Ne paie pas » coché dans IANSEO sur une partie seulement des inscriptions.">⚠</span><?php endif; ?></td>
                <td class="num"><?= eng_money($a['due']) ?>
                    <?php if ($a['n'] > 0): ?><div><a class="sub" href="<?= eng_h($ENG_ROOT . 'recu.php?account=' . rawurlencode($a['key'])) ?>" target="_blank"
                        title="<?= in_array($a['status'], ['unpaid', 'diff'], true) ? 'Récapitulatif PDF' : 'Reçu PDF' ?>"><?= in_array($a['status'], ['unpaid', 'diff'], true) ? 'Récap.' : 'Reçu' ?></a></div><?php endif; ?></td>
                <td><span class="badge <?= $a['status'] ?>"><?= eng_h($statuses[$a['status']]) ?></span>
                    <?php if ($a['status'] === 'diff'): ?><div class="sub">écart <?= ($a['diff'] > 0 ? '+' : '') . eng_money($a['diff']) ?></div><?php endif; ?></td>
                <td>
                <?php if ($a['status'] === 'unpaid'): ?>
                    <form class="inline" method="post" action="<?= eng_h(eng_self_url($self, $f)) ?>">
                        <?= eng_token_field() ?><input type="hidden" name="action" value="pay">
                        <input type="hidden" name="account" value="<?= eng_h($a['key']) ?>">
                        <?= eng_method_select($methods, $lastMethod) ?>
                        <input type="text" name="note" maxlength="160" placeholder="note">
                        <button type="submit" class="pay">Payer</button>
                    </form>
                <?php elseif ($p): ?>
                    <div><?= eng_h($methods[$p['EpMethod']] ?? $p['EpMethod']) ?> · <?= eng_money($p['EpAmount']) ?>
                        <span class="sub"> · <?= eng_h(eng_local_time($p['EpCreated'])) ?><?= $p['EpBy'] !== '' ? ' · ' . eng_h($p['EpBy']) : '' ?></span></div>
                    <?php if ($p['EpNote'] !== ''): ?><div class="sub"><?= eng_h($p['EpNote']) ?></div><?php endif; ?>
                    <form class="inline" method="post" action="<?= eng_h(eng_self_url($self, $f)) ?>">
                        <?= eng_token_field() ?><input type="hidden" name="account" value="<?= eng_h($a['key']) ?>">
                        <?php if ($a['status'] === 'diff' && $a['n'] > 0): ?>
                            <button type="submit" name="action" value="resync" class="warn"
                                data-confirm="Régulariser à <?= eng_h(eng_money($a['due'])) ?> (même moyen de paiement) ?">Régulariser</button>
                        <?php endif; ?>
                        <button type="submit" name="action" value="cancel" class="ghost"
                            data-confirm="Annuler le paiement de <?= eng_h(trim($a['first'] . ' ' . $a['name']) ?: $a['key']) ?> ?">Annuler</button>
                    </form>
                <?php else: ?>
                    <span class="sub">—</span>
                <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<script>
(function () {
    // Filtres : rechargement dès qu'une liste change.
    document.querySelectorAll('#filters select').forEach(function (s) {
        s.addEventListener('change', function () { s.form.submit(); });
    });
    // Confirmation des boutons sensibles.
    document.querySelectorAll('#eng [data-confirm]').forEach(function (b) {
        b.addEventListener('click', function (e) {
            if (!confirm(b.dataset.confirm)) e.preventDefault();
        });
    });
    var club = document.getElementById('payclub');
    if (club) club.addEventListener('submit', function (e) {
        // elements[...] : club.method désignerait l'attribut « post » du formulaire.
        var cs = club.elements['club'], ms = club.elements['method'];
        var o = cs.options[cs.selectedIndex];
        var m = ms.options[ms.selectedIndex].text;
        if (!confirm('Régler ' + o.dataset.sum + ' en « ' + m + ' » ?')) e.preventDefault();
    });
    // Chèque choisi : la note sert au numéro de chèque (reste facultative).
    document.querySelectorAll('#eng select[name=method]').forEach(function (s) {
        var note = s.form.elements['note'];
        if (!note) return;
        var base = note.placeholder;
        var sync = function () { note.placeholder = s.value === 'cheque' ? 'n° de chèque (facultatif)' : base; };
        s.addEventListener('change', sync);
        sync();
    });
    // Empêche le double envoi d'un formulaire de paiement.
    document.querySelectorAll('#eng form[method=post]').forEach(function (f) {
        f.addEventListener('submit', function (e) {
            if (e.defaultPrevented) return;
            if (f.dataset.sent) { e.preventDefault(); return; }
            f.dataset.sent = '1';
        });
    });
})();
</script>
<?php include($CFG->DOCUMENT_PATH . 'Common/Templates/tail.php'); ?>
