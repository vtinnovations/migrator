<?php

/*
 * Contao Migrator
 *
 * Package: vtinnovations/migrator
 * Copyright: V&T Innovations Team
 * Licence: LGPL-3.0-or-later
 * Website: https://www.v-t.one
 */

/*
 * V&T Innovations Contao Migrator — standalone recovery panel.
 *
 * This file is auto-copied into the project's public/ directory by the bundle on every kernel
 * boot (idempotent md5 compare), mirroring the vtinnovations/guardian recovery panel. It runs
 * INDEPENDENTLY of the Contao backend: it renders its own minimal HTML and never touches the
 * BE chrome (@Contao/be_main / header_menu), so a migration can still be driven and the schema
 * repaired even when the backend itself 500s — e.g. a missing table after a cross-version
 * restore (5.3 → 5.7 adds tl_job, which the BE header queries on every page).
 *
 * Status is read straight from the job JSON on disk (no kernel needed). Driving a job (tick) and
 * running contao:migrate boot the kernel IN-PROCESS (no shell subprocess — honours locked-down
 * Plesk hosts). Booting the kernel is fine; only rendering the BE response hits the missing table.
 *
 * Auth: the operator token at var/migrator/auth.token (the same token that gates the BE module),
 * compared in constant time. Pass it as ?key=... (note: query strings land in access logs — this
 * is a break-glass recovery tool; rotate the token afterwards if that matters).
 */

declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

$root = \dirname(__DIR__);
$scratch = $root.'/var/migrator';
$jobsDir = $scratch.'/jobs';
$tokenFile = $scratch.'/auth.token';

// UI strings come from the bundle's Contao language files, so this standalone page carries no
// text of its own and stays in sync with the back end. English is loaded first as the fallback
// layer, then the requested language overwrites what it translates. No kernel is involved.
$lang = (string) ($_GET['lang'] ?? '');

if (!\in_array($lang, ['en', 'de'], true)) {
    $lang = str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')), 'de') ? 'de' : 'en';
}

$GLOBALS['TL_LANG']['tcmig'] = [];

foreach (array_unique(['en', $lang]) as $load) {
    foreach ([$root.'/vendor/vtinnovations/migrator/contao/languages/'.$load.'/tcmig.php'] as $file) {
        if (is_file($file)) {
            include $file;
        }
    }
}

$T = (array) ($GLOBALS['TL_LANG']['tcmig'] ?? []);

/** Translate a key, optionally filling its %s placeholders. */
$t = static function (string $key, string ...$args) use ($T): string {
    $text = (string) ($T[$key] ?? $key);

    return [] === $args ? $text : vsprintf($text, $args);
};

$expected = is_file($tokenFile) ? trim((string) file_get_contents($tokenFile)) : '';
$given = (string) ($_GET['key'] ?? $_POST['key'] ?? '');
$authed = '' !== $expected && '' !== $given && hash_equals($expected, $given);
$action = (string) ($_GET['action'] ?? '');

/** Find the newest job JSON; $activeOnly skips completed/failed (paused still counts — resumable). */
$findJob = static function (string $jobsDir, bool $activeOnly): ?array {
    $best = null;
    $bestTs = -1.0;

    foreach (glob($jobsDir.'/*.json') ?: [] as $f) {
        $d = json_decode((string) file_get_contents($f), true);

        if (!\is_array($d) || !isset($d['id'])) {
            continue;
        }

        if ($activeOnly && \in_array($d['state'] ?? '', ['completed', 'failed'], true)) {
            continue;
        }

        $ts = (float) ($d['createdAt'] ?? 0);

        if ($ts > $bestTs) {
            $bestTs = $ts;
            $best = $d;
        }
    }

    return $best;
};

