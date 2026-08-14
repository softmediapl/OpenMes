// Planner design tokens + pure layout/format helpers.
//
// Colours reference the OpenMES brand tokens (the `--om-*` CSS vars in
// resources/css/app.css), which are redefined under `.dark` — so referencing
// them in inline styles means dark mode "just works" with no per-component
// branching. This board follows the "OpenMES Schedule" design: technical
// Geist-Mono labelling, ACCEPTED = blue, maintenance = purple.
import { __, formatNumber } from '../../../../lib/i18n';
import { loadColorVar } from '../../../../lib/load';

export const MONO = 'var(--font-mono)';

// ── Work-order status → brand tokens ────────────────────────────────────────
export const STATUS = {
    PENDING:     { label: 'Pending',     solid: 'var(--om-pending)',  soft: 'var(--om-pending-bg)' },
    ACCEPTED:    { label: 'Accepted',    solid: 'var(--om-accepted)', soft: 'var(--om-accepted-bg)' },
    IN_PROGRESS: { label: 'Running',     solid: 'var(--om-running)',  soft: 'var(--om-running-bg)' },
    BLOCKED:     { label: 'Blocked',     solid: 'var(--om-blocked)',  soft: 'var(--om-blocked-bg)' },
    PAUSED:      { label: 'Paused',      solid: 'var(--om-downtime)', soft: 'var(--om-downtime-bg)' },
    // Held for a configuration change (#182) — nearer to blocked than to a break.
    CHANGE_HOLD: { label: 'Change hold', solid: 'var(--om-downtime)', soft: 'var(--om-downtime-bg)' },
    DONE:        { label: 'Done',        solid: 'var(--om-done)',     soft: 'var(--om-done-bg)' },
};
export function statusOf(s) { return STATUS[s] || STATUS.PENDING; }
export function statusLabel(s) { return __(statusOf(s).label); }

export const MAINT = 'var(--om-maint)';
export const MAINT_BG = 'var(--om-maint-bg)';

// Distinct shift accents for the day/shift column headers (decorative — they
// only tell the shift sub-columns apart, carrying no status meaning).
const SHIFT_COLORS = { 1: '#6366f1', 2: '#0ea5e9', 3: '#14b8a6', 4: '#8b5cf6' };
export function shiftColor(n) { return SHIFT_COLORS[n] || 'var(--om-accent)'; }

// Priority on the OpenMES 1–5 scale (0 reads as Lowest).
export function priorityMeta(p) {
    if (p >= 5) return { label: 'Urgent', color: 'var(--om-blocked)' };
    if (p === 4) return { label: 'High',   color: 'var(--om-accent)' };
    if (p === 3) return { label: 'Medium', color: 'var(--om-downtime)' };
    if (p === 2) return { label: 'Low',    color: 'var(--om-accepted)' };
    return         { label: 'Lowest', color: 'var(--om-faint)' };
}

// Load heat — single source of truth in lib/load (shared with the capacity view).
export const loadColor = loadColorVar;
export function loadLabel(pct) {
    if (pct > 100) return __('Overloaded');
    if (pct > 80) return __('Near capacity');
    return __('Healthy');
}

// ── Dates ───────────────────────────────────────────────────────────────────
export function parseDate(s) { // 'YYYY-MM-DD' -> local Date at noon (TZ-safe)
    if (!s) return null;
    const [y, m, d] = s.split('-').map(Number);
    return new Date(y, m - 1, d, 12, 0, 0);
}
export function fmtKey(date) {
    return date.getFullYear() + '-'
        + String(date.getMonth() + 1).padStart(2, '0') + '-'
        + String(date.getDate()).padStart(2, '0');
}
export function todayKey() { return fmtKey(new Date()); }

function addDaysKey(dateStr, days) {
    const date = parseDate(dateStr);
    if (!date) return dateStr;
    date.setDate(date.getDate() + days);
    return fmtKey(date);
}

