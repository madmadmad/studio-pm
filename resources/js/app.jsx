import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import { IconContext } from '@phosphor-icons/react';

const pages = import.meta.glob('./Pages/**/*.jsx', { eager: true });

createInertiaApp({
    resolve: (name) => {
        const page = pages[`./Pages/${name}.jsx`];
        if (!page) {
            throw new Error(`Page not found: ./Pages/${name}.jsx`);
        }
        return page.default;
    },
    setup({ el, App, props }) {
        // Bold app-wide by default -- Phosphor's "regular" weight read as
        // too thin at the small sizes (14-20px) icons are used at here.
        // A weight="bold" prop set at an individual icon still wins over
        // this, so the handful of icons already forcing it are unaffected.
        createRoot(el).render(
            <IconContext.Provider value={{ weight: 'bold' }}>
                <App {...props} />
            </IconContext.Provider>
        );
    },
});
