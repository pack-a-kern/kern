import type { DocumentExtractionSummary } from '../types/documents';
import type { RentalSummary } from '../types/rentals';
import { CURRENCIES } from '../types/wallet';
import { currencyFrom, entryAt, groupOf, readString, subjectPaymentIndex } from './readingFields';

/*
 * The rental form's values and where they start from: empty, an existing rental, or a contract.
 * Kept apart from the dialog so the rentals page and the document review screen share one source.
 */

export interface RentalForm {
    property_type: string;
    address: string;
    ownership_percent: string;
    tenant_type: string;
    tenant_name: string;
    start_date: string;
    end_date: string;
    currency_code: string;
    /** Major units, as typed. */
    rent: string;
    payment_frequency: string;
}

/**
 * An empty form. The selects start blank and the dialog shows the server's first choice for them
 * once it is known, so no list of enum values is kept here.
 */
export function emptyRentalForm(): RentalForm {
    return {
        property_type: '',
        address: '',
        // Most rentals are of a property the person owns outright; a co-owner changes it.
        ownership_percent: '100',
        tenant_type: '',
        tenant_name: '',
        start_date: '',
        end_date: '',
        currency_code: CURRENCIES[0],
        rent: '',
        payment_frequency: '',
    };
}

export function rentalFormFor(rental: RentalSummary): RentalForm {
    return {
        property_type: rental.propertyType,
        address: rental.address,
        ownership_percent: rental.ownershipPercent,
        tenant_type: rental.tenantType,
        tenant_name: rental.tenantName,
        start_date: rental.startDate,
        end_date: rental.endDate ?? '',
        currency_code: rental.currencyCode,
        rent: (rental.rentMinor / 100).toFixed(2),
        payment_frequency: rental.paymentFrequency,
    };
}

/**
 * Starting values read from an accepted contract.
 *
 * The tenant is the one paying the rent: the server has already matched which side of the payment
 * the subject is on, so the other side is the tenant. The address is the property's as the contract
 * prints it, and starts empty when the contract did not say.
 */
export function rentalFormFromReading(extraction: DocumentExtractionSummary | null): RentalForm {
    const fields = extraction?.proposedFields ?? {};
    const index = subjectPaymentIndex(extraction);
    const payment = entryAt(fields, 'payments', index);
    const counterparty = extraction?.paymentParties[index]?.counterparty ?? null;

    return {
        ...emptyRentalForm(),
        tenant_name: counterparty ?? (readString(payment, 'payer') || readString(groupOf(fields, 'recipient'), 'name')),
        start_date: readString(fields, 'date'),
        end_date: readString(fields, 'contract_end_date'),
        address: readString(fields, 'contract_rental_address'),
        currency_code: currencyFrom(payment),
        rent: readString(payment, 'amount_paid'),
    };
}
