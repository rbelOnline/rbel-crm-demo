import { useEffect, useId, useRef, useState, type ReactNode } from 'react';
import { useQuery } from '@tanstack/react-query';
import { format, isValid, parse, parseISO } from 'date-fns';
import { CalendarIcon, Check, ChevronsUpDown, Loader2, Search, X } from 'lucide-react';
import { Popover, PopoverAnchor, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Calendar } from '@/components/ui/calendar';
import { AlertDialog, AlertDialogAction, AlertDialogCancel, AlertDialogContent, AlertDialogDescription, AlertDialogFooter, AlertDialogHeader, AlertDialogTitle } from '@/components/ui/alert-dialog';
import { api } from '@/lib/api';
import { fullName } from '@/lib/format';
import { useDebounce } from '@/hooks/use-data';
import type { ClientSummary } from '@/lib/types';
import { cn } from '@/lib/utils';

/** Which clients a picker offers: `owner` = may own a policy; `owns` / `insured` = actually owns / is insured under one. */
export type ClientLookupRole = 'owner' | 'owns' | 'insured';

/**
 * Async client search. Used separately for Policy Owner, Policy Insured and
 * the client on appointments, reminders and goals — each picker holds its own value.
 */
export function ClientPicker({
    value,
    onChange,
    placeholder = 'Select a client…',
    role,
    invalid,
    id,
    allowClear,
    exclude,
}: {
    value: number | null | undefined;
    onChange: (id: number | null, client?: ClientSummary) => void;
    placeholder?: string;
    role?: ClientLookupRole;
    invalid?: boolean;
    id?: string;
    allowClear?: boolean;
    exclude?: number[];
}) {
    const [open, setOpen] = useState(false);
    const [term, setTerm] = useState('');
    const q = useDebounce(term, 250);

    const results = useQuery({
        queryKey: ['client-lookup', q, role],
        queryFn: async () => (await api.get<{ data: ClientSummary[] }>('/clients/lookup', { params: { q, role, limit: 20 } })).data.data,
        enabled: open,
    });

    // Resolve the label for the current value.
    const selected = useQuery({
        queryKey: ['client-summary', value],
        queryFn: async () => (await api.get<{ data: ClientSummary[] }>('/clients/lookup', { params: { ids: String(value) } })).data.data[0] ?? null,
        enabled: !!value,
        staleTime: 60_000,
    });

    const items = (results.data ?? []).filter((c) => !exclude?.includes(c.id));
    const emptyText =
        role === 'owner' ? 'No Policy Owners found.' : role === 'owns' ? 'No clients found as Policy Owner.' : role === 'insured' ? 'No clients found as Policy Insured.' : 'No clients found.';

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <div className="relative">
                <PopoverTrigger asChild>
                    <Button
                        id={id}
                        type="button"
                        variant="outline"
                        role="combobox"
                        aria-expanded={open}
                        aria-invalid={invalid}
                        className={cn('w-full justify-between font-normal', !value && 'text-muted-foreground', invalid && 'border-destructive')}
                    >
                        <span className="truncate">{value ? (selected.data ? fullName(selected.data) : 'Loading…') : placeholder}</span>
                        <ChevronsUpDown className="size-4 opacity-50" />
                    </Button>
                </PopoverTrigger>
                {allowClear && value ? (
                    <button type="button" className="absolute top-1/2 right-8 -translate-y-1/2 rounded p-0.5 text-muted-foreground hover:text-foreground" onClick={() => onChange(null)} aria-label="Clear selection">
                        <X className="size-3.5" />
                    </button>
                ) : null}
            </div>
            <PopoverContent className="w-(--radix-popover-trigger-width) min-w-[280px] p-2" align="start">
                <Input autoFocus value={term} onChange={(e) => setTerm(e.target.value)} placeholder="Search name, email or mobile…" className="mb-2 h-9" />
                <div className="max-h-64 overflow-y-auto" role="listbox">
                    {results.isLoading && (
                        <div className="flex items-center gap-2 p-3 text-sm text-muted-foreground">
                            <Loader2 className="size-4 animate-spin" /> Searching…
                        </div>
                    )}
                    {!results.isLoading && items.length === 0 && <p className="p-3 text-sm text-muted-foreground">{emptyText}</p>}
                    {items.map((c) => (
                        <button
                            key={c.id}
                            type="button"
                            role="option"
                            aria-selected={c.id === value}
                            onClick={() => {
                                onChange(c.id, c);
                                setOpen(false);
                            }}
                            className="flex w-full items-center justify-between gap-2 rounded-md px-2 py-2 text-left text-sm hover:bg-accent focus-visible:bg-accent focus-visible:outline-none"
                        >
                            <span>
                                <span className="font-medium">{fullName(c)}</span>
                                <span className="ml-2 text-xs text-muted-foreground">
                                    {c.age !== null ? `${c.age} yrs` : ''}
                                    {!c.is_policy_owner && ' · insured only'}
                                </span>
                            </span>
                            {c.id === value && <Check className="size-4 text-primary" />}
                        </button>
                    ))}
                </div>
            </PopoverContent>
        </Popover>
    );
}

