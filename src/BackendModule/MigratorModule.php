<?php

declare(strict_types=1);

namespace Vtinnovations\Migrator\BackendModule;

use Contao\Controller;
use Contao\System;
use Symfony\Component\HttpFoundation\Request;
use Vtinnovations\Migrator\Config\ConfigStore;
use Vtinnovations\Migrator\Config\Paths;
use Vtinnovations\Migrator\Job\JobFactory;
use Vtinnovations\Migrator\Job\JobState;
use Vtinnovations\Migrator\Job\JobStore;
use Vtinnovations\Migrator\Security\LicenseManager;
use Vtinnovations\Migrator\Security\TokenManager;
use Vtinnovations\Migrator\Transfer\ChunkAssembler;
use Vtinnovations\Migrator\Transfer\PairingStore;

/**
 * Backend dashboard for the migrator. Rendered via the legacy BE_MOD callback (no constructor
 * injection), so every collaborator is fetched from the container. Handles its own POST
 * actions (start export/import/push, mint a pairing, confirm a paused job) with a
 * POST/redirect/GET cycle, then renders recent jobs and a live monitor for the active one.
 *
 * The pipeline itself is driven by the dashboard's JS polling the backend status route, which
 * runs one time-budgeted JobRunner tick per poll.
 */
final class MigratorModule
{
    private JobFactory $jobs;
    private JobStore $store;
    private PairingStore $pairings;
    private Paths $paths;
    private ConfigStore $config;
    private ChunkAssembler $assembler;
    private TokenManager $tokens;
    private LicenseManager $license;

    /** @var array<string, string> UI strings for the active backend language. */
    private array $L = [];

    /** Inline pairing-token result, shown inside the Server-to-Server tab after a mint. */
    private string $mintResult = '';

    public function __construct()
    {
        $container = System::getContainer();
        $this->jobs = $container->get(JobFactory::class);
        $this->store = $container->get(JobStore::class);
        $this->pairings = $container->get(PairingStore::class);
        $this->paths = $container->get(Paths::class);
        $this->config = $container->get(ConfigStore::class);
        $this->assembler = $container->get(ChunkAssembler::class);
        $this->tokens = $container->get(TokenManager::class);
        $this->license = $container->get(LicenseManager::class);
    }

    public function generate(): string
    {
        $this->initLang();
        $request = System::getContainer()->get('request_stack')->getCurrentRequest();
        $notice = '';

        if (null !== $request && $request->isMethod('POST')) {
            $notice = $this->handlePost($request);
        }

        // Re-verify against the license server once the cache is older than a day so a revocation
        // (or expiry) takes effect without the operator manually re-entering the key.
        if (null !== $request && $this->license->isCacheStale()) {
            $this->license->refresh($request->getHost());
        }

        // Whole bundle is license-gated: until a valid key is stored, show only the activation
        // form. handlePost() already processed a save_license POST above (and redirects on success).
        if (!$this->license->isLicensed()) {
            return $this->renderLicenseGate($notice);
        }

        // Fall back to the newest still-running job so a plain reload (e.g. via the menu link,
        // which drops ?job=) keeps showing the live monitor instead of a frozen job list.
        $jobId = (string) ($request?->query->get('job') ?? '');

        if ('' === $jobId) {
            $jobId = $this->findActiveJobId();
        }

        $isess = $this->importSession($request?->query->get('isess'));

        return $this->render($jobId, $isess, $notice);
    }

    private function findActiveJobId(): string
    {
        $active = [];

        foreach ($this->store->listIds() as $id) {
            $job = $this->store->load($id);

            // Include paused jobs — they need the operator's confirm input, which lives in the
            // monitor's paused controls.
            if (null !== $job && !$job->state->isTerminal()) {
                $active[$id] = $job->createdAt;
            }
        }

        if ([] === $active) {
            return '';
        }

        arsort($active);

        return (string) array_key_first($active);
    }

    private function handlePost(Request $request): string
    {
        $action = (string) $request->request->get('tcmig_action', '');

        // Server-side license gate: every action except entering the license is refused until a
        // valid key is stored. The UI already hides the controls, but this is the authority.
        if ('save_license' !== $action && !$this->license->isLicensed()) {
            return '';
        }

        switch ($action) {
            case 'save_license':
                if ($this->license->activate((string) $request->request->get('license_key', ''), $request->getHost())) {
                    $this->redirectToJob(''); // PRG into the now-unlocked dashboard
                }

                // Surface the server's reason (invalid key, domain mismatch, expired, server down).
                return $this->box('error', $this->license->lastMessage() ?: $this->t('license_invalid'));

            case 'start_export':
                $job = $this->jobs->createExport([], $this->nullIfEmpty($request->request->get('passphrase')));
                $this->redirectToJob($job->id);
                break;

            case 'start_push':
                $job = $this->jobs->createPush([
                    'remoteUrl' => trim((string) $request->request->get('remoteUrl', '')),
                    'pairing' => trim((string) $request->request->get('pairing', '')),
                ], $this->nullIfEmpty($request->request->get('passphrase')));
                $this->redirectToJob($job->id);
                break;

            case 'start_import':
                return $this->handleImport($request);

            case 'start_import_split':
                $this->redirectToImport(bin2hex(random_bytes(6)));
                break;

            case 'import_part':
                return $this->handleImportPart($request);

            case 'import_assemble':
                return $this->handleImportAssemble($request);

            case 'mint':
                $pairing = $this->pairings->mint();
                $this->mintResult = $this->box(
                    'confirm',
                    sprintf($this->t('mint_text'), gmdate('H:i', $pairing['expiresAt'])),
                    '<code style="word-break:break-all">'.$this->esc($pairing['token']).'</code>'
                );

                return '';

            case 'confirm_passphrase':
                $this->handlePassphrase($request);
                break;

            case 'confirm_composer':
                $this->resumeJob((string) $request->request->get('job', ''), ['composerAuditConfirmed' => true]);
                break;

            case 'confirm_composer_fix':
                $this->resumeJob((string) $request->request->get('job', ''), ['composerFix' => true, 'composerAuditConfirmed' => true]);
                break;

            case 'confirm_compat':
                $this->resumeJob((string) $request->request->get('job', ''), ['compatConfirmed' => true]);
                break;

            case 'confirm_urlmap':
                $from = trim((string) $request->request->get('url_from', ''));
                $to = trim((string) $request->request->get('url_to', ''));
                $map = ('' !== $from && '' !== $to) ? [['from' => $from, 'to' => $to]] : [];
                $this->resumeJob((string) $request->request->get('job', ''), ['urlMap' => $map]);
                break;
        }

        return '';
    }

    private function handleImport(Request $request): string
    {
        $file = $request->files->get('package');

        if (null === $file || !$file->isValid()) {
            return $this->box('error', $this->t('err_no_package'));
        }

        $session = bin2hex(random_bytes(6));
        $dir = $this->paths->incomingDir($session);
        $name = 'upload.tcmig';
        $file->move($dir, $name);

        $job = $this->jobs->createImport(
            ['archive' => $dir.'/'.$name],
            $this->nullIfEmpty($request->request->get('passphrase'))
        );
        $this->redirectToJob($job->id);

        return '';
    }

    /**
     * Store one uploaded split-download volume as the next chunk of an import session. Parts are
     * appended in upload order, so the operator must upload .001, .002, … in sequence.
     */
    private function handleImportPart(Request $request): string
    {
        $session = $this->importSession($request->request->get('isess'));

        if ('' === $session) {
            return $this->box('error', $this->t('err_invalid_isess'));
        }

        $file = $request->files->get('part');

        if (null === $file || !$file->isValid()) {
            return $this->box('error', $this->t('err_no_part'));
        }

        $index = $this->assembler->state($session)['count'];
        $this->assembler->appendFile($session, $index, $file->getPathname());
        $this->redirectToImport($session);

        return '';
    }

    /**
     * Reassemble the uploaded parts into one .tcmig and start the import on it.
     */
    private function handleImportAssemble(Request $request): string
    {
        $session = $this->importSession($request->request->get('isess'));

        if ('' === $session) {
            return $this->box('error', $this->t('err_invalid_isess'));
        }

        $count = $this->assembler->state($session)['count'];

        if ($count < 1) {
            return $this->box('error', $this->t('err_no_parts'));
        }

        // No whole-payload sha here — the import pipeline verifies the manifest and per-chunk
        // checksums inside the package.
        $archive = $this->assembler->assemble($session, $count);
        $this->assembler->discardChunks($session);

        $job = $this->jobs->createImport(
            ['archive' => $archive],
            $this->nullIfEmpty($request->request->get('passphrase'))
        );
        $this->redirectToJob($job->id);

        return '';
    }

