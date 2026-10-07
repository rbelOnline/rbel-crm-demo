import { useState } from 'react';
import { NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom';
import {
    BarChart3,
    Bell,
    CalendarDays,
    CalendarRange,
    Files,
    Goal,
    LayoutDashboard,
    LogOut,
    Mail,
    Menu,
    Monitor,
    Moon,
    Package,
    TrendingUp,
    ScrollText,
    ShieldCheck,
    Sun,
    User as UserIcon,
    UserPlus,
    Users,
    Zap,
} from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Sheet, SheetContent, SheetTitle } from '@/components/ui/sheet';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { useAuth } from '@/hooks/use-auth';
import { useTheme } from '@/hooks/use-theme';
import { initials, label } from '@/lib/format';
import { cn } from '@/lib/utils';

const NAV = [
    { to: '/', label: 'Dashboard', icon: LayoutDashboard, end: true },
    { to: '/leads', label: 'Leads', icon: UserPlus },
    { to: '/clients', label: 'Clients', icon: Users },
    { to: '/products', label: 'Products', icon: Package },
    { to: '/fund-types', label: 'Fund Types', icon: TrendingUp },
    { to: '/documents', label: 'Documents', icon: Files },
    { to: '/calendar', label: 'Calendar', icon: CalendarRange },
    { to: '/appointments', label: 'Appointments', icon: CalendarDays },
    { to: '/reminders', label: 'Reminders', icon: Bell },
    { to: '/goals', label: 'Goals', icon: Goal },
    { to: '/email-templates', label: 'Email Templates', icon: Mail },
    { to: '/analytics', label: 'Analytics', icon: BarChart3 },
    { to: '/automations', label: 'Automations', icon: Zap, admin: true },
    { to: '/audit-logs', label: 'Audit Logs', icon: ScrollText, admin: true },
];

function Brand() {
    return (
        <div className="flex items-center gap-3 px-2">
            <div className="grid size-9 place-items-center rounded-lg bg-brand text-white shadow-sm">
                <ShieldCheck className="size-5" aria-hidden />
            </div>
            <div className="leading-tight">
                <p className="text-[15px] font-bold tracking-tight">RBEL-CRM</p>
                <p className="text-[11px] text-muted-foreground">Financial Advisor Client Management &amp; Analytics</p>
            </div>
        </div>
    );
}

function Nav({ onNavigate }: { onNavigate?: () => void }) {
    const { user } = useAuth();
    return (
        <nav className="grid gap-0.5" aria-label="Main">
            {NAV.filter((n) => !n.admin || user?.permissions.admin).map(({ to, label: text, icon: Icon, end }) => (
                <NavLink
                    key={to}
                    to={to}
                    end={end}
                    onClick={onNavigate}
                    className={({ isActive }) =>
                        cn(
                            'flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium text-sidebar-foreground/80 transition-colors hover:bg-sidebar-accent hover:text-sidebar-accent-foreground',
                            isActive && 'bg-sidebar-accent text-sidebar-accent-foreground',
                        )
                    }
                >
                    <Icon className="size-4.5" aria-hidden />
                    {text}
                </NavLink>
            ))}
        </nav>
    );
}

function ThemeToggle() {
    const { theme, setTheme } = useTheme();
    const next = theme === 'light' ? 'dark' : theme === 'dark' ? 'system' : 'light';
    const Icon = theme === 'light' ? Sun : theme === 'dark' ? Moon : Monitor;
    return (
        <Button variant="ghost" size="icon" onClick={() => setTheme(next)} aria-label={`Theme: ${theme}. Switch to ${next}`} title={`Theme: ${theme}`}>
            <Icon className="size-4.5" />
        </Button>
    );
}

export function AppLayout() {
    const { user, logout } = useAuth();
    const [mobileOpen, setMobileOpen] = useState(false);
    const navigate = useNavigate();
    const location = useLocation();
    const current = NAV.find((n) => (n.end ? location.pathname === n.to : location.pathname.startsWith(n.to)));

    return (
        <div className="min-h-screen lg:grid lg:grid-cols-[260px_minmax(0,1fr)]">
            <aside className="sticky top-0 hidden h-screen flex-col gap-6 border-r bg-sidebar px-3 py-5 lg:flex">
                <Brand />
                <Nav />
                <div className="mt-auto rounded-lg border bg-card p-3 text-xs text-muted-foreground">
                    Signed in as <span className="font-medium text-foreground">{label(user?.role)}</span>
                </div>
            </aside>

            <Sheet open={mobileOpen} onOpenChange={setMobileOpen}>
                <SheetContent side="left" className="w-[280px] gap-6 bg-sidebar p-4">
                    <SheetTitle className="sr-only">Navigation</SheetTitle>
                    <Brand />
                    <Nav onNavigate={() => setMobileOpen(false)} />
                </SheetContent>
            </Sheet>

            <div className="flex min-w-0 flex-col">
                <header className="sticky top-0 z-30 flex h-16 items-center gap-3 border-b bg-background/85 px-4 backdrop-blur sm:px-6">
                    <Button variant="ghost" size="icon" className="lg:hidden" onClick={() => setMobileOpen(true)} aria-label="Open navigation">
                        <Menu className="size-5" />
                    </Button>
                    <p className="font-semibold lg:hidden">RBEL-CRM</p>
                    <p className="hidden text-sm text-muted-foreground lg:block">{current?.label}</p>
                    <div className="ml-auto flex items-center gap-1">
                        <ThemeToggle />
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button variant="ghost" className="h-10 gap-2 px-2" aria-label="User menu">
                                    <Avatar className="size-8">
                                        <AvatarFallback className="bg-primary/10 text-xs font-semibold text-primary">{initials(user?.name ?? '')}</AvatarFallback>
                                    </Avatar>
                                    <span className="hidden text-left text-sm leading-tight sm:block">
                                        <span className="block font-medium">{user?.name}</span>
                                        <span className="block text-xs text-muted-foreground">{user?.job_title ?? label(user?.role)}</span>
                                    </span>
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end" className="w-56">
                                <DropdownMenuLabel className="font-normal">
                                    <p className="font-medium">{user?.name}</p>
                                    <p className="truncate text-xs text-muted-foreground">{user?.email}</p>
                                </DropdownMenuLabel>
                                <DropdownMenuSeparator />
                                <DropdownMenuItem onClick={() => navigate('/profile')}>
                                    <UserIcon className="size-4" /> Profile
                                </DropdownMenuItem>
                                <DropdownMenuItem onClick={() => navigate('/email-templates?tab=logs')}>
                                    <Mail className="size-4" /> Email logs
                                </DropdownMenuItem>
                                <DropdownMenuSeparator />
                                <DropdownMenuItem onClick={() => logout().then(() => navigate('/login'))} variant="destructive">
                                    <LogOut className="size-4" /> Log out
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </div>
                </header>
                <main className="mx-auto w-full max-w-[1400px] flex-1 px-4 py-6 sm:px-6 lg:px-8">
                    <Outlet />
                </main>
            </div>
        </div>
    );
}
