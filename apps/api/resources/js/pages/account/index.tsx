import { useCallback, useEffect, useState, type JSX } from 'react';
import { toast } from 'sonner';
import { KeyRoundIcon, MonitorSmartphoneIcon, RotateCcw, ShieldCheckIcon, UsersIcon } from 'lucide-react';

import { getProfile, listDocumentAiConsents, listSubjects } from '../../api/accountApi';
import { fetchMfaStatus, listSessions } from '../../api/authApi';
import AppShell from '../../components/AppShell';
import { Page, PanelButton, Stat, StatGrid, Status } from '../../components/panel';
import { useTranslation } from '../../i18n';
import { describeApiFailure } from '../../lib/apiErrors';
import type { Profile, SubjectsPage } from '../../types/account';
import type { MfaStatus, SessionSummary } from '../../types/auth';
import AccountCard from './AccountCard';
import GuaranteesCard from './GuaranteesCard';
import PasswordCard from './PasswordCard';
import AppearanceCard from './AppearanceCard';
import NotificationsCard from './NotificationsCard';
import SessionsCard from './SessionsCard';
import SignInMethods from './SignInMethods';

/**
 * Everything about the signed-in account, on one page.
 *
 * It replaces four screens — profile, security, taxpayers, privacy — which were four routes for
 * what a person thinks of as one thing. What is *not* here is anything belonging to a taxpayer:
 * their names, codes, AI consent and display currency live on the taxpayers screen, because each
 * taxpayer is encrypted under its own key and shared with its own members. Keeping that line
 * visible is the point — otherwise someone turns on AI for one taxpayer believing they turned it
 * on for the account.
 *
 * Four figures at the top rather than eight: a tile nobody reads is a tile in the way. Passkeys and
 * recovery codes answer one question together ("what else can get me in?"), and taxpayer count and
 * AI count answer another ("how many, and how many send documents out?").
 */
