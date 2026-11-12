<?php

declare(strict_types=1);

namespace App\Http\Inertia;

use Closure;
use Inertia\OnceProp;

/**
 * A once prop whose key is worked out only when Inertia asks for it — when a page is drawn — rather than
 * when the middleware shares it, which every request does, a JSON call too. For a key that costs a query.
 */
final class LazilyKeyedOnceProp extends OnceProp
{
    /** @param Closure(): string $keyOf */
    public function __construct(callable $callback, private readonly Closure $keyOf)
    {
        parent::__construct($callback);
    }

    public function getKey(): string
    {
        return $this->key ??= ($this->keyOf)();
    }
}
