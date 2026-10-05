// The studio's About section closing a proposal (Settings > Proposals),
// under a rule like the team section. `about` is the server's
// aboutSection() -- null when the proposal leaves it out.
export default function ProposalAbout({ about }) {
    if (!about) return null;

    return (
        <div className="document__about">
            <div className="document__section-title">{about.heading}</div>
            <div className="prose document__about-body" dangerouslySetInnerHTML={{ __html: about.body }} />
        </div>
    );
}
