import { useCallback, useEffect, useMemo, useState, type JSX, type ReactNode } from 'react';
import { toast } from 'sonner';

import {
    fetchAiModels,
    getAiTaskModels,
    getBankLogos,
    getBrowserProxy,
    getPlatformData,
    listAiProviders,
    listSettings,
    listUserAgents,
    replaceAiConnection,
    replaceBrowserIdentity,
    replaceBrowserProxy,
    listSystemHealth,
    refreshBankLogos,
    testAiConnection,
    updateSettingsGroup,
    verifyAiModels,
} from '../../api/settingsApi';
import {
    Field,
    FieldGrid,
    FieldSpan,
    Metric,
    MetricGrid,
    Page,
    Panel,
    PanelButton,
    PanelDialog,
    PanelInput,
    PanelMultiSelect,
    PanelSelect,
    Pill,
    Row,
    RowList,
    Status,
    DataTable,
    type StatusTone,
} from '../../components/panel';
import { Skeleton } from '../../components/ui/skeleton';
import { Tooltip, TooltipContent, TooltipTrigger } from '../../components/ui/tooltip';
import { Switch } from '../../components/ui/switch';
import Checkbox from '../../components/Checkbox';
import { useFormErrors } from '../../hooks/useFormErrors';
import { useTranslation } from '../../i18n';
import type { Dictionary } from '../../i18n/dictionary';
import { ApiError } from '../../lib/http';
import { apiRequest } from '../../lib/http';
import { cn } from '../../lib/utils';
import { BASE_CURRENCY } from '../../types/wallet';
import {
    ActivityIcon,
    AppWindowIcon,
    BanknoteIcon,
    BotIcon,
    Building2Icon,
    Check,
    FilesIcon,
    GaugeIcon,
    GlobeIcon,
    ScaleIcon,
    KeyRound,
    KeyRoundIcon,
    LandmarkIcon,
    LinkIcon,
    ListIcon,
    LockIcon,
    PencilIcon,
    PlugZap,
    RefreshCw,
    RotateCcw,
    ServerIcon,
    ShieldCheckIcon,
    X,
} from 'lucide-react';

import { iconForSetting } from '../../lib/settingIcons';
import { unitFor } from '../../lib/settingUnits';
import {
    BROWSER_ENGINES,
    type AiModelVerification,
    type AiProvider,
    type AiTaskModel,
    type BankLogoCoverage,
    type BrowserEngine,
    type BrowserProxyView,
    type ComponentHealth,
    type Setting,
    type SettingGroupPayload,
    type UserAgentChoices,
} from '../../types/settings';
// Produced by the Operations module's probes; read here because what this installation is
// configured to trust, and whether those sources are still delivering, is a settings question.
import type { PlatformData, SchedulerStatus } from '../../types/operations';
import { useAppName } from '../../lib/sharedProps';

/**
 * Drafts hold what the operator typed, in the unit shown on screen — never the stored unit.
 *
 * Storing the converted value here and re-rendering it was a real defect: typing `26` into a size
 * field wrote `27262976` back into the same box on the next keystroke. Conversion now happens once,
 * when the payload is built.
 */
type Draft = Record<string, string>;

type GroupKey = keyof Dictionary['pages']['adminSettings']['groups'];

const AI_GROUP = 'ai';
const DOCUMENTS_GROUP = 'documents';
const BANKS_GROUP = 'banks';
const SOURCES_GROUP = 'sources';
const BROWSER_GROUP = 'browser';

/** The browser's engine and name: saved together, in their own dialog, never by the card's save. */
const BROWSER_ENGINE_KEY = 'sources.browser_engine';
const BROWSER_USER_AGENT_KEY = 'sources.browser_user_agent';
/** The proxy's keys, likewise saved together in their own dialog. */
const BROWSER_PROXY_PREFIX = 'browser_worker.proxy_';

/** Settings whose value is a long line — a browser's User-Agent — given the card's full width; the rest pair up. */
const WIDE_SETTINGS = new Set(['sources.browser_user_agent']);

/**
 * The mark on each card's head.
 *
 * Keyed by group rather than derived, because a group is a subject ("official sources", "security")
 * and nothing about its settings says which picture stands for it. A group with no entry simply has
 * no mark — the card still aligns.
 */
const GROUP_ICONS: Record<string, JSX.Element> = {
    ai: <BotIcon className="size-4" />,
    documents: <FilesIcon className="size-4" />,
    sources: <GlobeIcon className="size-4" />,
    browser: <AppWindowIcon className="size-4" />,
    legislation_portal: <ScaleIcon className="size-4" />,
    fx: <BanknoteIcon className="size-4" />,
    spv: <LandmarkIcon className="size-4" />,
    security: <ShieldCheckIcon className="size-4" />,
    banks: <Building2Icon className="size-4" />,
};

/**
 * System settings.
 *
 * Each card uses the body its content calls for rather than one generic key/value list: values you
 * type are a field grid, values you read far more often than you change are metrics with an edit
 * dialog, and anything that is a state rather than a value — a lock, a health check — is a row.
 * The provenance badge on every field says whether the value in effect was saved here or inherited
 * from server configuration; the legend at the foot explains it once.
 */
export default function AdminSettings(): JSX.Element {
    const appName = useAppName();
    const { t } = useTranslation();

    const [groups, setGroups] = useState<SettingGroupPayload[] | null>(null);
    const [providers, setProviders] = useState<AiProvider[]>([]);
    const [health, setHealth] = useState<ComponentHealth[] | 'failed' | null>(null);
    const [platform, setPlatform] = useState<PlatformData | null>(null);
    const [forbidden, setForbidden] = useState(false);
    const [loadFailed, setLoadFailed] = useState(false);
    const [drafts, setDrafts] = useState<Draft>({});
    const [savingGroup, setSavingGroup] = useState<string | null>(null);
    const [reloadToken, setReloadToken] = useState(0);

    const describeFailure = useCallback(
        (cause: unknown): string => {
            if (cause instanceof ApiError) {
                if (cause.reason === 'network') {
                    return t((d) => d.errors.network);
                }

                // Laravel's validation envelope names the offending field; showing the first
                // message beats "request failed", which tells the operator nothing actionable.
                const first = Object.values(cause.errors ?? {})[0]?.[0];

                if (first !== undefined) {
                    return first;
                }

                if (cause.status === 403) {
                    return t((d) => d.pages.adminSettings.forbidden);
                }
            }

            return t((d) => d.errors.unexpected);
        },
        [t],
    );

    useEffect(() => {
        let active = true;

        listSettings()
            .then((loaded) => {
                if (!active) {
                    return;
                }

                setGroups(loaded);
                setLoadFailed(false);
                setForbidden(false);
            })
            .catch((cause: unknown) => {
                if (!active) {
                    return;
                }

                if (cause instanceof ApiError && cause.status === 403) {
                    setForbidden(true);

                    return;
                }

                setLoadFailed(true);
                toast.error(
                    t((d) => d.pages.adminSettings.loadFailed),
                    { description: describeFailure(cause) },
                );
            });

        return () => {
            active = false;
        };
    }, [reloadToken, describeFailure, t]);

    // The preset catalogue and the health board are both auxiliary: neither blocks the form, and a
    // failure leaves its own card empty rather than taking the page down with it.
    useEffect(() => {
        let active = true;

        void listAiProviders().then(
            (loaded) => {
                if (active) {
                    setProviders(loaded);
                }
            },
            () => {
                if (active) {
                    setProviders([]);
                }
            },
        );
        // A failed read is not an empty read: collapsing both to `[]` made a transient network
        // blip render as "status unknown", which is indistinguishable from a real answer.
        void listSystemHealth().then(
            (loaded) => {
                if (active) {
                    setHealth(loaded);
                }
            },
            () => {
                if (active) {
                    setHealth('failed');
                }
            },
        );
        void getPlatformData().then(
            (loaded) => {
                if (active) {
                    setPlatform(loaded);
                }
            },
            () => {
                if (active) {
                    // Both cards then render their own "unknown" rather than the page failing:
                    // neither is required to change a setting.
                    setPlatform({
                        scheduler: { alive: false, lastBeatAt: null, ageSeconds: null, maxAgeSeconds: 0 },
                        freshness: [],
                        sources: [],
                    });
                }
            },
        );

        return () => {
            active = false;
        };
    }, [reloadToken]);

    const dirtyGroups = useMemo(() => {
        const dirty = new Set<string>();

        for (const group of groups ?? []) {
            for (const setting of group.settings) {
                if (drafts[setting.key] !== undefined) {
                    dirty.add(group.group);
                }
            }
        }

        return dirty;
    }, [groups, drafts]);

    // Warn before losing edits — a settings form is easy to abandon by reflex.
    useEffect(() => {
        if (dirtyGroups.size === 0) {
            return;
        }

        const warn = (event: BeforeUnloadEvent): void => {
            event.preventDefault();
        };

        window.addEventListener('beforeunload', warn);

        return () => {
            window.removeEventListener('beforeunload', warn);
        };
    }, [dirtyGroups]);

    const setDraft = useCallback((key: string, value: string): void => {
        setDrafts((current) => ({ ...current, [key]: value }));
    }, []);

    const discardGroup = useCallback((group: SettingGroupPayload): void => {
        const keys = new Set(group.settings.map((setting) => setting.key));

        setDrafts((current) => Object.fromEntries(Object.entries(current).filter(([key]) => !keys.has(key))));
    }, []);

    const save = useCallback(
        async (group: SettingGroupPayload): Promise<void> => {
            const payload: Record<string, string | null> = {};

            for (const setting of group.settings) {
                const draft = drafts[setting.key];

                // A disabled field cannot produce a draft, but the payload is built from data
                // rather than from what the UI allowed — the server refuses these too.
                if (draft === undefined || setting.read_only) {
                    continue;
                }

                if (draft === '') {
                    // An emptied field clears the override and restores the inherited value; that
                    // is a different intent from leaving a field untouched, so it is sent
                    // explicitly rather than omitted.
                    payload[setting.key] = null;

                    continue;
                }

                const unit = unitFor(setting.key);
                payload[setting.key] = unit ? unit.toStored(draft) : draft;
            }

            setSavingGroup(group.group);

            try {
                setGroups(await updateSettingsGroup(group.group, payload));

                const saved = new Set(Object.keys(payload));
                setDrafts((current) => Object.fromEntries(Object.entries(current).filter(([key]) => !saved.has(key))));

                toast.success(
                    t((d) => d.pages.adminSettings.saved),
                    {
                        description: t((d) => d.pages.adminSettings.groups[group.group as GroupKey]),
                    },
                );
            } catch (cause: unknown) {
                toast.error(
                    t((d) => d.pages.adminSettings.saveFailed),
                    { description: describeFailure(cause) },
                );
            } finally {
                setSavingGroup(null);
            }
        },
        [drafts, describeFailure, t],
    );

    if (forbidden) {
        return (
            <>
                <div className="mx-auto w-full max-w-lg pt-16 text-center">
                    <h1 className="text-lg font-semibold">{t((d) => d.pages.adminSettings.title)}</h1>
                    <p className="text-muted-foreground mt-2 text-sm">{t((d) => d.pages.adminSettings.forbidden)}</p>
                </div>
            </>
        );
    }

    return (
        <>
            <Page
                title={t((d) => d.pages.adminSettings.title)}
                lede={t((d) => d.pages.adminSettings.description).replace('{appName}', appName)}
                actions={
                    loadFailed ? (
                        <PanelButton
                            onClick={() => {
                                setGroups(null);
                                setLoadFailed(false);
                                setReloadToken((token) => token + 1);
                            }}
                        >
                            <RotateCcw className="size-3.5" aria-hidden="true" />
                            {t((d) => d.pages.adminSettings.retry)}
                        </PanelButton>
                    ) : undefined
                }
            >
                {groups === null ? (
                    <div className="card-columns">
                        {[0, 1, 2, 3].map((index) => (
                            <Skeleton key={index} className="h-56 w-full rounded-xl" />
                        ))}
                    </div>
                ) : (
                    <>
                        {/* Multi-column flow rather than a row-aligned grid: these cards have very
                            different heights, and a grid aligns rows, leaving a tall card's
                            neighbour padded with dead space. */}
                        <div className="card-columns">
                            {groups.map((group) => (
                                <GroupPanel
                                    key={group.group}
                                    group={group}
                                    providers={providers}
                                    drafts={drafts}
                                    dirty={dirtyGroups.has(group.group)}
                                    saving={savingGroup === group.group}
                                    onDraft={setDraft}
                                    onDiscard={() => {
                                        discardGroup(group);
                                    }}
                                    onSave={() => {
                                        void save(group);
                                    }}
                                    describeFailure={describeFailure}
                                    onReloaded={setGroups}
                                />
                            ))}

                            <SystemStatusPanel
                                health={health}
                                scheduler={platform?.scheduler ?? null}
                                onRetry={() => {
                                    setHealth(null);
                                    setReloadToken((token) => token + 1);
                                }}
                            />
                            <DataFreshnessPanel platform={platform} />
                        </div>

                        {/* Outside the column flow: a five-column table does not belong in a
                            narrow card track, and this one is read across rather than down. Not on a
                            phone: it is a read-only list of what the code allows, looked at while
                            configuring an installation, which is not done from one. */}
                        <div className="hidden sm:block">
                            <OfficialSourcesPanel platform={platform} />
                        </div>

                        <Legend />
                    </>
                )}
            </Page>
        </>
    );
}

