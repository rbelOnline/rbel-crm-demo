import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { ArrowLeft } from 'lucide-react';
import { Skeleton } from '@/components/ui/skeleton';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { PageHeader } from '@/components/shared/misc';
import { PolicyForm, type BeneficiaryFocus } from '@/pages/policy-form';
import { api } from '@/lib/api';
import { fullName } from '@/lib/format';
import type { Client, Policy } from '@/lib/types';

/**
 * Add / edit a client record (policy) as a full page:
 *  - /clients/new                 new client
 *  - /clients/new?owner={clientId} new policy for an existing Policy Owner (from their profile)
 *  - /clients/:id/edit            edit
 *  - /clients/:id/edit?add_beneficiary=1   edit, at a new beneficiary (policy page's "Add beneficiary")
 *  - /clients/:id/edit?beneficiary={id}    edit, at that beneficiary (policy page's beneficiary "Edit")
 */
export default function PolicyFormPage() {
    const { id } = useParams();
    const [params] = useSearchParams();
    const navigate = useNavigate();
    const ownerId = id ? null : params.get('owner');
    const focus: BeneficiaryFocus | undefined = !id
        ? undefined
        : params.get('add_beneficiary')
          ? { add: true }
          : params.get('beneficiary')
            ? { beneficiaryId: Number(params.get('beneficiary')) }
            : undefined;

    // Same query keys as the detail and profile pages, so their cached data is reused.
    const policy = useQuery({
        queryKey: ['policy', id],
        queryFn: async () => (await api.get<{ data: Policy }>(`/policies/${id}`)).data.data,
        enabled: !!id,
    });
    const owner = useQuery({
        queryKey: ['client', ownerId],
        queryFn: async () => (await api.get<{ data: Client }>(`/clients/${ownerId}`)).data.data,
        enabled: !!ownerId,
    });

    // Where Cancel and the back link go: the record, the owner's profile, or the list.
    const back = id ? `/clients/${id}` : ownerId ? `/people/${ownerId}` : '/clients';
    const backLabel = id ? `Policy ${policy.data?.policy_number ?? ''}`.trim() : ownerId ? (owner.data ? fullName(owner.data) : 'Client') : 'Clients';

    const loading = (id && policy.isLoading) || (ownerId && owner.isLoading);
    const failed = (id && (policy.isError || (!policy.isLoading && !policy.data))) || (ownerId && owner.isError);

    return (
        <div className="grid gap-6">
            <div>
                <Link to={back} className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground">
                    <ArrowLeft className="size-4" /> {backLabel}
                </Link>
                <div className="mt-2">
                    <PageHeader
                        title={id ? `Edit policy ${policy.data?.policy_number ?? ''}`.trim() : 'New client'}
                        description={`The policy and its beneficiaries are saved together. Contact details are saved on each client's profile${id || ownerId ? ' (changes here update it)' : ''}.`}
                    />
                </div>
            </div>

            {loading ? (
                <Skeleton className="h-[600px] rounded-xl" />
            ) : failed ? (
                <Alert variant="destructive">
                    <AlertDescription>
                        {id ? 'Policy not found.' : 'Client not found.'} <Link to="/clients" className="underline">Back to clients</Link>
                    </AlertDescription>
                </Alert>
            ) : (
                <PolicyForm
                    policy={id ? policy.data : null}
                    defaultOwner={owner.data ? { id: owner.data.id, name: fullName(owner.data) } : undefined}
                    onSaved={(p) => navigate(`/clients/${p.id}`, { replace: true })}
                    onCancel={() => navigate(back)}
                    focus={focus}
                />
            )}
        </div>
    );
}