// Convert a manual day/shift placement into the canonical minute-level window.
// Wall-clock strings are intentional: Laravel interprets them in the plant
// timezone, avoiding browser timezone conversion at the scheduling boundary.
export function shiftWindow(startDate, startShift, endDate, endShift, shifts = []) {
    if (!startDate || !startShift) return null;

    const count = Math.max(1, shifts.length);
    const fallbackTime = (slot, edge) => {
        const minutes = Math.round(((slot - 1 + edge) * 1440) / count) % 1440;
        return `${String(Math.floor(minutes / 60)).padStart(2, '0')}:${String(minutes % 60).padStart(2, '0')}`;
    };
    const startDefinition = shifts[startShift - 1];
    const resolvedEndShift = endShift || startShift;
    const endDefinition = shifts[resolvedEndShift - 1];
    const startTime = hhmm(startDefinition?.start_time) || fallbackTime(startShift, 0);
    const endShiftStartTime = hhmm(endDefinition?.start_time) || fallbackTime(resolvedEndShift, 0);
    const endTime = hhmm(endDefinition?.end_time) || fallbackTime(resolvedEndShift, 1);
    let resolvedEndDate = endDate || startDate;

    // The selected end cell owns an overnight shift that finishes on the next
    // calendar day, regardless of which shift the whole span started in.
    if (endTime <= endShiftStartTime) {
        resolvedEndDate = addDaysKey(resolvedEndDate, 1);
    }

    return {
        planned_start_at: `${startDate}T${startTime}:00`,
        planned_end_at: `${resolvedEndDate}T${endTime}:00`,
    };
}

export function dayList(startStr, count, showWeekends) {
    const start = parseDate(startStr);
    if (!start) return [];
    const out = [];
    for (let i = 0; out.length < count && i < 90; i++) {
        const d = new Date(start);
        d.setDate(start.getDate() + i);
        const dow = d.getDay();
        if (!showWeekends && (dow === 0 || dow === 6)) continue;
        out.push({ date: fmtKey(d), isWeekend: dow === 0 || dow === 6 });
    }
    return out;
}

// Read the wall-clock time straight from the ISO string (which carries the
// plant-timezone offset emitted by the server) rather than via `new Date()`,
// so the viewer's browser timezone can't shift the hourly layout or labels.
export function fmtTime(iso) {
    const m = /T(\d{2}):(\d{2})/.exec(iso || '');
    return m ? `${m[1]}:${m[2]}` : '';
}
export function hhmm(t) { return t ? String(t).slice(0, 5) : ''; }
export function minuteOfDay(iso) {
    const m = /T(\d{2}):(\d{2})/.exec(iso || '');
    return m ? (+m[1]) * 60 + (+m[2]) : 0;
}
// ISO week number for a 'YYYY-MM-DD' string.
export function isoWeek(dateStr) {
    const d = parseDate(dateStr);
    if (!d) return null;
    const t = new Date(Date.UTC(d.getFullYear(), d.getMonth(), d.getDate()));
    const day = t.getUTCDay() || 7;
    t.setUTCDate(t.getUTCDate() + 4 - day);
    const yearStart = new Date(Date.UTC(t.getUTCFullYear(), 0, 1));
    return Math.ceil((((t - yearStart) / 86400000) + 1) / 7);
}
export function fmtQty(n) { return n == null ? '—' : formatNumber(n); }
export function fmtDurationMinutes(minutes) {
    if (minutes == null || minutes <= 0) return '—';
    const hours = Math.floor(minutes / 60);
    const remainder = minutes % 60;
    if (!hours) return `${remainder} min`;
    const duration = remainder ? `${hours} h ${remainder} min` : `${hours} h`;
    if (minutes < 1440) return duration;

    const days = formatNumber(minutes / 1440, { minimumFractionDigits: 1, maximumFractionDigits: 1 });
    return `${duration} (${days} d)`;
}

