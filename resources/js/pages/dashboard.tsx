import { useState } from 'react';
import { Link } from 'react-router-dom';
import { useQuery, keepPreviousData } from '@tanstack/react-query';
import { AlertTriangle, Cake, CalendarClock, CalendarDays, CircleDollarSign, Gift, Goal, Info, Mail, PackageCheck, Truck, UserMinus, Users, BellRing } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { SendEmailDialog } from '@/pages/email-templates';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import { Progress } from '@/components/ui/progress';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { KpiCard, PageHeader, StatusBadge, EmptyState } from '@/components/shared/misc';
import { AgeDonutChart, SalesBarChart } from '@/components/shared/charts';
import { api } from '@/lib/api';
import { useMeta } from '@/hooks/use-data';
import { useAuth } from '@/hooks/use-auth';
import { date, money, moneyCompact, MONTHS, num, pct, time12 } from '@/lib/format';
import type { AgeDistribution, MonthlySales, PersonRole } from '@/lib/types';
import { cn } from '@/lib/utils';

interface DueItem {
    type: 'premium' | 'reminder' | 'appointment' | 'delivery';
    title: string;
    subtitle: string;
    amount?: number;
    date: string;
    overdue: boolean;
    link: string;
    /** Email recipient: Policy Owner for premiums/deliveries, else the reminder's/appointment's client. */
    client_id: number | null;
    policy_id: number | null;
}

interface DashboardData {
    filters: { year: number; age_role: PersonRole; today: string };
    kpis: {
        total_clients: number;
        pending_delivery: number;
        appointments_upcoming: number;
        appointments_today: number;
        goals_active: number;
        goals_avg_progress: number;
        goals_achieved: number;
        inactive_clients: number;
        churn_rate_pct: number;
        churn_definition: string;
    };
    due_today: DueItem[];
    birthdays: { id: number; name: string; birthdate: string; day: number; turning: number; has_email: boolean }[];
    anniversaries: { id: number; policy_number: string; product: string; policy_owner: string; policy_insured: string; issued_date: string; anniversary_date: string; years: number; ape: number }[];
    sales: MonthlySales;
    age_distribution: AgeDistribution;
    upcoming_appointments: { id: number; title: string; client: string | null; client_id: number; date: string; time: string; status: string }[];
    goals: { id: number; title: string; target_amount: number; current_amount: number; progress: number; target_date: string; status: string }[];
}

const dueIcon = { premium: CircleDollarSign, reminder: BellRing, appointment: CalendarClock, delivery: Truck };

