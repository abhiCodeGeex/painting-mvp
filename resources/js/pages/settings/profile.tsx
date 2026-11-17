import { type BreadcrumbItem, type SharedData } from '@/types';
import { Transition } from '@headlessui/react';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler } from 'react';

import DeleteUser from '@/components/delete-user';
import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Profile settings',
        href: '/settings/profile',
    },
];

interface CustomerProfile {
    type: 'individual' | 'company';
    company_name: string | null;
    phone: string | null;
    gstin: string | null;
    pan: string | null;
}

export default function Profile({ mustVerifyEmail, status, customer }: { mustVerifyEmail: boolean; status?: string; customer: CustomerProfile | null }) {
    const { auth } = usePage<SharedData>().props;

    const { data, setData, patch, errors, processing, recentlySuccessful } = useForm({
        name: auth.user.name,
        email: auth.user.email,
        ...(customer
            ? {
                  type: customer.type,
                  company_name: customer.company_name ?? '',
                  phone: customer.phone ?? '',
                  gstin: customer.gstin ?? '',
                  pan: customer.pan ?? '',
              }
            : {}),
    });
    const fieldErrors = errors as Record<string, string | undefined>;

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        patch(route('profile.update'));
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Profile settings" />

            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall title="Profile information" description="Update your name and email address" />

                    <form onSubmit={submit} className="space-y-6">
                        <div className="grid gap-2">
                            <Label htmlFor="name">Name</Label>

                            <Input
                                id="name"
                                className="mt-1 block w-full"
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                required
                                autoComplete="name"
                                placeholder="Full name"
                            />

                            <InputError className="mt-2" message={errors.name} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="email">Email address</Label>

                            <Input
                                id="email"
                                type="email"
                                className="mt-1 block w-full"
                                value={data.email}
                                onChange={(e) => setData('email', e.target.value)}
                                required
                                autoComplete="username"
                                placeholder="Email address"
                            />

                            <InputError className="mt-2" message={errors.email} />
                        </div>

                        {customer && (
                            <>
                                <div className="grid gap-2">
                                    <Label htmlFor="phone">Mobile number</Label>
                                    <Input id="phone" value={data.phone ?? ''} onChange={(e) => setData('phone', e.target.value)} placeholder="+91 98765 43210" />
                                    <InputError message={fieldErrors.phone} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="type">Account type</Label>
                                    <NativeSelect id="type" value={data.type} onChange={(e) => setData('type', e.target.value as 'individual' | 'company')}>
                                        <option value="individual">Individual</option>
                                        <option value="company">Company</option>
                                    </NativeSelect>
                                </div>

                                {data.type === 'company' && (
                                    <>
                                        <div className="grid gap-2">
                                            <Label htmlFor="company_name">Company name</Label>
                                            <Input id="company_name" value={data.company_name ?? ''} onChange={(e) => setData('company_name', e.target.value)} />
                                            <InputError message={fieldErrors.company_name} />
                                        </div>
                                        <div className="grid gap-4 sm:grid-cols-2">
                                            <div className="grid gap-2">
                                                <Label htmlFor="gstin">GSTIN</Label>
                                                <Input
                                                    id="gstin"
                                                    maxLength={15}
                                                    value={data.gstin ?? ''}
                                                    onChange={(e) => setData('gstin', e.target.value.toUpperCase())}
                                                    placeholder="27AAPFU0939F1ZV"
                                                />
                                                <InputError message={fieldErrors.gstin} />
                                            </div>
                                            <div className="grid gap-2">
                                                <Label htmlFor="pan">PAN</Label>
                                                <Input
                                                    id="pan"
                                                    maxLength={10}
                                                    value={data.pan ?? ''}
                                                    onChange={(e) => setData('pan', e.target.value.toUpperCase())}
                                                    placeholder="AAPFU0939F"
                                                />
                                                <InputError message={fieldErrors.pan} />
                                            </div>
                                        </div>
                                    </>
                                )}
                            </>
                        )}

                        {mustVerifyEmail && auth.user.email_verified_at === null && (
                            <div>
                                <p className="mt-2 text-sm text-neutral-800">
                                    Your email address is unverified.
                                    <Link
                                        href={route('verification.send')}
                                        method="post"
                                        as="button"
                                        className="rounded-md text-sm text-neutral-600 underline hover:text-neutral-900 focus:ring-2 focus:ring-offset-2 focus:outline-hidden"
                                    >
                                        Click here to re-send the verification email.
                                    </Link>
                                </p>

                                {status === 'verification-link-sent' && (
                                    <div className="mt-2 text-sm font-medium text-green-600">
                                        A new verification link has been sent to your email address.
                                    </div>
                                )}
                            </div>
                        )}

                        <div className="flex items-center gap-4">
                            <Button disabled={processing}>Save</Button>

                            <Transition
                                show={recentlySuccessful}
                                enter="transition ease-in-out"
                                enterFrom="opacity-0"
                                leave="transition ease-in-out"
                                leaveTo="opacity-0"
                            >
                                <p className="text-sm text-neutral-600">Saved</p>
                            </Transition>
                        </div>
                    </form>
                </div>

                <DeleteUser />
            </SettingsLayout>
        </AppLayout>
    );
}
