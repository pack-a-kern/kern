/**
 * The shape every locale dictionary must satisfy. Adding a string means adding it to this
 * interface once — `ro.ts` and `en.ts` (`satisfies Dictionary`) then both fail to compile until
 * the same key exists in both, which is the actual guarantee this hand-rolled setup buys over a
 * runtime-keyed library: a missing translation is a `tsc` error, not a silently-blank string in
 * production. See `docs/handoff/040-ui-foundation.md` "i18n" for why this was chosen over
 * `react-i18next`/`next-intl`.
 */
import type { AiExtractionOutcome, DocumentStage, DocumentType } from '../types/documents';
import type { PaletteName } from '../lib/palette';

export interface Dictionary {
    nav: {
        dashboard: string;
        walletGroup: string;
        wallet: string;
        walletAccounts: string;
        walletTransactions: string;
        documents: string;
        rentals: string;
        taxesGroup: string;
        taxes: string;
        taxesExplain: string;
        declarations: string;
        legislation: string;
        spv: string;
        accountGroup: string;
        settingsSubjects: string;
        logs: string;
        adminGroup: string;
        adminSettings: string;
        adminParameters: string;
        adminUsers: string;
        adminOperations: string;
        adminJobs: string;
        adminAudit: string;
    };
    shell: {
        appName: string;
        appTagline: string;
        openMenu: string;
        closeMenu: string;
        collapseSidebar: string;
        expandSidebar: string;
        changeTheme: string;
        impersonating: string;
        impersonationStop: string;
        impersonationStopFailed: string;
        notifications: string;
        markAllRead: string;
        notificationTitle: string;
        notificationBody: string;
        changePalette: string;
        /** Keyed by `resources/js/lib/palette.ts`'s registry — add a palette, `tsc` asks for these. */
        paletteNames: Record<PaletteName, string>;
        paletteDescriptions: Record<PaletteName, string>;
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
        taxSubject: string;
        fiscalSnapshot: string;
        obligationCount: string;
        nextDeadline: string;
        searchSubjects: string;
        noSubjectsFound: string;
        manageSubjects: string;
    };
    placeholder: {
        badge: string;
        blockedOnLabel: string;
        backToDashboard: string;
    };
    common: {
        loading: string;
        save: string;
        /** Dismisses a dialog that has nothing to submit — a viewer, not a form. */
        cancel: string;
        close: string;
        /** The destructive action's own label — never "OK", which says nothing about what happens. */
        delete: string;
        deleteFailed: string;
        search: string;
        noResults: string;
        actions: string;
        loadMore: string;
        loadingMore: string;
    };
    pagination: {
        nav: string;
        previous: string;
        next: string;
        morePages: string;
        /** `{first}`, `{last}`, `{total}` and `{unit}` are substituted. */
        range: string;
        rows: string;
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
        eyebrow: string;
        welcomeTitle: string;
        welcomeSubtitle: string;
        mfaEyebrow: string;
        mfaTitle: string;
        mfaSubtitle: string;
        mfaPanelTitle: string;
        mfaPanelBody: string;
        mfaVerify: string;
        useRecoveryCode: string;
        useAuthenticatorCode: string;
        recoveryCodeLabel: string;
        recoveryCodeHint: string;
        recoveryCodePlaceholder: string;
        recoveryVerify: string;
        recoveryEyebrow: string;
        recoveryTitle: string;
        recoverySubtitle: string;
        registerEyebrow: string;
        registerSubtitle: string;
        showPassword: string;
        hidePassword: string;
        orSeparator: string;
        legalNote: string;
        asideBadge: string;
        asideTitle: string;
        asideBody: string;
        asideStrongTitle: string;
        asideStrongBody: string;
        asideDeviceTitle: string;
        asideDeviceBody: string;
        asideAuditTitle: string;
        asideAuditBody: string;
        footerCopyright: string;
    };
    dashboard: {
        title: string;
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
        backToInbox: string;
        addTitle: string;
        /** Shown beside the capture panel's title while the device has no connection. */
        offline: string;
        typeLabel: string;
        typeHint: string;
        processingNote: string;
        queueNote: string;
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
        statusDuplicate: string;
        duplicateNote: string;
        batchProgress: string;
    };
    documentsInbox: {
        title: string;
        /** `{count}` is substituted — the card head's running total. */
        count: string;
        /** What the pager counts, e.g. "documents". */
        unit: string;
        emptyTitle: string;
        selectPage: string;
        /** `{count}` is substituted. */
        selectedCount: string;
        deleteSelected: string;
        deleteSelectedTitle: string;
        deleteSelectedHint: string;
        emptyFiltered: string;
        description: string;
        newDocument: string;
        liveNotice: string;
        /** Shown when the list fell back to this device's cache because the server was unreachable. */
        offline: string;
        empty: string;
        loadFailed: string;
        /** How many extraction proposals on a document are still waiting on a human. */
        review: string;
        view: string;
        deleteTitle: string;
        deleteHint: string;
        deleted: string;
        noActiveSubject: string;
        goToDashboard: string;
        filters: {
            allStatuses: string;
            allTypes: string;
            everyDocument: string;
            from: string;
            received: string;
            to: string;
            needsReview: string;
            reset: string;
        };
        columns: {
            document: string;
            type: string;
            received: string;
            size: string;
            status: string;
        };
    };
    documentsReview: {
        /** Shown in place of a `proposed_fields` value that cannot be serialised for display. */
        unrenderableValue: string;
        title: string;
        description: string;
        receivedLabel: string;
        formatLabel: string;
        documentIdLabel: string;
        typeLabel: string;
        statusLabel: string;
        rejectedLabel: string;
        /** Alt text for the image preview — copy, so it belongs here rather than in the markup. */
        previewAlt: string;
        previewPdfTitle: string;
        back: string;
        source: string;
        sourceHint: string;
        zoomIn: string;
        zoomOut: string;
        rotate: string;
        quarantineNotice: string;
        rejectedNotice: string;
        loadingPreview: string;
        unsupportedPreview: string;
        openInNewTab: string;
        proposedExtractions: string;
        proposedExtractionsHint: string;
        /** `{percent}` is substituted. */
        confidenceChip: string;
        noAutoConfirmNotice: string;
        loading: string;
        empty: string;
        nameLabel: string;
        nameHint: string;
        originalFilenameLabel: string;
        rename: string;
        actions: string;
        renameSave: string;
        renameCancel: string;
        renameSuggestion: string;
        renameUseSuggestion: string;
        renameFailed: string;
        renameCleared: string;
        aiOutcomeWorking: string;
        aiOutcomeWorkingHint: string;
        aiOutcomeTitle: string;
        aiOutcomeAttemptedAt: string;
        aiOutcomeRetry: string;
        aiOutcomeRetrying: string;
        aiOutcomeRetryQueued: string;
        aiOutcomeRetryFailed: string;
        aiOutcomeRetryHint: string;
        /** Keyed by `App\Domain\Documents\Enums\AiExtractionOutcome`. */
        aiOutcomes: Record<AiExtractionOutcome, string>;
        accept: string;
        correct: string;
        submitCorrection: string;
        cancelCorrection: string;
        addField: string;
        removeField: string;
        fieldNamePlaceholder: string;
        fieldRequired: string;
        fieldOptional: string;
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
        /** The human step that turns a reviewed document into a wallet transaction. */
        createTransaction: string;
        createTransactionHint: string;
        createTransactionExisting: string;
        transactionAccount: string;
        transactionNoAccounts: string;
        transactionCurrencyHint: string;
        transactionDirection: string;
        directionOut: string;
        directionIn: string;
        transactionAmount: string;
        transactionAmountHint: string;
        transactionDate: string;
        transactionDescription: string;
        transactionCounterparty: string;
        transactionCreated: string;
        transactionFailed: string;
        historyTitle: string;
        historyHint: string;
        scansTitle: string;
        noScans: string;
        scannerLabel: string;
        definitionsLabel: string;
        /** Shown where a scanner answered a scan but not a version query. */
        notRecorded: string;
        scanOutcomes: Record<string, string | undefined>;
        chainTitle: string;
        noEvents: string;
        chainEvents: Record<string, string | undefined>;
    };
    /** Keyed by `App\Domain\Documents\Enums\DocumentStage` — what a person is told. */
    documentStage: Record<DocumentStage, string>;
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
        /** A deterministic parser written for one document layout — reproducible, unlike a model. */
        PATTERN_PARSER: string;
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
    /**
     * The extraction field names, as a person reads them.
     *
     * Keyed by the wire names `App\Domain\Documents\Parsing\ExtractionSchema` defines. A
     * `Record<string, string>` rather than a closed union because the schema is server-side: a new
     * field appears here as a missing translation, not a type error.
     */
    extractionField: Record<string, string>;
    documentType: {
        RECEIPT: string;
        INVOICE: string;
        BANK_STATEMENT: string;
        DEPOSIT_AGREEMENT: string;
        RENTAL_CONTRACT: string;
        ANAF_DECISION: string;
        OTHER: string;
    };
    /** One line saying what each document type is for — shown under its name in a picker. */
    documentTypeHint: Record<DocumentType, string>;
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
        rateLimited: string;
        /**
         * A refusal the server names with a code, translated here.
         *
         * `message` is English and written for whoever reads a log; `reason` is the machine-readable
         * half of the same answer, and the only part that can be said in a person's own language.
         * A code with no entry falls back to the server's sentence, which is still better than a
         * generic line.
         */
        reasons: Record<string, string | undefined>;
    };
    pages: {
        wallet: {
            /** Shared by all three wallet screens. */
            noSubject: string;
            loadFailed: string;
            empty: string;
            /** Why there is no single "total balance" across currencies. */
            perCurrencyNote: string;
            /** The system's own name for the source every taxpayer has. Never a stored value. */
            generalSource: string;
            unknownBalance: string;
            asOf: string;
            verified: string;
            /** The balance includes transactions added on top of (or instead of) an observation. */
            derived: string;
            unverified: string;
            sources: Record<string, string | undefined>;
            kinds: Record<string, string | undefined>;
            frequencies: Record<string, string | undefined>;
            rateTypes: Record<string, string | undefined>;
            interestMethods: Record<string, string | undefined>;
        };
        walletOverview: {
            title: string;
            description: string;
            /** Everything the subject holds, in one currency. An indication, never a fiscal figure. */
            netWorth: string;
            netWorthRate: string;
            netWorthDirect: string;
            netWorthPartial: string;
            totalBalance: string;
            acrossAccounts: string;
            deposits: string;
            depositCount: string;
            /** Forecast, not earned yet: what the running deposits will have produced by maturity. */
            interestAtMaturity: string;
            grossAccrued: string;
            recognisedIncome: string;
            recognisedHint: string;
            accountsTitle: string;
            accountsHint: string;
            viewAll: string;
            maturingTitle: string;
            maturingHint: string;
            viewDeposits: string;
            noAccounts: string;
            noMaturing: string;
        };
        walletAccounts: {
            title: string;
            description: string;
            edit: string;
            /** Shown instead of the row's actions on the source nobody may change. */
            systemSource: string;
            saved: string;
            columns: {
                account: string;
                type: string;
                balance: string;
                status: string;
            };
            active: string;
            add: string;
            addHint: string;
            added: string;
            addFailed: string;
            form: {
                displayName: string;
                displayNameHint: string;
                kind: string;
                currency: string;
                /** What a cash holding is worth — the source's whole value. */
                holdingAmount: string;
                holdingAmountHint: string;
                /** A deposit's own start date — the day interest begins accruing. */
                startsAt: string;
            };
            deleteTitle: string;
            deleteHint: string;
            deleted: string;
            /** Shown on a 409: records still explain a figure through this source. */
            deleteBlocked: string;
        };
        walletDeposits: {
            title: string;
            description: string;
            includeMatured: string;
            columns: {
                deposit: string;
                principal: string;
                rate: string;
                /** Gross interest over the whole term — what the deposit will have earned by then. */
                interestAtMaturity: string;
                opened: string;
                maturity: string;
                status: string;
            };
            matured: string;
            daysLeft: string;
            totalDays: string;
            autoRenewal: string;
            noRate: string;
            notComputed: string;
            notComputedHint: string;
            /** How the RON figure behind the interest gets its provenance, and when. */
            interestHint: string;
            add: string;
            addHint: string;
            added: string;
            addFailed: string;
            form: {
                institution: string;
                principal: string;
                principalHint: string;
                currency: string;
                openedAt: string;
                /** The length a deposit is bought as; the maturity date is derived from it. */
                term: string;
                termMonths: string;
                termOneMonth: string;
                termHint: string;
                maturityAt: string;
                maturityCalculated: string;
                maturityPending: string;
                annualRate: string;
                annualRateHint: string;
                rateType: string;
                interestMethod: string;
                paymentFrequency: string;
                autoRenewal: string;
            };
        };
        walletTransactions: {
            classify: {
                title: string;
                hint: string;
                category: string;
                country: string;
                amount: string;
                choose: string;
                addSplit: string;
                /** "Unassigned: {amount}" — what the splits do not yet account for. */
                remainder: string;
                clear: string;
                saved: string;
                cleared: string;
                failed: string;
                action: string;
                unclassified: string;
                allStatuses: string;
                classified: string;
            };
            /** Labels for `config/tax_categories.php`. A code with no label renders as the code. */
            categories: Record<string, string | undefined>;
            deleteTitle: string;
            deleteHint: string;
            deleted: string;
            title: string;
            description: string;
            empty: string;
            capped: string;
            allAccounts: string;
            columns: {
                date: string;
                account: string;
                description: string;
                amount: string;
                status: string;
            };
            add: string;
            addHint: string;
            added: string;
            addFailed: string;
            statuses: Record<string, string | undefined>;
            form: {
                account: string;
                amount: string;
                amountHint: string;
                currency: string;
                bookedAt: string;
                valueAt: string;
                valueAtHint: string;
                description: string;
                counterparty: string;
                counterpartyHint: string;
            };
        };
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
        account: {
            title: string;
            description: string;
            stats: {
                signIn: string;
                signInSub: string;
                subjects: string;
                subjectsSub: string;
            };
        };
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
            /** A display preference: how totals are summed, never how a tax is computed. */
            displayCurrencySaved: string;
            displayCurrencyFailed: string;
            title: string;
            description: string;
            retry: string;
            loadFailed: string;
            empty: string;
            tableTitle: string;
            tableDescription: string;
            unit: string;
            edit: string;
            editTitle: string;
            editHint: string;
            unchanged: string;
            save: string;
            saved: string;
            saveFailed: string;
            deactivate: string;
            reactivate: string;
            close: string;
            closeTitle: string;
            closeHint: string;
            statusChanged: string;
            statusFailed: string;
            addNote: string;
            types: Record<string, string | undefined>;
            roles: Record<string, string | undefined>;
            statuses: Record<string, string | undefined>;
            columns: { subject: string; type: string; role: string; status: string; currency: string; ai: string };
            stats: {
                total: string;
                active: string;
                owned: string;
                ownedSub: string;
                ai: string;
                aiSub: string;
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
                cnpHint: string;
                birthDate: string;
                birthDateHint: string;
                country: string;
                countyCode: string;
                countyPlaceholder: string;
                countyNone: string;
                locality: string;
                address: string;
            };
            checkFields: string;
            checkingCnp: string;
            continueLabel: string;
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
            /** The four `Status` tones, named once so every panel labels them identically. */
            states: {
                ok: string;
                warn: string;
                bad: string;
                idle: string;
            };
            freshness: {
                title: string;
                description: string;
                labels: Record<string, string | undefined>;
                details: Record<string, string | undefined>;
                unknown: string;
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
            usersDescription: string;
                        unit: string;
                        loginAs: string;
                        erase: string;
                        erased: string;
                        eraseFailed: string;
                        eraseTitle: string;
                        eraseHint: string;
                        eraseAuditNote: string;
                        loginAsTitle: string;
                        loginAsHint: string;
                        loginAsFailed: string;
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
            newTitle: string;
            newHint: string;
            newSubmit: string;
            newDoneTitle: string;
            newDoneHint: string;
            newDone: string;
            newPassword: string;
            newPasswordHint: string;
            copy: string;
            passwordCopied: string;
            newButton: string;
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
            forbidden: string;
            loadFailed: string;
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
                /** The run history, opened per task — the only place `task_runs` is listed. */
                details: string;
                detailsTitle: string;
                historyDescription: string;
                historyEmpty: string;
                historyFailed: string;
                unit: string;
                startedAt: string;
                trigger: string;
                duration: string;
                exitCode: string;
                output: string;
                noOutput: string;
                statuses: Record<string, string | undefined>;
                triggers: Record<string, string | undefined>;
            };
            cancel: string;
            confirm: string;
        };
        /**
         * The queue, read from Horizon — a different population from `adminOperations`, which is
         * the six maintenance tasks. Neither ever contains the other.
         */
        adminJobs: {
            forbidden: string;
            loadFailed: string;
            unavailable: string;
            queue: {
                pending: string;
                completed: string;
                failed: string;
                failedWindow: string;
                workers: string;
                longestWait: string;
                throughput: string;
                throughputSub: string;
                seconds: string;
            };
            listTitle: string;
            listDescription: string;
            unit: string;
            empty: string;
            /** Horizon's trim windows, stated rather than hidden: 60 minutes, 7 days for failures. */
            retentionNotice: string;
            /** Why a job's payload is never rendered — a design property, not a gap. */
            noPayloadNotice: string;
            /** Says where maintenance-task runs went, so nobody hunts for them in this list. */
            tasksNotice: string;
            statusFilter: Record<string, string | undefined>;
            columns: {
                job: string;
                queue: string;
                status: string;
                when: string;
            };
            statuses: Record<string, string | undefined>;
            details: string;
            detailsTitle: string;
            connection: string;
            queuedAt: string;
            completedAt: string;
            failedAt: string;
            error: string;
            retriedPill: string;
            retry: string;
            discard: string;
            discardTitle: string;
            discardConfirm: string;
            retrySucceeded: string;
            discarded: string;
            actionFailed: string;
            cancel: string;
            confirm: string;
        };
        /** The audit trail, the queue and the task ledger — one screen, three tabs. */
        logs: {
            title: string;
            description: string;
            tabs: {
                audit: string;
                jobs: string;
                tasks: string;
            };
        };
        adminAudit: {
            forbidden: string;
            loadFailed: string;
            empty: string;
            reset: string;
            details: string;
            detailsTitle: string;
            tableTitle: string;
            tableDescription: string;
            unit: string;
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
        };
    };
}
