import { useRef, useState, type ChangeEvent } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { AlertTriangle, ArrowLeft, Download, Eye, FileText, Loader2, Pencil, Plus, RefreshCw, Trash2, Upload, UserRound, Users } from 'lucide-react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { DetailItem, EmptyState, RoleBadge, StatusBadge } from '@/components/shared/misc';
import { ConfirmDialog } from '@/components/shared/pickers';
import { ClientDocumentsCard } from '@/pages/client-documents-card';
import { api, errorMessage, fieldErrors, openFile } from '@/lib/api';
import { useAuth } from '@/hooks/use-auth';
import { beneficiaryType, date, dateTime, fileSize, fullName, label, money } from '@/lib/format';
import type { Beneficiary, ClientSummary, Policy } from '@/lib/types';

const MAX_MB = 10;
const ACCEPT = '.pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png';

function PersonCard({ role, person }: { role: 'owner' | 'insured'; person?: ClientSummary }) {
    return (
        <div className="rounded-xl border p-4">
            <div className="mb-3 flex items-center justify-between">
                <RoleBadge role={role} />
                <UserRound className="size-4 text-muted-foreground" />
            </div>
            {person ? (
                <>
                    <Link to={`/people/${person.id}`} className="text-base font-semibold hover:underline">
                        {fullName(person)}
                    </Link>
                    <p className="text-sm text-muted-foreground">
                        {person.age !== null ? `${person.age} years old` : 'Age unknown'} · {label(person.gender)}
                    </p>
                </>
            ) : (
                '—'
            )}
        </div>
    );
}

