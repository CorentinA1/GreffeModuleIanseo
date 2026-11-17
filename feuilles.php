<?php
/**
 * feuilles.php — GREFFE : feuilles de marque complétées, par club ou par archer.
 *
 * Onglet proposé quand ScoreKeeper (NG, NG PRO ou NG Live) est activé sur la compétition, à qui peut lire les
 * qualifications. La feuille est celle d'IANSEO « toutes les distances sur une seule page », remplie des flèches
 * saisies (lib/scorecards.php). Le tableau n'apparaît que quand toutes les flèches de la compétition sont saisies ;
 * avant, la page dit combien de feuilles sont complètes et lesquelles manquent.
 *
 * Inspiré du module « Feuilles de marque par club ou archer » de Steph. Lecture seule.
 */
define('ENG_ACCESS', 'read');
require_once __DIR__ . '/lib/boot.php';
require_once __DIR__ . '/lib/scorecards.php';

/** Texte des fichiers de langue d'IANSEO, échappé (les entités déjà écrites restent telles quelles). */
function eng_sc_core_t($key, $module = 'Common')
{
    return htmlspecialchars(get_text($key, $module), ENT_QUOTES, 'UTF-8', false);
}

$available = eng_sc_available();
$comp      = eng_sc_competition();
$sessions  = $available ? eng_sc_sessions() : [];
$summary   = ($available && !$comp['field']) ? eng_sc_summary($sessions, $comp['distances']) : null;
$pdfUrl    = $ENG_ROOT . 'feuilles-pdf.php';

$PAGE_TITLE = 'Greffe - Feuilles de marque';
include($CFG->DOCUMENT_PATH . 'Common/Templates/head.php');
?>
<style>
#eng {
    --bleu:var(--header-dark); --sur-bleu:var(--row-main-text); --bleu-fonce:var(--main-text); --bleu-clair:var(--table-background);
    --bleu-survol:color-mix(in srgb, var(--header-dark) 82%, var(--black)); --lien:var(--thm-link, var(--header-dark));
    --gris:var(--text-dark); --sous:var(--text-muted); --bord:var(--input-border); --carte:var(--row-background);
    max-width:980px; margin:0 auto; color:var(--gris); font-size:13px; }
#eng .card { border:1px solid var(--bord); border-radius:6px; background:var(--carte); padding:10px 14px; margin:0 0 12px; }
#eng .card h3 { margin:0 0 8px; font-size:14px; color:var(--bleu-fonce); }
#eng .opts { display:flex; flex-wrap:wrap; gap:6px 22px; align-items:center; margin:0 0 6px; }
#eng .opts b { color:var(--bleu-fonce); }
#eng .sub { color:var(--sous); font-size:11px; }
#eng a.btn { border-radius:5px; padding:4px 10px; font-size:12px; font-weight:600; text-decoration:none; display:inline-block;
             background:var(--bleu); border:1px solid var(--bleu); color:var(--sur-bleu); white-space:nowrap; }
#eng a.btn:hover { background:var(--bleu-survol); }
#eng a.btn.small { padding:2px 8px; font-size:11px; }
#eng .msg { border-left:4px solid var(--text-warning, #ffc107); background:var(--carte); padding:6px 10px; margin:0 0 8px; }
#eng table.lst { width:100%; border-collapse:collapse; }
#eng table.lst th, #eng table.lst td { border-bottom:1px solid var(--bord); padding:4px 6px; text-align:left; }
#eng table.lst th { color:var(--bleu-fonce); }
#eng table.lst .num { text-align:right; white-space:nowrap; }
#eng table.lst tr.club td { background:var(--bleu-clair); font-weight:700; color:var(--bleu-fonce); }
#eng table.lst td.pdf { text-align:right; width:1%; }
#eng details summary { cursor:pointer; color:var(--lien); }
</style>

<div id="eng">
    <?= eng_nav('feuilles') ?>

<?php if (!$available): ?>
    <div class="msg">Cet onglet sert quand <b>Ianseo ScoreKeeper</b> (NG, NG PRO ou NG Live) est activé sur la compétition
        (fiche de la compétition, « Activer Ianseo ScoreKeeper »), et demande la lecture sur les qualifications.</div>
<?php elseif ($comp['field']): ?>
    <div class="msg">Les parcours (campagne, nature, 3D) ont leurs propres feuilles de marque : cet onglet ne s'y applique pas.</div>
<?php elseif (!$summary['archers']): ?>
    <div class="msg">Aucun archer placé sur une cible.</div>