interface GroupPanelProps {
    group: SettingGroupPayload;
    providers: AiProvider[];
    drafts: Draft;
    dirty: boolean;
    saving: boolean;
    onDraft: (key: string, value: string) => void;
    onDiscard: () => void;
    onSave: () => void;
    describeFailure: (cause: unknown) => string;
    /** Settings saved outside the group save — the AI connection dialog — hand back the fresh state. */
    onReloaded: (groups: SettingGroupPayload[]) => void;
}

function GroupPanel(props: GroupPanelProps): JSX.Element {
    const { t } = useTranslation();
    const { group } = props;

    const actions = (
        <>
            {props.dirty ? (
                <PanelButton variant="ghost" onClick={props.onDiscard} disabled={props.saving}>
                    <RotateCcw className="size-3.5" aria-hidden="true" />
                    {t((d) => d.pages.adminSettings.reset)}
                </PanelButton>
            ) : null}
            <PanelButton primary onClick={props.onSave} disabled={!props.dirty || props.saving}>
                <Check className="size-3.5" aria-hidden="true" />
                {props.saving ? t((d) => d.pages.adminSettings.saving) : t((d) => d.pages.adminSettings.save)}
            </PanelButton>
        </>
    );

    if (group.group === AI_GROUP) {
        return (
            <AiPanel
                group={group}
                providers={props.providers}
                drafts={props.drafts}
                onDraft={props.onDraft}
                describeFailure={props.describeFailure}
                onReloaded={props.onReloaded}
                actions={actions}
            />
        );
    }

    if (group.group === BANKS_GROUP) {
        return (
            <BanksPanel
                group={group}
                drafts={props.drafts}
                onDraft={props.onDraft}
                describeFailure={props.describeFailure}
                dirty={props.dirty}
                actions={actions}
            />
        );
    }

    if (group.group === BROWSER_GROUP) {
        return (
            <BrowserPanel
                group={group}
                drafts={props.drafts}
                onDraft={props.onDraft}
                dirty={props.dirty}
                actions={actions}
                describeFailure={props.describeFailure}
                onReloaded={props.onReloaded}
            />
        );
    }

    if (group.group === SOURCES_GROUP) {
        return (
            <SourcesPanel
                group={group}
                drafts={props.drafts}
                onDraft={props.onDraft}
                dirty={props.dirty}
                actions={actions}
            />
        );
    }

    if (group.group === DOCUMENTS_GROUP) {
        return (
            <UploadLimitsPanel
                group={group}
                drafts={props.drafts}
                onDraft={props.onDraft}
                onDiscard={props.onDiscard}
                onSave={props.onSave}
                saving={props.saving}
                dirty={props.dirty}
            />
        );
    }

    return (
        <Panel
            title={t((d) => d.pages.adminSettings.groups[group.group as GroupKey])}
            description={t((d) => d.pages.adminSettings.groupHints[group.group as GroupKey])}
            icon={GROUP_ICONS[group.group]}
            aside={props.dirty ? <Pill>{t((d) => d.pages.adminSettings.unsaved)}</Pill> : undefined}
            footActions={actions}
        >
            {/* Read-only settings sit in the same grid as the rest, as disabled fields. They are
                part of the group's configuration and reading them alongside their siblings is the
                point; pulling them into a separate list made them look like a different kind of
                thing. The `locked` marker and the foot legend carry the "cannot change" meaning. */}
            <FieldGrid>
                {group.settings.map((setting) => {
                    const field = (
                        <SettingField
                            key={setting.key}
                            setting={setting}
                            draft={props.drafts[setting.key]}
                            onDraft={(value) => {
                                props.onDraft(setting.key, value);
                            }}
                        />
                    );

                    return WIDE_SETTINGS.has(setting.key) ? <FieldSpan key={setting.key}>{field}</FieldSpan> : field;
                })}
            </FieldGrid>
        </Panel>
    );
}

/**
 * Who this installation says it is, and how its browsers go out.
 *
 * The engine and the name are one choice — a name is honest only on its own engine — so they are shown
 * here and changed in a dialog that saves both. The proxy likewise: on with its address and credentials,
 * off with none. Both ask for the password or code again when saved. The crawler's own name stays a
 * field of the card, saved with it.
 */
function BrowserPanel(props: {
    group: SettingGroupPayload;
    drafts: Draft;
    onDraft: (key: string, value: string) => void;
    dirty: boolean;
    actions: ReactNode;
    describeFailure: (cause: unknown) => string;
    onReloaded: (groups: SettingGroupPayload[]) => void;
}): JSX.Element {
    const { t } = useTranslation();
    const [proxy, setProxy] = useState<BrowserProxyView | null>(null);
    const [identityOpen, setIdentityOpen] = useState(false);
    const [proxyOpen, setProxyOpen] = useState(false);
    const { describeFailure } = props;

    useEffect(() => {
        let active = true;

        getBrowserProxy().then(
            (loaded) => {
                if (active) {
                    setProxy(loaded);
                }
            },
            (cause: unknown) => {
                if (active) {
                    toast.error(describeFailure(cause));
                }
            },
        );

        return () => {
            active = false;
        };
    }, [describeFailure]);

    const valueOf = (key: string): string => props.group.settings.find((setting) => setting.key === key)?.value ?? '';
    const engine = valueOf(BROWSER_ENGINE_KEY);
    const userAgent = valueOf(BROWSER_USER_AGENT_KEY);
    const general = props.group.settings.filter(
        (setting) =>
            setting.key !== BROWSER_ENGINE_KEY &&
            setting.key !== BROWSER_USER_AGENT_KEY &&
            !setting.key.startsWith(BROWSER_PROXY_PREFIX),
    );
    const words = (d: Dictionary): Dictionary['pages']['adminSettings']['browserCard'] =>
        d.pages.adminSettings.browserCard;

    return (
        <Panel
            title={t((d) => d.pages.adminSettings.groups.browser)}
            description={t((d) => d.pages.adminSettings.groupHints.browser)}
            icon={GROUP_ICONS.browser}
            aside={props.dirty ? <Pill>{t((d) => d.pages.adminSettings.unsaved)}</Pill> : undefined}
            footActions={props.actions}
        >
            <FieldGrid>
                <FieldSpan>
                    <Field label={t((d) => words(d).engine)} hint={t((d) => words(d).identityHint)}>
                        <PanelInput
                            id="browser.identity.engine"
                            readOnly
                            icon={<AppWindowIcon className="size-3.5" />}
                            value={
                                (BROWSER_ENGINES as readonly string[]).includes(engine)
                                    ? t((d) => words(d).engines[engine as BrowserEngine])
                                    : engine
                            }
                            actionEnd={
                                <PanelButton
                                    onClick={() => {
                                        setIdentityOpen(true);
                                    }}
                                >
                                    <PencilIcon className="size-3.5" aria-hidden="true" />
                                    {t((d) => words(d).change)}
                                </PanelButton>
                            }
                        />
                    </Field>
                </FieldSpan>
                <FieldSpan>
                    <Field
                        label={t(
                            (d) =>
                                d.pages.adminSettings.settingLabels[BROWSER_USER_AGENT_KEY] ?? BROWSER_USER_AGENT_KEY,
                        )}
                    >
                        <PanelInput id="browser.identity.user_agent" readOnly value={userAgent} />
                    </Field>
                </FieldSpan>
                {general.map((setting) => (
                    <SettingField
                        key={setting.key}
                        setting={setting}
                        draft={props.drafts[setting.key]}
                        onDraft={(value) => {
                            props.onDraft(setting.key, value);
                        }}
                    />
                ))}
            </FieldGrid>

            <div className="border-border mt-4 border-t pt-4">
                <RowList>
                    <Row
                        title={t((d) => words(d).proxyTitle)}
                        subtitle={
                            proxy === null
                                ? t((d) => words(d).proxyHint)
                                : proxy.enabled
                                  ? `${proxy.server ?? ''} · ${proxy.playbooks.length === 0 ? t((d) => words(d).proxyNoPlaybook) : proxy.playbooks.join(', ')}`
                                  : t((d) => words(d).proxyHint)
                        }
                        status={
                            proxy === null ? undefined : (
                                <Pill>{proxy.enabled ? t((d) => words(d).proxyOn) : t((d) => words(d).proxyOff)}</Pill>
                            )
                        }
                    >
                        <PanelButton
                            disabled={proxy === null}
                            onClick={() => {
                                setProxyOpen(true);
                            }}
                        >
                            <PencilIcon className="size-3.5" aria-hidden="true" />
                            {t((d) => words(d).change)}
                        </PanelButton>
                    </Row>
                </RowList>
            </div>

            <BrowserIdentityDialog
                open={identityOpen}
                onOpenChange={setIdentityOpen}
                engine={engine}
                userAgent={userAgent}
                describeFailure={props.describeFailure}
                onSaved={props.onReloaded}
            />
            {proxy === null ? null : (
                <BrowserProxyDialog
                    open={proxyOpen}
                    onOpenChange={setProxyOpen}
                    proxy={proxy}
                    describeFailure={props.describeFailure}
                    onSaved={setProxy}
                />
            )}
        </Panel>
    );
}