/** A Policy Owner candidate: an existing owner-flagged client, or a lead (converted to a client when the policy is saved). */
export interface OwnerOption {
    kind: 'client' | 'lead';
    id: number;
    first_name: string;
    middle_name: string | null;
    last_name: string;
    age: number | null;
}

export type OwnerValue = { kind: 'client' | 'lead'; id: number } | null;

/**
 * Policy Owner search for the Clients form, as a plain text box: matching Policy Owners
 * and leads are suggested under it while typing (no dropdown button). The box shows the
 * picked person's name; leaving without picking restores it.
 */
export function OwnerAutocomplete({ value, onChange, placeholder = 'Search policy owner or lead…', invalid, id, allowClear }: { value: OwnerValue; onChange: (v: OwnerValue) => void; placeholder?: string; invalid?: boolean; id?: string; allowClear?: boolean }) {
    const [term, setTerm] = useState('');
    const [open, setOpen] = useState(false);
    const [active, setActive] = useState(0);
    const q = useDebounce(term.trim(), 250);
    const listId = useId();

    const selected = useQuery({
        queryKey: ['owner-lookup', 'selected', value?.kind, value?.id],
        queryFn: async () => (await api.get<{ data: OwnerOption[] }>('/owner-lookup', { params: { [`${value!.kind}_id`]: value!.id } })).data.data[0] ?? null,
        enabled: !!value,
        staleTime: 60_000,
    });
    const selectedName = value && selected.data ? fullName(selected.data) : '';

    // Show the picked person's name whenever the box is not being typed in.
    useEffect(() => {
        if (!open) setTerm(selectedName);
    }, [selectedName, open]);

    const results = useQuery({
        queryKey: ['owner-lookup', q],
        queryFn: async () => (await api.get<{ data: OwnerOption[] }>('/owner-lookup', { params: { q, limit: 8 } })).data.data,
        // Only search what was typed, not the name of the person already picked.
        enabled: open && q.length >= 1 && q !== selectedName,
    });

    // Policy Owners first, then leads; keyboard order follows the display order.
    const items = [...(results.data ?? []).filter((o) => o.kind === 'client'), ...(results.data ?? []).filter((o) => o.kind === 'lead')];
    const showList = open && q.length >= 1 && q !== selectedName;

    const pick = (o: OwnerOption) => {
        onChange({ kind: o.kind, id: o.id });
        setTerm(fullName(o));
        setOpen(false);
    };

    const close = () => {
        setOpen(false);
        setTerm(selectedName);
    };

    return (
        <div className="relative">
            <div className="relative">
                <Search className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
                <Input
                    id={id}
                    value={term}
                    placeholder={placeholder}
                    autoComplete="off"
                    role="combobox"
                    aria-expanded={showList}
                    aria-controls={listId}
                    aria-autocomplete="list"
                    aria-invalid={invalid}
                    className={cn('pl-8', (allowClear && (value || term)) && 'pr-8', invalid && 'border-destructive')}
                    onFocus={(e) => e.target.select()}
                    onChange={(e) => {
                        setTerm(e.target.value);
                        setActive(0);
                        setOpen(true);
                    }}
                    onBlur={close}
                    onKeyDown={(e) => {
                        if (e.key === 'ArrowDown' && items.length) {
                            e.preventDefault();
                            setOpen(true);
                            setActive((i) => (i + 1) % items.length);
                        } else if (e.key === 'ArrowUp' && items.length) {
                            e.preventDefault();
                            setActive((i) => (i - 1 + items.length) % items.length);
                        } else if (e.key === 'Enter' && showList && items[active]) {
                            e.preventDefault();
                            pick(items[active]);
                        } else if (e.key === 'Escape' && showList) {
                            e.preventDefault();
                            close();
                        }
                    }}
                />
                {allowClear && (value || term) ? (
                    <button
                        type="button"
                        className="absolute top-1/2 right-2 -translate-y-1/2 rounded p-0.5 text-muted-foreground hover:text-foreground"
                        onMouseDown={(e) => e.preventDefault()}
                        onClick={() => {
                            setTerm('');
                            setOpen(false);
                            if (value) onChange(null);
                        }}
                        aria-label="Clear"
                    >
                        <X className="size-3.5" />
                    </button>
                ) : null}
            </div>
            {showList && (
                <div id={listId} role="listbox" className="absolute z-50 mt-1 max-h-72 w-full overflow-y-auto rounded-md border bg-popover p-1 text-sm shadow-md">
                    {results.isLoading ? (
                        <div className="flex items-center gap-2 p-2 text-muted-foreground">
                            <Loader2 className="size-4 animate-spin" /> Searching…
                        </div>
                    ) : !items.length ? (
                        <p className="p-2 text-muted-foreground">No Policy Owners or leads found.</p>
                    ) : (
                        items.map((o, i) => (
                            <button
                                key={`${o.kind}-${o.id}`}
                                type="button"
                                role="option"
                                aria-selected={i === active}
                                // Keep focus in the input so blur does not close the list before the click lands.
                                onMouseDown={(e) => e.preventDefault()}
                                onMouseEnter={() => setActive(i)}
                                onClick={() => pick(o)}
                                className={cn('flex w-full items-center justify-between gap-2 rounded px-2 py-1.5 text-left', i === active && 'bg-accent')}
                            >
                                <span className="min-w-0 truncate">
                                    <span className="font-medium">{fullName(o)}</span>
                                    {o.age !== null && <span className="ml-2 text-xs text-muted-foreground">{o.age} yrs</span>}
                                </span>
                                <span className="shrink-0 text-xs text-muted-foreground">{o.kind === 'lead' ? 'Lead' : 'Policy Owner'}</span>
                            </button>
                        ))
                    )}
                </div>
            )}
        </div>
    );
}

