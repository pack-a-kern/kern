/* global window, document, localStorage, fetch */
/**
 * The offline page's behaviour: the person's theme and language, and coming back on its own.
 *
 * Reads the same `kern.theme` / `kern.locale` keys the application writes (resources/js/hooks/
 * useTheme.ts, resources/js/i18n/index.ts), and like the application falls back to Romanian when no
 * language was chosen. The HTML itself is English, so the page still reads if this file never runs.
 *
 * `navigator.onLine` alone is not trusted to say the connection is back — it is true on a network
 * that reaches nothing — so the page also asks the server, and reloads only on a real answer.
 */
(function () {
    const read = (key) => {
        try {
            return localStorage.getItem(key);
        } catch {
            return null;
        }
    };

    const theme = read('kern.theme');
    if (theme === 'dark' || theme === 'light') {
        document.documentElement.classList.add(theme);
    }

    const texts = {
        en: {
            back: 'Back online, loading…',
        },
        ro: {
            documentTitle: 'Fără conexiune — Kern',
            title: 'Ești offline',
            body: 'Pagina nu s-a putut încărca fiindcă dispozitivul nu are conexiune. Documentele puse deja în coada de încărcare rămân pe acest dispozitiv și pleacă singure când revine internetul.',
            retry: 'Reîncearcă',
            waiting: 'Aștept conexiunea…',
            back: 'Conexiunea a revenit, se încarcă…',
        },
    };

    const locale = read('kern.locale') === 'en' ? 'en' : 'ro';
    const dictionary = texts[locale];
    document.documentElement.lang = locale;

    if (locale === 'ro') {
        document.title = dictionary.documentTitle;
        for (const element of document.querySelectorAll('[data-text]')) {
            const text = dictionary[element.getAttribute('data-text')];
            if (text !== undefined) {
                element.textContent = text;
            }
        }
    }

    let reloading = false;

    const comeBack = () => {
        if (reloading) {
            return;
        }
        reloading = true;
        const status = document.querySelector('.status');
        status.classList.add('back');
        status.querySelector('[data-text="waiting"]').textContent = dictionary.back;
        window.location.reload();
    };

    // Not a navigation and not under /build, so the service worker lets it through to the network.
    const probe = async () => {
        try {
            const response = await fetch('/manifest.webmanifest', { cache: 'no-store' });
            if (response.ok) {
                comeBack();
            }
        } catch {
            // Still offline.
        }
    };

    document.getElementById('retry').addEventListener('click', () => {
        void probe();
    });
    window.addEventListener('online', () => {
        void probe();
    });
    window.setInterval(() => {
        void probe();
    }, 10000);
})();
