<?php
/**
 * lib/update.php — mise à jour du module depuis GitHub.
 *
 * Même principe que les modules de la communauté ianseo :
 *   module.json   d'où vient le module (dépôt, branche, dossier, jeton
 *                 facultatif). Configuration LOCALE : jamais dans files[],
 *                 jamais écrasée par une mise à jour.
 *   version.json  version, notes et liste files[] des fichiers du module. Il
 *                 vient du dépôt : tout ce qu'on y lit est une donnée non fiable.
 *
 * Fonctions sans dépendance à ianseo (testables en ligne de commande) : le
 * dossier du module est toujours passé en paramètre.
 *
 * Règles de sûreté :
 *   - seuls des chemins relatifs simples, à l'intérieur du module, sont acceptés ;
 *   - tous les fichiers sont téléchargés AVANT d'écrire quoi que ce soit : un
 *     seul échec, et rien n'est modifié ;
 *   - chaque fichier est écrit dans un fichier temporaire puis renommé ;
 *     version.json en dernier, pour qu'une mise à jour interrompue reste visible ;
 *   - une installation de développement (dossier .git) est refusée.
 */

/** Configuration par défaut : le dépôt public du module. */
function eng_upd_defaults()
{
    return [
        'github_url'    => 'https://github.com/CorentinA1/GreffeModuleIanseo',
        'github_branch' => 'main',
        'github_path'   => '',
        'github_token'  => '',
    ];
}

/**
 * Lit module.json ; les clés absentes prennent la valeur par défaut.
 *
 * @param string $dir Dossier du module.
 * @return array github_url, github_branch, github_path, github_token.
 */
function eng_upd_config($dir)
{
    $cfg = eng_upd_defaults();
    $f = $dir . '/module.json';
    if (is_file($f)) {
        $d = json_decode((string) file_get_contents($f), true);
        if (is_array($d)) {
            foreach ($cfg as $k => $v) {
                if (isset($d[$k]) && is_string($d[$k])) $cfg[$k] = trim($d[$k]);
            }
        }
    }
    return $cfg;
}

/**
 * Adresse « raw » d'un fichier du module sur GitHub.
 *
 * @return string Vide si l'adresse du dépôt est inutilisable.
 */
function eng_upd_raw_url(array $cfg, $rel)
{
    if (!preg_match('#github\.com/([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+?)(?:\.git)?/?$#i', $cfg['github_url'], $m)) return '';
    $branch = preg_match('#^[A-Za-z0-9_./-]+$#', $cfg['github_branch']) ? $cfg['github_branch'] : 'main';
    $path = trim($cfg['github_path'], '/');
    if ($path !== '' && !eng_upd_safe_path($path)) return '';
    return 'https://raw.githubusercontent.com/' . $m[1] . '/' . $m[2] . '/' . $branch . '/'
        . ($path !== '' ? $path . '/' : '') . $rel;
}

/**
 * Chemin relatif acceptable pour un fichier du module ?
 *
 * Lettres, chiffres, « _ - . / » seulement ; ni chemin absolu, ni « .. », ni
 * dossier caché, ni module.json (configuration locale).
 */
function eng_upd_safe_path($rel)
{
    $rel = (string) $rel;
    if ($rel === '' || strlen($rel) > 120 || !preg_match('#^[A-Za-z0-9_][A-Za-z0-9_./-]*$#', $rel)) return false;
    foreach (explode('/', $rel) as $part) {
        if ($part === '' || $part === '.' || $part === '..' || $part[0] === '.') return false;
    }
    return strcasecmp($rel, 'module.json') !== 0;
}

/**
 * Télécharge un fichier (https). curl si disponible, sinon file_get_contents.
 *
 * @return string|false Contenu, ou false en cas d'échec (y compris HTTP ≠ 200).
 */
