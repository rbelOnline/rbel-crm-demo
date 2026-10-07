import type { ReactNode } from 'react';
import { Link } from 'react-router-dom';
import type { LucideIcon } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import { label as toLabel } from '@/lib/format';

export function PageHeader({ title, description, actions }: { title: string; description?: ReactNode; actions?: ReactNode }) {
    return (
        <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <h1 className="text-2xl font-semibold tracking-tight">{title}</h1>
                {description && <p className="mt-1 text-sm text-muted-foreground">{description}</p>}
            </div>
            {actions && <div className="flex flex-wrap gap-2">{actions}</div>}
        </div>
    );
}

/** Stat tile. With `to`, the whole card links to the module behind the number. */
export function KpiCard({ title, value, hint, icon: Icon, tone = 'primary', to }: { title: string; value: ReactNode; hint?: ReactNode; icon: LucideIcon; tone?: 'primary' | 'warning' | 'success' | 'danger' | 'muted'; to?: string }) {
    const tones = {
        primary: 'bg-primary/10 text-primary',
        warning: 'bg-warning/15 text-amber-700 dark:text-warning',
        success: 'bg-success/15 text-emerald-700 dark:text-success',
        danger: 'bg-destructive/10 text-destructive',
        muted: 'bg-muted text-muted-foreground',
    };
    const card = (
        <Card className={cn('gap-0 py-0', to && 'h-full transition-colors group-hover:border-primary/40 group-hover:bg-muted/40')}>
            <CardContent className="flex items-start justify-between gap-3 p-5">
                <div className="min-w-0">
                    <p className="text-sm font-medium text-muted-foreground">{title}</p>
                    <p className="mt-2 text-3xl font-semibold tracking-tight tabular">{value}</p>
                    {hint && <p className="mt-1 line-clamp-2 text-xs text-muted-foreground">{hint}</p>}
                </div>
                <div className={cn('grid size-10 shrink-0 place-items-center rounded-lg', tones[tone])}>
                    <Icon className="size-5" aria-hidden />
                </div>
            </CardContent>
        </Card>
    );
    return to ? (
        <Link to={to} className="group rounded-xl focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none">
            {card}
        </Link>
    ) : (
        card
    );
}

const statusTone: Record<string, string> = {
    active: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-300',
    in_force: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-300',
    sent: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-300',
    completed: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-300',
    achieved: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-300',
    pending: 'bg-amber-50 text-amber-800 ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-300',
    scheduled: 'bg-sky-50 text-sky-700 ring-sky-600/20 dark:bg-sky-500/10 dark:text-sky-300',
    in_progress: 'bg-sky-50 text-sky-700 ring-sky-600/20 dark:bg-sky-500/10 dark:text-sky-300',
    rescheduled: 'bg-violet-50 text-violet-700 ring-violet-600/20 dark:bg-violet-500/10 dark:text-violet-300',
    draft: 'bg-slate-100 text-slate-700 ring-slate-500/20 dark:bg-slate-500/15 dark:text-slate-300',
    not_started: 'bg-slate-100 text-slate-700 ring-slate-500/20 dark:bg-slate-500/15 dark:text-slate-300',
    prospect: 'bg-slate-100 text-slate-700 ring-slate-500/20 dark:bg-slate-500/15 dark:text-slate-300',
    lead: 'bg-sky-50 text-sky-700 ring-sky-600/20 dark:bg-sky-500/10 dark:text-sky-300',
    ready: 'bg-sky-50 text-sky-700 ring-sky-600/20 dark:bg-sky-500/10 dark:text-sky-300',
    already_sent: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-300',
    no_email: 'bg-amber-50 text-amber-800 ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-300',
    lapsed: 'bg-red-50 text-red-700 ring-red-600/20 dark:bg-red-500/10 dark:text-red-300',
    failed: 'bg-red-50 text-red-700 ring-red-600/20 dark:bg-red-500/10 dark:text-red-300',
    inactive: 'bg-red-50 text-red-700 ring-red-600/20 dark:bg-red-500/10 dark:text-red-300',
    cancelled: 'bg-zinc-100 text-zinc-600 ring-zinc-500/20 dark:bg-zinc-500/15 dark:text-zinc-300',
    terminated: 'bg-red-50 text-red-700 ring-red-600/20 dark:bg-red-500/10 dark:text-red-300',
    cooling_off: 'bg-sky-50 text-sky-700 ring-sky-600/20 dark:bg-sky-500/10 dark:text-sky-300',
    postponed: 'bg-orange-50 text-orange-700 ring-orange-600/20 dark:bg-orange-500/10 dark:text-orange-300',
    surrendered: 'bg-zinc-100 text-zinc-600 ring-zinc-500/20 dark:bg-zinc-500/15 dark:text-zinc-300',
    matured: 'bg-teal-50 text-teal-700 ring-teal-600/20 dark:bg-teal-500/10 dark:text-teal-300',
    archived: 'bg-zinc-100 text-zinc-600 ring-zinc-500/20 dark:bg-zinc-500/15 dark:text-zinc-300',
};

