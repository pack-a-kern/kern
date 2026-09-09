import { Link } from '@inertiajs/react';
import type { JSX } from 'react';
import { logout } from '../api/authApi';

interface NavProps {
    current: 'dashboard' | 'inbox' | 'capture';
}

const LINK_BASE = 'rounded-md px-3 py-2 text-sm font-medium transition-colors';
const LINK_ACTIVE = 'bg-blue-600 text-white';
const LINK_INACTIVE = 'text-neutral-600 hover:bg-neutral-100 hover:text-neutral-900';

export default function Nav({ current }: NavProps): JSX.Element {
    async function handleLogout(): Promise<void> {
        try {
            await logout();
        } finally {
            // Full navigation, not an Inertia visit — guarantees a fresh CSRF token on the login
            // page's next document load (resources/js/lib/csrf.ts's docblock).
            window.location.assign('/login');
        }
    }

    return (
        <header className="border-b border-neutral-200 bg-white">
            <nav className="mx-auto flex max-w-4xl flex-wrap items-center justify-between gap-2 px-4 py-3">
                <span className="text-base font-semibold text-neutral-900">TaxOS România</span>
                <div className="flex flex-wrap items-center gap-1">
                    <Link
                        href="/dashboard"
                        className={`${LINK_BASE} ${current === 'dashboard' ? LINK_ACTIVE : LINK_INACTIVE}`}
                    >
                        Panou
                    </Link>
                    <Link
                        href="/documents"
                        className={`${LINK_BASE} ${current === 'inbox' ? LINK_ACTIVE : LINK_INACTIVE}`}
                    >
                        Documente
                    </Link>
                    <Link
                        href="/documents/capture"
                        className={`${LINK_BASE} ${current === 'capture' ? LINK_ACTIVE : LINK_INACTIVE}`}
                    >
                        Adaugă document
                    </Link>
                    <button
                        type="button"
                        onClick={() => void handleLogout()}
                        className={`${LINK_BASE} ${LINK_INACTIVE} cursor-pointer`}
                    >
                        Deconectare
                    </button>
                </div>
            </nav>
        </header>
    );
}