/**
 * The engine first, then a name it can honestly send, grouped by system — from the list read weekly. A
 * name typed by hand is the operator's to vouch for, so it is offered on Chromium only, as the server
 * accepts it.
 */
function BrowserIdentityDialog(props: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    engine: string;
    userAgent: string;
    describeFailure: (cause: unknown) => string;
    onSaved: (groups: SettingGroupPayload[]) => void;
}): JSX.Element {
    const { t } = useTranslation();
    const [choices, setChoices] = useState<UserAgentChoices | null>(null);
    const [engine, setEngine] = useState(props.engine);
    const [userAgent, setUserAgent] = useState(props.userAgent);
    const [saving, setSaving] = useState(false);
    const errors = useFormErrors();
    const { open, describeFailure } = props;
    const loaded = choices !== null;

    useEffect(() => {
        if (!open || loaded) {
            return;
        }

        listUserAgents().then(setChoices, (cause: unknown) => {
            toast.error(describeFailure(cause));
        });
    }, [open, loaded, describeFailure]);

    const words = (d: Dictionary): Dictionary['pages']['adminSettings']['browserCard'] =>
        d.pages.adminSettings.browserCard;
    const systems = choices?.engines.find((entry) => entry.engine === engine)?.systems ?? [];
    const listed = systems.flatMap((system) =>
        system.agents.map((agent) => ({
            value: agent.userAgent,
            label: `${t((d) => words(d).browsers[agent.browser] ?? agent.browser)} ${String(agent.version)}`,
            description: agent.userAgent,
            group: t((d) => words(d).systems[system.system] ?? system.system),
        })),
    );
    const typedAllowed = engine === 'chromium';
    // The name saved, when the list no longer holds it: shown as what it is, so the select never reads empty.
    const options =
        userAgent !== '' && typedAllowed && !listed.some((option) => option.value === userAgent)
            ? [{ value: userAgent, label: userAgent, group: t((d) => words(d).typedGroup) }, ...listed]
            : listed;

    const save = async (): Promise<void> => {
        setSaving(true);
        errors.reset();

        try {
            await replaceBrowserIdentity(engine as BrowserEngine, userAgent);
            props.onSaved(await listSettings());
            toast.success(t((d) => words(d).identitySaved));
            props.onOpenChange(false);
        } catch (cause: unknown) {
            if (!errors.capture(cause)) {
                toast.error(props.describeFailure(cause));
            }
        } finally {
            setSaving(false);
        }
    };

    return (
        <PanelDialog
            open={props.open}
            onOpenChange={(next) => {
                setEngine(props.engine);
                setUserAgent(props.userAgent);
                errors.reset();
                props.onOpenChange(next);
            }}
            title={t((d) => words(d).identityTitle)}
            description={t((d) => words(d).identityHint)}
            actions={
                <>
                    <PanelButton
                        variant="outline"
                        onClick={() => {
                            props.onOpenChange(false);
                        }}
                    >
                        <X className="size-3.5" aria-hidden="true" />
                        {t((d) => d.pages.adminSettings.cancel)}
                    </PanelButton>
                    <PanelButton
                        primary
                        disabled={userAgent === '' || saving}
                        onClick={() => {
                            void save();
                        }}
                    >
                        <Check className="size-3.5" aria-hidden="true" />
                        {saving ? t((d) => d.pages.adminSettings.saving) : t((d) => d.pages.adminSettings.save)}
                    </PanelButton>
                </>
            }
        >
            <FieldGrid>
                <FieldSpan>
                    <Field label={t((d) => words(d).engine)} error={errors.get('engine')}>
                        <PanelSelect
                            id="browser.identity.engine.choice"
                            value={engine}
                            options={BROWSER_ENGINES.map((value) => ({
                                value,
                                label: t((d) => words(d).engines[value]),
                            }))}
                            onValueChange={(value) => {
                                setEngine(value);
                                // A name belongs to one engine: picking another starts the name again.
                                setUserAgent(value === props.engine ? props.userAgent : '');
                            }}
                        />
                    </Field>
                </FieldSpan>
                <FieldSpan>
                    <Field
                        label={t(
                            (d) =>
                                d.pages.adminSettings.settingLabels[BROWSER_USER_AGENT_KEY] ?? BROWSER_USER_AGENT_KEY,
                        )}
                        hint={
                            choices === null
                                ? null
                                : choices.publishedAt === null
                                  ? t((d) => words(d).listEmpty)
                                  : t((d) => words(d).listDate).replace('{date}', choices.publishedAt.slice(0, 10))
                        }
                        error={errors.get('user_agent')}
                    >
                        <PanelSelect
                            id="browser.identity.user_agent.choice"
                            value={userAgent}
                            options={options}
                            searchable
                            placeholder={t((d) => words(d).pickUserAgent)}
                            {...(typedAllowed
                                ? {
                                      custom: (typed: string) =>
                                          typed === ''
                                              ? null
                                              : {
                                                    value: typed,
                                                    label: t((d) => words(d).useTyped).replace('{value}', typed),
                                                },
                                  }
                                : {})}
                            onValueChange={setUserAgent}
                        />
                    </Field>
                </FieldSpan>
            </FieldGrid>
        </PanelDialog>
    );
}

/**
 * The proxy: on with its address, user name and password, or off — which empties all three. The
 * password is never shown; keeping the proxy on means typing it again. Which playbooks use it is ticked
 * from those the installed packages registered.
 */
function BrowserProxyDialog(props: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    proxy: BrowserProxyView;
    describeFailure: (cause: unknown) => string;
    onSaved: (proxy: BrowserProxyView) => void;
}): JSX.Element {
    const { t } = useTranslation();
    const [enabled, setEnabled] = useState(props.proxy.enabled);
    const [server, setServer] = useState(props.proxy.server ?? '');
    const [username, setUsername] = useState(props.proxy.username ?? '');
    const [password, setPassword] = useState('');
    const [playbooks, setPlaybooks] = useState<string[]>(props.proxy.playbooks);
    const [saving, setSaving] = useState(false);
    const errors = useFormErrors();
    const words = (d: Dictionary): Dictionary['pages']['adminSettings']['browserCard'] =>
        d.pages.adminSettings.browserCard;

    const save = async (): Promise<void> => {
        setSaving(true);
        errors.reset();

        try {
            props.onSaved(await replaceBrowserProxy({ enabled, server: server.trim(), username, password, playbooks }));
            toast.success(t((d) => words(d).proxySaved));
            props.onOpenChange(false);
        } catch (cause: unknown) {
            if (!errors.capture(cause)) {
                toast.error(props.describeFailure(cause));
            }
        } finally {
            setSaving(false);
        }
    };

    return (
        <PanelDialog
            open={props.open}
            onOpenChange={(next) => {
                // Opened fresh every time, from what is saved — never a password typed last time.
                setEnabled(props.proxy.enabled);
                setServer(props.proxy.server ?? '');
                setUsername(props.proxy.username ?? '');
                setPassword('');
                setPlaybooks(props.proxy.playbooks);
                errors.reset();
                props.onOpenChange(next);
            }}
            title={t((d) => words(d).proxyTitle)}
            description={t((d) => words(d).proxyHint)}
            actions={
                <>
                    <PanelButton
                        variant="outline"
                        onClick={() => {
                            props.onOpenChange(false);
                        }}
                    >
                        <X className="size-3.5" aria-hidden="true" />
                        {t((d) => d.pages.adminSettings.cancel)}
                    </PanelButton>
                    <PanelButton
                        primary
                        disabled={saving}
                        onClick={() => {
                            void save();
                        }}
                    >
                        <Check className="size-3.5" aria-hidden="true" />
                        {saving ? t((d) => d.pages.adminSettings.saving) : t((d) => d.pages.adminSettings.save)}
                    </PanelButton>
                </>
            }
        >
            <RowList>
                <Row title={t((d) => words(d).proxyEnabled)}>
                    <Switch
                        id="browser.proxy.enabled"
                        aria-label={t((d) => words(d).proxyEnabled)}
                        checked={enabled}
                        onCheckedChange={setEnabled}
                    />
                </Row>
            </RowList>

            {enabled ? (
                <FieldGrid>
                    <FieldSpan>
                        <Field label={t((d) => words(d).proxyServer)} error={errors.get('server')}>
                            <PanelInput
                                id="browser.proxy.server"
                                value={server}
                                placeholder="http://proxy.example.net:3128"
                                autoComplete="off"
                                icon={<LinkIcon className="size-3.5" />}
                                onChange={(event) => {
                                    setServer(event.target.value);
                                }}
                            />
                        </Field>
                    </FieldSpan>
                    <Field label={t((d) => words(d).proxyUsername)} error={errors.get('username')}>
                        <PanelInput
                            id="browser.proxy.username"
                            value={username}
                            autoComplete="off"
                            onChange={(event) => {
                                setUsername(event.target.value);
                            }}
                        />
                    </Field>
                    <Field
                        label={t((d) => words(d).proxyPassword)}
                        hint={props.proxy.passwordSet ? t((d) => words(d).proxyPasswordAgain) : null}
                        error={errors.get('password')}
                    >
                        <PanelInput
                            id="browser.proxy.password"
                            type="password"
                            autoComplete="new-password"
                            value={password}
                            icon={<KeyRoundIcon className="size-3.5" />}
                            onChange={(event) => {
                                setPassword(event.target.value);
                            }}
                        />
                    </Field>
                </FieldGrid>
            ) : null}

            <p className="eyebrow mt-4 mb-2">{t((d) => words(d).proxyPlaybooks)}</p>
            {props.proxy.availablePlaybooks.length === 0 ? (
                <p className="note">{t((d) => words(d).proxyNoPlaybook)}</p>
            ) : (
                <RowList>
                    {props.proxy.availablePlaybooks.map((name) => (
                        <Row key={name} title={name}>
                            <Checkbox
                                aria-label={name}
                                checked={playbooks.includes(name)}
                                onCheckedChange={(checked) => {
                                    setPlaybooks((current) =>
                                        checked ? [...current, name] : current.filter((entry) => entry !== name),
                                    );
                                }}
                            />
                        </Row>
                    ))}
                </RowList>
            )}
        </PanelDialog>
    );
}

