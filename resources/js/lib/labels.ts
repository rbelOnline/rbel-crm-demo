import type { AppointmentLabel } from '@/lib/types';

/** The four appointment colour labels, in display order (names come from meta.appointment_labels). */
export const LABEL_COLOURS: AppointmentLabel[] = ['green', 'blue', 'yellow', 'red'];

/** Classes per label: a dot / swatch, and a calendar chip. */
export const LABEL_STYLE: Record<AppointmentLabel, { dot: string; chip: string }> = {
    green: { dot: 'bg-emerald-500', chip: 'bg-emerald-100 text-emerald-900 dark:bg-emerald-500/20 dark:text-emerald-100' },
    blue: { dot: 'bg-sky-500', chip: 'bg-sky-100 text-sky-900 dark:bg-sky-500/20 dark:text-sky-100' },
    yellow: { dot: 'bg-amber-400', chip: 'bg-amber-100 text-amber-900 dark:bg-amber-400/20 dark:text-amber-100' },
    red: { dot: 'bg-red-500', chip: 'bg-red-100 text-red-900 dark:bg-red-500/20 dark:text-red-100' },
};

/** Appointments without a label. */
export const NO_LABEL_STYLE = { dot: 'bg-muted-foreground/40', chip: 'bg-muted text-foreground' };

export const labelStyle = (label: AppointmentLabel | null | undefined) => (label ? LABEL_STYLE[label] : NO_LABEL_STYLE);
