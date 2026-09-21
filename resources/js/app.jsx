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
        // App-wide icon defaults -- size matches the 20px standard used by
        // .icon-btn (see components.css) so any icon that doesn't set its
        // own size prop still lines up; either value is still overridden
        // by a prop set on an individual icon.
        createRoot(el).render(
            <IconContext.Provider value={{ weight: 'regular', size: 20 }}>
                <App {...props} />
            </IconContext.Provider>
        );
    },
});