function eng_upd_http_get($url, $token = '')
{
    $headers = ['User-Agent: ianseo-greffe-updater'];
    if ($token !== '') $headers[] = 'Authorization: token ' . $token;

    if (function_exists('curl_init')) {
        $c = curl_init($url);
        curl_setopt_array($c, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        $body = curl_exec($c);
        $code = (int) curl_getinfo($c, CURLINFO_HTTP_CODE);
        curl_close($c);
        return ($body !== false && $code === 200) ? $body : false;
    }

    $ctx = stream_context_create(['http' => [
        'header'        => implode("\r\n", $headers) . "\r\n",
        'timeout'       => 20,
        'ignore_errors' => true,
    ]]);
    $body = @file_get_contents($url, false, $ctx);
    $status = isset($http_response_header[0]) ? $http_response_header[0] : '';
    return ($body !== false && preg_match('#\s200\s#', $status . ' ')) ? $body : false;
}

/** version.json local, ou null. */
function eng_upd_local($dir)
{
    $f = $dir . '/version.json';
    $d = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
    return is_array($d) ? $d : null;
}

/**
 * version.json du dépôt, validé.
 *
 * @return array Manifeste, ou ['_error' => message].
 */
function eng_upd_remote(array $cfg)
{
    $url = eng_upd_raw_url($cfg, 'version.json');
    if ($url === '') return ['_error' => 'Adresse du dépôt GitHub invalide dans module.json.'];
    $raw = eng_upd_http_get($url . '?t=' . time(), $cfg['github_token']);
    if ($raw === false || $raw === '') {
        return ['_error' => 'GitHub injoignable, ou version.json introuvable sur le dépôt.'];
    }
    $d = json_decode($raw, true);
    if (!is_array($d) || !isset($d['version']) || !preg_match('/^\d+\.\d+\.\d+$/', (string) $d['version'])) {
        return ['_error' => 'version.json du dépôt illisible.'];
    }
    $files = is_array($d['files'] ?? null) ? $d['files'] : [];
    $d['files'] = array_values(array_filter($files, 'eng_upd_safe_path'));
    if (!$d['files'] || count($d['files']) !== count($files)) {
        return ['_error' => 'Liste de fichiers du dépôt vide ou contenant des chemins refusés.'];
    }
    if (!in_array('version.json', $d['files'], true)) $d['files'][] = 'version.json';
    return $d;
}

/**
 * État de la mise à jour.
 *
 * @return array local (version|null), remote (version|null), notes, date,
 *               update (bool), dev (installation Git), error (message|null).
 */
function eng_upd_check($dir)
{
    $local = eng_upd_local($dir);
    $remote = eng_upd_remote(eng_upd_config($dir));
    $lv = $local['version'] ?? null;
    if (isset($remote['_error'])) {
        return ['local' => $lv, 'remote' => null, 'notes' => '', 'date' => '', 'update' => false,
                'dev' => is_dir($dir . '/.git'), 'error' => $remote['_error']];
    }
    return [
        'local'  => $lv,
        'remote' => $remote['version'],
        'notes'  => (string) ($remote['notes'] ?? ''),
        'date'   => (string) ($remote['date'] ?? ''),
        'update' => $lv === null || version_compare($remote['version'], $lv, '>'),
        'dev'    => is_dir($dir . '/.git'),
        'error'  => null,
    ];
}

/**
 * Installe la version du dépôt.
 *
 * @param string $dir Dossier du module.
 * @param bool $force Réinstaller même si la version est identique.
 * @return array ['ok' => bool, 'message' => texte, 'files' => nombre écrit].
 */
function eng_upd_apply($dir, $force = false)
{
    if (is_dir($dir . '/.git')) {
        return ['ok' => false, 'files' => 0, 'message' => 'Installation de développement (dossier .git) : mettez-la à jour avec Git, pas depuis cette page.'];
    }
    $cfg = eng_upd_config($dir);
    $remote = eng_upd_remote($cfg);
    if (isset($remote['_error'])) return ['ok' => false, 'files' => 0, 'message' => $remote['_error']];

    $lv = eng_upd_local($dir)['version'] ?? null;
    if (!$force && $lv !== null && !version_compare($remote['version'], $lv, '>')) {
        return ['ok' => true, 'files' => 0, 'message' => 'Le module est déjà à jour (' . $lv . ').'];
    }

    // 1. Tout télécharger d'abord.
    $content = [];
    foreach ($remote['files'] as $rel) {
        $body = eng_upd_http_get(eng_upd_raw_url($cfg, $rel), $cfg['github_token']);
        if ($body === false || $body === '') {
            return ['ok' => false, 'files' => 0, 'message' => 'Téléchargement impossible : ' . $rel . '. Aucun fichier n\'a été modifié.'];
        }
        $content[$rel] = $body;
    }

    // 2. Écrire, version.json en dernier.
    $order = array_keys($content);
    usort($order, function ($a, $b) { return ($a === 'version.json') <=> ($b === 'version.json'); });
    $n = 0;
    foreach ($order as $rel) {
        $dest = $dir . '/' . $rel;
        if (!is_dir(dirname($dest)) && !@mkdir(dirname($dest), 0755, true)) {
            return ['ok' => false, 'files' => $n, 'message' => 'Dossier impossible à créer : ' . dirname($rel) . ' (droits d\'écriture ?).'];
        }
        $tmp = $dest . '.tmp-' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $content[$rel]) === false || !@rename($tmp, $dest)) {
            @unlink($tmp);
            return ['ok' => false, 'files' => $n, 'message' => 'Écriture impossible : ' . $rel . ' (droits d\'écriture sur le dossier du module ?). '
                . $n . ' fichier(s) déjà remplacé(s) : relancez la mise à jour.'];
        }
        $n++;
    }
    if (function_exists('opcache_reset')) @opcache_reset();
    return ['ok' => true, 'files' => $n, 'message' => 'Module mis à jour en version ' . $remote['version'] . ' (' . $n . ' fichiers).'];
}
