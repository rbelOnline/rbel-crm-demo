import { useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft, CalendarDays, FilePlus2, Mail, MapPin, Pencil, Phone, Trash2, Briefcase, Cake, FileText } from 'lucide-react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { DetailItem, EmptyState, RoleBadge, StatusBadge } from '@/components/shared/misc';
import { ConfirmDialog } from '@/components/shared/pickers';
import { ClientFormDialog } from '@/pages/client-form';
import { SendEmailDialog } from '@/pages/email-templates';
import { api, errorMessage } from '@/lib/api';
import { useAuth } from '@/hooks/use-auth';
import { date, fullName, label, money, num, time12 } from '@/lib/format';
import type { Client, Policy, PolicyActivity } from '@/lib/types';

function PolicyList({ policies, counterpart }: { policies: Policy[]; counterpart: 'insured' | 'owner' }) {
    const navigate = useNavigate();
    if (!policies.length) return <p className="py-6 text-center text-sm text-muted-foreground">None.</p>;
    return (
        <div className="overflow-x-auto">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Policy No.</TableHead>
                        <TableHead>{counterpart === 'insured' ? 'Policy Insured' : 'Policy Owner'}</TableHead>
                        <TableHead className="hidden md:table-cell">Product</TableHead>
                        <TableHead className="text-right">APE</TableHead>
                        <TableHead className="hidden sm:table-cell">Issued</TableHead>
                        <TableHead>Status</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {policies.map((p) => {
                        const other = counterpart === 'insured' ? p.policy_insured : p.policy_owner;
                        return (
                            <TableRow key={p.id} className="cursor-pointer" onClick={() => navigate(`/clients/${p.id}`)}>
                                <TableCell className="font-medium whitespace-nowrap">{p.policy_number}</TableCell>
                                <TableCell>
                                    {p.is_self_insured ? <span className="text-muted-foreground">Self</span> : fullName(other)}
                                </TableCell>
                                <TableCell className="hidden md:table-cell">{p.product?.name}</TableCell>
                                <TableCell className="text-right tabular">{money(p.ape)}</TableCell>
                                <TableCell className="hidden whitespace-nowrap sm:table-cell">{date(p.issued_date)}</TableCell>
                                <TableCell>
                                    <StatusBadge status={p.status} />
                                </TableCell>
                            </TableRow>
                        );
                    })}
                </TableBody>
            </Table>
        </div>
    );
}

