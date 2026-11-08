import { logout } from '../api/authApi';

/**
 * Ends the session and goes to the sign-in page — from the account card's menu, its sign-out
 * button, or the taxpayer chooser's way out.
 *
 * A full navigation, not an Inertia visit: it guarantees a fresh CSRF token on the login page's next
 * document load (`lib/csrf.ts`'s docblock).
 */
export async function signOut(): Promise<void> {
    try {
        await logout();
    } finally {
        window.location.assign('/login');
    }
}