    private function importSession(mixed $raw): string
    {
        $value = \is_string($raw) ? $raw : '';

        return preg_match('/^[a-f0-9]{4,32}$/', $value) ? $value : '';
    }

    /**
     * @param array<string, mixed> $metaPatch
     */
    /**
     * Write the supplied export passphrase to the job's transient 0600 sidecar (never into the
     * job JSON), clear the needsPassphrase flag, and re-arm so verify_package re-runs.
     */
    private function handlePassphrase(Request $request): void
    {
        $jobId = (string) $request->request->get('job', '');
        $job = $this->store->load($jobId);

        if (null === $job) {
            return;
        }

        $passphrase = (string) $request->request->get('passphrase', '');

        if ('' !== $passphrase) {
            $file = $this->paths->jobSecretFile($jobId);
            file_put_contents($file, $passphrase, LOCK_EX);
            @chmod($file, 0600);
        }

        $this->resumeJob($jobId, ['needsPassphrase' => false]);
    }

    private function resumeJob(string $jobId, array $metaPatch): void
    {
        $job = $this->store->load($jobId);

        if (null === $job) {
            return;
        }

        foreach ($metaPatch as $k => $v) {
            $job->meta[$k] = $v;
        }

        // Re-arm a paused job so the next tick re-runs the paused step with the new input.
        if (JobState::Paused === $job->state) {
            $job->state = JobState::Pending;
        }

        $this->store->save($job);
        $this->redirectToJob($jobId);
    }

    private function render(string $jobId, string $isess, string $notice): string
    {
        $token = $this->csrfToken();
        $self = 'contao?do=tc_migrator';

        $opToken = $this->tokens->operatorToken();
        $recoveryUrl = '/_tcmig-recovery.php?key='.rawurlencode($opToken);
        $tf = $this->tokenField($token);

        // A fresh mint jumps straight to the Server-to-server tab so the operator sees the token.
        $defaultTab = '' !== $this->mintResult ? 's2s' : 'export';

        $html = '<div id="tl_buttons"><a href="contao" class="header_back" title="Back">Back</a></div>';
        $html .= $this->styles();
        $html .= '<div id="tcmig">';
        $html .= '<h1 class="tcmig-head">Contao Migrator</h1>';
        $html .= '<p class="tcmig-sub">'.$this->t('sub').'</p>';
        $html .= $notice;

        // Live monitor for the active job stays above the tabs so it's visible from any tab.
        if ('' !== $jobId) {
            $html .= $this->monitor($jobId);
        }

        // ── Tab navigation ──
        $html .= '<div class="tcmig-tabs">'
            .'<button type="button" class="tcmig-tab" data-tab="export">'.$this->t('tab_export').'</button>'
            .'<button type="button" class="tcmig-tab" data-tab="import">'.$this->t('tab_import').'</button>'
            .'<button type="button" class="tcmig-tab" data-tab="s2s">'.$this->t('tab_s2s').'</button>'
            .'<button type="button" class="tcmig-tab" data-tab="recovery">'.$this->t('tab_recovery').'</button>'
            .'</div>';

        // ── Panels ──
        $html .= '<div class="tcmig-panel" data-panel="export">'.$this->exportCard($tf).'</div>';
        $html .= '<div class="tcmig-panel" data-panel="import">'.$this->importForms($tf, $isess).'</div>';
        $html .= '<div class="tcmig-panel" data-panel="s2s">'
            .'<div class="tcmig-note">'.$this->t('s2s_intro').'</div>'
            .$this->mintResult
            .'<div class="tcmig-grid">'.$this->receiveCard($tf).$this->sendCard($tf).'</div></div>';
        $html .= '<div class="tcmig-panel" data-panel="recovery">'.$this->recoveryCard($recoveryUrl, $opToken).'</div>';

        $html .= $this->jobList($self);
        $html .= '</div>';

        $defJson = json_encode($defaultTab, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT);
        $html .= <<<JS
<script>
(function(){
  var valid = ['export','import','s2s','recovery'], def = {$defJson};
  function sw(name, hash){
    if(valid.indexOf(name) < 0){ name = def; }
    document.querySelectorAll('#tcmig .tcmig-tab').forEach(function(b){ b.classList.toggle('active', b.dataset.tab === name); });
    document.querySelectorAll('#tcmig .tcmig-panel').forEach(function(p){ p.classList.toggle('active', p.dataset.panel === name); });
    if(hash){ try{ history.replaceState(null, '', '#tab='+name); }catch(e){ location.hash = '#tab='+name; } }
  }
  document.querySelectorAll('#tcmig .tcmig-tab').forEach(function(b){
    b.addEventListener('click', function(){ sw(b.dataset.tab, true); });
  });
  var m = (location.hash.match(/tab=(\w+)/) || [])[1];
  sw(m || def, false);
})();
</script>
JS;

        return $html;
    }