<?php elseif (!$summary['scoring']['complete']): $sc = $summary['scoring']; ?>
    <div class="card">
        <h3>Feuilles de marque complétées</h3>
        <p>Les feuilles apparaissent quand <b>toutes les flèches de la compétition</b> sont saisies.
            Feuilles complètes pour l'instant : <b><?= $sc['done'] ?></b> sur <b><?= $sc['done'] + $sc['todo'] ?></b>.</p>
        <?php if ($sc['missing']): ?>
            <details><summary>Archers à compléter (<?= count($sc['missing']) ?>)</summary>
                <table class="lst"><thead><tr><th>Club</th><th>Archer</th><th class="num">Départ</th><th class="num">Cible</th><th class="num">Flèches saisies</th></tr></thead><tbody>
                <?php foreach ($sc['missing'] as $m): ?>
                    <tr><td><?= eng_h($m['club']) ?></td><td><?= eng_h($m['name']) ?></td><td class="num"><?= $m['session'] ?></td>
                        <td class="num"><?= eng_h($m['target']) ?></td><td class="num"><?= $m['shot'] ?> / <?= $m['expected'] ?: '?' ?></td></tr>
                <?php endforeach; ?>
                </tbody></table>
            </details>
        <?php endif; ?>
        <p class="sub">Un archer marqué non partant, abandon ou disqualifié (IRM) n'est pas attendu.</p>
    </div>
<?php else: ?>
    <form id="sc-form" class="card" autocomplete="off" onsubmit="return false">
        <h3>Feuilles de marque complétées <span class="sub">une page par archer, toutes ses distances, scores remplis</span></h3>
        <?php if (count($sessions) > 1): ?>
            <div class="opts"><b>Départs</b>
                <?php foreach ($sessions as $o => $label): ?>
                    <label><input type="checkbox" name="Sessions[]" value="<?= $o ?>" checked> <?= eng_h($label) ?></label>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <div class="opts"><b>Mise en page</b>
            <label><input type="checkbox" name="ScorePageHeaderFooter" value="1" checked> <?= eng_sc_core_t('ScorePageHeaderFooter', 'Tournament') ?></label>
            <label><input type="checkbox" name="ScoreHeader" value="1"> <?= eng_sc_core_t('ScoreTournament', 'Tournament') ?></label>
            <label><input type="checkbox" name="ScoreLogos" value="1"> <?= eng_sc_core_t('ScoreLogos', 'Tournament') ?></label>
            <label><input type="checkbox" name="ScoreFlags" value="1" checked> <?= eng_sc_core_t('ScoreFlags', 'Tournament') ?></label>
        </div>
        <div class="opts"><b>Archive ZIP</b>
            <label><input type="radio" name="Mode" value="club" checked> un PDF par club</label>
            <label><input type="radio" name="Mode" value="archer"> un PDF par archer</label>
            <?php if (class_exists('ZipArchive')): ?>
                <a class="btn" href="#" id="sc-zip">⬇ Télécharger l'archive</a>
                <progress id="sc-progress" max="1" value="0" hidden></progress>
                <span id="sc-status" role="status"></span>
            <?php else: ?>
                <span class="sub">Indisponible : l'extension « zip » de PHP manque sur ce serveur.</span>
            <?php endif; ?>
            <?= eng_token_field() ?>
        </div>
        <div class="opts"><b>Affichage</b>
            <label><input type="radio" name="sc-view" value="club" checked> par club</label>
            <label><input type="radio" name="sc-view" value="archer"> par archer (regroupés par club)</label>
            <a class="btn sc-pdf" href="#" target="_blank" data-all="1">📄 Toutes les feuilles</a>
        </div>

        <table class="lst" id="sc-clubs"><thead><tr><th>Code</th><th>Club</th><th class="num">Archers</th><th></th></tr></thead><tbody>
        <?php foreach ($summary['clubs'] as $club): ?>
            <tr><td><?= eng_h($club['code']) ?></td><td><?= $club['code'] === '' ? '<i>Sans club</i>' : eng_h($club['name']) ?></td>
                <td class="num"><?= count($club['archers']) ?></td>
                <td class="pdf"><a class="btn small sc-pdf" href="#" target="_blank" data-club="<?= eng_h($club['code']) ?>">📄 PDF du club</a></td></tr>
        <?php endforeach; ?>
        </tbody><tfoot><tr><th></th><th>Total</th><th class="num"><?= $summary['archers'] ?></th><th></th></tr></tfoot></table>

        <table class="lst" id="sc-archers" hidden><thead><tr><th>Archer</th><th>Licence</th><th>Catégorie</th><th class="num">Départ</th>
            <th class="num">Cible</th><th class="num">Score</th><th></th></tr></thead><tbody>
        <?php foreach ($summary['clubs'] as $club): $n = count($club['archers']); ?>
            <tr class="club"><td colspan="6"><?= $club['code'] === '' ? 'Sans club' : eng_h($club['code'] . ' — ' . $club['name']) ?>
                <span class="sub">(<?= $n ?> archer<?= $n > 1 ? 's' : '' ?>)</span></td>
                <td class="pdf"><a class="btn small sc-pdf" href="#" target="_blank" data-club="<?= eng_h($club['code']) ?>">📄 Club</a></td></tr>
            <?php foreach ($club['archers'] as $a): ?>
                <tr><td><?= eng_h($a['name']) ?></td><td><?= eng_h($a['licence']) ?></td><td><?= eng_h($a['cat']) ?></td>
                    <td class="num"><?= $a['session'] ?></td><td class="num"><?= eng_h($a['target']) ?></td>
                    <td class="num"><?= $a['irm'] ? '<span class="sub">IRM</span>' : $a['score'] ?></td>
                    <td class="pdf"><a class="btn small sc-pdf" href="#" target="_blank" data-archer="<?= $a['id'] ?>">📄 PDF</a></td></tr>
            <?php endforeach; ?>
        <?php endforeach; ?>
        </tbody></table>
    </form>
    <p class="sub">Fonctionnalité inspirée du module « Feuilles de marque par club ou archer » de Steph
        (<a href="https://github.com/Steph-Krs/IanseoModules" target="_blank" rel="noopener">IanseoModules</a>).</p>
