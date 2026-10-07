import { useEffect, useRef, useState } from 'react';
import { Controller, useFieldArray, useForm, useWatch } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Info, Loader2, Plus, Trash2, Users } from 'lucide-react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Switch } from '@/components/ui/switch';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Field } from '@/components/shared/misc';
import { ClientPicker, DatePicker, OwnerAutocomplete, type OwnerValue } from '@/components/shared/pickers';
import { FundTypesField } from '@/pages/fund-types';
import { MoneyInput } from '@/components/shared/money-input';
import { api } from '@/lib/api';
import { applyServerErrors, MOBILE_RE, personName, scrollToFirstError } from '@/lib/forms';
import { BENEFICIARY_TYPES, label, todayISO } from '@/lib/format';
import { useMeta } from '@/hooks/use-data';
import type { Client, Lead, Policy } from '@/lib/types';
import { cn } from '@/lib/utils';

const money = (msg: string) => z.string().trim().min(1, msg).refine((v) => !isNaN(Number(v)) && /^\d+(\.\d{1,2})?$/.test(v), 'Enter an amount with up to 2 decimals.');

/** A beneficiary's own details; id is kept (hidden) for existing ones, so editing updates that beneficiary. */
const beneficiarySchema = z.object({
    id: z.number().nullable(),
    first_name: personName().refine((v) => !!v, 'Required.'),
    middle_name: personName(),
    last_name: personName().refine((v) => !!v, 'Required.'),
    birthdate: z.string().nullable().refine((v) => !v || v <= todayISO(), 'Cannot be in the future.'),
    relationship: z.string().min(1, 'Required.'),
    beneficiary_type: z.enum(['primary', 'contingent']),
    allocation_percentage: z.string().trim().min(1, 'Required.').refine((v) => /^\d+(\.\d{1,2})?$/.test(v) && Number(v) > 0 && Number(v) <= 100, '1–100'),
});

/**
 * A role's client: name and contact details. They create a new client, or
 * update the linked one (editing, or an existing client picked). Required ones are checked in the
 * form's superRefine, since the insured's are skipped when the owner is the insured.
 */
const party = z.object({
    first_name: personName(),
    middle_name: personName(),
    last_name: personName(),
    birthdate: z.string().nullable().refine((v) => !v || v <= todayISO(), 'Birthdate cannot be in the future.'),
    gender: z.enum(['male', 'female', 'other']).or(z.literal('')),
    email: z.string().trim().email('Enter a valid email address.').max(191).or(z.literal('')),
    mobile_number: z.string().trim().regex(MOBILE_RE, 'Digits, spaces, +, - and parentheses only.').or(z.literal('')),
    address: z.string().trim().max(255),
    occupation: z.string().max(120),
});
type Party = z.infer<typeof party>;
const NAME_KEYS = ['first_name', 'middle_name', 'last_name'] as const;
const CONTACT_KEYS = ['birthdate', 'gender', 'email', 'mobile_number', 'address', 'occupation'] as const;
/** Every Policy Owner / Insured must have these (same as PolicyRequest::REQUIRED_PERSON_FIELDS). */
const REQUIRED: { key: keyof Party; name: string }[] = [
    { key: 'first_name', name: 'first name' },
    { key: 'last_name', name: 'last name' },
    { key: 'birthdate', name: 'birthdate' },
    { key: 'mobile_number', name: 'mobile number' },
    { key: 'email', name: 'email address' },
    { key: 'address', name: 'address' },
];
/** Same as PolicyRequest::MIN_OWNER_AGE. */
const MIN_OWNER_AGE = 18;

