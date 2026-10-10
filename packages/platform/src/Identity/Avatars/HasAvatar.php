<?php

declare(strict_types=1);

namespace Kern\Platform\Identity\Avatars;

use Illuminate\Support\Carbon;

/**
 * Something that can wear a picture: a user, a taxpayer.
 *
 * ## Why an interface and not a second copy of the actions
 *
 * {@see SetAvatar} is the valuable part of this feature and almost none of it is about users. Weighing
 * the upload before anything decodes it, scanning the bytes as they arrived rather than the ones this
 * installation wrote, rebuilding through {@see \Kern\Platform\Uploads\SquareImageNormaliser} so the
 * stored file shares nothing with the uploaded one — that is a pipeline about images. Four lines at the
 * end of it were about users. Those four are what this interface abstracts, so the pipeline is written
 * once and a second kind of owner costs a model method rather than a second action nobody will keep in
 * step with the first.
 *
 * ## Why the owner names its own type
 *
 * The audit row has to say what was changed, and `resourceType` is read by people. A `user` and a
 * `tax_subject` are different things to anybody auditing, and deriving the word from the class name
 * would make a rename of the class a silent rewrite of history.
 */
interface HasAvatar
{
    /** Where the bytes are, or null when this owner has no picture. */
    public function avatarPath(): ?string;

    /**
     * Records a new picture and persists it, or clears it when both are null.
     *
     * The owner does the writing rather than the action, so nothing here needs to know that an owner
     * is an Eloquent model — which is also what keeps `save()` out of this contract.
     */
    public function saveAvatar(?string $path, ?Carbon $at): void;

    /** The id the file is named after, and the one the audit row points at. */
    public function avatarOwnerId(): string;

    /** What this owner is called in an audit row: `user`, `tax_subject`. */
    public function avatarOwnerType(): string;
}
