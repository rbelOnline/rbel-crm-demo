import { useState } from 'react';
import { Link } from 'react-router-dom';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { Activity, FileWarning, Info, TrendingUp, UserMinus, Users } from 'lucide-react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { KpiCard, PageHeader } from '@/components/shared/misc';
import { AgeDonutChart, CategoryDonut, HBarChart, SalesBarChart } from '@/components/shared/charts';
import { ClientPicker } from '@/components/shared/pickers';
import { api } from '@/lib/api';
import { useMeta } from '@/hooks/use-data';
import { date, fullName, label, money, moneyCompact, num, pct } from '@/lib/format';
import type { AgeDistribution, MonthlySales, PersonRole } from '@/lib/types';

interface AnalyticsData {
    filters: { year: number | null; role: PersonRole };
    sales: MonthlySales;
    annual_sales: { year: number; policy_count: number; total_ape: number; cumulative_ape: number; yoy_change_pct: number | null }[];
    age_distribution: AgeDistribution;
    gender_distribution: { gender: string; person_count: number; pct: number }[];
    policies: {
        summary: Record<string, number>;
        by_status: { status: string; policy_count: number; total_ape: number }[];
        by_product: { product: string; plan_type: string; policy_count: number; total_ape: number; ape_share_pct: number; ape_rank: number }[];
        by_payment_mode: { mode_of_payment: string; policy_count: number; total_ape: number }[];
        delivery_aging: { aging_bucket: string; policy_count: number; oldest_days: number }[];
    };
    top_clients: { client_id: number; first_name: string; middle_name: string | null; last_name: string; policy_count: number; total_ape: number; ape_rank: number; share_pct: number; cumulative_share_pct: number }[];
    retention: {
        definition: string;
        active_clients: number;
        inactive_clients: number;
        completed_clients: number;
        prospects: number;
        churn_rate_pct: number;
        retention_rate_pct: number;
        recently_inactive: { client_id: number; first_name: string; middle_name: string | null; last_name: string; policies_owned: number; last_policy_date: string; last_status_change_at: string | null }[];
    };
}

/** Sales for one client in one role — shows why owner and insured must stay separate. */
function PersonSales({ year }: { year: number }) {
    const [personId, setPersonId] = useState<number | null>(null);
    const [role, setRole] = useState<PersonRole>('owner');
    const q = useQuery({
        queryKey: ['analytics', 'person-sales', year, personId, role],
        queryFn: async () => (await api.get<{ data: MonthlySales }>('/analytics/sales', { params: { year, client_id: personId, role } })).data.data,
        enabled: !!personId,
    });

    return (
        <Card>
            <CardHeader>
                <CardTitle>Sales for a specific client · {year}</CardTitle>
                <CardDescription>Choose a client and whether to count policies they <strong>own</strong> or policies <strong>insuring</strong> them.</CardDescription>
            </CardHeader>
            <CardContent className="grid gap-4">
                <div className="grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto]">
                    <ClientPicker value={personId} onChange={(id) => setPersonId(id)} placeholder="Select a client…" allowClear />
                    <Tabs value={role} onValueChange={(v) => setRole(v as PersonRole)}>
                        <TabsList>
                            <TabsTrigger value="owner">As Policy Owner</TabsTrigger>
                            <TabsTrigger value="insured">As Policy Insured</TabsTrigger>
                        </TabsList>
                    </Tabs>
                </div>
                {!personId ? (
                    <p className="py-8 text-center text-sm text-muted-foreground">Select a client to see their monthly APE.</p>
                ) : q.data ? (
                    <>
                        <p className="text-sm text-muted-foreground">
                            {num(q.data.policy_count)} {role === 'owner' ? 'owned' : 'insuring'} policies · <span className="font-medium text-foreground">{money(q.data.total_ape)}</span> APE
                        </p>
                        <SalesBarChart data={q.data} height={220} />
                    </>
                ) : (
                    <Skeleton className="h-[220px]" />
                )}
            </CardContent>
        </Card>
    );
}

