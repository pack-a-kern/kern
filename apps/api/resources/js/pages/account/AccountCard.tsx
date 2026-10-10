import type { JSX } from 'react';
import { UserRoundIcon } from 'lucide-react';

import { Detail, DetailList, Panel, Pill, Status } from '../../components/panel';
import { formatDateTime } from '../../lib/format';
import { Skeleton } from '../../components/ui/skeleton';
import { useTranslation } from '../../i18n';
import type { Profile } from '../../types/account';

/**
 * What the `users` row actually holds: an email, a status, the platform roles, a join date.
 *
 * Thin on purpose. Names, CNPs and addresses belong to a *taxpayer*, encrypted with that subject's
 * own key — they live on the taxpayers screen, and saying so here is why the notice at the foot
 * exists rather than leaving someone hunting for a name field that will never be on this card.
 */
export default function AccountCard({ profile }: { profile: Profile | null }): JSX.Element {
    const { t, locale } = useTranslation();

    return (
        <Panel
            title={t((d) => d.pages.settingsProfile.accountTitle)}
            description={t((d) => d.pages.settingsProfile.accountDescription)}
            icon={<UserRoundIcon className="size-4" />}
        >
            {profile === null ? (
                <Skeleton className="tile h-28 w-full" />
            ) : (
                <DetailList>
                    <Detail label={t((d) => d.pages.settingsProfile.email)}>
                        <span className="font-semibold">{profile.email}</span>
                        {profile.emailVerifiedAt === null ? (
                            <div>
                                {/*
                                 * A link, not a bare badge. It used to be the latter, and there was no
                                 * verification flow behind it at all — a state nobody could ever leave,
                                 * which reads as something the person should act on while offering
                                 * nothing to act with.
                                 */}
                                <a href="/verify-email" className="inline-flex">
                                    <Pill tone="locked">{t((d) => d.pages.settingsProfile.emailUnverified)}</Pill>
                                </a>
                            </div>
                        ) : null}
                    </Detail>
                    <Detail label={t((d) => d.pages.settingsProfile.status)}>
                        <Status tone={profile.status === 'ACTIVE' ? 'ok' : 'warn'}>{profile.status ?? '—'}</Status>
                    </Detail>
                    <Detail label={t((d) => d.pages.settingsProfile.roles)}>
                        {profile.roles.length === 0 ? (
                            <span className="note">{t((d) => d.pages.settingsProfile.noRoles)}</span>
                        ) : (
                            <div className="flex flex-wrap gap-1">
                                {profile.roles.map((role) => (
                                    <Pill key={role} tone="accent">
                                        {role}
                                    </Pill>
                                ))}
                            </div>
                        )}
                    </Detail>
                    <Detail label={t((d) => d.pages.settingsProfile.created)}>
                        {/* The instant, not "3 months ago": a detail row is read for the record, and
                            `formatDateTime` keeps it in the reader's own zone — slicing an instant to
                            its UTC date lands on the wrong day for anyone who signed up in the
                            evening west of Greenwich. */}
                        {profile.createdAt === null ? '—' : formatDateTime(profile.createdAt, locale)}
                    </Detail>
                </DetailList>
            )}
            <p className="note mt-3">{t((d) => d.pages.settingsProfile.identityNotice)}</p>
        </Panel>
    );
}
