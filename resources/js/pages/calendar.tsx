import { useEffect, useMemo, useState, type ReactNode } from 'react';
import { Link } from 'react-router-dom';
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { addMonths, eachDayOfInterval, endOfMonth, endOfWeek, format, isSameMonth, isToday, parseISO, startOfMonth, startOfWeek } from 'date-fns';
import { CalendarClock, ChevronLeft, ChevronRight, Loader2, MapPin, Pencil, Plus, Repeat as RepeatIcon, Trash2, UserRound } from 'lucide-react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Field, PageHeader, StatusBadge } from '@/components/shared/misc';
import { ConfirmDialog, DatePicker } from '@/components/shared/pickers';
import { AppointmentDialog } from '@/pages/appointments';
import { api, errorMessage, fieldErrors } from '@/lib/api';
import { useMeta } from '@/hooks/use-data';
import { useAuth } from '@/hooks/use-auth';
import { labelStyle, LABEL_COLOURS } from '@/lib/labels';
import { fullName, time12 } from '@/lib/format';
import type { Appointment, AppointmentLabel, ScheduleItem } from '@/lib/types';
import { cn } from '@/lib/utils';

const WEEKDAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

/** Entries shown per day in the month grid; the rest are counted as "+N more". */
const PER_DAY = 3;

/** Hours always shown in the day planner (7 AM – 8 PM); entries outside stretch it. */
const DAY_START = 7;
const DAY_END = 20;

/** Legend keys: entries without a label, and the two kinds of entry. */
const NONE = 'none';
const KIND_APPOINTMENT = 'kind:appointment';
const KIND_PERSONAL = 'kind:personal';

/** An appointment or a personal schedule item, as placed on the calendar. */
type Entry =
    | { kind: 'appointment'; key: string; date: string; time: string; end: null; title: string; label: AppointmentLabel | null; item: Appointment }
    | { kind: 'personal'; key: string; date: string; time: string; end: string | null; title: string; label: AppointmentLabel | null; item: ScheduleItem };

const iso = (d: Date) => format(d, 'yyyy-MM-dd');
const hhmm = (t: string) => t.slice(0, 5);
const hourOf = (t: string) => Number(t.slice(0, 2));
const hourLabel = (h: number) => format(new Date(2000, 0, 1, h), 'h a');
const pad = (h: number) => `${String(h).padStart(2, '0')}:00`;

