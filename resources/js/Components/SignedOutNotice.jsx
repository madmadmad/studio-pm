import Button from './Button';
import { useSignedOut } from '../lib/session';

// Over the page when the session has ended while the app sat open (the
// Mac slept, two hours went by): says so plainly, rather than saves
// failing one by one. The page stays as it was underneath; to-dos and chat
// messages being written are kept in the browser. "Sign in again" reloads
// the page, which goes through sign-in and comes back here.
export default function SignedOutNotice() {
    const signedOut = useSignedOut();
    if (!signedOut) return null;

    return (
        <div className="modal" role="alertdialog" aria-modal="true" aria-labelledby="signed-out-title">
            <div className="modal__backdrop" />
            <div className="modal__panel">
                <div className="modal__header">
                    <h2 id="signed-out-title" className="modal__title">You&rsquo;ve been signed out</h2>
                </div>
                <div className="modal__body">
                    <p className="form-hint">
                        Your session ended while the app was open. Sign in again to carry on &mdash; to-dos and chat messages you were writing are kept.
                    </p>
                </div>
                <div className="modal__footer">
                    <Button variant="confirm" onClick={() => window.location.reload()}>Sign in again</Button>
                </div>
            </div>
        </div>
    );
}