// Render one structured composer-audit warning ({code,args}) using the shared cw_* strings in the
// active language. Args are HTML-escaped.
$fmtComposerWarn = static function ($w) use ($T): string {
    if (\is_string($w)) {
        return htmlspecialchars($w, \ENT_QUOTES, 'UTF-8');
    }

    $w = (array) $w;
    $code = (string) ($w['code'] ?? '');
    $a = (array) ($w['args'] ?? []);
    $e = static fn (string $k): string => htmlspecialchars((string) ($a[$k] ?? ''), \ENT_QUOTES, 'UTF-8');
    // Same wording as the back end module: one shared cw_* table, not a second German copy.
    $tpl = (string) ($T['cw_'.$code] ?? $code);

    switch ($code) {
        case 'repo_path':
        case 'repo_custom':
            return sprintf($tpl, $e('label'), $e('url'));
        case 'path_no_version':
            return sprintf($tpl, $e('name'), $e('url'));
        case 'repo_vcs':
            return sprintf($tpl, $e('label'), $e('type'), $e('url'));
        case 'dev_constraint':
            return sprintf($tpl, $e('pkg'), $e('constraint'));
        case 'min_stability':
            return sprintf($tpl, $e('value'));
        default:
            return $tpl;
    }
};

// Job-log lines are emitted in English by the pipeline and translated at display time. Load the
// bundle's Messages catalog via the composer autoloader (no kernel boot); if it is unavailable the
// raw English text is shown, which is still readable.
$loadMsg = static function () use ($root): bool {
    static $ready = null;
    if (null === $ready) {
        if (!class_exists('\\Vtinnovations\\Migrator\\Support\\Messages') && is_file($root.'/vendor/autoload.php')) {
            @require_once $root.'/vendor/autoload.php';
        }
        $ready = class_exists('\\Vtinnovations\\Migrator\\Support\\Messages');
    }

    return $ready;
};
$tr = static fn (string $s): string => $loadMsg() ? \Vtinnovations\Migrator\Support\Messages::translate($lang, $s) : $s;
$trState = static fn (string $s): string => $loadMsg() ? \Vtinnovations\Migrator\Support\Messages::stateLabel($lang, $s) : $s;
$trStep = static fn (string $s): string => $loadMsg() ? \Vtinnovations\Migrator\Support\Messages::stepLabel($lang, $s) : $s;

$jobStatus = static function (?array $d) use ($fmtComposerWarn, $tr, $trState, $trStep): array {
    if (null === $d) {
        return ['found' => false];
    }

    $steps = $d['steps'] ?? [];
    $cursor = (int) ($d['cursor'] ?? 0);
    $total = \count($steps);
    $log = array_map(
        static fn ($l): array => ['level' => (string) ($l['level'] ?? 'info'), 'msg' => $tr((string) ($l['msg'] ?? ''))],
        \array_slice((array) ($d['log'] ?? []), -14)
    );

    return [
        'found' => true,
        'id' => (string) $d['id'],
        'type' => (string) ($d['type'] ?? ''),
        'state' => (string) ($d['state'] ?? ''),
        'stateLabel' => $trState((string) ($d['state'] ?? '')),
        'step' => $steps[$cursor] ?? '',
        'stepLabel' => $trStep((string) ($steps[$cursor] ?? '')),
        'progress' => $total > 0 ? (int) round(min(1.0, $cursor / $total) * 100) : 0,
        'error' => isset($d['error']) ? $tr((string) $d['error']) : null,
        'meta' => [
            'mode' => $d['meta']['mode'] ?? null,
            'needsPassphrase' => (bool) ($d['meta']['needsPassphrase'] ?? false),
            'composerWarnings' => array_map($fmtComposerWarn, array_values((array) ($d['meta']['composerWarnings'] ?? []))),
        ],
        'log' => $log,
    ];
};

/**
 * Boot the application kernel in-process. Returns the kernel (container via getContainer()).
 * Memoized: the licence gate and the action handlers share one kernel, and Symfony's boot() is
 * idempotent, so repeated calls in the same request cost nothing.
 */