/**
 * Official sources: the limits on what is fetched, first; where each one is fetched from, folded below.
 * What a request says about itself is the Browser card's.
 *
 * The addresses are a different kind of setting — changed when a source moves, which is rare — and
 * listed among the limits they made the card read as a wall of URLs. Which is which
 * is read from the setting's own type, so an address added later folds in with the rest.
 */
function SourcesPanel(props: {
    group: SettingGroupPayload;
    drafts: Draft;
    onDraft: (key: string, value: string) => void;
    dirty: boolean;
    actions: ReactNode;
}): JSX.Element {
    const { t } = useTranslation();
    const isAddress = (setting: Setting): boolean => setting.type === 'url' || setting.key.endsWith('_url_template');
    // Who it says it is, then what it will accept — the order a reader asks in.
    const rank = (setting: Setting): number => (setting.type === 'header_value' || setting.type === 'contact' ? 0 : 1);
    const general = props.group.settings.filter((setting) => !isAddress(setting)).sort((a, b) => rank(a) - rank(b));
    const addresses = props.group.settings.filter(isAddress);

    const field = (setting: Setting): JSX.Element => (
        <SettingField
            key={setting.key}
            setting={setting}
            draft={props.drafts[setting.key]}
            onDraft={(value) => {
                props.onDraft(setting.key, value);
            }}
        />
    );

    return (
        <Panel
            title={t((d) => d.pages.adminSettings.groups.sources)}
            description={t((d) => d.pages.adminSettings.groupHints.sources)}
            icon={GROUP_ICONS.sources}
            aside={props.dirty ? <Pill>{t((d) => d.pages.adminSettings.unsaved)}</Pill> : undefined}
            footActions={props.actions}
        >
            <FieldGrid>{general.map(field)}</FieldGrid>

            {addresses.length === 0 ? null : (
                <details className="border-border mt-4 border-t pt-4">
                    <summary className="eyebrow cursor-pointer">
                        {t((d) => d.pages.adminSettings.sourceAddresses.title)}
                    </summary>
                    <p className="note mt-1 mb-3">{t((d) => d.pages.adminSettings.sourceAddresses.hint)}</p>
                    <FieldGrid>{addresses.map(field)}</FieldGrid>
                </details>
            )}
        </Panel>
    );
}

/**
 * The bank list's countries, and where its logos come from.
 *
 * The logo folder is not chosen here: it is the server's `BANKS_LOGOS_PATH`, and the card says where
 * it is and what to put in it — a folder per country, a file per bank — then how many of the banks a
 * person picks from have a logo, the file each of the rest would need, and "read again" for after
 * files were added.
 */
function BanksPanel(props: {
    group: SettingGroupPayload;
    drafts: Draft;
    onDraft: (key: string, value: string) => void;
    describeFailure: (cause: unknown) => string;
    dirty: boolean;
    actions: ReactNode;
}): JSX.Element {
    const { t } = useTranslation();
    const [coverage, setCoverage] = useState<BankLogoCoverage | null>(null);
    const [refreshing, setRefreshing] = useState(false);
    const [showMissing, setShowMissing] = useState(false);
    const { describeFailure } = props;
    const countries = props.group.settings.find((setting) => setting.key === 'banks.countries');

    useEffect(() => {
        let current = true;

        getBankLogos().then(
            (loaded) => {
                if (current) {
                    setCoverage(loaded);
                }
            },
            (cause: unknown) => {
                if (current) {
                    toast.error(
                        t((d) => d.pages.adminSettings.banks.loadFailed),
                        { description: describeFailure(cause) },
                    );
                }
            },
        );

        return () => {
            current = false;
        };
    }, [describeFailure, t]);

    const refresh = async (): Promise<void> => {
        setRefreshing(true);

        try {
            setCoverage(await refreshBankLogos());
            toast.success(t((d) => d.pages.adminSettings.banks.refreshed));
        } catch (cause: unknown) {
            toast.error(
                t((d) => d.pages.adminSettings.banks.loadFailed),
                { description: describeFailure(cause) },
            );
        } finally {
            setRefreshing(false);
        }
    };

    return (
        <Panel
            title={t((d) => d.pages.adminSettings.groups.banks)}
            description={t((d) => d.pages.adminSettings.groupHints.banks)}
            icon={GROUP_ICONS.banks}
            aside={props.dirty ? <Pill>{t((d) => d.pages.adminSettings.unsaved)}</Pill> : undefined}
            footActions={props.actions}
        >
            {/* The countries, and beside them the folder's two actions — under the field on a phone,
                side by side, each half the width; at the field's end from `sm`. Where the logos go is
                behind the mark beside the label: something a person looks up once, at the server. */}
            <div className="flex flex-col gap-3 sm:flex-row sm:items-end">
                {countries !== undefined ? (
                    <div className="min-w-0 flex-1">
                        <SettingField
                            setting={countries}
                            help={
                                coverage === null
                                    ? undefined
                                    : t((d) =>
                                          coverage.exists
                                              ? d.pages.adminSettings.banks.naming
                                              : d.pages.adminSettings.banks.noFolder,
                                      ).replace('{path}', coverage.path)
                            }
                            draft={props.drafts[countries.key]}
                            onDraft={(value) => {
                                props.onDraft(countries.key, value);
                            }}
                        />
                    </div>
                ) : null}
                <div className="flex gap-2">
                    <PanelButton
                        className="flex-1 sm:flex-none"
                        onClick={() => void refresh()}
                        disabled={refreshing || coverage === null}
                    >
                        <RefreshCw className={cn('size-3.5', refreshing && 'animate-spin')} aria-hidden="true" />
                        {t((d) => d.pages.adminSettings.banks.refresh)}
                    </PanelButton>
                    <PanelButton
                        variant="outline"
                        className="flex-1 sm:flex-none"
                        disabled={coverage === null || coverage.missing.length === 0}
                        onClick={() => {
                            setShowMissing(true);
                        }}
                    >
                        <ListIcon className="size-3.5" aria-hidden="true" />
                        {t((d) => d.pages.adminSettings.banks.viewMissing).replace(
                            '{count}',
                            String(coverage?.missing.length ?? 0),
                        )}
                    </PanelButton>
                </div>
            </div>
            <PanelDialog
                open={showMissing}
                onOpenChange={setShowMissing}
                title={t((d) => d.pages.adminSettings.banks.missingTitle)}
                description={t((d) => d.pages.adminSettings.banks.missingHint).replace('{path}', coverage?.path ?? '')}
                dismissOnOutsideClick
                actions={
                    <PanelButton
                        variant="outline"
                        onClick={() => {
                            setShowMissing(false);
                        }}
                    >
                        {t((d) => d.pages.adminSettings.banks.close)}
                    </PanelButton>
                }
            >
                <RowList>
                    {(coverage?.missing ?? []).map((bank) => (
                        <Row
                            key={bank.id}
                            title={bank.name}
                            subtitle={bank.file ?? t((d) => d.pages.adminSettings.banks.noFile)}
                        >
                            {null}
                        </Row>
                    ))}
                </RowList>
            </PanelDialog>
        </Panel>
    );
}

/**
 * Upload limits are read far more often than they are changed, so the card shows them formatted and
 * moves editing into a dialog. That also removes the reason the sizes were awkward to edit inline:
 * a unit suffix inside a number input collides with the spinner arrows, and there is nowhere good
 * to put it. In the dialog the unit belongs to the label — "Maximum PDF size (MB)".
 */
function UploadLimitsPanel(props: {
    group: SettingGroupPayload;
    drafts: Draft;
    dirty: boolean;
    saving: boolean;
    onDraft: (key: string, value: string) => void;
    onDiscard: () => void;
    onSave: () => void;
}): JSX.Element {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);

    const editable = props.group.settings.filter((setting) => !setting.read_only);

    return (
        <>
            <Panel
                title={t((d) => d.pages.adminSettings.groups.documents)}
                description={t((d) => d.pages.adminSettings.groupHints.documents)}
                icon={GROUP_ICONS.documents}
                aside={props.dirty ? <Pill>{t((d) => d.pages.adminSettings.unsaved)}</Pill> : undefined}
                footNote={t((d) => d.pages.adminSettings.limitsNote)}
                footActions={
                    <PanelButton
                        onClick={() => {
                            setOpen(true);
                        }}
                    >
                        <PencilIcon className="size-3.5" aria-hidden="true" />
                        {t((d) => d.pages.adminSettings.editLimits)}
                    </PanelButton>
                }
            >
                <MetricGrid>
                    {editable.map((setting) => (
                        <Metric
                            key={setting.key}
                            label={t((d) => d.pages.adminSettings.settingLabels[setting.key] ?? setting.key)}
                            value={formatSetting(setting, props.drafts[setting.key])}
                            mark={<SourceMark setting={setting} />}
                        />
                    ))}
                </MetricGrid>

                {/* What every upload goes through, beside the limits it is held to: one card for one
                    subject, where these used to be a second card a reader had to connect to this one. */}
                <section className="border-border mt-4 border-t pt-4">
                    <h3 className="eyebrow">{t((d) => d.pages.adminSettings.pipeline.title)}</h3>
                    <p className="note mt-1 mb-2">{t((d) => d.pages.adminSettings.pipeline.description)}</p>
                    <DocumentPipelineRows />
                </section>
            </Panel>

            <PanelDialog
                open={open}
                onOpenChange={setOpen}
                title={t((d) => d.pages.adminSettings.editLimits)}
                description={t((d) => d.pages.adminSettings.editLimitsHint)}
                actions={
                    <>
                        <PanelButton
                            variant="outline"
                            onClick={() => {
                                props.onDiscard();
                                setOpen(false);
                            }}
                            disabled={props.saving}
                        >
                            <X className="size-3.5" aria-hidden="true" />
                            {t((d) => d.pages.adminSettings.cancel)}
                        </PanelButton>
                        <PanelButton
                            primary
                            disabled={!props.dirty || props.saving}
                            onClick={() => {
                                props.onSave();
                                setOpen(false);
                            }}
                        >
                            <Check className="size-3.5" aria-hidden="true" />
                            {props.saving
                                ? t((d) => d.pages.adminSettings.saving)
                                : t((d) => d.pages.adminSettings.save)}
                        </PanelButton>
                    </>
                }
            >
                <FieldGrid>
                    {editable.map((setting) => (
                        <SettingField
                            key={setting.key}
                            setting={setting}
                            hideMarker
                            draft={props.drafts[setting.key]}
                            onDraft={(value) => {
                                props.onDraft(setting.key, value);
                            }}
                        />
                    ))}
                </FieldGrid>
            </PanelDialog>
        </>
    );
}

/**
 * The AI group, with the affordances a plain key/value list cannot give: a preset picker that fills
 * in the endpoint, a model list read from the provider itself, and a key that is replaced through a
 * dialog rather than by typing over a masked box.
 */
