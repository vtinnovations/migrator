<?php

declare(strict_types=1);

namespace Vtinnovations\Migrator\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Contao\Message;
use Contao\System;
use Symfony\Component\HttpFoundation\RequestStack;
use Vtinnovations\Migrator\Config\EntitlementExchange;
use Vtinnovations\Migrator\Config\EntitlementState;
use Vtinnovations\Migrator\Transfer\ExchangeException;

/**
 * The three authoritative state transitions for the global entitlement, dispatched from ONE
 * `config.onsubmit` callback on tl_settings — Contao calls this once per Settings-page submission,
 * after every ordinary field has already validated and saved.
 *
 * Why a submit dispatcher and not three DCA widget fields: the buttons rendered by
 * {@see \Vtinnovations\Migrator\BackendModule\EntitlementSummary} are plain named
 * `<button type="submit">` controls with no `formaction` — the exact mechanism Contao's own
 * toolbar uses to tell "Save" from "Save and close" apart (both are submit buttons in the SAME
 * form; only the clicked one's name/value pair is present in the POST body). Reading "which name is
 * present" is the entire action signal; nothing here depends on the browser honouring a submission
 * override, which is what failed in production for the previous formaction-based panel.
 *
 * Failures are reported through Contao's own `Message` class, the same session-backed mechanism
 * Contao itself uses for a failed save — never a raw exception, and never packet material. A
 * failure never erases a previously valid licence: that guarantee lives in EntitlementExchange,
 * which only persists after a complete signed package has verified end to end.
 */
final class EntitlementFields
{
    /** @var array<string, string> UI strings for the active backend language. */
    private array $labels = [];

    public function __construct(
        private readonly EntitlementExchange $exchange,
        private readonly RequestStack $requestStack,
    ) {
    }

    #[AsCallback(table: 'tl_settings', target: 'config.onsubmit')]
    public function onSubmit(DataContainer $dc): void
    {
        $request = $this->requestStack->getCurrentRequest();

        if (null === $request) {
            return;
        }

        // Precedence only matters for a deliberately forged request carrying more than one name —
        // a real click can only ever produce exactly one, since a browser submits just the clicked
        // button's name/value pair. Destructive-first: if a remove is present at all, honour it.
        try {
            if ($request->request->has('tcmig_license_remove')) {
                $this->remove();
                Message::addConfirmation($this->t('flash_removed'));
            } elseif ($request->request->has('tcmig_license_refresh')) {
                $this->refresh();
                Message::addConfirmation($this->t('flash_updated'));
            } elseif ($request->request->has('tcmig_license_activate')) {
                $key = trim((string) $request->request->get('tcmig_license_key', ''));
                $this->activate($key);
                Message::addConfirmation($this->t('flash_activated'));
            }
        } catch (ExchangeException $e) {
            Message::addError($this->reason($e));
        } catch (\Throwable) {
            Message::addError($this->t('err_generic'));
        }
    }

    /**
     * Verify and activate a newly entered key. Pure: throws on refusal, never touches Message,
     * DataContainer or the session, so it is directly unit-testable.
     *
     * @throws ExchangeException on empty key or a refused/unverifiable package
     * @throws \Exception        when the exchange succeeds cryptographically but the resulting
     *                           evaluation still withholds entitlement (not yet valid, model
     *                           incompatible)
     */
    public function activate(string $key): EntitlementState
    {
        if ('' === $key) {
            throw new ExchangeException('empty_key');
        }

        $state = $this->exchange->activate($key, $this->currentHost());

        if (!$state->isLicensed()) {
            throw new \Exception($this->t('flash_not_activated'));
        }

        return $state;
    }

    /**
     * Refresh with the STORED key and the current version (action=refresh). Never accepts a key
     * from the caller — the request is built entirely from the authenticated local record.
     *
     * @throws ExchangeException when no state is stored or the refresh is refused
     */
    public function refresh(): void
    {
        $this->exchange->refresh($this->currentHost());
    }

    /**
     * Authorised local removal: drops the authoritative state under lock so the instance reverts to
     * the unlicensed default immediately.
     */
    public function remove(): void
    {
        $this->exchange->remove();
    }

    /**
     * The host of the current backend request. It is only ever a HINT: HostInventory accepts it
     * solely when it is already a member of the trusted configured inventory, and otherwise falls
     * back to the primary configured host — so a spoofed Host header cannot choose the identity
     * this installation activates under.
     */
    private function currentHost(): ?string
    {
        return $this->requestStack->getCurrentRequest()?->getHost();
    }

    /**
     * A server rejection carries a customer-facing message (no packet material); anything else maps
     * to a generic line so internal verification categories never leak to the admin screen.
     */
    private function reason(ExchangeException $e): string
    {
        if (str_starts_with($e->category(), 'server_')) {
            return $e->getMessage();
        }

        return $this->t(match ($e->category()) {
            'no_configured_domain' => 'err_no_domain',
            'empty_key' => 'err_empty_key',
            'no_state', 'no_stored_key' => 'err_no_state',
            default => 'err_generic',
        });
    }

    /** Administrator-visible strings come from contao/languages/<lang>/tcmig.php, never from here. */
    private function t(string $key): string
    {
        if ([] === $this->labels) {
            try {
                System::loadLanguageFile('tcmig');
                $strings = $GLOBALS['TL_LANG']['tcmig'] ?? [];
                $this->labels = \is_array($strings) ? $strings : [];
            } catch (\Throwable) {
                // Leave the table empty; t() then returns the key rather than failing the save.
            }
        }

        return $this->labels[$key] ?? $key;
    }
}