const schema = z
    .object({
        policy_number: z.string().trim().min(1, 'Policy number is required.').max(40).regex(/^[A-Za-z0-9][A-Za-z0-9\-/]*$/, 'Letters, numbers, dashes and slashes only.'),
        // Each role is an existing client (id) or, for a new client, a typed name.
        policy_owner_id: z.number().nullable(),
        // Or a lead picked as owner: converted into a client when the policy is saved.
        policy_owner_lead_id: z.number().nullable(),
        policy_owner: party,
        policy_insured_id: z.number().nullable(),
        policy_insured: party,
        insured_same_as_owner: z.boolean(),
        product_id: z.string().min(1, 'Select a product.'),
        ape: money('APE is required.'),
        sum_assured: money('Sum assured is required.').refine((v) => Number(v) > 0, 'Must be greater than 0.'),
        // Any number of funds from the Fund Types module.
        fund_type_ids: z.array(z.number()),
        issued_date: z.string({ message: 'Issued date is required.' }).nullable().refine((v) => !!v, 'Issued date is required.').refine((v) => !v || v <= todayISO(), 'Cannot be in the future.'),
        mode_of_payment: z.string().min(1, 'Required.'),
        status: z.string().min(1, 'Required.'),
        policy_delivery_date: z.string().nullable(),
        is_orphan: z.boolean(),
        remarks: z.string().max(5000).optional(),
        beneficiaries: z.array(beneficiarySchema).max(10),
    })
    .superRefine((v, ctx) => {
        const requireParty = (role: 'policy_owner' | 'policy_insured', text: string) => {
            for (const { key, name } of REQUIRED) {
                if (!String(v[role][key] ?? '').trim()) ctx.addIssue({ code: 'custom', path: [role, key], message: `${text} ${name} is required.` });
            }
        };
        requireParty('policy_owner', 'Owner');
        if (!v.insured_same_as_owner) requireParty('policy_insured', 'Insured');
        // The Policy Owner signs the contract: at least 18 on the issued date (matches PolicyRequest).
        const ownerBirthdate = v.policy_owner.birthdate;
        if (ownerBirthdate && v.issued_date) {
            const [y, m, d] = ownerBirthdate.split('-').map(Number);
            const adultOn = new Date(y + MIN_OWNER_AGE, m - 1, d); // Feb 29 rolls to Mar 1, as on the server
            const [iy, im, id] = v.issued_date.split('-').map(Number);
            if (adultOn > new Date(iy, im - 1, id)) {
                ctx.addIssue({ code: 'custom', path: ['policy_owner', 'birthdate'], message: `The Policy Owner must be at least ${MIN_OWNER_AGE} years old on the issued date.` });
            }
        }
        if (v.policy_delivery_date && v.issued_date && v.policy_delivery_date < v.issued_date) {
            ctx.addIssue({ code: 'custom', path: ['policy_delivery_date'], message: 'Cannot be before the issued date.' });
        }
        // All beneficiaries together (Primary and Secondary) must total exactly 100% (matches PolicyRequest::allocationErrors).
        if (v.beneficiaries.length) {
            const total = Math.round(v.beneficiaries.reduce((s, b) => s + (Number(b.allocation_percentage) || 0), 0) * 100) / 100;
            if (total !== 100) ctx.addIssue({ code: 'custom', path: ['beneficiaries'], message: `Beneficiary percentages must total 100% (currently ${total}%).` });
        }
    });

type Values = z.infer<typeof schema>;

const blankBeneficiary: Values['beneficiaries'][number] = { id: null, first_name: '', middle_name: '', last_name: '', birthdate: null, relationship: '', beneficiary_type: 'primary', allocation_percentage: '' };

const noName: Party = { first_name: '', middle_name: '', last_name: '', birthdate: null, gender: '', email: '', mobile_number: '', address: '', occupation: '' };

function toValues(p?: Policy | null, defaultOwnerId?: number): Values {
    if (!p) {
        return {
            policy_number: '', policy_owner_id: defaultOwnerId ?? null, policy_owner_lead_id: null, policy_owner: noName, policy_insured_id: null, policy_insured: noName, insured_same_as_owner: false,
            product_id: '', ape: '', sum_assured: '', fund_type_ids: [],
            issued_date: todayISO(), mode_of_payment: 'annual', status: 'pending', policy_delivery_date: null, is_orphan: false, remarks: '', beneficiaries: [],
        };
    }
    return {
        policy_number: p.policy_number,
        policy_owner_id: p.policy_owner_id,
        policy_owner_lead_id: null,
        policy_owner: noName,
        policy_insured_id: p.policy_insured_id,
        policy_insured: noName,
        insured_same_as_owner: p.policy_owner_id === p.policy_insured_id,
        product_id: String(p.product_id),
        ape: String(p.ape),
        sum_assured: String(p.sum_assured),
        fund_type_ids: p.fund_type_ids ?? [],
        issued_date: p.issued_date,
        mode_of_payment: p.mode_of_payment,
        status: p.status,
        policy_delivery_date: p.policy_delivery_date,
        is_orphan: p.is_orphan,
        remarks: p.remarks ?? '',
        beneficiaries: (p.beneficiaries ?? []).map((b) => ({
            id: b.id,
            first_name: b.first_name,
            middle_name: b.middle_name ?? '',
            last_name: b.last_name,
            birthdate: b.birthdate,
            relationship: b.relationship,
            beneficiary_type: b.beneficiary_type,
            allocation_percentage: b.allocation_percentage === null ? '' : String(b.allocation_percentage),
        })),
    };
}

/** Full record of a linked client (same query key as their profile page, so it is shared/cached). */
function useClientDetails(id: number | null | undefined) {
    return useQuery({
        queryKey: ['client', String(id)],
        queryFn: async () => (await api.get<{ data: Client }>(`/clients/${id}`)).data,
        enabled: !!id,
        select: (res) => res.data,
    });
}

/** A lead picked as Policy Owner: their details load into the form. */
function useLeadDetails(id: number | null | undefined) {
    return useQuery({
        queryKey: ['lead', String(id)],
        queryFn: async () => (await api.get<{ data: Lead }>(`/leads/${id}`)).data.data,
        enabled: !!id,
    });
}

