import BioPhoto from './BioPhoto';

// A proposal's team section -- heading, then each person's photo, name,
// position and bio -- as the client sees it: the public proposal page and
// the read-only view on a project. `team` is the server's teamSection()
// (bios already cut down to safe HTML), or null when there's none.
export default function ProposalTeam({ team }) {
    if (!team?.members?.length) return null;

    return (
        <div className="document__team">
            <div className="document__section-title">{team.heading}</div>
            {team.members.map((member) => (
                <div key={member.id} className="document__member">
                    <BioPhoto name={member.name} url={member.photo_url} />
                    <div>
                        <div className="document__member-name">{member.name}</div>
                        {member.job_title && <div className="document__member-title">{member.job_title}</div>}
                        {member.bio && <div className="prose document__member-bio" dangerouslySetInnerHTML={{ __html: member.bio }} />}
                    </div>
                </div>
            ))}
        </div>
    );
}