/** Profile of one client: their policies as Policy Owner and as Policy Insured. */
export default function PersonDetailPage() {
    const { id } = useParams();
    const navigate = useNavigate();
    const qc = useQueryClient();
    const { user } = useAuth();
    const [editing, setEditing] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const [emailing, setEmailing] = useState(false);

    const { data, isLoading, isError } = useQuery({
        queryKey: ['client', id],
        queryFn: async () => (await api.get<{ data: Client; meta: { policy_activity: PolicyActivity[] } }>(`/clients/${id}`)).data,
    });

    const remove = useMutation({
        mutationFn: () => api.delete(`/clients/${id}`),
        onSuccess: () => {
            toast.success('Client deleted.');
            ['clients', 'policies', 'dashboard', 'analytics', 'appointments', 'reminders', 'goals'].forEach((k) => qc.invalidateQueries({ queryKey: [k] }));
            navigate('/clients');
        },
        onError: (e) => {
            toast.error(errorMessage(e));
            setDeleting(false);
        },
    });

    if (isLoading) return <Skeleton className="h-96 rounded-xl" />;
    if (isError || !data) {
        return (
            <Alert variant="destructive">
                <AlertDescription>
                    Client not found. <Link to="/clients" className="underline">Back to clients</Link>
                </AlertDescription>
            </Alert>
        );
    }

    const c = data.data;
    const activity = data.meta.policy_activity;
    const name = fullName(c);

    return (
        <div className="grid gap-6">
            <div>
                <Link to="/clients" className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground">
                    <ArrowLeft className="size-4" /> Clients
                </Link>
                <div className="mt-2 flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
                    <div>
                        <div className="flex flex-wrap items-center gap-2">
                            <h1 className="text-2xl font-semibold tracking-tight">{name}</h1>
                            {c.client_status && <StatusBadge status={c.client_status} />}
                        </div>
                        <div className="mt-2 flex flex-wrap gap-1.5">
                            {!!c.owned_policies_count && <RoleBadge role="owner" />}
                            {!!c.insured_policies_count && <RoleBadge role="insured" />}
                        </div>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button variant="outline" onClick={() => setEditing(true)}>
                            <Pencil className="size-4" /> Edit
                        </Button>
                        {user?.permissions.manage && (
                            <Button variant="outline" onClick={() => setEmailing(true)} disabled={!c.email}>
                                <Mail className="size-4" /> Email
                            </Button>
                        )}
                        {c.is_policy_owner && (
                            <Button onClick={() => navigate(`/clients/new?owner=${c.id}`)}>
                                <FilePlus2 className="size-4" /> New policy
                            </Button>
                        )}
                        {user?.permissions.manage && (
                            <Button variant="ghost" className="text-destructive hover:text-destructive" onClick={() => setDeleting(true)} aria-label="Delete client">
                                <Trash2 className="size-4" />
                            </Button>
                        )}
                    </div>
                </div>
            </div>

            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <Card className="py-4"><CardContent className="px-5"><p className="text-sm text-muted-foreground">Policies owned</p><p className="text-2xl font-semibold tabular">{num(c.owned_policies_count ?? 0)}</p></CardContent></Card>
                <Card className="py-4"><CardContent className="px-5"><p className="text-sm text-muted-foreground">Insured under</p><p className="text-2xl font-semibold tabular">{num(c.insured_policies_count ?? 0)}</p></CardContent></Card>
                <Card className="py-4"><CardContent className="px-5"><p className="text-sm text-muted-foreground">APE as owner</p><p className="text-2xl font-semibold tabular">{money(c.total_ape_owned ?? 0)}</p></CardContent></Card>
                <Card className="py-4"><CardContent className="px-5"><p className="text-sm text-muted-foreground">In-force owned</p><p className="text-2xl font-semibold tabular">{num(c.in_force_owned_count ?? 0)}</p></CardContent></Card>
            </div>

            <div className="grid gap-6 lg:grid-cols-[320px_minmax(0,1fr)]">
                <Card className="h-fit">
                    <CardHeader>
                        <CardTitle>Profile</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <dl className="grid gap-4">
                            <DetailItem label="Contact">
                                <p className="flex items-center gap-2"><Mail className="size-3.5 text-muted-foreground" />{c.email ? <a href={`mailto:${c.email}`} className="hover:underline">{c.email}</a> : '—'}</p>
                                <p className="mt-1 flex items-center gap-2"><Phone className="size-3.5 text-muted-foreground" />{c.mobile_number ?? '—'}</p>
                            </DetailItem>
                            <DetailItem label="Birthdate">
                                <span className="flex items-center gap-2"><Cake className="size-3.5 text-muted-foreground" />{date(c.birthdate)} {c.age !== null && `· ${c.age} yrs`}</span>
                            </DetailItem>
                            <DetailItem label="Policy Owner">{c.is_policy_owner ? 'Yes' : 'No'}</DetailItem>
                            <DetailItem label="Gender">{label(c.gender)}</DetailItem>
                            <DetailItem label="Occupation"><span className="flex items-center gap-2"><Briefcase className="size-3.5 text-muted-foreground" />{c.occupation ?? '—'}</span></DetailItem>
                            <DetailItem label="Address"><span className="flex items-start gap-2"><MapPin className="mt-0.5 size-3.5 shrink-0 text-muted-foreground" />{c.address ?? '—'}</span></DetailItem>
                            <DetailItem label="Added">{date(c.created_at)}</DetailItem>
                        </dl>
                    </CardContent>
                </Card>

                <Tabs defaultValue="policies" className="min-w-0">
                    <TabsList className="w-full justify-start overflow-x-auto sm:w-auto">
                        <TabsTrigger value="policies">Policies</TabsTrigger>
                        <TabsTrigger value="activity">Activity</TabsTrigger>
                        <TabsTrigger value="appointments">Appointments</TabsTrigger>
                    </TabsList>

                    <TabsContent value="policies" className="grid gap-6">
                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2"><RoleBadge role="owner" /> Policies owned</CardTitle>
                                <CardDescription>{c.first_name} is the <strong>Policy Owner</strong> — the insured may be someone else.</CardDescription>
                            </CardHeader>
                            <CardContent><PolicyList policies={c.owned_policies ?? []} counterpart="insured" /></CardContent>
                        </Card>
                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2"><RoleBadge role="insured" /> Policies insuring {c.first_name}</CardTitle>
                                <CardDescription>{c.first_name} is the <strong>Policy Insured</strong> — the owner may be someone else.</CardDescription>
                            </CardHeader>
                            <CardContent><PolicyList policies={c.insured_policies ?? []} counterpart="owner" /></CardContent>
                        </Card>
                    </TabsContent>

                    <TabsContent value="activity">
                        <Card>
                            <CardHeader>
                                <CardTitle>Policy activity</CardTitle>
                                <CardDescription>Every policy {c.first_name} owns or is insured under, in issue order, with the gap since the previous one.</CardDescription>
                            </CardHeader>
                            <CardContent>
                                {!activity.length ? (
                                    <EmptyState icon={FileText} title="No policy activity" />
                                ) : (
                                    <ol className="relative ml-2 border-l pl-6">
                                        {activity.map((a) => (
                                            <li key={a.id} className="mb-5 last:mb-0">
                                                <span className="absolute -left-[5px] mt-1.5 size-2.5 rounded-full bg-primary" aria-hidden />
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <Link to={`/clients/${a.id}`} className="font-medium hover:underline">{a.policy_number}</Link>
                                                    <RoleBadge role={a.role} />
                                                    <StatusBadge status={a.status} />
                                                </div>
                                                <p className="mt-1 text-sm text-muted-foreground">
                                                    Issued {date(a.issued_date)} · {money(a.ape)} APE
                                                    {a.days_since_previous !== null && ` · ${num(a.days_since_previous)} days after previous`}
                                                </p>
                                                <p className="text-xs text-muted-foreground">Cumulative APE: {money(a.cumulative_ape)}</p>
                                            </li>
                                        ))}
                                    </ol>
                                )}
                            </CardContent>
                        </Card>
                    </TabsContent>

                    <TabsContent value="appointments">
                        <Card>
                            <CardHeader className="flex flex-row items-center justify-between">
                                <CardTitle>Recent appointments</CardTitle>
                                <Link to={`/appointments?client_id=${c.id}`} className="text-sm font-medium text-primary hover:underline">All appointments</Link>
                            </CardHeader>
                            <CardContent>
                                {!c.appointments?.length ? (
                                    <EmptyState icon={CalendarDays} title="No appointments" />
                                ) : (
                                    <ul className="divide-y">
                                        {c.appointments.map((a) => (
                                            <li key={a.id} className="flex items-center justify-between gap-3 py-3">
                                                <div>
                                                    <p className="text-sm font-medium">{a.title}</p>
                                                    <p className="text-xs text-muted-foreground">{date(a.appointment_date)} · {time12(a.appointment_time)}{a.location && ` · ${a.location}`}</p>
                                                </div>
                                                <StatusBadge status={a.status} />
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </CardContent>
                        </Card>
                    </TabsContent>
                </Tabs>
            </div>

            <ClientFormDialog open={editing} onOpenChange={setEditing} client={c} />
            <SendEmailDialog open={emailing} onOpenChange={setEmailing} clientId={c.id} />
            <ConfirmDialog
                open={deleting}
                onOpenChange={setDeleting}
                title="Delete this client?"
                description={`${name} will be permanently removed, together with every policy they own or are insured under (and those policies' beneficiaries and documents), and their appointments, reminders, goals and email history. This cannot be undone.`}
                onConfirm={() => remove.mutate()}
                loading={remove.isPending}
            />
        </div>
    );
}