/** New policy from a client's profile: written for that existing client as Policy Owner. */
type DefaultOwner = { id: number; name: string };

/** Add / edit a client record (policy) with its owner, insured and beneficiaries. Rendered by PolicyFormPage. */
/** Open the form at the beneficiaries: with a new blank one added, or at an existing one (by id). */
export type BeneficiaryFocus = { add: true } | { beneficiaryId: number };

export function PolicyForm({ policy, defaultOwner, onSaved, onCancel, focus }: { policy?: Policy | null; defaultOwner?: DefaultOwner; onSaved?: (p: Policy) => void; onCancel: () => void; focus?: BeneficiaryFocus }) {
    const qc = useQueryClient();
    const meta = useMeta();
    // Keyed on the id so a new defaultOwner object each render does not reset the form.
    const defaultOwnerId = defaultOwner?.id;
    const form = useForm<Values>({ resolver: zodResolver(schema), defaultValues: toValues(policy, defaultOwnerId) });
    const { register, control, handleSubmit, reset, setValue, setError, formState: { errors } } = form;
    const beneficiaries = useFieldArray({ control, name: 'beneficiaries' });
    const watched = useWatch({ control });
    // New client: owner/insured are typed names. Editing keeps the linked people (reassignable via picker).
    const typed = !policy;

    // Whose saved details ("client:5" / "lead:3") have been loaded into each role (reset with the form).
    const applied = useRef<Partial<Record<'policy_owner' | 'policy_insured', string>>>({});

    // Keyed on the policy id: a background refetch of the same policy must not wipe edits in progress.
    const policyId = policy?.id;
    // Row of the beneficiary to scroll to and highlight (from the policy page's Add / Edit beneficiary).
    const [highlight, setHighlight] = useState<number | null>(null);

    useEffect(() => {
        applied.current = {};
        const values = toValues(policy, defaultOwnerId);
        let row: number | null = null;
        if (focus && 'add' in focus) {
            // The only beneficiary gets 100%; otherwise the advisor splits it.
            values.beneficiaries = [...values.beneficiaries, { ...blankBeneficiary, allocation_percentage: values.beneficiaries.length ? '' : '100' }];
            row = values.beneficiaries.length - 1;
        } else if (focus && 'beneficiaryId' in focus) {
            const i = values.beneficiaries.findIndex((b) => b.id === focus.beneficiaryId);
            row = i >= 0 ? i : null;
        }
        reset(values);

        if (focus) {
            setHighlight(row);
            // After the rows render: bring the row (or the section) into view and put the cursor in its first name.
            requestAnimationFrame(() => {
                const target = document.getElementById(row !== null ? `beneficiary-${row}` : 'beneficiaries');
                target?.scrollIntoView({ behavior: 'smooth', block: 'center' });
                if (row !== null) document.getElementById(`ben_${row}_first`)?.focus({ preventScroll: true });
            });
            const t = window.setTimeout(() => setHighlight(null), 2500);
            return () => window.clearTimeout(t);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [policyId, defaultOwnerId, reset]);

    // Linked clients or a picked lead (editing, from a profile, or picked): load their current details.
    const ownerLeadId = watched.policy_owner_lead_id;
    const ownerId = !ownerLeadId ? watched.policy_owner_id : null;
    const insuredId = !watched.insured_same_as_owner ? watched.policy_insured_id : null;
    const ownerClient = useClientDetails(ownerId);
    const ownerLead = useLeadDetails(ownerLeadId);
    const ownerDetails = ownerLeadId ? ownerLead : ownerClient;
    const insuredDetails = useClientDetails(insuredId);
    const ownerValue: OwnerValue = watched.policy_owner_lead_id ? { kind: 'lead', id: watched.policy_owner_lead_id } : watched.policy_owner_id ? { kind: 'client', id: watched.policy_owner_id } : null;

    useEffect(() => {
        const sources = [
            ['policy_owner', ownerLeadId ? 'lead' : 'client', ownerLeadId ?? ownerId, ownerDetails.data],
            ['policy_insured', 'client', insuredId, insuredDetails.data],
        ] as const;
        for (const [role, kind, id, person] of sources) {
            // Apply once per person, so edits in progress are never overwritten by a refetch.
            if (!id || !person || person.id !== id || applied.current[role] === `${kind}:${id}`) continue;
            // A lead has no address: it stays empty for the advisor to fill in.
            const values = person as Partial<Record<(typeof NAME_KEYS)[number] | (typeof CONTACT_KEYS)[number], string | null>>;
            for (const key of [...NAME_KEYS, ...CONTACT_KEYS]) setValue(`${role}.${key}`, values[key] ?? (key === 'birthdate' ? null : ''));
            applied.current[role] = `${kind}:${id}`;
        }
    }, [ownerId, ownerLeadId, insuredId, ownerDetails.data, insuredDetails.data, setValue]);

    const save = useMutation({
        mutationFn: async (v: Values) => {
            const { policy_owner, policy_insured, ...rest } = v;
            // Name and details create the client (no id) or update the linked one; empty optional ones are cleared.
            const person = (p: Party) => Object.fromEntries([...NAME_KEYS, ...CONTACT_KEYS].map((k) => [k, (typeof p[k] === 'string' ? p[k].trim() : p[k]) || null]));
            const payload = {
                ...rest,
                policy_owner: person(policy_owner),
                ...(v.insured_same_as_owner ? { policy_insured_id: null } : { policy_insured: person(policy_insured) }),
                product_id: Number(v.product_id),
                ape: Number(v.ape),
                sum_assured: Number(v.sum_assured),
                remarks: v.remarks || null,
                beneficiaries: v.beneficiaries.map((b) => ({
                    ...(b.id ? { id: b.id } : {}),
                    first_name: b.first_name.trim(),
                    middle_name: b.middle_name.trim() || null,
                    last_name: b.last_name.trim(),
                    birthdate: b.birthdate || null,
                    relationship: b.relationship,
                    beneficiary_type: b.beneficiary_type,
                    allocation_percentage: Number(b.allocation_percentage),
                })),
            };
            const res = policy ? await api.put<{ data: Policy }>(`/policies/${policy.id}`, payload) : await api.post<{ data: Policy }>('/policies', payload);
            return res.data.data;
        },
        onSuccess: (saved) => {
            toast.success(policy ? 'Policy updated.' : 'Policy created.');
            ['policies', 'clients', 'client', 'client-summary', 'leads', 'owner-lookup', 'dashboard', 'analytics', 'goals'].forEach((k) => qc.invalidateQueries({ queryKey: [k] }));
            qc.invalidateQueries({ queryKey: ['policy', String(saved.id)] });
            onSaved?.(saved);
        },
        onError: (e) => {
            applyServerErrors(e, setError);
            scrollToFirstError('policy-form');
        },
    });

    // Unlinking a picked client: empty their fields so a new client is typed instead.
    const clearParty = (role: 'policy_owner' | 'policy_insured') => {
        for (const key of [...NAME_KEYS, ...CONTACT_KEYS]) setValue(`${role}.${key}`, key === 'birthdate' ? null : '');
        delete applied.current[role];
    };

    const selfInsured = !!watched.insured_same_as_owner;
    const ownerChosen = typed
        ? !!watched.policy_owner_id || !!watched.policy_owner_lead_id || !!(watched.policy_owner?.first_name && watched.policy_owner?.last_name)
        : !!watched.policy_owner_id || !!watched.policy_owner_lead_id;

    // The owner is an existing Policy Owner or a lead; never both.
    const pickOwner = (v: OwnerValue) => {
        setValue('policy_owner_id', v?.kind === 'client' ? v.id : null, { shouldValidate: false });
        setValue('policy_owner_lead_id', v?.kind === 'lead' ? v.id : null, { shouldValidate: false });
        if (!v) clearParty('policy_owner');
    };
    const insuredChosen = typed ? !!watched.policy_insured?.first_name || !!watched.policy_insured?.last_name : !!watched.policy_insured_id;

    // A linked client's name is edited here too (it updates their profile), once their details have loaded.
    const nameFields = (role: 'policy_owner' | 'policy_insured', idPrefix: string) => {
        const e = errors[role];
        const linkedId = role === 'policy_owner' ? ownerLeadId ?? ownerId : insuredId;
        const loading = !!linkedId && (role === 'policy_owner' ? ownerDetails : insuredDetails).isLoading;
        return (
            <div className="grid gap-2 sm:grid-cols-3">
                <Field label="First name" htmlFor={`${idPrefix}_first`} required error={e?.first_name?.message}>
                    <Input id={`${idPrefix}_first`} autoComplete="given-name" maxLength={80} readOnly={loading} placeholder={loading ? 'Loading…' : undefined} {...register(`${role}.first_name`)} aria-invalid={!!e?.first_name} />
                </Field>
                <Field label="Middle name" htmlFor={`${idPrefix}_middle`} error={e?.middle_name?.message}>
                    <Input id={`${idPrefix}_middle`} autoComplete="additional-name" maxLength={80} readOnly={loading} placeholder={loading ? 'Loading…' : undefined} {...register(`${role}.middle_name`)} aria-invalid={!!e?.middle_name} />
                </Field>
                <Field label="Last name" htmlFor={`${idPrefix}_last`} required error={e?.last_name?.message}>
                    <Input id={`${idPrefix}_last`} autoComplete="family-name" maxLength={80} readOnly={loading} placeholder={loading ? 'Loading…' : undefined} {...register(`${role}.last_name`)} aria-invalid={!!e?.last_name} />
                </Field>
            </div>
        );
    };

    const contactFields = (role: 'policy_owner' | 'policy_insured', idPrefix: string) => {
        const e = errors[role];
        return (
            <div className="mt-1 grid grid-cols-2 gap-2">
                <Field label="Birthdate" htmlFor={`${idPrefix}_birthdate`} required error={e?.birthdate?.message}>
                    <Controller
                        control={control}
                        name={`${role}.birthdate`}
                        render={({ field }) => (
                            <DatePicker id={`${idPrefix}_birthdate`} value={field.value || null} onChange={field.onChange} clearable invalid={!!e?.birthdate} disabled={(d) => d > new Date()} />
                        )}
                    />
                </Field>
                <Field label="Gender" htmlFor={`${idPrefix}_gender`} error={e?.gender?.message}>
                    <Controller
                        control={control}
                        name={`${role}.gender`}
                        render={({ field }) => (
                            <Select value={field.value || undefined} onValueChange={field.onChange}>
                                <SelectTrigger id={`${idPrefix}_gender`} className="w-full"><SelectValue placeholder="Select…" /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="male">Male</SelectItem>
                                    <SelectItem value="female">Female</SelectItem>
                                    <SelectItem value="other">Other</SelectItem>
                                </SelectContent>
                            </Select>
                        )}
                    />
                </Field>
                <Field label="Mobile number" htmlFor={`${idPrefix}_mobile`} required error={e?.mobile_number?.message}>
                    <Input id={`${idPrefix}_mobile`} placeholder="+63 9xx xxx xxxx" {...register(`${role}.mobile_number`)} aria-invalid={!!e?.mobile_number} />
                </Field>
                <Field label="Occupation" htmlFor={`${idPrefix}_occupation`} error={e?.occupation?.message}>
                    <Input id={`${idPrefix}_occupation`} {...register(`${role}.occupation`)} aria-invalid={!!e?.occupation} />
                </Field>
                <Field label="Email address" htmlFor={`${idPrefix}_email`} required error={e?.email?.message} className="col-span-2">
                    <Input id={`${idPrefix}_email`} type="email" {...register(`${role}.email`)} aria-invalid={!!e?.email} />
                </Field>
                <Field label="Address" htmlFor={`${idPrefix}_address`} required error={e?.address?.message} className="col-span-2">
                    <Input id={`${idPrefix}_address`} {...register(`${role}.address`)} aria-invalid={!!e?.address} />
                </Field>
            </div>
        );
    };

    const benCount = (watched.beneficiaries ?? []).length;
    const benTotal = Math.round((watched.beneficiaries ?? []).reduce((s, b) => s + (Number(b?.allocation_percentage) || 0), 0) * 100) / 100;

    // Owner and insured are different people: suggest the Policy Owner as a beneficiary,
    // unless someone with the owner's name is already listed.
    const owner = watched.policy_owner;
    const ownerFirst = (owner?.first_name ?? '').trim();
    const ownerLast = (owner?.last_name ?? '').trim();
    const sameAsOwner = (p?: { first_name?: string; last_name?: string }) =>
        (p?.first_name ?? '').trim().toLowerCase() === ownerFirst.toLowerCase() && (p?.last_name ?? '').trim().toLowerCase() === ownerLast.toLowerCase();
    const differentPeople = !selfInsured && insuredChosen && !(watched.policy_owner_id && watched.policy_owner_id === watched.policy_insured_id) && !sameAsOwner(watched.policy_insured);
    const suggestOwner = differentPeople && !!ownerFirst && !!ownerLast && !(watched.beneficiaries ?? []).some(sameAsOwner) && beneficiaries.fields.length < 10;
    const addOwnerAsBeneficiary = () =>
        beneficiaries.append({
            ...blankBeneficiary,
            first_name: ownerFirst,
            middle_name: (owner?.middle_name ?? '').trim(),
            last_name: ownerLast,
            birthdate: owner?.birthdate || null,
            // The only beneficiary so far gets 100%; otherwise the advisor splits it.
            allocation_percentage: benCount === 0 ? '100' : '',
        });

    return (
        <div className="grid gap-5">
                <form id="policy-form" onSubmit={handleSubmit((v) => save.mutate(v), () => scrollToFirstError('policy-form'))} className="grid gap-5" noValidate>
                    <div className="grid gap-4 rounded-xl border bg-muted/30 p-4 lg:grid-cols-2">
                        <div className="grid content-start gap-1.5">
                            <p className="text-sm font-semibold">Policy Owner</p>
                            <p className="-mt-1 text-xs text-muted-foreground">The person who owns and pays for the policy.</p>
                            {typed && defaultOwner && watched.policy_owner_id === defaultOwner.id ? (
                                <p className="rounded-md border bg-background px-3 py-2 text-sm">
                                    {defaultOwner.name} <span className="text-muted-foreground">· existing client</span>
                                </p>
                            ) : typed ? (
                                // New client: reuse an existing Policy Owner or a lead (their details load below), or type a new client.
                                <OwnerAutocomplete id="owner" value={ownerValue} onChange={pickOwner} allowClear placeholder="Search policy owner or lead (optional)…" invalid={!!errors.policy_owner_id || !!errors.policy_owner_lead_id} />
                            ) : (
                                // Editing: the owner can be swapped for another Policy Owner or a lead, but not left empty.
                                <OwnerAutocomplete id="owner" value={ownerValue} onChange={(v) => v && pickOwner(v)} placeholder="Search policy owner or lead…" invalid={!!errors.policy_owner_id || !!errors.policy_owner_lead_id} />
                            )}
                            {(errors.policy_owner_id?.message || errors.policy_owner_lead_id?.message) && (
                                <p className="text-xs font-medium text-destructive" role="alert">{errors.policy_owner_id?.message ?? errors.policy_owner_lead_id?.message}</p>
                            )}
                            {watched.policy_owner_lead_id ? (
                                <p className="text-xs text-muted-foreground">This lead becomes a client when you save. Add their address below.</p>
                            ) : (
                                typed && !defaultOwner && !watched.policy_owner_id && <p className="text-xs text-muted-foreground">Or enter a new client below.</p>
                            )}
                            {nameFields('policy_owner', 'owner')}
                            {contactFields('policy_owner', 'owner')}
                        </div>
                        <div className="grid content-start gap-1.5">
                            <p className="text-sm font-semibold">Policy Insured</p>
                            <p className="-mt-1 text-xs text-muted-foreground">The person whose life / health is covered.</p>
                            {selfInsured ? (
                                <p className="rounded-md border border-dashed px-3 py-2 text-sm text-muted-foreground">Same person as the Policy Owner</p>
                            ) : (
                                <>
                                    {!typed && <Controller control={control} name="policy_insured_id" render={({ field }) => <ClientPicker id="insured" value={field.value} onChange={(id) => field.onChange(id)} placeholder="Select policy insured…" />} />}
                                    {nameFields('policy_insured', 'insured')}
                                    {contactFields('policy_insured', 'insured')}
                                </>
                            )}
                        </div>
                        <div className="flex items-center gap-2 lg:col-span-2">
                            <Controller
                                control={control}
                                name="insured_same_as_owner"
                                render={({ field }) => <Switch id="same" checked={field.value} onCheckedChange={field.onChange} disabled={!ownerChosen} />}
                            />
                            <Label htmlFor="same" className="text-sm font-normal">
                                Owner is also the insured
                            </Label>
                            {!selfInsured && ownerChosen && insuredChosen && (
                                <span className="ml-auto flex items-center gap-1 text-xs text-muted-foreground">
                                    <Info className="size-3.5" /> Owner and insured are different people
                                </span>
                            )}
                        </div>
                    </div>

                    {/* Plan Details: the same boxed panel as Policy Owner / Insured above. */}
                    <div className="grid gap-4 rounded-xl border bg-muted/30 p-4">
                        <div>
                            <p className="text-sm font-semibold">Plan Details</p>
                            <p className="text-xs text-muted-foreground">The plan, premium, dates, status and the funds the policy is invested in.</p>
                        </div>
                        <div className="grid gap-4 sm:grid-cols-3">
                            <Field label="Policy number" htmlFor="policy_number" required error={errors.policy_number?.message}>
                                <Input id="policy_number" className="uppercase" {...register('policy_number')} aria-invalid={!!errors.policy_number} />
                            </Field>
                            <Field label="Product" htmlFor="product" required error={errors.product_id?.message} className="sm:col-span-2">
                                <Controller
                                    control={control}
                                    name="product_id"
                                    render={({ field }) => (
                                        <Select value={field.value || undefined} onValueChange={field.onChange}>
                                            <SelectTrigger id="product" className="w-full" aria-invalid={!!errors.product_id}>
                                                <SelectValue placeholder="Select product…" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {/* Inactive plans are hidden, except the one this record already uses. */}
                                                {meta.data?.products
                                                    .filter((p) => p.is_active !== false || String(p.id) === field.value)
                                                    .map((p) => (
                                                        <SelectItem key={p.id} value={String(p.id)}>
                                                            {p.name} <span className="text-muted-foreground">· {p.plan_type}{p.is_active === false && ' · inactive'}</span>
                                                        </SelectItem>
                                                    ))}
                                            </SelectContent>
                                        </Select>
                                    )}
                                />
                            </Field>
                            <Field label="APE (₱)" htmlFor="ape" required error={errors.ape?.message}>
                                <Controller control={control} name="ape" render={({ field }) => <MoneyInput id="ape" value={field.value} onChange={field.onChange} onBlur={field.onBlur} placeholder="0.00" aria-invalid={!!errors.ape} />} />
                            </Field>
                            <Field label="Sum assured (₱)" htmlFor="sum_assured" required error={errors.sum_assured?.message}>
                                <Controller control={control} name="sum_assured" render={({ field }) => <MoneyInput id="sum_assured" value={field.value} onChange={field.onChange} onBlur={field.onBlur} placeholder="0.00" aria-invalid={!!errors.sum_assured} />} />
                            </Field>
                            <Field label="Mode of payment" htmlFor="mode" required error={errors.mode_of_payment?.message}>
                                <Controller
                                    control={control}
                                    name="mode_of_payment"
                                    render={({ field }) => (
                                        <Select value={field.value} onValueChange={field.onChange}>
                                            <SelectTrigger id="mode" className="w-full"><SelectValue /></SelectTrigger>
                                            <SelectContent>
                                                {meta.data?.payment_modes.map((m) => <SelectItem key={m} value={m}>{label(m)}</SelectItem>)}
                                            </SelectContent>
                                        </Select>
                                    )}
                                />
                            </Field>
                            <Field label="Issued date" htmlFor="issued_date" required error={errors.issued_date?.message}>
                                <Controller control={control} name="issued_date" render={({ field }) => <DatePicker id="issued_date" value={field.value} onChange={field.onChange} invalid={!!errors.issued_date} disabled={(d) => d > new Date()} />} />
                            </Field>
                            <Field label="Delivery date" htmlFor="delivery" error={errors.policy_delivery_date?.message} hint="Leave empty while delivery is pending.">
                                <Controller control={control} name="policy_delivery_date" render={({ field }) => <DatePicker id="delivery" value={field.value} onChange={field.onChange} clearable placeholder="Pending delivery" invalid={!!errors.policy_delivery_date} disabled={(d) => d > new Date()} />} />
                            </Field>
                            <Field label="Status" htmlFor="status" required error={errors.status?.message}>
                                <Controller
                                    control={control}
                                    name="status"
                                    render={({ field }) => (
                                        <Select value={field.value} onValueChange={field.onChange}>
                                            <SelectTrigger id="status" className="w-full"><SelectValue /></SelectTrigger>
                                            <SelectContent>
                                                {meta.data?.policy_statuses.map((s) => <SelectItem key={s} value={s}>{label(s)}</SelectItem>)}
                                            </SelectContent>
                                        </Select>
                                    )}
                                />
                            </Field>
                            <Field label="Fund types" htmlFor="fund_types" hint="Optional. Add every fund the policy is invested in." error={errors.fund_type_ids?.message ?? (Array.isArray(errors.fund_type_ids) ? errors.fund_type_ids.find(Boolean)?.message : undefined)} className="sm:col-span-3">
                                <Controller
                                    control={control}
                                    name="fund_type_ids"
                                    render={({ field }) => <FundTypesField id="fund_types" value={field.value} onChange={field.onChange} options={meta.data?.fund_types ?? []} invalid={!!errors.fund_type_ids} />}
                                />
                            </Field>
                        </div>
                    </div>

                    <div className="grid gap-4 sm:grid-cols-3">
                        <div className="flex items-center gap-2 sm:col-span-3">
                            <Controller control={control} name="is_orphan" render={({ field }) => <Switch id="is_orphan" checked={field.value} onCheckedChange={field.onChange} />} />
                            <Label htmlFor="is_orphan" className="text-sm font-normal">Orphan policy</Label>
                        </div>
                        <Field label="Remarks" htmlFor="remarks" className="sm:col-span-3">
                            <Textarea id="remarks" rows={2} {...register('remarks')} />
                        </Field>
                    </div>

                    <Separator />

                    <div id="beneficiaries" className="grid scroll-mt-20 gap-3">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <div>
                                <h3 className="flex items-center gap-2 font-semibold"><Users className="size-4" /> Beneficiaries</h3>
                                <p className={cn('text-xs', benCount && benTotal !== 100 ? 'font-medium text-destructive' : 'text-muted-foreground')}>
                                    {benCount ? `Total: ${benTotal}% of 100%` : 'Optional. All beneficiaries together must total 100%.'}
                                </p>
                            </div>
                            <Button type="button" variant="outline" size="sm" onClick={() => beneficiaries.append({ ...blankBeneficiary })} disabled={beneficiaries.fields.length >= 10}>
                                <Plus className="size-4" /> Add beneficiary
                            </Button>
                        </div>
                        {suggestOwner && (
                            <div className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-dashed px-3 py-2 text-sm">
                                <span>
                                    <span className="text-muted-foreground">Suggested beneficiary:</span>{' '}
                                    <span className="font-medium">{[ownerFirst, owner?.middle_name?.trim(), ownerLast].filter(Boolean).join(' ')}</span>
                                    <span className="text-muted-foreground"> · Policy Owner</span>
                                </span>
                                <Button type="button" variant="secondary" size="sm" onClick={addOwnerAsBeneficiary}>
                                    <Plus className="size-4" /> Add as beneficiary
                                </Button>
                            </div>
                        )}
                        {errors.beneficiaries?.message && <p className="text-xs font-medium text-destructive" role="alert">{errors.beneficiaries.message}</p>}
                        {errors.beneficiaries?.root?.message && <p className="text-xs font-medium text-destructive" role="alert">{errors.beneficiaries.root.message}</p>}

                        {beneficiaries.fields.map((f, i) => {
                            const e = errors.beneficiaries?.[i];
                            return (
                                <div key={f.id} id={`beneficiary-${i}`} className={cn('relative grid gap-3 rounded-xl border p-3 pr-12 transition-shadow sm:grid-cols-12', highlight === i && 'ring-2 ring-primary')}>
                                    <Field label="First name" required htmlFor={`ben_${i}_first`} className="sm:col-span-4" error={e?.first_name?.message}>
                                        <Input id={`ben_${i}_first`} maxLength={80} {...register(`beneficiaries.${i}.first_name`)} aria-invalid={!!e?.first_name} />
                                    </Field>
                                    <Field label="Middle name" htmlFor={`ben_${i}_middle`} className="sm:col-span-4" error={e?.middle_name?.message}>
                                        <Input id={`ben_${i}_middle`} maxLength={80} {...register(`beneficiaries.${i}.middle_name`)} aria-invalid={!!e?.middle_name} />
                                    </Field>
                                    <Field label="Last name" required htmlFor={`ben_${i}_last`} className="sm:col-span-4" error={e?.last_name?.message}>
                                        <Input id={`ben_${i}_last`} maxLength={80} {...register(`beneficiaries.${i}.last_name`)} aria-invalid={!!e?.last_name} />
                                    </Field>
                                    <Field label="Birthdate" htmlFor={`ben_${i}_birthdate`} className="sm:col-span-3" error={e?.birthdate?.message}>
                                        <Controller
                                            control={control}
                                            name={`beneficiaries.${i}.birthdate`}
                                            render={({ field }) => <DatePicker id={`ben_${i}_birthdate`} value={field.value || null} onChange={field.onChange} clearable invalid={!!e?.birthdate} disabled={(d) => d > new Date()} />}
                                        />
                                    </Field>
                                    <Field label="Relationship" required className="sm:col-span-3" error={e?.relationship?.message}>
                                        <Controller
                                            control={control}
                                            name={`beneficiaries.${i}.relationship`}
                                            render={({ field }) => (
                                                <Select value={field.value || undefined} onValueChange={field.onChange}>
                                                    <SelectTrigger className={cn('w-full', e?.relationship && 'border-destructive')}><SelectValue placeholder="Select…" /></SelectTrigger>
                                                    <SelectContent>{meta.data?.relationships.map((r) => <SelectItem key={r} value={r}>{label(r)}</SelectItem>)}</SelectContent>
                                                </Select>
                                            )}
                                        />
                                    </Field>
                                    <Field label="Percentage" required htmlFor={`ben_${i}_pct`} className="sm:col-span-3" error={e?.allocation_percentage?.message}>
                                        <Input id={`ben_${i}_pct`} inputMode="decimal" placeholder="%" {...register(`beneficiaries.${i}.allocation_percentage`)} aria-invalid={!!e?.allocation_percentage} />
                                    </Field>
                                    <Field label="Designation" className="sm:col-span-3">
                                        <Controller
                                            control={control}
                                            name={`beneficiaries.${i}.beneficiary_type`}
                                            render={({ field }) => (
                                                <Select value={field.value} onValueChange={field.onChange}>
                                                    <SelectTrigger className="w-full"><SelectValue /></SelectTrigger>
                                                    <SelectContent>{BENEFICIARY_TYPES.map((t) => <SelectItem key={t.value} value={t.value}>{t.label}</SelectItem>)}</SelectContent>
                                                </Select>
                                            )}
                                        />
                                    </Field>
                                    <div className="absolute top-2 right-2">
                                        <Button type="button" variant="ghost" size="icon" className="text-destructive" onClick={() => beneficiaries.remove(i)} aria-label="Remove beneficiary">
                                            <Trash2 className="size-4" />
                                        </Button>
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                </form>

                {/* Kept in view at the bottom of the page while scrolling a long form. */}
                <div className="sticky bottom-3 z-10 flex justify-end gap-2 rounded-xl border bg-background/95 p-3 shadow-sm backdrop-blur">
                    <Button type="button" variant="outline" onClick={onCancel} disabled={save.isPending}>Cancel</Button>
                    <Button type="submit" form="policy-form" disabled={save.isPending}>
                        {save.isPending && <Loader2 className="size-4 animate-spin" />}
                        {policy ? 'Save changes' : 'Create client'}
                    </Button>
                </div>
        </div>
    );
}
