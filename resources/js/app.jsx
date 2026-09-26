import { createInertiaApp, router } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import { IconContext } from '@phosphor-icons/react';

const pages = import.meta.glob('./Pages/**/*.jsx', { eager: true });

// When the site has been rebuilt or deployed since this page loaded, the
// server answers the next request with "new version" (409) and Inertia
// does a full page load to pick it up -- except for background refreshes
// (router.reload(), which every drawer save uses), where it silently does
// nothing, leaving the page stale until a manual refresh. Handle that case
// the same way for every request: load the page fresh.
router.on('location', (event) => {
    if (!event.detail.versionChange) return;
    event.preventDefault();
    const url = event.detail.url;
    if (url.origin === window.location.origin && url.pathname === window.location.pathname && url.search === window.location.search) {
        window.location.reload();
    } else {
        window.location.href = url.href;
    }
});

createInertiaApp({
    resolve: (name) => {
        const page = pages[`./Pages/${name}.jsx`];
        if (!page) {
            throw new Error(`Page not found: ./Pages/${name}.jsx`);
        }
        return page.default;
    },
    setup({ el, App, props }) {
        // App-wide icon defaults. Icon buttons (.icon-btn -- see scss
        // components/_icon-btn) deliberately omit their own size prop so this is
        // the one place controlling their size; an icon elsewhere that
        // still sets its own size/weight prop overrides this as usual.
        createRoot(el).render(
            <IconContext.Provider value={{ weight: 'regular', size: 18 }}>
                <App {...props} />
            </IconContext.Provider>
        );
    },
});
