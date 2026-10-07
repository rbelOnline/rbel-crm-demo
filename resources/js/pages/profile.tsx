import { useEffect, useState } from 'react';
import { useMutation } from '@tanstack/react-query';
import { KeyRound, Loader2, UserRound } from 'lucide-react';
import { toast } from 'sonner';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Field, PageHeader } from '@/components/shared/misc';
import { MailSettingsCard } from '@/pages/mail-settings-card';
import { api, errorMessage, fieldErrors } from '@/lib/api';
import { useAuth } from '@/hooks/use-auth';
import { dateTime, initials, label } from '@/lib/format';
import type { User } from '@/lib/types';

export default function ProfilePage() {
    const { user, setUser } = useAuth();
    const [profile, setProfile] = useState({ name: '', email: '', phone: '', job_title: '', license_number: '', bio: '' });
    const [pErrors, setPErrors] = useState<Record<string, string>>({});
    const [pw, setPw] = useState({ current_password: '', password: '', password_confirmation: '' });
    const [pwErrors, setPwErrors] = useState<Record<string, string>>({});

    useEffect(() => {
        if (user) setProfile({ name: user.name, email: user.email, phone: user.phone ?? '', job_title: user.job_title ?? '', license_number: user.license_number ?? '', bio: user.bio ?? '' });
    }, [user]);

    const saveProfile = useMutation({
        mutationFn: async () => {
            const local: Record<string, string> = {};
            if (!profile.name.trim()) local.name = 'Name is required.';
            if (!/^\S+@\S+\.\S+$/.test(profile.email)) local.email = 'Enter a valid email address.';
            if (Object.keys(local).length) { setPErrors(local); throw new Error('local'); }
            const body = Object.fromEntries(Object.entries(profile).map(([k, v]) => [k, v === '' ? null : v]));
            return (await api.put<{ data: User }>('/profile', body)).data.data;
        },
        onSuccess: (u) => { setUser(u); setPErrors({}); toast.success('Profile updated.'); },
        onError: (e) => {
            if ((e as Error).message === 'local') return;
            const f = fieldErrors(e);
            setPErrors(f);
            if (!Object.keys(f).length) toast.error(errorMessage(e));
        },
    });

    const savePassword = useMutation({
        mutationFn: async () => {
            const local: Record<string, string> = {};
            if (!pw.current_password) local.current_password = 'Enter your current password.';
            if (pw.password.length < 8 || !/[A-Za-z]/.test(pw.password) || !/\d/.test(pw.password)) local.password = 'At least 8 characters with letters and numbers.';
            if (pw.password !== pw.password_confirmation) local.password_confirmation = 'Passwords do not match.';
            if (Object.keys(local).length) { setPwErrors(local); throw new Error('local'); }
            return api.put('/profile/password', pw);
        },
        onSuccess: () => { setPw({ current_password: '', password: '', password_confirmation: '' }); setPwErrors({}); toast.success('Password changed. Other sessions were signed out.'); },
        onError: (e) => {
            if ((e as Error).message === 'local') return;
            const f = fieldErrors(e);
            setPwErrors(f);
            if (!Object.keys(f).length) toast.error(errorMessage(e));
        },
    });

    if (!user) return null;

    return (
        <div className="grid gap-6">
            <PageHeader title="Profile" description="Your account details and security." />
            <div className="grid gap-6 lg:grid-cols-[300px_minmax(0,1fr)]">
                <Card className="h-fit">
                    <CardContent className="flex flex-col items-center p-6 text-center">
                        <Avatar className="size-20"><AvatarFallback className="bg-primary/10 text-2xl font-semibold text-primary">{initials(user.name)}</AvatarFallback></Avatar>
                        <p className="mt-3 text-lg font-semibold">{user.name}</p>
                        <p className="text-sm text-muted-foreground">{user.job_title ?? label(user.role)}</p>
                        <dl className="mt-5 grid w-full gap-2 text-left text-sm">
                            <div className="flex justify-between"><dt className="text-muted-foreground">Role</dt><dd className="font-medium">{label(user.role)}</dd></div>
                            <div className="flex justify-between"><dt className="text-muted-foreground">License</dt><dd>{user.license_number ?? '—'}</dd></div>
                            <div className="flex justify-between gap-2"><dt className="text-muted-foreground">Last login</dt><dd className="text-right">{dateTime(user.last_login_at)}</dd></div>
                        </dl>
                    </CardContent>
                </Card>

                <div className="grid gap-6">
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2"><UserRound className="size-4" /> Profile information</CardTitle>
                            <CardDescription>Your name appears in client emails as {'{{advisor_name}}'}.</CardDescription>
                        </CardHeader>
                        <CardContent>
                            <form className="grid gap-4 sm:grid-cols-2" onSubmit={(e) => { e.preventDefault(); saveProfile.mutate(); }} noValidate>
                                <Field label="Name" required error={pErrors.name}><Input value={profile.name} onChange={(e) => setProfile({ ...profile, name: e.target.value })} aria-invalid={!!pErrors.name} /></Field>
                                <Field label="Email" required error={pErrors.email}><Input type="email" value={profile.email} onChange={(e) => setProfile({ ...profile, email: e.target.value })} aria-invalid={!!pErrors.email} /></Field>
                                <Field label="Phone" error={pErrors.phone}><Input value={profile.phone} onChange={(e) => setProfile({ ...profile, phone: e.target.value })} /></Field>
                                <Field label="Job title" error={pErrors.job_title}><Input value={profile.job_title} onChange={(e) => setProfile({ ...profile, job_title: e.target.value })} /></Field>
                                <Field label="License number" error={pErrors.license_number}><Input value={profile.license_number} onChange={(e) => setProfile({ ...profile, license_number: e.target.value })} /></Field>
                                <Field label="Bio" error={pErrors.bio} className="sm:col-span-2"><Textarea rows={3} value={profile.bio} onChange={(e) => setProfile({ ...profile, bio: e.target.value })} /></Field>
                                <div className="sm:col-span-2"><Button type="submit" disabled={saveProfile.isPending}>{saveProfile.isPending && <Loader2 className="size-4 animate-spin" />} Save profile</Button></div>
                            </form>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2"><KeyRound className="size-4" /> Change password</CardTitle>
                            <CardDescription>At least 8 characters, with letters and numbers.</CardDescription>
                        </CardHeader>
                        <CardContent>
                            <form className="grid gap-4 sm:grid-cols-3" onSubmit={(e) => { e.preventDefault(); savePassword.mutate(); }} noValidate>
                                <Field label="Current password" error={pwErrors.current_password}><Input type="password" autoComplete="current-password" value={pw.current_password} onChange={(e) => setPw({ ...pw, current_password: e.target.value })} aria-invalid={!!pwErrors.current_password} /></Field>
                                <Field label="New password" error={pwErrors.password}><Input type="password" autoComplete="new-password" value={pw.password} onChange={(e) => setPw({ ...pw, password: e.target.value })} aria-invalid={!!pwErrors.password} /></Field>
                                <Field label="Confirm new password" error={pwErrors.password_confirmation}><Input type="password" autoComplete="new-password" value={pw.password_confirmation} onChange={(e) => setPw({ ...pw, password_confirmation: e.target.value })} aria-invalid={!!pwErrors.password_confirmation} /></Field>
                                <div className="sm:col-span-3"><Button type="submit" disabled={savePassword.isPending}>{savePassword.isPending && <Loader2 className="size-4 animate-spin" />} Update password</Button></div>
                            </form>
                        </CardContent>
                    </Card>

                    {user.permissions.admin && <MailSettingsCard userEmail={user.email} />}
                </div>
            </div>
        </div>
    );
}
