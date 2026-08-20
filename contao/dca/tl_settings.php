<?php

declare(strict_types=1);

use Contao\CoreBundle\DataContainer\PaletteManipulator;
use Vtinnovations\Migrator\BackendModule\EntitlementSummary;

/*
 * The ONLY administrator-facing licensing surface for this global (instance-wide) package:
 * Contao → Settings, "V-T.ONE Licence management" legend (shared with every other V-T.ONE
 * package), "Migrator" field heading.
 *
 * There is deliberately no second licence screen anywhere else — the backend module shows a
 * read-only pointer to this section and never collects a key, and no package settings panel,
 * standalone module or root-page section exists for the migrator licence.
 *
 * The section is ONE render-only field (input_field_callback short-circuits DataContainer::row(),
 * so Contao builds no widget for it and stores nothing): EntitlementSummary renders the current
 * state, the key input and three named `<button type="submit">` action controls, all inside
 * Contao's own single `<form id="tl_settings">`. None of those buttons carries `formaction` — each
 * one submits to the form's own default action, exactly like Contao's own Save / Save-and-close
 * buttons. EntitlementFields::onSubmit() (a config.onsubmit callback) reads which button's name is
 * present in the submission and dispatches accordingly. The bundle therefore owns NO backend route
 * for licence management and depends on no formaction override, nested form or JavaScript.
 *
 * EntitlementFields::onSubmit() is NOT registered here: its #[AsCallback(target: 'config.onsubmit')]
 * attribute already merges it into config.onsubmit_callback via Contao's own DI compiler pass (the
 * same mechanism that wired the field-level callbacks before it). Registering it again here would
 * run the dispatcher twice per submission — once per registration — double-firing every action.
 */

if (!isset($GLOBALS['TL_DCA']['tl_settings']['palettes']['default'])) {
    return;
}

$GLOBALS['TL_DCA']['tl_settings']['fields']['tcmig_license_status'] = [
    'label' => &$GLOBALS['TL_LANG']['tl_settings']['tcmig_license_status'],
    'input_field_callback' => [EntitlementSummary::class, 'render'],
    'eval' => ['tl_class' => 'clr'],
];

PaletteManipulator::create()
    ->addLegend('vtone_licence_legend', null, PaletteManipulator::POSITION_PREPEND)
    ->addField('tcmig_license_status', 'vtone_licence_legend', PaletteManipulator::POSITION_APPEND)
    ->applyToPalette('default', 'tl_settings')
;
