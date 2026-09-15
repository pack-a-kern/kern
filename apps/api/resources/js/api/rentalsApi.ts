import { apiRequest } from '../lib/http';
import { parseRentals, type RentalList } from '../types/rentals';

function rentalsPath(subjectId: string, rentalId?: string, action?: string): string {
    const base = `/v1/subjects/${encodeURIComponent(subjectId)}/rentals`;
    const withId = rentalId === undefined ? base : `${base}/${encodeURIComponent(rentalId)}`;

    return action === undefined ? withId : `${withId}/${action}`;
}

/** `GET .../rentals` — every rental, what each owes per year, and the form's choices. */
export async function listRentals(subjectId: string): Promise<RentalList> {
    return parseRentals(await apiRequest(rentalsPath(subjectId)));
}

// A type alias rather than an interface: the request body is typed as a plain record, and only an
// alias is assignable to one.
export type OpenRentalRequest = {
    property_type: string;
    address: string;
    ownership_percent: string;
    tenant_type: string;
    tenant_name: string;
    start_date: string;
    end_date: string | null;
    currency_code: string;
    rent_minor: number;
    payment_frequency: string;
    /** The accepted contract it was read from. Refused once that contract already has a rental. */
    document_id?: string;
};

/** `POST .../rentals` — the property and its contract, in one request. */
export async function openRental(subjectId: string, body: OpenRentalRequest): Promise<void> {
    await apiRequest(rentalsPath(subjectId), { method: 'POST', body });
}

/**
 * `PATCH .../rentals/{id}` — the description only.
 *
 * The rent and the dates are refused by the server rather than ignored: they change through
 * {@link changeRentalRent} and {@link endRental}, which keep earlier years at what they were owed.
 */
export async function updateRental(
    subjectId: string,
    rentalId: string,
    body: {
        property_type: string;
        address: string;
        ownership_percent: string;
        tenant_type: string;
        tenant_name: string;
    },
): Promise<void> {
    await apiRequest(rentalsPath(subjectId, rentalId), { method: 'PATCH', body });
}

/** `POST .../rentals/{id}/rent` — a new rent from a date; periods before it keep the old one. */
export async function changeRentalRent(
    subjectId: string,
    rentalId: string,
    body: { effective_from: string; rent_minor: number; payment_frequency: string },
): Promise<void> {
    await apiRequest(rentalsPath(subjectId, rentalId, 'rent'), { method: 'POST', body });
}

/** `POST .../rentals/{id}/end` — rent stops being owed from this date. */
export async function endRental(subjectId: string, rentalId: string, endDate: string): Promise<void> {
    await apiRequest(rentalsPath(subjectId, rentalId, 'end'), { method: 'POST', body: { end_date: endDate } });
}

/** `DELETE .../rentals/{id}` — for a rental entered by mistake. */
export async function deleteRental(subjectId: string, rentalId: string): Promise<void> {
    await apiRequest(rentalsPath(subjectId, rentalId), { method: 'DELETE' });
}
