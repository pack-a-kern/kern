import { apiRequest } from '../lib/http';
import { parseReadiness, type Readiness } from '../types/setup';
import { runOperationTask } from './operationsApi';

/**
 * `GET /api/v1/setup/requirements` — what each package declared it needs, resolved to whether the
 * install is bootstrapped and each feature available (ADR 0027). Install state, not a taxpayer's,
 * so it takes no subject.
 */
export async function getReadiness(): Promise<Readiness> {
    return parseReadiness(await apiRequest('/v1/setup/requirements'));
}

/**
 * The type, number and year of an act, split from the identifier a `law` need carries — the form
 * `SourceDocument.external_identifier` is written in, `"{TYPE} {number}/{year}"` (e.g. `LEGE
 * 227/2015`). This is the only reading the screen does of a need's argument; the shape is fixed by
 * the server ({@see \Kern\Legislation\Actions\SyncLegislationDocument::identifier()}), so a value
 * that does not match it is a misconfigured requirement, surfaced rather than guessed at.
 */
function actFromIdentifier(identifier: string): { type: string; number: number; year: number } {
    const match = /^(\S+)\s+(\d{1,5})\/(\d{4})$/.exec(identifier.trim());
    const type = match?.[1];
    const number = match?.[2];
    const year = match?.[3];

    if (type === undefined || number === undefined || year === undefined) {
        throw new Error(`The law identifier "${identifier}" is not "TYPE number/year".`);
    }

    return { type, number: Number(number), year: Number(year) };
}

/**
 * Imports the law a `law` need names, directly by its identifier — no portal search. It runs the
 * same locked, audited ingest task the Operations screen and the "Add a law" dialog run
 * (`legislation.ingest` → `kern:legislation:sync`), which reads the law and every consolidation it
 * has had and dates them; `operations.run` is required, as for any import. The need flips met once
 * the law is in force, which the screen learns by asking readiness again.
 */
export async function importRequiredLaw(identifier: string): Promise<void> {
    const act = actFromIdentifier(identifier);

    await runOperationTask('legislation.ingest', {
        act_type: act.type,
        act_number: act.number,
        act_year: act.year,
    });
}

/**
 * `POST /api/v1/legislation/watches/declared/{key}/run` — runs the package-declared watch a `watch`
 * need names, mirroring its row first on a fresh install. It reads what the watch would find at its
 * hour (the minimum-wage decision, a programme's orders) as a law; the need flips met once the watch
 * has read one. `operations.run` is required, as for running any watch.
 */
export async function importWatchedActs(key: string): Promise<void> {
    await apiRequest(`/v1/legislation/watches/declared/${encodeURIComponent(key)}/run`, { method: 'POST' });
}

/**
 * `POST /api/v1/fiscal-parameters/minimum-wage` — read the minimum wage from the decisions already in
 * the library, proposing a value for each. The watch (above) finds and reads the decision laws; this
 * reads the value out of the ones already there, which is what the `parameters` step needs when the
 * laws are present but no value is proposed (every value dropped, a re-install on a populated
 * library). The step flips to "approve" once the candidate lands, which the screen learns by asking
 * readiness again.
 */
export async function readMinimumWages(): Promise<void> {
    await apiRequest('/v1/fiscal-parameters/minimum-wage', { method: 'POST' });
}
