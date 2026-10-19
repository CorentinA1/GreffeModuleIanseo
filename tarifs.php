<?php
/**
 * tarifs.php — GREFFE : configuration des frais d'inscription.
 *
 * Règles (libellé, divisions, classes, clubs, grille de prix TOTAL par nombre
 * de départs), grille par défaut, tarif de base par départ, mention en pied de
 * reçu. La grille a une case par session de la compétition.
 *
 * L'aperçu en direct envoie le formulaire en cours (non enregistré) à cette
 * même page (action=preview) : le calcul reste celui de lib/pricing.php, sans
 * copie en JavaScript.
 */
define('ENG_ACCESS', 'write');
require_once __DIR__ . '/lib/boot.php';

$tour     = eng_tournament();
$sessions = $tour->Sessions;
$divs     = eng_codes('div');
$clss     = eng_codes('cls');
$clubs    = eng_codes('club');
$org      = eng_org_code();
$action   = $_POST['action'] ?? '';

/** Mention habituelle d'une association non assujettie à la TVA (proposée, jamais imposée). */
const ENG_NOTE_SUGGESTION = "Association loi 1901 — TVA non applicable, article 293 B du Code général des impôts.";

// ── Aperçu en direct (JSON) ──────────────────────────────────────────────────
if ($action === 'preview') {
    eng_check_token();
    $config = eng_config_from_post($_POST, $sessions, $org);
    $n = max(1, min($sessions, intval($_POST['p_n'] ?? 1)));
    $p = eng_price($config, (string) ($_POST['p_div'] ?? ''), (string) ($_POST['p_cls'] ?? ''), $n, (string) ($_POST['p_club'] ?? ''));
    $keys = array_keys(eng_posted_rules($_POST));   // indice de règle → clé dans le formulaire
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'total' => eng_money($p['total']),
        'label' => $p['label'],
        'key'   => $p['rule'] === null ? null : (string) $keys[$p['rule']],
        'short' => $p['short'],
    ]);
    exit;
}

// ── Enregistrement ───────────────────────────────────────────────────────────
$errors = $warnings = [];
if ($action === 'save') {
    eng_check_token();
    $config = eng_config_from_post($_POST, $sessions, $org);
    $check  = eng_validate_config($config, $_POST);
    if (!$check['errors']) {
        eng_save_config($config);
        $_SESSION['ENG_FLASH'] = ['ok' => 'Tarifs enregistrés.', 'warnings' => $check['warnings']];
        header('Location: ' . $ENG_ROOT . 'tarifs.php');
        exit;
    }
    // Erreur : on réaffiche la saisie telle quelle, rien n'est enregistré.
    $errors = $check['errors'];
    $warnings = $check['warnings'];
    $form = [
        'base'    => (string) ($_POST['base'] ?? ''),
        'default' => is_array($_POST['default'] ?? null) ? $_POST['default'] : [],
        'rules'   => eng_posted_rules($_POST),
        'note'    => (string) ($_POST['note'] ?? ''),
    ];
} else {
    $config = eng_load_config();
    $form = [
        'base'    => eng_amount_input($config['base'] ?: null),
        'default' => array_map('eng_amount_input', $config['default']),
        'rules'   => array_map(function ($r) {
            $r['grid'] = array_map('eng_amount_input', $r['grid']);
            return $r;
        }, $config['rules']),
        'note'    => $config['note'],
    ];
    if (!empty($config['rules']) || !eng_grid_is_empty($config['default'])) {
        $warnings = eng_validate_config($config)['warnings'];
    }
}

$flash = $_SESSION['ENG_FLASH'] ?? null;
unset($_SESSION['ENG_FLASH']);
if ($flash) $warnings = $flash['warnings'];

/**
 * Cases à cocher d'une liste de codes. Un code enregistré mais absent de la
 * compétition (division supprimée depuis) reste affiché, coché, et signalé.
 */