/** Formats accepted when a date is typed (the first is also how dates are shown). */
const TYPED_DATE_FORMATS = ['MM/dd/yyyy', 'M/d/yyyy', 'yyyy-MM-dd', 'MMM d, yyyy', 'MMM d yyyy', 'MMMM d, yyyy', 'MMMM d yyyy', 'd MMM yyyy'];

/** A typed date, or null if it is not a complete, real date (e.g. 02/30/1990). */
function parseTypedDate(text: string): Date | null {
    const t = text.trim();
    if (!/\d{4}/.test(t)) return null; // wait for a 4-digit year
    for (const f of TYPED_DATE_FORMATS) {
        const d = parse(t, f, new Date());
        if (isValid(d) && d.getFullYear() >= 1900) return d;
    }
    return null;
}

/**
 * Slashes are added while typing digits: "0520" → "05/20", "05201990" → "05/20/1990".
 * Slashes typed by hand are kept, so "5/7/1990" stays as typed.
 */
function maskDigits(text: string): string {
    if (!/^[\d/]*$/.test(text)) return text; // a month name or ISO date: leave as typed
    const parts = text.split('/');
    // A month or day past 2 digits spills into the next part.
    while (parts.length < 3 && parts[parts.length - 1].length > 2) {
        const last = parts.pop()!;
        parts.push(last.slice(0, 2), last.slice(2));
    }
    if (parts.length >= 3) parts[2] = parts[2].slice(0, 4);
    return parts.slice(0, 3).join('/');
}

/**
 * Date input bound to a yyyy-MM-dd string. Type it (05/20/1990, 1990-05-20 or
 * May 20, 1990) or pick it: the calendar opens beside the box and jumps to and
 * selects the typed date as soon as it is complete.
 */
