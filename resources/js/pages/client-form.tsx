import { useEffect } from 'react';
import { Controller, useForm, type Control, type FieldErrors, type UseFormRegister } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { Loader2 } from 'lucide-react';
import { toast } from 'sonner';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Field } from '@/components/shared/misc';
import { DatePicker } from '@/components/shared/pickers';
import { api } from '@/lib/api';
import { applyServerErrors, MOBILE_RE, nullify, personName } from '@/lib/forms';
import { fullName, todayISO } from '@/lib/format';
import type { Client, Lead } from '@/lib/types';

/** Details every client and lead has. */
const personSchema = z.object({
    first_name: personName().refine((v) => v.length > 0, 'First name is required.'),
    middle_name: personName().optional(),
    last_name: personName().refine((v) => v.length > 0, 'Last name is required.'),
    birthdate: z.string().nullable().optional().refine((v) => !v || v <= todayISO(), 'Birthdate cannot be in the future.'),
    gender: z.string().optional(),
    email: z.string().trim().email('Enter a valid email address.').max(191).optional().or(z.literal('')),
    mobile_number: z.string().trim().regex(MOBILE_RE, 'Digits, spaces, +, - and parentheses only.').optional().or(z.literal('')),
    occupation: z.string().max(120).optional(),
});

type PersonValues = z.infer<typeof personSchema>;

const emptyPerson: PersonValues = { first_name: '', middle_name: '', last_name: '', birthdate: null, gender: '', email: '', mobile_number: '', occupation: '' };

function personValues(p: Client | Lead): PersonValues {
    return {
        first_name: p.first_name,
        middle_name: p.middle_name ?? '',
        last_name: p.last_name,
        birthdate: p.birthdate,
        gender: p.gender ?? '',
        email: p.email ?? '',
        mobile_number: p.mobile_number ?? '',
        occupation: p.occupation ?? '',
    };
}

/** Name, birthdate, gender, occupation, email and mobile: the fields clients and leads share. */
function PersonFields<T extends PersonValues>({ register, control, errors }: { register: UseFormRegister<T>; control: Control<T>; errors: FieldErrors<PersonValues> }) {
    // The generic form shares these field names; narrow once for the helpers below.
    const reg = register as unknown as UseFormRegister<PersonValues>;
    const ctl = control as unknown as Control<PersonValues>;

    return (
        <>
            <Field label="First name" htmlFor="first_name" required error={errors.first_name?.message}>
                <Input id="first_name" {...reg('first_name')} aria-invalid={!!errors.first_name} />
            </Field>
            <Field label="Middle name" htmlFor="middle_name" error={errors.middle_name?.message}>
                <Input id="middle_name" {...reg('middle_name')} />
            </Field>
            <Field label="Last name" htmlFor="last_name" required error={errors.last_name?.message}>
                <Input id="last_name" {...reg('last_name')} aria-invalid={!!errors.last_name} />
            </Field>
            <Field label="Birthdate" htmlFor="birthdate" error={errors.birthdate?.message}>
                <Controller control={ctl} name="birthdate" render={({ field }) => <DatePicker id="birthdate" value={field.value} onChange={field.onChange} clearable invalid={!!errors.birthdate} disabled={(d) => d > new Date()} />} />
            </Field>
            <Field label="Gender" htmlFor="gender">
                <Controller
                    control={ctl}
                    name="gender"
                    render={({ field }) => (
                        <Select value={field.value || undefined} onValueChange={field.onChange}>
                            <SelectTrigger id="gender" className="w-full">
                                <SelectValue placeholder="Select…" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="male">Male</SelectItem>
                                <SelectItem value="female">Female</SelectItem>
                                <SelectItem value="other">Other</SelectItem>
                            </SelectContent>
                        </Select>
                    )}
                />
            </Field>
            <Field label="Occupation" htmlFor="occupation" error={errors.occupation?.message}>
                <Input id="occupation" {...reg('occupation')} />
            </Field>
            <Field label="Email" htmlFor="email" error={errors.email?.message} className="sm:col-span-2">
                <Input id="email" type="email" {...reg('email')} aria-invalid={!!errors.email} />
            </Field>
            <Field label="Mobile number" htmlFor="mobile_number" error={errors.mobile_number?.message}>
                <Input id="mobile_number" placeholder="+63 9xx xxx xxxx" {...reg('mobile_number')} aria-invalid={!!errors.mobile_number} />
            </Field>
        </>
    );
}

