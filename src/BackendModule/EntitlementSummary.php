<?php

declare(strict_types=1);

namespace Vtinnovations\Migrator\BackendModule;

use Contao\System;
use Vtinnovations\Migrator\Config\EntitlementEvaluator;
use Vtinnovations\Migrator\Config\EntitlementState;
use Vtinnovations\Migrator\Config\HostInventory;

/**
 * The sole global licence surface (Contao → Settings, "V-T.ONE Licence management" legend,
 * "Migrator" field heading): current state, the key field, and the three action buttons, all in
 * one rendered block.
 *
 * Every control here is a plain, named `<button type="submit">` inside Contao's own single
 * `<form id="tl_settings">` — the exact mechanism Contao's own toolbar uses to distinguish "Save"
 * from "Save and close" (`Input::post('saveNclose') !== null`, see DC_File::edit()). None of them
 * carries `formaction`/`formmethod`: every click submits to the form's own default action, so there
 * is no cross-target redirection for a browser or Turbo Drive to get wrong. That was the failure
 * class the previous panel hit — its buttons overrode the destination via `formaction`, and when
 * that override lost to the enclosing form's own action, the click silently posted somewhere else
 * entirely. {@see \Vtinnovations\Migrator\EventListener\DataContainer\EntitlementFields} reads
 * which button's name is present in the submitted request and dispatches accordingly.
 *
 * The state is resolved server-side on every render, so it can never be a stale copy or a permanent
 * client-side "loading" placeholder. The evaluation re-runs the full crypto pipeline over the stored
 * bytes, so a hand-edited state file shows as invalid rather than licensed. The key input never
 * carries a value: it is emitted as a raw HTML fragment (not a Contao widget bound to stored state),
 * so there is no code path that could echo an authenticated key back into the page.
 *
 * Contao's DCA callback layer instantiates this class with `new` and no constructor arguments
 * (System::importStatic), so collaborators are fetched from the container inside build() — the
 * same pattern MigratorModule uses — rather than via constructor injection.
 */
final class EntitlementSummary
{
    /** @var array<string, string> UI strings for the active backend language. */
    private array $labels = [];

    /**
     * DCA input_field_callback: Contao calls this with the DataContainer (and, depending on the
     * minor version, an extra label argument) and renders the returned HTML in place of a widget.
     * Wrapped so any failure degrades to a visible diagnostic instead of a fatal Settings page.
     */
    public function render(/* DataContainer */ $dc = null, string $xlabel = ''): string
    {
        try {
            return $this->build();
        } catch (\Throwable) {
            // Never leak an internal message to the screen; the reason belongs in the diagnostics,
            // not in the administrator's face.
            return '<div class="widget"><p class="tl_error">'.$this->esc($this->t('panel_unavailable')).'</p></div>';
        }
    }

    private function build(): string
    {
        $container = System::getContainer();
        $evaluator = $container->get(EntitlementEvaluator::class);
        \assert($evaluator instanceof EntitlementEvaluator);

        $state = $evaluator->evaluate();

        $html = '<div class="widget vtmig-license" style="max-width:640px">';
        $html .= '<h3>' . $this->esc($GLOBALS['TL_LANG']['tl_settings']['tcmig_license_status'][0] ?? 'Migrator') . '</h3>';
        $html .= '<div style="padding:12px 15px;border:1px solid var(--content-border);border-radius:4px;background:var(--content-bg)">';
        $html .= $this->statusLine($state);
        $html .= $this->hostHint($container, $state);
        $html .= $this->controls();
        $html .= '</div></div>';

        return $html;
    }

    /**
     * One line per distinguishable model state. The Trial/Free-and-Pro model must be able to show
     * unlicensed, Trial active, Free active, Pro active, Pro expired WITH the signed Free fallback,
     * and expired/invalid without fallback — a single licensed/unlicensed boolean would collapse
     * states an administrator has to tell apart to know what to do next.
     */
    private function statusLine(EntitlementState $state): string
    {
        [$colour, $label] = match ($state->status) {
            EntitlementState::STATUS_PRO => ['var(--green)', $this->t('panel_status_pro')],
            EntitlementState::STATUS_TRIAL => ['var(--green)', $this->t('panel_status_trial')],
            EntitlementState::STATUS_FREE => ['var(--green)', $this->t('panel_status_free')],
            EntitlementState::STATUS_PRO_FREE_FALLBACK => ['var(--orange)', $this->t('panel_status_pro_fallback')],
            EntitlementState::STATUS_EXPIRED => ['var(--red)', $this->t('panel_status_expired')],
            EntitlementState::STATUS_INVALID => ['var(--red)', $this->t('panel_status_invalid')],
            default => ['var(--red)', $this->t('panel_status_unlicensed')],
        };

        $detail = $state->isLicensed()
            ? $this->detailLine($state)
            : $this->esc($this->t('panel_hint'));

        return sprintf(
            '<div style="font-size:15px;font-weight:bold;color:%s;margin-bottom:4px">%s</div>'
            .'<div class="tl_gray" style="font-size:12px">%s</div>',
            $colour,
            $this->esc($label),
            $detail,
        );
    }