function eng_chips($name, array $known, array $checked)
{
    $all = $known;
    foreach ($checked as $c) {
        if (!isset($all[$c])) $all[$c] = null;
    }
    $html = '';
    foreach ($all as $code => $descr) {
        $on = in_array($code, $checked, true);
        $title = $descr === null ? 'Code absent de cette compétition' : $descr;
        $html .= '<label class="chip' . ($descr === null ? ' unknown' : '') . '" title="' . eng_h($title) . '">'
            . '<input type="checkbox" name="' . eng_h($name) . '[]" value="' . eng_h($code) . '"' . ($on ? ' checked' : '') . '>'
            . eng_h($code) . '</label>';
    }
    return $html;
}

/** Ligne de saisie d'une grille : une case par nombre de départs. */
function eng_grid_inputs($name, array $values, $sessions)
{
    $html = '<div class="grid">';
    for ($n = 1; $n <= $sessions; $n++) {
        $html .= '<label><span>' . $n . ' départ' . ($n > 1 ? 's' : '') . '</span>'
            . '<input type="text" inputmode="decimal" name="' . eng_h($name) . '[' . $n . ']" value="'
            . eng_h($values[$n] ?? '') . '" placeholder="—"></label>';
    }
    return $html . '</div>';
}

/**
 * Critère de club d'une règle : liste du mode, puis les clubs à cocher (visibles
 * pour « Clubs choisis » seulement).
 */
function eng_club_field($p, array $r, array $clubs, $org)
{
    $mode = (string) ($r['club'] ?? 'all');
    if (!isset(eng_club_modes()[$mode])) $mode = 'all';
    $checked = is_array($r['clubs'] ?? null) ? array_map('strtoupper', $r['clubs']) : [];
    $orgText = $org !== '' ? $org . (($clubs[$org] ?? '') !== '' ? ' — ' . $clubs[$org] : '') : 'non renseigné';
    $html = '<div class="field"><b>Clubs</b> <small>club organisateur : ' . eng_h($orgText) . '</small><div>'
        . '<select class="club-mode" name="' . eng_h($p) . '[club]">';
    foreach (eng_club_modes() as $k => $l) {
        $html .= '<option value="' . eng_h($k) . '"' . ($k === $mode ? ' selected' : '') . '>' . eng_h($l) . '</option>';
    }
    return $html . '</select></div><div class="chips club-list"' . ($mode === 'list' ? '' : ' hidden') . '>'
        . eng_chips($p . '[clubs]', $clubs, $checked) . '</div></div>';
}

/** Bloc d'une règle. $i vaut « __I__ » dans le modèle utilisé pour en ajouter. */
function eng_rule_block($i, array $r, array $divs, array $clss, array $clubs, $org, $sessions)
{
    $p = 'rules[' . $i . ']';
    $div = is_array($r['div'] ?? null) ? array_map('strtoupper', $r['div']) : [];
    $cls = is_array($r['cls'] ?? null) ? array_map('strtoupper', $r['cls']) : [];
    return '<div class="rule" data-i="' . eng_h($i) . '">'
        . '<div class="rule-head"><span class="num"></span>'
        . '<input type="text" class="label" name="' . $p . '[label]" maxlength="60" placeholder="Libellé (ex. Jeunes)" value="' . eng_h($r['label'] ?? '') . '">'
        . '<span class="tools">'
        . '<button type="button" data-move="-1" title="Monter">▲</button>'
        . '<button type="button" data-move="1" title="Descendre">▼</button>'
        . '<button type="button" data-del title="Supprimer la règle">✕</button>'
        . '</span></div>'
        . '<div class="field"><b>Divisions</b> <small>aucune cochée = toutes</small><div class="chips">'
        . eng_chips($p . '[div]', $divs, $div) . '</div></div>'
        . '<div class="field"><b>Classes</b> <small>aucune cochée = toutes</small><div class="chips">'
        . eng_chips($p . '[cls]', $clss, $cls) . '</div></div>'
        . eng_club_field($p, $r, $clubs, $org)
        . '<div class="field"><b>Prix total selon le nombre de départs</b>'
        . eng_grid_inputs($p . '[grid]', is_array($r['grid'] ?? null) ? $r['grid'] : [], $sessions) . '</div>'
        . '</div>';
}

