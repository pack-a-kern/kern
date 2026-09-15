import { type JSX } from 'react';

import AppShell from '../components/AppShell';
import { Page, TabRail } from '../components/panel';
import { useRememberedState } from '../hooks/useRememberedState';
import { useTranslation } from '../i18n';
import AuditTab from './logs/AuditTab';
import JobsTab from './logs/JobsTab';
import TasksTab from './logs/TasksTab';

type Tab = 'audit' | 'jobs' | 'tasks';

const TABS: readonly Tab[] = ['audit', 'jobs', 'tasks'];

/**
 * Everything this installation writes down about itself.
 *
 * Three records that answer three different questions, kept on one screen because the question a
 * person arrives with is usually "what happened", not "which table holds it":
 *
 *  - **Audit** — the append-only, hash-chained trail of who did what to taxpayer data. Evidence.
 *  - **Jobs** — the work queue, read live from Horizon. What the machine is doing right now.
 *  - **Tasks** — the six maintenance commands and every run of them. What the machine was told to
 *    do on a schedule, and what it reported back.
 *
 * They are genuinely separate populations and none can be derived from another: a task travels the
 * queue wrapped in one job class and records its own outcome, so it never appears among the failed
 * jobs; no queued job ever appears in the run ledger; and the audit chain records neither, because
 * it records decisions rather than mechanics.
 *
 * Each tab carries its own permission and handles its own refusal inline — `audit.view` and
 * `operations.view` are held by different people, and a missing one should cost a tab rather than
 * the screen.
 */
export default function Logs(): JSX.Element {
    const { t } = useTranslation();
    // The tab last opened, so coming back to the logs lands where the person was.
    const [tab, setTab] = useRememberedState<Tab>('logs.tab', 'audit', { isValid: (value) => TABS.includes(value) });

    return (
        <AppShell>
            <Page title={t((d) => d.pages.logs.title)} lede={t((d) => d.pages.logs.description)}>
                <TabRail
                    value={tab}
                    onChange={setTab}
                    tabs={[
                        { value: 'audit', label: t((d) => d.pages.logs.tabs.audit) },
                        { value: 'jobs', label: t((d) => d.pages.logs.tabs.jobs) },
                        { value: 'tasks', label: t((d) => d.pages.logs.tabs.tasks) },
                    ]}
                />

                {/* Mounted one at a time, not hidden with CSS: each tab holds a live poll or a
                    paged fetch, and three of those running behind a tab nobody is looking at is
                    three times the load for one screen's worth of answers. */}
                {tab === 'audit' ? <AuditTab /> : null}
                {tab === 'jobs' ? <JobsTab /> : null}
                {tab === 'tasks' ? <TasksTab /> : null}
            </Page>
        </AppShell>
    );
}