function AiPanel(props: {
    group: SettingGroupPayload;
    providers: AiProvider[];
    drafts: Draft;
    onDraft: (key: string, value: string) => void;
    describeFailure: (cause: unknown) => string;
    onReloaded: (groups: SettingGroupPayload[]) => void;
    actions: ReactNode;
}): JSX.Element {
    const { t } = useTranslation();
    const [liveModels, setLiveModels] = useState<string[] | null>(null);
    const [loadingModels, setLoadingModels] = useState(false);
    const [testing, setTesting] = useState(false);
    const [connection, setConnection] = useState<{ tone: StatusTone; label: string } | null>(null);
    const [connectionDialogOpen, setConnectionDialogOpen] = useState(false);
    const [taskModels, setTaskModels] = useState<AiTaskModel[] | null>(null);
    const [verifying, setVerifying] = useState(false);
    const [verification, setVerification] = useState<AiModelVerification | null>(null);

    // The per-task model list is this installation's own configuration, not a provider call, so it
    // loads with the card. The check against the provider stays behind its button. A failure to read
    // it leaves the section empty rather than breaking the AI card around it.
    useEffect(() => {
        let active = true;

        void getAiTaskModels()
            .then((models) => {
                if (active) {
                    setTaskModels(models);
                }
            })
            .catch(() => {
                if (active) {
                    setTaskModels([]);
                }
            });

        return () => {
            active = false;
        };
    }, []);

    const settingOf = (key: string): Setting | undefined => props.group.settings.find((s) => s.key === key);
    const valueOf = (key: string): string => props.drafts[key] ?? settingOf(key)?.value ?? '';

    const providerSetting = settingOf('ai.provider');
    const modelSetting = settingOf('ai.model');
    const baseUrlSetting = settingOf('ai.base_url');
    const providerKey = valueOf('ai.provider');
    const preset = props.providers.find((candidate) => candidate.key === providerKey);
    // The saved address, never a draft: it changes only with its key, in the connection dialog.
    const baseUrl = baseUrlSetting?.value ?? '';
    const apiKeySetting = settingOf('ai.api_key');
    // A provider picked but not saved yet offers its own address in the dialog; otherwise the saved one.
    const dialogAddress =
        preset?.baseUrl != null && providerKey !== (providerSetting?.value ?? '') ? preset.baseUrl : baseUrl;
    const enabledSetting = settingOf('ai.enabled');
    const maxTokensSetting = settingOf('ai.extraction_max_tokens');
    const timeoutSetting = settingOf('ai.extraction_timeout_seconds');
    const enhancementSetting = settingOf('ai.supports_enhancement');
    const conversionSetting = settingOf('ai.supports_conversion');
    const enabled = valueOf('ai.enabled') === '1' || valueOf('ai.enabled') === 'true';

    // Live models win once fetched; before that the preset's own list is offered, and a value
    // already saved is always included so the current configuration is never absent from its own
    // dropdown. Computed directly: at most a few hundred short strings, and the inputs it depends
    // on change on every keystroke anyway.
    const modelOptions = ((): string[] => {
        const options = new Set(liveModels ?? preset?.models ?? []);
        const current = valueOf('ai.model');

        if (current !== '') {
            options.add(current);
        }

        return [...options].sort((a, b) => a.localeCompare(b));
    })();

    const applyPreset = (key: string): void => {
        const chosen = props.providers.find((candidate) => candidate.key === key);

        props.onDraft('ai.provider', key);
        setLiveModels(null);
        setConnection(null);

        // The address is not drafted here: it is saved only with its key. A preset with an address of
        // its own opens the connection dialog with that address filled in.
        if (chosen?.baseUrl != null && chosen.baseUrl !== baseUrl) {
            setConnectionDialogOpen(true);
        }

        // The saved model almost certainly does not exist on a different provider.
        props.onDraft('ai.model', '');
    };

    const loadModels = async (): Promise<void> => {
        if (providerKey === '') {
            return;
        }

        setLoadingModels(true);

        try {
            const result = await fetchAiModels(providerKey);

            if (!result.ok) {
                toast.error(
                    t((d) => d.pages.adminSettings.ai.modelsFailed),
                    {
                        description: t(
                            (d) =>
                                d.pages.adminSettings.ai.modelReasons[result.reason as string] ?? d.errors.unexpected,
                        ),
                    },
                );

                return;
            }

            setLiveModels(result.models);
            toast.success(
                t((d) => d.pages.adminSettings.ai.modelsLoaded).replace('{count}', String(result.models.length)),
            );
        } catch (cause: unknown) {
            toast.error(
                t((d) => d.pages.adminSettings.ai.modelsFailed),
                {
                    description: props.describeFailure(cause),
                },
            );
        } finally {
            setLoadingModels(false);
        }
    };

    const runTest = async (): Promise<void> => {
        setTesting(true);

        try {
            const result = await testAiConnection();

            if (result.success) {
                setConnection({ tone: 'ok', label: t((d) => d.pages.adminSettings.testOk) });
                toast.success(
                    t((d) => d.pages.adminSettings.testOk),
                    {
                        ...(result.latencyMs !== null ? { description: `${String(result.latencyMs)} ms` } : {}),
                    },
                );

                return;
            }

            setConnection({ tone: 'bad', label: t((d) => d.pages.adminSettings.testFailed) });
            toast.error(
                t((d) => d.pages.adminSettings.testFailed),
                { description: t((d) => d.pages.adminSettings.ai.failedHint) },
            );
        } catch (cause: unknown) {
            setConnection({ tone: 'bad', label: t((d) => d.pages.adminSettings.testFailed) });
            toast.error(
                t((d) => d.pages.adminSettings.testFailed),
                { description: props.describeFailure(cause) },
            );
        } finally {
            setTesting(false);
        }
    };

    const runCheck = async (): Promise<void> => {
        setVerifying(true);

        try {
            const result = await verifyAiModels();
            setVerification(result);

            if (!result.checkable) {
                toast.error(
                    t((d) => d.pages.adminSettings.ai.taskModels.checkFailed),
                    {
                        description: t(
                            (d) =>
                                d.pages.adminSettings.ai.modelReasons[result.reason as string] ?? d.errors.unexpected,
                        ),
                    },
                );
            }
        } catch (cause: unknown) {
            setVerification(null);
            toast.error(
                t((d) => d.pages.adminSettings.ai.taskModels.checkFailed),
                {
                    description: props.describeFailure(cause),
                },
            );
        } finally {
            setVerifying(false);
        }
    };

    // The task id is the backend's contract; its label is the frontend's. Spelled out rather than
    // indexed so a task added to `config/ai/tasks.php` must be given a name here, and the i18n guard
    // sees every one of these keys reached.
    const taskLabel = (id: string): string => {
        switch (id) {
            case 'document_read':
                return t((d) => d.pages.adminSettings.ai.taskModels.tasks.document_read);
            case 'statement_mapping':
                return t((d) => d.pages.adminSettings.ai.taskModels.tasks.statement_mapping);
            case 'statement_classification':
                return t((d) => d.pages.adminSettings.ai.taskModels.tasks.statement_classification);
            case 'id_card':
                return t((d) => d.pages.adminSettings.ai.taskModels.tasks.id_card);
            case 'law_reader':
                return t((d) => d.pages.adminSettings.ai.taskModels.tasks.law_reader);
            case 'law_second_reader':
                return t((d) => d.pages.adminSettings.ai.taskModels.tasks.law_second_reader);
            default:
                return id;
        }
    };

    return (
        <>
            <Panel
                title={t((d) => d.pages.adminSettings.groups.ai)}
                description={t((d) => d.pages.adminSettings.groupHints.ai)}
                icon={GROUP_ICONS.ai}
                aside={
                    enabledSetting ? (
                        <Switch
                            aria-label={t((d) => d.pages.adminSettings.settingLabels['ai.enabled'] ?? 'ai.enabled')}
                            checked={enabled}
                            onCheckedChange={(checked) => {
                                props.onDraft('ai.enabled', checked ? '1' : '0');
                            }}
                        />
                    ) : undefined
                }
                footNote={
                    connection ? (
                        <Status tone={connection.tone}>{connection.label}</Status>
                    ) : (
                        t((d) => d.pages.adminSettings.ai.testHint)
                    )
                }
                footActions={
                    <>
                        <PanelButton
                            disabled={testing}
                            onClick={() => {
                                void runTest();
                            }}
                        >
                            <PlugZap className="size-3.5" aria-hidden="true" />
                            {testing
                                ? t((d) => d.pages.adminSettings.testing)
                                : t((d) => d.pages.adminSettings.testConnection)}
                        </PanelButton>
                        {props.actions}
                    </>
                }
            >
                <FieldGrid>
                    <Field
                        label={t((d) => d.pages.adminSettings.settingLabels['ai.provider'] ?? 'ai.provider')}
                        mark={providerSetting ? <SourceMark setting={providerSetting} /> : null}
                    >
                        {props.providers.length > 0 ? (
                            <PanelSelect
                                id="ai.provider"
                                value={providerKey}
                                onValueChange={applyPreset}
                                placeholder={t((d) => d.pages.adminSettings.ai.choose)}
                                options={props.providers.map((provider) => ({
                                    value: provider.key,
                                    label: provider.label,
                                }))}
                            />
                        ) : (
                            <PanelInput
                                id="ai.provider"
                                icon={iconForSetting('ai.provider', 'string')}
                                value={providerKey}
                                onChange={(event) => {
                                    props.onDraft('ai.provider', event.target.value);
                                }}
                            />
                        )}
                    </Field>

                    <Field
                        label={t((d) => d.pages.adminSettings.settingLabels['ai.model'] ?? 'ai.model')}
                        mark={modelSetting ? <SourceMark setting={modelSetting} /> : null}
                    >
                        <div className="flex gap-1.5">
                            {modelOptions.length > 0 ? (
                                <PanelSelect
                                    id="ai.model"
                                    className="min-w-0 flex-1"
                                    value={valueOf('ai.model')}
                                    onValueChange={(value) => {
                                        props.onDraft('ai.model', value);
                                    }}
                                    placeholder={t((d) => d.pages.adminSettings.ai.choose)}
                                    options={modelOptions.map((model) => ({ value: model, label: model }))}
                                />
                            ) : (
                                <PanelInput
                                    id="ai.model"
                                    icon={iconForSetting('ai.model', 'string')}
                                    className="min-w-0 flex-1"
                                    value={valueOf('ai.model')}
                                    onChange={(event) => {
                                        props.onDraft('ai.model', event.target.value);
                                    }}
                                />
                            )}

                            {preset?.canListModels === true ? (
                                <PanelButton
                                    inline
                                    disabled={loadingModels}
                                    onClick={() => {
                                        void loadModels();
                                    }}
                                >
                                    <RefreshCw className="size-3.5" aria-hidden="true" />
                                    {loadingModels
                                        ? t((d) => d.pages.adminSettings.ai.loadingModels)
                                        : t((d) => d.pages.adminSettings.ai.loadModels)}
                                </PanelButton>
                            ) : null}
                        </div>
                    </Field>

                    <FieldSpan>
                        <Field
                            label={t((d) => d.pages.adminSettings.settingLabels['ai.base_url'] ?? 'ai.base_url')}
                            mark={baseUrlSetting ? <SourceMark setting={baseUrlSetting} /> : null}
                            hint={
                                preset?.docsUrl != null ? (
                                    <a
                                        className="underline underline-offset-2"
                                        href={preset.docsUrl}
                                        target="_blank"
                                        rel="noreferrer noopener"
                                    >
                                        {t((d) => d.pages.adminSettings.ai.docs)}
                                    </a>
                                ) : null
                            }
                        >
                            {/* Read-only, like the key beside it: the address changes only with its key,
                                in the connection dialog, so a key is never sent to an address it was
                                not saved with. */}
                            <PanelInput
                                id="ai.base_url"
                                readOnly
                                icon={<LinkIcon className="size-3.5" />}
                                value={baseUrl === '' ? t((d) => d.pages.adminSettings.secretUnset) : baseUrl}
                                actionEnd={
                                    <PanelButton
                                        onClick={() => {
                                            setConnectionDialogOpen(true);
                                        }}
                                    >
                                        <PencilIcon className="size-3.5" aria-hidden="true" />
                                        {t((d) => d.pages.adminSettings.ai.changeConnection)}
                                    </PanelButton>
                                }
                            />
                        </Field>
                    </FieldSpan>

                    <FieldSpan>
                        <Field
                            label={t((d) => d.pages.adminSettings.settingLabels['ai.api_key'] ?? 'ai.api_key')}
                            mark={apiKeySetting ? <SourceMark setting={apiKeySetting} /> : null}
                            hint={
                                preset !== undefined && !preset.requiresApiKey
                                    ? t((d) => d.pages.adminSettings.ai.keyOptional)
                                    : null
                            }
                        >
                            {/* A stored key is never sent to the browser, so there is nothing to
                                edit in place — the field shows what is on file, and replacing it is
                                the same dialog as the address, because the two are saved together. */}
                            <PanelInput
                                readOnly
                                icon={<KeyRoundIcon className="size-3.5" />}
                                value={
                                    apiKeySetting?.has_value === true
                                        ? `•••••••• ${apiKeySetting.hint ?? ''}`.trim()
                                        : t((d) => d.pages.adminSettings.secretUnset)
                                }
                                actionEnd={
                                    <PanelButton
                                        onClick={() => {
                                            setConnectionDialogOpen(true);
                                        }}
                                    >
                                        <KeyRound className="size-3.5" aria-hidden="true" />
                                        {t((d) => d.pages.adminSettings.ai.replaceKey)}
                                    </PanelButton>
                                }
                            />
                        </Field>
                    </FieldSpan>

                    {/* Sits with the model because it is a property of the model: a reasoning model
                        spends this budget thinking before it answers and needs thousands of it, an
                        instruct model answers directly and needs far fewer. Both are chosen here. */}
                    <Field
                        label={t(
                            (d) =>
                                d.pages.adminSettings.settingLabels['ai.extraction_max_tokens'] ??
                                'ai.extraction_max_tokens',
                        )}
                        mark={maxTokensSetting ? <SourceMark setting={maxTokensSetting} /> : null}
                    >
                        <PanelInput
                            type="number"
                            min={1}
                            value={valueOf('ai.extraction_max_tokens')}
                            onChange={(event) => {
                                props.onDraft('ai.extraction_max_tokens', event.target.value);
                            }}
                        />
                    </Field>

                    {/* Reading a long statement on a self-hosted model takes minutes; a ceiling that
                        suits a receipt reports a working model as unreachable. */}
                    <Field
                        label={t(
                            (d) =>
                                d.pages.adminSettings.settingLabels['ai.extraction_timeout_seconds'] ??
                                'ai.extraction_timeout_seconds',
                        )}
                        mark={timeoutSetting ? <SourceMark setting={timeoutSetting} /> : null}
                    >
                        <PanelInput
                            type="number"
                            min={1}
                            value={valueOf('ai.extraction_timeout_seconds')}
                            onChange={(event) => {
                                props.onDraft('ai.extraction_timeout_seconds', event.target.value);
                            }}
                        />
                    </Field>

                    {/* A capability of the endpoint, not a preference: `docscanner` is something a
                        gateway either implements or does not, and OpenAI does not. Off means the
                        upload screen never offers it, rather than offering something that fails. */}
                    <FieldSpan>
                        <label className="flex items-start gap-2 text-xs">
                            <Checkbox
                                checked={valueOf('ai.supports_enhancement') === '1'}
                                onCheckedChange={(next) => {
                                    props.onDraft('ai.supports_enhancement', next ? '1' : '0');
                                }}
                                aria-label={t(
                                    (d) =>
                                        d.pages.adminSettings.settingLabels['ai.supports_enhancement'] ??
                                        'ai.supports_enhancement',
                                )}
                            />
                            <span className="min-w-0">
                                {t(
                                    (d) =>
                                        d.pages.adminSettings.settingLabels['ai.supports_enhancement'] ??
                                        'ai.supports_enhancement',
                                )}
                                {enhancementSetting ? <SourceMark setting={enhancementSetting} /> : null}
                            </span>
                        </label>
                    </FieldSpan>

                    {/* The same kind of capability: `docling` turns a statement PDF into CSV before it
                        is read. Off means a statement PDF is read here, as it always was. */}
                    <FieldSpan>
                        <label className="flex items-start gap-2 text-xs">
                            <Checkbox
                                checked={valueOf('ai.supports_conversion') === '1'}
                                onCheckedChange={(next) => {
                                    props.onDraft('ai.supports_conversion', next ? '1' : '0');
                                }}
                                aria-label={t(
                                    (d) =>
                                        d.pages.adminSettings.settingLabels['ai.supports_conversion'] ??
                                        'ai.supports_conversion',
                                )}
                            />
                            <span className="min-w-0">
                                {t(
                                    (d) =>
                                        d.pages.adminSettings.settingLabels['ai.supports_conversion'] ??
                                        'ai.supports_conversion',
                                )}
                                {conversionSetting ? <SourceMark setting={conversionSetting} /> : null}
                            </span>
                        </label>
                    </FieldSpan>

                    {/* Which model each kind of work uses, read from `config/ai/tasks.php` against the
                        base model, and — on the button, never on open — whether the provider actually
                        serves them. The embedding model is not here: it runs in-process, not on a
                        provider, so there is nothing to choose and nothing to check. */}
                    <FieldSpan>
                        <div className="flex flex-col gap-2">
                            <div className="flex items-start justify-between gap-2">
                                <div className="min-w-0">
                                    <div className="text-xs font-medium">
                                        {t((d) => d.pages.adminSettings.ai.taskModels.title)}
                                    </div>
                                    <div className="text-muted-foreground text-xs">
                                        {t((d) => d.pages.adminSettings.ai.taskModels.hint)}
                                    </div>
                                </div>
                                <PanelButton
                                    inline
                                    disabled={verifying || taskModels === null || taskModels.length === 0}
                                    onClick={() => {
                                        void runCheck();
                                    }}
                                >
                                    <RefreshCw className="size-3.5" aria-hidden="true" />
                                    {verifying
                                        ? t((d) => d.pages.adminSettings.ai.taskModels.checking)
                                        : t((d) => d.pages.adminSettings.ai.taskModels.check)}
                                </PanelButton>
                            </div>

                            <ul className="flex flex-col gap-1">
                                {(taskModels ?? []).map((task) => {
                                    const exists =
                                        task.model === null || verification === null || !verification.checkable
                                            ? null
                                            : verification.exists[task.model] === true;

                                    return (
                                        <li
                                            key={task.id}
                                            className="border-border flex items-center justify-between gap-2 rounded-sm border px-2 py-1 text-xs"
                                        >
                                            <span className="min-w-0 truncate">{taskLabel(task.id)}</span>
                                            <span className="flex shrink-0 items-center gap-1.5">
                                                {task.model === null ? (
                                                    <span className="text-muted-foreground">
                                                        {t((d) => d.pages.adminSettings.ai.taskModels.off)}
                                                    </span>
                                                ) : (
                                                    <>
                                                        <code className="font-mono">{task.model}</code>
                                                        {task.usesDefault ? (
                                                            <Pill>
                                                                {t((d) => d.pages.adminSettings.ai.taskModels.default)}
                                                            </Pill>
                                                        ) : null}
                                                    </>
                                                )}
                                                {exists === true ? (
                                                    <Status tone="ok">
                                                        {t((d) => d.pages.adminSettings.ai.taskModels.exists)}
                                                    </Status>
                                                ) : null}
                                                {exists === false ? (
                                                    <Status tone="bad">
                                                        {t((d) => d.pages.adminSettings.ai.taskModels.missing)}
                                                    </Status>
                                                ) : null}
                                            </span>
                                        </li>
                                    );
                                })}
                            </ul>
                        </div>
                    </FieldSpan>
                </FieldGrid>
            </Panel>

            <ConnectionDialog
                open={connectionDialogOpen}
                onOpenChange={setConnectionDialogOpen}
                initialAddress={dialogAddress}
                keyOptional={preset !== undefined && !preset.requiresApiKey}
                describeFailure={props.describeFailure}
                onSaved={(groups) => {
                    setConnection(null);
                    setLiveModels(null);
                    props.onReloaded(groups);
                }}
            />
        </>
    );
}