export default function AccountPage(): JSX.Element {
    const { t } = useTranslation();

    const [profile, setProfile] = useState<Profile | null>(null);
    const [mfa, setMfa] = useState<MfaStatus | null>(null);
    const [sessions, setSessions] = useState<SessionSummary[] | null>(null);
    const [subjects, setSubjects] = useState<SubjectsPage | null>(null);
    const [aiSubjects, setAiSubjects] = useState<number | null>(null);
    const [failed, setFailed] = useState(false);
    const [reloadToken, setReloadToken] = useState(0);

    const describeFailure = useCallback((cause: unknown): string => describeApiFailure(cause, t), [t]);

    useEffect(() => {
        let active = true;

        getProfile()
            .then((loaded) => {
                if (active) {
                    setProfile(loaded);
                    setFailed(false);
                }
            })
            .catch((cause: unknown) => {
                if (active) {
                    setFailed(true);
                    toast.error(
                        t((d) => d.pages.settingsProfile.loadFailed),
                        { description: describeFailure(cause) },
                    );
                }
            });

        // Not `Promise.all` with the profile: a failing MFA endpoint must not blank the account
        // card, and a failing profile must not hide the sessions. Each pane reports its own state.
        void fetchMfaStatus().then(
            (loaded) => {
                if (active) {
                    setMfa(loaded);
                }
            },
            () => {
                if (active) {
                    setMfa(null);
                }
            },
        );

        listSessions()
            .then((loaded) => {
                if (active) {
                    setSessions(loaded);
                }
            })
            .catch((cause: unknown) => {
                if (active) {
                    setSessions([]);
                    toast.error(
                        t((d) => d.pages.settingsSecurity.loadFailed),
                        { description: describeFailure(cause) },
                    );
                }
            });

        // Only for the summary tile — the taxpayers themselves are managed on their own screen.
        // The first page is enough for the AI count only while it holds every taxpayer, so the
        // *total* comes from the page's own count and the AI figure is qualified by it below.
        void Promise.all([listSubjects(), listDocumentAiConsents()]).then(
            ([page, consented]) => {
                if (active) {
                    setSubjects(page);
                    setAiSubjects(page.data.filter((subject) => consented.has(subject.id)).length);
                }
            },
            () => {
                if (active) {
                    setSubjects(null);
                    setAiSubjects(0);
                }
            },
        );

        return () => {
            active = false;
        };
    }, [reloadToken, describeFailure, t]);

    const reload = (): void => {
        setSessions(null);
        setReloadToken((token) => token + 1);
    };

    const protectedAccount = mfa !== null && (mfa.totpEnrolled || mfa.passkeysCount > 0);

    return (
        <AppShell>
            <Page
                title={t((d) => d.pages.account.title)}
                lede={t((d) => d.pages.account.description)}
                actions={
                    failed ? (
                        <PanelButton
                            onClick={() => {
                                setProfile(null);
                                setFailed(false);
                                setReloadToken((token) => token + 1);
                            }}
                        >
                            <RotateCcw className="size-3.5" aria-hidden="true" />
                            {t((d) => d.pages.settingsProfile.retry)}
                        </PanelButton>
                    ) : undefined
                }
            >
                <StatGrid>
                    <Stat
                        label={t((d) => d.pages.settingsSecurity.stats.mfa)}
                        value={
                            mfa === null ? (
                                '—'
                            ) : (
                                <Status tone={protectedAccount ? 'ok' : 'warn'}>
                                    {protectedAccount
                                        ? t((d) => d.pages.settingsSecurity.stats.on)
                                        : t((d) => d.pages.settingsSecurity.stats.off)}
                                </Status>
                            )
                        }
                        sub={t((d) => d.pages.settingsSecurity.stats.mfaSub)}
                        /* The one tile reporting a verdict rather than a count, so the one that
                           takes a tone — an unprotected account should read as unprotected. */
                        icon={<ShieldCheckIcon className="size-3.5" aria-hidden="true" />}
                        tone={mfa === null ? 'neutral' : protectedAccount ? 'success' : 'destructive'}
                    />
                    <Stat
                        label={t((d) => d.pages.account.stats.signIn)}
                        value={mfa === null ? '—' : mfa.passkeysCount}
                        sub={t((d) => d.pages.account.stats.signInSub).replace(
                            '{codes}',
                            String(mfa?.recoveryCodesRemaining ?? 0),
                        )}
                        icon={<KeyRoundIcon className="size-3.5" aria-hidden="true" />}
                    />
                    <Stat
                        label={t((d) => d.pages.settingsSecurity.stats.sessions)}
                        value={sessions === null ? '—' : sessions.length}
                        sub={t((d) => d.pages.settingsSecurity.stats.sessionsSub)}
                        icon={<MonitorSmartphoneIcon className="size-3.5" aria-hidden="true" />}
                    />
                    <Stat
                        label={t((d) => d.pages.account.stats.subjects)}
                        value={subjects === null ? '—' : subjects.total}
                        sub={t((d) => d.pages.account.stats.subjectsSub).replace('{count}', String(aiSubjects ?? 0))}
                        icon={<UsersIcon className="size-3.5" aria-hidden="true" />}
                    />
                </StatGrid>

                <div className="card-columns">
                    <AccountCard
                        profile={profile}
                        // In place rather than refetching: the upload is already accepted by the
                        // time this runs, and the rest of the card has not changed.
                        onAvatarChange={(version) => {
                            setProfile((current) =>
                                current === null ? current : { ...current, avatarUpdatedAt: version },
                            );
                        }}
                    />
                    <PasswordCard hasPassword={profile?.hasPassword ?? false} describeFailure={describeFailure} />
                    <SignInMethods mfa={mfa} onChanged={reload} describeFailure={describeFailure} />
                    <GuaranteesCard />
                    <AppearanceCard />
                    <NotificationsCard />
                </div>

                <SessionsCard sessions={sessions} onChanged={reload} />
            </Page>
        </AppShell>
    );
}
