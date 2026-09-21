import { Head } from '@inertiajs/react';
import { CheckCircle, Copy, DownloadSimple, Eye, PaperPlaneTilt, PencilSimple, Trash } from '@phosphor-icons/react';
import AppLayout from '../../Layouts/AppLayout';
import Button from '../../Components/Button';
import Card from '../../Components/Card';
import Badge from '../../Components/Badge';

function Section({ title, description, children }) {
    return (
        <section className="mb-10">
            <h2 className="font-display text-lg font-semibold mb-1">{title}</h2>
            {description && <p className="text-sm text-shadow-grey mb-4">{description}</p>}
            {children}
        </section>
    );
}

function IconButtonExample({ icon, label, variant }) {
    return (
        <div className="flex flex-col items-center gap-2">
            <button title={label} className={`icon-btn ${variant}`}>
                {icon}
            </button>
            <div className="text-xs text-shadow-grey text-center">{label}</div>
        </div>
    );
}

function Swatch({ name, varName }) {
    return (
        <div className="flex items-center gap-3">
            <div className="w-10 h-10 rounded border border-border" style={{ backgroundColor: `var(${varName})` }} />
            <div>
                <div className="text-sm font-medium">{name}</div>
                <div className="text-xs text-shadow-grey">{varName}</div>
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
    ['Fuchsia', '--color-fuchsia'],
    ['Fuchsia soft', '--color-fuchsia-soft'],
];

export default function StyleGuide() {
    return (
        <AppLayout>
            <Head title="Style Guide" />
            <h1 className="font-display text-2xl font-semibold mb-1">Style guide</h1>
            <p className="text-sm text-shadow-grey mb-8">
                A live reference of the shared UI classes in resources/css/components.css and resources/css/tokens.css.
                Every element below is rendered from the real, app-wide CSS -- edit those files and this page updates
                with everything else.
            </p>

            <Section title="Colors" description="Defined in tokens.css, consumed everywhere via var(--color-...).">
                <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
                    {COLORS.map(([name, varName]) => (
                        <Swatch key={varName} name={name} varName={varName} />
                    ))}
                </div>
            </Section>

            <Section title="Buttons" description="One .btn base + a variant class. Use the Button component or apply the classes directly.">
                <div className="flex flex-wrap items-center gap-3 mb-3">
                    <Button variant="confirm" className="btn-sm">Small</Button>
                    <Button variant="primary">Primary</Button>
                    <Button variant="confirm">Confirm</Button>
                    <Button variant="accent">Accent</Button>
                    <Button variant="secondary">Secondary</Button>
                    <Button variant="outline">Outline</Button>
                    <Button variant="danger">Danger</Button>
                    <Button variant="link">Link</Button>
                    <Button variant="link-accent">Link accent</Button>
                    <Button variant="link-danger">Link danger</Button>
                </div>
                <div className="flex flex-wrap items-center gap-3">
                    <Button variant="primary" disabled>Primary (disabled)</Button>
                    <Button variant="confirm" disabled>Confirm (disabled)</Button>
                </div>
            </Section>

            <Section title="Icon buttons" description="A bare icon as a row action -- .icon-btn plus exactly one variant. Used for Preview, Copy, Download, Edit, Mark paid, Send, and Delete throughout invoices, proposals, expenses, and project tabs.">
                <div className="flex flex-wrap items-start gap-6">
                    <IconButtonExample icon={<Eye size={20} />} label="Preview" variant="icon-btn-secondary" />
                    <IconButtonExample icon={<Copy size={20} />} label="Copy link" variant="icon-btn-secondary" />
                    <IconButtonExample icon={<DownloadSimple size={20} />} label="Download" variant="icon-btn-secondary" />
                    <IconButtonExample icon={<PencilSimple size={20} />} label="Edit" variant="icon-btn-confirm" />
                    <IconButtonExample icon={<CheckCircle size={20} />} label="Mark paid" variant="icon-btn-confirm" />
                    <IconButtonExample icon={<PaperPlaneTilt size={20} />} label="Send" variant="icon-btn-accent" />
                    <IconButtonExample icon={<Trash size={20} />} label="Delete" variant="icon-btn-danger" />
                </div>
            </Section>

            <Section title="Cards" description="The .card class -- a white, bordered panel. Add .card-padded (or the Card component's default) for inner spacing.">
                <div className="grid grid-cols-2 gap-4 max-w-lg">
                    <Card>
                        <div className="text-xs mb-1 text-shadow-grey">Open invoices</div>
                        <div className="tabular-nums text-2xl font-medium">12</div>
                    </Card>
                    <Card padded={false}>
                        <div className="p-4 text-sm text-shadow-grey">card without card-padded</div>
                    </Card>
                </div>
            </Section>

            <Section title="Form fields" description="The .field and .field-label classes cover inputs, selects, and textareas.">
                <div className="grid grid-cols-2 gap-4 max-w-md">
                    <div>
                        <label className="field-label">Client name</label>
                        <input className="field" placeholder="Acme Co." />
                    </div>
                    <div>
                        <label className="field-label">Status</label>
                        <select className="field">
                            <option>Active</option>
                            <option>Inactive</option>
                        </select>
                    </div>
                    <div>
                        <label className="field-label">Read-only</label>
                        <input className="field" value="fixed@example.com" readOnly />
                    </div>
                </div>
                <p className="text-sm text-shadow-grey mt-4 mb-2">
                    Add .field-sm for a field sitting inline next to a button (matches .btn's height):
                </p>
                <div className="flex items-center gap-2 mb-4">
                    <select className="field field-sm w-auto">
                        <option>Check</option>
                        <option>Other</option>
                    </select>
                    <Button variant="confirm">Record payment</Button>
                </div>
                <p className="text-sm text-shadow-grey mb-2">
                    .field-xs is more compact still, for a control embedded directly in a table row:
                </p>
                <select className="field field-xs w-auto">
                    <option>Team Member</option>
                    <option>Manager</option>
                </select>
            </Section>

            <Section title="Badges" description="Tone -> color mapping lives in Components/Badge.jsx.">
                <div className="flex flex-wrap items-center gap-2">
                    <Badge tone="neutral" label="Neutral" />
                    <Badge tone="watermelon" label="Watermelon" />
                    <Badge tone="fern" label="Fern" />
                    <Badge tone="fuchsia" label="Fuchsia" />
                </div>
            </Section>

            <Section title="Table" description="Apply .table to the <table> element -- header, row, and cell styling follow automatically.">
                <table className="table max-w-lg">
                    <thead>
                        <tr>
                            <th>Project</th>
                            <th>Client</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td className="font-medium">Studio site redesign</td>
                            <td>Acme Co.</td>
                            <td><Badge tone="fern" label="Active" /></td>
                        </tr>
                        <tr>
                            <td className="font-medium">Brand refresh</td>
                            <td>Globex</td>
                            <td><Badge tone="watermelon" label="Estimated" /></td>
                        </tr>
                    </tbody>
                </table>

                <p className="text-sm text-shadow-grey mt-6 mb-2">
                    Add .table-flush when the table is nested inside an already-padded .card (no horizontal cell padding, tighter rows):
                </p>
                <Card>
                    <table className="table table-flush max-w-lg">
                        <thead>
                            <tr>
                                <th>Description</th>
                                <th className="text-right">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>Design</td>
                                <td className="text-right tabular-nums">$1,200.00</td>
                            </tr>
                        </tbody>
                    </table>
                </Card>
            </Section>
        </AppLayout>
    );
}