$PAGE_TITLE = 'Greffe - Tarifs';
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
       max-width:980px;
       color:var(--gris); font-size:13px; }
#eng .card { border:1px solid var(--bord); border-radius:6px; background:var(--carte);
             box-shadow:0 1px 3px rgba(0,0,0,.08); margin-bottom:16px; }
#eng .card > h3 { margin:0; padding:10px 14px; font-size:14px; color:var(--sur-bleu);
                  background:var(--bleu); border-radius:5px 5px 0 0; }
#eng .card > div { padding:14px; }
#eng .banner { background:var(--bleu-clair); border-left:4px solid var(--bleu);
               border-radius:0 6px 6px 0; padding:10px 14px; margin-bottom:16px; }
#eng .banner b { color:var(--bleu-fonce); }
#eng .msg { border-radius:6px; padding:10px 14px; margin-bottom:12px; }
#eng .msg ul { margin:4px 0 0 18px; padding:0; }
#eng .msg.ok   { background:var(--ok-fond); border:1px solid var(--ok-bord); color:var(--vert); }
#eng .msg.err  { background:var(--err-fond); border:1px solid var(--err-bord); color:var(--rouge); }
#eng .msg.warn { background:var(--warn-fond); border:1px solid var(--warn-bord); color:var(--orange); }
#eng .rule { border:1px solid var(--bord); border-radius:6px; padding:10px 12px; margin-bottom:10px; background:var(--fond); }
#eng .rule.hit { border-color:var(--bleu); box-shadow:0 0 0 2px var(--bleu-clair); }
#eng .rule-head { display:flex; align-items:center; gap:8px; margin-bottom:6px; }
#eng .rule-head .num { font-weight:bold; color:var(--bleu-fonce); min-width:22px; }
#eng .rule-head .label { flex:1; font-weight:600; }
#eng .tools button { border:1px solid var(--bord); background:var(--carte); border-radius:4px; cursor:pointer; padding:2px 7px; }
#eng .tools button:hover { background:var(--bleu-clair); }
#eng .field { margin:8px 0 0; }
#eng .field small { color:var(--sous); }
#eng .chips { display:flex; flex-wrap:wrap; gap:4px; margin-top:4px; }
#eng .chip { display:inline-flex; align-items:center; gap:3px; border:1px solid var(--bord); background:var(--carte);
             border-radius:12px; padding:1px 8px 1px 4px; cursor:pointer; font-size:12px; }
#eng .chip:has(input:checked) { background:var(--bleu); border-color:var(--bleu); color:var(--sur-bleu); }
#eng .chip.unknown { border-style:dashed; border-color:var(--rouge); }
#eng .grid { display:flex; flex-wrap:wrap; gap:8px; margin-top:4px; }
#eng .grid label { display:flex; flex-direction:column; font-size:11px; color:var(--sous); }
#eng input[type=text] { background:var(--champ); color:var(--champ-texte); padding:5px 7px; border:1px solid var(--bord); border-radius:5px; font-size:13px; }
#eng .grid input { width:78px; text-align:right; }
#eng textarea { display:block; width:100%; box-sizing:border-box; margin:4px 0 6px; background:var(--champ); color:var(--champ-texte);
                padding:5px 7px; border:1px solid var(--bord); border-radius:5px; font:inherit; }
#eng button.small { padding:3px 10px; font-size:12px; }
#eng .chips[hidden] { display:none; }
#eng select { background:var(--champ); color:var(--champ-texte); padding:5px 7px; border:1px solid var(--bord); border-radius:5px; font-size:13px; }
#eng button.primary { background:var(--bleu); border:1px solid var(--bleu); color:var(--sur-bleu); font-weight:600;
                      padding:9px 20px; font-size:14px; border-radius:6px; cursor:pointer; }
#eng button.primary:hover { background:var(--bleu-survol); }
#eng button.secondary { background:var(--carte); border:1px solid var(--bleu); color:var(--lien); font-weight:600;
                        padding:6px 14px; border-radius:6px; cursor:pointer; }