export function durationEstimateMeta(workOrder) {
    const leadTime = workOrder.estimated_lead_time_minutes ?? workOrder.estimated_duration_minutes ?? null;
    const operationTime = workOrder.estimated_operation_minutes ?? null;
    const missingSteps = workOrder.unestimated_step_numbers ?? [];
    const complete = workOrder.estimate_complete !== false && leadTime != null;
    const parts = [
        `${__('Minimum process lead time')}: ${fmtDurationMinutes(leadTime)}`,
        `${__('Total operation time')}: ${fmtDurationMinutes(operationTime)}`,
    ];
    if (!complete) {
        parts.push(missingSteps.length
            ? `${__('Missing time standards for steps')}: ${missingSteps.join(', ')}`
            : __('Time estimate is incomplete'));
    }

    return {
        leadTime,
        operationTime,
        complete,
        title: parts.join(' · '),
    };
}

// planned_end_at is an exclusive boundary. Subtract one wall-clock minute
// before mapping it to a weekly cell, so a night shift ending at 06:00 does
// not appear to occupy the following day's night-shift column as well.
function exclusiveEndDate(iso, endShift, shifts, shiftsPerDay) {
    const match = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/.exec(iso || '');
    if (!match) return null;

    const definition = shifts[endShift - 1];
    const fallbackStart = Math.round(((endShift - 1) * 1440) / shiftsPerDay);
    const fallbackEnd = Math.round((endShift * 1440) / shiftsPerDay) % 1440;
    const startMinute = definition ? minuteOfDay(`T${hhmm(definition.start_time)}`) : fallbackStart;
    const endMinute = definition ? minuteOfDay(`T${hhmm(definition.end_time)}`) : fallbackEnd;
    if (endMinute <= startMinute) return addDaysKey(iso.slice(0, 10), -1);

    const date = new Date(Date.UTC(+match[1], +match[2] - 1, +match[3], +match[4], +match[5]));
    date.setUTCMinutes(date.getUTCMinutes() - 1);
    return `${date.getUTCFullYear()}-${String(date.getUTCMonth() + 1).padStart(2, '0')}-${String(date.getUTCDate()).padStart(2, '0')}`;
}

// ── Multi-segment placements ────────────────────────────────────────────────
// An order runs one primary placement (the wo's own line/date columns — the
// minute plan lives there) plus any number of coarse extra segments
// (wo.placements). Each segment has a stable `key`: 'primary' or the extra
// placement's id.

export function placementsOf(wo) {
    return [
        {
            key: 'primary',
            line_id: wo.line_id,
            schedule_date: wo.planned_start_at?.slice(0, 10) ?? null,
            schedule_end_date: wo.planned_end_at?.slice(0, 10) ?? null,
            shift_number: wo.shift_number,
            end_shift_number: wo.end_shift_number,
            is_extra: false,
        },
        ...(wo.placements || []).map((p) => ({
            key: p.id,
            ...p,
            schedule_date: p.due_date,
            schedule_end_date: p.end_date,
            is_extra: true,
        })),
    ];
}

// An order occupies every line any of its segments runs on.
export function onLine(wo, lineId) {
    return wo.line_id === lineId || (wo.placements || []).some((p) => p.line_id === lineId);
}

// The order as if the given segment were its (coarse) schedule. Extra
// segments never carry the minute plan — that belongs to the primary.
export function projectSegment(wo, p) {
    if (p.key === 'primary') {
        return {
            ...wo,
            schedule_date: p.schedule_date,
            schedule_end_date: p.schedule_end_date,
            is_extra_placement: false,
        };
    }
    return {
        ...wo,
        schedule_date: p.schedule_date,
        schedule_end_date: p.schedule_end_date,
        shift_number: p.shift_number ?? wo.shift_number,
        end_shift_number: p.end_shift_number,
        planned_start_at: null,
        planned_end_at: null,
        is_extra_placement: true,
    };
}

