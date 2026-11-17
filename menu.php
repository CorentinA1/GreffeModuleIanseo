<?php
// ── Menu ianseo — module GREFFE (dossier ENGAGEMENT) ─────────────────────────
// Inclus sur toutes les pages par Common/Menu.php : aucune requête ici (sauf le
// paramètre ISK-NG Mode, lu et mis en cache par IANSEO), et surtout pas eng_schema() (les tables se créent à l'ouverture d'une page).
// Entrées visibles seulement si une compétition est ouverte et que
// l'utilisateur a au moins la lecture sur les participants.
if (!empty($on) && !empty($_SESSION['TourId'])
    && subFeatureAcl($acl, AclParticipants, 'pEntries') >= AclReadOnly) {

    // Même clé partout ('Greffe') : le premier élément est le titre du sous-menu.
    if (!isset($ret['MODS']['Greffe'])) $ret['MODS']['Greffe'][] = 'Greffe';

    if (subFeatureAcl($acl, AclParticipants, 'pEntries') >= AclReadWrite) {
        $ret['MODS']['Greffe'][] = 'Tarifs|' . $CFG->ROOT_DIR . 'Modules/Custom/ENGAGEMENT/tarifs.php';
        $ret['MODS']['Greffe'][] = 'Paiements|' . $CFG->ROOT_DIR . 'Modules/Custom/ENGAGEMENT/paiements.php';
    }
    $ret['MODS']['Greffe'][] = 'Éditions|' . $CFG->ROOT_DIR . 'Modules/Custom/ENGAGEMENT/editions.php';
    $ret['MODS']['Greffe'][] = 'Rapport - Bilan|' . $CFG->ROOT_DIR . 'Modules/Custom/ENGAGEMENT/reporting.php';
    // Feuilles de marque : seulement avec ScoreKeeper NG, NG PRO ou NG Live activé et la lecture sur les
    // qualifications (même test que eng_scorecards_tab(), lib/boot.php). Un paramètre lu, mis en cache par IANSEO.
    if (in_array((string) getModuleParameter('ISK-NG', 'Mode', ''), ['ng-lite', 'ng-pro', 'ng-live'], true)
        && subFeatureAcl($acl, AclQualification) >= AclReadOnly) {
        $ret['MODS']['Greffe'][] = 'Feuilles de marque|' . $CFG->ROOT_DIR . 'Modules/Custom/ENGAGEMENT/feuilles.php';
    }
    if (subFeatureAcl($acl, AclParticipants, 'pEntries') >= AclReadWrite) {
        $ret['MODS']['Greffe'][] = 'Sauvegarde|' . $CFG->ROOT_DIR . 'Modules/Custom/ENGAGEMENT/sauvegarde.php';
    }

    // Administrateur seulement. Avec un module de comptes (AUTH), AclRoot ne
    // suffit pas : il faut aussi la vue administrateur serveur (AUTH_ROOT).
    if (subFeatureAcl($acl, AclRoot, '') >= AclReadWrite
        && (empty($_SESSION['AUTH_User']) || !empty($_SESSION['AUTH_ROOT']))) {
        $ret['MODS']['Greffe'][] = MENU_DIVIDER;
        // Pastille « mise à jour disponible » : lit seulement l'état gardé par la dernière
        // vérification (lib/update.php, eng_upd_notify_*), jamais GitHub. Délai écoulé :
        // une image invisible lance la vérification en arrière-plan (admin/update-check.php).
        $grMaj = '';
        $grReal = realpath(__DIR__);
        $grState = @json_decode((string) @file_get_contents(sys_get_temp_dir() . '/ianseo-maj-' . md5($grReal !== false ? $grReal : __DIR__) . '.json'), true);
        $grLocal = @json_decode((string) @file_get_contents(__DIR__ . '/version.json'), true);
        $grHave = is_array($grLocal) ? (string) ($grLocal['version'] ?? '') : '';
        $grLatest = is_array($grState) ? (string) ($grState['latest'] ?? '') : '';
        // 3 ou 4 nombres (1.7.0, 1.7.0.1), comme eng_upd_valid_version() (lib/update.php).
        if ($grHave !== '' && preg_match('/^\d+\.\d+\.\d+(?:\.\d+)?$/', $grLatest) && version_compare($grLatest, $grHave, '>')) {
            $grTip = htmlspecialchars('Mise à jour disponible : ' . $grHave . ' → ' . $grLatest, ENT_QUOTES, 'UTF-8');
            $grMaj = '<span class="maj-dot" title="' . $grTip . '" style="display:inline-block;min-width:15px;height:15px;line-height:15px;padding:0 3px;border-radius:8px;'
                . 'background:#f08c00;background:color-mix(in srgb,var(--text-warning,#ffc107) 60%,var(--text-danger,#dc3545));color:#fff;font-size:10px;font-weight:bold;text-align:center;margin-left:6px;vertical-align:middle">⬆</span>';
            $grDot = '<span class="maj-dot" title="' . $grTip . '" style="display:inline-block;width:8px;height:8px;border-radius:50%;'
                . 'background:#f08c00;background:color-mix(in srgb,var(--text-warning,#ffc107) 60%,var(--text-danger,#dc3545));margin-left:5px;vertical-align:middle"></span>';
            $ret['MODS']['Greffe'][0] .= $grDot;
            // Titre « Modules » de la barre : un seul point, quel que soit le nombre de modules concernés.
            if (isset($ret['MODS'][0]) && strpos($ret['MODS'][0], 'maj-dot') === false) {
                $ret['MODS'][0] .= '<span class="maj-dot" title="Mise à jour disponible pour un module" style="display:inline-block;width:8px;height:8px;border-radius:50%;'
                    . 'background:#f08c00;background:color-mix(in srgb,var(--text-warning,#ffc107) 60%,var(--text-danger,#dc3545));margin-left:4px;vertical-align:super"></span>';
            }
        }
        if (!is_array($grState) || time() >= (int) ($grState['next'] ?? 0)) {
            $grMaj .= '<img src="' . $CFG->ROOT_DIR . 'Modules/Custom/' . basename(__DIR__) . '/admin/update-check.php" alt="" width="1" height="1" style="position:absolute;opacity:0">';
        }
        $ret['MODS']['Greffe'][] = 'Mise à jour du module' . $grMaj . '|' . $CFG->ROOT_DIR . 'Modules/Custom/ENGAGEMENT/admin/update.php';
    }
}
