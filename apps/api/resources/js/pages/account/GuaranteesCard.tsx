import type { JSX } from 'react';
import { ShieldIcon } from 'lucide-react';

import { Panel, Pill, Row, RowList, Status } from '../../components/panel';
import { useTranslation } from '../../i18n';

/**
 * What is *not* optional.
 *
 * The consent switch lives on the taxpayers table, because consent is granted per taxpayer. This
 * card is the other half of the answer, and the more important one: an account holder asking
 * "where does my data go?" is owed the whole answer, and a screen that lists only the toggles
 * implies everything else is negotiable.
 */
export default function GuaranteesCard(): JSX.Element {
    const { t } = useTranslation();

    return (
        <Panel
            title={t((d) => d.pages.settingsPrivacy.guaranteesTitle)}
            description={t((d) => d.pages.settingsPrivacy.guaranteesDescription)}
            icon={<ShieldIcon className="size-4" />}
        >
            <RowList>
                <Row
                    title={t((d) => d.pages.settingsPrivacy.guarantees.encryption)}
                    subtitle={t((d) => d.pages.settingsPrivacy.guarantees.encryptionHint)}
                >
                    <Pill tone="locked">{t((d) => d.pages.settingsPrivacy.alwaysOn)}</Pill>
                </Row>
                <Row
                    title={t((d) => d.pages.settingsPrivacy.guarantees.localFirst)}
                    subtitle={t((d) => d.pages.settingsPrivacy.guarantees.localFirstHint)}
                >
                    <Pill tone="locked">{t((d) => d.pages.settingsPrivacy.alwaysOn)}</Pill>
                </Row>
                <Row
                    title={t((d) => d.pages.settingsPrivacy.guarantees.audit)}
                    subtitle={t((d) => d.pages.settingsPrivacy.guarantees.auditHint)}
                >
                    <Pill tone="locked">{t((d) => d.pages.settingsPrivacy.alwaysOn)}</Pill>
                </Row>
                <Row
                    title={t((d) => d.pages.settingsPrivacy.guarantees.logs)}
                    subtitle={t((d) => d.pages.settingsPrivacy.guarantees.logsHint)}
                >
                    <Status tone="ok">{t((d) => d.pages.settingsPrivacy.guarantees.redacted)}</Status>
                </Row>
            </RowList>
        </Panel>
    );
}
