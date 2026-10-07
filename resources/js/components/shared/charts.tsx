import { useState, type ReactNode } from 'react';
import { Bar, BarChart, CartesianGrid, Cell, LabelList, Pie, PieChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { BarChart3, Table2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { money, moneyCompact, num, pct } from '@/lib/format';
import type { AgeDistribution, MonthlySales } from '@/lib/types';

/* Chart colors come from CSS tokens (app.css) so light/dark each use their own validated steps. */
export const SERIES = Array.from({ length: 8 }, (_, i) => `var(--chart-${i + 1})`);
const SEQ = Array.from({ length: 7 }, (_, i) => `var(--seq-${i + 1})`);
const AXIS = { fontSize: 12, fill: 'var(--muted-foreground)' };

/** Toggle between the chart and an accessible table of the same numbers. */
export function ChartOrTable({ chart, table }: { chart: ReactNode; table: ReactNode }) {
    const [mode, setMode] = useState<'chart' | 'table'>('chart');
    return (
        <div>
            <div className="mb-2 flex justify-end">
                <Button variant="ghost" size="sm" className="h-7 text-xs text-muted-foreground" onClick={() => setMode(mode === 'chart' ? 'table' : 'chart')} aria-pressed={mode === 'table'}>
                    {mode === 'chart' ? <Table2 className="size-3.5" /> : <BarChart3 className="size-3.5" />}
                    {mode === 'chart' ? 'View as table' : 'View as chart'}
                </Button>
            </div>
            {mode === 'chart' ? chart : table}
        </div>
    );
}

function TooltipBox({ title, rows }: { title: string; rows: [string, string][] }) {
    return (
        <div className="rounded-lg border bg-popover px-3 py-2 text-xs text-popover-foreground shadow-md">
            <p className="mb-1 font-semibold">{title}</p>
            {rows.map(([k, v]) => (
                <p key={k} className="flex justify-between gap-4">
                    <span className="text-muted-foreground">{k}</span>
                    <span className="font-medium tabular">{v}</span>
                </p>
            ))}
        </div>
    );
}

/** Jan–Dec APE bars: a single series, so one hue and no legend box. */
export function SalesBarChart({ data, height = 280 }: { data: MonthlySales; height?: number }) {
    const best = Math.max(...data.months.map((m) => m.total_ape));
    return (
        <ChartOrTable
            chart={
                <ResponsiveContainer width="100%" height={height}>
                    <BarChart data={data.months} margin={{ top: 20, right: 8, left: 0, bottom: 0 }} barCategoryGap="22%">
                        <CartesianGrid vertical={false} stroke="var(--border)" />
                        <XAxis dataKey="label" tickLine={false} axisLine={false} tick={AXIS} />
                        <YAxis tickLine={false} axisLine={false} tick={AXIS} tickFormatter={(v) => moneyCompact(v)} width={64} />
                        <Tooltip
                            cursor={{ fill: 'var(--muted)', opacity: 0.6 }}
                            content={({ active, payload }) => {
                                if (!active || !payload?.length) return null;
                                const m = payload[0].payload as MonthlySales['months'][number];
                                return (
                                    <TooltipBox
                                        title={`${m.label} ${data.year}`}
                                        rows={[
                                            ['APE', money(m.total_ape)],
                                            ['Policies', num(m.policy_count)],
                                            ['Year to date', money(m.running_total)],
                                            ['Rank in year', m.sales_rank ? `#${m.sales_rank}` : '—'],
                                        ]}
                                    />
                                );
                            }}
                        />
                        <Bar dataKey="total_ape" radius={[4, 4, 0, 0]} maxBarSize={36} name="APE">
                            {data.months.map((m) => (
                                <Cell key={m.month} fill="var(--chart-1)" fillOpacity={m.total_ape === best && best > 0 ? 1 : 0.78} />
                            ))}
                            {/* Label only the best month, not every bar. */}
                            <LabelList dataKey="total_ape" position="top" className="fill-foreground text-[11px] font-medium" formatter={(v: unknown) => (Number(v) === best && best > 0 ? moneyCompact(Number(v)) : '')} />
                        </Bar>
                    </BarChart>
                </ResponsiveContainer>
            }
            table={
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Month</TableHead>
                            <TableHead className="text-right">Policies</TableHead>
                            <TableHead className="text-right">APE</TableHead>
                            <TableHead className="text-right">Year to date</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {data.months.map((m) => (
                            <TableRow key={m.month}>
                                <TableCell>{m.label}</TableCell>
                                <TableCell className="text-right tabular">{num(m.policy_count)}</TableCell>
                                <TableCell className="text-right tabular">{money(m.total_ape)}</TableCell>
                                <TableCell className="text-right tabular">{money(m.running_total)}</TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            }
        />
    );
}

/**
 * Generation donut (by birth year). Generations are ordered, so they use a
 * single-hue sequential ramp (younger = lighter) with a legend that carries
 * label, birth years and value.
 */
export function AgeDonutChart({ data, height = 240, showYears = true }: { data: AgeDistribution; height?: number; /** Birth years next to each generation (off on the Dashboard). */ showYears?: boolean }) {
    const ranges = data.ranges;
    const total = ranges.reduce((s, r) => s + r.person_count, 0);
    const colorFor = (i: number, key: string) => (key === 'unknown' ? 'var(--chart-8)' : SEQ[Math.min(SEQ.length - 1, Math.round((i * (SEQ.length - 1)) / Math.max(1, ranges.length - 2)))]);
    const who = data.role === 'owner' ? 'policy owners' : 'policy insureds';

    if (total === 0) {
        return <p className="py-16 text-center text-sm text-muted-foreground">No {who} for this period.</p>;
    }

    return (
        <ChartOrTable
            chart={
                <div className="grid items-center gap-4 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
                    <div className="relative" style={{ height }}>
                        <ResponsiveContainer width="100%" height="100%">
                            <PieChart>
                                <Pie data={ranges} dataKey="person_count" nameKey="label" innerRadius="62%" outerRadius="92%" paddingAngle={1} stroke="var(--card)" strokeWidth={2}>
                                    {ranges.map((r, i) => (
                                        <Cell key={r.key} fill={colorFor(i, r.key)} />
                                    ))}
                                </Pie>
                                <Tooltip
                                    content={({ active, payload }) => {
                                        if (!active || !payload?.length) return null;
                                        const r = payload[0].payload as AgeDistribution['ranges'][number];
                                        return <TooltipBox title={showYears ? `${r.label} · ${r.years}` : r.label} rows={[[`${data.role === 'owner' ? 'Owners' : 'Insureds'}`, num(r.person_count)], ['Share', pct(r.pct)], ['Policies', num(r.policy_count)], ['APE', money(r.total_ape)]]} />;
                                    }}
                                />
                            </PieChart>
                        </ResponsiveContainer>
                        <div className="pointer-events-none absolute inset-0 grid place-items-center text-center">
                            <div>
                                <p className="text-xl font-semibold tabular">{num(total)}</p>
                                <p className="text-[11px] text-muted-foreground">{data.role === 'owner' ? 'owners' : 'insureds'}</p>
                            </div>
                        </div>
                    </div>
                    <ul className="grid gap-1.5 text-sm">
                        {ranges.map((r, i) => (
                            <li key={r.key} className="flex items-center justify-between gap-3">
                                <span className="flex items-center gap-2">
                                    <span className="size-2.5 rounded-sm" style={{ background: colorFor(i, r.key) }} aria-hidden />
                                    {r.label}
                                    {showYears && <span className="text-xs text-muted-foreground">{r.years}</span>}
                                </span>
                                <span className="text-muted-foreground tabular">
                                    {num(r.person_count)} <span className="text-xs">({pct(r.pct, 0)})</span>
                                </span>
                            </li>
                        ))}
                    </ul>
                </div>
            }
            table={
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Generation</TableHead>\n                            <TableHead>Born</TableHead>
                            <TableHead className="text-right">{data.role === 'owner' ? 'Owners' : 'Insureds'}</TableHead>
                            <TableHead className="text-right">Share</TableHead>
                            <TableHead className="text-right">Policies</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {ranges.map((r) => (
                            <TableRow key={r.key}>
                                <TableCell>{r.label}</TableCell>
                                <TableCell className="text-muted-foreground tabular">{r.years}</TableCell>
                                <TableCell className="text-right tabular">{num(r.person_count)}</TableCell>
                                <TableCell className="text-right tabular">{pct(r.pct)}</TableCell>
                                <TableCell className="text-right tabular">{num(r.policy_count)}</TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            }
        />
    );
}

/** Horizontal bars for categorical breakdowns (product, payment mode, status). */
export function HBarChart({ rows, valueKey, labelKey, format = (v: number) => num(v), height }: { rows: Record<string, unknown>[]; valueKey: string; labelKey: string; format?: (v: number) => string; height?: number }) {
    const h = height ?? Math.max(160, rows.length * 38);
    return (
        <ChartOrTable
            chart={
                <ResponsiveContainer width="100%" height={h}>
                    <BarChart data={rows} layout="vertical" margin={{ top: 0, right: 64, left: 0, bottom: 0 }} barCategoryGap="28%">
                        <CartesianGrid horizontal={false} stroke="var(--border)" />
                        <XAxis type="number" hide />
                        <YAxis type="category" dataKey={labelKey} tickLine={false} axisLine={false} tick={AXIS} width={150} />
                        <Tooltip cursor={{ fill: 'var(--muted)', opacity: 0.6 }} content={({ active, payload }) => (active && payload?.length ? <TooltipBox title={String(payload[0].payload[labelKey])} rows={[['Value', format(Number(payload[0].value))]]} /> : null)} />
                        <Bar dataKey={valueKey} fill="var(--chart-1)" radius={[0, 4, 4, 0]} maxBarSize={22}>
                            <LabelList dataKey={valueKey} position="right" className="fill-muted-foreground text-[11px]" formatter={(v: unknown) => format(Number(v))} />
                        </Bar>
                    </BarChart>
                </ResponsiveContainer>
            }
            table={
                <Table>
                    <TableBody>
                        {rows.map((r) => (
                            <TableRow key={String(r[labelKey])}>
                                <TableCell>{String(r[labelKey])}</TableCell>
                                <TableCell className="text-right tabular">{format(Number(r[valueKey]))}</TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            }
        />
    );
}

/** Categorical donut (gender, status) — categorical palette in fixed order + legend. */
export function CategoryDonut({ rows, valueKey, labelKey, height = 200 }: { rows: Record<string, unknown>[]; valueKey: string; labelKey: string; height?: number }) {
    const total = rows.reduce((s, r) => s + Number(r[valueKey]), 0);
    return (
        <div className="grid items-center gap-4 sm:grid-cols-2">
            <div style={{ height }}>
                <ResponsiveContainer width="100%" height="100%">
                    <PieChart>
                        <Pie data={rows} dataKey={valueKey} nameKey={labelKey} innerRadius="60%" outerRadius="92%" paddingAngle={1} stroke="var(--card)" strokeWidth={2}>
                            {rows.map((r, i) => (
                                <Cell key={String(r[labelKey])} fill={SERIES[i % SERIES.length]} />
                            ))}
                        </Pie>
                        <Tooltip content={({ active, payload }) => (active && payload?.length ? <TooltipBox title={String(payload[0].name)} rows={[['Count', num(Number(payload[0].value))], ['Share', pct((Number(payload[0].value) / (total || 1)) * 100)]]} /> : null)} />
                    </PieChart>
                </ResponsiveContainer>
            </div>
            <ul className="grid gap-1.5 text-sm">
                {rows.map((r, i) => (
                    <li key={String(r[labelKey])} className="flex items-center justify-between gap-3">
                        <span className="flex items-center gap-2 capitalize">
                            <span className="size-2.5 rounded-sm" style={{ background: SERIES[i % SERIES.length] }} aria-hidden />
                            {String(r[labelKey]).replace(/_/g, ' ')}
                        </span>
                        <span className="text-muted-foreground tabular">
                            {num(Number(r[valueKey]))} <span className="text-xs">({pct((Number(r[valueKey]) / (total || 1)) * 100, 0)})</span>
                        </span>
                    </li>
                ))}
            </ul>
        </div>
    );
}