/**
 * The provider's address and its key, saved together and at once — not drafted into the group save.
 *
 * Together because a key must only ever go to the address it was saved with; at once because there
 * is nothing to stage — the server refuses either one through the group save. An empty key means the
 * provider takes none. The address is checked on the server, whose answer is shown under the field.
 */
function ConnectionDialog(props: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    initialAddress: string;
    keyOptional: boolean;
    describeFailure: (cause: unknown) => string;
    onSaved: (groups: SettingGroupPayload[]) => void;
}): JSX.Element {
    const { t } = useTranslation();
    const [address, setAddress] = useState(props.initialAddress);
    const [key, setKey] = useState('');
    const [saving, setSaving] = useState(false);
    const errors = useFormErrors();

    const save = async (): Promise<void> => {
        setSaving(true);
        errors.reset();

        try {
            await replaceAiConnection(address.trim(), key);
            props.onSaved(await listSettings());
            toast.success(t((d) => d.pages.adminSettings.ai.connectionSaved));
            props.onOpenChange(false);
        } catch (cause: unknown) {
            // A refused address names the field; anything else is a toast.
            if (!errors.capture(cause)) {
                toast.error(props.describeFailure(cause));
            }
        } finally {
            setSaving(false);
        }
    };

    return (
        <PanelDialog
            open={props.open}
            onOpenChange={(open) => {
                // Opened fresh every time, from what is saved — never a half-typed key from last time.
                setAddress(props.initialAddress);
                setKey('');
                errors.reset();
                props.onOpenChange(open);
            }}
            title={t((d) => d.pages.adminSettings.ai.connectionTitle)}
            description={t((d) => d.pages.adminSettings.ai.connectionHint)}
            actions={
                <>
                    <PanelButton
                        variant="outline"
                        onClick={() => {
                            props.onOpenChange(false);
                        }}
                    >
                        <X className="size-3.5" aria-hidden="true" />
                        {t((d) => d.pages.adminSettings.cancel)}
                    </PanelButton>
                    <PanelButton
                        primary
                        disabled={address.trim() === '' || saving}
                        onClick={() => {
                            void save();
                        }}
                    >
                        <Check className="size-3.5" aria-hidden="true" />
                        {saving ? t((d) => d.pages.adminSettings.saving) : t((d) => d.pages.adminSettings.save)}
                    </PanelButton>
                </>
            }
        >
            <FieldGrid>
                <FieldSpan>
                    <Field
                        label={t((d) => d.pages.adminSettings.settingLabels['ai.base_url'] ?? 'ai.base_url')}
                        error={errors.get('base_url')}
                    >
                        <PanelInput
                            id="ai.connection.base_url"
                            value={address}
                            placeholder="https://api.example.com"
                            autoComplete="off"
                            icon={<LinkIcon className="size-3.5" />}
                            onChange={(event) => {
                                setAddress(event.target.value);
                            }}
                        />
                    </Field>
                </FieldSpan>
                <FieldSpan>
                    <Field
                        label={t((d) => d.pages.adminSettings.settingLabels['ai.api_key'] ?? 'ai.api_key')}
                        hint={
                            props.keyOptional
                                ? t((d) => d.pages.adminSettings.ai.keyOptional)
                                : t((d) => d.pages.adminSettings.ai.keyHint)
                        }
                        error={errors.get('api_key')}
                    >
                        <PanelInput
                            id="ai.connection.api_key"
                            type="password"
                            autoComplete="new-password"
                            value={key}
                            placeholder="sk-••••••••••••"
                            icon={<KeyRoundIcon className="size-3.5" />}
                            onChange={(event) => {
                                setKey(event.target.value);
                            }}
                        />
                    </Field>
                </FieldSpan>
            </FieldGrid>
        </PanelDialog>
    );
}