export function StatusBadge({ status, className }: { status: string; className?: string }) {
    return (
        <span className={cn('inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset whitespace-nowrap', statusTone[status] ?? statusTone.draft, className)}>
            {toLabel(status)}
        </span>
    );
}

/** Explicit role tag so owner vs insured is never ambiguous in lists. */
export function RoleBadge({ role }: { role: 'owner' | 'insured' | 'owner_and_insured' | 'beneficiary' }) {
    const map = {
        owner: ['Owner', 'border-sky-300 text-sky-700 dark:border-sky-500/40 dark:text-sky-300'],
        insured: ['Insured', 'border-violet-300 text-violet-700 dark:border-violet-500/40 dark:text-violet-300'],
        owner_and_insured: ['Owner & Insured', 'border-teal-300 text-teal-700 dark:border-teal-500/40 dark:text-teal-300'],
        beneficiary: ['Beneficiary', 'border-amber-300 text-amber-800 dark:border-amber-500/40 dark:text-amber-300'],
    } as const;
    const [text, cls] = map[role];
    return (
        <Badge variant="outline" className={cn('font-medium', cls)}>
            {text}
        </Badge>
    );
}

export function Field({ label, error, htmlFor, required, hint, children, className }: { label: string; error?: string; htmlFor?: string; required?: boolean; hint?: ReactNode; children: ReactNode; className?: string }) {
    // content-start: when a sibling in the same form row grows (e.g. shows an error), this field
    // is stretched to the row height; keep label + input at the top instead of spreading them out.
    return (
        <div className={cn('grid content-start gap-1.5', className)}>
            <Label htmlFor={htmlFor} className="text-sm">
                {label}
                {required && <span className="text-destructive" aria-hidden> *</span>}
            </Label>
            {children}
            {error ? (
                <p className="text-xs font-medium text-destructive" role="alert">
                    {error}
                </p>
            ) : hint ? (
                <p className="text-xs text-muted-foreground">{hint}</p>
            ) : null}
        </div>
    );
}

export function DetailItem({ label, children, className }: { label: string; children: ReactNode; className?: string }) {
    return (
        <div className={className}>
            <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">{label}</dt>
            <dd className="mt-1 text-sm">{children ?? '—'}</dd>
        </div>
    );
}

export function EmptyState({ icon: Icon, title, description, action }: { icon: LucideIcon; title: string; description?: ReactNode; action?: ReactNode }) {
    return (
        <div className="flex flex-col items-center justify-center rounded-xl border border-dashed px-6 py-10 text-center">
            <Icon className="mb-3 size-8 text-muted-foreground/60" aria-hidden />
            <p className="font-medium">{title}</p>
            {description && <p className="mt-1 max-w-sm text-sm text-muted-foreground">{description}</p>}
            {action && <div className="mt-4">{action}</div>}
        </div>
    );
}
