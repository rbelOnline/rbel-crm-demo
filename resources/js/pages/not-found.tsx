import { Link } from 'react-router-dom';
import { Compass } from 'lucide-react';
import { Button } from '@/components/ui/button';

export default function NotFoundPage() {
    return (
        <div className="grid min-h-[60vh] place-items-center text-center">
            <div>
                <Compass className="mx-auto size-10 text-muted-foreground" />
                <h1 className="mt-4 text-2xl font-semibold">Page not found</h1>
                <p className="mt-1 text-muted-foreground">The page you're looking for doesn't exist.</p>
                <Button asChild className="mt-6"><Link to="/">Back to dashboard</Link></Button>
            </div>
        </div>
    );
}