const segStartKey = (p) => `${p.schedule_date}|${String(p.shift_number ?? 1).padStart(2, '0')}`;
const segEndKey = (p) => `${p.schedule_end_date || p.schedule_date}|${String(p.end_shift_number ?? p.shift_number ?? 1).padStart(2, '0')}`;

// The order's dated segments in chronological order — the chain a staircase
// follows across lines (chips and connectors are drawn between neighbours).
export function segmentChain(wo) {
    return placementsOf(wo)
        .filter((p) => p.line_id && p.schedule_date)
        .sort((a, b) => (segStartKey(a) < segStartKey(b) ? -1 : 1));
}

// Chip meta for one segment: names its chain neighbour and the direction —
// 'to' (the order continues there next), 'from' (it came from there), or
// 'both' (the neighbouring segment runs concurrently).
export function chainChipMeta(wo, key, allLines) {
    const chain = segmentChain(wo);
    if (chain.length < 2) return null;
    const idx = chain.findIndex((p) => p.key === key);
    if (idx < 0) return null;
    const codeOf = (p) => allLines.find((l) => l.id === p.line_id)?.code ?? '?';
    const next = chain[idx + 1];
    const prev = chain[idx - 1];
    if (next) return { code: codeOf(next), dir: segStartKey(next) > segEndKey(chain[idx]) ? 'to' : 'both' };
    if (prev) return { code: codeOf(prev), dir: segEndKey(prev) < segStartKey(chain[idx]) ? 'from' : 'both' };
    return null;
}

// ── Weekly placement ────────────────────────────────────────────────────────
// The coarse day/shift slot occupied by a canonical schedule placement. The
// customer due date is deliberately never considered here.
export function weeklySlot(wo, shiftsPerDay) {
    const date = wo.schedule_date ?? wo.planned_start_at?.slice(0, 10) ?? null;
    let shift = wo.shift_number;
    if (!shift && wo.planned_start_at) {
        // Read the hour from the ISO string (plant-timezone offset), not via
        // new Date().getHours() which would shift by the viewer's browser TZ.
        const h = Math.floor(minuteOfDay(wo.planned_start_at) / 60);
        shift = Math.min(shiftsPerDay, Math.floor(h / (24 / shiftsPerDay)) + 1);
    }
    return { date, shift: Math.min(shift || 1, shiftsPerDay) };
}

// Which calendar day a work order shows on in the monthly view.
export function onMonthlyDay(wo, iso, dayNum, monthNum) {
    // Extra segments occupy their own days too.
    if ((wo.placements || []).some((p) => p.due_date === iso)) return true;
    if (wo.planned_start_at) return wo.planned_start_at.slice(0, 10) === iso;
    return false;
}

// Lay a line's orders onto the day×shift columns as spanning blocks. Each item
// gets startCol/endCol (covering the canonical planned window) and a lane
// so overlapping spans stack instead of colliding. Columns: day*shiftsPerDay +
// (shift-1).
export function weeklyPlacements(orders, days, shiftsPerDay, lineId = null, shifts = []) {
    const dayIdx = {}; days.forEach((d, i) => { dayIdx[d.date] = i; });
    const N = days.length * shiftsPerDay;
    const colOf = (date, shift) => (date in dayIdx ? dayIdx[date] * shiftsPerDay + (Math.min(shift, shiftsPerDay) - 1) : -1);
    const items = [];
    orders.forEach((orig) => {
        // One block per segment the order runs on this line.
        const segs = lineId != null
            ? placementsOf(orig).filter((p) => p.line_id === lineId)
            : [placementsOf(orig)[0]];
        segs.forEach((p) => {
            const wo = projectSegment(orig, p);
            const sl = weeklySlot(wo, shiftsPerDay);
            const startCol = colOf(sl.date, sl.shift);
            if (startCol < 0) return;
            let endCol = startCol;
            const endDate = wo.is_extra_placement
                ? wo.schedule_end_date
                : exclusiveEndDate(wo.planned_end_at, wo.end_shift_number || wo.shift_number || sl.shift, shifts, shiftsPerDay);
            if (endDate && (endDate in dayIdx)) endCol = colOf(endDate, wo.end_shift_number || sl.shift);
            else if (wo.end_shift_number && wo.end_shift_number > sl.shift) endCol = colOf(sl.date, wo.end_shift_number);
            if (endCol < startCol) endCol = startCol;
            items.push({ wo: orig, placementKey: p.key, lineId, startCol, endCol });
        });
    });
    items.sort((a, b) => a.startCol - b.startCol || b.endCol - a.endCol);
    const laneEnds = [];
    items.forEach((it) => {
        let lane = laneEnds.findIndex((e) => e <= it.startCol);
        if (lane === -1) { lane = laneEnds.length; laneEnds.push(0); }
        laneEnds[lane] = it.endCol + 1;
        it.lane = lane;
    });
    return { items, lanes: Math.max(1, laneEnds.length), N };
}