#eng .layout { display:flex; gap:16px; align-items:flex-start; }
#eng .layout > .main { flex:1; min-width:0; }
#eng .layout > .side { width:270px; position:sticky; top:10px; }
#eng .preview .total { font-size:26px; font-weight:bold; color:var(--bleu-fonce); margin:10px 0 2px; }
#eng .preview .rule-name { font-weight:600; }
#eng .preview .short { color:var(--orange); margin-top:6px; display:none; }
#eng .preview label { display:block; margin-bottom:8px; }
#eng .preview select { width:100%; margin-top:2px; }
#eng .help { color:var(--sous); font-size:12px; margin:0 0 10px; }
#eng .savebar { display:flex; align-items:center; gap:12px; }
#eng .dirty { color:var(--orange); display:none; }
@media (max-width: 860px) { #eng .layout { flex-direction:column; } #eng .layout > .side { width:auto; position:static; } }
</style>

<div id="eng">
    <?= eng_nav('tarifs') ?>

    <div class="banner">
        <b><?= eng_h($tour->ToName ?? '') ?></b><br>
        <?= $sessions ?> session<?= $sessions > 1 ? 's' : '' ?> : chaque grille a <?= $sessions ?> case<?= $sessions > 1 ? 's' : '' ?>,
        une par nombre de départs. Les prix sont des <b>totaux</b> (ex. 2 départs = 17 €), pas des prix par départ.
    </div>

    <?php $engAdmin = hasFullACL(AclRoot, '', AclReadWrite) && (trim((string) ($_SESSION['AUTH_User'] ?? '')) === '' || !empty($_SESSION['AUTH_ROOT'])); ?>
    <div class="msg warn"><b>Module indépendant d'IANSEO, sans garantie ni SAV</b> : il n'est pas développé par l'équipe
        IANSEO et peut contenir des bugs ; vérifiez les montants. <b>Sauvegardez les tarifs et paiements</b> : ils ne sont
        pas dans l'export de la compétition (<a href="<?= eng_h($ENG_ROOT . 'sauvegarde.php') ?>">page Sauvegarde</a><?= $engAdmin ? ', ou <a href="' . eng_h($ENG_ROOT . 'admin/uninstall.php?backup=1') . '">sauvegarde .sql de toutes les compétitions</a>' : '' ?>).
        Détails : <a href="https://github.com/CorentinA1/GreffeModuleIanseo/blob/main/docs/AVERTISSEMENT.md" target="_blank" rel="noopener">À lire avant d'utiliser le module</a>.</div>
    <div class="msg" style="background:var(--bleu-clair);border:1px solid var(--bleu)">📖 <b>Mode d'emploi</b> : tarifs, paiements, rapport et
        bilan, reçus et droits d'accès sont décrits dans l'<a href="https://github.com/CorentinA1/GreffeModuleIanseo/blob/main/docs/UTILISATION.md" target="_blank" rel="noopener">utilisation</a>.</div>

    <?php if ($flash): ?>
        <div class="msg ok"><?= eng_h($flash['ok']) ?></div>
    <?php endif; ?>
    <?php if ($errors): ?>
        <div class="msg err"><b>Rien n'a été enregistré.</b> Corrigez :
            <ul><?php foreach ($errors as $e): ?><li><?= eng_h($e) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>
    <?php if ($warnings): ?>
        <div class="msg warn"><b>À vérifier :</b>
            <ul><?php foreach ($warnings as $w): ?><li><?= eng_h($w) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>

    <form id="eng-form" method="post" action="<?= eng_h($ENG_ROOT . 'tarifs.php') ?>">
        <?= eng_token_field() ?>
        <input type="hidden" name="action" value="save">

        <div class="layout">
            <div class="main">
                <div class="card">
                    <h3>Règles tarifaires</h3>
                    <div>
                        <p class="help">Pour chaque archer, <b>la première règle qui correspond</b> à sa division, sa
                            classe et son club s'applique. L'ordre compte : placez les règles les plus précises en haut
                            (par exemple « Jeunes du club organisateur » avant « Jeunes »).</p>
                        <div id="rules">
                            <?php foreach (array_values($form['rules']) as $i => $r) echo eng_rule_block($i, $r, $divs, $clss, $clubs, $org, $sessions); ?>
                        </div>
                        <button type="button" class="secondary" id="add-rule">+ Ajouter une règle</button>
                    </div>
                </div>

                <div class="card">
                    <h3>Si aucune règle ne correspond</h3>
                    <div>
                        <div class="field"><b>Grille par défaut</b> <small>facultative : toute la grille, ou rien</small>
                            <?= eng_grid_inputs('default', $form['default'], $sessions) ?></div>
                        <div class="field"><b>Tarif de base, par départ</b>
                            <small>utilisé seulement si la grille par défaut est vide : total = tarif × nombre de départs</small>
                            <div class="grid"><label><span>&nbsp;</span>
                                <input type="text" inputmode="decimal" name="base" value="<?= eng_h($form['base']) ?>" placeholder="0"></label></div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <h3>Reçus</h3>
                    <div>
                        <div class="field"><b>Mention en pied de reçu</b>
                            <small>facultative, imprimée sur les reçus et récapitulatifs ; vide = aucune mention</small>
                            <textarea name="note" rows="3" maxlength="<?= ENG_NOTE_MAX ?>"
                                placeholder="Ex. : <?= eng_h(ENG_NOTE_SUGGESTION) ?>"><?= eng_h($form['note']) ?></textarea>
                            <button type="button" class="secondary small" id="note-suggest"
                                data-text="<?= eng_h(ENG_NOTE_SUGGESTION) ?>">Insérer la mention habituelle des associations</button>
                            <p class="help" style="margin-top:6px">À n'utiliser que si elle s'applique à votre club : renseignez-vous
                                auprès de votre trésorier.</p>
                        </div>
                    </div>
                </div>

                <div class="savebar">
                    <button class="primary" type="submit">Enregistrer les tarifs</button>
                    <span class="dirty" id="dirty">Modifications non enregistrées</span>
                </div>
            </div>

            <div class="side">
                <div class="card preview">
                    <h3>Aperçu</h3>
                    <div>
                        <p class="help">Calculé sur la saisie en cours, même non enregistrée.</p>
                        <label>Division
                            <select name="p_div"><?php foreach ($divs as $c => $d): ?>
                                <option value="<?= eng_h($c) ?>"><?= eng_h($c . ($d !== '' ? ' — ' . $d : '')) ?></option>
                            <?php endforeach; ?></select></label>
                        <label>Classe
                            <select name="p_cls"><?php foreach ($clss as $c => $d): ?>
                                <option value="<?= eng_h($c) ?>"><?= eng_h($c . ($d !== '' ? ' — ' . $d : '')) ?></option>
                            <?php endforeach; ?></select></label>
                        <label>Club
                            <select name="p_club">
                                <?php if ($org !== ''): ?><option value="<?= eng_h($org) ?>">Club organisateur (<?= eng_h($org) ?>)</option><?php endif; ?>
                                <option value="">Un autre club</option>
                                <?php foreach ($clubs as $c => $d): if ($c === $org) continue; ?>
                                <option value="<?= eng_h($c) ?>"><?= eng_h($c . ($d !== '' ? ' — ' . $d : '')) ?></option>
                                <?php endforeach; ?></select></label>
                        <label>Nombre de départs
                            <select name="p_n"><?php for ($n = 1; $n <= $sessions; $n++): ?>
                                <option value="<?= $n ?>"><?= $n ?></option>
                            <?php endfor; ?></select></label>
                        <div class="total" id="pv-total">—</div>
                        <div>Règle : <span class="rule-name" id="pv-label">—</span></div>
                        <div class="short" id="pv-short">Grille incomplète : dernier total saisi appliqué.</div>
                    </div>
                </div>
            </div>
        </div>
    </form>

    <template id="rule-template"><?= eng_rule_block('__I__', [], $divs, $clss, $clubs, $org, $sessions) ?></template>
</div>

<script>
(function () {
    var form = document.getElementById('eng-form');
    var rules = document.getElementById('rules');
    var tpl = document.getElementById('rule-template');
    var next = rules.querySelectorAll('.rule').length;
    var dirty = false;

    function renumber() {
        rules.querySelectorAll('.rule').forEach(function (el, k) {
            el.querySelector('.num').textContent = (k + 1) + '.';
        });
    }

    function markDirty() {
        dirty = true;
        document.getElementById('dirty').style.display = 'inline';
    }

    document.getElementById('add-rule').addEventListener('click', function () {
        var holder = document.createElement('div');
        holder.innerHTML = tpl.innerHTML.replace(/__I__/g, 'n' + (next++));
        var el = holder.firstElementChild;
        rules.appendChild(el);
        renumber();
        el.querySelector('.label').focus();
        markDirty();
        preview();
    });

    // Critère de club : les clubs à cocher n'apparaissent que pour « Clubs choisis ».
    rules.addEventListener('change', function (e) {
        if (!e.target.classList.contains('club-mode')) return;
        e.target.closest('.field').querySelector('.club-list').hidden = e.target.value !== 'list';
    });

    var suggest = document.getElementById('note-suggest');
    suggest.addEventListener('click', function () {
        var note = form.elements['note'];
        var text = suggest.dataset.text;
        if (note.value.indexOf(text) !== -1) return;
        note.value = note.value.trim() === '' ? text : note.value.replace(/\s+$/, '') + '\n' + text;
        markDirty();
    });

    rules.addEventListener('click', function (e) {
        var btn = e.target.closest('button');
        if (!btn) return;
        var rule = btn.closest('.rule');
        if (btn.hasAttribute('data-del')) {
            var name = rule.querySelector('.label').value || 'cette règle';
            if (!confirm('Supprimer ' + name + ' ?')) return;
            rule.remove();
        } else if (btn.dataset.move === '-1' && rule.previousElementSibling) {
            rules.insertBefore(rule, rule.previousElementSibling);
        } else if (btn.dataset.move === '1' && rule.nextElementSibling) {
            rules.insertBefore(rule.nextElementSibling, rule);
        } else {
            return;
        }
        renumber();
        markDirty();
        preview();
    });

    // Aperçu : le formulaire courant part au moteur PHP, au plus une requête à la fois.
    var timer = null, pending = false, running = false;
    function preview() {
        clearTimeout(timer);
        timer = setTimeout(run, 200);
    }
    function run() {
        if (running) { pending = true; return; }
        running = true;
        var data = new FormData(form);
        data.set('action', 'preview');
        // getAttribute : form.action désignerait le champ caché name="action".
        fetch(form.getAttribute('action'), { method: 'POST', body: data, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                document.getElementById('pv-total').textContent = j.total;
                document.getElementById('pv-label').textContent = j.label;
                document.getElementById('pv-short').style.display = j.short ? 'block' : 'none';
                rules.querySelectorAll('.rule').forEach(function (el) {
                    el.classList.toggle('hit', j.key === el.dataset.i);
                });
            })
            .catch(function () { document.getElementById('pv-total').textContent = '?'; })
            .finally(function () {
                running = false;
                if (pending) { pending = false; run(); }
            });
    }

    form.addEventListener('input', function (e) {
        if (!/^p_/.test(e.target.name)) markDirty();
        preview();
    });
    form.addEventListener('change', function (e) {
        if (e.target.type === 'checkbox') markDirty();
        preview();
    });
    form.addEventListener('submit', function () { dirty = false; });
    window.addEventListener('beforeunload', function (e) {
        if (dirty) { e.preventDefault(); e.returnValue = ''; }
    });

    renumber();
    preview();
<?php if ($errors): ?>
    markDirty();
<?php endif; ?>
})();
</script>
<?php include($CFG->DOCUMENT_PATH . 'Common/Templates/tail.php'); ?>
