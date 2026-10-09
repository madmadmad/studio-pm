import { useEffect, useRef, useState } from 'react';
import { Gif } from '@phosphor-icons/react';
import { api } from '../../lib/api';

const SEARCH_DELAY_MS = 350;

// The composer's GIF button: opens a panel of GIPHY's trending GIFs, or a
// search, above the composer. Picking one calls `onPick(gif)` and closes.
// Searches go through the server (/api/chat/gifs), which holds the key.
// "Powered by GIPHY" is GIPHY's attribution rule for a search like this.
export default function GifPicker({ onPick }) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [results, setResults] = useState([]);
    const [next, setNext] = useState(0);
    const [hasMore, setHasMore] = useState(false);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');
    const ref = useRef(null);
    const request = useRef(0);

    useEffect(() => {
        if (!open) return undefined;
        const close = (e) => !ref.current?.contains(e.target) && setOpen(false);
        const onKey = (e) => {
            if (e.key === 'Escape') {
                e.stopPropagation();
                setOpen(false);
            }
        };
        document.addEventListener('pointerdown', close);
        document.addEventListener('keydown', onKey, true);
        return () => {
            document.removeEventListener('pointerdown', close);
            document.removeEventListener('keydown', onKey, true);
        };
    }, [open]);

    async function load(q, offset) {
        const id = ++request.current;
        setLoading(true);
        setError('');
        try {
            const data = await api.get(`/api/chat/gifs?${new URLSearchParams({ q, offset })}`);
            if (id !== request.current) return; // a newer search is on its way
            setResults((current) => (offset === 0 ? data.results : [...current, ...data.results]));
            setNext(data.next_offset);
            setHasMore(data.has_more);
        } catch (e) {
            if (id === request.current) setError(e.message || "Couldn't load GIFs.");
        } finally {
            if (id === request.current) setLoading(false);
        }
    }

    // Trending on open; a search a moment after typing stops.
    useEffect(() => {
        if (!open) return undefined;
        const timer = setTimeout(() => load(query.trim(), 0), query ? SEARCH_DELAY_MS : 0);
        return () => clearTimeout(timer);
    }, [open, query]);

    function onScroll(e) {
        const el = e.currentTarget;
        if (hasMore && !loading && el.scrollHeight - el.scrollTop - el.clientHeight < 200) load(query.trim(), next);
    }

    function pick(gif) {
        onPick(gif);
        setOpen(false);
        setQuery('');
    }

    return (
        <div ref={ref} className="gif-picker">
            <button type="button" className="icon-btn icon-btn--secondary" onClick={() => setOpen((o) => !o)} title="GIF" aria-label="Add a GIF" aria-expanded={open}>
                <Gif size={20} />
            </button>
            {open && (
                <div className="popover gif-picker__panel" role="dialog" aria-label="Choose a GIF">
                    <input
                        className="input gif-picker__search"
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                        placeholder="Search GIPHY"
                        aria-label="Search GIPHY"
                        maxLength={50}
                        autoFocus
                    />
                    <div className="gif-picker__results" onScroll={onScroll}>
                        {error && <p className="gif-picker__status" role="alert">{error}</p>}
                        {!error && !loading && results.length === 0 && <p className="gif-picker__status">No GIFs found.</p>}
                        <div className="gif-picker__grid">
                            {results.map((gif) => (
                                <button key={gif.id} type="button" className="gif-picker__item" onClick={() => pick(gif)} title={gif.title}>
                                    <img
                                        src={gif.preview_url}
                                        alt={gif.title}
                                        width={gif.preview_width || undefined}
                                        height={gif.preview_height || undefined}
                                        loading="lazy"
                                        className="gif-picker__img"
                                    />
                                </button>
                            ))}
                        </div>
                        {loading && <p className="gif-picker__status">Loading…</p>}
                    </div>
                    <p className="gif-picker__attribution">Powered by GIPHY</p>
                </div>
            )}
        </div>
    );
}
