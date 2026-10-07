import { format, parseISO } from 'date-fns';

const peso = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP', maximumFractionDigits: 2 });
const pesoCompact = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP', notation: 'compact', maximumFractionDigits: 1 });
const number = new Intl.NumberFormat('en-PH');

export const money = (v: number | string | null | undefined) => (v === null || v === undefined || v === '' ? '—' : peso.format(Number(v)));
export const moneyCompact = (v: number | string | null | undefined) => (v === null || v === undefined ? '—' : pesoCompact.format(Number(v)));
export const num = (v: number | null | undefined) => (v === null || v === undefined ? '—' : number.format(v));
export const pct = (v: number | null | undefined, digits = 1) => (v === null || v === undefined ? '—' : `${v.toFixed(digits)}%`);

export function date(value: string | null | undefined, pattern = 'MMM d, yyyy') {
    if (!value) return '—';
    try {
        return format(parseISO(value), pattern);
    } catch {
        return value;
    }
}

export const dateTime = (value: string | null | undefined) => date(value, 'MMM d, yyyy · h:mm a');

/** Beneficiary type as shown to users: "contingent" is called Secondary. */
export const BENEFICIARY_TYPES = [
    { value: 'primary', label: 'Primary' },
    { value: 'contingent', label: 'Secondary' },
] as const;

export function beneficiaryType(value: string | null | undefined) {
    return BENEFICIARY_TYPES.find((t) => t.value === value)?.label ?? label(value);
}

/** snake_case → Title Case ("semi_annual" → "Semi Annual"). */
export function label(value: string | null | undefined) {
    if (!value) return '—';
    return value.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

export const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

export function initials(name: string) {
    return name
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((p) => p[0]?.toUpperCase())
        .join('');
}

export function fileSize(bytes: number | null | undefined) {
    if (!bytes) return '—';
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(0)} KB`;
    return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}

export const todayISO = () => format(new Date(), 'yyyy-MM-dd');

/** "First Middle Last". Names are stored in parts; the full name is only ever built here, for display. */
export function fullName(p: { first_name: string; middle_name?: string | null; last_name: string } | null | undefined) {
    if (!p) return '';
    return [p.first_name, p.middle_name, p.last_name].filter((s) => s && s.trim()).join(' ');
}

/** "14:30" or "14:30:00" → "2:30 PM" (times are stored as 24-hour HH:mm; shown in 12-hour format). */
export function time12(value: string | null | undefined) {
    if (!value) return '';
    const [h, m] = value.split(':').map(Number);
    if (Number.isNaN(h) || Number.isNaN(m)) return value;
    return `${h % 12 || 12}:${String(m).padStart(2, '0')} ${h < 12 ? 'AM' : 'PM'}`;
}
