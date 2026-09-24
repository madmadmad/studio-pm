import { X } from '@phosphor-icons/react';

// Right-edge slide-in panel. `header` sits on the left of the top bar;
// `actions` (icon buttons, given the `drawer__action` class) sit to the
// left of the built-in close button. Escape handling stays with the
// caller, which owns the open/closed state.
export default function Drawer({ header, actions, onClose, children }) {
    return (
        <div className="drawer">
            <div className="drawer__backdrop" onClick={onClose} />
            <div className="drawer__panel">
                <div className="drawer__header">
                    {header}
                    <div className="drawer__actions">
                        {actions}
                        <button onClick={onClose} className="icon-btn icon-btn--secondary drawer__action">
                            <X />
                        </button>
                    </div>
                </div>
                <div className="drawer__body">{children}</div>
            </div>
        </div>
    );
}
