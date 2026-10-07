import { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Loader2, Mail, Save, Send } from 'lucide-react';
import { toast } from 'sonner';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Field } from '@/components/shared/misc';
import { api, errorMessage, fieldErrors } from '@/lib/api';
import { dateTime } from '@/lib/format';

interface MailSettings {
    source: 'app' | 'env';
    enabled: boolean;
    host: string | null;
    port: number;
    encryption: 'tls' | 'ssl';
    username: string | null;
    password_set: boolean;
    from_address: string | null;
    from_name: string | null;
    updated_at: string | null;
}

const PRESETS = [
    { label: 'Gmail', host: 'smtp.gmail.com', port: 587, encryption: 'tls' as const },
    { label: 'Outlook / Microsoft 365', host: 'smtp.office365.com', port: 587, encryption: 'tls' as const },
];

/** Outgoing mail server for the whole app (admins). The password is write-only. */
export function MailSettingsCard({ userEmail }: { userEmail: string }) {
    const qc = useQueryClient();
    const settings = useQuery({
        queryKey: ['mail-settings'],
        queryFn: async () => (await api.get<{ data: MailSettings }>('/mail-settings')).data.data,
    });
    const [v, setV] = useState({ enabled: false, host: '', port: '587', encryption: 'tls' as 'tls' | 'ssl', username: '', password: '', from_address: '', from_name: '' });
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [testTo, setTestTo] = useState(userEmail);

    useEffect(() => {
        const s = settings.data;
        if (!s) return;
        setV({ enabled: s.enabled, host: s.host ?? '', port: String(s.port), encryption: s.encryption, username: s.username ?? '', password: '', from_address: s.from_address ?? '', from_name: s.from_name ?? '' });
    }, [settings.data]);

    const save = useMutation({
        mutationFn: async () => (await api.put<{ data: MailSettings }>('/mail-settings', { ...v, port: Number(v.port) || 0 })).data.data,
        onSuccess: (data) => {
            qc.setQueryData(['mail-settings'], data);
            // Send dialogs and Automations read whether delivery is on.
            ['meta', 'automations'].forEach((k) => qc.invalidateQueries({ queryKey: [k] }));
            setErrors({});
            toast.success(data.enabled ? 'Email settings saved. Sending is on.' : 'Email settings saved. Sending is off.');
        },
        onError: (e) => {
            const f = fieldErrors(e);
            setErrors(f);
            if (!Object.keys(f).length) toast.error(errorMessage(e));
        },
    });

    const test = useMutation({
        mutationFn: async () => (await api.post<{ message: string }>('/mail-settings/test', { to: testTo || null })).data.message,
        onSuccess: (msg) => toast.success(msg),
        onError: (e) => toast.error(errorMessage(e, 'The test email could not be sent.'), { duration: 12_000 }),
    });

    const s = settings.data;
    // While still on .env values, Save is always available so they can be moved into the app as-is.
    const dirty =
        !!s &&
        (s.source === 'env' ||
            v.enabled !== s.enabled || v.host !== (s.host ?? '') || v.port !== String(s.port) || v.encryption !== s.encryption || v.username !== (s.username ?? '') ||
            v.password !== '' || v.from_address !== (s.from_address ?? '') || v.from_name !== (s.from_name ?? ''));

    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center gap-2"><Mail className="size-4" /> Email sending <span className="text-xs font-normal text-muted-foreground">(admin)</span></CardTitle>
                <CardDescription>
                    The mailbox the whole app sends from (client emails and Automations). Recipients see the user's name as sender, and replies go to that user's own email.
                    {s && (s.source === 'env' ? ' Currently using the values from the server file (.env); saving stores them here.' : s.updated_at ? ` Last changed ${dateTime(s.updated_at)}.` : '')}
                </CardDescription>
            </CardHeader>
            <CardContent>
                {!s ? (
                    <Skeleton className="h-64" />
                ) : (
                    <form className="grid gap-4" onSubmit={(e) => { e.preventDefault(); save.mutate(); }} noValidate>
                        <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg border bg-muted/30 px-4 py-3">
                            <div className="flex items-center gap-2">
                                <Switch id="mail-enabled" checked={v.enabled} onCheckedChange={(enabled) => setV({ ...v, enabled })} />
                                <Label htmlFor="mail-enabled" className="text-sm">{v.enabled ? 'Sending is on' : 'Sending is off (emails are only logged)'}</Label>
                            </div>
                            <div className="flex flex-wrap items-center gap-1.5 text-xs text-muted-foreground">
                                Quick fill:
                                {PRESETS.map((p) => (
                                    <Button key={p.label} type="button" variant="outline" size="sm" className="h-7" onClick={() => setV({ ...v, host: p.host, port: String(p.port), encryption: p.encryption })}>
                                        {p.label}
                                    </Button>
                                ))}
                            </div>
                        </div>

                        <div className="grid gap-4 sm:grid-cols-[minmax(0,1fr)_110px_170px]">
                            <Field label="Mail server (SMTP host)" required={v.enabled} error={errors.host}>
                                <Input value={v.host} onChange={(e) => setV({ ...v, host: e.target.value })} placeholder="smtp.gmail.com" aria-invalid={!!errors.host} autoComplete="off" />
                            </Field>
                            <Field label="Port" required error={errors.port}>
                                <Input inputMode="numeric" value={v.port} onChange={(e) => setV({ ...v, port: e.target.value.replace(/\D/g, '') })} aria-invalid={!!errors.port} />
                            </Field>
                            <Field label="Security" error={errors.encryption}>
                                <Select value={v.encryption} onValueChange={(encryption) => setV({ ...v, encryption: encryption as 'tls' | 'ssl', port: encryption === 'ssl' && v.port === '587' ? '465' : encryption === 'tls' && v.port === '465' ? '587' : v.port })}>
                                    <SelectTrigger className="w-full"><SelectValue /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="tls">STARTTLS (587)</SelectItem>
                                        <SelectItem value="ssl">SSL/TLS (465)</SelectItem>
                                    </SelectContent>
                                </Select>
                            </Field>
                        </div>

                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field label="Username (mailbox login)" required={v.enabled} error={errors.username} hint="For Gmail, the full Gmail address.">
                                <Input value={v.username} onChange={(e) => setV({ ...v, username: e.target.value })} aria-invalid={!!errors.username} autoComplete="off" />
                            </Field>
                            <Field label="Password" error={errors.password} hint={s.password_set ? 'A password is saved. Leave blank to keep it.' : 'For Gmail, a 16-character App Password (not your normal password).'}>
                                <Input type="password" value={v.password} onChange={(e) => setV({ ...v, password: e.target.value })} placeholder={s.password_set ? '•••••••• (saved)' : ''} autoComplete="new-password" aria-invalid={!!errors.password} />
                            </Field>
                            <Field label="Sender address" error={errors.from_address} hint="Leave blank to use the username. Gmail only allows its own address.">
                                <Input type="email" value={v.from_address} onChange={(e) => setV({ ...v, from_address: e.target.value })} placeholder={v.username || 'same as username'} aria-invalid={!!errors.from_address} />
                            </Field>
                            <Field label="Sender name" error={errors.from_name} hint="Used when no user is attached (e.g. test emails).">
                                <Input value={v.from_name} onChange={(e) => setV({ ...v, from_name: e.target.value })} placeholder="RBEL-CRM" />
                            </Field>
                        </div>

                        <div className="flex flex-wrap items-end justify-between gap-3 border-t pt-4">
                            <Button type="submit" disabled={!dirty || save.isPending}>
                                {save.isPending ? <Loader2 className="size-4 animate-spin" /> : <Save className="size-4" />} Save email settings
                            </Button>
                            <div className="flex flex-wrap items-end gap-2">
                                <Field label="Send a test email to">
                                    <Input type="email" className="w-64" value={testTo} onChange={(e) => setTestTo(e.target.value)} />
                                </Field>
                                <Button type="button" variant="outline" onClick={() => test.mutate()} disabled={test.isPending || dirty || !s.enabled} title={dirty ? 'Save first' : !s.enabled ? 'Turn sending on and save first' : undefined}>
                                    {test.isPending ? <Loader2 className="size-4 animate-spin" /> : <Send className="size-4" />} Send test
                                </Button>
                            </div>
                        </div>
                    </form>
                )}
            </CardContent>
        </Card>
    );
}
