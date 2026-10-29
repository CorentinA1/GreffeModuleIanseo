<?php
/**
 * editions.php — GREFFE : toutes les éditions PDF du module sur une page.
 *
 * Rangées par moment du concours : à la table de greffe (liste papier, plan de cible avec blasons),
 * pour les clubs (récapitulatif d'un club, de tous les clubs), après le concours (bilan, état des
 * chèques, état complet). La page ne fait que des liens vers les PDF existants (liste.php, plan.php,
 * recu.php, reporting.php) ; lecture seule, comme eux. Le reçu d'un archer reste sur sa ligne,
 * dans Paiements ; les PDF et CSV restent aussi dans Rapport - Bilan.
 */
define('ENG_ACCESS', 'read');
require_once __DIR__ . '/lib/boot.php';
require_once __DIR__ . '/lib/accounts.php';
require_once __DIR__ . '/lib/report.php';

$tour     = eng_tournament();
$accounts = eng_load_accounts($ENG_TOUR, eng_load_config());
$report   = eng_report($accounts);
$rep      = $ENG_ROOT . 'reporting.php';

// Clubs ayant au moins un archer inscrit (récapitulatif d'un club).
$clubs = [];
foreach ($accounts as $a) {
    if ($a['n'] > 0) $clubs[$a['club_id']] = trim($a['club_code'] . ' — ' . $a['club_name'], ' —');
}
asort($clubs, SORT_FLAG_CASE | SORT_STRING);
$hasCheques = isset($report['methods']['cheque']);
$ses = intval($_GET['depart'] ?? 0);   // départ filtré dans Paiements, présélectionné

$PAGE_TITLE = 'Greffe - Éditions';
include($CFG->DOCUMENT_PATH . 'Common/Templates/head.php');
?>
<style>
#eng {
    /* Couleurs : variables d'IANSEO (Common/Styles/colors.css), redéfinies par le module THEME pour chaque palette
       et pour le mode sombre. */
    --bleu:var(--header-dark); --sur-bleu:var(--row-main-text); --bleu-fonce:var(--main-text); --bleu-clair:var(--table-background);
    --bleu-survol:color-mix(in srgb, var(--header-dark) 82%, var(--black)); --lien:var(--thm-link, var(--header-dark));
    --gris:var(--text-dark); --sous:var(--text-muted); --bord:var(--input-border);
    --carte:var(--row-background); --champ:var(--text-white); --champ-texte:var(--black);
    max-width:980px; margin:0 auto; color:var(--gris); font-size:13px; }
#eng .group { margin:0 0 18px; }
#eng .group h3 { margin:0 0 8px; font-size:14px; color:var(--bleu-fonce); border-bottom:2px solid var(--bleu-clair); padding-bottom:4px; }
#eng .group h3 small { font-weight:400; color:var(--sous); font-size:12px; margin-left:6px; }
#eng .cards { display:grid; grid-template-columns:repeat(auto-fit, minmax(300px, 1fr)); gap:10px; }
#eng .card { border:1px solid var(--bord); border-radius:6px; background:var(--carte); padding:10px 14px; margin:0;
             display:flex; flex-direction:column; gap:8px; min-width:0; }
#eng .card b { color:var(--bleu-fonce); }
#eng .card p { margin:0; }
#eng .card .row { display:flex; flex-wrap:wrap; gap:6px; align-items:center; margin-top:auto; }
#eng .sub { color:var(--sous); font-size:11px; }
#eng select { padding:5px 7px; border:1px solid var(--bord); background:var(--champ); color:var(--champ-texte);
              border-radius:5px; font-size:13px; max-width:100%; }
#eng button { border-radius:5px; cursor:pointer; padding:4px 10px; font-size:12px; font-weight:600;
              background:var(--bleu); border:1px solid var(--bleu); color:var(--sur-bleu); }
#eng button:hover { background:var(--bleu-survol); }
#eng button:disabled { opacity:.5; cursor:default; }
</style>