    /**
     * License activation screen shown until a valid key is stored. Nothing else renders — no
     * export/import/push controls, no job list — so the bundle is unusable until activated.
     */
    private function renderLicenseGate(string $notice): string
    {
        $tf = $this->tokenField($this->csrfToken());

        $html = '<div id="tl_buttons"><a href="contao" class="header_back" title="Back">Back</a></div>';
        $html .= $this->styles();
        $html .= '<div id="tcmig">';
        $html .= '<h1 class="tcmig-head">Contao Migrator</h1>';
        $html .= '<p class="tcmig-sub">'.$this->t('license_locked').'</p>';
        $html .= $notice;
        $html .= '<div class="tcmig-grid"><div class="tcmig-card">'
            .'<div class="tcmig-card-h">'.$this->t('license_h').'</div>'
            .'<div class="tcmig-card-d">'.$this->t('license_d').'</div>'
            .'<form method="post" data-turbo="false">'.$tf
            .'<input type="hidden" name="tcmig_action" value="save_license">'
            .'<div class="tcmig-field"><label>'.$this->t('license_key_label').'</label>'
            .'<input type="text" name="license_key" class="tcmig-input" value="'.$this->esc($this->license->getLicenseKey()).'" autocomplete="off" spellcheck="false"></div>'
            .'<button type="submit" class="tcmig-btn">'.$this->t('license_btn').'</button></form></div></div>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Self-contained, theme-agnostic styling for the dashboard. Everything is scoped under
     * #tcmig and built on translucent grey overlays + an accent, so it reads correctly on both
     * the light and dark Contao backend themes without hard-coding fore/background colours.
     */
    private function styles(): string
    {
        return <<<'CSS'
<style>
#tcmig{--tc-accent:#2f9e72;--tc-accent-2:#36b083;--tc-bg:rgba(127,127,127,.06);--tc-bd:rgba(127,127,127,.22);--tc-bd-strong:rgba(127,127,127,.42);--tc-r:12px;max-width:1120px;padding:4px 18px 24px}
#tcmig *{box-sizing:border-box}
#tcmig .tcmig-head{margin:0 0 4px;font-size:23px;font-weight:700;letter-spacing:-.02em}
#tcmig .tcmig-sub{margin:0 0 18px;opacity:.7;font-size:13px;line-height:1.55}
#tcmig a{color:var(--tc-accent);text-decoration:none}
#tcmig a:hover{text-decoration:underline}
#tcmig code{background:rgba(127,127,127,.18);padding:2px 6px;border-radius:5px;word-break:break-all;font-size:12px}
#tcmig .tcmig-muted{opacity:.6}
#tcmig .tcmig-note{background:var(--tc-bg);border:1px solid var(--tc-bd);border-left:3px solid var(--tc-accent);border-radius:var(--tc-r);padding:13px 15px;margin:0 0 20px;font-size:13px;line-height:1.55}
#tcmig .tcmig-token{margin-top:8px}
#tcmig .tcmig-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(330px,1fr));gap:16px;margin:0 0 20px}
#tcmig .tcmig-card{background:var(--tc-bg);border:1px solid var(--tc-bd);border-radius:var(--tc-r);padding:18px 18px 20px;display:flex;flex-direction:column}
#tcmig .tcmig-card-h{display:flex;align-items:center;gap:8px;margin:0 0 4px;font-size:15px;font-weight:650}
#tcmig .tcmig-card-h::before{content:"";width:9px;height:9px;border-radius:50%;background:var(--tc-accent);flex:0 0 9px}
#tcmig .tcmig-card-d{opacity:.68;font-size:12.5px;line-height:1.5;margin:0 0 14px}
#tcmig form{margin:0}
#tcmig .tcmig-field{margin:0 0 12px}
#tcmig .tcmig-field>label{display:block;font-size:12px;font-weight:600;opacity:.82;margin:0 0 5px}
#tcmig .tcmig-input{width:100%;padding:9px 11px;border:1px solid var(--tc-bd-strong);border-radius:8px;background:rgba(127,127,127,.07);color:inherit;font:inherit;font-size:13px}
#tcmig .tcmig-input:focus{outline:0;border-color:var(--tc-accent);box-shadow:0 0 0 3px rgba(47,158,114,.18)}
#tcmig .tcmig-input[type=file]{padding:7px 9px;cursor:pointer}
#tcmig .tcmig-btn{display:inline-flex;align-items:center;gap:7px;margin-top:auto;align-self:flex-start;padding:9px 17px;border:0;border-radius:8px;background:var(--tc-accent);color:#fff;font:inherit;font-size:13px;font-weight:600;cursor:pointer;transition:filter .15s}
#tcmig .tcmig-btn:hover{filter:brightness(1.09)}
#tcmig .tcmig-btn--ghost{background:transparent;border:1px solid var(--tc-bd-strong);color:inherit}
#tcmig .tcmig-sep{border:0;border-top:1px dashed var(--tc-bd);margin:16px 0}
#tcmig .tcmig-monitor{background:var(--tc-bg);border:1px solid var(--tc-bd);border-radius:var(--tc-r);padding:18px;margin:0 0 20px}
#tcmig .tcmig-monitor-h{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:0 0 12px}
#tcmig .tcmig-monitor-h strong{font-size:15px}
#tcmig .tcmig-state{font-size:12.5px;opacity:.85}
#tcmig .tcmig-barwrap{height:10px;background:rgba(127,127,127,.22);border-radius:6px;overflow:hidden;margin:0 0 12px}
#tcmig #tcmig-bar{height:100%;width:0;background:linear-gradient(90deg,var(--tc-accent),var(--tc-accent-2));transition:width .35s ease}
#tcmig #tcmig-log{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:11.5px;line-height:1.55;max-height:230px;overflow:auto;background:rgba(0,0,0,.16);border:1px solid var(--tc-bd);border-radius:8px;padding:10px;white-space:pre-wrap;word-break:break-word}
#tcmig .tcmig-jobs{background:var(--tc-bg);border:1px solid var(--tc-bd);border-radius:var(--tc-r);overflow:hidden;margin:0 0 8px}
#tcmig .tcmig-jobs-h{padding:14px 16px;font-weight:650;font-size:15px;border-bottom:1px solid var(--tc-bd)}
#tcmig .tcmig-empty{padding:18px 16px;opacity:.6;font-size:13px}
#tcmig table.tcmig-tbl{width:100%;border-collapse:collapse;font-size:13px}
#tcmig .tcmig-tbl th{text-align:left;font-size:10.5px;text-transform:uppercase;letter-spacing:.05em;opacity:.55;padding:10px 16px;font-weight:600}
#tcmig .tcmig-tbl td{padding:11px 16px;border-top:1px solid var(--tc-bd);vertical-align:middle}
#tcmig .tcmig-badge{display:inline-block;padding:2px 10px;border-radius:20px;font-size:11px;font-weight:600;text-transform:capitalize;background:rgba(127,127,127,.2)}
#tcmig .st-completed{background:rgba(47,158,114,.18);color:var(--tc-accent-2)}
#tcmig .st-failed{background:rgba(214,80,80,.16);color:#e06b6b}
#tcmig .st-paused{background:rgba(214,160,60,.18);color:#d8a33c}
#tcmig .st-cancelled{background:rgba(140,140,140,.22);color:#b0b0b0}
#tcmig .tcmig-alert{border-radius:10px;padding:12px 14px;margin:0 0 16px;font-size:13px;line-height:1.5;border:1px solid var(--tc-bd)}
#tcmig .tcmig-alert--err{background:rgba(214,80,80,.1);border-color:rgba(214,80,80,.42)}
#tcmig .tcmig-alert--ok{background:rgba(47,158,114,.1);border-color:rgba(47,158,114,.42)}
#tcmig .tcmig-linkbtn{background:none;border:0;color:var(--tc-accent);cursor:pointer;padding:0;font:inherit;text-decoration:underline}
#tcmig .tcmig-tabs{display:flex;gap:4px;border-bottom:2px solid var(--tc-bd);margin:0 0 20px;flex-wrap:wrap}
#tcmig .tcmig-tab{background:transparent;border:0;border-bottom:3px solid transparent;color:inherit;opacity:.58;padding:10px 18px;cursor:pointer;font:inherit;font-size:13.5px;font-weight:600;margin-bottom:-2px;transition:opacity .15s,color .15s,background .15s;border-radius:8px 8px 0 0}
#tcmig .tcmig-tab:hover{opacity:.9;background:rgba(127,127,127,.08)}
#tcmig .tcmig-tab.active{opacity:1;color:var(--tc-accent);border-bottom-color:var(--tc-accent)}
#tcmig .tcmig-panel{display:none}
#tcmig .tcmig-panel.active{display:block}
</style>
CSS;
    }

    /**
     * Pick the UI string table for the active backend language. Contao sets
     * $GLOBALS['TL_LANGUAGE'] from the logged-in backend user's preference; anything that is
     * not German falls back to English.
     */
    private function initLang(): void
    {
        $lang = str_starts_with((string) ($GLOBALS['TL_LANGUAGE'] ?? 'en'), 'de') ? 'de' : 'en';
        $this->L = $this->strings()[$lang];
    }

    private function t(string $key): string
    {
        return $this->L[$key] ?? $key;
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function strings(): array
    {
        return [
            'en' => [
                'sub' => 'Move a Contao&nbsp;5 site to a new host — download a portable package, import one, or push it directly server&#8209;to&#8209;server.',
                'tab_export' => 'Export',
                'tab_import' => 'Import',
                'tab_s2s' => 'Server&#8209;to&#8209;server',
                'tab_recovery' => 'Recovery panel',
                'license_locked' => 'This plugin is free but requires a license. Get your free key at v-t.one and enter it to unlock every feature.',
                'license_h' => 'License activation',
                'license_d' => 'The migrator is free, but needs a free license key (registered per domain at v-t.one). Enter your key here — it is verified online.',
                'license_key_label' => 'License key',
                'license_btn' => 'Activate license',
                'license_invalid' => 'That license key is not valid. The plugin stays locked.',
                'recovery_h' => 'Standalone recovery panel',
                'recovery_d' => 'A web-root file that drives a migration and runs <code>contao:migrate</code> independently of the backend — use it if the backend breaks after a cross-version restore.',
                'recovery_link' => 'Open standalone recovery panel&nbsp;&rarr;',
                'recovery_note' => ' A web-root file that drives a migration and runs <code>contao:migrate</code> independently of the backend — use it if the backend breaks after a cross-version restore.',
                'recovery_token' => 'Recovery token',
                'recovery_token_loc' => '(also at <code>var/migrator/auth.token</code>, needed when the backend is down)',
                'export_h' => 'Export',
                'export_d' => 'Build a portable <code>.tcmig</code> package of this site and download it.',
                'export_pass' => 'Export passphrase',
                'export_pass_hint' => '(optional — encrypts secrets &amp; signs the package)',
                'export_btn' => 'Start export',
                'import_h' => 'Import',
                'import_d' => 'Upload a <code>.tcmig</code> package and restore it onto this site.',
                'import_file' => 'Package file',
                'import_file_hint' => '(single .tcmig)',
                'pass' => 'Passphrase',
                'pass_hint_signed' => '(if the package was passphrase-signed)',
                'import_btn' => 'Upload &amp; import',
                'split_q' => 'Package too large for a single upload? Split the download below into small parts, then select them all here — they upload one after another.',
                'split_size_label' => 'Max MB per part',
                'split_prepare_btn' => 'Prepare parts',
                'split_parts_label' => 'Select all parts at once (.001, .002, …)',
                'split_upload_btn' => 'Upload &amp; import',
                'js_no_files' => 'No files selected.',
                'js_uploading' => 'Uploading part',
                'js_upload_err' => 'Error on part',
                'js_upload_fail' => 'Upload failed (network/timeout) — reload and retry.',
                'js_assembling' => 'Assembling parts & starting import…',
                'js_assemble_err' => 'Assemble error:',
                'js_assemble_fail' => 'Assemble failed (network/timeout).',
                'js_fits_one' => 'Package fits one file — use Download above.',
                'js_parts' => 'parts of ~',
                'js_total' => 'total',
                'js_part' => 'part',
                'js_dl_all' => 'Download all sequentially',
                'split_start_btn' => 'Start split import',
                'split_session' => 'Split import session %s — %d part(s) uploaded. Upload the volumes in order (.001, .002, …); the next expected is part %s.',
                'next_part' => 'Next part file',
                'upload_part_btn' => 'Upload part %s',
                'assemble_btn' => 'Assemble %d part(s) &amp; import',
                's2s_intro' => '<strong>Direct server-to-server transfer</strong> — no manual download/upload. One install is the <strong>DESTINATION</strong> (the new host, receives), the other the <strong>SOURCE</strong> (the old host, sends). Run the migrator backend on BOTH.'
                    .'<ol style="margin:10px 0 4px;padding-left:22px;line-height:1.7">'
                    .'<li><strong>On the DESTINATION</strong> (this backend on the new host) — card &ldquo;Receive&rdquo; below: click &ldquo;Mint pairing token&rdquo;. It is a single-use token, valid only for this one transfer and only for a short time.</li>'
                    .'<li><strong>Copy</strong> the token and take it to the SOURCE.</li>'
                    .'<li><strong>On the SOURCE</strong> (the old host&rsquo;s migrator backend) — card &ldquo;Send&rdquo;: paste the destination&rsquo;s base URL and the token, set a passphrase, then &ldquo;Build &amp; push&rdquo;.</li>'
                    .'<li>The source builds the package and pushes it HMAC-signed straight to the destination, which starts the import automatically.</li>'
                    .'</ol><strong>Important:</strong> the package must be <strong>passphrase-signed</strong> — you need the SAME passphrase on the destination to verify it, so note it down.',
                'recv_h' => 'Receive',
                'recv_mode' => '(Mode B — DESTINATION)',
                'recv_d' => 'On the NEW host: mint the single-use token here, then hand it to the source.',
                'mint_btn' => 'Mint pairing token',
                'send_h' => 'Send',
                'send_mode' => '(Mode B — SOURCE)',
                'send_d' => 'On the OLD host: build the package and push it straight to the paired destination.',
                'dest_url' => 'Destination base URL',
                'pairing' => 'Pairing token',
                'send_pass_hint' => '(required so the destination can verify the package)',
                'push_btn' => 'Build &amp; push',
                'job' => 'Job',
                'download_pkg' => 'Download package',
                'paused_pass_msg' => 'This package is passphrase-signed. Enter the export passphrase to verify and continue.',
                'paused_pass_btn' => 'Verify &amp; continue',
                'compat_warn' => 'Compatibility warnings:',
                'compat_btn' => 'Proceed anyway',
                'composer_warn' => 'composer.json issues that may break install on the destination — review before sending:',
                'composer_btn' => 'Proceed anyway',
                'composer_fix_btn' => 'Fix it for the migration package (ship vendor/ + portable composer.json)',
                'cw_missing' => 'composer.json not found in the project root — the package cannot be audited before sending.',
                'cw_invalid_json' => 'composer.json is not valid JSON — fix it before migrating.',
                'cw_repo_path' => 'Repository "%s" is a local PATH repo (%s) — the destination host will not have this directory, so composer install fails unless the package is published or the same path exists there.',
                'cw_repo_vcs' => 'Repository "%s" is a %s repo (%s) — the destination needs network access and credentials to reach it during composer install.',
                'cw_repo_custom' => 'Custom composer repository "%s" (%s) — make sure it is reachable and authenticated on the destination host.',
                'cw_packagist_disabled' => 'Packagist is disabled in composer.json — every dependency must come from the custom repositories, which have to be reachable on the destination host.',
                'cw_dev_constraint' => 'Package "%s" is required at a dev constraint ("%s") — dev versions are not reproducible unless their source repo is reachable on the destination.',
                'cw_min_stability' => 'minimum-stability is "%s" (not stable) — the destination may resolve different, unstable versions than the source.',
                'cw_path_no_version' => 'Path package "%s" (%s) has no "version" in its composer.json — composer install may refuse it on the destination (path packages should pin a version).',
                'urlmap_msg' => 'Confirm the host rewrite before the database is rewritten:',
                'urlmap_old' => 'Old host',
                'urlmap_new' => 'New host',
                'urlmap_btn' => 'Apply rewrite',
                'jobs_h' => 'Jobs',
                'no_jobs' => 'No jobs yet.',
                'col_id' => 'ID',
                'col_type' => 'Type',
                'col_state' => 'State',
                'col_progress' => 'Progress',
                'col_action' => 'Action',
                'cancel_btn' => 'Cancel',
                'cancel_confirm' => 'Cancel this job?',
                'delete_btn' => 'Delete',
                'delete_confirm' => 'Delete this job and its package/backup? This cannot be undone.',
                'dl' => 'download',
                'split_parts' => 'split parts',
                'mint_text' => 'Pairing token (valid until %s UTC) — paste this into the SOURCE install. It is shown only once:',
                'err_no_package' => 'No valid package uploaded.',
                'err_invalid_isess' => 'Invalid import session.',
                'err_no_part' => 'No valid part uploaded.',
                'err_no_parts' => 'No parts uploaded yet.',
                'err_unknown_job' => 'Unknown job %s.',
            ],
            'de' => [
                'sub' => 'Eine Contao&nbsp;5-Website auf einen neuen Host umziehen — als portables Paket herunterladen, ein Paket importieren oder direkt von Server zu Server übertragen.',
                'tab_export' => 'Export',
                'tab_import' => 'Import',
                'tab_s2s' => 'Server&#8209;zu&#8209;Server',
                'tab_recovery' => 'Wiederherstellung',
                'license_locked' => 'Dieses Plugin ist kostenlos, benötigt aber eine Lizenz. Hol dir deinen kostenlosen Schlüssel auf v-t.one und gib ihn ein, um alle Funktionen freizuschalten.',
                'license_h' => 'Lizenz aktivieren',
                'license_d' => 'Der Migrator ist kostenlos, braucht aber einen kostenlosen Lizenzschlüssel (pro Domain auf v-t.one registriert). Gib deinen Schlüssel hier ein — er wird online verifiziert.',
                'license_key_label' => 'Lizenzschlüssel',
                'license_btn' => 'Lizenz aktivieren',
                'license_invalid' => 'Dieser Lizenzschlüssel ist ungültig. Das Plugin bleibt gesperrt.',
                'recovery_h' => 'Eigenständiges Wiederherstellungs-Panel',
                'recovery_d' => 'Eine Datei im Web-Root, die eine Migration steuert und <code>contao:migrate</code> unabhängig vom Backend ausführt — nutze sie, falls das Backend nach einer versionsübergreifenden Wiederherstellung nicht mehr lädt.',
                'recovery_link' => 'Eigenständiges Wiederherstellungs-Panel öffnen&nbsp;&rarr;',
                'recovery_note' => ' Eine Datei im Web-Root, die eine Migration steuert und <code>contao:migrate</code> unabhängig vom Backend ausführt — nutze sie, falls das Backend nach einer versionsübergreifenden Wiederherstellung nicht mehr lädt.',
                'recovery_token' => 'Wiederherstellungs-Token',
                'recovery_token_loc' => '(auch in <code>var/migrator/auth.token</code>, nötig wenn das Backend nicht erreichbar ist)',
                'export_h' => 'Export',
                'export_d' => 'Ein portables <code>.tcmig</code>-Paket dieser Website erstellen und herunterladen.',
                'export_pass' => 'Export-Passphrase',
                'export_pass_hint' => '(optional — verschlüsselt Geheimnisse &amp; signiert das Paket)',
                'export_btn' => 'Export starten',
                'import_h' => 'Import',
                'import_d' => 'Ein <code>.tcmig</code>-Paket hochladen und auf dieser Website wiederherstellen.',
                'import_file' => 'Paketdatei',
                'import_file_hint' => '(einzelne .tcmig)',
                'pass' => 'Passphrase',
                'pass_hint_signed' => '(falls das Paket mit einer Passphrase signiert wurde)',
                'import_btn' => 'Hochladen &amp; importieren',
                'split_q' => 'Paket zu groß für einen einzelnen Upload? Teile den Download unten in kleine Teile, wähle sie dann hier alle aus — sie werden nacheinander hochgeladen.',
                'split_size_label' => 'Max. MB pro Teil',
                'split_prepare_btn' => 'Teile vorbereiten',
                'split_parts_label' => 'Alle Teile auf einmal wählen (.001, .002, …)',
                'split_upload_btn' => 'Hochladen &amp; importieren',
                'js_no_files' => 'Keine Dateien gewählt.',
                'js_uploading' => 'Lade Teil',
                'js_upload_err' => 'Fehler bei Teil',
                'js_upload_fail' => 'Upload fehlgeschlagen (Netzwerk/Timeout) — neu laden und erneut versuchen.',
                'js_assembling' => 'Füge Teile zusammen & starte Import…',
                'js_assemble_err' => 'Assemble-Fehler:',
                'js_assemble_fail' => 'Assemble fehlgeschlagen (Netzwerk/Timeout).',
                'js_fits_one' => 'Paket passt in eine Datei — nutze Download oben.',
                'js_parts' => 'Teile à ~',
                'js_total' => 'gesamt',
                'js_part' => 'Teil',
                'js_dl_all' => 'Alle nacheinander laden',
                'split_start_btn' => 'Geteilten Import starten',
                'split_session' => 'Geteilte Import-Sitzung %s — %d Teil(e) hochgeladen. Lade die Teile der Reihe nach hoch (.001, .002, …); als Nächstes wird Teil %s erwartet.',
                'next_part' => 'Nächste Teildatei',
                'upload_part_btn' => 'Teil %s hochladen',
                'assemble_btn' => '%d Teil(e) zusammenfügen &amp; importieren',
                's2s_intro' => '<strong>Direkte Übertragung von Server zu Server</strong> — kein manuelles Herunterladen/Hochladen. Eine Installation ist das <strong>ZIEL</strong> (neuer Host, empfängt), die andere die <strong>QUELLE</strong> (alter Host, sendet). Der Migrator läuft auf BEIDEN.'
                    .'<ol style="margin:10px 0 4px;padding-left:22px;line-height:1.7">'
                    .'<li><strong>Am ZIEL</strong> (dieses Backend auf dem neuen Host) — Karte „Empfangen" unten: „Kopplungstoken erzeugen" klicken. Ein Einmal-Token, gültig nur für diese eine Übertragung und nur kurze Zeit.</li>'
                    .'<li>Token <strong>kopieren</strong> und zur QUELLE bringen.</li>'
                    .'<li><strong>An der QUELLE</strong> (Migrator-Backend des alten Hosts) — Karte „Senden": Basis-URL des Ziels und Token einfügen, eine Passphrase setzen, dann „Erstellen &amp; senden".</li>'
                    .'<li>Die Quelle baut das Paket und pusht es HMAC-signiert direkt ans Ziel, das den Import automatisch startet.</li>'
                    .'</ol><strong>Wichtig:</strong> Das Paket muss <strong>passphrase-signiert</strong> sein — dieselbe Passphrase brauchst du am Ziel zum Verifizieren. Notiere sie dir.',
                'recv_h' => 'Empfangen',
                'recv_mode' => '(Modus B — ZIEL)',
                'recv_d' => 'Auf dem NEUEN Host: hier den Einmal-Token erzeugen und der Quelle geben.',
                'mint_btn' => 'Kopplungstoken erzeugen',
                'send_h' => 'Senden',
                'send_mode' => '(Modus B — QUELLE)',
                'send_d' => 'Auf dem ALTEN Host: Paket bauen und direkt an das gekoppelte Ziel senden.',
                'dest_url' => 'Basis-URL des Ziels',
                'pairing' => 'Kopplungstoken',
                'send_pass_hint' => '(erforderlich, damit das Ziel das Paket verifizieren kann)',
                'push_btn' => 'Erstellen &amp; senden',
                'job' => 'Auftrag',
                'download_pkg' => 'Paket herunterladen',
                'paused_pass_msg' => 'Dieses Paket ist mit einer Passphrase signiert. Gib die Export-Passphrase ein, um es zu verifizieren und fortzufahren.',
                'paused_pass_btn' => 'Verifizieren &amp; fortfahren',
                'compat_warn' => 'Kompatibilitätswarnungen:',
                'compat_btn' => 'Trotzdem fortfahren',
                'composer_warn' => 'composer.json-Probleme, die die Installation am Ziel stören könnten — vor dem Versand prüfen:',
                'composer_btn' => 'Trotzdem fortfahren',
                'composer_fix_btn' => 'Fürs Migrations-Paket beheben (vendor/ mitschicken + portable composer.json)',
                'cw_missing' => 'composer.json im Projekt-Root nicht gefunden — das Paket kann vor dem Versand nicht geprüft werden.',
                'cw_invalid_json' => 'composer.json ist kein gültiges JSON — vor der Migration korrigieren.',
                'cw_repo_path' => 'Repository „%s" ist ein lokales PATH-Repo (%s) — der Ziel-Host hat dieses Verzeichnis nicht, daher schlägt composer install fehl, sofern das Paket nicht veröffentlicht ist oder derselbe Pfad dort existiert.',
                'cw_repo_vcs' => 'Repository „%s" ist ein %s-Repo (%s) — das Ziel braucht Netzwerkzugang und Zugangsdaten, um es beim composer install zu erreichen.',
                'cw_repo_custom' => 'Eigenes composer-Repository „%s" (%s) — stelle sicher, dass es am Ziel-Host erreichbar und authentifiziert ist.',
                'cw_packagist_disabled' => 'Packagist ist in der composer.json deaktiviert — alle Abhängigkeiten müssen aus den eigenen Repositories kommen, die am Ziel-Host erreichbar sein müssen.',
                'cw_dev_constraint' => 'Paket „%s" ist mit einer dev-Version gefordert („%s") — dev-Versionen sind nicht reproduzierbar, sofern das Quell-Repo am Ziel nicht erreichbar ist.',
                'cw_min_stability' => 'minimum-stability ist „%s" (nicht stable) — das Ziel könnte andere, instabile Versionen auflösen als die Quelle.',
                'cw_path_no_version' => 'Path-Paket „%s" (%s) hat keine „version" in seiner composer.json — composer install könnte es am Ziel ablehnen (Path-Pakete sollten eine Version pinnen).',
                'urlmap_msg' => 'Bestätige die Host-Umschreibung, bevor die Datenbank umgeschrieben wird:',
                'urlmap_old' => 'Alter Host',
                'urlmap_new' => 'Neuer Host',
                'urlmap_btn' => 'Umschreibung anwenden',
                'jobs_h' => 'Aufträge',
                'no_jobs' => 'Noch keine Aufträge.',
                'col_id' => 'ID',
                'col_type' => 'Typ',
                'col_state' => 'Status',
                'col_progress' => 'Fortschritt',
                'col_action' => 'Aktion',
                'cancel_btn' => 'Abbrechen',
                'cancel_confirm' => 'Diesen Auftrag abbrechen?',
                'delete_btn' => 'Löschen',
                'delete_confirm' => 'Diesen Auftrag samt Paket/Backup löschen? Kann nicht rückgängig gemacht werden.',
                'dl' => 'herunterladen',
                'split_parts' => 'Teile',
                'mint_text' => 'Kopplungstoken (gültig bis %s UTC) — füge ihn in der QUELL-Installation ein. Er wird nur einmal angezeigt:',
                'err_no_package' => 'Kein gültiges Paket hochgeladen.',
                'err_invalid_isess' => 'Ungültige Import-Sitzung.',
                'err_no_part' => 'Kein gültiger Teil hochgeladen.',
                'err_no_parts' => 'Noch keine Teile hochgeladen.',
                'err_unknown_job' => 'Unbekannter Auftrag %s.',
            ],
        ];
    }

    private function monitor(string $jobId): string
    {
        $job = $this->store->load($jobId);

        if (null === $job) {
            return $this->box('error', sprintf($this->t('err_unknown_job'), $this->esc($jobId)));
        }

        $router = System::getContainer()->get('router');
        $statusUrl = $router->generate('tcmig_status', ['id' => $jobId]);
        $tickUrl = $router->generate('tcmig_tick', ['id' => $jobId]);
        $downloadUrl = $router->generate('tcmig_download', ['id' => $jobId]);
        $cancelUrl = $router->generate('tcmig_cancel', ['id' => $jobId]);
        $jid = $this->esc($jobId);

        $extra = $this->pausedControls($job, 'contao?do=tc_migrator', $this->csrfToken());

        // Values are JSON-encoded so they land safely inside the JS string literals.
        $jsonFlags = JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT;
        $statusJson = json_encode($statusUrl, $jsonFlags);
        $tickJson = json_encode($tickUrl, $jsonFlags);
        $dlJson = json_encode($downloadUrl, $jsonFlags);
        $idJson = json_encode($jobId, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT);
        $slJson = json_encode([
            'fits_one' => $this->t('js_fits_one'),
            'parts' => $this->t('js_parts'),
            'total' => $this->t('js_total'),
            'part' => $this->t('js_part'),
            'dl_all' => $this->t('js_dl_all'),
        ], $jsonFlags);
        $cancelJson = json_encode($cancelUrl, $jsonFlags);
        $tokJson = json_encode($this->csrfToken(), $jsonFlags);
        $cxlConfirmJson = json_encode($this->t('cancel_confirm'), $jsonFlags);

        // Two independent loops:
        //  - status (fast, read-only) keeps the bar/log live every 1.5s;
        //  - drive() runs one tick then immediately calls itself again, so the job advances
        //    continuously while the tab is open (the tick endpoint releases the session lock,
        //    so it never blocks the status poll). The background cron covers the closed tab.
        // On a terminal/paused state both loops stop and the page reloads at most once
        // (sessionStorage guard) so the server can render the download link / paused controls.
        $js = <<<JS
<script>
(function(){
  var statusUrl = {$statusJson}, tickUrl = {$tickJson}, dlUrl = {$dlJson}, jobId = {$idJson}, SL = {$slJson};
  var cancelUrl = {$cancelJson}, TOKEN = {$tokJson}, CXL_CONFIRM = {$cxlConfirmJson};
  var bar = document.getElementById('tcmig-bar');
  var log = document.getElementById('tcmig-log');
  var st = document.getElementById('tcmig-state');
  var dl = document.getElementById('tcmig-dl');
  var splitBox = document.getElementById('tcmig-split');
  var cancelBtn = document.getElementById('tcmig-cancel');
  var done = false;
  function fmtMb(b){ return (b/1048576).toFixed(1)+' MB'; }
  function downloadAll(base, parts, mb){
    var i = 0;
    (function next(){
      if(i >= parts) return;
      var a = document.createElement('a'); a.href = base+'?part='+i+'&vol='+mb; a.download = '';
      document.body.appendChild(a); a.click(); document.body.removeChild(a);
      i++; setTimeout(next, 1200); // stagger so the browser doesn't drop concurrent downloads
    })();
  }
  function prepareSplit(){
    var mbEl = document.getElementById('tcmig-split-mb'), out = document.getElementById('tcmig-split-out');
    var mb = parseInt(mbEl.value, 10) || 10; if(mb < 1) mb = 1;
    out.textContent = '…';
    fetch(dlUrl+'?meta=1&vol='+mb, {credentials:'same-origin'}).then(function(r){return r.json();}).then(function(d){
      if(!d.parts){ out.textContent = '(error)'; return; }
      if(d.parts <= 1){ out.textContent = SL.fits_one; return; }
      var h = '<div style="margin-bottom:6px">'+d.parts+' '+SL.parts+mb+' MB ('+fmtMb(d.bytes)+' '+SL.total+'):</div>';
      for(var i=0;i<d.parts;i++){ h += '<a href="'+dlUrl+'?part='+i+'&vol='+mb+'" style="margin-right:10px">'+SL.part+' '+(i+1)+'/'+d.parts+'</a>'; }
      h += '<div style="margin-top:10px"><button type="button" id="tcmig-dlall" class="tcmig-btn">'+SL.dl_all+'</button></div>';
      out.innerHTML = h;
      var all = document.getElementById('tcmig-dlall');
      if(all){ all.addEventListener('click', function(){ downloadAll(dlUrl, d.parts, mb); }); }
    }).catch(function(){ out.textContent = '(error)'; });
  }
  function finish(state){
    if(done) return; done = true;
    var key = 'tcmig_reloaded_'+jobId;
    if(sessionStorage.getItem(key) !== state){
      sessionStorage.setItem(key, state);
      setTimeout(function(){location.reload();}, 600);
    }
  }
  function isTerminal(s){ return s==='completed' || s==='failed' || s==='paused' || s==='cancelled'; }
  function isFinal(s){ return s==='completed' || s==='failed' || s==='cancelled'; }
  function doCancel(){
    if(!confirm(CXL_CONFIRM)) return;
    cancelBtn.disabled = true; cancelBtn.textContent = '…';
    var fd = new FormData(); fd.append('REQUEST_TOKEN', TOKEN);
    fetch(cancelUrl, {method:'POST', credentials:'same-origin', body:fd})
      .then(function(){ setTimeout(function(){ location.reload(); }, 500); })
      .catch(function(){ cancelBtn.disabled = false; });
  }
  if(cancelBtn){ cancelBtn.addEventListener('click', doCancel); }
  function status(){
    if(done) return;
    fetch(statusUrl, {credentials:'same-origin'}).then(function(r){return r.json();}).then(function(d){
      if(d.error){ st.textContent = d.error; return; }
      bar.style.width = Math.round(d.progress*100)+'%';
      st.textContent = (d.stateLabel||d.state) + (d.stepLabel ? ' — '+d.stepLabel : (d.step ? ' — '+d.step : ''));
      log.innerHTML = (d.log||[]).map(function(l){return '['+l.level+'] '+l.msg;}).join('<br>');
      if(cancelBtn){ cancelBtn.style.display = isFinal(d.state) ? 'none' : 'inline-flex'; }
      if(isTerminal(d.state)){
        if(dl && d.meta && d.meta.download && d.meta.download.ready){ dl.href = dlUrl; dl.style.display = 'inline-block'; if(splitBox) splitBox.style.display = 'block'; }
        finish(d.state); return;
      }
      setTimeout(status, 1500);
    }).catch(function(){ setTimeout(status, 3000); });
  }
  function drive(){
    if(done) return;
    fetch(tickUrl, {credentials:'same-origin'}).then(function(r){return r.json();}).then(function(d){
      if(d.error || isTerminal(d.state)){ return; } // status() handles the terminal UI/reload
      drive(); // straight into the next tick
    }).catch(function(){ setTimeout(drive, 3000); });
  }
  var sb = document.getElementById('tcmig-split-btn');
  if(sb){ sb.addEventListener('click', prepareSplit); }
  status();
  drive();
})();
</script>
JS;

        return '<div class="tcmig-monitor">'
            .'<div class="tcmig-monitor-h"><strong>'.$this->t('job').' '.$jid.'</strong>'
            .'<span id="tcmig-state" class="tcmig-state">…</span></div>'
            .'<div class="tcmig-barwrap"><div id="tcmig-bar"></div></div>'
            .'<div id="tcmig-log"></div>'
            .'<a id="tcmig-dl" href="#" class="tcmig-btn" style="display:none;margin-top:12px">'.$this->t('download_pkg').'</a>'
            .'<div id="tcmig-split" style="display:none;margin-top:14px">'
            .'<label style="font-size:12px;font-weight:600;opacity:.82;margin-right:8px">'.$this->t('split_size_label').'</label>'
            .'<input id="tcmig-split-mb" type="number" min="1" value="10" class="tcmig-input" style="width:90px;display:inline-block">'
            .'<button type="button" id="tcmig-split-btn" class="tcmig-btn tcmig-btn--ghost" style="margin-left:8px">'.$this->t('split_prepare_btn').'</button>'
            .'<div id="tcmig-split-out" style="margin-top:10px;font-size:12.5px;line-height:1.9"></div></div>'
            .'<button type="button" id="tcmig-cancel" class="tcmig-btn tcmig-btn--ghost" style="display:none;margin-top:12px">'.$this->t('cancel_btn').'</button>'
            .$extra
            .'</div>'
            .$js;
    }

    private function pausedControls($job, string $self, string $token): string
    {
        if (JobState::Paused !== $job->state) {
            return '';
        }

        $jid = $this->esc($job->id);

        // Pre-send composer.json audit (preflight) — blocks before anything is built/shipped.
        $composerWarnings = $job->meta['composerWarnings'] ?? [];

        if (!empty($composerWarnings) && empty($job->meta['composerAuditConfirmed'])) {
            $list = implode('<br>', array_map([$this, 'composerWarnText'], $composerWarnings));

            return '<hr class="tcmig-sep">'
                .'<div class="tcmig-alert tcmig-alert--err">'.$this->t('composer_warn').'<br>'.$list.'</div>'
                .'<form method="post" style="display:inline-block;margin-right:8px">'.$this->tokenField($token)
                .'<input type="hidden" name="tcmig_action" value="confirm_composer_fix">'
                .'<input type="hidden" name="job" value="'.$jid.'">'
                .'<button type="submit" class="tcmig-btn">'.$this->t('composer_fix_btn').'</button></form>'
                .'<form method="post" style="display:inline-block">'.$this->tokenField($token)
                .'<input type="hidden" name="tcmig_action" value="confirm_composer">'
                .'<input type="hidden" name="job" value="'.$jid.'">'
                .'<button type="submit" class="tcmig-btn tcmig-btn--ghost">'.$this->t('composer_btn').'</button></form>';
        }

        // Passphrase pause (verify_package) takes precedence — it blocks before compat/urlmap.
        if (!empty($job->meta['needsPassphrase'])) {
            return '<hr class="tcmig-sep">'
                .'<div class="tcmig-alert tcmig-alert--err">'.$this->t('paused_pass_msg').'</div>'
                .'<form method="post" data-turbo="false">'.$this->tokenField($token)
                .'<input type="hidden" name="tcmig_action" value="confirm_passphrase">'
                .'<input type="hidden" name="job" value="'.$jid.'">'
                .'<div class="tcmig-field"><label>'.$this->t('export_pass').'</label>'
                .'<input type="password" name="passphrase" class="tcmig-input" autocomplete="new-password"></div>'
                .'<button type="submit" class="tcmig-btn">'.$this->t('paused_pass_btn').'</button></form>';
        }

        $warnings = $job->meta['compatWarnings'] ?? [];

        if (!empty($warnings) && empty($job->meta['compatConfirmed'])) {
            $list = implode('<br>', array_map([$this, 'esc'], $warnings));

            return '<hr class="tcmig-sep">'
                .'<div class="tcmig-alert tcmig-alert--err">'.$this->t('compat_warn').'<br>'.$list.'</div>'
                .'<form method="post">'.$this->tokenField($token)
                .'<input type="hidden" name="tcmig_action" value="confirm_compat">'
                .'<input type="hidden" name="job" value="'.$jid.'">'
                .'<button type="submit" class="tcmig-btn">'.$this->t('compat_btn').'</button></form>';
        }

        // Otherwise assume a URL-map confirmation is required.
        return '<hr class="tcmig-sep">'
            .'<p class="tcmig-state">'.$this->t('urlmap_msg').'</p>'
            .'<form method="post">'.$this->tokenField($token)
            .'<input type="hidden" name="tcmig_action" value="confirm_urlmap">'
            .'<input type="hidden" name="job" value="'.$jid.'">'
            .'<div class="tcmig-field"><label>'.$this->t('urlmap_old').'</label><input type="text" name="url_from" class="tcmig-input" placeholder="old-domain.tld"></div>'
            .'<div class="tcmig-field"><label>'.$this->t('urlmap_new').'</label><input type="text" name="url_to" class="tcmig-input" placeholder="new-domain.tld"></div>'
            .'<button type="submit" class="tcmig-btn">'.$this->t('urlmap_btn').'</button></form>';
    }

    private function exportCard(string $tf): string
    {
        return '<div class="tcmig-card">'
            .'<div class="tcmig-card-h">'.$this->t('export_h').'</div>'
            .'<div class="tcmig-card-d">'.$this->t('export_d').'</div>'
            .'<form method="post">'.$tf
            .'<input type="hidden" name="tcmig_action" value="start_export">'
            .'<div class="tcmig-field"><label>'.$this->t('export_pass').' <span class="tcmig-muted">'.$this->t('export_pass_hint').'</span></label>'
            .'<input type="password" name="passphrase" class="tcmig-input" autocomplete="new-password"></div>'
            .'<button type="submit" class="tcmig-btn">'.$this->t('export_btn').'</button></form></div>';
    }

    private function receiveCard(string $tf): string
    {
        return '<div class="tcmig-card">'
            .'<div class="tcmig-card-h">'.$this->t('recv_h').' <span class="tcmig-muted">'.$this->t('recv_mode').'</span></div>'
            .'<div class="tcmig-card-d">'.$this->t('recv_d').'</div>'
            .'<form method="post" data-turbo="false">'.$tf
            .'<input type="hidden" name="tcmig_action" value="mint">'
            .'<button type="submit" class="tcmig-btn">'.$this->t('mint_btn').'</button></form></div>';
    }

    private function sendCard(string $tf): string
    {
        return '<div class="tcmig-card">'
            .'<div class="tcmig-card-h">'.$this->t('send_h').' <span class="tcmig-muted">'.$this->t('send_mode').'</span></div>'
            .'<div class="tcmig-card-d">'.$this->t('send_d').'</div>'
            .'<form method="post">'.$tf
            .'<input type="hidden" name="tcmig_action" value="start_push">'
            .'<div class="tcmig-field"><label>'.$this->t('dest_url').'</label><input type="text" name="remoteUrl" class="tcmig-input" placeholder="https://new-host.tld"></div>'
            .'<div class="tcmig-field"><label>'.$this->t('pairing').'</label><input type="text" name="pairing" class="tcmig-input" placeholder="session.key"></div>'
            .'<div class="tcmig-field"><label>'.$this->t('pass').' <span class="tcmig-muted">'.$this->t('send_pass_hint').'</span></label>'
            .'<input type="password" name="passphrase" class="tcmig-input" autocomplete="new-password"></div>'
            .'<button type="submit" class="tcmig-btn">'.$this->t('push_btn').'</button></form></div>';
    }

    /**
     * Recovery-panel tab: a link into the standalone web-root panel (operator token prefilled in
     * the URL — acceptable since this view is already behind the admin-gated backend) plus the
     * raw token to copy when the backend is down and the URL can't be reached.
     */
    private function recoveryCard(string $recoveryUrl, string $opToken): string
    {
        return '<div class="tcmig-card">'
            .'<div class="tcmig-card-h">'.$this->t('recovery_h').'</div>'
            .'<div class="tcmig-card-d">'.$this->t('recovery_d').'</div>'
            .'<p><a href="'.$this->esc($recoveryUrl).'" target="_blank" rel="noopener" class="tcmig-btn" style="text-decoration:none">'.$this->t('recovery_link').'</a></p>'
            .'<div class="tcmig-field" style="margin-top:14px"><label>'.$this->t('recovery_token').' <span class="tcmig-muted">'.$this->t('recovery_token_loc').'</span></label>'
            .'<code style="display:block;padding:9px 11px">'.$this->esc($opToken).'</code></div></div>';
    }

    /**
     * Single-file import plus the split-volume flow (upload .001, .002, … sequentially, then
     * assemble). The split path keeps every upload under the host's post_max_size/upload limit.
     */
    private function importForms(string $tf, string $isess): string
    {
        $single = '<div class="tcmig-card">'
            .'<div class="tcmig-card-h">'.$this->t('import_h').'</div>'
            .'<div class="tcmig-card-d">'.$this->t('import_d').'</div>'
            .'<form method="post" enctype="multipart/form-data" data-turbo="false">'.$tf
            .'<input type="hidden" name="tcmig_action" value="start_import">'
            .'<div class="tcmig-field"><label>'.$this->t('import_file').' <span class="tcmig-muted">'.$this->t('import_file_hint').'</span></label>'
            .'<input type="file" name="package" class="tcmig-input" accept=".tcmig"></div>'
            .'<div class="tcmig-field"><label>'.$this->t('pass').' <span class="tcmig-muted">'.$this->t('pass_hint_signed').'</span></label>'
            .'<input type="password" name="passphrase" class="tcmig-input" autocomplete="new-password"></div>'
            .'<button type="submit" class="tcmig-btn">'.$this->t('import_btn').'</button></form>';

        // Split import: pick ALL parts at once; the browser uploads them SEQUENTIALLY (one small
        // request each → never hits post_max_size or the execution-time wall), then calls assemble
        // to reassemble + enqueue the import. A fresh session id ties the parts together.
        $router = System::getContainer()->get('router');
        $sess = bin2hex(random_bytes(6));
        $uploadUrl = $router->generate('tcmig_upload_part', ['isess' => $sess]);
        $assembleUrl = $router->generate('tcmig_assemble', ['isess' => $sess]);

        $flags = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES;
        $upJson = json_encode($uploadUrl, $flags);
        $asJson = json_encode($assembleUrl, $flags);
        $tokJson = json_encode($this->csrfToken(), $flags);
        $lJson = json_encode([
            'no_files' => $this->t('js_no_files'),
            'uploading' => $this->t('js_uploading'),
            'upload_err' => $this->t('js_upload_err'),
            'upload_fail' => $this->t('js_upload_fail'),
            'assembling' => $this->t('js_assembling'),
            'assemble_err' => $this->t('js_assemble_err'),
            'assemble_fail' => $this->t('js_assemble_fail'),
        ], $flags);

        $single .= '<hr class="tcmig-sep">'
            .'<div class="tcmig-card-d">'.$this->t('split_q').'</div>'
            .'<div class="tcmig-field"><label>'.$this->t('split_parts_label').'</label>'
            .'<input type="file" id="tcmig-parts-in" class="tcmig-input" multiple></div>'
            .'<div class="tcmig-field"><label>'.$this->t('pass').' <span class="tcmig-muted">'.$this->t('pass_hint_signed').'</span></label>'
            .'<input type="password" id="tcmig-parts-pass" class="tcmig-input" autocomplete="new-password"></div>'
            .'<button type="button" id="tcmig-parts-btn" class="tcmig-btn">'.$this->t('split_upload_btn').'</button>'
            .'<div id="tcmig-parts-prog" class="tcmig-state" style="margin-top:10px"></div></div>';

        $single .= <<<JS
<script>
(function(){
  var input=document.getElementById('tcmig-parts-in'), btn=document.getElementById('tcmig-parts-btn'),
      prog=document.getElementById('tcmig-parts-prog'), passEl=document.getElementById('tcmig-parts-pass');
  if(!btn) return;
  var UP={$upJson}, AS={$asJson}, TOKEN={$tokJson}, L={$lJson};
  btn.addEventListener('click', function(){
    var files=Array.prototype.slice.call(input.files||[]);
    if(!files.length){ prog.textContent=L.no_files; return; }
    files.sort(function(a,b){ return a.name<b.name?-1:(a.name>b.name?1:0); });
    btn.disabled=true;
    var i=0;
    function up(){
      if(i>=files.length){ assemble(); return; }
      prog.textContent=L.uploading+' '+(i+1)+'/'+files.length+' ('+files[i].name+')…';
      var fd=new FormData(); fd.append('REQUEST_TOKEN', TOKEN); fd.append('index', i); fd.append('part', files[i]);
      fetch(UP, {method:'POST', credentials:'same-origin', body:fd}).then(function(r){return r.json();}).then(function(d){
        if(!d.ok){ prog.textContent=L.upload_err+' '+(i+1)+': '+(d.error||'?'); btn.disabled=false; return; }
        i++; up();
      }).catch(function(){ prog.textContent=L.upload_fail; btn.disabled=false; });
    }
    function assemble(){
      prog.textContent=L.assembling;
      var fd=new FormData(); fd.append('REQUEST_TOKEN', TOKEN); var p=(passEl&&passEl.value)||''; if(p) fd.append('passphrase', p);
      fetch(AS, {method:'POST', credentials:'same-origin', body:fd}).then(function(r){return r.json();}).then(function(d){
        if(!d.ok){ prog.textContent=L.assemble_err+' '+(d.error||'?'); btn.disabled=false; return; }
        window.location='contao?do=tc_migrator&job='+encodeURIComponent(d.jobId);
      }).catch(function(){ prog.textContent=L.assemble_fail; btn.disabled=false; });
    }
    up();
  });
})();
</script>
JS;

        return $single;
    }

    private function jobList(string $self): string
    {
        $jobs = [];

        foreach ($this->store->listIds() as $id) {
            $job = $this->store->load($id);

            if (null !== $job) {
                $jobs[] = $job;
            }
        }

        usort($jobs, static fn ($a, $b): int => $b->createdAt <=> $a->createdAt);
        $jobs = \array_slice($jobs, 0, 12);

        if ([] === $jobs) {
            return '<div class="tcmig-jobs"><div class="tcmig-jobs-h">'.$this->t('jobs_h').'</div><div class="tcmig-empty">'.$this->t('no_jobs').'</div></div>';
        }

        $rows = '';
        $router = System::getContainer()->get('router');
        $lang = str_starts_with((string) ($GLOBALS['TL_LANGUAGE'] ?? 'en'), 'de') ? 'de' : 'en';

        foreach ($jobs as $job) {
            $link = $self.'&job='.$this->esc($job->id);
            $dl = '';

            if (!empty($job->meta['download']['ready'])) {
                $eUrl = $this->esc($router->generate('tcmig_download', ['id' => $job->id]));
                $dl = ' · <a href="'.$eUrl.'">'.$this->t('dl').'</a>'
                    .' · <button type="button" class="tcmig-parts tcmig-linkbtn" data-url="'.$eUrl.'">'.$this->t('split_parts').'</button>'
                    .'<span class="tcmig-parts-out"></span>';
            }

            $stateRaw = $this->esc($job->state->value);
            $stateLabel = $this->esc(\Vtinnovations\Migrator\Support\Messages::stateLabel($lang, $job->state->value));

            if ($job->state->isTerminal()) {
                $aUrl = $this->esc($router->generate('tcmig_delete', ['id' => $job->id]));
                $action = '<button type="button" class="tcmig-jobact tcmig-linkbtn" data-url="'.$aUrl.'" data-kind="delete">'.$this->t('delete_btn').'</button>';
            } else {
                $aUrl = $this->esc($router->generate('tcmig_cancel', ['id' => $job->id]));
                $action = '<button type="button" class="tcmig-jobact tcmig-linkbtn" data-url="'.$aUrl.'" data-kind="cancel">'.$this->t('cancel_btn').'</button>';
            }

            $rows .= '<tr><td><a href="'.$this->esc($link).'">'.$this->esc(substr($job->id, 0, 8)).'</a></td>'
                .'<td>'.$this->esc($job->type->value).'</td>'
                .'<td><span class="tcmig-badge st-'.$stateRaw.'">'.$stateLabel.'</span></td>'
                .'<td>'.round($job->progress() * 100).'%'.$dl.'</td>'
                .'<td>'.$action.'</td></tr>';
        }

        $flags = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES;
        $tokJson = json_encode($this->csrfToken(), $flags);
        $cxlJson = json_encode($this->t('cancel_confirm'), $flags);
        $delJson = json_encode($this->t('delete_confirm'), $flags);

        // Lazily list split-part links on demand; wire per-row cancel/delete (CSRF-signed fetch).
        $js = <<<JS
<script>
document.querySelectorAll('.tcmig-parts').forEach(function(b){
  b.addEventListener('click', function(){
    var out = b.nextElementSibling, base = b.getAttribute('data-url');
    out.textContent = ' …';
    fetch(base+'?meta=1', {credentials:'same-origin'}).then(function(r){return r.json();}).then(function(d){
      if(!d.parts || d.parts<=1){ out.textContent = ' (1 Datei — Download nutzen)'; return; }
      var h = ' ';
      for(var i=0;i<d.parts;i++){ h += '<a href="'+base+'?part='+i+'">Teil '+(i+1)+'</a> '; }
      out.innerHTML = h;
      b.style.display = 'none';
    }).catch(function(){ out.textContent = ' (Fehler)'; });
  });
});
(function(){
  var TOKEN={$tokJson}, CXL={$cxlJson}, DEL={$delJson};
  document.querySelectorAll('.tcmig-jobact').forEach(function(b){
    b.addEventListener('click', function(){
      var kind=b.getAttribute('data-kind'), url=b.getAttribute('data-url');
      if(!confirm(kind==='delete'?DEL:CXL)) return;
      b.disabled=true;
      var fd=new FormData(); fd.append('REQUEST_TOKEN', TOKEN);
      fetch(url, {method:'POST', credentials:'same-origin', body:fd}).then(function(r){return r.json();}).then(function(d){
        if(d && d.ok){ setTimeout(function(){location.reload();},400); }
        else { b.disabled=false; alert((d&&d.error)||'Fehler'); }
      }).catch(function(){ b.disabled=false; });
    });
  });
})();
</script>
JS;

        return '<div class="tcmig-jobs"><div class="tcmig-jobs-h">'.$this->t('jobs_h').'</div>'
            .'<table class="tcmig-tbl"><thead><tr><th>'.$this->t('col_id').'</th><th>'.$this->t('col_type').'</th><th>'.$this->t('col_state').'</th><th>'.$this->t('col_progress').'</th><th>'.$this->t('col_action').'</th></tr></thead>'
            .'<tbody>'.$rows.'</tbody></table></div>'.$js;
    }

    /**
     * Render one composer-audit warning in the active language. New warnings are structured
     * ({code, args}); legacy jobs stored pre-rendered English strings — those are shown as-is.
     *
     * @param array{code?: string, args?: array<string, string>}|string $w
     */
    private function composerWarnText(array|string $w): string
    {
        if (\is_string($w)) {
            return $this->esc($w);
        }

        $code = (string) ($w['code'] ?? '');
        $a = (array) ($w['args'] ?? []);
        $tpl = $this->t('cw_'.$code);
        $e = fn (string $k): string => $this->esc((string) ($a[$k] ?? ''));

        return match ($code) {
            'repo_path', 'repo_custom' => sprintf($tpl, $e('label'), $e('url')),
            'path_no_version' => sprintf($tpl, $e('name'), $e('url')),
            'repo_vcs' => sprintf($tpl, $e('label'), $e('type'), $e('url')),
            'dev_constraint' => sprintf($tpl, $e('pkg'), $e('constraint')),
            'min_stability' => sprintf($tpl, $e('value')),
            default => $tpl,
        };
    }

    private function box(string $type, string $text, string $extra = ''): string
    {
        $cls = 'error' === $type ? 'tcmig-alert--err' : 'tcmig-alert--ok';

        return '<div class="tcmig-alert '.$cls.'">'.$this->esc($text).($extra ? '<div style="margin-top:8px">'.$extra.'</div>' : '').'</div>';
    }

    private function tokenField(string $token): string
    {
        return '<input type="hidden" name="REQUEST_TOKEN" value="'.$this->esc($token).'">';
    }

    private function csrfToken(): string
    {
        $container = System::getContainer();
        $name = (string) $container->getParameter('contao.csrf_token_name');

        return $container->get('contao.csrf.token_manager')->getToken($name)->getValue();
    }

    private function redirectToJob(string $jobId): void
    {
        Controller::redirect('contao?do=tc_migrator&job='.$jobId);
    }

    private function redirectToImport(string $session): void
    {
        Controller::redirect('contao?do=tc_migrator&isess='.$session);
    }

    private function nullIfEmpty(mixed $value): ?string
    {
        $value = \is_string($value) ? trim($value) : '';

        return '' === $value ? null : $value;
    }

    public function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
