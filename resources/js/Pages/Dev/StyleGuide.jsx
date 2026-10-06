import { useEffect, useRef, useState } from 'react';
import { Head } from '@inertiajs/react';
import { CaretRight, CheckCircle, Copy, DownloadSimple, Eye, PaperPlaneTilt, PencilSimple, Trash } from '@phosphor-icons/react';
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
            <div className="style-guide__swatch-chip" style={{ background: `var(${varName})` }} />
            <div>
                <div className="style-guide__swatch-name">{name}</div>
                <div className="style-guide__caption">{varName}</div>
            </div>
        </div>
    );
}

// The intents -- see base/_tokens.scss.
const INTENTS = ['primary', 'secondary', 'accent', 'success', 'danger', 'warning', 'info'];

const capitalize = (word) => word[0].toUpperCase() + word.slice(1);

// The color tokens by kind, in the order base/_tokens.scss defines them.
// Intents come one to a row (base, hover, soft, text, on) in a five-column
// grid, so the columns line up across them.
const COLOR_GROUPS = [
    {
        title: 'Brand',
        note: 'One color in -- yours (Profile), or on what a client sees, theirs -- and each of these worked out from it by app/Support/BrandPalette, nudged lighter or darker only as far as contrast needs. Only the role tokens below reference these -- components never do. Danger keeps its own red whatever the brand color.',
        colors: [
            ['Brand', '--brand'],
            ['Hover', '--brand-hover'],
            ['On', '--brand-on'],
            ['Text on dark', '--brand-text-dark'],
            ['Text on light', '--brand-text-light'],
            ['Icon on dark', '--brand-icon-dark'],
            ['Icon on light', '--brand-icon-light'],
            ['Quiet on dark', '--brand-quiet-dark'],
            ['Quiet on light', '--brand-quiet-light'],
            ['Danger', '--danger'],
        ],
    },
    {
        title: 'Intents',
        note: 'By meaning, never hue -- the brand color plus danger\'s red, with grey separating the other states. Base fills; -hover under the pointer; -soft (low alpha) for badge and banner backgrounds; -text for the intent as text on a dark surface or on its -soft; -on for text on a base fill.',
        columns: 5,
        colors: INTENTS.flatMap((intent) => [
            [capitalize(intent), `--color-${intent}`],
            [`${capitalize(intent)} hover`, `--color-${intent}-hover`],
            [`${capitalize(intent)} soft`, `--color-${intent}-soft`],
            [`${capitalize(intent)} text`, `--color-${intent}-text`],
            [`${capitalize(intent)} on`, `--color-${intent}-on`],
        ]),
    },
    {
        title: 'Marks',
        note: 'Red with no text of its own: the keyboard focus ring, and the gradient on the headline metric card.',
        colors: [
            ['Indicator', '--color-indicator'],
            ['Focus ring', '--color-focus-ring'],
            ['Primary gradient', '--gradient-primary'],
        ],
    },
    {
        title: 'Text',
        note: 'Off-white body text, secondary text (labels, meta), tertiary text (disabled only -- below AA on raised surfaces), and text on a colored fill.',
        colors: [
            ['Text', '--color-text'],
            ['Text muted', '--color-text-muted'],
            ['Text subtle', '--color-text-subtle'],
            ['Text inverse', '--color-text-inverse'],
        ],
    },
    {
        title: 'Surfaces and border',
        note: 'Darkest first -- in a dark UI, higher means lighter: the page; cards; filled cards and hover rows; menus and modals; neutral chips. Fields have no border: a faint white lift over whatever they sit on. The border is a low-contrast divider; border strong is the toggle\'s off track and an inline edit\'s edge while you edit it.',
        colors: [
            ['Background', '--color-bg'],
            ['Surface', '--color-surface'],
            ['Surface subtle', '--color-surface-subtle'],
            ['Surface raised', '--color-surface-raised'],
            ['Surface muted', '--color-surface-muted'],
            ['Field', '--color-field'],
            ['Border', '--color-border'],
            ['Border strong', '--color-border-strong'],
        ],
    },
    {
        title: 'Canvas',
        note: 'The darkest layer, which the sidebar sits on; text and rules on it; and the panel each page sits in (separated by a dithered shadow image, resources/images/panel-shadow.png).',
        colors: [
            ['Canvas', '--color-canvas'],
            ['On canvas', '--color-on-canvas'],
            ['On canvas strong', '--color-on-canvas-strong'],
            ['On canvas muted', '--color-on-canvas-muted'],
            ['On canvas subtle', '--color-on-canvas-subtle'],
            ['On canvas border', '--color-on-canvas-border'],
            ['On canvas fill', '--color-on-canvas-fill'],
            ['On canvas fill hover', '--color-on-canvas-fill-hover'],
            ['Panel', '--color-panel'],
        ],
    },
    {
        title: 'Overlays',
        note: 'Backdrops behind a drawer or modal, a modal on a modal, and the image lightbox.',
        colors: [
            ['Scrim', '--color-scrim'],
            ['Scrim strong', '--color-scrim-strong'],
            ['Scrim heavy', '--color-scrim-heavy'],
        ],
    },
    {
        title: 'Decorative',
        note: 'Fills with no meaning of their own -- initials avatars pick from these.',
        colors: [
            ['Decorative 1', '--color-decorative-1'],
            ['Decorative 2', '--color-decorative-2'],
            ['Decorative 3', '--color-decorative-3'],
        ],
    },
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

const TYPE_SIZES = ['2xs', 'xs', 'sm', 'base', 'lg', 'xl', '2xl'];

// One sample line per type size, with the tracking the letter-spacing
// curve gives it -- read back from the rendered text, so this shows what
// the real CSS produces as the two tokens are tuned.
function TrackingSamples() {
    const refs = useRef({});
    const [measured, setMeasured] = useState({});

    useEffect(() => {
        setMeasured(Object.fromEntries(TYPE_SIZES.map((size) => {
            const style = getComputedStyle(refs.current[size]);
            const fontSize = parseFloat(style.fontSize);
            const spacing = parseFloat(style.letterSpacing) || 0;
            return [size, { fontSize, spacing, em: spacing / fontSize }];
        })));
    }, []);

    return (
        <div className="style-guide__tracking">
            {TYPE_SIZES.map((size) => (
                <div key={size} className="style-guide__tracking-row">
                    <span
                        ref={(el) => { refs.current[size] = el; }}
                        style={{ fontSize: `var(--font-size-${size})`, lineHeight: `var(--line-height-${size})` }}
                    >
                        Studio operations
                    </span>
                    <span className="style-guide__caption">
                        --font-size-{size}
                        {measured[size] && ` · ${measured[size].fontSize}px · ${measured[size].spacing.toFixed(2)}px (${measured[size].em.toFixed(3)}em)`}
                    </span>
                </div>
            ))}
        </div>
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
                <Section title="Colors" description="Defined in base/_tokens.scss, consumed everywhere via var(--color-...). Named by role, so any can be re-pointed without renaming.">
                    {COLOR_GROUPS.map((group) => (
                        <div key={group.title} className="style-guide__color-group">
                            <div className="section-label section-label--ruled">{group.title}</div>
                            <p className="style-guide__description">{group.note}</p>
                            <div className={`style-guide__swatches${group.columns === 5 ? ' style-guide__swatches--five' : ''}`}>
                                {group.colors.map(([name, varName]) => (
                                    <Swatch key={varName} name={name} varName={varName} />
                                ))}
                            </div>
                        </div>
                    ))}
                </Section>

                <Section title="Tracking" description="Letter-spacing follows font size: scale × size + offset, set on every element in base/_elements.scss. Tune the curve with --letter-spacing-scale and --letter-spacing-offset in base/_tokens.scss. Display headings opt out with --letter-spacing-display.">
                    <TrackingSamples />
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

                <Section title="Buttons" description=".btn plus one color modifier (.btn--primary, --confirm...), or .link-btn for an inline text action. Use the Button component or apply the classes directly. Action buttons (Primary, Confirm, Accent) are red; Secondary -- Cancel, Save as draft and the like -- is a subtle grey fill; Danger is grey with a trash glyph and only turns red on hover.">
                    <div className="style-guide__row style-guide__row--spaced">
                        <Button variant="confirm" className="btn--sm">Small</Button>
                        <Button variant="primary">Primary</Button>
                        <Button variant="confirm">Confirm</Button>
                        <Button variant="accent">Accent</Button>
                        <Button variant="secondary">Secondary</Button>
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
                        <IconButtonExample icon={<PencilSimple />} label="Edit" variant="edit" />
                        <IconButtonExample icon={<CheckCircle />} label="Mark paid" variant="confirm" />
                        <IconButtonExample icon={<PaperPlaneTilt />} label="Send" variant="accent" />
                        <IconButtonExample icon={<Trash />} label="Delete" variant="danger" />
                    </div>
                </Section>

                <Section title="Cards" description="The .card class -- a panel a step lighter than the page, lifted by a soft shadow (--shadow-card) rather than a border. Add .card--padded (or the Card component's default) for inner spacing. A MetricCard's primary tone is the red gradient for a headline figure; its danger tone is grey, edged in red, with a warning glyph -- it often sits beside a primary card, so it can't be red too.">
                    <div className="style-guide__grid">
                        <MetricCard label="Open invoices" value="12" />
                        <MetricCard label="Hours remaining" value="137.5h" tone="primary" />
                        <MetricCard label="Overdue" value="$2,400.00" tone="danger" />
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

                <Section title="Badges" description="A badge's tone is neutral or an intent (Components/Badge.jsx). Neutral is a grey fill with muted text (Draft); accent, warning, info and primary a grey fill with light text (Sent, In progress, the Primary contact label); success solid red (Paid, Active, Accepted); danger solid red with a warning glyph (Overdue).">
                    <div className="style-guide__row style-guide__row--compact">
                        <Badge tone="neutral" label="Neutral" />
                        {INTENTS.map((intent) => (
                            <Badge key={intent} tone={intent} label={intent[0].toUpperCase() + intent.slice(1)} />
                        ))}
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
                                <td><Badge tone="success" label="Active" /></td>
                            </tr>
                            <tr>
                                <td className="style-guide__strong">Brand refresh</td>
                                <td>Globex</td>
                                <td><Badge tone="accent" label="Estimated" /></td>
                            </tr>
                        </tbody>
                    </table>

                    <p className="style-guide__note style-guide__note--after-table">
                        A row ends with .row-action, the open caret: grey at rest, red on hover. Tab and toolbar counts (.count) are grey:
                    </p>
                    <div className="style-guide__row style-guide__row--compact">
                        <button title="Open" className="row-action">
                            <CaretRight size={14} weight="bold" />
                        </button>
                        <span className="count">4</span>
                    </div>

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

                <Section title="Feedback" description="Errors are red with a warning glyph, so they never read as brand-red text; success is neutral, since a red box would read as an error.">
                    <div className="alert alert--danger">This invoice can't be sent until it has a contact.</div>
                    <div className="alert alert--success">Payment recorded.</div>
                    <div className="alert alert--info">This invoice has already been sent.</div>
                    <div className="form-error">Enter a valid email address.</div>
                </Section>

                <Section title="Avatars" description="Avatar.jsx -- a photo when one's uploaded, otherwise initials on a fill picked deterministically from the author's id (see --color-decorative-* in base/_tokens.scss).">
                    <div className="style-guide__row style-guide__row--loose">
                        <Avatar name="Bill Sattler" id={1} size={40} />
                        <Avatar name="Casey Client" id={2} size={40} />
                        <Avatar name="Robin Teammate" id={3} size={40} />
                        <Avatar name="Sam Bystander" id={4} size={40} />
                    </div>
                </Section>

                <Section title="Messages" description="The .message__body (plain text, lined up with the sender name) and .attachment-chip (a non-image file inline in a message) -- see MessagesPanel.jsx.">
                    <div className="message__body style-guide__message">
                        This is what a message body looks like -- soft background, generous padding, no heavy border.
                    </div>
                    <AttachmentChip attachment={{ original_name: 'brand-brief.pdf', size: 245000 }} downloadUrl="#" />
                </Section>
            </div>
        </AppLayout>
    );
}
