<?php

declare(strict_types=1);

namespace Kern\Legislation\Legislatie;

use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * The act types a law can be read as — `operations.legislation.document_types`, the types the ingest task
 * offers — as acts are filed ({@see PortalAct::key()}). What a portal search may be narrowed to, and what
 * a result says it can be read as.
 */
final readonly class ImportableTypes
{
    public function __construct(private ConfigRepository $config)
    {
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_values(array_map(
            static fn (mixed $type): string => PortalAct::key(is_string($type) ? $type : ''),
            $this->config->array('operations.legislation.document_types'),
        ));
    }

    public function includes(PortalAct $act): bool
    {
        return in_array($act->typeKey(), $this->keys(), true);
    }
}