$bootKernel = static function (string $root) {
    static $memo = null;

    if (null !== $memo) {
        return $memo;
    }

    require_once $root.'/vendor/autoload.php';

    if (class_exists(\Symfony\Component\Dotenv\Dotenv::class) && is_file($root.'/.env')) {
        (new \Symfony\Component\Dotenv\Dotenv())->bootEnv($root.'/.env');
    }

    if (class_exists(\Contao\ManagerBundle\HttpKernel\ContaoKernel::class)) {
        $request = \Symfony\Component\HttpFoundation\Request::createFromGlobals();
        $kernel = \Contao\ManagerBundle\HttpKernel\ContaoKernel::fromRequest($root, $request);

        // In prod Contao wraps the kernel in the ContaoCache HTTP cache, which has no
        // boot()/getContainer(). Unwrap to the real kernel before the caller boots it.
        if ($kernel instanceof \Symfony\Component\HttpKernel\HttpCache\HttpCache) {
            $kernel = $kernel->getKernel();
        }

        return $memo = $kernel;
    }

    if (class_exists('App\Kernel')) {
        $env = (string) ($_SERVER['APP_ENV'] ?? 'prod');
        $debug = filter_var($_SERVER['APP_DEBUG'] ?? false, \FILTER_VALIDATE_BOOL);

        return $memo = new \App\Kernel($env, $debug);
    }

    throw new \RuntimeException('No kernel class found (neither ContaoKernel nor App\\Kernel).');
};

$json = static function (array $data): void {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, \JSON_UNESCAPED_SLASHES);
    exit;
};