<div id="eng">
    <?= eng_nav('editions') ?>

    <div class="group">
        <h3>À la table de greffe <small>à imprimer avant le concours</small></h3>
        <div class="cards">
            <form class="card" method="get" action="<?= eng_h($ENG_ROOT . 'liste.php') ?>" target="_blank">
                <b>Liste papier du greffe</b>
                <p>Les archers et ce qu'ils doivent, une colonne par moyen de paiement pour pointer à la main.</p>
                <div class="row">
                    <select name="depart">
                        <option value="all">Tous les départs confondus</option>
                        <option value="each">Par départ (une page par départ)</option>
                        <?php for ($s = 1; $s <= $tour->Sessions; $s++): ?><option value="<?= $s ?>"<?= $ses === $s ? ' selected' : '' ?>>Départ <?= $s ?> seulement</option><?php endfor; ?>
                    </select>
                    <select name="only">
                        <option value="all">Tous les archers</option>
                        <option value="unpaid">Seulement ceux qui doivent encore</option>
                    </select>
                    <select name="sort">
                        <option value="name">Tri par nom</option>
                        <option value="club">Tri par club</option>
                    </select>
                    <button type="submit">Éditer la liste</button>
                </div>
                <div class="sub">Par départ, chaque archer figure au départ où il règle (son premier départ) ;
                    ses départs suivants le rappellent, pour ne pas le faire payer deux fois.</div>
            </form>
            <form class="card" method="get" action="<?= eng_h($ENG_ROOT . 'plan.php') ?>" target="_blank">
                <b>Plan de cible avec blasons</b>
                <p>Le plan de cible d'IANSEO, blason de chaque archer en code court : « 60 » pour un blason unique de 60 cm,
                    « T40 (CL) » / « T40 (CO) » pour un trispot classique / poulie. Légende en fin de document.</p>
                <div class="row">
                    <select name="depart">
                        <option value="">Tous les départs</option>
                        <?php for ($s = 1; $s <= $tour->Sessions; $s++): ?><option value="<?= $s ?>"<?= $ses === $s ? ' selected' : '' ?>>Départ <?= $s ?> seulement</option><?php endfor; ?>
                    </select>
                    <button type="submit">Éditer le plan</button>
                </div>
            </form>
        </div>
    </div>

    <div class="group">
        <h3>Pour les clubs <small>à remettre aux responsables de club</small></h3>
        <div class="cards">
            <form class="card" method="get" action="<?= eng_h($ENG_ROOT . 'recu.php') ?>" target="_blank">
                <b>Récapitulatif d'un club</b>
                <p>Ses archers, ce qu'ils doivent et ce qui est réglé.</p>
                <div class="row">
                    <select name="club"<?= $clubs ? '' : ' disabled' ?>>
                        <?php foreach ($clubs as $id => $l): ?><option value="<?= $id ?>"><?= eng_h($l) ?></option><?php endforeach; ?>
                        <?php if (!$clubs): ?><option>Aucun archer inscrit</option><?php endif; ?>
                    </select>
                    <button type="submit"<?= $clubs ? '' : ' disabled' ?>>Éditer le récapitulatif</button>
                </div>
            </form>
            <form class="card" method="get" action="<?= eng_h($ENG_ROOT . 'recu.php') ?>" target="_blank">
                <input type="hidden" name="club" value="all">
                <b>Récapitulatifs de tous les clubs</b>
                <p>Un récapitulatif par club, à la suite, dans un seul PDF.</p>
                <div class="row"><button type="submit"<?= $clubs ? '' : ' disabled' ?>>Éditer les récapitulatifs</button></div>
            </form>
        </div>
    </div>

    <div class="group">
        <h3>Après le concours <small>pour le trésorier</small></h3>
        <div class="cards">
            <form class="card" method="get" action="<?= eng_h($rep) ?>" target="_blank">
                <input type="hidden" name="bilan" value="1">
                <b>Bilan financier</b>
                <p>Résumé, totaux par moyen de paiement, impayés par départ.</p>
                <div class="row"><button type="submit">Éditer le bilan</button></div>
            </form>
            <form class="card" method="get" action="<?= eng_h($rep) ?>" target="_blank">
                <input type="hidden" name="cheques" value="1">
                <b>État des chèques</b>
                <p>Bordereau de remise en banque.</p>
                <div class="row"><button type="submit"<?= $hasCheques ? '' : ' disabled' ?>>Éditer l'état</button>
                    <?php if (!$hasCheques): ?><span class="sub">Aucun chèque enregistré.</span><?php endif; ?></div>
            </form>
            <form class="card" method="get" action="<?= eng_h($rep) ?>" target="_blank">
                <input type="hidden" name="pdf" value="1">
                <b>État complet</b>
                <p>Le rapport en entier : synthèse, ventilations par moyen, club, règle et départ, listes de contrôle.</p>
                <div class="row"><button type="submit">Éditer l'état complet</button></div>
            </form>
        </div>
    </div>

    <?= eng_footer() ?>
</div>
<?php include($CFG->DOCUMENT_PATH . 'Common/Templates/tail.php'); ?>
