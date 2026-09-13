import { useCallback, useEffect, useMemo, useState, type JSX, type ReactNode } from 'react';
import { toast } from 'sonner';

import {
    fetchAiModels,
    getPlatformData,
    listAiProviders,
    listSettings,
    listSystemHealth,
    testAiConnection,
    updateSettingsGroup,
} from '../../api/settingsApi';
import AppShell from '../../components/AppShell';
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
import { useTranslation } from '../../i18n';
import type { Dictionary } from '../../i18n/dictionary';
import { ApiError } from '../../lib/http';
import { cn } from '../../lib/utils';
import {
    ActivityIcon,
    BanknoteIcon,
    BotIcon,
    FilesIcon,
    KeyRoundIcon,
    LandmarkIcon,
    LinkIcon,
    LockIcon,
    PencilIcon,
    ScanLineIcon,
    ServerIcon,
    ShieldCheckIcon,
    GaugeIcon,
    GlobeIcon,
} from 'lucide-react';

import { iconForSetting } from '../../lib/settingIcons';
import { unitFor } from '../../lib/settingUnits';
import type { AiProvider, ComponentHealth, Setting, SettingGroupPayload } from '../../types/settings';
// Produced by the Operations module's probes; read here because what this installation is
// configured to trust, and whether those sources are still delivering, is a settings question.
import type { PlatformData, SchedulerStatus } from '../../types/operations';

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
    fx: <BanknoteIcon className="size-4" />,
    spv: <LandmarkIcon className="size-4" />,
    security: <ShieldCheckIcon className="size-4" />,
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
            <AppShell>
                <div className="mx-auto w-full max-w-lg pt-16 text-center">
                    <h1 className="text-lg font-semibold">{t((d) => d.pages.adminSettings.title)}</h1>
                    <p className="text-muted-foreground mt-2 text-sm">{t((d) => d.pages.adminSettings.forbidden)}</p>
                </div>
            </AppShell>
        );
    }

    return (
        <AppShell>
            <Page
                title={t((d) => d.pages.adminSettings.title)}
                lede={t((d) => d.pages.adminSettings.description)}
                actions={
                    loadFailed ? (
                        <PanelButton
                            onClick={() => {
                                setGroups(null);
                                setLoadFailed(false);
                                setReloadToken((token) => token + 1);
                            }}
                        >
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
                                />
                            ))}

                            <DocumentPipelinePanel />
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
                            narrow card track, and this one is read across rather than down. */}
                        <OfficialSourcesPanel platform={platform} />

                        <Legend />
                    </>
                )}
            </Page>
        </AppShell>
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
}