// --- Unauthenticated: login form / token-missing notice ------------------------------------
if (!$authed) {
    if ('json' === ($_GET['fmt'] ?? '')) {
        http_response_code(403);
        $json(['error' => 'unauthorized']);
    }

    http_response_code('' === $given ? 200 : 403);
    $esc = static fn (string $v): string => htmlspecialchars($v, \ENT_QUOTES, 'UTF-8');
    $msg = '' === $expected
        ? '<p class="err">'.$esc($t('rec_no_token')).'</p>'
        : ('' === $given ? '' : '<p class="err">'.$esc($t('rec_wrong_token')).'</p>');
    ?><!DOCTYPE html>
<html lang="<?= $esc($lang) ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $esc($t('rec_login_title')) ?></title><style>
body{font:14px/1.6 system-ui,sans-serif;background:#1d1d1d;color:#e6e6e6;display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0}
form{background:#262626;padding:28px;border-radius:10px;width:320px}
h1{font-size:17px;margin:0 0 16px}input{width:100%;box-sizing:border-box;padding:9px;border-radius:6px;border:1px solid #444;background:#1d1d1d;color:#e6e6e6;margin-bottom:12px}
button{width:100%;padding:10px;border:0;border-radius:6px;background:#3a9b7c;color:#fff;font-weight:600;cursor:pointer}
.err{color:#e26d6d}</style></head><body>
<form method="post"><h1><?= $esc($t('rec_login_h')) ?></h1><?= $msg ?>
<label><?= $esc($t('rec_token_label')) ?></label><input type="password" name="key" autocomplete="off" autofocus>
<button type="submit"><?= $esc($t('rec_open')) ?></button></form></body></html><?php
    exit;
}

// --- Authenticated JSON actions ------------------------------------------------------------
if ('status' === $action) {
    $json($jobStatus($findJob($jobsDir, false)));
}

// --- Licence gate ---------------------------------------------------------------------------
// Everything this panel can DRIVE is licence-gated exactly like the backend module. The decision
// is delegated to the bundle's authoritative evaluator through the booted kernel — no licence
// crypto, key material or state parsing is duplicated in this standalone file — and it fails
// CLOSED: if the kernel cannot boot, the panel stays read-only rather than unlocking itself.
// Resolved after the status action above, so diagnosing a broken install never needs a kernel.
$licensed = (static function () use ($bootKernel, $root): bool {
    try {
        $kernel = $bootKernel($root);
        $kernel->boot();

        return $kernel->getContainer()
            ->get(\Vtinnovations\Migrator\Config\EntitlementEvaluator::class)
            ->evaluate()
            ->isLicensed()
        ;
    } catch (\Throwable) {
        return false;
    }
})();

// Mutating actions require a valid license (status stays readable so the operator can diagnose).
if (\in_array($action, ['tick', 'migrate', 'mint', 'passphrase', 'confirm_composer', 'confirm_composer_fix', 'cancel'], true) && !$licensed) {
    http_response_code(403);
    $json(['ok' => false, 'error' => 'license', 'reason' => 'license']);
}

if ('tick' === $action) {
    @set_time_limit(0);

    try {
        $job = $findJob($jobsDir, true);

        if (null === $job) {
            $json(['ok' => false, 'error' => $t('rec_no_active_drive')]);
        }

        $kernel = $bootKernel($root);
        $kernel->boot();
        $container = $kernel->getContainer();
        $runner = $container->get(\Vtinnovations\Migrator\Job\JobRunner::class);
        $runner->tick((string) $job['id']);

        // Re-read fresh state from disk (the runner persisted it).
        $json(['ok' => true] + $jobStatus($findJob($jobsDir, false)));
    } catch (\Throwable $e) {
        $json(['ok' => false, 'error' => $e->getMessage()]);
    }
}

if ('migrate' === $action) {
    @set_time_limit(0);

    try {
        $kernel = $bootKernel($root);
        $kernel->boot();

        $app = new \Symfony\Bundle\FrameworkBundle\Console\Application($kernel);
        $app->setAutoExit(false);

        $out = new \Symfony\Component\Console\Output\BufferedOutput();
        $code = $app->run(new \Symfony\Component\Console\Input\ArrayInput([
            'command' => 'contao:migrate',
            '--no-interaction' => true,
            '--no-backup' => true,
        ]), $out);

        // Re-publish bundle web assets too — a fresh destination has no public/bundles/, so every
        // bundle CSS/JS 404s and both BE and FE render unstyled. assets:install recreates them.
        $webDir = is_dir($root.'/public') ? 'public' : (is_dir($root.'/web') ? 'web' : 'public');
        $assetsCode = $app->run(new \Symfony\Component\Console\Input\ArrayInput([
            'command' => 'assets:install',
            'target' => $webDir,
            '--symlink' => true,
            '--relative' => true,
            '--no-interaction' => true,
        ]), $out);

        $json(['ok' => 0 === $code && 0 === $assetsCode, 'code' => $code, 'assetsCode' => $assetsCode, 'output' => trim($out->fetch())]);
    } catch (\Throwable $e) {
        $json(['ok' => false, 'error' => $e->getMessage()]);
    }
}

if ('mint' === $action) {
    @set_time_limit(0);

    try {
        $kernel = $bootKernel($root);
        $kernel->boot();
        $container = $kernel->getContainer();
        $pairing = $container->get(\Vtinnovations\Migrator\Transfer\PairingStore::class)->mint();

        $json(['ok' => true, 'token' => (string) $pairing['token'], 'expiresAt' => (int) $pairing['expiresAt']]);
    } catch (\Throwable $e) {
        $json(['ok' => false, 'error' => $e->getMessage()]);
    }
}

// Patch a job JSON on disk (state + meta) atomically — no kernel needed, so these work when the
// backend itself is down. Only touched fields change; every other job field is preserved.
$patchJob = static function (string $jobsDir, string $id, array $metaPatch, ?string $state) {
    $file = $jobsDir.'/'.$id.'.json';
    $d = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

    if (!\is_array($d)) {
        return false;
    }

    foreach ($metaPatch as $k => $v) {
        $d['meta'][$k] = $v;
    }

    if (null !== $state) {
        $d['state'] = $state;
    }

    $tmp = $file.'.tmp';
    $json = json_encode($d, \JSON_UNESCAPED_SLASHES | \JSON_PRETTY_PRINT);

    return false !== $json && false !== file_put_contents($tmp, $json, LOCK_EX) && @rename($tmp, $file);
};

// Supply the export passphrase to a paused (needsPassphrase) job from the panel — writes the
// transient 0600 secret sidecar and re-arms the job so verify_package re-runs. Mirrors the BE
// module's confirm_passphrase, but reachable when the backend is down (no jumping back to it).
if ('passphrase' === $action) {
    $job = $findJob($jobsDir, true);

    if (null === $job) {
        $json(['ok' => false, 'error' => 'Kein aktiver Auftrag zum Entsperren.']);
    }

    $pass = (string) ($_POST['passphrase'] ?? '');

    if ('' === $pass) {
        $json(['ok' => false, 'error' => 'Leere Passphrase.']);
    }

    $id = (string) $job['id'];
    $secretFile = $jobsDir.'/'.$id.'.secret';
    file_put_contents($secretFile, $pass, LOCK_EX);
    @chmod($secretFile, 0600);

    if (!$patchJob($jobsDir, $id, ['needsPassphrase' => false], 'pending')) {
        $json(['ok' => false, 'error' => $t('rec_restart_failed')]);
    }

    $json(['ok' => true] + $jobStatus($findJob($jobsDir, false)));
}

// "Proceed anyway" past the pre-send composer.json audit warnings from the panel.
if ('confirm_composer' === $action) {
    $job = $findJob($jobsDir, true);

    if (null === $job) {
        $json(['ok' => false, 'error' => $t('rec_no_active_job')]);
    }

    if (!$patchJob($jobsDir, (string) $job['id'], ['composerAuditConfirmed' => true], 'pending')) {
        $json(['ok' => false, 'error' => $t('rec_restart_failed')]);
    }

    $json(['ok' => true] + $jobStatus($findJob($jobsDir, false)));
}

// Abort the active job. Touch the lock-free cancel marker (a running tick picks it up mid-slice)
// and, if nothing is ticking, flip the state to cancelled directly on disk.
if ('cancel' === $action) {
    $job = $findJob($jobsDir, true);

    if (null === $job) {
        $json(['ok' => false, 'error' => $t('rec_no_active_job')]);
    }

    @touch($jobsDir.'/'.(string) $job['id'].'.cancel');
    $patchJob($jobsDir, (string) $job['id'], [], 'cancelled');

    $json(['ok' => true] + $jobStatus($findJob($jobsDir, false)));
}

// "Fix it for the package": ship vendor/ + portable composer.json, then continue.
if ('confirm_composer_fix' === $action) {
    $job = $findJob($jobsDir, true);

    if (null === $job) {
        $json(['ok' => false, 'error' => $t('rec_no_active_job')]);
    }

    if (!$patchJob($jobsDir, (string) $job['id'], ['composerFix' => true, 'composerAuditConfirmed' => true], 'pending')) {
        $json(['ok' => false, 'error' => $t('rec_restart_failed')]);
    }

    $json(['ok' => true] + $jobStatus($findJob($jobsDir, false)));
}

// --- Authenticated HTML panel --------------------------------------------------------------
$keyQ = rawurlencode($given);
$esc = static fn (string $v): string => htmlspecialchars($v, \ENT_QUOTES, 'UTF-8');
?><!DOCTYPE html>
<html lang="<?= $esc($lang) ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $esc($t('rec_panel_title')) ?></title><style>
body{font:14px/1.6 system-ui,sans-serif;background:#1d1d1d;color:#e6e6e6;margin:0;padding:24px;max-width:820px}
h1{font-size:18px;margin:0 0 16px}code{background:rgba(127,127,127,.2);padding:2px 6px;border-radius:4px}
#st{margin-bottom:10px}
#barwrap{height:18px;background:rgba(127,127,127,.25);border-radius:9px;overflow:hidden;margin-bottom:16px}
#bar{height:100%;width:0;background:#3a9b7c;transition:width .4s}
.btns{margin-bottom:16px}button{padding:9px 16px;border:0;border-radius:6px;color:#fff;font-weight:600;cursor:pointer;margin-right:10px}
.drive{background:#3a9b7c}.mig{background:#c07a2b}.mint{background:#5566c4}.cxl{background:#b04a4a}button[disabled]{opacity:.5;cursor:default}
#mintbox{display:none;background:rgba(85,102,196,.12);border:1px solid rgba(85,102,196,.5);border-radius:6px;padding:12px;margin-bottom:16px}
#mintbox code{display:block;word-break:break-all;margin:6px 0;font-size:13px}
pre{background:rgba(0,0,0,.35);border:1px solid rgba(127,127,127,.3);border-radius:6px;padding:10px;max-height:46vh;overflow:auto;white-space:pre-wrap;word-break:break-word}
.hint{color:#9a9a9a;font-size:12px}
.lic{background:rgba(226,109,109,.12);border:1px solid rgba(226,109,109,.5);border-radius:6px;padding:12px;margin-bottom:16px;color:#e26d6d}
#passbox{background:rgba(85,102,196,.12);border:1px solid rgba(85,102,196,.5);border-radius:6px;padding:12px;margin-bottom:16px}
#passin{padding:8px;border-radius:6px;border:1px solid #444;background:#1d1d1d;color:#e6e6e6;margin-right:8px;width:240px}
#compwarn{background:rgba(226,160,60,.12);border:1px solid rgba(226,160,60,.55);border-radius:6px;padding:12px;margin-bottom:16px;color:#e0b45c}
#compwarn ul{margin:8px 0;padding-left:20px}#compwarn button{background:#c07a2b;margin-top:6px}</style></head><body>
<h1><?= $esc($t('rec_panel_h')) ?></h1>
<?php if (!$licensed) { ?><div class="lic"><?= $esc($t('rec_locked')) ?></div><?php } ?>
<div id="st"><?= $esc($t('rec_loading')) ?></div>
<div id="barwrap"><div id="bar"></div></div>
<div class="btns">
  <button id="b-drive" class="drive"<?= $licensed ? '' : ' disabled' ?>><?= $esc($t('rec_drive')) ?></button>
  <button id="b-mig" class="mig"<?= $licensed ? '' : ' disabled' ?>><?= $esc($t('rec_migrate')) ?></button>
  <button id="b-mint" class="mint"<?= $licensed ? '' : ' disabled' ?>><?= $esc($t('rec_mint')) ?></button>
  <button id="b-cancel" class="cxl"<?= $licensed ? '' : ' disabled' ?> style="display:none"><?= $esc($t('rec_cancel')) ?></button>
</div>
<div id="mintbox"></div>
<div id="passbox" style="display:none"><strong><?= $esc($t('rec_pass_signed')) ?></strong> <?= $esc($t('rec_pass_prompt')) ?>
<div style="margin-top:8px"><input type="password" id="passin" placeholder="<?= $esc($t('rec_pass_placeholder')) ?>" autocomplete="new-password"><button id="b-pass" class="drive"><?= $esc($t('rec_pass_submit')) ?></button></div></div>
<div id="compwarn" style="display:none"></div>
<p class="hint"><?= $esc($t('rec_hint')) ?></p>
<pre id="log"></pre>
<script>
// Same string table as the server side; the panel holds no literals of its own.
var T=<?= json_encode($T, JSON_UNESCAPED_UNICODE) ?>;
function t(k){ return (T && T[k]) || k; }
function tf(k){ var a=Array.prototype.slice.call(arguments,1), i=0; return t(k).replace(/%s/g,function(){ return a[i++]; }); }
var KEY=<?= json_encode($keyQ) ?>, base='?key='+KEY;
var bar=document.getElementById('bar'), st=document.getElementById('st'), logEl=document.getElementById('log');
var bDrive=document.getElementById('b-drive'), bMig=document.getElementById('b-mig'), bMint=document.getElementById('b-mint'), bCancel=document.getElementById('b-cancel');
var mintBox=document.getElementById('mintbox');
var passBox=document.getElementById('passbox'), passIn=document.getElementById('passin'), bPass=document.getElementById('b-pass');
var compWarn=document.getElementById('compwarn');
var driving=false;
function setLog(lines){ logEl.textContent=lines.map(function(l){return '['+l.level+'] '+l.msg;}).join('\n'); logEl.scrollTop=logEl.scrollHeight; }
function isTerminal(s){ return s==='completed'||s==='failed'||s==='cancelled'; }
function esc(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;'); }
function render(d){
  if(!d.found){ st.textContent=t('rec_no_job'); return; }
  bar.style.width=(d.progress||0)+'%';
  var np=!!(d.meta&&d.meta.needsPassphrase);
  st.textContent='['+(d.stateLabel||d.state)+'] '+(d.stepLabel||d.step||'')+' — '+(d.progress||0)+'%'+(np?t('rec_need_pass'):'');
  if(bCancel){ bCancel.style.display = isTerminal(d.state) ? 'none' : 'inline-block'; }
  passBox.style.display = np ? 'block' : 'none';
  var cw=(d.meta&&d.meta.composerWarnings)||[];
  if(cw.length){
    compWarn.style.display='block';
    compWarn.innerHTML='<strong>'+esc(t('rec_cw_head'))+'</strong><ul>'+cw.map(function(w){return '<li>'+w+'</li>';}).join('')+'</ul><button id="b-compfix">'+esc(t('rec_cw_fix'))+'</button> <button id="b-comp">'+esc(t('rec_cw_continue'))+'</button>';
    var bf=document.getElementById('b-compfix'); if(bf) bf.onclick=confirmComposerFix;
    var bc=document.getElementById('b-comp'); if(bc) bc.onclick=confirmComposer;
  } else { compWarn.style.display='none'; }
  if(d.log) setLog(d.log);
}
function status(){
  fetch(base+'&action=status').then(function(r){return r.json();}).then(function(d){
    render(d);
    if(d.found && !isTerminal(d.state)) setTimeout(status,1500);
  }).catch(function(){ setTimeout(status,3000); });
}
function drive(){
  if(driving) return; driving=true; bDrive.disabled=true; bDrive.textContent=t('rec_driving');
  (function loop(){
    fetch(base+'&action=tick').then(function(r){return r.json();}).then(function(d){
      if(d.error){ st.textContent=tf('rec_tick_error',d.error); driving=false; bDrive.disabled=false; bDrive.textContent=t('rec_drive'); return; }
      render(d);
      if(d.found && !isTerminal(d.state)){ loop(); }
      else { driving=false; bDrive.disabled=false; bDrive.textContent=t('rec_drive'); }
    }).catch(function(){ setTimeout(loop,3000); });
  })();
}
function migrate(){
  bMig.disabled=true; bMig.textContent=t('rec_migrating'); st.textContent=t('rec_migrate_running');
  var httpStatus=0;
  fetch(base+'&action=migrate').then(function(r){ httpStatus=r.status; return r.text(); }).then(function(text){
    bMig.disabled=false; bMig.textContent=t('rec_migrate');
    var d=null; try{ d=JSON.parse(text); }catch(e){}
    if(!d){
      // Non-JSON body = PHP fatal / 500 / timeout. Surface the raw response so the real error is visible.
      st.textContent=tf('rec_migrate_nojson',httpStatus);
      logEl.textContent=text||t('rec_empty_response');
      return;
    }
    if(d.error){ st.textContent=tf('rec_migrate_error',d.error); return; }
    st.textContent=d.ok?tf('rec_migrate_done',d.code):tf('rec_migrate_failed',d.code,(d.assetsCode!=null?d.assetsCode:'n/a'));
    if(d.output) logEl.textContent=d.output;
  }).catch(function(e){ bMig.disabled=false; bMig.textContent=t('rec_migrate'); st.textContent=tf('rec_migrate_req_failed',(e&&e.message?e.message:t('rec_unknown'))); });
}
function mint(){
  bMint.disabled=true; bMint.textContent=t('rec_minting');
  fetch(base+'&action=mint').then(function(r){return r.json();}).then(function(d){
    bMint.disabled=false; bMint.textContent=t('rec_mint');
    if(d.error){ mintBox.style.display='block'; mintBox.textContent=tf('rec_mint_error',d.error); return; }
    var exp=new Date(d.expiresAt*1000).toUTCString();
    mintBox.style.display='block';
    mintBox.innerHTML='<strong>'+esc(tf('rec_mint_head',exp))+'</strong><code>'+
      d.token.replace(/&/g,'&amp;').replace(/</g,'&lt;')+'</code>'+esc(t('rec_mint_note'));
  }).catch(function(){ bMint.disabled=false; bMint.textContent=t('rec_mint'); mintBox.style.display='block'; mintBox.textContent=t('rec_mint_req_failed'); });
}
function submitPass(){
  var pass=passIn.value||'';
  if(!pass){ passIn.focus(); return; }
  bPass.disabled=true; bPass.textContent=t('rec_verifying');
  var body='passphrase='+encodeURIComponent(pass);
  fetch(base+'&action=passphrase', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:body}).then(function(r){return r.json();}).then(function(d){
    bPass.disabled=false; bPass.textContent=t('rec_pass_submit');
    if(!d.ok){ st.textContent=tf('rec_pass_error',(d.error||t('rec_unknown'))); return; }
    passIn.value=''; passBox.style.display='none'; render(d); if(!driving) drive();
  }).catch(function(){ bPass.disabled=false; bPass.textContent=t('rec_pass_submit'); st.textContent=t('rec_pass_req_failed'); });
}
function confirmComposer(){
  fetch(base+'&action=confirm_composer').then(function(r){return r.json();}).then(function(d){
    if(!d.ok){ st.textContent=tf('rec_confirm_error',(d.error||t('rec_unknown'))); return; }
    compWarn.style.display='none'; render(d); if(!driving) drive();
  }).catch(function(){ st.textContent=t('rec_confirm_req_failed'); });
}
function confirmComposerFix(){
  fetch(base+'&action=confirm_composer_fix').then(function(r){return r.json();}).then(function(d){
    if(!d.ok){ st.textContent=tf('rec_fix_error',(d.error||t('rec_unknown'))); return; }
    compWarn.style.display='none'; render(d); if(!driving) drive();
  }).catch(function(){ st.textContent=t('rec_fix_req_failed'); });
}
function cancelJob(){
  if(!confirm(t('rec_cancel_confirm'))) return;
  bCancel.disabled=true;
  fetch(base+'&action=cancel').then(function(r){return r.json();}).then(function(d){
    bCancel.disabled=false;
    if(!d.ok){ st.textContent=tf('rec_cancel_error',(d.error||t('rec_unknown'))); return; }
    render(d);
  }).catch(function(){ bCancel.disabled=false; st.textContent=t('rec_cancel_req_failed'); });
}
bDrive.addEventListener('click',drive);
bMig.addEventListener('click',migrate);
bMint.addEventListener('click',mint);
bPass.addEventListener('click',submitPass);
bCancel.addEventListener('click',cancelJob);
status();
</script>
</body></html>
