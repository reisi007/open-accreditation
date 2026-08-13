import { zodResolver } from '@hookform/resolvers/zod';
import { t } from '@lingui/core/macro';
import { useLingui } from '@lingui/react';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { Navigate, useLocation, useNavigate } from 'react-router-dom';
import { z } from 'zod';
import { ApiError } from '../api/client';
import { useAuth } from '../logic/useAuth';

const createLoginSchema = () =>
    z.object({
        email: z.email(t`Bitte eine gültige E-Mail-Adresse eingeben.`),
        password: z.string().min(1, t`Passwort ist erforderlich.`),
    });

type LoginFormValues = z.infer<ReturnType<typeof createLoginSchema>>;

export function LoginPage() {
    const { i18n } = useLingui();
    const { isAuthenticated, login } = useAuth();
    const navigate = useNavigate();
    const location = useLocation();
    const [serverError, setServerError] = useState<string | null>(null);

    const loginSchema = createLoginSchema();

    const {
        register,
        handleSubmit,
        formState: { errors, isSubmitting },
    } = useForm<LoginFormValues>({
        resolver: zodResolver(loginSchema),
    });

    const from = (location.state as { from?: string } | null)?.from ?? '/admin';

    if (isAuthenticated) {
        return <Navigate to={from} replace />;
    }

    const onSubmit = handleSubmit(async (values) => {
        setServerError(null);
        try {
            await login(values.email, values.password);
            navigate(from, { replace: true });
        } catch (error) {
            if (error instanceof ApiError) {
                setServerError(error.message);
            } else {
                setServerError(i18n._(t`Anmeldung fehlgeschlagen. Bitte versuche es erneut.`));
            }
        }
    });

    return (
        <section className="mx-auto flex max-w-md flex-col gap-6">
            <h1 className="text-3xl font-bold">{i18n._(t`Anmelden`)}</h1>

            {serverError ? (
                <div role="alert" className="alert alert-error">
                    <span>{serverError}</span>
                </div>
            ) : null}

            <form className="flex flex-col gap-4" noValidate onSubmit={onSubmit}>
                <div className="form-control">
                    <label className="label" htmlFor="login-email">
                        <span className="label-text">{i18n._(t`E-Mail`)}</span>
                    </label>
                    <input
                        id="login-email"
                        type="email"
                        autoComplete="email"
                        className={`input ${errors.email ? 'input-error' : ''}`}
                        {...register('email')}
                        required
                    />
                    {errors.email ? <span className="label-text-alt mt-1 text-error">{errors.email.message}</span> : null}
                </div>

                <div className="form-control">
                    <label className="label" htmlFor="login-password">
                        <span className="label-text">{i18n._(t`Passwort`)}</span>
                    </label>
                    <input
                        id="login-password"
                        type="password"
                        autoComplete="current-password"
                        className={`input ${errors.password ? 'input-error' : ''}`}
                        {...register('password')}
                        required
                    />
                    {errors.password ? (
                        <span className="label-text-alt mt-1 text-error">{errors.password.message}</span>
                    ) : null}
                </div>

                <button type="submit" className="btn btn-primary mt-2" disabled={isSubmitting}>
                    {isSubmitting ? <span className="loading loading-spinner loading-xs"></span> : null}
                    {i18n._(t`Anmelden`)}
                </button>
            </form>
        </section>
    );
}
