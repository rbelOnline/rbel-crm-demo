import { useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { formatDistanceToNowStrict } from 'date-fns';
import { AlertTriangle, Cake, CalendarHeart, CircleCheck, CircleDollarSign, Clock, Loader2, Play, Save, Users } from 'lucide-react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { EmptyState, Field, PageHeader, StatusBadge } from '@/components/shared/misc';
import { ConfirmDialog } from '@/components/shared/pickers';
import { api, errorMessage, fieldErrors } from '@/lib/api';
import { dateTime } from '@/lib/format';
import type { Automation, AutomationRecipient, EmailTemplate, Paginated } from '@/lib/types';
import { cn } from '@/lib/utils';

type Sender = { id: number; name: string; email: string };
type IndexResponse = { data: Automation[]; senders: Sender[]; email_delivery_enabled: boolean; scheduler_last_seen: string | null };

/** How often the page re-checks settings, run results and today's recipients. */
const LIVE_MS = 10_000;

/** Re-renders every `ms` so "x ago" texts stay current between data refreshes. */
function useNow(ms = 5_000) {
    const [now, setNow] = useState(() => Date.now());
    useEffect(() => {
        const id = setInterval(() => setNow(Date.now()), ms);
        return () => clearInterval(id);
    }, [ms]);
    return now;
}

/** The scheduler checks every minute; no check for 3 minutes means it is not running. */
function SchedulerStatus({ lastSeen }: { lastSeen: string | null }) {
    const now = useNow();
    const age = lastSeen ? now - new Date(lastSeen).getTime() : Infinity;
    if (age < 3 * 60_000) {
        return (
            <Alert className="border-emerald-300 bg-emerald-50 text-emerald-800 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300">
                <CircleCheck className="size-4" />
                <AlertDescription className="text-current">
                    Automatic sending is running. Turned-on automations send by themselves at their daily time (last check {formatDistanceToNowStrict(new Date(lastSeen!))} ago). Each person or policy gets an automated email at most once a day.
                </AlertDescription>
            </Alert>
        );
    }
    return (
        <Alert className="border-amber-300 bg-amber-50 text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300">
            <AlertTriangle className="size-4" />
            <AlertDescription className="text-current">
                Automatic sending is <strong>not running</strong>{lastSeen ? ` (last check ${formatDistanceToNowStrict(new Date(lastSeen))} ago)` : ''}, so turned-on automations will not send. Start it with <code>install-scheduler.ps1</code> (runs on its own, also after a restart) or <code>start-scheduler.bat</code>. You can still use Run now.
            </AlertDescription>
        </Alert>
    );
}

const ABOUT: Record<Automation['key'], { icon: typeof Cake; description: string }> = {
    birthday_greeting: { icon: Cake, description: 'Emails every client and lead whose birthday is today.' },
    premium_due: { icon: CircleDollarSign, description: 'Emails the Policy Owner of every active policy with a premium due today. Use {{premium_due}} and {{due_date}} in the template for the amount and date.' },
    policy_anniversary: { icon: CalendarHeart, description: 'Emails the Policy Owner of every active policy issued on this day in an earlier year. Use {{issued_date}} and {{policy_years}} in the template for the issue date and how many years it has been.' },
};

function AutomationCard({ automation, senders, templates, deliveryOn }: { automation: Automation; senders: Sender[]; templates: EmailTemplate[]; deliveryOn: boolean }) {
    const qc = useQueryClient();
    const about = ABOUT[automation.key];
    const Icon = about.icon;
    const [form, setForm] = useState({ email_template_id: '', send_time: '08:00', sender_user_id: '' });
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [confirmRun, setConfirmRun] = useState(false);

    // Reset the form only when the SAVED settings change, not on every live refresh,
    // so edits in progress are never wiped.
    useEffect(() => {
        setForm({ email_template_id: automation.email_template_id ? String(automation.email_template_id) : '', send_time: automation.send_time, sender_user_id: automation.sender_user_id ? String(automation.sender_user_id) : '' });
    }, [automation.email_template_id, automation.send_time, automation.sender_user_id]);

    const recipients = useQuery({
        queryKey: ['automation-preview', automation.id],
        queryFn: async () => (await api.get<{ data: AutomationRecipient[] }>(`/automations/${automation.id}/preview`)).data.data,
        refetchInterval: LIVE_MS,
        refetchOnWindowFocus: true,
    });

    // When a run finishes while the page is open (e.g. the scheduled send), say so and refresh the list.
    const seenRun = useRef(automation.last_run_at);
    useEffect(() => {
        if (automation.last_run_at === seenRun.current) return;
        seenRun.current = automation.last_run_at;
        const s = automation.last_run_summary;
        qc.invalidateQueries({ queryKey: ['automation-preview', automation.id] });
        qc.invalidateQueries({ queryKey: ['email-logs'] });
        if (s?.trigger === 'schedule') {
            toast.info(`${automation.label} ran automatically`, { description: s.error ?? `${s.sent} sent, ${s.failed} failed, ${s.skipped} skipped.` });
        }
    }, [automation.last_run_at, automation.last_run_summary, automation.id, automation.label, qc]);

    const save = useMutation({
        mutationFn: (enabled: boolean) =>
            api.put(`/automations/${automation.id}`, {
                enabled,
                email_template_id: form.email_template_id ? Number(form.email_template_id) : null,
                send_time: form.send_time,
                sender_user_id: form.sender_user_id ? Number(form.sender_user_id) : null,
            }),
        onSuccess: (_r, enabled) => {
            setErrors({});
            toast.success(enabled === automation.enabled ? 'Settings saved.' : enabled ? `${automation.label} turned on.` : `${automation.label} turned off.`);
            qc.invalidateQueries({ queryKey: ['automations'] });
        },
        onError: (e) => {
            const f = fieldErrors(e);
            setErrors(f);
            toast.error(Object.values(f)[0] ?? errorMessage(e));
        },
    });

    const run = useMutation({
        mutationFn: async () => (await api.post<{ data: NonNullable<Automation['last_run_summary']> }>(`/automations/${automation.id}/run`)).data.data,
        onSuccess: (s) => {
            setConfirmRun(false);
            if (s.error) toast.error(s.error);
            else toast.success(`${automation.label}: ${s.sent} sent, ${s.failed} failed, ${s.skipped} skipped.`);
            qc.invalidateQueries({ queryKey: ['automations'] });
            qc.invalidateQueries({ queryKey: ['automation-preview', automation.id] });
            qc.invalidateQueries({ queryKey: ['email-logs'] });
        },
        onError: (e) => {
            setConfirmRun(false);
            toast.error(errorMessage(e));
        },
    });

    const dirty =
        form.email_template_id !== (automation.email_template_id ? String(automation.email_template_id) : '') ||
        form.send_time !== automation.send_time ||
        form.sender_user_id !== (automation.sender_user_id ? String(automation.sender_user_id) : '');
    const ready = (recipients.data ?? []).filter((r) => r.status === 'ready').length;
    const last = automation.last_run_summary;

    return (
        <Card>
            <CardHeader className="flex flex-row items-start justify-between gap-4">
                <div className="flex items-start gap-3">
                    <span className="grid size-10 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary">
                        <Icon className="size-5" aria-hidden />
                    </span>
                    <div>
                        <CardTitle>{automation.label}</CardTitle>
                        <CardDescription className="mt-1 max-w-xl">{about.description}</CardDescription>
                    </div>
                </div>
                <div className="flex items-center gap-2">
                    <Label htmlFor={`on-${automation.id}`} className="text-sm">{automation.enabled ? 'On' : 'Off'}</Label>
                    <Switch id={`on-${automation.id}`} checked={automation.enabled} onCheckedChange={(on) => save.mutate(on)} disabled={save.isPending} />
                </div>
            </CardHeader>
            <CardContent className="grid gap-5">
                <div className="grid gap-4 sm:grid-cols-[minmax(0,1fr)_130px_minmax(0,1fr)_auto] sm:items-end">
                    <Field label="Email template" error={errors.email_template_id}>
                        <Select value={form.email_template_id || undefined} onValueChange={(v) => setForm({ ...form, email_template_id: v })}>
                            <SelectTrigger className="w-full" aria-invalid={!!errors.email_template_id}><SelectValue placeholder="Choose a template…" /></SelectTrigger>
                            <SelectContent>
                                {templates.map((t) => (
                                    <SelectItem key={t.id} value={String(t.id)}>
                                        {t.name}{t.status !== 'active' && ` (${t.status})`}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </Field>
                    <Field label="Send daily at" error={errors.send_time}>
                        <Input type="time" value={form.send_time} onChange={(e) => setForm({ ...form, send_time: e.target.value })} />
                    </Field>
                    <Field label="Send as" error={errors.sender_user_id}>
                        <Select value={form.sender_user_id || undefined} onValueChange={(v) => setForm({ ...form, sender_user_id: v })}>
                            <SelectTrigger className="w-full"><SelectValue placeholder="First admin" /></SelectTrigger>
                            <SelectContent>{senders.map((s) => <SelectItem key={s.id} value={String(s.id)}>{s.name} · {s.email}</SelectItem>)}</SelectContent>
                        </Select>
                    </Field>
                    <Button variant="outline" onClick={() => save.mutate(automation.enabled)} disabled={!dirty || save.isPending}>
                        {save.isPending ? <Loader2 className="size-4 animate-spin" /> : <Save className="size-4" />} Save
                    </Button>
                </div>

                <div className="rounded-xl border">
                    <div className="flex flex-wrap items-center justify-between gap-2 border-b bg-muted/40 px-4 py-2.5">
                        <p className="flex items-center gap-2 text-sm font-medium">
                            <Users className="size-4 text-muted-foreground" /> Today&apos;s recipients
                            {recipients.data && <span className="font-normal text-muted-foreground">· {ready} to send of {recipients.data.length}</span>}
                        </p>
                        <Button size="sm" onClick={() => setConfirmRun(true)} disabled={!deliveryOn || !automation.email_template_id || ready === 0 || run.isPending || dirty}>
                            {run.isPending ? <Loader2 className="size-4 animate-spin" /> : <Play className="size-4" />} Run now
                        </Button>
                    </div>
                    {recipients.isLoading ? (
                        <Skeleton className="m-4 h-16" />
                    ) : !recipients.data?.length ? (
                        <EmptyState icon={Icon} title="Nobody today" description="No one matches this automation today." />
                    ) : (
                        <ul className="max-h-72 divide-y overflow-y-auto">
                            {recipients.data.map((r, i) => (
                                <li key={i} className="flex items-center justify-between gap-3 px-4 py-2.5 text-sm">
                                    <span className="min-w-0">
                                        {r.client_id ? <Link to={`/people/${r.client_id}`} className="font-medium hover:underline">{r.name}</Link> : <span className="font-medium">{r.name}</span>}
                                        <span className="block truncate text-xs text-muted-foreground">
                                            {r.email ?? 'no email'} · {r.policy_id ? <Link to={`/clients/${r.policy_id}`} className="hover:underline">{r.detail}</Link> : r.detail}
                                        </span>
                                    </span>
                                    <StatusBadge status={r.status} />
                                </li>
                            ))}
                        </ul>
                    )}
                </div>

                <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted-foreground">
                    <span className="flex items-center gap-1"><Clock className="size-3.5" /> Last run: {automation.last_run_at ? `${dateTime(automation.last_run_at)} (${last?.trigger === 'manual' ? 'Run now' : 'scheduled'})` : 'never'}</span>
                    {last && !last.error && <span>{last.sent} sent · {last.failed} failed · {last.skipped} skipped</span>}
                    {last?.error && <span className="text-destructive">{last.error}</span>}
                    {last?.items.some((i) => i.status === 'failed') && (
                        <span className="text-destructive">Failed: {last.items.filter((i) => i.status === 'failed').slice(0, 3).map((i) => `${i.name} (${i.reason})`).join('; ')}</span>
                    )}
                </div>
            </CardContent>

            <ConfirmDialog
                open={confirmRun}
                onOpenChange={setConfirmRun}
                title={`Send ${ready} email${ready === 1 ? '' : 's'} now?`}
                description={`"${automation.template?.name}" will be sent to today's ${ready} recipient${ready === 1 ? '' : 's'}. People already emailed today are skipped.`}
                confirmLabel="Send now"
                onConfirm={() => run.mutate()}
                loading={run.isPending}
            />
        </Card>
    );
}

export default function AutomationsPage() {
    const index = useQuery({
        queryKey: ['automations'],
        queryFn: async () => (await api.get<IndexResponse>('/automations')).data,
        // Keep the scheduler status, on/off state and last-run results live while the page is open.
        refetchInterval: LIVE_MS,
        refetchOnWindowFocus: true,
    });
    const now = useNow();
    const templates = useQuery({
        queryKey: ['email-templates', 'automation-options'],
        queryFn: async () => (await api.get<Paginated<EmailTemplate>>('/email-templates', { params: { per_page: 100, sort: 'name' } })).data.data,
    });

    return (
        <div className="grid gap-5">
            <PageHeader
                title="Automations"
                description="Emails sent automatically every day: birthday greetings, payment reminders due today, and policy anniversaries."
                actions={
                    index.dataUpdatedAt > 0 && (
                        <span className="flex items-center gap-2 text-xs text-muted-foreground" aria-live="polite">
                            <span className={cn('size-2 rounded-full', index.isError ? 'bg-destructive' : 'animate-pulse bg-emerald-500')} aria-hidden />
                            {index.isError ? 'Live updates paused (connection problem)' : `Live · updated ${Math.max(0, Math.round((now - index.dataUpdatedAt) / 1000))}s ago`}
                        </span>
                    )
                }
            />

            {index.data && !index.data.email_delivery_enabled && (
                <Alert variant="destructive">
                    <AlertTriangle className="size-4" />
                    <AlertDescription>Email delivery is not set up (log-only mode), so automations cannot send. See the README, "Sending email".</AlertDescription>
                </Alert>
            )}
            {index.data && <SchedulerStatus lastSeen={index.data.scheduler_last_seen} />}

            {index.isLoading || !index.data ? (
                <div className="grid gap-5">{[0, 1].map((i) => <Skeleton key={i} className="h-80 rounded-xl" />)}</div>
            ) : (
                index.data.data.map((a) => <AutomationCard key={a.id} automation={a} senders={index.data.senders} templates={templates.data ?? []} deliveryOn={index.data.email_delivery_enabled} />)
            )}
        </div>
    );
}
