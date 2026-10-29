import fs from 'node:fs';
import path from 'node:path';
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';

// In development the stylesheet comes from this dev server, so its
// /webfonts/… urls (base/_fonts.scss) are asked of it too -- and Vite
// doesn't serve public/, so every font 404'd and the browser fell back to
// the system font. This serves public/webfonts here as well. A production
// build is untouched: the page serves its own public/ files.
function devWebfonts() {
    const root = path.resolve('public/webfonts');
    const types = { '.woff2': 'font/woff2', '.woff': 'font/woff' };

    return {
        name: 'dev-webfonts',
        configureServer(server) {
            server.middlewares.use('/webfonts', (req, res, next) => {
                const file = path.join(root, decodeURIComponent((req.url || '').split('?')[0]));
                if (!file.startsWith(root + path.sep) || !fs.existsSync(file) || !fs.statSync(file).isFile()) {
                    return next();
                }
                // Fonts are fetched with CORS: the page is on another origin.
                if (req.headers.origin) res.setHeader('Access-Control-Allow-Origin', req.headers.origin);
                res.setHeader('Content-Type', types[path.extname(file)] || 'application/octet-stream');
                fs.createReadStream(file).pipe(res);
            });
        },
    };
}

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/scss/app.scss', 'resources/js/app.jsx'],
            refresh: true,
        }),
        react(),
        devWebfonts(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
