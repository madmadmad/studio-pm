import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import { addDays, daysBetween, formatMonth, formatShort, monthStarts, startOfWeek, toDay } from '../../lib/scheduleDates';
import { todayInAppTimezone } from '../../lib/format';

const STATUS_LABELS = { todo: 'To do', in_progress: 'In progress', done: 'Done' };
// Pointer travel (px) before a press on a bar counts as a drag, not a click.
const DRAG_THRESHOLD = 3;
// The least room (px) a timeline label gets before the next: "Sep 28" or
// "Jan 2027" in the subhead type, with a gap.
const LABEL_SPACE = 60;

// The timeline's labels (and gridlines), as many as fit its width: each
// week, every other week, each month, or each quarter. Months name the
// year on the first label and on each January.
function timelineTicks(weeks, rangeStart, rangeEnd, pxPerDay) {
    const weekPx = pxPerDay * 7;
    if (weekPx >= LABEL_SPACE) return weeks.map((date) => ({ date, label: formatShort(date) }));
    if (weekPx * 2 >= LABEL_SPACE) return weeks.filter((_, i) => i % 2 === 0).map((date) => ({ date, label: formatShort(date) }));
    const monthPx = pxPerDay * 30.4;
    const step = monthPx >= LABEL_SPACE ? 1 : monthPx * 3 >= LABEL_SPACE ? 3 : 6;
    return monthStarts(rangeStart, rangeEnd)
        .filter((date) => (Number(date.slice(5, 7)) - 1) % step === 0)
        .map((date, i) => ({ date, label: formatMonth(date, i === 0 || date.slice(5, 7) === '01') }));
}

// The Schedule tab's gantt chart: one row per item, a bar across its date
// range on a shared timeline. The timeline runs from the Monday on or
// before the earliest date to the Sunday on or after the latest, with
// gridlines and labels as often as fit (timelineTicks) and a line at today. Bars and labels are
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

    // The timeline's width, so its labels can thin out to fit (weekly on a
    // short schedule, monthly on a long one). Measured before paint.
    const headTrack = useRef(null);
    const [trackWidth, setTrackWidth] = useState(null);
    useLayoutEffect(() => {
        const el = headTrack.current;
        if (!el) return undefined;
        setTrackWidth(el.getBoundingClientRect().width);
        const observer = new ResizeObserver(([entry]) => setTrackWidth(entry.contentRect.width));
        observer.observe(el);
        return () => observer.disconnect();
    }, []);
    // Until measured, weekly, as before.
    const ticks = trackWidth
        ? timelineTicks(weeks, rangeStart, rangeEnd, trackWidth / totalDays)
        : weeks.map((date) => ({ date, label: formatShort(date) }));

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
        return ticks.map(({ date }) => <span key={date} className="schedule-chart__gridline" style={{ left: pct(offset(date)) }} />);
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
                    <div ref={headTrack} className="schedule-chart__track">
                        {ticks.map(({ date, label }) => (
                            <span key={date} className="schedule-chart__week" style={{ left: pct(offset(date)) }}>
                                {label}
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