function GroupPanel(props: GroupPanelProps): JSX.Element {
    const { t } = useTranslation();
    const { group } = props;

    const actions = (
        <>
            {props.dirty ? (
                <PanelButton variant="ghost" onClick={props.onDiscard} disabled={props.saving}>
                    {t((d) => d.pages.adminSettings.reset)}
                </PanelButton>
            ) : null}
            <PanelButton primary onClick={props.onSave} disabled={!props.dirty || props.saving}>
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
                {group.settings.map((setting) => (
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
 * Splits a base URL into the scheme and everything after it.
 *
 * The stored value stays a whole URL — that is what the server validates and what every provider
 * preset supplies. The scheme is only *presented* separately, so the field holds the part someone
 * actually edits. Defaults to `https` while the value is empty or carries no scheme yet.
 */
function splitUrl(value: string): { scheme: string; rest: string } {
    const match = /^(https?):\/\/(.*)$/.exec(value);

    return match === null ? { scheme: 'https', rest: value } : { scheme: match[1] ?? 'https', rest: match[2] ?? '' };
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
    actions: ReactNode;
}): JSX.Element {
    const { t } = useTranslation();
    const [liveModels, setLiveModels] = useState<string[] | null>(null);
    const [loadingModels, setLoadingModels] = useState(false);
    const [testing, setTesting] = useState(false);
    const [connection, setConnection] = useState<{ tone: StatusTone; label: string } | null>(null);
    const [keyDialogOpen, setKeyDialogOpen] = useState(false);

    const settingOf = (key: string): Setting | undefined => props.group.settings.find((s) => s.key === key);
    const valueOf = (key: string): string => props.drafts[key] ?? settingOf(key)?.value ?? '';

    const providerSetting = settingOf('ai.provider');
    const modelSetting = settingOf('ai.model');
    const baseUrlSetting = settingOf('ai.base_url');
    const providerKey = valueOf('ai.provider');
    const preset = props.providers.find((candidate) => candidate.key === providerKey);
    const baseUrl = valueOf('ai.base_url');
    const apiKeyDraft = props.drafts['ai.api_key'] ?? '';
    const apiKeySetting = settingOf('ai.api_key');
    const enabledSetting = settingOf('ai.enabled');
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

        // Only prefill; never overwrite a URL the operator typed for a preset that has none.
        if (chosen?.baseUrl != null) {
            props.onDraft('ai.base_url', chosen.baseUrl);
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
            const result = await fetchAiModels({
                provider: providerKey,
                baseUrl: baseUrl === '' ? null : baseUrl,
                apiKey: apiKeyDraft === '' ? null : apiKeyDraft,
            });

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
                {
                    ...(result.message !== null ? { description: result.message } : {}),
                },
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
                            <PanelInput
                                id="ai.base_url"
                                value={splitUrl(baseUrl).rest}
                                placeholder="api.example.com"
                                autoComplete="off"
                                // Plain text, not a control: it states the scheme the stored value
                                // carries. It follows the value rather than dictating it, so the
                                // self-hosted preset — which is reached over http — shows `http://`
                                // instead of a fixed `https://` that would be a lie.
                                addonStart={
                                    <>
                                        <LinkIcon className="size-3.5" />
                                        {`${splitUrl(baseUrl).scheme}://`}
                                    </>
                                }
                                onChange={(event) => {
                                    const typed = event.target.value;
                                    const pasted = /^(https?):\/\//.exec(typed);

                                    // Pasting a whole URL adopts its scheme instead of producing
                                    // `https://http://host`.
                                    props.onDraft(
                                        'ai.base_url',
                                        pasted === null ? `${splitUrl(baseUrl).scheme}://${typed}` : typed,
                                    );
                                }}
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
                                edit in place — the field shows what is on file and replacing it is
                                a deliberate, separate act, fused to the field rather than floating
                                beside it. */}
                            <PanelInput
                                readOnly
                                icon={<KeyRoundIcon className="size-3.5" />}
                                value={
                                    apiKeyDraft !== ''
                                        ? t((d) => d.pages.adminSettings.ai.keyPending)
                                        : apiKeySetting?.has_value === true
                                          ? `•••••••• ${apiKeySetting.hint ?? ''}`.trim()
                                          : t((d) => d.pages.adminSettings.secretUnset)
                                }
                                actionEnd={
                                    <PanelButton
                                        onClick={() => {
                                            setKeyDialogOpen(true);
                                        }}
                                    >
                                        {t((d) => d.pages.adminSettings.ai.replaceKey)}
                                    </PanelButton>
                                }
                            />
                        </Field>
                    </FieldSpan>
                </FieldGrid>
            </Panel>

            <ReplaceKeyDialog
                open={keyDialogOpen}
                onOpenChange={setKeyDialogOpen}
                onSubmit={(value) => {
                    props.onDraft('ai.api_key', value);
                }}
            />
        </>
    );
}

function ReplaceKeyDialog(props: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onSubmit: (value: string) => void;
}): JSX.Element {
    const { t } = useTranslation();
    const [value, setValue] = useState('');

    return (
        <PanelDialog
            open={props.open}
            onOpenChange={(open) => {
                if (!open) {
                    setValue('');
                }

                props.onOpenChange(open);
            }}
            title={t((d) => d.pages.adminSettings.ai.replaceKeyTitle)}
            description={t((d) => d.pages.adminSettings.ai.replaceKeyHint)}
            actions={
                <>
                    <PanelButton
                        variant="outline"
                        onClick={() => {
                            props.onOpenChange(false);
                        }}
                    >
                        {t((d) => d.pages.adminSettings.cancel)}
                    </PanelButton>
                    <PanelButton
                        primary
                        disabled={value === ''}
                        onClick={() => {
                            props.onSubmit(value);
                            props.onOpenChange(false);
                        }}
                    >
                        {t((d) => d.pages.adminSettings.ai.replaceKey)}
                    </PanelButton>
                </>
            }
        >
            <Field label={t((d) => d.pages.adminSettings.settingLabels['ai.api_key'] ?? 'ai.api_key')}>
                <PanelInput
                    type="password"
                    autoComplete="new-password"
                    value={value}
                    placeholder="sk-••••••••••••"
                    onChange={(event) => {
                        setValue(event.target.value);
                    }}
                />
            </Field>
        </PanelDialog>
    );
}

/**
 * Properties of the upload pipeline that are deliberately not configurable. They are on this screen
 * because "can I turn this off?" is a real question an administrator arrives with, and the honest
 * answer is worth showing — an absent control reads as an oversight.
 */
function DocumentPipelinePanel(): JSX.Element {
    const { t } = useTranslation();

    return (
        <Panel
            title={t((d) => d.pages.adminSettings.pipeline.title)}
            icon={<ScanLineIcon className="size-4" />}
            description={t((d) => d.pages.adminSettings.pipeline.description)}
        >
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
        </Panel>
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
                      footActions: <PanelButton onClick={onRetry}>{t((d) => d.pages.adminSettings.retry)}</PanelButton>,
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

function SettingField(props: {
    setting: Setting;
    draft: string | undefined;
    onDraft: (value: string) => void;
    /** Provenance is already on the card that opened this editor; repeating it here only steals
     *  width from the label, which then truncates. */
    hideMarker?: boolean;
}): JSX.Element {
    const { t } = useTranslation();
    const { setting } = props;

    const isSecret = setting.type === 'secret';
    const isBoolean = setting.type === 'boolean';
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
            htmlFor={setting.key}
            hint={
                isSecret
                    ? setting.has_value
                        ? t((d) => d.pages.adminSettings.secretReplace)
                        : t((d) => d.pages.adminSettings.secretUnset)
                    : null
            }
        >
            {isBoolean ? (
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
                            <td className="col-end" data-label={t((d) => d.pages.adminSettings.sources.columns.state)}>
                                {source.mirrored ? (
                                    <Pill>{t((d) => d.pages.adminSettings.sources.inUse)}</Pill>
                                ) : (
                                    <Pill tone="locked">
                                        {t((d) => d.pages.adminSettings.sources.neverFetched)}
                                    </Pill>
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
