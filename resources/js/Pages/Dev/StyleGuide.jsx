import { useEffect, useState } from 'react';
import { Head } from '@inertiajs/react';
import { CheckCircle, Copy, DownloadSimple, Eye, PaperPlaneTilt, PencilSimple, Trash } from '@phosphor-icons/react';
import AppLayout from '../../Layouts/AppLayout';
import Button from '../../Components/Button';
import Card from '../../Components/Card';
import Badge from '../../Components/Badge';
import Avatar from '../../Components/Avatar';
import AttachmentChip from '../../Components/AttachmentChip';
import MetricCard from '../../Components/MetricCard';
import PageHeader from '../../Components/PageHeader';

function Section({ title, description, children }) {
    return (
        <section className="style-guide__section">
            <h2 className="style-guide__heading">{title}</h2>
            {description && <p className="style-guide__description">{description}</p>}
            {children}
        </section>
    );
}

function IconButtonExample({ icon, label, variant }) {
    return (
        <div className="style-guide__icon-example">
            <button title={label} className={`icon-btn icon-btn--${variant}`}>
                {icon}
            </button>
            <div className="style-guide__caption">{label}</div>
        </div>
    );
}

function Swatch({ name, varName }) {
    return (
        <div className="style-guide__swatch">
            <div className="style-guide__swatch-chip" style={{ backgroundColor: `var(${varName})` }} />
            <div>
                <div className="style-guide__swatch-name">{name}</div>
                <div className="style-guide__caption">{varName}</div>
            </div>
        </div>
    );
}

const COLORS = [
    ['Gunmetal', '--color-gunmetal'],
    ['Gunmetal light', '--color-gunmetal-light'],
    ['Porcelain', '--color-porcelain'],
    ['Border', '--color-border'],
    ['Mist', '--color-mist'],
    ['Shadow grey', '--color-shadow-grey'],
    ['Watermelon', '--color-watermelon'],
    ['Watermelon soft', '--color-watermelon-soft'],
    ['Fern', '--color-fern'],
    ['Fern soft', '--color-fern-soft'],
];

const SHELL_COLORS = [
    ['Canvas', '--color-canvas'],
    ['Panel', '--color-panel'],
];

const SHELL_TOKENS = [
    ['--radius-panel', 'Content panel left corners'],
    ['--radius-drawer', 'Drawer leading edge'],
    ['--shadow-drawer', 'Drawer over the panel'],
    ['--duration-panel', 'Panel slide-in on page navigation'],
    ['--duration-drawer', 'Drawer slide in and out'],
    ['--ease-exit', 'Drawer closing'],
    ['--motion-panel-offset', 'Panel slide distance'],
];

// Reads each token's live value from :root, so the table can't drift
// from base/_tokens.scss.
function ShellTokenTable() {
    const [values, setValues] = useState({});

    useEffect(() => {
        const styles = getComputedStyle(document.documentElement);
        setValues(Object.fromEntries(SHELL_TOKENS.map(([name]) => [name, styles.getPropertyValue(name).trim()])));
    }, []);

    return (
        <table className="table style-guide__table">
            <thead>
                <tr>
                    <th>Token</th>
                    <th>Value</th>
                    <th>Used for</th>
                </tr>
            </thead>
            <tbody>
                {SHELL_TOKENS.map(([name, use]) => (
                    <tr key={name}>
                        <td className="style-guide__strong">{name}</td>
                        <td>{values[name]}</td>
                        <td>{use}</td>
                    </tr>
                ))}
            </tbody>
        </table>
    );
}