export default function DashboardPage() {
    const now = new Date();
    const { user } = useAuth();
    const meta = useMeta();
    // Issue year for Total Clients, churn, pending delivery, goals, generations and monthly sales. Defaults to this year.
    const [year, setYear] = useState(now.getFullYear());
    const thisYear = now.getFullYear();
    const thisMonth = now.getMonth() + 1;
    const [ageRole, setAgeRole] = useState<PersonRole>('owner');
    const [emailing, setEmailing] = useState<{ clientId: number; policyId: number | null } | null>(null);
    const canEmail = !!user?.permissions.manage;

    const { data, isLoading, isError, refetch } = useQuery({
        queryKey: ['dashboard', year, ageRole],
        queryFn: async () => (await api.get<{ data: DashboardData }>('/dashboard', { params: { year, age_role: ageRole } })).data.data,
        placeholderData: keepPreviousData,
    });

    // Newest first.
    const years = [...(meta.data?.years ?? [thisYear])].sort((a, b) => b - a);
    const k = data?.kpis;

    return (
        <div className="grid gap-6">
            <PageHeader
                title={`Good ${now.getHours() < 12 ? 'morning' : now.getHours() < 18 ? 'afternoon' : 'evening'}, ${user?.name.split(' ')[0]}`}
                description="Here's your book of business at a glance."
                actions={
                    <>
                        <div className="flex items-center gap-2 text-sm">
                            <span className="text-muted-foreground">Year</span>
                            <Select value={String(year)} onValueChange={(v) => setYear(Number(v))}>
                                <SelectTrigger className="w-[100px]" aria-label="Year">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {years.map((y) => (
                                        <SelectItem key={y} value={String(y)}>
                                            {y}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    </>
                }
            />

            {isError && (
                <Alert variant="destructive">
                    <AlertTriangle className="size-4" />
                    <AlertDescription>
                        The dashboard could not be loaded.{' '}
                        <button className="underline" onClick={() => refetch()}>
                            Retry
                        </button>
                    </AlertDescription>
                </Alert>
            )}

            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-5">
                {isLoading || !k ? (
                    Array.from({ length: 5 }).map((_, i) => <Skeleton key={i} className="h-[118px] rounded-xl" />)
                ) : (
                    <>
                        <KpiCard title="Total Clients" value={num(k.total_clients)} hint={`Client records issued in ${year}`} icon={Users} to="/clients" />
                        <KpiCard
                            title="Churn / Inactive Clients"
                            value={num(k.inactive_clients)}
                            hint={
                                <Tooltip>
                                    <TooltipTrigger className="inline-flex items-center gap-1 underline decoration-dotted underline-offset-2">
                                        {pct(k.churn_rate_pct)} churn rate <Info className="size-3" />
                                    </TooltipTrigger>
                                    <TooltipContent className="max-w-xs">{k.churn_definition}</TooltipContent>
                                </Tooltip>
                            }
                            icon={UserMinus}
                            tone="danger"
                        />
                        <KpiCard title="Pending Policy Delivery" value={num(k.pending_delivery)} hint={`In-force, not yet delivered · issued in ${year}`} icon={PackageCheck} tone="warning" />
                        <KpiCard
                            title="Appointments"
                            value={num(k.appointments_upcoming)}
                            hint={`${k.appointments_today} today · upcoming scheduled`}
                            icon={CalendarDays}
                            tone="success"
                            to="/appointments?status=scheduled&upcoming=1&sort=appointment_date&direction=asc"
                        />
                        <KpiCard title="Goals" value={num(k.goals_active)} hint={`Active, due in ${year} · ${pct(k.goals_avg_progress, 0)} avg progress`} icon={Goal} />
                    </>
                )}
            </div>

            <div className="grid gap-6 xl:grid-cols-3">
                <Card className="xl:col-span-2">
                    <CardHeader className="flex flex-row items-start justify-between gap-4">
                        <div>
                            <CardTitle>Monthly Sales · {year}</CardTitle>
                            <CardDescription>Annualized premium (APE) of new policies issued, excluding postponed</CardDescription>
                        </div>
                        {data && (
                            <div className="text-right">
                                <p className="text-xl font-semibold tabular">{moneyCompact(data.sales.total_ape)}</p>
                                <p className={cn('text-xs', (data.sales.yoy_change_pct ?? 0) >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-destructive')}>
                                    {data.sales.yoy_change_pct === null ? `${num(data.sales.policy_count)} policies` : `${data.sales.yoy_change_pct >= 0 ? '▲' : '▼'} ${Math.abs(data.sales.yoy_change_pct)}% vs ${year - 1}`}
                                </p>
                            </div>
                        )}
                    </CardHeader>
                    <CardContent>{data ? <SalesBarChart data={data.sales} /> : <Skeleton className="h-[280px]" />}</CardContent>
                </Card>

                <Card>
                    <CardHeader className="gap-3">
                        <div>
                            <CardTitle>Generations</CardTitle>
                            <CardDescription>
                                Distinct {ageRole === 'owner' ? 'policy owners' : 'policy insureds'} in {year}, by birth year
                            </CardDescription>
                        </div>
                        <div className="flex items-center gap-2 text-sm">
                            <span className="text-muted-foreground">Filter by:</span>
                            <Tabs value={ageRole} onValueChange={(v) => setAgeRole(v as PersonRole)}>
                                <TabsList className="h-8">
                                    <TabsTrigger value="owner" className="text-xs">Policy Owner</TabsTrigger>
                                    <TabsTrigger value="insured" className="text-xs">Policy Insured</TabsTrigger>
                                </TabsList>
                            </Tabs>
                        </div>
                    </CardHeader>
                    <CardContent>{data ? <AgeDonutChart data={data.age_distribution} showYears={false} /> : <Skeleton className="h-[240px]" />}</CardContent>
                </Card>
            </div>

            <div className="grid gap-6 lg:grid-cols-2 xl:grid-cols-3">
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <BellRing className="size-4 text-primary" /> Due Today / Reminders
                        </CardTitle>
                        <CardDescription>{date(data?.filters.today)} · premiums, follow-ups, appointments and deliveries</CardDescription>
                    </CardHeader>
                    <CardContent>
                        {!data ? (
                            <Skeleton className="h-48" />
                        ) : data.due_today.length === 0 ? (
                            <EmptyState icon={PackageCheck} title="All clear" description="Nothing needs action today." />
                        ) : (
                            <ul className="-mx-2 max-h-[360px] divide-y overflow-y-auto">
                                {data.due_today.map((item, i) => {
                                    const Icon = dueIcon[item.type];
                                    return (
                                        <li key={i} className="flex items-center gap-1">
                                            <Link to={item.link} className="flex min-w-0 flex-1 items-start gap-3 rounded-lg px-2 py-2.5 hover:bg-muted/60">
                                                <span className={cn('mt-0.5 grid size-8 shrink-0 place-items-center rounded-md', item.overdue ? 'bg-destructive/10 text-destructive' : 'bg-primary/10 text-primary')}>
                                                    <Icon className="size-4" aria-hidden />
                                                </span>
                                                <span className="min-w-0 flex-1">
                                                    <span className="block truncate text-sm font-medium">{item.title}</span>
                                                    <span className="block truncate text-xs text-muted-foreground">{item.subtitle}</span>
                                                </span>
                                                <span className="shrink-0 text-right text-xs">
                                                    {item.amount !== undefined && <span className="block font-medium tabular">{money(item.amount)}</span>}
                                                    {item.overdue && <span className="font-medium text-destructive">Overdue · {date(item.date, 'MMM d')}</span>}
                                                </span>
                                            </Link>
                                            {canEmail && item.client_id && <EmailButton onClick={() => setEmailing({ clientId: item.client_id!, policyId: item.policy_id })} />}
                                        </li>
                                    );
                                })}
                            </ul>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <Cake className="size-4 text-primary" /> Birthdays · {MONTHS[thisMonth - 1]}
                        </CardTitle>
                        <CardDescription>Client birthdays this month and the age they turn this year</CardDescription>
                    </CardHeader>
                    <CardContent>
                        {!data ? (
                            <Skeleton className="h-48" />
                        ) : data.birthdays.length === 0 ? (
                            <EmptyState icon={Cake} title="No birthdays" description={`No client birthdays in ${MONTHS[thisMonth - 1]}.`} />
                        ) : (
                            <ul className="-mx-2 max-h-[360px] divide-y overflow-y-auto">
                                {data.birthdays.map((b) => {
                                    const isToday = b.day === now.getDate();
                                    return (
                                        <li key={b.id} className="flex items-center gap-1">
                                            <Link to={`/people/${b.id}`} className={cn('flex min-w-0 flex-1 items-center gap-3 rounded-lg px-2 py-2.5 hover:bg-muted/60', isToday && 'bg-accent')}>
                                                <span className="grid size-9 shrink-0 place-items-center rounded-md bg-muted text-center leading-none">
                                                    <span className="text-sm font-semibold tabular">{b.day}</span>
                                                </span>
                                                <span className="min-w-0 flex-1">
                                                    <span className="block truncate text-sm font-medium">{b.name}</span>
                                                    <span className="block text-xs text-muted-foreground">Turns {b.turning}{isToday && ' · today 🎉'}</span>
                                                </span>
                                            </Link>
                                            {canEmail && <EmailButton disabled={!b.has_email} onClick={() => setEmailing({ clientId: b.id, policyId: null })} />}
                                        </li>
                                    );
                                })}
                            </ul>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <Gift className="size-4 text-primary" /> Policy Anniversaries · {MONTHS[thisMonth - 1]} {thisYear}
                        </CardTitle>
                        <CardDescription>In-force policies reaching an anniversary this month</CardDescription>
                    </CardHeader>
                    <CardContent>
                        {!data ? (
                            <Skeleton className="h-48" />
                        ) : data.anniversaries.length === 0 ? (
                            <EmptyState icon={Gift} title="No anniversaries" description="No in-force policies reach an anniversary this month." />
                        ) : (
                            <ul className="-mx-2 max-h-[360px] divide-y overflow-y-auto">
                                {data.anniversaries.map((a) => (
                                    <li key={a.id}>
                                        <Link to={`/clients/${a.id}`} className="flex items-center gap-3 rounded-lg px-2 py-2.5 hover:bg-muted/60">
                                            <span className="grid size-9 shrink-0 place-items-center rounded-md bg-primary/10 text-sm font-semibold text-primary tabular">{a.years}y</span>
                                            <span className="min-w-0 flex-1">
                                                <span className="block truncate text-sm font-medium">
                                                    {a.policy_number} · {a.product}
                                                </span>
                                                <span className="block truncate text-xs text-muted-foreground">
                                                    Owner: {a.policy_owner} · Insured: {a.policy_insured}
                                                </span>
                                            </span>
                                            <span className="shrink-0 text-xs text-muted-foreground">{date(a.anniversary_date, 'MMM d')}</span>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <CalendarClock className="size-4 text-primary" /> Upcoming Appointments
                        </CardTitle>
                        <CardDescription>Next 14 days</CardDescription>
                    </CardHeader>
                    <CardContent>
                        {!data ? (
                            <Skeleton className="h-40" />
                        ) : data.upcoming_appointments.length === 0 ? (
                            <EmptyState icon={CalendarDays} title="No upcoming appointments" action={<Link to="/appointments" className="text-sm font-medium text-primary">Schedule one</Link>} />
                        ) : (
                            <ul className="-mx-2 divide-y">
                                {data.upcoming_appointments.map((a) => (
                                    <li key={a.id} className="flex items-center gap-3 px-2 py-2.5">
                                        <span className="w-14 shrink-0 text-center">
                                            <span className="block text-xs text-muted-foreground uppercase">{date(a.date, 'MMM')}</span>
                                            <span className="block text-lg leading-tight font-semibold tabular">{date(a.date, 'd')}</span>
                                        </span>
                                        <span className="min-w-0 flex-1">
                                            <span className="block truncate text-sm font-medium">{a.title}</span>
                                            <Link to={`/people/${a.client_id}`} className="block truncate text-xs text-muted-foreground hover:underline">
                                                {time12(a.time)} · {a.client}
                                            </Link>
                                        </span>
                                        <StatusBadge status={a.status} />
                                    </li>
                                ))}
                            </ul>
                        )}
                    </CardContent>
                </Card>

                <Card className="lg:col-span-2">
                    <CardHeader className="flex flex-row items-center justify-between">
                        <div>
                            <CardTitle className="flex items-center gap-2">
                                <Goal className="size-4 text-primary" /> Goals
                            </CardTitle>
                            <CardDescription>Active goals, nearest target date first</CardDescription>
                        </div>
                        <Link to="/goals" className="text-sm font-medium text-primary hover:underline">
                            View all
                        </Link>
                    </CardHeader>
                    <CardContent>
                        {!data ? (
                            <Skeleton className="h-40" />
                        ) : data.goals.length === 0 ? (
                            <EmptyState icon={Goal} title="No active goals" />
                        ) : (
                            <ul className="grid gap-4 sm:grid-cols-2">
                                {data.goals.map((g) => (
                                    <li key={g.id} className="rounded-lg border p-4">
                                        <div className="flex items-start justify-between gap-2">
                                            <p className="text-sm font-medium">{g.title}</p>
                                            <span className="text-sm font-semibold tabular">{pct(g.progress, 0)}</span>
                                        </div>
                                        <Progress value={g.progress} className="mt-3 h-2" aria-label={`${g.title} progress`} />
                                        <p className="mt-2 flex justify-between text-xs text-muted-foreground">
                                            <span className="tabular">
                                                {num(g.current_amount)} / {num(g.target_amount)}
                                            </span>
                                            <span>Due {date(g.target_date)}</span>
                                        </p>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </CardContent>
                </Card>
            </div>

            <SendEmailDialog open={!!emailing} onOpenChange={(o) => !o && setEmailing(null)} clientId={emailing?.clientId} policyId={emailing?.policyId} />
        </div>
    );
}

function EmailButton({ onClick, disabled }: { onClick: () => void; disabled?: boolean }) {
    const text = disabled ? 'No email address on file' : 'Send email from a template';
    return (
        <Tooltip>
            <TooltipTrigger asChild>
                {/* span keeps the tooltip working when the button is disabled */}
                <span tabIndex={disabled ? 0 : -1}>
                    <Button variant="ghost" size="icon" className="size-8 shrink-0 text-muted-foreground hover:text-primary" onClick={onClick} disabled={disabled} aria-label={text}>
                        <Mail className="size-4" />
                    </Button>
                </span>
            </TooltipTrigger>
            <TooltipContent>{text}</TooltipContent>
        </Tooltip>
    );
}