    /**
     * The five facts every V-T.ONE section on this screen shows, and no more: which licence (masked
     * — the full key is never read here), which package, since when, until when, last confirmed
     * when. The bound domain and the signed host set were dropped; they are record internals nobody
     * acts on from this screen, and while something IS wrong the configured hosts are shown by
     * hostHint() instead, where they are the fix rather than trivia.
     */
    private function detailLine(EntitlementState $state): string
    {
        $parts = [
            $this->t('panel_key').': '.($state->maskedKey ?: '—'),
            $this->t('panel_package').': '.strtoupper($state->package),
            $this->t('panel_valid_from').': '.$this->moment($state->startsAt),
            $this->t('panel_valid_until').': '.($state->lifetime
                ? $this->t('panel_lifetime')
                : $this->moment($state->expiresAt)),
            $this->t('panel_last_verified').': '.$this->moment($state->verifiedAt),
        ];

        return $this->esc(implode(' · ', $parts));
    }

    private function moment(?int $timestamp): string
    {
        return null !== $timestamp && $timestamp > 0 ? date('Y-m-d H:i', $timestamp) : '—';
    }

    /**
     * Activation binds to an exact configured host, so an installation with no root-page domain
     * can never activate. Saying so up front turns an otherwise opaque refusal into an actionable
     * one. Only trusted configured hosts are shown — never the request's own Host header, and only
     * while the installation is not licensed: once it is, the host it bound to is settled and the
     * line would only pad the summary.
     */
    private function hostHint(mixed $container, EntitlementState $state): string
    {
        try {
            $inventory = $container->get(HostInventory::class)->inventory();
        } catch (\Throwable) {
            return '';
        }

        if ([] === $inventory) {
            return sprintf(
                '<div class="tl_error" style="margin-top:8px;font-size:12px">%s</div>',
                $this->esc($this->t('err_no_domain')),
            );
        }

        if ($state->isLicensed()) {
            return '';
        }

        return sprintf(
            '<div class="tl_gray" style="margin-top:8px;font-size:12px">%s: %s</div>',
            $this->esc($this->t('panel_configured_hosts')),
            $this->esc(implode(', ', $inventory)),
        );
    }

    /**
     * The key input plus the three action buttons. Every button carries a distinct `name`, no
     * `value` beyond the fixed marker `"1"`, and no `formaction`/`formmethod`/`onclick` handler
     * except a plain `confirm()` gate on the destructive one — none of that touches where or how
     * the form submits, so it cannot reproduce the previous defect.
     */
    private function controls(): string
    {
        $html = sprintf(
            '<label style="display:block;margin:12px 0 4px"><strong>%s</strong></label>'
            .'<input type="text" name="tcmig_license_key" value="" autocomplete="off" spellcheck="false" '
            .'style="width:100%%;padding:6px;box-sizing:border-box" placeholder="%s">',
            $this->esc($this->t('panel_key_label')),
            $this->esc($this->t('panel_key_placeholder')),
        );

        $html .= '<div style="margin-top:8px;display:flex;gap:8px;flex-wrap:wrap">';
        $html .= $this->button('tcmig_license_activate', $this->t('panel_activate'));
        $html .= $this->button('tcmig_license_refresh', $this->t('panel_refresh'));
        $html .= $this->button(
            'tcmig_license_remove',
            $this->t('panel_remove'),
            'onclick="return confirm(\''.$this->escJs($this->t('panel_remove_confirm')).'\')"',
        );
        $html .= '</div>';

        return $html;
    }

    private function button(string $name, string $label, string $extraAttrs = ''): string
    {
        return sprintf(
            '<button type="submit" name="%s" value="1" class="tl_submit"%s>%s</button>',
            $this->esc($name),
            '' !== $extraAttrs ? ' '.$extraAttrs : '',
            $this->esc($label),
        );
    }

    /**
     * Contao resolves contao/languages/<lang>/tcmig.php for the backend user's language and falls
     * back to English itself, so this class carries no strings of its own. Loaded lazily so the
     * failure path in render() can still translate its message.
     */
    private function t(string $key): string
    {
        if ([] === $this->labels) {
            try {
                System::loadLanguageFile('tcmig');
                $strings = $GLOBALS['TL_LANG']['tcmig'] ?? [];
                $this->labels = \is_array($strings) ? $strings : [];
            } catch (\Throwable) {
                // Leave the table empty; t() then returns the key rather than failing the page.
            }
        }

        return $this->labels[$key] ?? $key;
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    private function escJs(string $value): string
    {
        return str_replace(["'", "\n", "\r"], ["\\'", ' ', ''], $value);
    }
}
