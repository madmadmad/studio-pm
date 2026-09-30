import Drawer, { DrawerByline, DrawerDate } from './Drawer';
import ProposalEditor from './ProposalEditor';
import { ProposalStatusBadge } from './StatusBadges';

// A proposal in the wide drawer, with the same editor the standalone page
// uses -- opened from a project's Proposals tab or a client's. `proposal`
// is null for a new one. `companies` is the editor's client list (just the
// one client here), and the presets fix the client, and the project when
// it's known (a project's tab locks it for a new proposal). `onChange`
// refreshes the page's data after a save or a status action.
export default function ProposalDrawer({ proposal, companies, services, presetCompanyId, presetProjectId = null, onChange, onClose }) {
    function saved() {
        onChange();
        onClose();
    }

    // Its actions (preview, copy link, send, unaccept) are in the editor's
    // toolbar at the end, so the drawer's corner holds only the close.
    return (
        <Drawer size="wide" onClose={onClose}>
            {proposal && (
                <DrawerByline>
                    <DrawerDate label="Created" date={proposal.created_at} />
                    <ProposalStatusBadge proposal={proposal} />
                </DrawerByline>
            )}
            <h2 className="drawer__title">{proposal ? proposal.title : 'New proposal'}</h2>
            <ProposalEditor
                key={proposal?.id ?? 'new'}
                proposal={proposal}
                companies={companies}
                services={services}
                presetCompanyId={presetCompanyId}
                presetProjectId={presetProjectId}
                onSaved={saved}
                onCancel={onClose}
                onChange={onChange}
            />
        </Drawer>
    );
}
