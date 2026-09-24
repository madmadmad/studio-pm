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