/**
 * Properties of the upload pipeline that are deliberately not configurable. They are on this screen
 * because "can I turn this off?" is a real question an administrator arrives with, and the honest
 * answer is worth showing — an absent control reads as an oversight. Shown in the uploads card.
 */
function DocumentPipelineRows(): JSX.Element {
    const { t } = useTranslation();

    return (
        <RowList>
            <Row
                title={t((d) => d.pages.adminSettings.pipeline.antivirus)}
                subtitle={t((d) => d.pages.adminSettings.pipeline.antivirusHint)}
            >
                <Pill tone="locked">{t((d) => d.pages.adminSettings.pipeline.alwaysOn)}</Pill>
            </Row>
            <Row
                title={t((d) => d.pages.adminSettings.pipeline.nativeText)}
                subtitle={t((d) => d.pages.adminSettings.pipeline.nativeTextHint)}
            >
                <Pill tone="locked">{t((d) => d.pages.adminSettings.pipeline.alwaysOn)}</Pill>
            </Row>
            <Row
                title={t((d) => d.pages.adminSettings.pipeline.encryption)}
                subtitle={t((d) => d.pages.adminSettings.pipeline.encryptionHint)}
            >
                <Pill tone="locked">{t((d) => d.pages.adminSettings.pipeline.alwaysOn)}</Pill>
            </Row>
        </RowList>
    );
}

/** Live state of the services this installation runs. Every row is a real probe, never inferred. */
function SystemStatusPanel({
    health,
    scheduler,
    onRetry,
}: {
    health: ComponentHealth[] | 'failed' | null;
    scheduler: SchedulerStatus | null;
    onRetry: () => void;
}): JSX.Element {
    const { t } = useTranslation();

    const tone = (state: ComponentHealth['state']): StatusTone =>
        state === 'healthy' ? 'ok' : state === 'degraded' ? 'bad' : 'idle';

    const components = Array.isArray(health) ? health : [];
    const overall: StatusTone = !Array.isArray(health)
        ? 'idle'
        : components.some((component) => component.state === 'degraded')
          ? 'bad'
          : components.length > 0
            ? 'ok'
            : 'idle';

    return (
        <Panel
            title={t((d) => d.pages.adminSettings.health.title)}
            icon={<ActivityIcon className="size-4" />}
            description={t((d) => d.pages.adminSettings.health.description)}
            aside={
                // Only a real answer gets a summary; "could not read" belongs in the body, next to
                // the control that does something about it, and is not repeated here.
                Array.isArray(health) && components.length > 0 ? (
                    <Status tone={overall}>
                        {overall === 'ok'
                            ? t((d) => d.pages.adminSettings.health.allOperational)
                            : t((d) => d.pages.adminSettings.health.degraded)}
                    </Status>
                ) : undefined
            }
            {...(health === 'failed'
                ? {
                      footNote: t((d) => d.pages.adminSettings.health.unavailable),
                      footActions: (
                          <PanelButton onClick={onRetry}>
                              <RotateCcw className="size-3.5" aria-hidden="true" />
                              {t((d) => d.pages.adminSettings.retry)}
                          </PanelButton>
                      ),
                  }
                : {})}
        >
            {health === null ? (
                <Skeleton className="tile h-28 w-full" />
            ) : components.length === 0 ? (
                <p className="note">{t((d) => d.pages.adminSettings.health.unknown)}</p>
            ) : (
                <RowList>
                    {/* The scheduler belongs among the services, not on a screen of its own: it is
                        one more process that is either reachable or not, and it is read for the
                        same reason as the database or the antivirus. Its heartbeat is a real
                        liveness check — a wedged `schedule:work` stays "up" while beating nothing. */}
                    {scheduler === null ? null : (
                        <Row
                            title={t((d) => d.pages.adminSettings.scheduler.title)}
                            subtitle={
                                scheduler.ageSeconds === null
                                    ? t((d) => d.pages.adminSettings.scheduler.never)
                                    : t((d) => d.pages.adminSettings.scheduler.age)
                                          .replace('{seconds}', String(scheduler.ageSeconds))
                                          .replace('{limit}', String(scheduler.maxAgeSeconds))
                            }
                        >
                            <Status tone={scheduler.alive ? 'ok' : 'bad'}>
                                {scheduler.alive
                                    ? t((d) => d.pages.adminSettings.scheduler.alive)
                                    : t((d) => d.pages.adminSettings.scheduler.stalled)}
                            </Status>
                        </Row>
                    )}
                    {components.map((component) => (
                        <Row
                            key={component.key}
                            title={t((d) => d.pages.adminSettings.health.components[component.key] ?? component.key)}
                            {...(component.detail !== null ? { subtitle: component.detail } : {})}
                        >
                            <Status tone={tone(component.state)}>
                                {component.state === 'healthy'
                                    ? t((d) => d.pages.adminSettings.health.healthy)
                                    : component.state === 'degraded'
                                      ? t((d) => d.pages.adminSettings.health.unreachable)
                                      : t((d) => d.pages.adminSettings.health.notConfigured)}
                            </Status>
                        </Row>
                    ))}
                </RowList>
            )}
        </Panel>
    );
}

/** The value as a person reads it: converted to its display unit, with the unit appended. */
function formatSetting(setting: Setting, draft: string | undefined): string {
    const unit = unitFor(setting.key);
    const shown = draft ?? (unit ? unit.toDisplay(setting.value ?? '') : (setting.value ?? ''));

    if (shown === '') {
        return '—';
    }

    return unit ? `${shown} ${unit.suffix}` : shown;
}

/**
 * Which currencies this installation works in, chosen from the ones there are rates for.
 *
 * The app's own multi-select rather than a text field, because the two mistakes a text field invites
 * are the two that matter: a code with no rates behind it, which makes a form offer a currency
 * nothing can convert, and a typo, which silently offers nothing at all. The options come from the
 * rates themselves — `GET /v1/fx/currencies` — so this screen can only offer what the application
 * can actually convert.
 *
 * The base currency is not in the list. It is not a preference: the fiscal figures are in lei and
 * every foreign amount converts to it, so the server pins it whatever is saved here, and offering it
 * as something to remove would be offering a choice that is not one. The hint below says so.
 */
function CurrencyChoice(props: {
    id: string;
    value: string;
    disabled: boolean;
    onChange: (next: string) => void;
}): JSX.Element {
    const { t } = useTranslation();
    const [available, setAvailable] = useState<string[] | null>(null);

    useEffect(() => {
        let current = true;

        void apiRequest('/v1/fx/currencies')
            .then((text) => {
                const body: unknown = JSON.parse(text);
                const rows =
                    typeof body === 'object' && body !== null ? (body as { available?: unknown }).available : null;

                if (current) {
                    setAvailable(
                        Array.isArray(rows) ? rows.filter((code): code is string => typeof code === 'string') : [],
                    );
                }
            })
            .catch(() => {
                if (current) {
                    setAvailable([]);
                }
            });

        return () => {
            current = false;
        };
    }, []);

    const chosen = props.value
        .split(',')
        .map((code) => code.trim().toUpperCase())
        .filter((code) => code !== '' && code !== BASE_CURRENCY);
    // Until the rates answer, what is already chosen is the only thing there is to offer — a spinner
    // would hide the current setting while loading a longer list of it.
    const options = (available ?? chosen).filter((code) => code !== BASE_CURRENCY);

    return (
        <PanelMultiSelect
            id={props.id}
            values={chosen}
            // Alphabetical, not the order they were picked: this is a set of currencies, and a
            // numbered chip would claim a precedence the setting does not have.
            ordered={false}
            searchable
            options={options.map((code) => ({ value: code, label: code }))}
            addLabel={t((d) => d.pages.adminSettings.currencyAdd)}
            removeLabel={t((d) => d.pages.adminSettings.currencyRemove)}
            placeholder={t((d) => d.pages.adminSettings.currencyNone)}
            disabled={props.disabled}
            onChange={(next) => {
                props.onChange(next.join(','));
            }}
        />
    );
}

