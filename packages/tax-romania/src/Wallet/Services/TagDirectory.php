<?php

declare(strict_types=1);

namespace Kern\TaxRomania\Wallet\Services;

use Illuminate\Support\Facades\DB;
use Kern\Platform\Crypto\BlindIndex\BlindIndexHasher;
use Kern\Platform\Crypto\BlindIndex\Normalizer;
use Kern\Platform\Tenancy\TenantContext;
use Kern\TaxRomania\Wallet\Models\Tag;

/**
 * The taxpayer's own tags: finding them by name, creating the ones that do not exist yet, and the
 * three things a person does to a vocabulary that grew by itself — rename, merge, delete.
 *
 * ## One spelling
 *
 * Tags arrive from three directions: typed on a form, proposed by a model reading a statement, and
 * carried by a remembered rule. Left alone that produces "Groceries", "groceries " and "GROCERIES"
 * as three tags that filter three different sets. Every name is normalised the same way before it is
 * looked up or stored, and the unique index on the blind index is what actually enforces it.
 *
 * ## Names are not queried
 *
 * `name_ciphertext` is encrypted, so "the tag called groceries" is not a `where`. It is found by the
 * blind index — the same keyed hash the rest of this codebase uses for exact lookup of an encrypted
 * value — and everything else works by id.
 */
final readonly class TagDirectory
{
    /** Long enough for a phrase, short enough that a tag stays a label rather than a note. */
    public const int MAX_LENGTH = 40;

    public function __construct(
        private BlindIndexHasher $blindIndex,
        private TenantContext $tenant,
    ) {
    }

    /**
     * One spelling for a tag: trimmed, collapsed whitespace, lower case, cut to length.
     *
     * Lower case because a tag is a label rather than a sentence, and "Restaurante" and
     * "restaurante" are one label typed twice.
     */
    public static function normalise(string $name): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', trim($name)) ?? '';

        return mb_substr(mb_strtolower($collapsed), 0, self::MAX_LENGTH);
    }

    /**
     * The tags this taxpayer has, by id, newest name first — decrypted, because a screen shows them.
     *
     * @return array<string, string> id => name
     */
    public function all(): array
    {
        $tags = [];

        foreach (Tag::query()->get() as $tag) {
            $tags[$tag->id] = (string) $tag->name_ciphertext;
        }

        asort($tags, SORT_NATURAL | SORT_FLAG_CASE);

        return $tags;
    }

    /**
     * Ids for a list of names, creating what does not exist yet.
     *
     * Empty names are dropped rather than stored: a form that submits an empty chip should not
     * leave a nameless tag behind. The order of the answer follows the order asked for, so a caller
     * can pair it with what it sent.
     *
     * @param  list<string>  $names
     * @return list<string>
     */
    public function idsFor(array $names): array
    {
        $ids = [];

        foreach ($names as $name) {
            $normalised = self::normalise($name);

            if ($normalised === '') {
                continue;
            }

            $id = $this->idFor($normalised);

            if (! in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /** The ids that exist among the given ones — a caller's list, narrowed to this taxpayer's tags. */
    /**
     * @param  list<string>  $ids
     * @return list<string>
     */
    public function existing(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return array_values(array_map(
            static fn (mixed $id): string => is_scalar($id) ? (string) $id : '',
            Tag::query()->whereKey($ids)->pluck('id')->all(),
        ));
    }

    /**
     * Rename a tag, unless the new name is one the taxpayer already has — that is a merge, and it is
     * a different question with a different answer, so it is refused here rather than guessed at.
     */
    public function rename(Tag $tag, string $name): bool
    {
        $normalised = self::normalise($name);

        if ($normalised === '') {
            return false;
        }

        $index = $this->indexFor($normalised);

        if ($index !== $tag->name_blind_index && $this->findByIndex($index) !== null) {
            return false;
        }

        $tag->name_ciphertext = $normalised;
        $tag->name_blind_index = $index;
        $tag->save();

        return true;
    }

    /**
     * Fold one tag into another: every transaction and every rule carrying it now carries the target,
     * and the folded tag goes.
     *
     * Insert-ignoring rather than a plain update, because a transaction may already carry both — the
     * pivot's primary key is the pair, and moving the link would collide on exactly the rows a merge
     * is most likely to touch.
     */
    public function merge(Tag $from, Tag $into): void
    {
        DB::transaction(function () use ($from, $into): void {
            foreach ([
                ['transaction_tags', 'transaction_id'],
                ['counterparty_rule_tags', 'rule_id'],
            ] as [$table, $ownerKey]) {
                $rows = DB::table($table)
                    ->where('tax_subject_id', $this->tenant->currentTenantId())
                    ->where('tag_id', $from->id)
                    ->pluck($ownerKey);

                foreach ($rows as $ownerId) {
                    DB::table($table)->insertOrIgnore([
                        $ownerKey => $ownerId,
                        'tag_id' => $into->id,
                        'tax_subject_id' => $this->tenant->currentTenantId(),
                    ]);
                }
            }

            // The links to the folded tag go with it: both pivots cascade on delete.
            $from->delete();
        });
    }

    private function idFor(string $normalisedName): string
    {
        $index = $this->indexFor($normalisedName);
        $existing = $this->findByIndex($index);

        if ($existing !== null) {
            return $existing->id;
        }

        // `tax_subject_id` explicitly, before the encrypted attribute: the cast reads it for the key
        // and the additional data, and the trait's auto-fill happens too late for that.
        $tag = new Tag([
            'tax_subject_id' => $this->tenant->currentTenantId(),
            'name_ciphertext' => $normalisedName,
            'name_blind_index' => $index,
        ]);
        $tag->save();

        return $tag->id;
    }

    private function indexFor(string $normalisedName): string
    {
        return $this->blindIndex->compute(Normalizer::text($normalisedName));
    }

    private function findByIndex(string $index): ?Tag
    {
        return Tag::query()->where('name_blind_index', $index)->first();
    }
}
