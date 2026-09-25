<?php

declare(strict_types=1);

namespace Kern\Platform\ObjectStore;

use Kern\Platform\Crypto\AssociatedData;

/**
 * The AAD bound into every encrypted frame and manifest this module writes. Reuses the exact
 * `subject_id | table | field | record_uuid` shape docs/handoff/005-crypto-core.md established
 * for field-level encryption ({@see AssociatedData::for()}) rather than inventing a second
 * binding scheme, per this module's brief:
 *
 *   subject_id  -> ObjectKey::$subjectId
 *   table       -> the fixed literal "object_store" (there is exactly one object store)
 *   field       -> the full object key, e.g. "subjects/{uuid}/documents/{uuid}/original.enc" —
 *                  safe to use unescaped for the same reason AssociatedData's own docblock gives:
 *                  it is entirely system-generated identifiers, never free-form input, so it can
 *                  never itself contain "|" in a way that collides two distinct keys.
 *   record_uuid -> the chunk index (decimal string) for a content frame, or the literal
 *                  "manifest" for the manifest object.
 *
 * This is what makes moving ciphertext to another subject's path, another document's path, or
 * another chunk position fail AEAD authentication (BLUEPRINT.md §4.2/§4.6).
 */
final class ObjectStoreAssociatedData
{
    private const string TABLE = 'object_store';

    private function __construct()
    {
    }

    public static function forChunk(ObjectKey $key, int $chunkIndex): AssociatedData
    {
        return AssociatedData::for($key->subjectId, self::TABLE, $key->value, (string) $chunkIndex);
    }

    public static function forManifest(ObjectKey $key): AssociatedData
    {
        return AssociatedData::for($key->subjectId, self::TABLE, $key->value, 'manifest');
    }
}
