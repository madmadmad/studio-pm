import { useEffect, useRef } from 'react';
import { router } from '@inertiajs/react';
import { api } from './api';

const INTERVAL_MS = 10_000;

// New messages without a reload: while a Messages tab is open and the
// browser tab is visible, ask `url` every 10 seconds whether anything has
// changed (a fingerprint -- App\Services\MessageVersion), and only when it
// has, refetch the page's project data. Half-typed replies and open threads
// are kept (Inertia keeps component state on a reload). Checks again as
// soon as the tab comes back into view.
export function useMessagePolling(url, version) {
    const known = useRef(version);

    useEffect(() => {
        known.current = version;
    }, [version]);

    useEffect(() => {
        let checking = false;

        async function check() {
            if (document.hidden || checking) return;
            checking = true;
            try {
                const { version: latest } = await api.get(url);
                if (latest && latest !== known.current) {
                    known.current = latest;
                    router.reload({ only: ['project', 'messagesVersion'] });
                }
            } catch {
                // Offline or signed out -- try again next time.
            } finally {
                checking = false;
            }
        }

        const timer = setInterval(check, INTERVAL_MS);
        const onVisible = () => !document.hidden && check();
        document.addEventListener('visibilitychange', onVisible);

        return () => {
            clearInterval(timer);
            document.removeEventListener('visibilitychange', onVisible);
        };
    }, [url]);
}
