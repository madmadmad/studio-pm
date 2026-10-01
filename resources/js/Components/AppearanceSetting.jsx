import { router } from '@inertiajs/react';
import { useState } from 'react';
import { Desktop, Moon, Sun } from '@phosphor-icons/react';
import { api } from '../lib/api';

const OPTIONS = [
    { value: 'dark', label: 'Dark', icon: <Moon /> },
    { value: 'light', label: 'Light', icon: <Sun /> },
    { value: 'system', label: 'System', icon: <Desktop /> },
];

// A person's Appearance (staff and clients alike): Dark (the default),
// Light, or System to follow the computer. The page switches at once --
// <html data-theme> drives the tokens (base/_tokens.scss) -- and the
// choice is saved to `endpoint` for every page after.
export default function AppearanceSetting({ current, endpoint }) {
    const [theme, setTheme] = useState(current || 'dark');
    const [error, setError] = useState('');

    async function choose(value) {
        const previous = theme;
        setTheme(value);
        setError('');
        document.documentElement.dataset.theme = value;
        try {
            await api.patch(endpoint, { theme: value });
            router.reload({ only: ['auth'] });
        } catch (err) {
            setTheme(previous);
            document.documentElement.dataset.theme = previous;
            setError(err.message || 'Could not save that.');
        }
    }

    return (
        <div className="form-panel">
            <div className="section-label section-label--ruled">Appearance</div>
            <div role="radiogroup" aria-label="Appearance" className="view-toggle appearance-setting">
                {OPTIONS.map((option) => (
                    <button
                        key={option.value}
                        type="button"
                        role="radio"
                        aria-checked={theme === option.value}
                        onClick={() => choose(option.value)}
                        className={`view-toggle__option${theme === option.value ? ' view-toggle__option--active' : ''}`}
                    >
                        {option.icon} {option.label}
                    </button>
                ))}
            </div>
            <p className="form-hint form-hint--attached">System follows your computer&rsquo;s light or dark setting.</p>
            {error && <div className="form-error">{error}</div>}
        </div>
    );
}
