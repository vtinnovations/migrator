<?php

/*
 * Tahericreate Contao Migrator — standalone recovery panel.
 *
 * This file is auto-copied into the project's public/ directory by the bundle on every kernel
 * boot (idempotent md5 compare), mirroring the tahericreate/updater recovery panel. It runs
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

$expected = is_file($tokenFile) ? trim((string) file_get_contents($tokenFile)) : '';
$given = (string) ($_GET['key'] ?? $_POST['key'] ?? '');
$authed = '' !== $expected && '' !== $given && hash_equals($expected, $given);
$action = (string) ($_GET['action'] ?? '');

// Whole-plugin license gate. Keys are issued + verified remotely by the backend module
// (LicenseManager); this standalone panel only READS the cached verification result from
// var/migrator/license.json (no network call here). Same grace logic as LicenseManager::isLicensed().
$licenseFile = $scratch.'/license.json';
$licenseData = is_file($licenseFile) ? json_decode((string) file_get_contents($licenseFile), true) : null;
$licenseData = \is_array($licenseData) ? $licenseData : [];
$licKey = trim((string) ($licenseData['license_key'] ?? ''));
$licVerifiedAt = (int) ($licenseData['license_verified_at'] ?? 0);
$licExpiresAt = $licenseData['license_expires_at'] ?? null;
$licensed = '' !== $licKey
    && (null === $licExpiresAt || (int) $licExpiresAt >= time())
    && $licVerifiedAt > 0
    && (time() - $licVerifiedAt) <= 7 * 86400;

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

// Render one structured composer-audit warning ({code,args}) in German for the panel (the
// panel is standalone; the operator here is German). Args are HTML-escaped.
$fmtComposerWarn = static function ($w): string {
    if (\is_string($w)) {
        return htmlspecialchars($w, \ENT_QUOTES, 'UTF-8');
    }

    $w = (array) $w;
    $code = (string) ($w['code'] ?? '');
    $a = (array) ($w['args'] ?? []);
    $e = static fn (string $k): string => htmlspecialchars((string) ($a[$k] ?? ''), \ENT_QUOTES, 'UTF-8');
    $t = [
        'missing' => 'composer.json im Projekt-Root nicht gefunden — Paket kann vor dem Versand nicht geprüft werden.',
        'invalid_json' => 'composer.json ist kein gültiges JSON — vor der Migration korrigieren.',
        'packagist_disabled' => 'Packagist ist in der composer.json deaktiviert — alle Abhängigkeiten müssen aus den eigenen Repositories kommen, die am Ziel erreichbar sein müssen.',
        'repo_path' => 'Repository „%s" ist ein lokales PATH-Repo (%s) — der Ziel-Host hat dieses Verzeichnis nicht; composer install schlägt fehl, sofern das Paket nicht veröffentlicht ist oder derselbe Pfad dort existiert.',
        'repo_vcs' => 'Repository „%s" ist ein %s-Repo (%s) — das Ziel braucht Netzwerkzugang und Zugangsdaten beim composer install.',
        'repo_custom' => 'Eigenes composer-Repository „%s" (%s) — muss am Ziel erreichbar und authentifiziert sein.',
        'dev_constraint' => 'Paket „%s" ist mit einer dev-Version gefordert („%s") — nicht reproduzierbar, sofern das Quell-Repo am Ziel nicht erreichbar ist.',
        'min_stability' => 'minimum-stability ist „%s" (nicht stable) — das Ziel könnte andere, instabile Versionen auflösen.',
        'path_no_version' => 'Path-Paket „%s" (%s) hat keine „version" in seiner composer.json — composer install könnte es am Ziel ablehnen.',
    ];
    $tpl = $t[$code] ?? $code;

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

// Best-effort German rendering for the panel: load the bundle's Messages catalog via the composer
// autoloader (no kernel boot). If unavailable, raw English is shown.
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
$tr = static fn (string $s): string => $loadMsg() ? \Vtinnovations\Migrator\Support\Messages::translate('de', $s) : $s;
$trState = static fn (string $s): string => $loadMsg() ? \Vtinnovations\Migrator\Support\Messages::stateLabel('de', $s) : $s;
$trStep = static fn (string $s): string => $loadMsg() ? \Vtinnovations\Migrator\Support\Messages::stepLabel('de', $s) : $s;

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

/** Boot the application kernel in-process. Returns the kernel (container via getContainer()). */
$bootKernel = static function (string $root) {
    require_once $root.'/vendor/autoload.php';

    if (class_exists(\Symfony\Component\Dotenv\Dotenv::class) && is_file($root.'/.env')) {
        (new \Symfony\Component\Dotenv\Dotenv())->bootEnv($root.'/.env');
    }

    if (class_exists(\Contao\ManagerBundle\HttpKernel\ContaoKernel::class)) {
        $request = \Symfony\Component\HttpFoundation\Request::createFromGlobals();

        return \Contao\ManagerBundle\HttpKernel\ContaoKernel::fromRequest($root, $request);
    }

    if (class_exists('App\Kernel')) {
        $env = (string) ($_SERVER['APP_ENV'] ?? 'prod');
        $debug = filter_var($_SERVER['APP_DEBUG'] ?? false, \FILTER_VALIDATE_BOOL);

        return new \App\Kernel($env, $debug);
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
    $msg = '' === $expected
        ? '<p class="err">Es existiert noch kein Operator-Token. Starte einmal einen Export oder Import im Backend, damit die Token-Datei erstellt wird, und komm dann hierher zurück.</p>'
        : ('' === $given ? '' : '<p class="err">Falscher Token.</p>');
    ?><!DOCTYPE html>
<html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Migrator Wiederherstellung — Login</title><style>
body{font:14px/1.6 system-ui,sans-serif;background:#1d1d1d;color:#e6e6e6;display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0}
form{background:#262626;padding:28px;border-radius:10px;width:320px}
h1{font-size:17px;margin:0 0 16px}input{width:100%;box-sizing:border-box;padding:9px;border-radius:6px;border:1px solid #444;background:#1d1d1d;color:#e6e6e6;margin-bottom:12px}
button{width:100%;padding:10px;border:0;border-radius:6px;background:#3a9b7c;color:#fff;font-weight:600;cursor:pointer}
.err{color:#e26d6d}</style></head><body>
<form method="post"><h1>Contao Migrator — Wiederherstellung</h1><?= $msg ?>
<label>Operator-Token</label><input type="password" name="key" autocomplete="off" autofocus>
<button type="submit">Wiederherstellungs-Panel öffnen</button></form></body></html><?php
    exit;
}

// --- Authenticated JSON actions ------------------------------------------------------------
if ('status' === $action) {
    $json($jobStatus($findJob($jobsDir, false)));
}

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
            $json(['ok' => false, 'error' => 'Kein aktiver Auftrag zum Antreiben.']);
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
        $json(['ok' => false, 'error' => 'Auftrag konnte nicht neu gestartet werden.']);
    }

    $json(['ok' => true] + $jobStatus($findJob($jobsDir, false)));
}

// "Proceed anyway" past the pre-send composer.json audit warnings from the panel.
if ('confirm_composer' === $action) {
    $job = $findJob($jobsDir, true);

    if (null === $job) {
        $json(['ok' => false, 'error' => 'Kein aktiver Auftrag.']);
    }

    if (!$patchJob($jobsDir, (string) $job['id'], ['composerAuditConfirmed' => true], 'pending')) {
        $json(['ok' => false, 'error' => 'Auftrag konnte nicht neu gestartet werden.']);
    }

    $json(['ok' => true] + $jobStatus($findJob($jobsDir, false)));
}

// Abort the active job. Touch the lock-free cancel marker (a running tick picks it up mid-slice)
// and, if nothing is ticking, flip the state to cancelled directly on disk.
if ('cancel' === $action) {
    $job = $findJob($jobsDir, true);

    if (null === $job) {
        $json(['ok' => false, 'error' => 'Kein aktiver Auftrag.']);
    }

    @touch($jobsDir.'/'.(string) $job['id'].'.cancel');
    $patchJob($jobsDir, (string) $job['id'], [], 'cancelled');

    $json(['ok' => true] + $jobStatus($findJob($jobsDir, false)));
}

// "Fix it for the package": ship vendor/ + portable composer.json, then continue.
if ('confirm_composer_fix' === $action) {
    $job = $findJob($jobsDir, true);

    if (null === $job) {
        $json(['ok' => false, 'error' => 'Kein aktiver Auftrag.']);
    }

    if (!$patchJob($jobsDir, (string) $job['id'], ['composerFix' => true, 'composerAuditConfirmed' => true], 'pending')) {
        $json(['ok' => false, 'error' => 'Auftrag konnte nicht neu gestartet werden.']);
    }

    $json(['ok' => true] + $jobStatus($findJob($jobsDir, false)));
}

// --- Authenticated HTML panel --------------------------------------------------------------
$keyQ = rawurlencode($given);
?><!DOCTYPE html>
<html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Migrator Wiederherstellung</title><style>
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
<h1>Contao Migrator — Wiederherstellungs-Panel</h1>
<?php if (!$licensed) { ?><div class="lic">Dieses Plugin ist gesperrt. Gib im Backend-Modul einen gültigen Lizenzschlüssel ein, um die Wiederherstellungs-Aktionen freizuschalten. Der Status bleibt lesbar, aber Antreiben / Migrieren / Erzeugen sind deaktiviert.</div><?php } ?>
<div id="st">Lädt…</div>
<div id="barwrap"><div id="bar"></div></div>
<div class="btns">
  <button id="b-drive" class="drive"<?= $licensed ? '' : ' disabled' ?>>Aktiven Auftrag antreiben</button>
  <button id="b-mig" class="mig"<?= $licensed ? '' : ' disabled' ?>>contao:migrate ausführen</button>
  <button id="b-mint" class="mint"<?= $licensed ? '' : ' disabled' ?>>Kopplungstoken erzeugen</button>
  <button id="b-cancel" class="cxl"<?= $licensed ? '' : ' disabled' ?> style="display:none">Auftrag abbrechen</button>
</div>
<div id="mintbox"></div>
<div id="passbox" style="display:none"><strong>Dieses Paket ist passphrase-signiert.</strong> Gib die Export-Passphrase ein, um zu verifizieren und fortzufahren:
<div style="margin-top:8px"><input type="password" id="passin" placeholder="Export-Passphrase" autocomplete="new-password"><button id="b-pass" class="drive">Verifizieren &amp; fortfahren</button></div></div>
<div id="compwarn" style="display:none"></div>
<p class="hint">„Antreiben" bringt eine pausierte/laufende Migration voran. „contao:migrate ausführen" repariert das Datenbank-Schema (erstellt Tabellen, die ein neueres Contao nach einer versionsübergreifenden Wiederherstellung erwartet) UND veröffentlicht die Bundle-Web-Assets neu (erstellt public/bundles/ neu — behebt ein ungestyltes Backend/Frontend nach frischer Wiederherstellung). „Kopplungstoken erzeugen" erstellt einen Einmal-Token für Modus B (diese Installation ist das ZIEL) — füge ihn in der Quell-Installation ein. Alles läuft server-seitig in-process — keine Shell nötig.</p>
<pre id="log"></pre>
<script>
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
  if(!d.found){ st.textContent='Kein Migrations-Auftrag gefunden.'; return; }
  bar.style.width=(d.progress||0)+'%';
  var np=!!(d.meta&&d.meta.needsPassphrase);
  st.textContent='['+(d.stateLabel||d.state)+'] '+(d.stepLabel||d.step||'')+' — '+(d.progress||0)+'%'+(np?' — Passphrase nötig':'');
  if(bCancel){ bCancel.style.display = isTerminal(d.state) ? 'none' : 'inline-block'; }
  passBox.style.display = np ? 'block' : 'none';
  var cw=(d.meta&&d.meta.composerWarnings)||[];
  if(cw.length){
    compWarn.style.display='block';
    compWarn.innerHTML='<strong>composer.json-Warnungen vor Versand:</strong><ul>'+cw.map(function(w){return '<li>'+w+'</li>';}).join('')+'</ul><button id="b-compfix">Fürs Paket beheben</button> <button id="b-comp">Trotzdem fortfahren</button>';
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
  if(driving) return; driving=true; bDrive.disabled=true; bDrive.textContent='Treibe an…';
  (function loop(){
    fetch(base+'&action=tick').then(function(r){return r.json();}).then(function(d){
      if(d.error){ st.textContent='Tick-Fehler: '+d.error; driving=false; bDrive.disabled=false; bDrive.textContent='Aktiven Auftrag antreiben'; return; }
      render(d);
      if(d.found && !isTerminal(d.state)){ loop(); }
      else { driving=false; bDrive.disabled=false; bDrive.textContent='Aktiven Auftrag antreiben'; }
    }).catch(function(){ setTimeout(loop,3000); });
  })();
}
function migrate(){
  bMig.disabled=true; bMig.textContent='Migriere…'; st.textContent='Führe contao:migrate aus…';
  var httpStatus=0;
  fetch(base+'&action=migrate').then(function(r){ httpStatus=r.status; return r.text(); }).then(function(text){
    bMig.disabled=false; bMig.textContent='contao:migrate ausführen';
    var d=null; try{ d=JSON.parse(text); }catch(e){}
    if(!d){
      // Non-JSON body = PHP fatal / 500 / timeout. Surface the raw response so the real error is visible.
      st.textContent='Migrate fehlgeschlagen — HTTP '+httpStatus+' (kein JSON). Siehe Log.';
      logEl.textContent=text||'(leere Antwort — vermutlich PHP-Timeout oder Speicherlimit)';
      return;
    }
    if(d.error){ st.textContent='Migrate-Fehler: '+d.error; return; }
    st.textContent=d.ok?'contao:migrate fertig (Exit '+d.code+'). Backend neu laden.':'contao:migrate fehlgeschlagen (migrate Exit '+d.code+', assets Exit '+(d.assetsCode!=null?d.assetsCode:'n/a')+').';
    if(d.output) logEl.textContent=d.output;
  }).catch(function(e){ bMig.disabled=false; bMig.textContent='contao:migrate ausführen'; st.textContent='Migrate-Anfrage fehlgeschlagen (Netzwerk/Abbruch): '+(e&&e.message?e.message:'unbekannt'); });
}
function mint(){
  bMint.disabled=true; bMint.textContent='Erzeuge…';
  fetch(base+'&action=mint').then(function(r){return r.json();}).then(function(d){
    bMint.disabled=false; bMint.textContent='Kopplungstoken erzeugen';
    if(d.error){ mintBox.style.display='block'; mintBox.textContent='Erzeugungs-Fehler: '+d.error; return; }
    var exp=new Date(d.expiresAt*1000).toUTCString();
    mintBox.style.display='block';
    mintBox.innerHTML='<strong>Kopplungstoken (Einmal-Nutzung, gültig bis '+exp+'):</strong><code>'+
      d.token.replace(/&/g,'&amp;').replace(/</g,'&lt;')+'</code>Füge ihn in das Push-Formular der QUELL-Installation ein. Wird nur einmal angezeigt — jetzt kopieren.';
  }).catch(function(){ bMint.disabled=false; bMint.textContent='Kopplungstoken erzeugen'; mintBox.style.display='block'; mintBox.textContent='Erzeugungs-Anfrage fehlgeschlagen.'; });
}
function submitPass(){
  var pass=passIn.value||'';
  if(!pass){ passIn.focus(); return; }
  bPass.disabled=true; bPass.textContent='Verifiziere…';
  var body='passphrase='+encodeURIComponent(pass);
  fetch(base+'&action=passphrase', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:body}).then(function(r){return r.json();}).then(function(d){
    bPass.disabled=false; bPass.textContent='Verifizieren & fortfahren';
    if(!d.ok){ st.textContent='Passphrase-Fehler: '+(d.error||'unbekannt'); return; }
    passIn.value=''; passBox.style.display='none'; render(d); if(!driving) drive();
  }).catch(function(){ bPass.disabled=false; bPass.textContent='Verifizieren & fortfahren'; st.textContent='Passphrase-Anfrage fehlgeschlagen.'; });
}
function confirmComposer(){
  fetch(base+'&action=confirm_composer').then(function(r){return r.json();}).then(function(d){
    if(!d.ok){ st.textContent='Bestätigungs-Fehler: '+(d.error||'unbekannt'); return; }
    compWarn.style.display='none'; render(d); if(!driving) drive();
  }).catch(function(){ st.textContent='Bestätigungs-Anfrage fehlgeschlagen.'; });
}
function confirmComposerFix(){
  fetch(base+'&action=confirm_composer_fix').then(function(r){return r.json();}).then(function(d){
    if(!d.ok){ st.textContent='Fix-Fehler: '+(d.error||'unbekannt'); return; }
    compWarn.style.display='none'; render(d); if(!driving) drive();
  }).catch(function(){ st.textContent='Fix-Anfrage fehlgeschlagen.'; });
}
function cancelJob(){
  if(!confirm('Diesen Auftrag abbrechen?')) return;
  bCancel.disabled=true;
  fetch(base+'&action=cancel').then(function(r){return r.json();}).then(function(d){
    bCancel.disabled=false;
    if(!d.ok){ st.textContent='Abbrechen-Fehler: '+(d.error||'unbekannt'); return; }
    render(d);
  }).catch(function(){ bCancel.disabled=false; st.textContent='Abbrechen-Anfrage fehlgeschlagen.'; });
}
bDrive.addEventListener('click',drive);
bMig.addEventListener('click',migrate);
bMint.addEventListener('click',mint);
bPass.addEventListener('click',submitPass);
bCancel.addEventListener('click',cancelJob);
status();
</script>
</body></html>
