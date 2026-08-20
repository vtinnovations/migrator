<?php

/*
 * Contao Migrator
 *
 * Package: vtinnovations/migrator
 * Copyright: V&T Innovations Team
 * Licence: LGPL-3.0-or-later
 * Website: https://www.v-t.one
 */

declare(strict_types=1);

use Vtinnovations\Migrator\BackendModule\MigratorModule;

/*
 * Backend module. Rendered through the legacy BE_MOD callback (Contao instantiates the class
 * with no arguments and calls generate()), so MigratorModule pulls its collaborators from the
 * container itself — they are declared public in services.yaml for exactly this reason.
 */
$GLOBALS['BE_MOD']['migrator']['tc_migrator'] = [
    'callback' => MigratorModule::class,
];