// Occupancy-based load % for a line over the visible days × shifts.
export function lineLoad(orders, lineId, days, shiftsPerDay) {
    const total = days.length * shiftsPerDay;
    if (!total) return 0;
    const dayIdx = {}; days.forEach((d, i) => { dayIdx[d.date] = i; });
    const covered = new Set();
    orders.forEach((o) => {
        placementsOf(o).filter((p) => p.line_id === lineId).forEach((p) => {
            const { date, shift } = weeklySlot(projectSegment(o, p), shiftsPerDay);
            if (date == null || !(date in dayIdx)) return;
            covered.add(dayIdx[date] * shiftsPerDay + (shift - 1));
        });
    });
    return Math.round((covered.size / total) * 100);
}

// ── Hourly layout ───────────────────────────────────────────────────────────
// Greedy interval lane packing + conflict detection per line, for one day.
export function hourlyLanes(orders, lineId, dateStr) {
    const items = orders
        // One bar per segment on this line. Extra segments are read-only
        // coarse placeholders here — the minute plan lives on the primary.
        .flatMap((orig) => placementsOf(orig)
            .filter((p) => p.line_id === lineId)
            .map((p) => ({ orig, proj: projectSegment(orig, p), key: p.key })))
        .filter(({ proj }) => {
            if (proj.planned_start_at && proj.planned_end_at) {
                return proj.planned_start_at.slice(0, 10) <= dateStr && dateStr <= proj.planned_end_at.slice(0, 10);
            }
            return proj.is_extra_placement && proj.schedule_date === dateStr;
        })
        .map(({ orig, proj, key }) => {
            if (!proj.planned_start_at || !proj.planned_end_at) {
                return { wo: orig, placementKey: key, start: 0, end: 60, spansOutside: false, placeholder: true };
            }
            const startsBefore = proj.planned_start_at.slice(0, 10) < dateStr;
            const endsAfter = proj.planned_end_at.slice(0, 10) > dateStr;
            const start = startsBefore ? 0 : minuteOfDay(proj.planned_start_at);
            const end = endsAfter ? 1440 : minuteOfDay(proj.planned_end_at);
            return { wo: orig, placementKey: key, start, end, spansOutside: startsBefore || endsAfter, placeholder: false };
        })
        .sort((a, b) => a.start - b.start || a.end - b.end);
    const laneEnds = [];
    items.forEach((it) => {
        let placed = -1;
        for (let l = 0; l < laneEnds.length; l++) { if (laneEnds[l] <= it.start) { placed = l; break; } }
        if (placed === -1) { placed = laneEnds.length; laneEnds.push(it.end); } else laneEnds[placed] = it.end;
        it.lane = placed;
    });
    const totalLanes = Math.max(1, laneEnds.length);
    items.forEach((a) => {
        a.conflict = items.some((b) => b !== a && a.start < b.end && b.start < a.end);
    });
    return { items, totalLanes };
}
