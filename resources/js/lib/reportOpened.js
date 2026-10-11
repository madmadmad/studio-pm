import { useEffect } from 'react';
import { api } from './api';

// A public invoice or proposal page (/i/..., /p/...) saying, once it has
// loaded in a browser, that the client has it open -- the server decides
// whether it counts (App\Support\DocumentViews). Quietly, whatever happens.
export function useReportOpened() {
    useEffect(() => {
        api.post(`${window.location.pathname.replace(/\/$/, '')}/viewed`).catch(() => {});
    }, []);
}