export default function StyleGuide() {
    return (
        <AppLayout>
            <Head title="Style Guide" />
            <PageHeader
                title="Style guide"
                subtitle="A live reference of the shared components in resources/scss/components/ and the tokens in resources/scss/base/_tokens.scss. Everything below is rendered from the real, app-wide CSS -- edit those files and this page updates with everything else."
            />

            <div className="style-guide">
                <Section title="Colors" description="Defined in base/_tokens.scss, consumed everywhere via var(--color-...).">
                    <div className="style-guide__swatches">
                        {COLORS.map(([name, varName]) => (
                            <Swatch key={varName} name={name} varName={varName} />
                        ))}
                    </div>
                </Section>

                <Section title="Shell" description="The app frame's three layers, back to front: canvas, content panel, drawer. Tokens in base/_tokens.scss; used by layout/_app-shell.scss and components/_drawer.scss only.">
                    <div className="style-guide__swatches">
                        {SHELL_COLORS.map(([name, varName]) => (
                            <Swatch key={varName} name={name} varName={varName} />
                        ))}
                    </div>
                    <div className="style-guide__shell-demo">
                        <div className="style-guide__shell-panel">Panel</div>
                        <div className="style-guide__shell-drawer">Drawer</div>
                    </div>
                    <ShellTokenTable />
                </Section>

                <Section title="Buttons" description=".btn plus one color modifier (.btn--primary, --confirm...), or .link-btn for an inline text action. Use the Button component or apply the classes directly.">
                    <div className="style-guide__row style-guide__row--spaced">
                        <Button variant="confirm" className="btn--sm">Small</Button>
                        <Button variant="primary">Primary</Button>
                        <Button variant="confirm">Confirm</Button>
                        <Button variant="accent">Accent</Button>
                        <Button variant="secondary">Secondary</Button>
                        <Button variant="outline">Outline</Button>
                        <Button variant="danger">Danger</Button>
                        <Button variant="link">Link</Button>
                        <Button variant="link-accent">Link accent</Button>
                    </div>
                    <div className="style-guide__row">
                        <Button variant="primary" disabled>Primary (disabled)</Button>
                        <Button variant="confirm" disabled>Confirm (disabled)</Button>
                    </div>
                </Section>

                <Section title="Icon buttons" description="A bare icon as a row action -- .icon-btn plus exactly one modifier. Used for Preview, Copy, Download, Edit, Mark paid, Send, and Delete throughout invoices, proposals, expenses, and project tabs.">
                    <div className="style-guide__icon-examples">
                        <IconButtonExample icon={<Eye />} label="Preview" variant="secondary" />
                        <IconButtonExample icon={<Copy />} label="Copy link" variant="secondary" />
                        <IconButtonExample icon={<DownloadSimple />} label="Download" variant="secondary" />
                        <IconButtonExample icon={<PencilSimple />} label="Edit" variant="confirm" />
                        <IconButtonExample icon={<CheckCircle />} label="Mark paid" variant="confirm" />
                        <IconButtonExample icon={<PaperPlaneTilt />} label="Send" variant="accent" />
                        <IconButtonExample icon={<Trash />} label="Delete" variant="danger" />
                    </div>
                </Section>

                <Section title="Cards" description="The .card class -- a white, bordered panel. Add .card--padded (or the Card component's default) for inner spacing.">
                    <div className="style-guide__grid">
                        <MetricCard label="Open invoices" value="12" />
                        <Card padded={false}>
                            <div className="style-guide__unpadded">card without card--padded</div>
                        </Card>
                    </div>
                </Section>

                <Section title="Form fields" description=".input covers inputs, selects, and textareas; pair each with a .label.">
                    <div className="style-guide__grid style-guide__grid--narrow">
                        <div>
                            <label className="label">Client name</label>
                            <input className="input" placeholder="Acme Co." />
                        </div>
                        <div>
                            <label className="label">Status</label>
                            <select className="input">
                                <option>Active</option>
                                <option>Inactive</option>
                            </select>
                        </div>
                        <div>
                            <label className="label">Read-only</label>
                            <input className="input" value="fixed@example.com" readOnly />
                        </div>
                    </div>
                    <p className="style-guide__note style-guide__note--after-block">
                        Add .input--sm for a field sitting inline next to a button (matches .btn's height), and .input--inline to size it to its content:
                    </p>
                    <div className="style-guide__row style-guide__row--tight">
                        <select className="input input--sm input--inline">
                            <option>Check</option>
                            <option>Other</option>
                        </select>
                        <Button variant="confirm">Record payment</Button>
                    </div>
                    <p className="style-guide__note">
                        .input--xs is more compact still, for a control embedded directly in a table row:
                    </p>
                    <select className="input input--xs input--inline">
                        <option>Team Member</option>
                        <option>Manager</option>
                    </select>
                </Section>

                <Section title="Badges" description="Tone -> color mapping lives in Components/Badge.jsx (.badge--neutral, --watermelon, --fern).">
                    <div className="style-guide__row style-guide__row--compact">
                        <Badge tone="neutral" label="Neutral" />
                        <Badge tone="watermelon" label="Watermelon" />
                        <Badge tone="fern" label="Fern" />
                    </div>
                </Section>

                <Section title="Table" description="Apply .table to the <table> element -- header, row, and cell styling follow automatically.">
                    <table className="table style-guide__table">
                        <thead>
                            <tr>
                                <th>Project</th>
                                <th>Client</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td className="style-guide__strong">Studio site redesign</td>
                                <td>Acme Co.</td>
                                <td><Badge tone="fern" label="Active" /></td>
                            </tr>
                            <tr>
                                <td className="style-guide__strong">Brand refresh</td>
                                <td>Globex</td>
                                <td><Badge tone="watermelon" label="Estimated" /></td>
                            </tr>
                        </tbody>
                    </table>

                    <p className="style-guide__note style-guide__note--after-table">
                        Add .table--flush when the table is nested inside an already-padded .card (no horizontal cell padding, tighter rows):
                    </p>
                    <Card>
                        <table className="table table--flush style-guide__table">
                            <thead>
                                <tr>
                                    <th>Description</th>
                                    <th className="style-guide__numeric">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>Design</td>
                                    <td className="style-guide__numeric u-tabular-nums">$1,200.00</td>
                                </tr>
                            </tbody>
                        </table>
                    </Card>
                </Section>

                <Section title="Avatars" description="Avatar.jsx -- a photo when one's uploaded, otherwise initials on a color picked deterministically from the author's id (see --color-avatar-* in base/_tokens.scss).">
                    <div className="style-guide__row style-guide__row--loose">
                        <Avatar name="Bill Sattler" id={1} size={40} />
                        <Avatar name="Casey Client" id={2} size={40} />
                        <Avatar name="Robin Teammate" id={3} size={40} />
                        <Avatar name="Sam Bystander" id={4} size={40} />
                    </div>
                </Section>

                <Section title="Messages" description="The .message__card body (soft fill, no border) and .attachment-chip (a non-image file inline in a message) -- see MessagesPanel.jsx.">
                    <div className="message__card style-guide__message">
                        This is what a message body looks like -- soft background, generous padding, no heavy border.
                    </div>
                    <AttachmentChip attachment={{ original_name: 'brand-brief.pdf', size: 245000 }} downloadUrl="#" />
                </Section>
            </div>
        </AppLayout>
    );
}
