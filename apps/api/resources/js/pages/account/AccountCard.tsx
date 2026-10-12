import type { JSX } from 'react';
import { CalendarDaysIcon, MailIcon, ShieldCheckIcon, UserRoundIcon, ActivityIcon } from 'lucide-react';

import AvatarField from '../../components/AvatarField';
import { Detail, DetailList, Panel, Pill, Status } from '../../components/panel';
import { removeOwnAvatar, uploadOwnAvatar, userAvatarUrl } from '../../api/accountApi';
import { initialsFor } from '../../lib/initials';
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
export default function AccountCard({
    profile,
    onAvatarChange,
}: {
    profile: Profile | null;
    /** Told the new version, or null once the picture is gone, so the page can redraw it. */
    onAvatarChange: (version: string | null) => void;
}): JSX.Element {
    const { t, locale } = useTranslation();

    return (
        <Panel
            title={t((d) => d.pages.settingsProfile.accountTitle)}
            description={t((d) => d.pages.settingsProfile.accountDescription)}
            icon={<UserRoundIcon className="size-4" />}
            note={t((d) => d.pages.settingsProfile.identityNotice)}
        >
            {profile === null ? (
                <Skeleton className="tile h-28 w-full" />
            ) : (
                <>
                    {/* Above the list rather than inside it: the list is label-and-value, and a
                        control with two buttons is neither. Uploading is its own request, so a
                        refusal from the scanner belongs to the picture and not to anything else on
                        this card. Initials come from the email, since a login has no display name —
                        the person's name belongs to a taxpayer, as the note at the foot says. */}
                    <AvatarField
                        initials={initialsFor(profile.email)}
                        src={
                            profile.avatarUpdatedAt === null
                                ? undefined
                                : userAvatarUrl(profile.id, profile.avatarUpdatedAt)
                        }
                        onUpload={(file) => uploadOwnAvatar(file)}
                        onRemove={() => removeOwnAvatar()}
                        onChange={onAvatarChange}
                    />

                    <DetailList split>
                        <Detail label={t((d) => d.pages.settingsProfile.email)} icon={<MailIcon aria-hidden="true" />}>
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
                        <Detail
                            label={t((d) => d.pages.settingsProfile.status)}
                            icon={<ActivityIcon aria-hidden="true" />}
                        >
                            <Status tone={profile.status === 'ACTIVE' ? 'ok' : 'warn'}>{profile.status ?? '—'}</Status>
                        </Detail>
                        <Detail
                            label={t((d) => d.pages.settingsProfile.roles)}
                            icon={<ShieldCheckIcon aria-hidden="true" />}
                        >
                            {profile.roles.length === 0 ? (
                                <span className="note">{t((d) => d.pages.settingsProfile.noRoles)}</span>
                            ) : (
                                <div className="flex flex-wrap justify-end gap-1">
                                    {profile.roles.map((role) => (
                                        <Pill key={role} tone="accent">
                                            {role}
                                        </Pill>
                                    ))}
                                </div>
                            )}
                        </Detail>
                        <Detail
                            label={t((d) => d.pages.settingsProfile.created)}
                            icon={<CalendarDaysIcon aria-hidden="true" />}
                        >
                            {/* The instant, not "3 months ago": a detail row is read for the record, and
                            `formatDateTime` keeps it in the reader's own zone — slicing an instant to
                            its UTC date lands on the wrong day for anyone who signed up in the
                            evening west of Greenwich. */}
                            {profile.createdAt === null ? '—' : formatDateTime(profile.createdAt, locale)}
                        </Detail>
                    </DetailList>
                </>
            )}
        </Panel>
    );
}
