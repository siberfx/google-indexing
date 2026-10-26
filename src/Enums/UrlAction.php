<?php

declare(strict_types=1);

namespace Siberfx\GoogleIndexing\Enums;

enum UrlAction: string
{
    case Updated = 'URL_UPDATED';
    case Deleted = 'URL_DELETED';
}