/** Rename the four colour labels (admins and advisors). */
function LabelsDialog({ open, onOpenChange, labels }: { open: boolean; onOpenChange: (o: boolean) => void; labels: { key: AppointmentLabel; name: string }[] }) {
    const qc = useQueryClient();
    const [names, setNames] = useState<Record<string, string>>({});
    const [errors, setErrors] = useState<Record<string, string>>({});

    useEffect(() => {
        if (!open) return;
        setErrors({});
        setNames(Object.fromEntries(labels.map((l) => [l.key, l.name])));
    }, [open, labels]);

    const save = useMutation({
        mutationFn: () => api.put('/calendar-labels', { labels: names }),
        onSuccess: () => {
            toast.success('Labels renamed.');
            qc.invalidateQueries({ queryKey: ['meta'] });
            onOpenChange(false);
        },
        onError: (e) => {
            const f = fieldErrors(e);
            setErrors(f);
            if (!Object.keys(f).length) toast.error(errorMessage(e));
        },
    });

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Calendar labels</DialogTitle>
                    <DialogDescription>Name each colour. Appointments and schedule items keep their colour when a label is renamed.</DialogDescription>
                </DialogHeader>
                <form id="labels-form" onSubmit={(e) => { e.preventDefault(); save.mutate(); }} className="grid gap-3" noValidate>
                    {LABEL_COLOURS.map((c) => (
                        <Field key={c} label={c[0].toUpperCase() + c.slice(1)} htmlFor={`label_${c}`} error={errors[`labels.${c}`]}>
                            <div className="flex items-center gap-2">
                                <span className={cn('size-4 shrink-0 rounded-full', labelStyle(c).dot)} aria-hidden />
                                <Input id={`label_${c}`} maxLength={40} value={names[c] ?? ''} onChange={(e) => setNames({ ...names, [c]: e.target.value })} aria-invalid={!!errors[`labels.${c}`]} />
                            </div>
                        </Field>
                    ))}
                </form>
                <DialogFooter>
                    <Button variant="outline" onClick={() => onOpenChange(false)}>Cancel</Button>
                    <Button type="submit" form="labels-form" disabled={save.isPending}>
                        {save.isPending && <Loader2 className="size-4 animate-spin" />} Save
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

type Repeat = ScheduleItem['repeat'];
type ScheduleValues = { title: string; date: string | null; start_time: string; end_time: string; repeat: Repeat; repeat_until: string | null; label: string; notes: string };

/** "Every week on Monday", worded for the item's first day. */
function repeatLabel(repeat: Repeat, date: string | null) {
    const d = date ? parseISO(date) : new Date();
    switch (repeat) {
        case 'daily':
            return 'Every day';
        case 'weekly':
            return `Every week on ${format(d, 'EEEE')}`;
        case 'monthly':
            return `Every month on day ${format(d, 'd')}`;
        case 'yearly':
            return `Every year on ${format(d, 'MMM d')}`;
        default:
            return 'Does not repeat';
    }
}

const REPEATS: Repeat[] = ['none', 'daily', 'weekly', 'monthly', 'yearly'];

/** Add / edit a personal schedule item (a repeating one is edited as a whole series). Deleting is handed to the page. */
function ScheduleItemDialog({ open, onOpenChange, item, defaults, labels, onDelete }: { open: boolean; onOpenChange: (o: boolean) => void; item: ScheduleItem | null; defaults?: { date: string; time: string }; labels: { key: AppointmentLabel; name: string }[]; onDelete: (item: ScheduleItem) => void }) {
    const qc = useQueryClient();
    const [v, setV] = useState<ScheduleValues>({ title: '', date: null, start_time: '09:00', end_time: '', repeat: 'none', repeat_until: null, label: '', notes: '' });
    const [errors, setErrors] = useState<Record<string, string>>({});

    useEffect(() => {
        if (!open) return;
        setErrors({});
        if (item) {
            // A series is edited from its first day.
            setV({ title: item.title, date: item.starts_on, start_time: item.start_time, end_time: item.end_time ?? '', repeat: item.repeat, repeat_until: item.repeat_until, label: item.label ?? '', notes: item.notes ?? '' });
        } else {
            // A new item fills the hour it was added at.
            const start = defaults?.time ?? '09:00';
            const end = hourOf(start) < 23 ? pad(hourOf(start) + 1) : '';
            setV({ title: '', date: defaults?.date ?? iso(new Date()), start_time: start, end_time: end, repeat: 'none', repeat_until: null, label: '', notes: '' });
        }
    }, [open, item, defaults]);

    const save = useMutation({
        mutationFn: () => {
            const local: Record<string, string> = {};
            if (!v.title.trim()) local.title = 'Title is required.';
            if (!v.date) local.date = 'Date is required.';
            if (v.end_time && v.end_time <= v.start_time) local.end_time = 'End time must be after the start time.';
            if (v.repeat !== 'none' && v.repeat_until && v.date && v.repeat_until < v.date) local.repeat_until = 'The last day cannot be before the first day.';
            if (Object.keys(local).length) {
                setErrors(local);
                throw new Error('local');
            }
            const body = {
                ...v,
                title: v.title.trim(),
                end_time: v.end_time || null,
                repeat_until: v.repeat === 'none' ? null : v.repeat_until,
                label: v.label || null,
                notes: v.notes.trim() || null,
            };
            return item ? api.put(`/schedule-items/${item.id}`, body) : api.post('/schedule-items', body);
        },
        onSuccess: () => {
            toast.success(item ? 'Schedule updated.' : 'Added to your schedule.');
            qc.invalidateQueries({ queryKey: ['schedule-items'] });
            onOpenChange(false);
        },
        onError: (e) => {
            if ((e as Error).message === 'local') return;
            const f = fieldErrors(e);
            setErrors(f);
            if (!Object.keys(f).length) toast.error(errorMessage(e));
        },
    });

    const series = !!item && item.repeat !== 'none';

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent dismissOnOutsideClick={false} className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{item ? 'Edit schedule' : 'Add to my schedule'}</DialogTitle>
                    <DialogDescription>
                        Personal schedule — only you can see it.{series && ' Changes apply to every occurrence.'}
                    </DialogDescription>
                </DialogHeader>
                <form id="schedule-form" onSubmit={(e) => { e.preventDefault(); save.mutate(); }} className="grid gap-4 sm:grid-cols-3" noValidate>
                    <Field label="Title" htmlFor="sched_title" required error={errors.title} className="sm:col-span-3">
                        <Input id="sched_title" autoFocus maxLength={150} value={v.title} onChange={(e) => setV({ ...v, title: e.target.value })} placeholder="e.g. Prospecting calls" aria-invalid={!!errors.title} />
                    </Field>
                    <Field label={v.repeat === 'none' ? 'Date' : 'First day'} required error={errors.date}>
                        <DatePicker value={v.date} onChange={(date) => setV({ ...v, date })} invalid={!!errors.date} />
                    </Field>
                    <Field label="Start" htmlFor="sched_start" required error={errors.start_time}>
                        <Input id="sched_start" type="time" value={v.start_time} onChange={(e) => setV({ ...v, start_time: e.target.value })} aria-invalid={!!errors.start_time} />
                    </Field>
                    <Field label="End" htmlFor="sched_end" error={errors.end_time}>
                        <Input id="sched_end" type="time" value={v.end_time} onChange={(e) => setV({ ...v, end_time: e.target.value })} aria-invalid={!!errors.end_time} />
                    </Field>
                    <Field label="Repeat" htmlFor="sched_repeat" error={errors.repeat} className={v.repeat === 'none' ? 'sm:col-span-3' : 'sm:col-span-2'}>
                        <Select value={v.repeat} onValueChange={(r) => setV({ ...v, repeat: r as Repeat })}>
                            <SelectTrigger id="sched_repeat" className="w-full"><SelectValue /></SelectTrigger>
                            <SelectContent>
                                {REPEATS.map((r) => (
                                    <SelectItem key={r} value={r}>
                                        {repeatLabel(r, v.date)}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </Field>
                    {v.repeat !== 'none' && (
                        <Field label="Until" htmlFor="sched_until" error={errors.repeat_until} hint="Empty = no end">
                            <DatePicker id="sched_until" value={v.repeat_until} onChange={(repeat_until) => setV({ ...v, repeat_until })} clearable placeholder="No end" invalid={!!errors.repeat_until} />
                        </Field>
                    )}
                    <Field label="Label" htmlFor="sched_label" className="sm:col-span-3">
                        <Select value={v.label || 'none'} onValueChange={(l) => setV({ ...v, label: l === 'none' ? '' : l })}>
                            <SelectTrigger id="sched_label" className="w-full"><SelectValue /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="none">
                                    <span className="flex items-center gap-2"><span className={cn('size-2.5 rounded-full', labelStyle(null).dot)} /> No label</span>
                                </SelectItem>
                                {labels.map((l) => (
                                    <SelectItem key={l.key} value={l.key}>
                                        <span className="flex items-center gap-2"><span className={cn('size-2.5 rounded-full', labelStyle(l.key).dot)} /> {l.name}</span>
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </Field>
                    <Field label="Notes" htmlFor="sched_notes" className="sm:col-span-3">
                        <Textarea id="sched_notes" rows={2} value={v.notes} onChange={(e) => setV({ ...v, notes: e.target.value })} />
                    </Field>
                </form>
                <DialogFooter className="sm:justify-between">
                    {item ? (
                        <Button type="button" variant="ghost" className="text-destructive hover:text-destructive" onClick={() => onDelete(item)}>
                            <Trash2 className="size-4" /> Delete
                        </Button>
                    ) : (
                        <span />
                    )}
                    <div className="flex gap-2">
                        <Button variant="outline" onClick={() => onOpenChange(false)}>Cancel</Button>
                        <Button type="submit" form="schedule-form" disabled={save.isPending}>
                            {save.isPending && <Loader2 className="size-4 animate-spin" />} Save
                        </Button>
                    </div>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

/** "+" menu: add a personal schedule item or a client appointment (at a given time, when known). */
function AddMenu({ time, onPersonal, onAppointment, className, children }: { time?: string; onPersonal: () => void; onAppointment: () => void; className?: string; children: ReactNode }) {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild className={className}>
                {children}
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
                <DropdownMenuItem onClick={onPersonal}>
                    <CalendarClock className="size-4" /> Personal schedule{time && ` at ${time12(time)}`}
                </DropdownMenuItem>
                <DropdownMenuItem onClick={onAppointment}>
                    <UserRound className="size-4" /> Appointment with a client
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

/**
 * Calendar: your personal planner. A month of appointments and personal schedule
 * items, coloured by label (green, blue, yellow, red; named in the legend), and an
 * hour-by-hour plan of the selected day where any hour can be filled in.
 */
export default function CalendarPage() {
    const [month, setMonth] = useState(() => startOfMonth(new Date()));
    const [selected, setSelected] = useState(() => iso(new Date()));
    const [apptDialog, setApptDialog] = useState<{ open: boolean; item: Appointment | null; date?: string; time?: string }>({ open: false, item: null });
    const [schedDialog, setSchedDialog] = useState<{ open: boolean; item: ScheduleItem | null; defaults?: { date: string; time: string } }>({ open: false, item: null });
    const [hidden, setHidden] = useState<Set<string>>(new Set());
    const [renaming, setRenaming] = useState(false);
    const meta = useMeta();
    const canManage = !!useAuth().user?.permissions.manage;
    const labels = meta.data?.appointment_labels ?? [];
    const labelName = (l: AppointmentLabel | null) => (l ? labels.find((x) => x.key === l)?.name : undefined);
    const toggle = (key: string) =>
        setHidden((h) => {
            const next = new Set(h);
            if (next.has(key)) next.delete(key);
            else next.add(key);
            return next;
        });

    // Whole weeks covering the month, Sunday first.
    const days = useMemo(() => eachDayOfInterval({ start: startOfWeek(startOfMonth(month)), end: endOfWeek(endOfMonth(month)) }), [month]);
    const from = iso(days[0]);
    const to = iso(days[days.length - 1]);

    // Keyed under 'appointments', so saving an appointment anywhere refreshes the calendar.
    const appointments = useQuery({
        queryKey: ['appointments', 'calendar', from, to],
        queryFn: async () => (await api.get<{ data: Appointment[] }>('/appointments/calendar', { params: { from, to } })).data.data,
        placeholderData: keepPreviousData,
    });
    const personal = useQuery({
        queryKey: ['schedule-items', from, to],
        queryFn: async () => (await api.get<{ data: ScheduleItem[] }>('/schedule-items', { params: { from, to } })).data.data,
        placeholderData: keepPreviousData,
    });

    // Both kinds of entry by day, in time order, minus what the legend hides.
    const byDay = useMemo(() => {
        const entries: Entry[] = [
            ...(appointments.data ?? []).map((a): Entry => ({ kind: 'appointment', key: `a${a.id}`, date: a.appointment_date, time: hhmm(a.appointment_time), end: null, title: a.title, label: a.label, item: a })),
            ...(personal.data ?? []).map((s): Entry => ({ kind: 'personal', key: `p${s.id}-${s.date}`, date: s.date, time: hhmm(s.start_time), end: s.end_time, title: s.title, label: s.label, item: s })),
        ].filter((e) => !hidden.has(e.label ?? NONE) && !hidden.has(e.kind === 'appointment' ? KIND_APPOINTMENT : KIND_PERSONAL));
        entries.sort((x, y) => x.time.localeCompare(y.time));

        const map = new Map<string, Entry[]>();
        for (const e of entries) map.set(e.date, [...(map.get(e.date) ?? []), e]);
        return map;
    }, [appointments.data, personal.data, hidden]);

    const goTo = (m: Date) => setMonth(startOfMonth(m));
    const goToday = () => {
        goTo(new Date());
        setSelected(iso(new Date()));
    };
    const pickDay = (d: Date) => {
        setSelected(iso(d));
        if (!isSameMonth(d, month)) goTo(d);
    };
    const open = (e: Entry) => (e.kind === 'appointment' ? setApptDialog({ open: true, item: e.item }) : setSchedDialog({ open: true, item: e.item }));

    // Delete straight from the day planner: your own personal items always; appointments for admins and advisors.
    const qc = useQueryClient();
    const [deleting, setDeleting] = useState<Entry | null>(null);
    const canDelete = (e: Entry) => e.kind === 'personal' || canManage;
    // scope 'day' removes only this day from a repeating item; 'all' deletes the item (the whole series).
    const remove = useMutation({
        mutationFn: ({ entry: e, scope }: { entry: Entry; scope: 'day' | 'all' }) =>
            e.kind === 'appointment'
                ? api.delete(`/appointments/${e.item.id}`)
                : scope === 'day'
                  ? api.post(`/schedule-items/${e.item.id}/skip`, { date: e.date })
                  : api.delete(`/schedule-items/${e.item.id}`),
        onSuccess: (_, { entry: e, scope }) => {
            toast.success(e.kind === 'appointment' ? 'Appointment deleted.' : scope === 'day' ? 'Removed from this day.' : 'Removed from your schedule.');
            qc.invalidateQueries({ queryKey: [e.kind === 'personal' ? 'schedule-items' : 'appointments'] });
            if (e.kind === 'appointment') qc.invalidateQueries({ queryKey: ['dashboard'] });
            setDeleting(null);
            setSchedDialog({ open: false, item: null });
        },
        onError: (err) => toast.error(errorMessage(err)),
    });
    const deletingSeries = deleting?.kind === 'personal' && deleting.item.repeat !== 'none';
    const addPersonal = (date: string, time = '09:00') => setSchedDialog({ open: true, item: null, defaults: { date, time } });
    const addAppointment = (date: string, time = '09:00') => setApptDialog({ open: true, item: null, date, time });

    const dayEntries = byDay.get(selected) ?? [];
    const monthCount = [...byDay.entries()].filter(([d]) => isSameMonth(parseISO(d), month)).reduce((n, [, list]) => n + list.length, 0);

    // Hours in the day planner: 7 AM – 8 PM, stretched for anything earlier or later.
    const hours = useMemo(() => {
        const hs = dayEntries.map((e) => hourOf(e.time));
        const first = Math.min(DAY_START, ...hs);
        const last = Math.max(DAY_END, ...hs);
        return Array.from({ length: last - first + 1 }, (_, i) => first + i);
    }, [dayEntries]);

    const loading = appointments.isLoading || personal.isLoading;

    return (
        <div className="grid gap-5">
            <PageHeader
                title="Calendar"
                description="Your personal schedule and client appointments."
                actions={
                    <AddMenu onPersonal={() => addPersonal(selected)} onAppointment={() => addAppointment(selected)}>
                        <Button>
                            <Plus className="size-4" /> New
                        </Button>
                    </AddMenu>
                }
            />

            <div className="grid gap-5 xl:grid-cols-[minmax(0,1fr)_380px]">
                <Card className="gap-0 py-0">
                    <div className="flex flex-wrap items-center justify-between gap-2 border-b px-4 py-3">
                        <div className="flex items-center gap-1">
                            <Button variant="outline" size="icon" className="size-8" onClick={() => goTo(addMonths(month, -1))} aria-label="Previous month">
                                <ChevronLeft className="size-4" />
                            </Button>
                            <Button variant="outline" size="icon" className="size-8" onClick={() => goTo(addMonths(month, 1))} aria-label="Next month">
                                <ChevronRight className="size-4" />
                            </Button>
                            <Button variant="outline" size="sm" className="ml-1" onClick={goToday}>
                                Today
                            </Button>
                        </div>
                        <h2 className="text-lg font-semibold tracking-tight">{format(month, 'MMMM yyyy')}</h2>
                        <p className="text-sm text-muted-foreground">
                            {monthCount} {monthCount === 1 ? 'entry' : 'entries'}
                        </p>
                    </div>

                    {/* Legend: click to hide / show a kind of entry or a label. */}
                    <div className="flex flex-wrap items-center gap-1.5 border-b px-4 py-2 text-xs">
                        {[
                            { key: KIND_APPOINTMENT, name: 'Appointments', mark: <UserRound className="size-3" /> },
                            { key: KIND_PERSONAL, name: 'Personal', mark: <span className="size-2.5 rounded-sm border border-dashed border-foreground/60" /> },
                        ].map((k) => (
                            <button key={k.key} type="button" onClick={() => toggle(k.key)} aria-pressed={!hidden.has(k.key)} className={cn('flex items-center gap-1.5 rounded-full border px-2.5 py-1 hover:bg-muted', hidden.has(k.key) && 'opacity-40 line-through')}>
                                {k.mark} {k.name}
                            </button>
                        ))}
                        <span className="mx-1 h-4 w-px bg-border" aria-hidden />
                        {[...labels.map((l) => ({ key: l.key as string, name: l.name, dot: labelStyle(l.key).dot })), { key: NONE, name: 'No label', dot: labelStyle(null).dot }].map((l) => (
                            <button
                                key={l.key}
                                type="button"
                                onClick={() => toggle(l.key)}
                                aria-pressed={!hidden.has(l.key)}
                                className={cn('flex items-center gap-1.5 rounded-full border px-2.5 py-1 transition-opacity hover:bg-muted', hidden.has(l.key) && 'opacity-40 line-through')}
                                title={hidden.has(l.key) ? `Show ${l.name}` : `Hide ${l.name}`}
                            >
                                <span className={cn('size-2.5 rounded-full', l.dot)} aria-hidden /> {l.name}
                            </button>
                        ))}
                        {canManage && (
                            <Button variant="ghost" size="sm" className="ml-auto h-7 text-xs" onClick={() => setRenaming(true)}>
                                <Pencil className="size-3.5" /> Edit labels
                            </Button>
                        )}
                    </div>

                    <div className="grid grid-cols-7 border-b text-center text-xs font-medium text-muted-foreground">
                        {WEEKDAYS.map((d) => (
                            <div key={d} className="py-2">
                                {d}
                            </div>
                        ))}
                    </div>

                    {loading ? (
                        <Skeleton className="m-4 h-[480px]" />
                    ) : (
                        <div className="grid grid-cols-7" role="grid" aria-label={format(month, 'MMMM yyyy')}>
                            {days.map((d) => {
                                const key = iso(d);
                                const items = byDay.get(key) ?? [];
                                const inMonth = isSameMonth(d, month);
                                const isSelected = key === selected;
                                return (
                                    <div
                                        key={key}
                                        role="gridcell"
                                        aria-selected={isSelected}
                                        tabIndex={0}
                                        onClick={() => pickDay(d)}
                                        onKeyDown={(e) => (e.key === 'Enter' || e.key === ' ') && (e.preventDefault(), pickDay(d))}
                                        className={cn(
                                            'min-h-20 min-w-0 cursor-pointer overflow-hidden border-r border-b p-1 text-left transition-colors hover:bg-muted/50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset sm:min-h-28 sm:p-1.5 [&:nth-child(7n)]:border-r-0',
                                            !inMonth && 'bg-muted/30 text-muted-foreground',
                                            isSelected && 'bg-primary/5',
                                        )}
                                    >
                                        <div className="flex justify-end">
                                            <span className={cn('grid size-6 place-items-center rounded-full text-xs tabular', isToday(d) && 'bg-primary font-semibold text-primary-foreground', isSelected && !isToday(d) && 'ring-1 ring-primary')}>
                                                {format(d, 'd')}
                                            </span>
                                        </div>
                                        {/* Small screens: a dot per entry. */}
                                        {items.length > 0 && (
                                            <div className="mt-1 flex flex-wrap gap-0.5 sm:hidden">
                                                {items.slice(0, 4).map((e) => (
                                                    <span key={e.key} className={cn('size-1.5 rounded-full', labelStyle(e.label).dot)} />
                                                ))}
                                            </div>
                                        )}
                                        {/* Larger screens: time and title; personal items have a dashed outline. */}
                                        <ul className="mt-1 hidden min-w-0 grid-cols-1 gap-0.5 sm:grid">
                                            {items.slice(0, PER_DAY).map((e) => (
                                                <li key={e.key} className="min-w-0">
                                                    <button
                                                        type="button"
                                                        onClick={(ev) => {
                                                            ev.stopPropagation();
                                                            open(e);
                                                        }}
                                                        className={cn(
                                                            'w-full truncate rounded px-1.5 py-0.5 text-left text-[11px] leading-4 hover:opacity-80',
                                                            labelStyle(e.label).chip,
                                                            e.kind === 'personal' && 'border border-dashed border-current/40',
                                                            e.kind === 'appointment' && e.item.status === 'cancelled' && 'line-through opacity-60',
                                                        )}
                                                        title={`${time12(e.time)} ${e.title}${e.kind === 'appointment' && e.item.client ? ` · ${fullName(e.item.client)}` : e.kind === 'personal' ? ' · personal' : ''}${labelName(e.label) ? ` · ${labelName(e.label)}` : ''}`}
                                                    >
                                                        <span className="font-medium tabular">{time12(e.time)}</span> {e.kind === 'personal' && e.item.repeat !== 'none' && <RepeatIcon className="inline size-3 align-[-1px]" aria-label="Repeats" />} {e.title}
                                                    </button>
                                                </li>
                                            ))}
                                            {items.length > PER_DAY && <li className="px-1.5 text-[11px] text-muted-foreground">+{items.length - PER_DAY} more</li>}
                                        </ul>
                                    </div>
                                );
                            })}
                        </div>
                    )}
                </Card>

                {/* The selected day, hour by hour. */}
                <Card className="h-fit gap-0 py-0">
                    <CardHeader className="flex flex-row items-start justify-between gap-2 border-b py-4">
                        <div>
                            <CardTitle>{format(parseISO(selected), 'EEEE, MMM d')}</CardTitle>
                            <CardDescription>{dayEntries.length ? `${dayEntries.length} scheduled` : 'Nothing scheduled — click + on any hour'}</CardDescription>
                        </div>
                        <AddMenu onPersonal={() => addPersonal(selected)} onAppointment={() => addAppointment(selected)}>
                            <Button variant="outline" size="sm">
                                <Plus className="size-4" /> Add
                            </Button>
                        </AddMenu>
                    </CardHeader>
                    <CardContent className="max-h-[70vh] overflow-y-auto p-0">
                        <ol>
                            {hours.map((h) => {
                                const inHour = dayEntries.filter((e) => hourOf(e.time) === h);
                                return (
                                    <li key={h} className="group grid grid-cols-[56px_minmax(0,1fr)_32px] items-start border-b last:border-b-0">
                                        <span className="px-2 pt-2 text-right text-[11px] text-muted-foreground tabular">{hourLabel(h)}</span>
                                        <div className="grid min-h-11 min-w-0 grid-cols-1 content-start gap-1 py-1.5">
                                            {inHour.map((e) => (
                                                <div key={e.key} className="group/entry relative min-w-0">
                                                <button
                                                    type="button"
                                                    onClick={() => open(e)}
                                                    className={cn(
                                                        'relative grid w-full min-w-0 grid-cols-1 gap-0.5 overflow-hidden rounded-md py-1.5 pr-2 pl-3 text-left text-xs hover:opacity-90',
                                                        labelStyle(e.label).chip,
                                                        e.kind === 'personal' && 'border border-dashed border-current/40',
                                                        canDelete(e) && 'pb-6',
                                                    )}
                                                >
                                                    <span className={cn('absolute inset-y-0 left-0 w-1', labelStyle(e.label).dot)} aria-hidden />
                                                    <span className="flex min-w-0 items-start justify-between gap-2">
                                                        <span className={cn('min-w-0 break-words font-medium', e.kind === 'appointment' && e.item.status === 'cancelled' && 'line-through')}>{e.title}</span>
                                                        {e.kind === 'appointment' ? <StatusBadge status={e.item.status} /> : <span className="flex shrink-0 items-center gap-1 text-[10px] uppercase tracking-wide opacity-70">{e.item.repeat !== 'none' && <RepeatIcon className="size-3" aria-label="Repeats" />}Personal</span>}
                                                    </span>
                                                    <span className="flex min-w-0 flex-wrap items-center gap-x-2 opacity-80">
                                                        <span className="tabular">{time12(e.time)}{e.end && ` – ${time12(e.end)}`}</span>
                                                        {e.kind === 'appointment' && e.item.client && (
                                                            <Link to={`/people/${e.item.client.id}`} onClick={(ev) => ev.stopPropagation()} className="underline-offset-2 hover:underline">
                                                                {fullName(e.item.client)}
                                                            </Link>
                                                        )}
                                                        {e.kind === 'appointment' && e.item.location && (
                                                            <span className="flex min-w-0 items-center gap-1"><MapPin className="size-3 shrink-0" /> <span className="truncate">{e.item.location}</span></span>
                                                        )}
                                                        {labelName(e.label) && <span>· {labelName(e.label)}</span>}
                                                    </span>
                                                </button>
                                                {canDelete(e) && (
                                                    <button
                                                        type="button"
                                                        onClick={() => setDeleting(e)}
                                                        className="absolute right-1 bottom-1 grid size-6 place-items-center rounded text-current opacity-60 hover:bg-background/60 hover:text-destructive hover:opacity-100 focus-visible:opacity-100 sm:opacity-0 sm:group-hover/entry:opacity-60"
                                                        aria-label={`Delete ${e.title}`}
                                                        title="Delete"
                                                    >
                                                        <Trash2 className="size-3.5" />
                                                    </button>
                                                )}
                                                </div>
                                            ))}
                                        </div>
                                        <AddMenu time={pad(h)} onPersonal={() => addPersonal(selected, pad(h))} onAppointment={() => addAppointment(selected, pad(h))} className="mt-1.5">
                                            <button type="button" className="grid size-7 place-items-center rounded text-muted-foreground/60 hover:bg-muted hover:text-foreground focus-visible:text-foreground sm:opacity-0 sm:group-hover:opacity-100 sm:focus-visible:opacity-100" aria-label={`Add at ${hourLabel(h)}`}>
                                                <Plus className="size-4" />
                                            </button>
                                        </AddMenu>
                                    </li>
                                );
                            })}
                        </ol>
                    </CardContent>
                </Card>
            </div>

            <LabelsDialog open={renaming} onOpenChange={setRenaming} labels={labels} />
            {/* A repeating item: this day only, or every occurrence. */}
            <Dialog open={!!deleting && deletingSeries} onOpenChange={(o) => !o && setDeleting(null)}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Delete a repeating item</DialogTitle>
                        <DialogDescription>
                            "{deleting?.title}" repeats. Remove it only on {deleting ? format(parseISO(deleting.date), 'EEEE, MMM d') : ''}, or every time it occurs?
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter className="gap-2 sm:justify-between">
                        <Button variant="outline" onClick={() => setDeleting(null)} disabled={remove.isPending}>Cancel</Button>
                        <div className="flex gap-2">
                            <Button variant="outline" onClick={() => deleting && remove.mutate({ entry: deleting, scope: 'day' })} disabled={remove.isPending}>
                                This day only
                            </Button>
                            <Button className="bg-destructive text-white hover:bg-destructive/90" onClick={() => deleting && remove.mutate({ entry: deleting, scope: 'all' })} disabled={remove.isPending}>
                                {remove.isPending && <Loader2 className="size-4 animate-spin" />} All occurrences
                            </Button>
                        </div>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
            <ConfirmDialog
                open={!!deleting && !deletingSeries}
                onOpenChange={(o) => !o && setDeleting(null)}
                title={deleting?.kind === 'appointment' ? 'Delete this appointment?' : 'Delete this schedule item?'}
                description={deleting ? `"${deleting.title}" at ${time12(deleting.time)} on ${format(parseISO(deleting.date), 'MMM d')} will be permanently removed.` : ''}
                onConfirm={() => deleting && remove.mutate({ entry: deleting, scope: 'all' })}
                loading={remove.isPending}
            />
            <ScheduleItemDialog open={schedDialog.open} item={schedDialog.item} defaults={schedDialog.defaults} labels={labels} onDelete={(s) => setDeleting({ kind: 'personal', key: `p${s.id}-${s.date}`, date: s.date, time: hhmm(s.start_time), end: s.end_time, title: s.title, label: s.label, item: s })} onOpenChange={(o) => setSchedDialog(o ? schedDialog : { open: false, item: null })} />
            <AppointmentDialog open={apptDialog.open} appointment={apptDialog.item} defaultDate={apptDialog.date} defaultTime={apptDialog.time} onOpenChange={(o) => setApptDialog(o ? apptDialog : { open: false, item: null })} />
        </div>
    );
}
