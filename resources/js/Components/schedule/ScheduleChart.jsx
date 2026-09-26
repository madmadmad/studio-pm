import { addDays, daysBetween, formatShort, startOfWeek, toDay } from '../../lib/scheduleDates';
import { todayInAppTimezone } from '../../lib/format';

// The Schedule tab's gantt chart: one row per item, a bar across its date
// range on a shared timeline. The timeline runs from the Monday on or
// before the earliest start to the Sunday on or after the latest end, with
// a gridline and label each week and a line at today. Bars and labels are
// placed by percentage of the timeline, so the chart fills its width (and
// scrolls sideways below a minimum on narrow screens). `onOpen(item)` runs
// when a bar or its label is clicked.
export default function ScheduleChart({ items, onOpen }) {
    const rangeStart = startOfWeek(items.reduce((min, i) => (i.starts_on < min ? i.starts_on : min), items[0].starts_on));
    const lastEnd = items.reduce((max, i) => (i.ends_on > max ? i.ends_on : max), items[0].ends_on);
    const rangeEnd = addDays(startOfWeek(lastEnd), 6);
    const totalDays = daysBetween(rangeStart, rangeEnd);

    const pct = (days) => `${(days / totalDays) * 100}%`;
    const offset = (date) => daysBetween(rangeStart, date) - 1;

    const weeks = [];
    for (let week = rangeStart; week <= rangeEnd; week = addDays(week, 7)) weeks.push(week);

    const today = todayInAppTimezone();
    const showToday = toDay(today) >= toDay(rangeStart) && toDay(today) <= toDay(rangeEnd);

    return (
        <div className="schedule-chart">
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
                    {items.map((item) => (
                        <div key={item.id} className="schedule-chart__row">
                            <button type="button" onClick={() => onOpen(item)} className="schedule-chart__label" title={item.title}>
                                <span className="u-truncate">{item.title}</span>
                            </button>
                            <div className="schedule-chart__track">
                                {weeks.map((week) => (
                                    <span key={week} className="schedule-chart__gridline" style={{ left: pct(offset(week)) }} />
                                ))}
                                <button
                                    type="button"
                                    onClick={() => onOpen(item)}
                                    title={`${item.title}: ${formatShort(item.starts_on)} – ${formatShort(item.ends_on)}`}
                                    className="schedule-chart__bar"
                                    style={{ left: pct(offset(item.starts_on)), width: pct(daysBetween(item.starts_on, item.ends_on)) }}
                                />
                            </div>
                        </div>
                    ))}

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
