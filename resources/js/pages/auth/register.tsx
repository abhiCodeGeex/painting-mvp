import { Head, useForm } from '@inertiajs/react';
import { Building2, LoaderCircle, User } from 'lucide-react';
import { FormEventHandler } from 'react';

import InputError from '@/components/input-error';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';
import { cn } from '@/lib/utils';

type RegisterForm = {
    type: 'individual' | 'company';
    name: string;
    company_name: string;
    gstin: string;
    phone: string;
    email: string;
    password: string;
    password_confirmation: string;
    terms: boolean;
    marketing: boolean;
};

export default function Register() {
    const { data, setData, post, processing, errors, reset } = useForm<RegisterForm>({
        type: 'individual',
        name: '',
        company_name: '',
        gstin: '',
        phone: '',
        email: '',
        password: '',
        password_confirmation: '',
        terms: false,
        marketing: false,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('register'), {
            onFinish: () => reset('password', 'password_confirmation'),
        });
    };

    const isCompany = data.type === 'company';

    return (
        <AuthLayout title="Create your account" description="Request artworks and framing, and track your quotations">
            <Head title="Register" />
            <form className="flex flex-col gap-5" onSubmit={submit}>
                <div className="grid grid-cols-2 gap-2">
                    {(
                        [
                            { value: 'individual', label: 'For my home', icon: User },
                            { value: 'company', label: 'For a business', icon: Building2 },
                        ] as const
                    ).map((o) => (
                        <button
                            key={o.value}
                            type="button"
                            onClick={() => setData('type', o.value)}
                            className={cn(
                                'flex items-center justify-center gap-2 rounded-lg border p-3 text-sm transition',
                                data.type === o.value ? 'border-primary ring-primary ring-2' : 'hover:bg-muted',
                            )}
                        >
                            <o.icon className="size-4" /> {o.label}
                        </button>
                    ))}
                </div>

                {isCompany && (
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field id="company_name" label="Company name" error={errors.company_name}>
                            <Input id="company_name" value={data.company_name} onChange={(e) => setData('company_name', e.target.value)} disabled={processing} required />
                        </Field>
                        <Field id="gstin" label="GSTIN (optional)" error={errors.gstin}>
                            <Input
                                id="gstin"
                                value={data.gstin}
                                onChange={(e) => setData('gstin', e.target.value.toUpperCase())}
                                maxLength={15}
                                placeholder="27AAPFU0939F1ZV"
                                disabled={processing}
                            />
                        </Field>
                    </div>
                )}

                <Field id="name" label={isCompany ? 'Your name' : 'Full name'} error={errors.name}>
                    <Input id="name" required autoFocus autoComplete="name" value={data.name} onChange={(e) => setData('name', e.target.value)} disabled={processing} />
                </Field>

                <div className="grid gap-4 sm:grid-cols-2">
                    <Field id="email" label="Email address" error={errors.email}>
                        <Input
                            id="email"
                            type="email"
                            required
                            autoComplete="email"
                            value={data.email}
                            onChange={(e) => setData('email', e.target.value)}
                            disabled={processing}
                            placeholder="email@example.com"
                        />
                    </Field>
                    <Field id="phone" label="Mobile number" error={errors.phone}>
                        <Input
                            id="phone"
                            type="tel"
                            required
                            autoComplete="tel"
                            value={data.phone}
                            onChange={(e) => setData('phone', e.target.value)}
                            disabled={processing}
                            placeholder="+91 98765 43210"
                        />
                    </Field>
                </div>

                <div className="grid gap-4 sm:grid-cols-2">
                    <Field id="password" label="Password" error={errors.password}>
                        <Input
                            id="password"
                            type="password"
                            required
                            autoComplete="new-password"
                            value={data.password}
                            onChange={(e) => setData('password', e.target.value)}
                            disabled={processing}
                        />
                    </Field>
                    <Field id="password_confirmation" label="Confirm password" error={errors.password_confirmation}>
                        <Input
                            id="password_confirmation"
                            type="password"
                            required
                            autoComplete="new-password"
                            value={data.password_confirmation}
                            onChange={(e) => setData('password_confirmation', e.target.value)}
                            disabled={processing}
                        />
                    </Field>
                </div>

                <div className="space-y-2 text-sm">
                    <label className="flex items-start gap-2">
                        <Checkbox checked={data.terms} onCheckedChange={(v) => setData('terms', v === true)} />
                        <span>I accept the terms of service and privacy policy.</span>
                    </label>
                    <InputError message={errors.terms} />
                    <label className="text-muted-foreground flex items-start gap-2">
                        <Checkbox checked={data.marketing} onCheckedChange={(v) => setData('marketing', v === true)} />
                        <span>Send me occasional news about new collections (optional).</span>
                    </label>
                </div>

                <Button type="submit" className="w-full" disabled={processing}>
                    {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                    Create account
                </Button>

                <div className="text-muted-foreground text-center text-sm">
                    Already have an account? <TextLink href={route('login')}>Log in</TextLink>
                </div>
            </form>
        </AuthLayout>
    );
}

function Field({ id, label, error, children }: { id: string; label: string; error?: string; children: React.ReactNode }) {
    return (
        <div className="grid gap-2">
            <Label htmlFor={id}>{label}</Label>
            {children}
            <InputError message={error} />
        </div>
    );
}