export default function PolicyDetailPage() {
    const { id } = useParams();
    const navigate = useNavigate();
    const qc = useQueryClient();
    const { user } = useAuth();
    const fileRef = useRef<HTMLInputElement>(null);
    const [deleting, setDeleting] = useState(false);
    const [deletingDoc, setDeletingDoc] = useState(false);
    const [removeBen, setRemoveBen] = useState<Beneficiary | null>(null);

    const { data: policy, isLoading, isError } = useQuery({
        queryKey: ['policy', id],
        queryFn: async () => (await api.get<{ data: Policy }>(`/policies/${id}`)).data.data,
    });

    const refresh = () => {
        qc.invalidateQueries({ queryKey: ['policy', id] });
        qc.invalidateQueries({ queryKey: ['policies'] });
    };

    const upload = useMutation({
        mutationFn: (file: File) => {
            const fd = new FormData();
            fd.append('document', file);
            return api.post(`/policies/${id}/document`, fd, { headers: { 'Content-Type': 'multipart/form-data' } });
        },
        onSuccess: () => {
            toast.success('Coverage document saved.');
            refresh();
        },
        onError: (e) => toast.error(fieldErrors(e).document ?? errorMessage(e)),
    });

    const removeDoc = useMutation({
        mutationFn: () => api.delete(`/policies/${id}/document`),
        onSuccess: () => {
            toast.success('Document deleted.');
            setDeletingDoc(false);
            refresh();
        },
        onError: (e) => toast.error(errorMessage(e)),
    });

    const removePolicy = useMutation({
        mutationFn: () => api.delete(`/policies/${id}`),
        onSuccess: () => {
            toast.success('Policy deleted.');
            ['policies', 'clients', 'dashboard', 'goals'].forEach((k) => qc.invalidateQueries({ queryKey: [k] }));
            navigate('/clients');
        },
        onError: (e) => toast.error(errorMessage(e)),
    });

    const removeBeneficiary = useMutation({
        mutationFn: (b: Beneficiary) => api.delete(`/policies/${id}/beneficiaries/${b.id}`),
        onSuccess: () => {
            toast.success('Beneficiary removed.');
            setRemoveBen(null);
            refresh();
        },
        onError: (e) => toast.error(errorMessage(e)),
    });

    const onFile = (e: ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0];
        e.target.value = '';
        if (!file) return;
        // Client-side checks for instant feedback; the server re-validates type and size.
        if (!/\.(pdf|jpe?g|png)$/i.test(file.name)) return toast.error('Only PDF, JPG or PNG files are allowed.');
        if (file.size > MAX_MB * 1024 * 1024) return toast.error(`File must be ${MAX_MB} MB or smaller.`);
        upload.mutate(file);
    };

    if (isLoading) return <Skeleton className="h-96 rounded-xl" />;
    if (isError || !policy) {
        return (
            <Alert variant="destructive">
                <AlertDescription>
                    Policy not found. <Link to="/clients" className="underline">Back to clients</Link>
                </AlertDescription>
            </Alert>
        );
    }

    const doc = policy.coverage_document;
    const benCount = (policy.beneficiaries ?? []).length;
    const benTotal = Math.round((policy.beneficiaries ?? []).reduce((s, b) => s + (b.allocation_percentage ?? 0), 0) * 100) / 100;

    return (
        <div className="grid gap-6">
            <div>
                <Link to="/clients" className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground">
                    <ArrowLeft className="size-4" /> Clients
                </Link>
                <div className="mt-2 flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
                    <div>
                        <div className="flex flex-wrap items-center gap-2">
                            <h1 className="text-2xl font-semibold tracking-tight">{policy.policy_number}</h1>
                            <StatusBadge status={policy.status} />
                            {policy.is_orphan && <span className="rounded-md bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800 dark:bg-amber-500/15 dark:text-amber-300">Orphan</span>}
                        </div>
                        <p className="mt-1 text-sm text-muted-foreground">
                            {policy.product?.name} · issued {date(policy.issued_date)}
                            {policy.is_orphan && ' · orphan policy'}
                        </p>
                    </div>
                    <div className="flex gap-2">
                        <Button variant="outline" onClick={() => navigate(`/clients/${policy.id}/edit`)}>
                            <Pencil className="size-4" /> Edit
                        </Button>
                        {user?.permissions.manage && (
                            <Button variant="ghost" className="text-destructive hover:text-destructive" onClick={() => setDeleting(true)} aria-label="Delete policy">
                                <Trash2 className="size-4" />
                            </Button>
                        )}
                    </div>
                </div>
            </div>

            <div className="grid gap-6 lg:grid-cols-3">
                <Card className="lg:col-span-2">
                    <CardHeader>
                        <CardTitle>Policy details</CardTitle>
                        <CardDescription>{policy.is_self_insured ? 'The owner is also the insured.' : 'The owner and the insured are different people.'}</CardDescription>
                    </CardHeader>
                    <CardContent className="grid gap-6">
                        <div className="grid gap-4 sm:grid-cols-2">
                            <PersonCard role="owner" person={policy.policy_owner} />
                            <PersonCard role="insured" person={policy.policy_insured} />
                        </div>
                        <dl className="grid grid-cols-2 gap-5 sm:grid-cols-3">
                            <DetailItem label="Product">{policy.product?.name}</DetailItem>
                            <DetailItem label="APE"><span className="tabular">{money(policy.ape)}</span></DetailItem>
                            <DetailItem label="Sum assured"><span className="tabular">{money(policy.sum_assured)}</span></DetailItem>
                            <DetailItem label="Fund types" className="col-span-full">
                                {policy.fund_types?.length ? (
                                    <ul className="flex flex-wrap gap-2">
                                        {policy.fund_types.map((f) => (
                                            <li key={f.id} className="flex items-center gap-2 rounded-lg border px-3 py-1 text-sm">
                                                {f.name}
                                            </li>
                                        ))}
                                    </ul>
                                ) : (
                                    '—'
                                )}
                            </DetailItem>
                            <DetailItem label="Mode of payment">{label(policy.mode_of_payment)}</DetailItem>
                            <DetailItem label="Issued date">{date(policy.issued_date)}</DetailItem>
                            <DetailItem label="Delivery date">{policy.policy_delivery_date ? date(policy.policy_delivery_date) : <StatusBadge status="pending" />}</DetailItem>
                            <DetailItem label="Status"><StatusBadge status={policy.status} /></DetailItem>
                            <DetailItem label="Status changed">{dateTime(policy.status_changed_at)}</DetailItem>
                            <DetailItem label="Orphan policy">{policy.is_orphan ? 'Yes' : 'No'}</DetailItem>
                            {policy.remarks && <DetailItem label="Remarks" className="col-span-full"><p className="whitespace-pre-line">{policy.remarks}</p></DetailItem>}
                        </dl>
                    </CardContent>
                </Card>

                <Card className="h-fit">
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2"><FileText className="size-4" /> Coverage document</CardTitle>
                        <CardDescription>PDF, JPG or PNG · max {MAX_MB} MB · stored privately</CardDescription>
                    </CardHeader>
                    <CardContent className="grid gap-3">
                        <input ref={fileRef} type="file" accept={ACCEPT} className="hidden" onChange={onFile} />
                        {doc ? (
                            <>
                                <div className="rounded-lg border bg-muted/40 p-3">
                                    <p className="truncate text-sm font-medium" title={doc.name}>{doc.name}</p>
                                    <p className="text-xs text-muted-foreground">{fileSize(doc.size)} · uploaded {dateTime(doc.uploaded_at)}</p>
                                </div>
                                <div className="grid grid-cols-2 gap-2">
                                    <Button variant="outline" size="sm" onClick={() => openFile(`/api/policies/${policy.id}/document`).catch((e) => toast.error(errorMessage(e)))}>
                                        <Eye className="size-4" /> View
                                    </Button>
                                    <Button variant="outline" size="sm" onClick={() => openFile(`/api/policies/${policy.id}/document/download`, doc.name).catch((e) => toast.error(errorMessage(e)))}>
                                        <Download className="size-4" /> Download
                                    </Button>
                                    <Button variant="outline" size="sm" disabled={upload.isPending} onClick={() => fileRef.current?.click()}>
                                        {upload.isPending ? <Loader2 className="size-4 animate-spin" /> : <RefreshCw className="size-4" />} Replace
                                    </Button>
                                    {user?.permissions.manage && (
                                        <Button variant="outline" size="sm" className="text-destructive" onClick={() => setDeletingDoc(true)}>
                                            <Trash2 className="size-4" /> Delete
                                        </Button>
                                    )}
                                </div>
                            </>
                        ) : (
                            <button
                                type="button"
                                onClick={() => fileRef.current?.click()}
                                disabled={upload.isPending}
                                className="flex flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed p-8 text-sm text-muted-foreground transition-colors hover:border-primary/50 hover:bg-accent/40"
                            >
                                {upload.isPending ? <Loader2 className="size-6 animate-spin" /> : <Upload className="size-6" />}
                                <span className="font-medium text-foreground">Upload coverage document</span>
                            </button>
                        )}
                    </CardContent>
                </Card>
            </div>

            <Card>
                <CardHeader className="flex flex-row items-start justify-between gap-4">
                    <div>
                        <CardTitle className="flex items-center gap-2"><Users className="size-4" /> Beneficiaries</CardTitle>
                        <CardDescription>
                            {!benCount ? (
                                'No beneficiaries yet.'
                            ) : benTotal === 100 ? (
                                'Total: 100%'
                            ) : (
                                <span className="inline-flex items-center gap-1 font-medium text-destructive" role="alert">
                                    <AlertTriangle className="size-3.5" /> Total is {benTotal}%. All beneficiaries together must total 100%.
                                </span>
                            )}
                        </CardDescription>
                    </div>
                    <Button size="sm" onClick={() => navigate(`/clients/${policy.id}/edit?add_beneficiary=1`)}>
                        <Plus className="size-4" /> Add
                    </Button>
                </CardHeader>
                <CardContent>
                    {!policy.beneficiaries?.length ? (
                        <EmptyState icon={Users} title="No beneficiaries" description="Add the people who will receive the policy benefit." />
                    ) : (
                        <div className="overflow-x-auto">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Beneficiary</TableHead>
                                        <TableHead>Relationship</TableHead>
                                        <TableHead className="hidden sm:table-cell">Designation</TableHead>
                                        <TableHead className="text-right">Percentage</TableHead>
                                        <TableHead className="hidden md:table-cell">Contact</TableHead>
                                        <TableHead className="w-20" />
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {policy.beneficiaries.map((b) => (
                                        <TableRow key={b.id}>
                                            <TableCell>
                                                <span className="font-medium">{fullName(b)}</span>
                                                {b.age !== null && <span className="ml-1 text-xs text-muted-foreground">({b.age})</span>}
                                            </TableCell>
                                            <TableCell>{label(b.relationship)}</TableCell>
                                            <TableCell className="hidden sm:table-cell">{beneficiaryType(b.beneficiary_type)}</TableCell>
                                            <TableCell className="text-right tabular">{b.allocation_percentage !== null ? `${b.allocation_percentage}%` : '—'}</TableCell>
                                            <TableCell className="hidden text-sm text-muted-foreground md:table-cell">{b.email ?? b.mobile_number ?? '—'}</TableCell>
                                            <TableCell className="text-right whitespace-nowrap">
                                                <Button variant="ghost" size="icon" className="size-8" onClick={() => navigate(`/clients/${policy.id}/edit?beneficiary=${b.id}`)} aria-label="Edit beneficiary"><Pencil className="size-3.5" /></Button>
                                                {user?.permissions.manage && (
                                                    <Button variant="ghost" size="icon" className="size-8 text-destructive" onClick={() => setRemoveBen(b)} aria-label="Remove beneficiary"><Trash2 className="size-3.5" /></Button>
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                    )}
                </CardContent>
            </Card>

            <ClientDocumentsCard policyId={policy.id} />
            <ConfirmDialog open={deleting} onOpenChange={setDeleting} title="Delete this policy?" description="The policy, its beneficiaries, documents and coverage document will be permanently deleted. The Policy Owner and Policy Insured are kept." onConfirm={() => removePolicy.mutate()} loading={removePolicy.isPending} />
            <ConfirmDialog open={deletingDoc} onOpenChange={setDeletingDoc} title="Delete coverage document?" description="The stored file will be permanently removed." onConfirm={() => removeDoc.mutate()} loading={removeDoc.isPending} />
            <ConfirmDialog open={!!removeBen} onOpenChange={(o) => !o && setRemoveBen(null)} title="Remove beneficiary?" description={`${fullName(removeBen)} will no longer be a beneficiary of this policy.`} confirmLabel="Remove" onConfirm={() => removeBen && removeBeneficiary.mutate(removeBen)} loading={removeBeneficiary.isPending} />
        </div>
    );
}
