<?php

declare(strict_types=1);

namespace Kern\Legislation\Enums;

/**
 * The Romanian legal-act structural hierarchy this milestone parses (`BLUEPRINT.md` §6.8, this
 * task's brief). `Titlu`/`Capitol`/`Secțiune` container levels above `Articol`
 * (see `Kern\Legislation\Parsing\LegislatieJustRoHtmlParser`'s docblock) are still
 * deliberately *not* modelled as their own citable sections — they never carry substantive text of
 * their own, only group other sections, so a future task adds them only if the product needs to
 * cite at that granularity.
 *
 * `ANEXA` was added after parsing the real Codul fiscal (Legea 227/2015, 7.6 MB) — unlike
 * Titlu/Capitol/Secțiune, an `S_ANX` ("Anexă"/Annex) genuinely carries its own citable
 * `ALINEAT`/`LITERA`/`PUNCT` content (e.g. lettered classification lists), so leaving it
 * unmodelled left every provision inside an annex an orphan with no ancestry at all — worse than
 * `Titlu`/`Capitol`, which never had citable children to lose. See
 * `docs/handoff/039-fiscal-code-parser-fix.md` for the measured evidence (9 real annexes, several
 * with colliding local numbering across different `Titlu`s, e.g. three separate "Anexa nr. 1"s).
 */
enum SectionType: string
{
    case ARTICOL = 'ARTICOL';
    case ALINEAT = 'ALINEAT';
    case LITERA = 'LITERA';
    case PUNCT = 'PUNCT';
    case ANEXA = 'ANEXA';

    /** A dash item — "– plan de rambursare a datoriilor;" — cited by its position under its parent. */
    case LINIUTA = 'LINIUTA';

    /**
     * What an act says before its first article — "Având în vedere prevederile: – …", "ministrul
     * finanțelor emite următorul ordin:" — the acts it rests on and who issues it. Its dashes are its
     * own lines, not provisions: they are cited as the preamble, never on their own.
     */
    case PREAMBUL = 'PREAMBUL';

    /** Who signed the act, where and when, and its number — "p. Ministrul finanțelor, …", "București, 27 octombrie 2022.", "Nr. 3.957." */
    case SEMNATURA = 'SEMNATURA';
}