<?php endif; ?>

    <?= eng_footer() ?>
</div>

<script>
(function () {
    var form = document.getElementById('sc-form');
    if (!form) return;
    // Affichage par club ou par archer.
    form.querySelectorAll('input[name="sc-view"]').forEach(function (r) {
        r.addEventListener('change', function () {
            document.getElementById('sc-clubs').hidden = r.value !== 'club';
            document.getElementById('sc-archers').hidden = r.value !== 'archer';
        });
    });
    // L'en-tête de page remplace l'en-tête et les logos de la compétition, comme dans l'impression d'IANSEO.
    form.addEventListener('change', function (ev) {
        var n = ev.target.name;
        if (n === 'ScorePageHeaderFooter' && ev.target.checked) { form.elements.ScoreHeader.checked = false; form.elements.ScoreLogos.checked = false; }
        if ((n === 'ScoreHeader' || n === 'ScoreLogos') && ev.target.checked) form.elements.ScorePageHeaderFooter.checked = false;
    });
    // PDF d'un archer, d'un club ou de tous, avec les options du moment du clic.
    form.addEventListener('click', function (ev) {
        var a = ev.target.closest('a.sc-pdf');
        if (!a) return;
        var p = new URLSearchParams();
        new FormData(form).forEach(function (v, k) { if (k !== 'sc-view' && k !== 'Mode' && k !== 'eng_token') p.append(k, v); });
        if (a.hasAttribute('data-archer')) p.set('archer', a.getAttribute('data-archer'));
        else if (a.hasAttribute('data-club')) p.set('club', a.getAttribute('data-club'));
        a.href = <?= json_encode($pdfUrl) ?> + '?' + p.toString();
    });

    // Archive : un appel pour démarrer, un par lot de fichiers (barre de progression), un pour l'archive.
    var btn = document.getElementById('sc-zip');
    if (!btn) return;
    var api = <?= json_encode($ENG_ROOT . 'feuilles-zip.php') ?>, busy = false;
    var bar = document.getElementById('sc-progress'), status = document.getElementById('sc-status');
    function say(t, err) { status.textContent = t; status.style.color = err ? 'var(--text-danger, #dc3545)' : ''; }
    function call(body) {
        return fetch(api, {method: 'POST', body: body, credentials: 'same-origin'}).then(function (res) {
            return res.text().then(function (t) {
                var d;
                try { d = JSON.parse(t); } catch (e) { throw new Error(t && t.length < 200 ? t : 'Erreur du serveur (' + res.status + ').'); }
                if (d.error) throw new Error(d.msg || 'Erreur du serveur.');
                return d;
            });
        });
    }
    btn.addEventListener('click', function (ev) {
        ev.preventDefault();
        if (busy) return;
        var token = form.elements.eng_token.value;
        function step(job, done, total) {
            bar.max = total; bar.value = done;
            say(done + ' fichier' + (done > 1 ? 's' : '') + ' sur ' + total + '…');
            if (done < total) {
                return call(new URLSearchParams({do: 'step', job: job, eng_token: token}))
                    .then(function (r) { return step(job, r.done, r.total); });
            }
            say('Archive en cours…');
            return call(new URLSearchParams({do: 'finish', job: job, eng_token: token}))
                .then(function (r) { say('Archive prête.'); window.location.href = r.url; });
        }
        var body = new URLSearchParams();
        new FormData(form).forEach(function (v, k) { if (k !== 'sc-view') body.append(k, v); });
        body.set('do', 'start');
        busy = true; bar.hidden = false; bar.removeAttribute('value');
        say('Préparation…');
        call(body)
            .then(function (r) { return step(r.job, r.done, r.total); })
            .catch(function (e) { say(e.message, true); })
            .then(function () { busy = false; bar.hidden = true; });
    });
})();
</script>
<?php include($CFG->DOCUMENT_PATH . 'Common/Templates/tail.php'); ?>
