/**
 * The shape every locale dictionary must satisfy. Adding a string means adding it to this
 * interface once — `ro.ts` and `en.ts` (`satisfies Dictionary`) then both fail to compile until
 * the same key exists in both, which is the actual guarantee this hand-rolled setup buys over a
 * runtime-keyed library: a missing translation is a `tsc` error, not a silently-blank string in
 * production. See `docs/handoff/040-ui-foundation.md` "i18n" for why this was chosen over
 * `react-i18next`/`next-intl`.
 */
export interface Dictionary {
    nav: {
        dashboard: string;
        walletGroup: string;
        wallet: string;
        walletAccounts: string;
        walletDeposits: string;
        documents: string;
        documentsCapture: string;
        rentals: string;
        taxesGroup: string;
        taxes: string;
        taxesExplain: string;
        declarations: string;
        legislation: string;
        spv: string;
        accountGroup: string;
        settingsProfile: string;
        settingsSecurity: string;
        settingsSubjects: string;
        settingsPrivacy: string;
        adminGroup: string;
        adminSettings: string;
        adminParameters: string;
        adminUsers: string;
        adminOperations: string;
        adminAudit: string;
    };
    shell: {
        appName: string;
        openMenu: string;
        closeMenu: string;
        collapseSidebar: string;
        expandSidebar: string;
        changeTheme: string;
        themeLight: string;
        themeDark: string;
        themeSystem: string;
        changeLanguage: string;
        languageRo: string;
        languageEn: string;
        account: string;
        signOut: string;
        activeSubject: string;
        noActiveSubject: string;
        manageSubjects: string;
    };
    placeholder: {
        badge: string;
        blockedOnLabel: string;
        backToDashboard: string;
    };
    common: {
        loading: string;
        cancel: string;
        save: string;
        loadMore: string;
        loadingMore: string;
    };
    pagination: {
        nav: string;
        previous: string;
        next: string;
        morePages: string;
    };
    home: {
        tagline: string;
        signIn: string;
    };
    auth: {
        appName: string;
        signInTitle: string;
        registerTitle: string;
        accountCreated: string;
        mfaCodeLabel: string;
        mfaCodeHint: string;
        mfaCodePlaceholder: string;
        confirm: string;
        back: string;
        emailLabel: string;
        passwordLabel: string;
        submitRegister: string;
        submitSignIn: string;
        passkeySignIn: string;
        noAccount: string;
        haveAccount: string;
        genericSignInError: string;
        genericMfaError: string;
        genericRegisterError: string;
        genericPasskeyError: string;
        rateLimited: string;
        enterEmailForPasskey: string;
    };
    dashboard: {
        title: string;
        loadError: string;
        aiConsentTitle: string;
        aiConsentBody: string;
        aiConsentGrant: string;
        aiConsentRevoke: string;
        aiConsentError: string;
        securityTitle: string;
        totpLabel: string;
        totpActive: string;
        totpInactive: string;
        passkeysLabel: string;
        recoveryCodesLabel: string;
        enrollTotp: string;
        enrollTotpError: string;
        registerPasskey: string;
        registerPasskeyError: string;
        regenerateCodes: string;
        regenerateCodesError: string;
        confirmTotpCodeLabel: string;
        confirmTotpHint: string;
        confirmTotpError: string;
        saveRecoveryCodesWarning: string;
        sessionsTitle: string;
        revokeOthers: string;
        revokeOthersError: string;
        thisSession: string;
        unknownDevice: string;
        lastUsed: string;
        revoke: string;
        revokeError: string;
        goToSecuritySettings: string;
        goToSubjectSettings: string;
        goToPrivacySettings: string;
        lede: string;
        empty: string;
    };
    subjects: {
        title: string;
        loading: string;
        loadError: string;
        activate: string;
        active: string;
        loadMore: string;
        empty: string;
        registerNew: string;
        registerTitle: string;
        registerEncryptionNote: string;
        displayNameLabel: string;
        firstNameLabel: string;
        lastNameLabel: string;
        cnpLabel: string;
        birthDateLabel: string;
        countyCodeLabel: string;
        localityLabel: string;
        addressLabel: string;
        submit: string;
        submitting: string;
        cancel: string;
        requiredFieldsError: string;
        cnpFormatError: string;
        birthDateFormatError: string;
        duplicateCnpError: string;
        genericError: string;
    };
    documentsCapture: {
        /** Shown for a queued upload that failed without the server sending its own message. */
        uploadFailedRetrying: string;
        title: string;
        intro: string;
        offlineNotice: string;
        typeLabel: string;
        takePhoto: string;
        chooseFiles: string;
        pickError: string;
        queueTitle: string;
        queueProcessing: string;
        queueEmpty: string;
        retry: string;
        remove: string;
        noActiveSubject: string;
        goToDashboard: string;
        statusQueued: string;
        statusUploading: string;
        statusUploaded: string;
        statusFailed: string;
        statusRejected: string;
    };
    documentsInbox: {
        title: string;
        newDocument: string;
        liveNotice: string;
        empty: string;
        review: string;
        noActiveSubject: string;
        goToDashboard: string;
        received: string;
    };
    documentsReview: {
        /** Shown in place of a `proposed_fields` value that cannot be serialised for display. */
        unrenderableValue: string;
        title: string;
        back: string;
        source: string;
        quarantineNotice: string;
        rejectedNotice: string;
        loadingPreview: string;
        unsupportedPreview: string;
        openInNewTab: string;
        proposedExtractions: string;
        noAutoConfirmNotice: string;
        loading: string;
        empty: string;
        accept: string;
        correct: string;
        submitCorrection: string;
        cancelCorrection: string;
        addField: string;
        removeField: string;
        fieldNamePlaceholder: string;
        fieldValuePlaceholder: string;
        correctionHint: string;
        reviewedBy: string;
        loadMore: string;
        noActiveSubject: string;
        alreadyReviewedError: string;
        acceptError: string;
        correctError: string;
        emptyCorrectionError: string;
        confidenceLabel: string;
    };
    statusBadge: {
        QUARANTINE: string;
        SCANNED: string;
        PROCESSING: string;
        READY: string;
        REJECTED: string;
    };
    extractionStatus: {
        PROPOSED: string;
        REVIEWED: string;
        ACCEPTED: string;
        REJECTED: string;
    };
    extractorType: {
        NATIVE_PDF_TEXT: string;
        EXTERNAL_DOCUMENT_AI: string;
        HUMAN_CORRECTION: string;
    };
    rejectionReason: {
        INFECTED: string;
        INVALID_MAGIC_BYTES: string;
        EXTENSION_MISMATCH: string;
        FILE_TOO_LARGE: string;
        IMAGE_PIXEL_LIMIT_EXCEEDED: string;
        UNSUPPORTED_FILE_TYPE: string;
        ENCRYPTED_PDF: string;
        TOO_MANY_PAGES: string;
        EMPTY_DOCUMENT: string;
        MALFORMED_PDF: string;
        EXTRACTION_TIMEOUT: string;
        EXTRACTION_FAILED: string;
    };
    documentType: {
        RECEIPT: string;
        INVOICE: string;
        BANK_STATEMENT: string;
        DEPOSIT_AGREEMENT: string;
        RENTAL_CONTRACT: string;
        ANAF_DECISION: string;
        OTHER: string;
    };
    subjectType: {
        PF: string;
        PFA: string;
        II: string;
        IF: string;
        SRL: string;
    };
    membershipRole: {
        OWNER: string;
        ADMIN: string;
        ACCOUNTANT: string;
        VIEWER: string;
    };
    /** Failure copy shared by every screen; keyed by `ApiError.reason` where one exists. */
    errors: {
        network: string;
        unexpected: string;
    };
    pages: {
        walletOverview: { title: string; description: string; blockedOn: string };
        walletAccounts: { title: string; description: string; blockedOn: string };
        walletDeposits: { title: string; description: string; blockedOn: string };
        rentals: { title: string; description: string; blockedOn: string };
        taxes: { title: string; description: string; blockedOn: string };
        taxesExplain: { title: string; description: string; blockedOn: string };
        declarations: { title: string; description: string; blockedOn: string };
        legislation: {
            title: string;
            description: string;
            searchPlaceholder: string;
            search: string;
            asOf: string;
            asOfHint: string;
            allTiers: string;
            /** Shown before any search has been run. */
            idle: string;
            empty: string;
            searchFailed: string;
            forbidden: string;
            /** Results whose effective dates cover the searched date. */
            confirmed: string;
            confirmedHint: string;
            /** Results with no effective dates at all — most of this corpus. */
            dateUnknown: string;
            dateUnknownHint: string;
            resultCount: string;
            effectiveFrom: string;
            effectiveUntil: string;
            published: string;
            chunkOf: string;
            /** How a hit scored, split into its lexical and semantic halves. */
            matchedBy: string;
            byWords: string;
            byMeaning: string;
            semanticUnavailable: string;
            copyCitation: string;
            citationCopied: string;
        };
        spv: { title: string; description: string; blockedOn: string };
        settingsProfile: {
            title: string;
            description: string;
            blockedOn: string;
            retry: string;
            loadFailed: string;
            accountTitle: string;
            accountDescription: string;
            email: string;
            emailUnverified: string;
            status: string;
            roles: string;
            noRoles: string;
            created: string;
            /** Why names and CNP are not on this screen. */
            identityNotice: string;
            passwordTitle: string;
            passwordDescription: string;
            passwordNote: string;
            passwordRules: string;
            currentPassword: string;
            newPassword: string;
            confirmPassword: string;
            changePassword: string;
            saving: string;
            passwordChanged: string;
            passwordFailed: string;
            passkeyOnly: string;
        };
        settingsSecurity: {
            title: string;
            description: string;
            loadFailed: string;
            factorsTitle: string;
            factorsDescription: string;
            passkeys: string;
            passkeysHint: string;
            totp: string;
            totpHint: string;
            recoveryCodes: string;
            recoveryCodesHint: string;
            registered: string;
            remaining: string;
            active: string;
            notSet: string;
            enrolNotice: string;
            sessionsTitle: string;
            sessionsDescription: string;
            sessionsNote: string;
            noSessions: string;
            thisDevice: string;
            unknownDevice: string;
            revoke: string;
            revokeOthers: string;
            sessionRevoked: string;
            othersRevoked: string;
            revokeFailed: string;
            columns: { device: string; ip: string; lastUsed: string };
            stats: {
                mfa: string;
                mfaSub: string;
                on: string;
                off: string;
                passkeys: string;
                recoveryCodes: string;
                recoveryCodesSub: string;
                sessions: string;
                sessionsSub: string;
            };
            addPasskey: string;
            enableTotp: string;
            regenerate: string;
            cancel: string;
            confirm: string;
            secret: string;
            secretHint: string;
            code: string;
            totpDialogTitle: string;
            totpDialogHint: string;
            totpEnabled: string;
            totpFailed: string;
            totpCodeRejected: string;
            passkeyAdded: string;
            passkeyFailed: string;
            codesTitle: string;
            codesHint: string;
            codesSaved: string;
            codesRegenerated: string;
            codesFailed: string;
            step1: string;
            step2: string;
            step3: string;
            recoveryCodesLocked: string;
            disable: string;
            disableTitle: string;
            disableHint: string;
            disableWarning: string;
            currentPassword: string;
            currentPasswordHint: string;
            totpDisabled: string;
            totpDisableFailed: string;
        };
        settingsSubjects: {
            title: string;
            description: string;
            retry: string;
            loadFailed: string;
            empty: string;
            tableTitle: string;
            addNote: string;
            types: Record<string, string | undefined>;
            roles: Record<string, string | undefined>;
            statuses: Record<string, string | undefined>;
            columns: { subject: string; type: string; role: string; status: string };
            stats: {
                total: string;
                active: string;
                owned: string;
                ownedSub: string;
                encryption: string;
                encryptionSub: string;
                perSubject: string;
            };
            add: string;
            added: string;
            addFailed: string;
            addTitle: string;
            addHint: string;
            cancel: string;
            saving: string;
            privacyNote: string;
            fields: {
                displayName: string;
                displayNameHint: string;
                firstName: string;
                lastName: string;
                cnp: string;
                birthDate: string;
                countyCode: string;
                locality: string;
                address: string;
            };
            checkFields: string;
        };
        settingsPrivacy: {
            title: string;
            description: string;
            retry: string;
            loadFailed: string;
            noSubjects: string;
            consentTitle: string;
            consentDescription: string;
            consentRowHint: string;
            consentNotice: string;
            consentGranted: string;
            consentRevoked: string;
            consentFailed: string;
            alwaysOn: string;
            guaranteesTitle: string;
            guaranteesDescription: string;
            guarantees: {
                encryption: string;
                encryptionHint: string;
                localFirst: string;
                localFirstHint: string;
                audit: string;
                auditHint: string;
                logs: string;
                logsHint: string;
                redacted: string;
            };
        };
        adminSettings: {
            title: string;
            description: string;
            blockedOn: string;
            source: { database: string; inherited: string; explain: string };
            readOnlyNotice: string;
            save: string;
            saving: string;
            saved: string;
            reset: string;
            unsaved: string;
            testConnection: string;
            testing: string;
            testOk: string;
            testFailed: string;
            secretSet: string;
            secretUnset: string;
            secretReplace: string;
            clearOverride: string;
            forbidden: string;
            loadFailed: string;
            retry: string;
            configPath: string;
            saveFailed: string;
            ai: {
                choose: string;
                docs: string;
                loadModels: string;
                loadingModels: string;
                modelsLoaded: string;
                modelsFailed: string;
                keyOptional: string;
                testHint: string;
                /**
                 * Keyed by the `reason` codes `App\Domain\Settings\Services\FetchAiProviderModels`
                 * returns. Indexed so an unrecognised code falls back rather than crashing the page;
                 * every code the service can emit is listed, and adding one there fails the build here.
                 */
                keyPending: string;
                replaceKey: string;
                replaceKeyTitle: string;
                replaceKeyHint: string;
                /** Keyed by ConnectionTestResult's `error` codes. */
                testReasons: Record<string, string | undefined>;
                modelReasons: Record<string, string | undefined>;
            };
            cancel: string;
            editLimits: string;
            editLimitsHint: string;
            limitsNote: string;
            /** Properties of the upload pipeline that are deliberately not configurable. */
            pipeline: {
                title: string;
                description: string;
                alwaysOn: string;
                antivirus: string;
                antivirusHint: string;
                nativeText: string;
                nativeTextHint: string;
                encryption: string;
                encryptionHint: string;
            };
            /** Live service status. Keyed by the component keys ProbeSystemHealth emits. */
            health: {
                title: string;
                description: string;
                allOperational: string;
                degraded: string;
                unavailable: string;
                unknown: string;
                healthy: string;
                unreachable: string;
                notConfigured: string;
                components: Record<string, string | undefined>;
            };
            groups: {
                ai: string;
                documents: string;
                sources: string;
                fx: string;
                spv: string;
                security: string;
            };
            legend: { title: string; database: string; inherited: string; locked: string };
            lockedBadge: string;
            settingLabels: Record<string, string>;
            groupHints: {
                ai: string;
                documents: string;
                sources: string;
                fx: string;
                spv: string;
                security: string;
            };
        };
        adminParameters: { title: string; description: string; blockedOn: string };
        adminUsers: {
            title: string;
            description: string;
            blockedOn: string;
            forbidden: string;
            loadFailed: string;
            retry: string;
            reset: string;
            empty: string;
            never: string;
            truncated: string;
            usersTitle: string;
            searchPlaceholder: string;
            allStatuses: string;
            mfaNone: string;
            mfaPasskeys: string;
            mfaTotp: string;
            members: string;
            notEnforced: string;
            rolesTitle: string;
            rolesDescription: string;
            /** States that recording a role and enforcing one are not the same thing here. */
            rolesNotice: string;
            enforcedTitle: string;
            enforcedDescription: string;
            edit: string;
            editTitle: string;
            editNotice: string;
            cancel: string;
            save: string;
            saving: string;
            saved: string;
            saveFailed: string;
            allRoles: string;
            noRole: string;
            noPermissions: string;
            systemRole: string;
            notEnforcedYet: string;
            matrixTitle: string;
            matrixDescription: string;
            matrixLegend: string;
            tabs: { users: string; roles: string; permissions: string };
            permissionGroups: Record<string, string | undefined>;
            statuses: Record<string, string | undefined>;
            roles: Record<string, string | undefined>;
            roleHints: Record<string, string | undefined>;
            columns: {
                user: string;
                role: string;
                permission: string;
                status: string;
                mfa: string;
                memberships: string;
                lastSeen: string;
            };
            stats: {
                users: string;
                admins: string;
                adminsSub: string;
                permissions: string;
                permissionsSub: string;
                mfa: string;
                mfaSub: string;
                settingsAdmins: string;
                settingsAdminsSub: string;
                rolesEnforced: string;
                rolesEnforcedSub: string;
                yes: string;
                no: string;
            };
            enforced: {
                active: string;
                planned: string;
                settingsAdmin: string;
                settingsAdminHint: string;
                tenancy: string;
                tenancyHint: string;
                rolePermissions: string;
                rolePermissionsHint: string;
            };
        };
        adminOperations: {
            title: string;
            description: string;
            forbidden: string;
            loadFailed: string;
            refresh: string;
            /** The four `Status` tones, named once so every panel labels them identically. */
            states: {
                ok: string;
                warn: string;
                bad: string;
                idle: string;
            };
            scheduler: {
                title: string;
                description: string;
                alive: string;
                stalled: string;
                never: string;
                lastBeat: string;
                /** "{seconds}s ago, limit {limit}s" — the staleness rule made visible. */
                age: string;
            };
            queue: {
                title: string;
                description: string;
                unavailable: string;
                pending: string;
                completed: string;
                failed: string;
                recentlyFailed: string;
                workers: string;
                longestWait: string;
                seconds: string;
            };
            tasks: {
                title: string;
                description: string;
                columns: {
                    task: string;
                    schedule: string;
                    lastRun: string;
                    state: string;
                };
                manualOnly: string;
                paused: string;
                running: string;
                readOnly: string;
                writes: string;
                never: string;
                run: string;
                runTitle: string;
                runConfirm: string;
                /** Named so the dialog can say exactly what will execute. */
                runCommand: string;
                argumentLabel: Record<string, string | undefined>;
                argumentRange: string;
                argumentUrlHint: string;
                argumentChoose: string;
                started: string;
                alreadyRunning: string;
                runFailed: string;
                enabled: string;
                enableFailed: string;
                names: Record<string, string | undefined>;
                descriptions: Record<string, string | undefined>;
            };
            activity: {
                title: string;
                description: string;
                empty: string;
                /** Why a failed job's payload is never rendered — a design property, not a gap. */
                noPayloadNotice: string;
                /** How far back the merged feed can see; it never claims more than it paged over. */
                windowNotice: string;
                columns: {
                    what: string;
                    when: string;
                    status: string;
                    action: string;
                };
                statusFilter: Record<string, string | undefined>;
                kindFilter: Record<string, string | undefined>;
                statuses: Record<string, string | undefined>;
                triggers: Record<string, string | undefined>;
                kinds: Record<string, string | undefined>;
                details: string;
                detailsTitle: string;
                trigger: string;
                duration: string;
                exitCode: string;
                output: string;
                noOutput: string;
                queue: string;
                connection: string;
                error: string;
                runAgain: string;
                retry: string;
                discard: string;
                discardTitle: string;
                discardConfirm: string;
                retried: string;
                discarded: string;
                actionFailed: string;
                page: string;
                previous: string;
                next: string;
            };
            freshness: {
                title: string;
                description: string;
                labels: Record<string, string | undefined>;
                details: Record<string, string | undefined>;
                unknown: string;
            };
            health: {
                title: string;
                description: string;
                components: Record<string, string | undefined>;
            };
            sources: {
                title: string;
                description: string;
                /** Why this table has no switch — a correctness property, not a missing feature. */
                readOnlyNotice: string;
                columns: {
                    code: string;
                    tier: string;
                    url: string;
                    enabled: string;
                    state: string;
                };
                allowed: string;
                blocked: string;
                inUse: string;
                neverFetched: string;
            };
            cancel: string;
            confirm: string;
        };
        adminAudit: {
            title: string;
            description: string;
            blockedOn: string;
            forbidden: string;
            loadFailed: string;
            empty: string;
            loading: string;
            loadMore: string;
            reset: string;
            details: string;
            detailsTitle: string;
            tableTitle: string;
            searchPlaceholder: string;
            allEvents: string;
            systemActor: string;
            platformScope: string;
            subject: string;
            chainTitle: string;
            chainKey: string;
            eventHash: string;
            previousHash: string;
            payloadHash: string;
            keyVersion: string;
            /** Why no request payload is shown — this is a design property, not a gap. */
            noPayloadNotice: string;
            ranges: Record<string, string | undefined>;
            columns: {
                time: string;
                event: string;
                resource: string;
                actor: string;
                sequence: string;
            };
            stats: {
                total: string;
                last24h: string;
                actors: string;
                actorsSub: string;
                integrity: string;
                integritySub: string;
                verified: string;
                broken: string;
            };
            page: string;
            previous: string;
            next: string;
        };
    };
}
