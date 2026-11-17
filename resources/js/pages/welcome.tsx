import AppLogoIcon from '@/components/app-logo-icon';
import { Button } from '@/components/ui/button';
import { type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { Frame, Palette, Ruler } from 'lucide-react';

const steps = [
    { icon: Palette, title: 'Choose your artwork', text: 'Pick from our catalog, brief us on a new creation, or bring your own piece.' },
    { icon: Ruler, title: 'Size & frame it', text: 'Set dimensions, medium, frame, mat and glazing, and preview it on your wall.' },
    { icon: Frame, title: 'Get a quotation', text: 'Receive a detailed quote you can approve, adjust or decline online.' },
];

export default function Welcome() {
    const { auth, name } = usePage<SharedData>().props;

    return (
        <>
            <Head title="Welcome" />
            <div className="bg-background text-foreground flex min-h-screen flex-col">
                <header className="mx-auto flex w-full max-w-5xl items-center justify-between p-4 sm:p-6">
                    <div className="flex items-center gap-2 font-semibold">
                        <AppLogoIcon className="size-6 fill-current" />
                        {name}
                    </div>
                    <nav className="flex items-center gap-2">
                        {auth.user ? (
                            <Button asChild>
                                <Link href={route('dashboard')}>Dashboard</Link>
                            </Button>
                        ) : (
                            <>
                                <Button variant="ghost" asChild>
                                    <Link href={route('login')}>Log in</Link>
                                </Button>
                                <Button asChild>
                                    <Link href={route('register')}>Register</Link>
                                </Button>
                            </>
                        )}
                    </nav>
                </header>

                <main className="mx-auto flex w-full max-w-5xl flex-1 flex-col justify-center gap-10 p-4 sm:p-6">
                    <div className="max-w-2xl">
                        <h1 className="text-3xl font-semibold tracking-tight sm:text-5xl">Artwork and framing, quoted to your exact wall.</h1>
                        <p className="text-muted-foreground mt-4 text-lg">
                            Send one enquiry for every piece in your project and get a clear, itemised quotation.
                        </p>
                        {!auth.user && (
                            <Button size="lg" className="mt-6" asChild>
                                <Link href={route('register')}>Start an enquiry</Link>
                            </Button>
                        )}
                    </div>

                    <div className="grid gap-6 sm:grid-cols-3">
                        {steps.map((step) => (
                            <div key={step.title} className="rounded-xl border p-5">
                                <step.icon className="text-muted-foreground mb-3 size-6" />
                                <h2 className="font-medium">{step.title}</h2>
                                <p className="text-muted-foreground mt-1 text-sm">{step.text}</p>
                            </div>
                        ))}
                    </div>
                </main>
            </div>
        </>
    );
}
