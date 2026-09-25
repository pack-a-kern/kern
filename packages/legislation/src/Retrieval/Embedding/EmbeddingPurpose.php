<?php

declare(strict_types=1);

namespace Kern\Legislation\Retrieval\Embedding;

/**
 * Which side of a retrieval a text is being embedded for.
 *
 * This exists because asymmetric embedding models — E5 among them — are trained with a marker on
 * each side (`query: ` / `passage: `) and lose accuracy when it is missing or wrong. Nothing
 * detects the mistake: the vector is well-formed, normalises correctly, and simply lands in a
 * slightly wrong part of the space, so retrieval degrades quietly instead of failing.
 *
 * Modelled as an enum on the interface rather than left to each caller's own string so that the
 * decision is made once, at a call site that can see what it is embedding, and so a symmetric model
 * (bge-m3 wants raw text on both sides) can ignore it by configuring both prefixes empty.
 */
enum EmbeddingPurpose: string
{
    /** Corpus text being indexed — a legislation chunk. */
    case PASSAGE = 'passage';

    /** A search query typed by a person. */
    case QUERY = 'query';
}