export default function AnalyticsPage() {
    const meta = useMeta();
    const [year, setYear] = useState<string>(String(new Date().getFullYear()));
    const [role, setRole] = useState<PersonRole>('owner');
    const yearParam = year === 'all' ? undefined : Number(year);

    const { data, isLoading, isError } = useQuery({
        queryKey: ['analytics', yearParam, role],
        queryFn: async () => (await api.get<{ data: AnalyticsData }>('/analytics', { params: { year: yearParam, role } })).data.data,
        placeholderData: keepPreviousData,
    });

    const s = data?.policies.summary;
    const who = role === 'owner' ? 'Policy Owners' : 'Policy Insureds';

    return (
        <div className="grid gap-6">
            <PageHeader
                title="Analytics"
                description="Sales, demographics, policy health and retention."
                actions={
                    <>
                        <Select value={year} onValueChange={setYear}>
                            <SelectTrigger className="w-[120px]" aria-label="Year"><SelectValue /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All years</SelectItem>
                                {meta.data?.years.map((y) => <SelectItem key={y} value={String(y)}>{y}</SelectItem>)}
                            </SelectContent>
                        </Select>
                        <div className="flex items-center gap-2 rounded-lg border bg-card px-2 py-1">
                            <span className="text-xs text-muted-foreground">Filter by:</span>
                            <Tabs value={role} onValueChange={(v) => setRole(v as PersonRole)}>
                                <TabsList className="h-8">
                                    <TabsTrigger value="owner" className="text-xs">Policy Owner</TabsTrigger>
                                    <TabsTrigger value="insured" className="text-xs">Policy Insured</TabsTrigger>
                                </TabsList>
                            </Tabs>
                        </div>
                    </>
                }
            />

            {isError && <Alert variant="destructive"><AlertDescription>Analytics could not be loaded.</AlertDescription></Alert>}

            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                {isLoading || !s ? (
                    Array.from({ length: 4 }).map((_, i) => <Skeleton key={i} className="h-[118px] rounded-xl" />)
                ) : (
                    <>
                        <KpiCard title="Policies" value={num(s.total)} hint={`${num(s.active)} active · ${num(s.pending)} pending`} icon={Activity} />
                        <KpiCard title="Total APE" value={moneyCompact(s.total_ape)} hint={`Sum assured ${moneyCompact(s.total_sum_assured)}`} icon={TrendingUp} tone="success" />
                        <KpiCard title="Owner ≠ Insured" value={num(s.owner_not_insured)} hint={`${num(s.self_insured)} self-insured`} icon={Users} />
                        <KpiCard title="Lapsed · Orphan" value={`${num(s.lapsed)} · ${num(s.orphan)}`} hint={`${num(s.pending_delivery)} pending delivery`} icon={FileWarning} tone="warning" />
                    </>
                )}
            </div>

            <div className="grid gap-6 xl:grid-cols-3">
                <Card className="xl:col-span-2">
                    <CardHeader>
                        <CardTitle>Monthly APE · {data?.sales.year}</CardTitle>
                        <CardDescription>{data && `${num(data.sales.policy_count)} policies · ${money(data.sales.total_ape)}`}{year === 'all' && ' (current year shown)'}</CardDescription>
                    </CardHeader>
                    <CardContent>{data ? <SalesBarChart data={data.sales} /> : <Skeleton className="h-[280px]" />}</CardContent>
                </Card>
                <Card>
                    <CardHeader>
                        <CardTitle>Annual APE</CardTitle>
                        <CardDescription>Year-over-year change (LAG window)</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <Table>
                            <TableHeader><TableRow><TableHead>Year</TableHead><TableHead className="text-right">Policies</TableHead><TableHead className="text-right">APE</TableHead><TableHead className="text-right">YoY</TableHead></TableRow></TableHeader>
                            <TableBody>
                                {data?.annual_sales.slice().reverse().map((y) => (
                                    <TableRow key={y.year}>
                                        <TableCell className="font-medium">{y.year}</TableCell>
                                        <TableCell className="text-right tabular">{num(y.policy_count)}</TableCell>
                                        <TableCell className="text-right tabular">{moneyCompact(y.total_ape)}</TableCell>
                                        <TableCell className={`text-right tabular ${y.yoy_change_pct === null ? 'text-muted-foreground' : y.yoy_change_pct >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-destructive'}`}>
                                            {y.yoy_change_pct === null ? '—' : `${y.yoy_change_pct > 0 ? '+' : ''}${y.yoy_change_pct}%`}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
            </div>

            <div className="grid gap-6 lg:grid-cols-2">
                <Card>
                    <CardHeader className="flex flex-row items-start justify-between gap-2">
                        <div>
                            <CardTitle>Generations · {who}</CardTitle>
                            <CardDescription>
                                Grouped by the {role === 'owner' ? 'policy owner' : 'policy insured'}'s birth year
                            </CardDescription>
                        </div>

                    </CardHeader>
                    <CardContent>{data ? <AgeDonutChart data={data.age_distribution} /> : <Skeleton className="h-[240px]" />}</CardContent>
                </Card>
                <Card>
                    <CardHeader>
                        <CardTitle>Gender · {who}</CardTitle>
                        <CardDescription>Distinct people in the selected role</CardDescription>
                    </CardHeader>
                    <CardContent>{data ? <CategoryDonut rows={data.gender_distribution} valueKey="person_count" labelKey="gender" /> : <Skeleton className="h-[200px]" />}</CardContent>
                </Card>
            </div>

            <div className="grid gap-6 lg:grid-cols-2">
                <Card>
                    <CardHeader><CardTitle>APE by product</CardTitle><CardDescription>Ranked with RANK() and share of total</CardDescription></CardHeader>
                    <CardContent>{data ? <HBarChart rows={data.policies.by_product} valueKey="total_ape" labelKey="product" format={(v) => moneyCompact(v)} /> : <Skeleton className="h-60" />}</CardContent>
                </Card>
                <Card>
                    <CardHeader><CardTitle>Policies by status</CardTitle><CardDescription>Active, pending, lapsed and terminated</CardDescription></CardHeader>
                    <CardContent>{data ? <HBarChart rows={data.policies.by_status.map((r) => ({ ...r, status: label(r.status) }))} valueKey="policy_count" labelKey="status" /> : <Skeleton className="h-60" />}</CardContent>
                </Card>
                <Card>
                    <CardHeader><CardTitle>Policies by payment mode</CardTitle></CardHeader>
                    <CardContent>{data ? <HBarChart rows={data.policies.by_payment_mode.map((r) => ({ ...r, mode_of_payment: label(r.mode_of_payment) }))} valueKey="policy_count" labelKey="mode_of_payment" /> : <Skeleton className="h-48" />}</CardContent>
                </Card>
                <Card>
                    <CardHeader><CardTitle>Pending delivery aging</CardTitle><CardDescription>In-force policies not yet delivered (all years)</CardDescription></CardHeader>
                    <CardContent>
                        {data && !data.policies.delivery_aging.length ? <p className="py-8 text-center text-sm text-muted-foreground">No policies pending delivery.</p> : data ? <HBarChart rows={data.policies.delivery_aging} valueKey="policy_count" labelKey="aging_bucket" /> : <Skeleton className="h-48" />}
                    </CardContent>
                </Card>
            </div>

            <div className="grid gap-6 xl:grid-cols-5">
                <Card className="xl:col-span-3">
                    <CardHeader>
                        <CardTitle>Top {who} by APE</CardTitle>
                        <CardDescription>DENSE_RANK with share and cumulative share of APE</CardDescription>
                    </CardHeader>
                    <CardContent className="overflow-x-auto">
                        <Table>
                            <TableHeader><TableRow><TableHead className="w-12">#</TableHead><TableHead>{role === 'owner' ? 'Policy Owner' : 'Policy Insured'}</TableHead><TableHead className="text-right">Policies</TableHead><TableHead className="text-right">APE</TableHead><TableHead className="hidden text-right sm:table-cell">Share</TableHead><TableHead className="hidden text-right md:table-cell">Cumulative</TableHead></TableRow></TableHeader>
                            <TableBody>
                                {data?.top_clients.map((c) => (
                                    <TableRow key={c.client_id}>
                                        <TableCell className="tabular text-muted-foreground">{c.ape_rank}</TableCell>
                                        <TableCell><Link to={`/people/${c.client_id}`} className="font-medium hover:underline">{fullName(c)}</Link></TableCell>
                                        <TableCell className="text-right tabular">{c.policy_count}</TableCell>
                                        <TableCell className="text-right tabular">{money(c.total_ape)}</TableCell>
                                        <TableCell className="hidden text-right tabular sm:table-cell">{pct(c.share_pct)}</TableCell>
                                        <TableCell className="hidden text-right tabular md:table-cell">{pct(c.cumulative_share_pct)}</TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
                <Card className="xl:col-span-2">
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2"><UserMinus className="size-4" /> Retention &amp; churn</CardTitle>
                        <CardDescription className="flex gap-1.5"><Info className="mt-0.5 size-3.5 shrink-0" />{data?.retention.definition}</CardDescription>
                    </CardHeader>
                    <CardContent className="grid gap-4">
                        {data && (
                            <>
                                <div className="grid grid-cols-2 gap-3 text-center sm:grid-cols-4">
                                    <div className="rounded-lg bg-muted/50 p-3"><p className="text-xl font-semibold tabular">{num(data.retention.active_clients)}</p><p className="text-xs text-muted-foreground">Active</p></div>
                                    <div className="rounded-lg bg-muted/50 p-3"><p className="text-xl font-semibold tabular">{num(data.retention.inactive_clients)}</p><p className="text-xs text-muted-foreground">Inactive (churned)</p></div>
                                    <div className="rounded-lg bg-muted/50 p-3"><p className="text-xl font-semibold tabular">{num(data.retention.completed_clients)}</p><p className="text-xs text-muted-foreground">Completed</p></div>
                                    <div className="rounded-lg bg-muted/50 p-3"><p className="text-xl font-semibold tabular">{num(data.retention.prospects)}</p><p className="text-xs text-muted-foreground">Prospects</p></div>
                                </div>
                                <p className="text-sm">
                                    Churn rate <span className="font-semibold tabular">{pct(data.retention.churn_rate_pct)}</span> · retention <span className="font-semibold tabular">{pct(data.retention.retention_rate_pct)}</span>
                                </p>
                                <div>
                                    <p className="mb-2 text-xs font-medium tracking-wide text-muted-foreground uppercase">Recently inactive</p>
                                    <ul className="divide-y text-sm">
                                        {data.retention.recently_inactive.map((r) => (
                                            <li key={r.client_id} className="flex justify-between gap-2 py-2">
                                                <Link to={`/people/${r.client_id}`} className="hover:underline">{fullName(r)}</Link>
                                                <span className="text-xs text-muted-foreground">{r.last_status_change_at ? date(r.last_status_change_at) : '—'}</span>
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            </>
                        )}
                    </CardContent>
                </Card>
            </div>

            <PersonSales year={yearParam ?? new Date().getFullYear()} />

        </div>
    );
}