export function DatePicker({ value, onChange, id, invalid, placeholder = 'MM/DD/YYYY', clearable, disabled }: { value: string | null | undefined; onChange: (v: string | null) => void; id?: string; invalid?: boolean; placeholder?: string; clearable?: boolean; disabled?: (d: Date) => boolean }) {
    const selected = value ? parseISO(value) : undefined;
    const shown = selected ? format(selected, TYPED_DATE_FORMATS[0]) : '';
    const [open, setOpen] = useState(false);
    const [text, setText] = useState(shown);
    const [month, setMonth] = useState<Date | undefined>(selected);
    const [focused, setFocused] = useState(false);
    const wrapper = useRef<HTMLDivElement>(null);

    // Follow outside changes (form reset, a pick in the calendar) while not typing.
    useEffect(() => {
        if (!focused) {
            setText(shown);
            if (selected) setMonth(selected);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [shown, focused]);

    const commit = (d: Date | null) => {
        onChange(d ? format(d, 'yyyy-MM-dd') : null);
        if (d) setMonth(d);
    };

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverAnchor asChild>
                <div ref={wrapper} className="relative">
                    <Input
                        id={id}
                        value={text}
                        placeholder={placeholder}
                        inputMode="numeric"
                        autoComplete="off"
                        aria-invalid={invalid}
                        className={cn('pr-9', invalid && 'border-destructive')}
                        onFocus={() => {
                            setFocused(true);
                            setOpen(true);
                        }}
                        onChange={(e) => {
                            const next = maskDigits(e.target.value);
                            setText(next);
                            if (!next.trim()) {
                                if (clearable) onChange(null);
                                return;
                            }
                            // A complete, allowed date is selected right away (and shown in the calendar).
                            const d = parseTypedDate(next);
                            if (d && !disabled?.(d)) commit(d);
                        }}
                        onBlur={() => {
                            setFocused(false);
                            const d = parseTypedDate(text);
                            if (!text.trim()) {
                                if (clearable) onChange(null);
                                else setText(shown);
                            } else if (!d || disabled?.(d)) {
                                setText(shown); // not a usable date: back to the saved value
                            } else {
                                setText(format(d, TYPED_DATE_FORMATS[0]));
                            }
                        }}
                        onKeyDown={(e) => {
                            if (e.key === 'Escape' && open) {
                                e.preventDefault();
                                setOpen(false);
                            } else if (e.key === 'Enter') {
                                e.preventDefault(); // don't submit the form from the date box
                                setOpen(false);
                            }
                        }}
                    />
                    <button
                        type="button"
                        tabIndex={-1}
                        className="absolute top-1/2 right-2 -translate-y-1/2 rounded p-0.5 text-muted-foreground hover:text-foreground"
                        onClick={() => setOpen((o) => !o)}
                        aria-label="Open calendar"
                    >
                        <CalendarIcon className="size-4" />
                    </button>
                </div>
            </PopoverAnchor>
            <PopoverContent
                className="w-auto p-0"
                align="start"
                // Keep the cursor in the box so typing continues while the calendar is open.
                onOpenAutoFocus={(e) => e.preventDefault()}
                onInteractOutside={(e) => {
                    if (wrapper.current?.contains(e.target as Node)) e.preventDefault();
                }}
            >
                <Calendar
                    mode="single"
                    selected={selected}
                    month={month}
                    onMonthChange={setMonth}
                    captionLayout="dropdown"
                    startMonth={new Date(1920, 0)}
                    endMonth={new Date(new Date().getFullYear() + 5, 11)}
                    disabled={disabled}
                    onSelect={(d) => {
                        commit(d ?? null);
                        setText(d ? format(d, TYPED_DATE_FORMATS[0]) : '');
                        setOpen(false);
                    }}
                />
                {clearable && value && (
                    <div className="border-t p-2">
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            className="w-full"
                            onClick={() => {
                                onChange(null);
                                setText('');
                                setOpen(false);
                            }}
                        >
                            Clear date
                        </Button>
                    </div>
                )}
            </PopoverContent>
        </Popover>
    );
}

export function ConfirmDialog({
    open,
    onOpenChange,
    title,
    description,
    confirmLabel = 'Delete',
    onConfirm,
    loading,
}: {
    open: boolean;
    onOpenChange: (o: boolean) => void;
    title: string;
    description: ReactNode;
    confirmLabel?: string;
    onConfirm: () => void;
    loading?: boolean;
}) {
    return (
        <AlertDialog open={open} onOpenChange={onOpenChange}>
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>{title}</AlertDialogTitle>
                    <AlertDialogDescription>{description}</AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                    <AlertDialogCancel disabled={loading}>Cancel</AlertDialogCancel>
                    <AlertDialogAction
                        className="bg-destructive text-white hover:bg-destructive/90"
                        disabled={loading}
                        onClick={(e) => {
                            e.preventDefault();
                            onConfirm();
                        }}
                    >
                        {loading && <Loader2 className="size-4 animate-spin" />}
                        {confirmLabel}
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
