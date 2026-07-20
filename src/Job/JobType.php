<?php

declare(strict_types=1);

namespace Vtinnovations\Migrator\Job;

enum JobType: string
{
    case Export = 'export';
    case Import = 'import';
    case Receive = 'receive';
}
