import { useState, type FormEvent } from 'react';
import { Navigate, useLocation, useNavigate } from 'react-router-dom';
import { Loader2, LockKeyhole, ShieldCheck } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Card, CardContent } from '@/components/ui/card';
import { Field } from '@/components/shared/misc';
import { useAuth } from '@/hooks/use-auth';
import { errorMessage, fieldErrors } from '@/lib/api';

export default function LoginPage() {
    const { user, login } = useAuth();
    const navigate = useNavigate();
    const location = useLocation();
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [remember, setRemember] = useState(true);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [formError, setFormError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    if (user) return <Navigate to="/" replace />;

    const submit = async (e: FormEvent) => {
        e.preventDefault();
        // Immediate client-side feedback; the server validates again.
        const local: Record<string, string> = {};
        if (!/^\S+@\S+\.\S+$/.test(email)) local.email = 'Enter a valid email address.';
        if (!password) local.password = 'Password is required.';
        setErrors(local);
        setFormError(null);
        if (Object.keys(local).length) return;

        setBusy(true);
        try {
            await login(email, password, remember);
            const from = (location.state as { from?: string } | null)?.from;
            navigate(from && from !== '/login' ? from : '/', { replace: true });
        } catch (err) {
            const fields = fieldErrors(err);
            setErrors(fields);
            if (!Object.keys(fields).length) setFormError(errorMessage(err));
        } finally {
            setBusy(false);
        }
    };

    return (
        <div className="grid min-h-screen lg:grid-cols-2">
            <div className="relative hidden overflow-hidden bg-brand p-12 text-white lg:flex lg:flex-col lg:justify-between">
                <div className="flex items-center gap-3">
                    <div className="grid size-10 place-items-center rounded-lg bg-white/15">
                        <ShieldCheck className="size-6" />
                    </div>
                    <span className="text-lg font-bold">RBEL-CRM</span>
                </div>
                <div className="max-w-md">
                    <h1 className="text-4xl font-semibold leading-tight">Financial Advisor Client Management &amp; Analytics</h1>
                    <p className="mt-4 text-white/80">
                        Clients, policies, owners, insureds and beneficiaries in one place, with dashboards that show what needs your attention today.
                    </p>
                </div>
                <p className="text-sm text-white/70">Secure session · Role-based access · Full audit trail</p>
                <div className="pointer-events-none absolute -right-24 -bottom-24 size-96 rounded-full bg-white/5" />
            </div>

            <div className="flex items-center justify-center p-6">
                <Card className="w-full max-w-sm border-0 shadow-none sm:border sm:shadow-sm">
                    <CardContent className="p-2 sm:p-6">
                        <div className="mb-6 lg:hidden">
                            <p className="text-xl font-bold">RBEL-CRM</p>
                            <p className="text-sm text-muted-foreground">Financial Advisor Client Management &amp; Analytics</p>
                        </div>
                        <div className="mb-6">
                            <h2 className="flex items-center gap-2 text-xl font-semibold">
                                <LockKeyhole className="size-5 text-primary" /> Sign in
                            </h2>
                            <p className="mt-1 text-sm text-muted-foreground">Use your advisor account to continue.</p>
                        </div>

                        {formError && (
                            <Alert variant="destructive" className="mb-4">
                                <AlertDescription>{formError}</AlertDescription>
                            </Alert>
                        )}

                        <form onSubmit={submit} className="grid gap-4" noValidate>
                            <Field label="Email" htmlFor="email" error={errors.email}>
                                <Input id="email" type="email" autoComplete="username" value={email} onChange={(e) => setEmail(e.target.value)} aria-invalid={!!errors.email} autoFocus />
                            </Field>
                            <Field label="Password" htmlFor="password" error={errors.password}>
                                <Input id="password" type="password" autoComplete="current-password" value={password} onChange={(e) => setPassword(e.target.value)} aria-invalid={!!errors.password} />
                            </Field>
                            <div className="flex items-center gap-2">
                                <Checkbox id="remember" checked={remember} onCheckedChange={(v) => setRemember(v === true)} />
                                <Label htmlFor="remember" className="text-sm font-normal">
                                    Remember me
                                </Label>
                            </div>
                            <Button type="submit" disabled={busy} className="mt-2">
                                {busy && <Loader2 className="size-4 animate-spin" />} Sign in
                            </Button>
                        </form>

                        <p className="mt-6 rounded-lg bg-muted p-3 text-xs text-muted-foreground">
                            Demo: <span className="font-medium text-foreground">admin@rbel-crm.test</span> / <span className="font-medium text-foreground">password</span>
                        </p>
                    </CardContent>
                </Card>
            </div>
        </div>
    );
}
