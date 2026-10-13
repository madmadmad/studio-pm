import { useEffect, useRef, useState } from 'react';
import { addDays, daysBetween, formatShort, startOfWeek, toDay } from '../../lib/scheduleDates';
import { todayInAppTimezone } from '../../lib/format';

const STATUS_LABELS = { todo: 'To do', in_progress: 'In progress', done: 'Done' };
// Pointer travel (px) before a press on a bar counts as a drag, not a click.
const DRAG_THRESHOLD = 3;

// The Schedule tab's gantt chart: one row per item, a bar across its date
// range on a shared timeline. The timeline runs from the Monday on or
// before the earliest date to the Sunday on or after the latest, with a
// gridline and label each week and a line at today. Bars and labels are
// placed by percentage of the timeline, so the chart fills its width (and
// scrolls sideways below a minimum on narrow screens). `onOpen(item)` runs
// when a bar or its label is clicked.
//
// `tasks` with a due date are markers on the timeline: in their schedule
// item's row, or an "Other tasks" row for those without one. `onOpenTask`
// runs when one is clicked.
//
// With `onChangeDates(item, starts_on, ends_on)` the bars can be dragged:
// the middle moves the item, either end moves just that date, snapping to
// days. The timeline gets a spare week each side to drag into, and holds
// its scale until the drop.
export default function ScheduleChart({ items, tasks = [], onOpen, onOpenTask, onChangeDates }) {
    const editable = Boolean(onChangeDates);
    const [drag, setDrag] = useState(null);
    // The drag as of the last pointer move, for the drop to read.
    const dragRef = useRef(null);
    dragRef.current = drag;
    // Set when a drag ends, so the click that follows doesn't open the item.
    const dragged = useRef(false);

    const dated = tasks.filter((t) => t.due_date).map((t) => ({ ...t, due: t.due_date.slice(0, 10) }));
    const itemIds = new Set(items.map((i) => i.id));
    const tasksFor = (item) => dated.filter((t) => t.schedule_item_id === item.id);
    const otherTasks = dated.filter((t) => !itemIds.has(t.schedule_item_id));

    const starts = [...items.map((i) => i.starts_on), ...dated.map((t) => t.due)];
    const ends = [...items.map((i) => i.ends_on), ...dated.map((t) => t.due)];
    const pad = editable ? 7 : 0;
    const rangeStart = addDays(startOfWeek(starts.reduce((min, d) => (d < min ? d : min))), -pad);
    const rangeEnd = addDays(startOfWeek(ends.reduce((max, d) => (d > max ? d : max))), 6 + pad);
    const totalDays = daysBetween(rangeStart, rangeEnd);

    const pct = (days) => `${(days / totalDays) * 100}%`;
    const offset = (date) => daysBetween(rangeStart, date) - 1;

    const weeks = [];
    for (let week = rangeStart; week <= rangeEnd; week = addDays(week, 7)) weeks.push(week);

    const today = todayInAppTimezone();
    const showToday = toDay(today) >= toDay(rangeStart) && toDay(today) <= toDay(rangeEnd);

    // The dates a drag would give its item, kept on the timeline and with
    // the end never before the start.
    function dragDates({ item, mode, days }) {
        const minShift = daysBetween(item.starts_on, rangeStart) - 1;
        const maxShift = daysBetween(item.ends_on, rangeEnd) - 1;
        const length = daysBetween(item.starts_on, item.ends_on) - 1;
        if (mode === 'move') {
            const shift = Math.min(Math.max(days, minShift), maxShift);
            return { starts_on: addDays(item.starts_on, shift), ends_on: addDays(item.ends_on, shift) };
        }
        if (mode === 'start') {
            return { starts_on: addDays(item.starts_on, Math.min(Math.max(days, minShift), length)), ends_on: item.ends_on };
        }
        return { starts_on: item.starts_on, ends_on: addDays(item.ends_on, Math.max(Math.min(days, maxShift), -length)) };
    }

    function startDrag(e, item, mode) {
        if (!editable || e.button !== 0) return;
        e.preventDefault();
        e.stopPropagation();
        const track = e.currentTarget.closest('.schedule-chart__track');
        setDrag({ item, mode, x0: e.clientX, dayWidth: track.getBoundingClientRect().width / totalDays, days: 0, moved: false });
    }

    useEffect(() => {
        if (!drag) return undefined;

        function move(e) {
            const dx = e.clientX - drag.x0;
            setDrag((d) => d && { ...d, days: Math.round(dx / d.dayWidth), moved: d.moved || Math.abs(dx) > DRAG_THRESHOLD });
        }

        function end() {
            const d = dragRef.current;
            setDrag(null);
            if (!d?.moved) return;
            // The click lands right after pointerup, if at all.
            dragged.current = true;
            setTimeout(() => { dragged.current = false; }, 0);
            const next = dragDates(d);
            if (next.starts_on !== d.item.starts_on || next.ends_on !== d.item.ends_on) {
                onChangeDates(d.item, next.starts_on, next.ends_on);
            }
        }

        window.addEventListener('pointermove', move);
        window.addEventListener('pointerup', end);
        window.addEventListener('pointercancel', end);
        return () => {
            window.removeEventListener('pointermove', move);
            window.removeEventListener('pointerup', end);
            window.removeEventListener('pointercancel', end);
        };
    }, [drag?.item.id, drag?.mode, drag?.x0]);

    function openItem(item) {
        if (!dragged.current) onOpen(item);
    }

    function gridlines() {
        return weeks.map((week) => <span key={week} className="schedule-chart__gridline" style={{ left: pct(offset(week)) }} />);
    }

    function markers(rowTasks) {
        return rowTasks.map((task) => {
            const overdue = task.status !== 'done' && task.due < today;
            const status = overdue ? 'Overdue' : STATUS_LABELS[task.status] ?? task.status;
            const props = {
                title: `${task.title}: due ${formatShort(task.due)} · ${status}`,
                className: `schedule-chart__task schedule-chart__task--${overdue ? 'overdue' : task.status}`,
                style: { left: pct(offset(task.due) + 0.5) },
            };
            // Only a button when there's a drawer to open.
            return onOpenTask
                ? <button key={task.id} {...props} type="button" onClick={() => onOpenTask(task)} aria-label={`${task.title}, due ${formatShort(task.due)}, ${status}`} />
                : <span key={task.id} {...props} />;
        });
    }

    return (
        <div className={`schedule-chart${drag?.moved ? ' schedule-chart--dragging' : ''}`}>
            <div className="schedule-chart__inner">
                <div className="schedule-chart__row schedule-chart__row--head">
                    <div className="schedule-chart__label" />
                    <div className="schedule-chart__track">
                        {weeks.map((week) => (
                            <span key={week} className="schedule-chart__week" style={{ left: pct(offset(week)) }}>
                                {formatShort(week)}
                            </span>
                        ))}
                    </div>
                </div>

                <div className="schedule-chart__body">
                    {items.map((item) => {
                        const dates = drag?.item.id === item.id && drag.moved ? dragDates(drag) : item;
                        return (
                            <div key={item.id} className="schedule-chart__row">
                                <button type="button" onClick={() => onOpen(item)} className="schedule-chart__label" title={item.title}>
                                    <span className="u-truncate">{item.title}</span>
                                </button>
                                <div className="schedule-chart__track">
                                    {gridlines()}
                                    <button
                                        type="button"
                                        onClick={() => openItem(item)}
                                        onPointerDown={(e) => startDrag(e, item, 'move')}
                                        title={`${item.title}: ${formatShort(dates.starts_on)} – ${formatShort(dates.ends_on)}${editable ? ' (drag to move, drag an end to change it)' : ''}`}
                                        className={`schedule-chart__bar${editable ? ' schedule-chart__bar--editable' : ''}`}
                                        style={{ left: pct(offset(dates.starts_on)), width: pct(daysBetween(dates.starts_on, dates.ends_on)) }}
                                    >
                                        {editable && (
                                            <>
                                                <span className="schedule-chart__handle schedule-chart__handle--start" onPointerDown={(e) => startDrag(e, item, 'start')} />
                                                <span className="schedule-chart__handle schedule-chart__handle--end" onPointerDown={(e) => startDrag(e, item, 'end')} />
                                            </>
                                        )}
                                    </button>
                                    {markers(tasksFor(item))}
                                </div>
                            </div>
                        );
                    })}

                    {otherTasks.length > 0 && (
                        <div className="schedule-chart__row">
                            <div className="schedule-chart__label schedule-chart__label--muted">Other tasks</div>
                            <div className="schedule-chart__track">
                                {gridlines()}
                                {markers(otherTasks)}
                            </div>
                        </div>
                    )}

                    {showToday && (
                        <div className="schedule-chart__today-lane" aria-hidden="true">
                            <div className="schedule-chart__label" />
                            <div className="schedule-chart__track">
                                <span className="schedule-chart__today" style={{ left: pct(offset(today) + 0.5) }} title="Today" />
                            </div>
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}