function SettingField(props: {
    setting: Setting;
    draft: string | undefined;
    onDraft: (value: string) => void;
    /** Provenance is already on the card that opened this editor; repeating it here only steals
     *  width from the label, which then truncates. */
    hideMarker?: boolean;
    /** An explanation behind a mark beside the label — `Field`'s `help`. */
    help?: string | undefined;
}): JSX.Element {
    const { t } = useTranslation();
    const { setting } = props;

    const isSecret = setting.type === 'secret';
    const isBoolean = setting.type === 'boolean';
    const isCurrencyList = setting.type === 'currency_list';
    const unit = unitFor(setting.key);

    // The draft is already in display units, so it is rendered verbatim; only the stored value
    // needs converting. Converting a draft here is what made typing into a size field replace the
    // typed number with a byte count.
    const shown = props.draft ?? (isSecret ? '' : unit ? unit.toDisplay(setting.value ?? '') : (setting.value ?? ''));

    return (
        <Field
            label={t((d) => d.pages.adminSettings.settingLabels[setting.key] ?? setting.key)}
            unit={unit?.suffix}
            mark={props.hideMarker === true ? null : <SourceMark setting={setting} />}
            {...(props.help === undefined ? {} : { help: props.help })}
            htmlFor={setting.key}
            inline={isBoolean}
            hint={
                isSecret
                    ? setting.has_value
                        ? t((d) => d.pages.adminSettings.secretReplace)
                        : t((d) => d.pages.adminSettings.secretUnset)
                    : null
            }
        >
            {isCurrencyList ? (
                <CurrencyChoice id={setting.key} value={shown} disabled={setting.read_only} onChange={props.onDraft} />
            ) : setting.type === 'choice' ? (
                <PanelSelect
                    id={setting.key}
                    value={shown}
                    disabled={setting.read_only}
                    onValueChange={props.onDraft}
                    options={setting.choices.map((choice) => ({
                        value: choice,
                        label: t((d) => d.pages.adminSettings.settingChoices[setting.key]?.[choice] ?? choice),
                    }))}
                />
            ) : isBoolean ? (
                <div className="flex h-8 items-center">
                    <Switch
                        id={setting.key}
                        checked={shown === '1' || shown === 'true'}
                        disabled={setting.read_only}
                        onCheckedChange={(checked) => {
                            props.onDraft(checked ? '1' : '0');
                        }}
                    />
                </div>
            ) : (
                <PanelInput
                    id={setting.key}
                    icon={iconForSetting(setting.key, setting.type)}
                    type={isSecret ? 'password' : setting.type === 'integer' ? 'number' : 'text'}
                    inputMode={setting.type === 'integer' ? 'decimal' : undefined}
                    step={unit?.step}
                    className={cn((setting.type === 'integer' || setting.type === 'decimal') && 'tabular-nums')}
                    value={shown}
                    // Disabled, not read-only: a disabled control is skipped by keyboard navigation
                    // and — the part that matters here — is never submitted with a form. The server
                    // refuses these keys as well; this is the visible half of that rule.
                    disabled={setting.read_only}
                    autoComplete={isSecret ? 'new-password' : 'off'}
                    onChange={(event) => {
                        props.onDraft(event.target.value);
                    }}
                />
            )}
        </Field>
    );
}

/**
 * Provenance, as a mark before the label rather than a pill after it.
 *
 * Three states, three colours, and the words in a tooltip. A pill repeating "inherited" beside
 * every one of a card's fields is the widest thing in the row and says the same thing eight times;
 * the mark carries the state at a glance and the sentence arrives when it is asked for. The foot
 * legend still spells all three out, for a first read.
 */
function SourceMark({ setting }: { setting: Setting }): JSX.Element {
    const { t } = useTranslation();

    const [tone, Icon, label] = setting.read_only
        ? (['locked', LockIcon, t((d) => d.pages.adminSettings.legend.locked)] as const)
        : setting.source === 'database'
          ? (['accent', PencilIcon, t((d) => d.pages.adminSettings.legend.database)] as const)
          : (['neutral', ServerIcon, t((d) => d.pages.adminSettings.legend.inherited)] as const);

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                {/* A span, not a button: it is not actionable, and making it focusable would put a
                    tab stop in front of every field for information the legend already gives. */}
                <span className={cn('source-mark', `source-mark-${tone}`)} aria-hidden="true">
                    <Icon className="size-2.5" />
                </span>
            </TooltipTrigger>
            <TooltipContent side="top">{label}</TooltipContent>
        </Tooltip>
    );
}

/**
 * How current the data this platform reasons over actually is.
 *
 * The question no queue metric answers: the queue can be empty, every worker healthy, and the BNR
 * rates still nine days old because the source published nothing and nobody looked. Each row is a
 * probe with its own staleness rule from `config/operations.php`, not a derived count.
 */
function DataFreshnessPanel({ platform }: { platform: PlatformData | null }): JSX.Element {
    const { t } = useTranslation();

    return (
        <Panel
            title={t((d) => d.pages.adminSettings.freshness.title)}
            icon={<GaugeIcon className="size-4" />}
            description={t((d) => d.pages.adminSettings.freshness.description)}
        >
            {platform === null ? (
                <div className="space-y-2">
                    {[0, 1, 2].map((index) => (
                        <Skeleton key={index} className="tile h-8 w-full" />
                    ))}
                </div>
            ) : platform.freshness.length === 0 ? (
                <p className="note">{t((d) => d.pages.adminSettings.freshness.unknown)}</p>
            ) : (
                <RowList>
                    {platform.freshness.map((item) => (
                        <Row
                            key={item.key}
                            title={t((d) => d.pages.adminSettings.freshness.labels[item.key] ?? item.key)}
                            {...(item.detail === null
                                ? {}
                                : {
                                      subtitle: t(
                                          (d) => d.pages.adminSettings.freshness.details[item.key] ?? '{detail}',
                                      ).replace('{detail}', item.detail),
                                  })}
                        >
                            <div className="flex items-center gap-2">
                                <span className="tabular-nums">
                                    {item.value ?? t((d) => d.pages.adminSettings.freshness.unknown)}
                                </span>
                                <Status tone={item.state}>{t((d) => d.pages.adminSettings.states[item.state])}</Status>
                            </div>
                        </Row>
                    ))}
                </RowList>
            )}
        </Panel>
    );
}

/**
 * The allowlist every download is checked against.
 *
 * Read-only, and that is a correctness property rather than a missing feature: the list is declared
 * in `config/sources.php` and re-mirrored onto `official_sources` at every fetch, and the fetcher
 * checks the config-derived definition rather than the row — so a switch here would report a state
 * it cannot hold.
 */
function OfficialSourcesPanel({ platform }: { platform: PlatformData | null }): JSX.Element {
    const { t } = useTranslation();

    return (
        <Panel
            title={t((d) => d.pages.adminSettings.sources.title)}
            icon={<GlobeIcon className="size-4" />}
            description={t((d) => d.pages.adminSettings.sources.description)}
            footNote={t((d) => d.pages.adminSettings.sources.readOnlyNotice)}
            flush
        >
            {platform === null ? (
                <div className="space-y-2 p-3">
                    {[0, 1, 2].map((index) => (
                        <Skeleton key={index} className="tile h-9 w-full" />
                    ))}
                </div>
            ) : platform.sources.length === 0 ? (
                <p className="note p-4">{t((d) => d.pages.adminSettings.freshness.unknown)}</p>
            ) : (
                <DataTable
                    // Stacked on a phone: a base URL is long enough on its own to push the trust
                    // tier and the state off-screen if these stayed five side-by-side columns.
                    stacked
                    head={
                        <>
                            <th>{t((d) => d.pages.adminSettings.sources.columns.code)}</th>
                            <th>{t((d) => d.pages.adminSettings.sources.columns.tier)}</th>
                            <th>{t((d) => d.pages.adminSettings.sources.columns.url)}</th>
                            <th>{t((d) => d.pages.adminSettings.sources.columns.enabled)}</th>
                            <th>{t((d) => d.pages.adminSettings.sources.columns.priority)}</th>
                            <th>{t((d) => d.pages.adminSettings.sources.columns.state)}</th>
                        </>
                    }
                >
                    {platform.sources.map((source) => (
                        <tr key={source.code}>
                            <td className="col-primary font-semibold">{source.code}</td>
                            <td data-label={t((d) => d.pages.adminSettings.sources.columns.tier)}>
                                <Pill>{source.trustTier}</Pill>
                            </td>
                            <td
                                className="mono col-wide break-all"
                                data-label={t((d) => d.pages.adminSettings.sources.columns.url)}
                            >
                                {source.baseUrl}
                            </td>
                            <td data-label={t((d) => d.pages.adminSettings.sources.columns.enabled)}>
                                <Status tone={source.enabled ? 'ok' : 'idle'}>
                                    {source.enabled
                                        ? t((d) => d.pages.adminSettings.sources.allowed)
                                        : t((d) => d.pages.adminSettings.sources.blocked)}
                                </Status>
                            </td>
                            <td
                                className="tabular-nums"
                                data-label={t((d) => d.pages.adminSettings.sources.columns.priority)}
                            >
                                {source.priority ?? '—'}
                            </td>
                            {/* A labelled line like its siblings, not `col-end`. The pinned corner
                                holds a value that speaks for itself and reserves 80px of the row's
                                name for it; this cell is a word of label plus a pill reading "Never
                                fetched", which is twice that and landed on top of the source's code.
                                The same call as `audit.tsx`, `jobs.tsx` and `tasks.tsx` made for
                                their own rows. */}
                            <td data-label={t((d) => d.pages.adminSettings.sources.columns.state)}>
                                {source.mirrored ? (
                                    <Pill>{t((d) => d.pages.adminSettings.sources.inUse)}</Pill>
                                ) : (
                                    <Pill tone="locked">{t((d) => d.pages.adminSettings.sources.neverFetched)}</Pill>
                                )}
                            </td>
                        </tr>
                    ))}
                </DataTable>
            )}
        </Panel>
    );
}

/** Explains the three marks once, at the foot of the page, instead of on every field. */
function Legend(): JSX.Element {
    const { t } = useTranslation();

    const rows: Array<[string, typeof LockIcon, string, string]> = [
        [
            'accent',
            PencilIcon,
            t((d) => d.pages.adminSettings.source.database),
            t((d) => d.pages.adminSettings.legend.database),
        ],
        [
            'neutral',
            ServerIcon,
            t((d) => d.pages.adminSettings.source.inherited),
            t((d) => d.pages.adminSettings.legend.inherited),
        ],
        [
            'locked',
            LockIcon,
            t((d) => d.pages.adminSettings.lockedBadge),
            t((d) => d.pages.adminSettings.legend.locked),
        ],
    ];

    return (
        // A key, not a card: it explains the page's notation rather than holding any of its content,
        // so it sits on the page ground with a hairline above it instead of competing with the
        // cards it describes.
        <section className="border-border mt-1 border-t pt-4">
            <h2 className="eyebrow mb-3">{t((d) => d.pages.adminSettings.legend.title)}</h2>
            <dl className="grid gap-x-6 gap-y-3 sm:grid-cols-2 xl:grid-cols-3">
                {rows.map(([tone, Icon, name, text]) => (
                    <div key={tone} className="flex items-start gap-2.5">
                        <dt className={cn('source-mark shrink-0', `source-mark-${tone}`)} aria-hidden="true">
                            <Icon className="size-2.5" />
                        </dt>
                        <dd className="min-w-0">
                            <span className="field-label block">{name}</span>
                            <span className="note mt-0.5 block">{text}</span>
                        </dd>
                    </div>
                ))}
            </dl>
        </section>
    );
}
