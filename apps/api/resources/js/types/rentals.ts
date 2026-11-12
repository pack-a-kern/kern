import { parseJson, vArray, vNullable, vNumber, vObject, vString } from '../lib/validate';

/** What a contract owes in one calendar year: this taxpayer's share, in the contract's currency. */
export interface RentalYear {
    year: number;
    shareMinor: number;
}

/**
 * One rental as `GET .../rentals` describes it.
 *
 * `years` is rent **owed**, never rent received — rental income is set by the contract whether or not
 * the tenant paid (Codul fiscal, art. 84), so nothing here depends on a transaction.
 */
export interface RentalSummary {
    id: string;
    propertyType: string;
    address: string;
    /** A decimal string, e.g. "50.00". */
    ownershipPercent: string;
    tenantType: string;
    tenantName: string;
    startDate: string;
    endDate: string | null;
    status: string;
    currencyCode: string;
    /** The rent in force now — the full contractual figure, before the ownership share. */
    rentMinor: number;
    paymentFrequency: string;
    paymentDay: number | null;
    sourceDocumentId: string | null;
    /** Newest first. */
    years: RentalYear[];
}

/** The form's choices, from the server's own enums so the two cannot drift. */
export interface RentalFormOptions {
    propertyTypes: string[];
    tenantTypes: string[];
    paymentFrequencies: string[];
}

export interface RentalList {
    rentals: RentalSummary[];
    /** This year on Romania's calendar, from the server: the year "owed this year" means. */
    currentYear: number;
    formOptions: RentalFormOptions;
}

const rentalListValidator = vObject({
    rentals: vArray(
        vObject({
            id: vString,
            property_type: vString,
            address: vString,
            ownership_percent: vString,
            tenant_type: vString,
            tenant_name: vString,
            start_date: vString,
            end_date: vNullable(vString),
            status: vString,
            currency_code: vString,
            rent_minor: vNumber,
            payment_frequency: vString,
            payment_day: vNullable(vNumber),
            source_document_id: vNullable(vString),
            years: vArray(vObject({ year: vNumber, share_minor: vNumber })),
        }),
    ),
    current_year: vNumber,
    form_options: vObject({
        property_types: vArray(vString),
        tenant_types: vArray(vString),
        payment_frequencies: vArray(vString),
    }),
});

export function parseRentals(text: string): RentalList {
    const body = parseJson(text, rentalListValidator);

    return {
        rentals: body.rentals.map((rental) => ({
            id: rental.id,
            propertyType: rental.property_type,
            address: rental.address,
            ownershipPercent: rental.ownership_percent,
            tenantType: rental.tenant_type,
            tenantName: rental.tenant_name,
            startDate: rental.start_date,
            endDate: rental.end_date,
            status: rental.status,
            currencyCode: rental.currency_code,
            rentMinor: rental.rent_minor,
            paymentFrequency: rental.payment_frequency,
            paymentDay: rental.payment_day,
            sourceDocumentId: rental.source_document_id,
            years: rental.years.map((entry) => ({ year: entry.year, shareMinor: entry.share_minor })),
        })),
        currentYear: body.current_year,
        formOptions: {
            propertyTypes: body.form_options.property_types,
            tenantTypes: body.form_options.tenant_types,
            paymentFrequencies: body.form_options.payment_frequencies,
        },
    };
}
