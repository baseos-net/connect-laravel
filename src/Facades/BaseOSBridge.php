<?php

namespace BaseOS\Connect\Facades;

use BaseOS\Connect\BridgeManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static void tenantUsing(callable $enter, ?callable $leave = null)
 * @method static array emitChanged(\BaseOS\Connect\Data\BridgeConnection|string $connection, string $remoteId, ?string $contentId = null, array $changed = [], ?string $remoteHash = null, ?array $actor = null)
 * @method static array emitDeleted(\BaseOS\Connect\Data\BridgeConnection|string $connection, string $remoteId, ?string $contentId = null)
 * @method static \BaseOS\Connect\Data\BridgeConnection|null connection(string $connectionId)
 * @method static \BaseOS\Connect\Data\BridgeConnection|null connectionForTenant(?string $tenant)
 * @method static array hello(\BaseOS\Connect\Data\BridgeConnection $connection, bool $withSchema = true)
 *
 * @see BridgeManager
 */
class BaseOSBridge extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return BridgeManager::class;
    }
}
