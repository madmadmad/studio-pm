import { Head, router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { Check, DownloadSimple, PencilSimple } from '@phosphor-icons/react';
import PortalLayout from '../../../Layouts/PortalLayout';
import Button from '../../../Components/Button';
import Drawer, { DrawerByline, DrawerDate } from '../../../Components/Drawer';
import EmptyState from '../../../Components/EmptyState';
import { NewThreadForm, ThreadView, threadParticipantActors } from '../../../Components/MessagesPanel';
import RichTextEditor from '../../../Components/RichTextEditor';
import RichTextView from '../../../Components/RichTextView';
import RowActions from '../../../Components/RowActions';
import { ProjectStatusBadge, TaskStatusBadge, InvoiceStatusBadge, ProposalStatusBadge } from '../../../Components/StatusBadges';
import TabToolbar from '../../../Components/TabToolbar';
import ContactCards from '../../../Components/client/ContactCards';
import { formatCurrency, formatDate, formatDateTime, formatFileSize, invoiceTotal } from '../../../lib/format';
import { api } from '../../../lib/api';
import { isBlankRichText, toRichText } from '../../../lib/richText';
import PageHeader from '../../../Components/PageHeader';
import TabBar from '../../../Components/TabBar';
import { useRememberedTab } from '../../../lib/useRememberedTab';

const TABS = ['Overview', 'Tasks', 'Messages', 'Proposals', 'Invoices', 'Team'];

const TASK_STATUS_OPTIONS = [
    { value: 'todo', label: 'To do' },
    { value: 'in_progress', label: 'In progress' },
    { value: 'done', label: 'Done' },
];

function reload() {
    router.reload({ only: ['project'] });
}

function OverviewTab({ project }) {
    return (
        <div className="card card--padded">
            <div className="portal-project__label">Status</div>
            <ProjectStatusBadge project={project} />
            {project.description && (
                <p className="portal-project__description">{project.description}</p>
            )}
        </div>
    );
}

function TaskRow({ task, onOpen }) {
    async function cycleStatus(e) {
        e.stopPropagation();
        const order = ['todo', 'in_progress', 'done'];
        const next = order[(order.indexOf(task.status) + 1) % order.length];
        await api.patch(`/api/portal/tasks/${task.id}`, { status: next });
        reload();
    }

    return (
        <div onClick={() => onOpen(task.id)} className="grid-row grid-row--action grid-row--link">
            <div className="task-list__title">
                <span className="u-truncate">{task.title}</span>
            </div>
            <div className="task-list__assignee portal-project__muted">{task.assignee || 'Unassigned'}</div>
            <div className="task-list__due portal-project__muted">{task.due_date ? formatDate(task.due_date) : '—'}</div>
            <div className="task-list__status">
                <button onClick={cycleStatus} title="Change status">
                    <TaskStatusBadge task={task} />
                </button>
            </div>
            <RowActions openLabel="Open task" />
        </div>
    );
}

// A task in the drawer, as on the staff project page: clients can change
// the title, status, due date and description (the portal API allows those
// four); assignee, subtasks and files are the studio's, shown read-only.
function TaskDrawer({ task, isNew, onClose }) {
    const [title, setTitle] = useState(task.title);
    const [description, setDescription] = useState(() => toRichText(task.description));
    const [editingDescription, setEditingDescription] = useState(isBlankRichText(task.description));
    const titleRef = useRef(null);

    // A just-created task opens with its placeholder title selected.
    useEffect(() => {
        if (isNew) titleRef.current?.select();
    }, []);

    async function updateField(field, value) {
        await api.patch(`/api/portal/tasks/${task.id}`, { [field]: value || null });
        reload();
    }

    async function saveDescription() {
        await updateField('description', isBlankRichText(description) ? null : description);
        setEditingDescription(false);
    }

    const subtasks = task.subtasks || [];
    const files = task.files || [];

    return (
        <Drawer onClose={onClose}>
            <DrawerByline>
                <DrawerDate label="Created" date={task.created_at} />
            </DrawerByline>

            <input
                ref={titleRef}
                value={title}
                onChange={(e) => setTitle(e.target.value)}
                onBlur={() => title.trim() && title !== task.title && updateField('title', title)}
                className="inline-edit inline-edit--title"
            />

            <div className="form-grid drawer__section drawer__section--divided">
                <div>
                    <div className="section-label section-label--tight">Status</div>
                    <select value={task.status} onChange={(e) => updateField('status', e.target.value)} className="input input--xs">
                        {TASK_STATUS_OPTIONS.map((s) => <option key={s.value} value={s.value}>{s.label}</option>)}
                    </select>
                </div>
                <div>
                    <div className="section-label section-label--tight">Assignee</div>
                    <div className="portal-project__value">{task.assignee || 'Unassigned'}</div>
                </div>
                <div className="form-grid__full">
                    <div className="section-label section-label--tight">Due date</div>
                    <input
                        type="date"
                        value={task.due_date ? task.due_date.slice(0, 10) : ''}
                        onChange={(e) => updateField('due_date', e.target.value)}
                        className="input input--xs"
                    />
                </div>
            </div>

            <div className="drawer__section">
                <div className="drawer__section-header">
                    <div className="section-label section-label--flush">Description</div>
                    {!editingDescription && (
                        <button onClick={() => setEditingDescription(true)} title="Edit description" className="icon-btn icon-btn--secondary">
                            <PencilSimple />
                        </button>
                    )}
                </div>
                {editingDescription ? (
                    <>
                        <RichTextEditor value={description} onChange={setDescription} />
                        <div className="form-actions form-actions--spaced">
                            <Button variant="confirm" onClick={saveDescription}>Done</Button>
                        </div>
                    </>
                ) : !isBlankRichText(description) ? (
                    <RichTextView value={description} />
                ) : (
                    <p className="drawer__empty">No description yet.</p>
                )}
            </div>

            {subtasks.length > 0 && (
                <div className="drawer__section">
                    <div className="section-label">Subtasks</div>
                    <div className="subtask-list">
                        {subtasks.map((subtask) => (
                            <div key={subtask.id} className="subtask-list__item">
                                <span className={`subtask-list__check${subtask.status === 'done' ? ' subtask-list__check--done' : ''}`}>
                                    {subtask.status === 'done' && <Check size={12} weight="bold" />}
                                </span>
                                <span className={`subtask-list__title${subtask.status === 'done' ? ' subtask-list__title--done' : ''}`}>{subtask.title}</span>
                            </div>
                        ))}
                    </div>
                </div>
            )}

            {files.length > 0 && (
                <div className="drawer__section">
                    <div className="section-label">Files</div>
                    <div className="task-files__list">
                        {files.map((file) => (
                            <div key={file.id} className="task-files__item">
                                <div className="task-files__info">
                                    <div className="task-files__name">{file.filename}</div>
                                    <div className="task-files__size">{formatFileSize(file.size)}</div>
                                </div>
                                <div className="task-files__actions">
                                    <a href={file.url} download={file.filename} title="Download" className="icon-btn icon-btn--secondary task-files__action">
                                        <DownloadSimple />
                                    </a>
                                </div>
                            </div>
                        ))}
                    </div>
                </div>
            )}
        </Drawer>
    );
}

function TasksTab({ project }) {
    const [creating, setCreating] = useState(false);
    const [newTaskId, setNewTaskId] = useState(null);
    const [selectedTaskId, setSelectedTaskId] = useState(null);
    const selectedTask = project.tasks.find((t) => t.id === selectedTaskId) || null;

    // As on the staff page: create it straight away and open its drawer,
    // with the placeholder title selected so typing replaces it.
    async function createTask() {
        setCreating(true);
        try {
            const task = await api.post(`/api/portal/projects/${project.id}/tasks`, { title: 'New task' });
            router.reload({
                only: ['project'],
                onSuccess: () => {
                    setNewTaskId(task.id);
                    setSelectedTaskId(task.id);
                },
            });
        } finally {
            setCreating(false);
        }
    }

    function closeTask() {
        setSelectedTaskId(null);
        setNewTaskId(null);
    }

    return (
        <div>
            <TabToolbar addLabel="New task" onAdd={createTask} disabled={creating} />
            <div className="card card--flush">
                {project.tasks.length === 0 ? (
                    <EmptyState text="No tasks yet." />
                ) : (
                    <>
                        <div className="grid-row grid-row--action grid-row--head">
                            <div className="task-list__title">Task</div>
                            <div className="task-list__assignee">Assignee</div>
                            <div className="task-list__due">Due date</div>
                            <div className="task-list__status">Status</div>
                            <div />
                        </div>
                        {project.tasks.map((task) => (
                            <TaskRow key={task.id} task={task} onOpen={setSelectedTaskId} />
                        ))}
                    </>
                )}
            </div>

            {selectedTask && (
                <TaskDrawer
                    key={selectedTask.id}
                    task={selectedTask}
                    isNew={selectedTask.id === newTaskId}
                    onClose={closeTask}
                />
            )}
        </div>
    );
}

const MESSAGE_ENDPOINTS = {
    reply: (id) => `/api/portal/messages/${id}/replies`,
    update: (id) => `/api/portal/messages/${id}`,
    destroy: (id) => `/api/portal/messages/${id}`,
    attachmentUrl: (id) => `/api/portal/attachments/${id}`,
    attachmentThumbnailUrl: (id) => `/api/portal/attachments/${id}/thumbnail`,
};

function messageEndpoints(project) {
    return { ...MESSAGE_ENDPOINTS, create: `/api/portal/projects/${project.id}/messages` };
}

// Who a client can write to: the studio staff on the project, and their
// colleagues who also have portal access.
function recipientOptionsFor(project, currentContact) {
    return [
        ...(project.active_users || []).map((u) => ({ token: `user:${u.id}`, name: u.name, sublabel: 'Team' })),
        ...(project.company.contacts || [])
            .filter((c) => c.has_portal_access && c.id !== currentContact?.id)
            .map((c) => ({ token: `contact:${c.id}`, name: c.name, sublabel: 'Client' })),
    ];
}

// A new message in the drawer.
function ComposeDrawer({ project, onClose }) {
    const currentContact = usePage().props.auth?.user;

    return (
        <Drawer onClose={onClose}>
            <h2 className="drawer__title">New message</h2>
            <NewThreadForm
                recipientOptions={recipientOptionsFor(project, currentContact)}
                endpoints={messageEndpoints(project)}
                onCreate={() => { onClose(); reload(); }}
                onCancel={onClose}
                bare
            />
        </Drawer>
    );
}

// Every thread listed here includes the client (the server only sends
// those), so there's no "Not joined" state as on the staff page.
function MessageThreadRow({ thread, onOpen }) {
    const participants = threadParticipantActors(thread);
    const lastActivity = thread.replies?.length ? thread.replies[thread.replies.length - 1].sent_at : thread.sent_at;

    return (
        <div onClick={() => onOpen(thread.id)} className="grid-row grid-row--action grid-row--link">
            <div className="project-messages__subject">
                <span className="u-truncate">{thread.subject}</span>
            </div>
            <div className="project-messages__participants">{participants.map((p) => p.name).join(', ') || '—'}</div>
            <div className="project-messages__date" title={formatDateTime(lastActivity)}>{formatDate(lastActivity)}</div>
            <RowActions openLabel="Open thread" />
        </div>
    );
}

// Threads list, with a thread and a new message each opening in a drawer
// -- the staff project page's Messages tab, as the client.
function MessagesTab({ project }) {
    const currentContact = usePage().props.auth?.user;
    const [composing, setComposing] = useState(false);
    const [openThreadId, setOpenThreadId] = useState(null);
    const threads = project.messages || [];
    const openThread = threads.find((t) => t.id === openThreadId) || null;

    return (
        <div>
            <TabToolbar addLabel="New message" onAdd={() => setComposing(true)} />
            <div className="card card--flush">
                {threads.length === 0 ? (
                    <EmptyState text="No messages yet." />
                ) : (
                    <>
                        <div className="grid-row grid-row--action grid-row--head">
                            <div className="project-messages__subject">Subject</div>
                            <div className="project-messages__participants">With</div>
                            <div className="project-messages__date">Last activity</div>
                            <div />
                        </div>
                        {threads.map((thread) => (
                            <MessageThreadRow key={thread.id} thread={thread} onOpen={setOpenThreadId} />
                        ))}
                    </>
                )}
            </div>

            {openThread && (
                <Drawer onClose={() => setOpenThreadId(null)}>
                    <ThreadView
                        thread={openThread}
                        currentActorType="contact"
                        currentActorId={currentContact?.id}
                        endpoints={messageEndpoints(project)}
                        onChange={reload}
                        bare
                    />
                </Drawer>
            )}

            {composing && <ComposeDrawer project={project} onClose={() => setComposing(false)} />}
        </div>
    );
}

function ProposalsTab({ project }) {
    return (
        <div className="card card--flush">
            {project.proposals.length === 0 ? (
                <EmptyState text="No accepted proposals yet." />
            ) : (
                project.proposals.map((proposal) => (
                    <div key={proposal.id} className="list-row list-row--static">
                        <div>
                            <div className="list-row__title">{proposal.title}</div>
                            <div className="list-row__meta">Accepted {formatDate(proposal.accepted_at)}</div>
                        </div>
                        <div className="list-row__aside">
                            <span className="list-row__amount">{formatCurrency(proposal.estimate_amount)}</span>
                            <ProposalStatusBadge proposal={proposal} />
                            <a
                                href={`/p/${proposal.accept_token}`}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="btn btn--confirm btn--sm"
                            >
                                Client view
                            </a>
                        </div>
                    </div>
                ))
            )}
        </div>
    );
}

function InvoicesTab({ project }) {
    return (
        <div className="card card--flush">
            {project.invoices.length === 0 ? (
                <EmptyState text="No invoices yet." />
            ) : (
                project.invoices.map((invoice) => (
                    <div key={invoice.id} className="list-row list-row--static">
                        <div>
                            <div className="list-row__title">Invoice #{invoice.invoice_number ?? invoice.id}</div>
                            <div className="list-row__meta">Issued {formatDate(invoice.issued_on)} &middot; Due {formatDate(invoice.due_on)}</div>
                        </div>
                        <div className="list-row__aside">
                            <span className="list-row__amount">{formatCurrency(invoiceTotal(invoice.items, invoice.surcharge))}</span>
                            <InvoiceStatusBadge invoice={invoice} />
                            <a
                                href={`/i/${invoice.public_token}`}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="btn btn--confirm btn--sm"
                            >
                                Client view
                            </a>
                        </div>
                    </div>
                ))
            )}
        </div>
    );
}

// The studio staff on this project, as cards like the client's own
// contacts (Contacts page): photo, name and role.
function TeamTab({ project }) {
    const people = (project.active_users || []).map((user) => ({
        id: user.id,
        name: user.name,
        role: user.role ? user.role.replace('_', ' ').replace(/^./, (c) => c.toUpperCase()) : null,
        avatar_url: user.avatar_url,
    }));

    return people.length === 0 ? (
        <div className="card card--flush">
            <EmptyState text="No staff assigned yet." />
        </div>
    ) : (
        <ContactCards contacts={people} />
    );
}

export default function PortalProjectShow({ project, canViewInvoices }) {
    // Invoices are for billing and primary contacts only.
    const tabs = canViewInvoices ? TABS : TABS.filter((t) => t !== 'Invoices');
    const [tab, setTab] = useRememberedTab('portal-project-page-tab', tabs);

    return (
        <PortalLayout>
            <Head title={project.name} />
            <PageHeader
                back={{ href: '/portal', label: project.company.name }}
                title={project.name}
                actions={<ProjectStatusBadge project={project} />}
                subtitle={project.company.name}
            />

            <TabBar tabs={tabs} tab={tab} setTab={setTab} />

            {tab === 'Overview' && <OverviewTab project={project} />}
            {tab === 'Tasks' && <TasksTab project={project} />}
            {tab === 'Messages' && <MessagesTab project={project} />}
            {tab === 'Proposals' && <ProposalsTab project={project} />}
            {tab === 'Invoices' && <InvoicesTab project={project} />}
            {tab === 'Team' && <TeamTab project={project} />}
        </PortalLayout>
    );
}
