<?php

declare(strict_types=1);

namespace Woyra\Resources;

use Woyra\Client;

/** @internal */
abstract class Resource
{
    public function __construct(protected readonly Client $client)
    {
    }

    protected static function id(string $id): string
    {
        return rawurlencode($id);
    }
}