function PolicyOwnerSwitch({ checked, onChange, disabled }: { checked: boolean; onChange: (v: boolean) => void; disabled?: boolean }) {
    return (
        <div className="flex items-start justify-between gap-4 rounded-lg border p-3 sm:col-span-3">
            <div className="grid gap-0.5">
                <label htmlFor="is_policy_owner" className="text-sm font-medium">Policy Owner</label>
                {/* Only explain when the switch is locked. */}
                {disabled && <p className="text-xs text-muted-foreground">This client owns policies, so they stay a Policy Owner.</p>}
            </div>
            <Switch id="is_policy_owner" checked={checked} onCheckedChange={onChange} disabled={disabled} />
        </div>
    );
}

const clientSchema = personSchema.extend({
    address: z.string().max(255).optional(),
    is_policy_owner: z.boolean(),
});

type ClientValues = z.infer<typeof clientSchema>;

/** Create / edit a client. */
export function ClientFormDialog({ open, onOpenChange, client, onSaved }: { open: boolean; onOpenChange: (o: boolean) => void; client?: Client | null; onSaved?: (c: Client) => void }) {
    const qc = useQueryClient();
    const form = useForm<ClientValues>({ resolver: zodResolver(clientSchema), defaultValues: { ...emptyPerson, address: '', is_policy_owner: true } });
    const { register, handleSubmit, control, reset, setError, formState: { errors } } = form;

    useEffect(() => {
        if (open) {
            reset(client ? { ...personValues(client), address: client.address ?? '', is_policy_owner: client.is_policy_owner } : { ...emptyPerson, address: '', is_policy_owner: true });
        }
    }, [open, client, reset]);

    const save = useMutation({
        mutationFn: async (values: ClientValues) => {
            const payload = nullify(values);
            const res = client ? await api.put<{ data: Client }>(`/clients/${client.id}`, payload) : await api.post<{ data: Client }>('/clients', payload);
            return res.data.data;
        },
        onSuccess: (saved) => {
            toast.success(client ? 'Details updated.' : 'Client created.');
            qc.invalidateQueries({ queryKey: ['clients'] });
            qc.invalidateQueries({ queryKey: ['client', String(saved.id)] });
            qc.invalidateQueries({ queryKey: ['client-summary'] });
            qc.invalidateQueries({ queryKey: ['dashboard'] });
            onOpenChange(false);
            onSaved?.(saved);
        },
        onError: (e) => applyServerErrors(e, setError),
    });

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent dismissOnOutsideClick={false} className="max-h-[92vh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>{client ? `Edit ${fullName(client)}` : 'New client'}</DialogTitle>
                    <DialogDescription>Personal and contact details.</DialogDescription>
                </DialogHeader>
                <form id="client-form" onSubmit={handleSubmit((v) => save.mutate(v))} className="grid gap-4 sm:grid-cols-3" noValidate>
                    <PersonFields register={register} control={control} errors={errors} />
                    <Field label="Address" htmlFor="address" error={errors.address?.message} className="sm:col-span-3">
                        <Input id="address" {...register('address')} />
                    </Field>
                    <Controller
                        control={control}
                        name="is_policy_owner"
                        render={({ field }) => <PolicyOwnerSwitch checked={field.value} onChange={field.onChange} disabled={!!client?.owned_policies_count && field.value} />}
                    />
                    {errors.is_policy_owner?.message && <p className="text-xs font-medium text-destructive sm:col-span-3">{errors.is_policy_owner.message}</p>}
                </form>
                <DialogFooter>
                    <Button variant="outline" onClick={() => onOpenChange(false)}>
                        Cancel
                    </Button>
                    <Button type="submit" form="client-form" disabled={save.isPending}>
                        {save.isPending && <Loader2 className="size-4 animate-spin" />}
                        {client ? 'Save changes' : 'Create client'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

const leadSchema = personSchema.extend({
    notes: z.string().max(5000).optional(),
});

type LeadValues = z.infer<typeof leadSchema>;

/** Create / edit a lead. */
export function LeadFormDialog({ open, onOpenChange, lead, onSaved }: { open: boolean; onOpenChange: (o: boolean) => void; lead?: Lead | null; onSaved?: (l: Lead) => void }) {
    const qc = useQueryClient();
    const form = useForm<LeadValues>({ resolver: zodResolver(leadSchema), defaultValues: { ...emptyPerson, notes: '' } });
    const { register, handleSubmit, control, reset, setError, formState: { errors } } = form;

    useEffect(() => {
        if (open) {
            reset(lead ? { ...personValues(lead), notes: lead.notes ?? '' } : { ...emptyPerson, notes: '' });
        }
    }, [open, lead, reset]);

    const save = useMutation({
        mutationFn: async (values: LeadValues) => {
            const payload = nullify(values);
            const res = lead ? await api.put<{ data: Lead }>(`/leads/${lead.id}`, payload) : await api.post<{ data: Lead }>('/leads', payload);
            return res.data.data;
        },
        onSuccess: (saved) => {
            toast.success(lead ? 'Lead updated.' : 'Lead created.');
            qc.invalidateQueries({ queryKey: ['leads'] });
            qc.invalidateQueries({ queryKey: ['dashboard'] });
            onOpenChange(false);
            onSaved?.(saved);
        },
        onError: (e) => applyServerErrors(e, setError),
    });

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent dismissOnOutsideClick={false} className="max-h-[92vh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>{lead ? `Edit ${fullName(lead)}` : 'New lead'}</DialogTitle>
                    <DialogDescription>A prospect without a policy. Convert them to a client when they buy.</DialogDescription>
                </DialogHeader>
                <form id="lead-form" onSubmit={handleSubmit((v) => save.mutate(v))} className="grid gap-4 sm:grid-cols-3" noValidate>
                    <PersonFields register={register} control={control} errors={errors} />
                    <Field label="Notes" htmlFor="notes" error={errors.notes?.message} className="sm:col-span-3">
                        <Textarea id="notes" rows={3} {...register('notes')} />
                    </Field>
                </form>
                <DialogFooter>
                    <Button variant="outline" onClick={() => onOpenChange(false)}>
                        Cancel
                    </Button>
                    <Button type="submit" form="lead-form" disabled={save.isPending}>
                        {save.isPending && <Loader2 className="size-4 animate-spin" />}
                        {lead ? 'Save changes' : 'Create lead'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

const convertSchema = z.object({
    address: z.string().max(255).optional(),
    is_policy_owner: z.boolean(),
});

type ConvertValues = z.infer<typeof convertSchema>;

/** Turn a lead into a client: their details are copied over and the lead is removed. */
export function ConvertLeadDialog({ open, onOpenChange, lead, onConverted }: { open: boolean; onOpenChange: (o: boolean) => void; lead: Lead | null; onConverted?: (c: Client) => void }) {
    const qc = useQueryClient();
    const form = useForm<ConvertValues>({ resolver: zodResolver(convertSchema), defaultValues: { address: '', is_policy_owner: true } });
    const { register, handleSubmit, control, reset, setError, formState: { errors } } = form;

    useEffect(() => {
        if (open) reset({ address: '', is_policy_owner: true });
    }, [open, reset]);

    const convert = useMutation({
        mutationFn: async (values: ConvertValues) => (await api.post<{ data: Client }>(`/leads/${lead!.id}/convert`, nullify(values))).data.data,
        onSuccess: (client) => {
            toast.success(`${fullName(client)} is now a client.`);
            qc.invalidateQueries({ queryKey: ['leads'] });
            qc.invalidateQueries({ queryKey: ['clients'] });
            qc.invalidateQueries({ queryKey: ['dashboard'] });
            onOpenChange(false);
            onConverted?.(client);
        },
        onError: (e) => applyServerErrors(e, setError),
    });

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent dismissOnOutsideClick={false} className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Convert {lead ? fullName(lead) : 'lead'} to a client?</DialogTitle>
                    <DialogDescription>Their details are copied to a new client record and the lead, with its notes, is removed.</DialogDescription>
                </DialogHeader>
                <form id="convert-form" onSubmit={handleSubmit((v) => convert.mutate(v))} className="grid gap-4 sm:grid-cols-3" noValidate>
                    <Field label="Address" htmlFor="convert-address" error={errors.address?.message} className="sm:col-span-3">
                        <Input id="convert-address" {...register('address')} />
                    </Field>
                    <Controller control={control} name="is_policy_owner" render={({ field }) => <PolicyOwnerSwitch checked={field.value} onChange={field.onChange} />} />
                </form>
                <DialogFooter>
                    <Button variant="outline" onClick={() => onOpenChange(false)}>
                        Cancel
                    </Button>
                    <Button type="submit" form="convert-form" disabled={convert.isPending}>
                        {convert.isPending && <Loader2 className="size-4 animate-spin" />}
                        Convert to client
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
