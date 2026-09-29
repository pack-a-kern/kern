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
        walletImports: string;
        walletReports: string;
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
        taxRules: string;
        adminUsers: string;
        adminOperations: string;
        adminJobs: string;
        adminAudit: string;
    };
    shell: {
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
        notificationsEmpty: string;
        openNotice: string;
        notificationsFailed: string;
        /**
         * One statement import's notice, by `ImportNoticeKind`. `{rows}`, `{imported}`, `{suggested}`
         * and `{reason}` are substituted; `{reason}` in words from `errors.reasons`, else `failedUnknown`.
         */
        importNotices: Record<
            'READY_FOR_MAPPING' | 'READY_FOR_REVIEW' | 'IMPORTED' | 'FAILED',
            { title: string; body: string }
        >;
        importNoticeFailedUnknown: string;
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
        open: string;
        view: string;
        /** The destructive action's own label — never "OK", which says nothing about what happens. */
        delete: string;
        edit: string;
        deleteFailed: string;
        search: string;
        noResults: string;
        actions: string;
        /** "{count} selected" — the bulk-action trigger, shared by every table that has one. */
        selectedCount: string;
        /** The header checkbox's label — select-all acts on this page only. */
        selectPage: string;
        loadMore: string;
        loadingMore: string;
    };
    pagination: {
        nav: string;
        next: string;
        previous: string;
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
        stagedHint: string;
        groupThisFile: string;
        groupedNotice: string;
        uploadCount: string;
        enhance: string;
        enhanced: string;
        enhanceFailed: string;
        stopUpload: string;
        stopUploadTitle: string;
        stopUploadHint: string;
        pageCount: string;
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
        /** Renames every selected document whose current name is not the one its reading proposed. */
        acceptNames: string;
        /** `{count}` is substituted. */
        namesAccepted: string;
        namesFailed: string;
        /** Under a document's name while the name its reading proposed has not been taken. `{name}` is substituted. */
        proposedName: string;
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
        page: string;
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
        /** While the pipeline still has the document — keyed by the in-flight `DocumentStage`s. */
        stageHint: Record<'CHECKING' | 'QUEUED' | 'PROCESSING', string>;
        aiOutcomeTitle: string;
        aiOutcomeAttemptedAt: string;
        aiOutcomeRetry: string;
        aiOutcomeRetrying: string;
        aiOutcomeRetryQueued: string;
        aiOutcomeRetryFailed: string;
        aiOutcomeRetryHint: string;
        /** Keyed by `Kern\Documents\Enums\AiExtractionOutcome`. */
        showAiReply: string;
        askedModel: string;
        rowCount: string;
        noCorrectableFields: string;
        readBy: string;
        tookSeconds: string;
        tokensUsed: string;
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
        /** Where a reader reported no confidence — never drawn as a bar at some default value. */
        confidenceUnreported: string;
        confidenceLabel: string;
        /** `{document}` and `{account}` are substituted. */
        currencyMismatch: string;
        /** The human step that turns a reviewed document into a wallet transaction. */
        createTransaction: string;
        createTransactionHint: string;
        /** The button, offered only once a person has accepted a reading. */
        importTransaction: string;
        importAwaitingAcceptance: string;
        /** `{count}` is substituted. */
        importBulkPending: string;
        createTransactionExisting: string;
        /** A contract becomes a rental instead of a transaction. */
        createRental: string;
        createRentalHint: string;
        rentalExisting: string;
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
        /** Shown only where one scan result covers several of a document's files. */
        scanFilesLabel: string;
        /** Shown where a scanner answered a scan but not a version query. */
        notRecorded: string;
        scanOutcomes: Record<string, string | undefined>;
        chainTitle: string;
        noEvents: string;
        chainEvents: Record<string, string | undefined>;
    };
    /** Keyed by `Kern\Documents\Enums\DocumentStage` — what a person is told. */
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
     * Keyed by the wire names `Kern\Documents\Parsing\ExtractionSchema` defines. A
     * `Record<string, string>` rather than a closed union because the schema is server-side: a new
     * field appears here as a missing translation, not a type error.
     */
    extractionField: Record<string, string>;
    /** Shown wherever a type is expected and nothing has read the document yet. */
    documentTypeUnknown: string;
    documentType: Record<DocumentType, string>;
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
            /** The system's own name for the source every taxpayer has. Never a stored value. */
            generalSource: string;
            unknownBalance: string;
            sources: Record<string, string | undefined>;
            kinds: Record<string, string | undefined>;
            frequencies: Record<string, string | undefined>;
            rateTypes: Record<string, string | undefined>;
            interestMethods: Record<string, string | undefined>;
        };
        walletOverview: {
            /** Everything the subject holds, in one currency. An indication, never a fiscal figure. */
            netWorth: string;
            netWorthRate: string;
            netWorthDirect: string;
            netWorthPartial: string;
            /** General and cash together — what can be spent today. */
            available: string;
            general: string;
            /** Why General can read 0 while deposits were funded from it. */
            generalNote: string;
            deposits: string;
            /** `{count}` is substituted. */
            depositCount: string;
            cash: string;
            other: string;
            /** Forecast, not earned yet: what the running deposits will have produced by maturity. */
            interestAtMaturity: string;
            interestAtMaturityHint: string;
            interestReceived: string;
            interestReceivedHint: string;
            /** Lent and not yet paid back — shown beside what the subject has, never added to it. */
            owedToMe: string;
            owedToMeHint: string;
            recognisedIncome: string;
            recognisedHint: string;
            byBankTitle: string;
            byBankHint: string;
            noBank: string;
            /** `{count}` is substituted in `bankDeposits`. */
            bankDeposits: string;
            bankDeposit: string;
            /** `{date}` is substituted. */
            nextMaturity: string;
            /** `{amount}` is substituted. */
            plusInterest: string;
            viewAccounts: string;
            maturingTitle: string;
            maturingHint: string;
            noDeposits: string;
        };
        walletReports: {
            title: string;
            description: string;
            period: string;
            byTag: string;
            byTagHint: string;
            byBank: string;
            byBankHint: string;
            byCategory: string;
            byCategoryHint: string;
            noBank: string;
            untagged: string;
            moneyIn: string;
            moneyOut: string;
            total: string;
            empty: string;
            loadFailed: string;
        };
        walletImports: {
            title: string;
            description: string;
            start: string;
            /** Removing an import that never became transactions. One that did is undone instead. */
            delete: string;
            deleteSelected: string;
            deleteSelectedTitle: string;
            deletedCount: string;
            table: {
                statement: string;
                kind: string;
                state: string;
                noBank: string;
            };
            losesImports: string;
            losesTransactions: string;
            losesFiles: string;
            deleteTitle: string;
            deleteHint: string;
            deleted: string;
            opening: string;
            working: string;
            historyTitle: string;
            historyHint: string;
            empty: string;
            steps: { file: string; columns: string; review: string; done: string };
            /** The screen shown instead of the review while the model is still suggesting. */
            /** A model call's failure code, in words. Keyed by the code the server stores. */
            aiReasons: Record<string, string | undefined>;
            suggesting: {
                title: string;
                hint: string;
                preparing: string;
                /** `{done}` and `{expected}` are substituted. */
                progress: string;
                progressLabel: string;
                transactions: string;
                counterparties: string;
                answered: string;
                rules: string;
                why: string;
                skip: string;
            };
            file: {
                startCount: string;
                batch: string;
                queued: string;
                waiting: string;
                openOne: string;
                next: string;
                choose: string;
                /** Under the picker once a file is chosen: `{name}` is substituted. */
                chosen: string;
                drop: string;
                hint: string;
                uploading: string;
                reading: string;
            };
            columns: {
                /** Reads the file again and asks for the form again — no re-upload. */
                askAgainFailed: string;
                notApplied: string;
                askAgain: string;
                title: string;
                hint: string;
                /** Reopening the form from the review, which is also how a person sees what matched what. */
                change: string;
                /** Heading for the plain list of where each field was read from. */
                summary: string;
                /** One transaction per line, or a line followed by its own detail lines. */
                layout: string;
                layoutRow: string;
                layoutBlock: string;
                date: string;
                dateFormat: string;
                amountMode: string;
                signed: string;
                debitCredit: string;
                amount: string;
                debit: string;
                credit: string;
                decimal: string;
                thousands: string;
                currency: string;
                currencyHint: string;
                bank: string;
                bankHint: string;
                /** Beside the bank when it was guessed, by where the guess came from. */
                bankFromIban: string;
                bankFromAi: string;
                fixedCurrency: string;
                description: string;
                counterparty: string;
                /** Asked per direction: a statement names the two sides of a payment differently. */
                counterpartyOut: string;
                counterpartyIn: string;
                reference: string;
                referenceOut: string;
                referenceIn: string;
                orHere: string;
                chainHint: string;
                referenceHint: string;
                thenSeparator: string;
                referenceFallbackOut: string;
                referenceFallbackIn: string;
                referenceFallbackHint: string;
                bankRequired: string;
                currencyRequired: string;
                dropLines: string;
                dropLinesHint: string;
                identityLabels: string;
                identityLabelsHint: string;
                removeHint: string;
                /** Why no form was suggested — each one has a different fix. */
                suggestionNoRoom: string;
                suggestionUnavailable: string;
                /** `{reason}` is substituted, in words — see `aiReasons`. */
                suggestionFailed: string;
                sheet: string;
                none: string;
                suggested: string;
                preview: string;
                /** The raw grid, kept as the secondary view behind the rebuilt one. */
                raw: string;
                /** The first transactions, rebuilt by the server from the choices so far. */
                rebuilt: string;
                rebuiltHint: string;
                rebuiltEmpty: string;
                /** `{folded}` and `{skipped}` are substituted. */
                rebuiltCounts: string;
                /** The model's own reply, shown so a wrong form can be checked against it. */
                aiAnswer: string;
                aiAnswerHint: string;
                /** The PDF was converted to CSV by the provider. `{pipeline}` and `{seconds}` are substituted. */
                converted: string;
                /** `{warning}` is the converter's own sentence. */
                convertedWarning: string;
                apply: string;
                /** `{parsed}` and `{total}` are substituted. */
                unusable: string;
            };
            review: {
                /** Asks again only about rows still without a category; edits are kept. */
                askAgain: string;
                title: string;
                hint: string;
                /**
                 * The arithmetic behind the transaction count: 12 522 lines becoming 1 700
                 * transactions is either exactly right or badly wrong, and only the numbers side by
                 * side say which.
                 */
                statLines: string;
                statTransactions: string;
                statFolded: string;
                statSkipped: string;
                statDuplicates: string;
                statAi: string;
                statRules: string;
                fileLine: string;
                answeredOf: string;
                statAnswered: string;
                /** `{done}` and `{expected}` are substituted. */
                aiWaiting: string;
                /** `{rows}` is substituted. */
                aiDone: string;
                /** `{rows}`, `{failed}`, `{calls}`, `{reasons}` and `{missing}` are substituted. */
                aiPartial: string;
                /** `{reason}` is substituted. */
                aiRoundFailed: string;
                aiUnavailable: string;
                aiNothing: string;
                aiCalls: string;
                /** `{number}` is substituted. */
                aiCall: string;
                aiSent: string;
                aiReply: string;
                /** `{reason}` is substituted. */
                aiFailed: string;
                all: string;
                new: string;
                duplicates: string;
                unanswered: string;
                duplicate: string;
                fromRule: string;
                fromAi: string;
                /** `{count}` is substituted. */
                selected: string;
                setCategory: string;
                addTag: string;
                exclude: string;
                include: string;
                /** `{count}` is substituted. */
                commit: string;
                importing: string;
            };
            done: {
                title: string;
                /** `{imported}` and `{duplicates}` are substituted. */
                summary: string;
                viewTransactions: string;
                undo: string;
                undone: string;
                undoTitle: string;
                undoHint: string;
            };
            /** `{count}` is substituted. */
            failedPartial: string;
            failed: string;
            /** Reads the stored file again, for a failure a retry can cure. */
            readAgain: string;
            /** By `StatementImportStatus`. */
            status: Record<string, string | undefined>;
        };
        walletAccounts: {
            title: string;
            description: string;
            edit: string;
            saved: string;
            columns: {
                account: string;
                amount: string;
                interest: string;
                maturity: string;
                status: string;
            };
            add: string;
            unit: string;
            /** The type filter's "everything" choice. */
            allTypes: string;
            /** When the type filter matches no source. */
            noMatches: string;
            emptyTitle: string;
            emptyHint: string;
            deleteSelected: string;
            deleteSelectedHint: string;
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
        };
        walletDeposits: {
            title: string;
            description: string;
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
            /** A deposit that matured and was settled — its money is back in the funding account. */
            closed: string;
            daysLeft: string;
            totalDays: string;
            running: string;
            /** `{date}` is substituted: the day a deposit started. */
            startedOn: string;
            /** `{amount}` is substituted: interest already paid, net. */
            receivedSoFar: string;
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
                /** Which source the deposit's money came out of — the movement that keeps totals honest. */
                fundingAccount: string;
                fundingAccountHint: string;
                fundingAccountNone: string;
                bank: string;
                bankHint: string;
                bankPlaceholder: string;
                bankEmpty: string;
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
                /** Added to the hint when the term ends on a weekend. */
                maturityMoved: string;
                annualRate: string;
                annualRateHint: string;
                rateType: string;
                interestMethod: string;
                paymentFrequency: string;
                /** Under a field fixed because interest computed from it is already on the ledger. */
                lockedAfterInterest: string;
                /** At the top of a closed deposit, whose form is read-only. */
                closedNotice: string;
            };
        };
        walletTransactions: {
            classify: {
                category: string;
                country: string;
                /** The filter that turns the list into a worklist. */
                unclassified: string;
                classified: string;
                allStatuses: string;
                /** The empty option on the form: recording now, answering from the worklist later. */
                later: string;
            };
            /** In the dialog of a transaction a deposit produced — its funding, interest or settlement. */
            systemNotice: string;
            /** Under the disabled account select when editing. */
            accountLocked: string;
            /** Lookalike transactions, gathered for a person to merge or keep — never refused at import. */
            duplicates: {
                /** The option in the status filter that switches the list to groups. */
                filter: string;
                /** `{count}` is substituted. */
                groupTitle: string;
                hint: string;
                merge: string;
                keep: string;
                /** Marks the row a merge would keep. */
                survivor: string;
                hasDocument: string;
                merged: string;
                kept: string;
                empty: string;
                failed: string;
                /** `{count}` is substituted. */
                mergeConfirmTitle: string;
                mergeConfirmHint: string;
            };
            /** Labels for `config/reference/tax_categories.php`. A code with no label renders as the code. */
            /** Narrowing the ledger: bank, tag, direction and a date range. */
            filters: {
                allBanks: string;
                allTags: string;
                allDirections: string;
                directionIn: string;
                directionOut: string;
                from: string;
                to: string;
                reset: string;
            };
            /** A person's own labels — never a tax vocabulary. */
            tags: {
                label: string;
                hint: string;
                placeholder: string;
                manage: string;
                manageHint: string;
                rename: string;
                merge: string;
                mergeConfirm: string;
                empty: string;
                renamed: string;
                merged: string;
                deleted: string;
                exists: string;
                failed: string;
                deleteTitle: string;
                deleteHint: string;
                /** `{count}` is substituted. */
                usedOn: string;
            };
            bank: {
                label: string;
                hint: string;
                none: string;
                /** After a bank that has closed or merged, in a list that includes those. */
                closed: string;
            };
            categories: Record<string, string | undefined>;
            deleteTitle: string;
            deleteHint: string;
            deleted: string;
            title: string;
            description: string;
            allAccounts: string;
            columns: {
                date: string;
                account: string;
                description: string;
                amount: string;
                status: string;
            };
            add: string;
            unit: string;
            deleteSelected: string;
            deleteSelectedHint: string;
            emptyTitle: string;
            emptyHint: string;
            addHint: string;
            added: string;
            saved: string;
            saveFailed: string;
            addFailed: string;
            statuses: Record<string, string | undefined>;
            form: {
                account: string;
                amount: string;
                amountHint: string;
                currency: string;
                bookedAt: string;
                description: string;
                counterparty: string;
                counterpartyHint: string;
            };
        };
        rentals: {
            title: string;
            description: string;
            add: string;
            addHint: string;
            edit: string;
            editHint: string;
            changeRent: string;
            changeRentHint: string;
            end: string;
            endHint: string;
            emptyTitle: string;
            emptyHint: string;
            added: string;
            saved: string;
            deleted: string;
            rentChanged: string;
            ended: string;
            saveFailed: string;
            deleteTitle: string;
            deleteHint: string;
            /** `{year}` is substituted in `owed`. */
            columns: { property: string; tenant: string; rent: string; period: string; owed: string; status: string };
            openEnded: string;
            active: string;
            endedStatus: string;
            /** `{percent}` is substituted. */
            share: string;
            fromContract: string;
            propertyTypes: Record<string, string | undefined>;
            tenantTypes: Record<string, string | undefined>;
            form: {
                propertyType: string;
                address: string;
                ownershipPercent: string;
                ownershipPercentHint: string;
                tenantType: string;
                tenantName: string;
                startDate: string;
                endDate: string;
                endDateHint: string;
                currency: string;
                rent: string;
                rentHint: string;
                paymentFrequency: string;
                termsLocked: string;
                effectiveFrom: string;
            };
        };
        taxes: { title: string; description: string; blockedOn: string };
        taxesExplain: { title: string; description: string; blockedOn: string };
        declarations: { title: string; description: string; blockedOn: string };
        legislation: {
            title: string;
            description: string;
            searchPlaceholder: string;
            search: string;
            /** The search card's own heading, above the field. */
            searchTitle: string;
            searchHint: string;
            tier: string;
            asOf: string;
            asOfHint: string;
            allTiers: string;
            /** Shown before any search has been run. */
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
            /** Opens the law a hit is in, at the date searched. */
            openInLaw: string;
            laws: {
                title: string;
                hint: string;
                /** Opens the dialog that finds an act on the portal. */
                add: string;
                /** No law read yet — says where to read one. */
                empty: string;
                failed: string;
                remove: string;
                removeTitle: string;
                removeDetail: string;
                removed: string;
                removeRefused: string;
                open: string;
                /** `{number}` is the Monitorul Oficial issue. */
                journal: string;
                actDate: string;
                publishedOn: string;
                inForceFrom: string;
                /** `{read}` of `{listed}`. */
                consolidations: string;
                /** `{count}` that could not be read. */
                consolidationsFailed: string;
                /** `{count}` laws read. */
                count: string;
                /** The consolidation in force now: the latest read. */
                currentVersion: string;
            };
            /** Finding an act on legislatie.just.ro and reading it. */
            add: {
                title: string;
                hint: string;
                by: string;
                byTitle: string;
                byNumber: string;
                words: string;
                wordsPlaceholder: string;
                number: string;
                year: string;
                chooseYear: string;
                anyYear: string;
                search: string;
                close: string;
                none: string;
                /** `{date}` it came into force. */
                inForce: string;
                type: string;
                allTypes: string;
                /** Per act type as the portal files it — `HOTARARE`; one missing shows the key. */
                types: Record<string, string | undefined>;
                import: string;
                /** Asked beside the act, before anything starts. */
                confirm: string;
                confirmImport: string;
                cancel: string;
                imported: string;
                importing: string;
                /** `{type}` as the portal writes it. */
                unsupported: string;
                started: string;
                busy: string;
                portalDown: string;
            };
            law: {
                /** A history stretch whose start is the portal note's day. */
                datedByNote: string;
                reader: string;
                /** `{period}` the consolidation shown applies in. */
                consolidatedOn: string;
                /** After a date: the consolidation still in force. */
                current: string;
                copyLink: string;
                linkCopied: string;
                copyArticleLink: string;
                versions: string;
                version: string;
                find: string;
                findPlaceholder: string;
                /** `{shown}` of `{total}` articles in the part shown. */
                findCount: string;
                findNone: string;
                contentsFilter: string;
                inThisPart: string;
                thisVersion: string;
                allVersions: string;
                source: string;
                sourceHint: string;
                openSource: string;
                /** The consolidation already on screen, in the versions panel. */
                shown: string;
                loadFailed: string;
                /** `{date}` that no consolidation covers. */
                noVersion: string;
                previousPart: string;
                nextPart: string;
                amended: string;
                /** `{count}` notes on a provision. */
                notes: string;
                notesHint: string;
                noteNoDate: string;
                /** `{type}`, `{number}`, `{date}`, `{journal}`, `{journalDate}`. */
                noteAct: string;
                /** Per kind of sentence: `{from}`, `{until}`, `{year}` as the kind gives them. */
                noteApplies: {
                    income_month_after_publication: string;
                    income_month: string;
                    income_year: string;
                    fiscal_year: string;
                    modified_fiscal_year: string;
                    quarter: string;
                    in_force: string;
                    in_force_on_publication: string;
                    from_date: string;
                    from_date_until: string;
                    until_date: string;
                    on_date: string;
                };
                history: string;
                historyTitle: string;
                historyHint: string;
                historyEmpty: string;
                historyFailed: string;
                /** `{from}`, and `{until}` when it has an end. */
                heldOpen: string;
                heldClosed: string;
                coverageGap: string;
                changes: string;
                firstVersion: string;
                close: string;
                consolidationsTitle: string;
                consolidationsHint: string;
                notRead: string;
                failed: string;
                /** `{count}` provisions. */
                sections: string;
                viewOn: string;
                onPortal: string;
                contentsTitle: string;
                /** `{first}` and `{last}`: the articles a heading spans. */
                contentsRange: string;
                contentsEmpty: string;
            };
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
        verifyEmail: {
                /** The page title and the sentence under it. */
            title: string;
            subtitle: string;
                    /**
             * Where a code will go, with the address substituted for `:destination`.
             *
             * Future tense on purpose: arriving at the screen sends nothing — a page that sent on
             * mount would send again on every refresh — so wording that claimed a code was already on
             * its way sent people looking for one that did not exist.
             */
            sentTo: string;
            codeLabel: string;
            codePlaceholder: string;
            submit: string;
            resend: string;
            resent: string;
                /** Shown when the setting is on, so the person understands why they cannot go on. */
            requiredNotice: string;
                /** Shown when it is off — verifying is offered, not demanded. */
            optionalNotice: string;
                /** No address on file, so nothing can be sent and there is no button to press. */
            undeliverable: string;
            verifiedTitle: string;
            verifiedBody: string;
            continueToApp: string;
            signOut: string;
            rateLimited: string;
            genericError: string;
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
                 * Keyed by the `reason` codes `Kern\Platform\Settings\Services\FetchAiProviderModels`
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
                    priority: string;
                    state: string;
                };
                allowed: string;
                blocked: string;
                inUse: string;
                neverFetched: string;
            };
        };
        taxRules: {
            title: string;
            description: string;
            /** Per parameter code — `RO.CASS.RATE`; one missing shows the code. */
            names: Record<string, string | undefined>;
            /** `{count}` minimum wages, "6 / 12 / 24". */
            wages: string;
            /** A base between `{floor}` and `{cap}` minimum wages. */
            wagesRange: string;
            /** `{from}`, and `{until}` when it has an end. */
            periodOpen: string;
            periodClosed: string;
            statuses: { CANDIDATE: string; APPROVED: string; REJECTED: string };
            /** Per check kind, its detail filled in by name — `{form_wage}`, `{year}`. */
            checks: Record<string, string | undefined>;
            /** Why a year holds no value, by `ParameterRead.outcome`; `{date}` it was read. */
            reads: Record<string, string | undefined>;
            /** The mark on a year no model could be asked for. */
            unread: string;
            overviewTitle: string;
            overviewHint: string;
            wageInForce: string;
            /** The right end of the wage's history: today. */
            wageHistory: string;
            rulesRead: string;
            yearsRead: string;
            waiting: string;
            /** The name of each tax, by its code, for the table it heads. */
            taxes: Record<string, string | undefined>;
            /** `{count}` rules a tax has. */
            rulesCount: string;
            /** What a dot, a dash and an empty cell mean. */
            legend: string;
            valuesTitle: string;
            valuesHint: string;
            parameter: string;
            /** Beside a value that has a warning to read. */
            look: string;
            empty: string;
            forbidden: string;
            failed: string;
            newYear: string;
            newYearHint: string;
            year: string;
            propose: string;
            /** The `{year}` whose law is being read. */
            yearQueued: string;
            readFrom: string;
            /** Which `{model}` read the paragraph. */
            readBy: string;
            inLaw: string;
            inLawHint: string;
            openLaw: string;
            approveAll: string;
            approveAllHint: string;
            approveAllAction: string;
            approveAllNoParagraph: string;
            approveAllProgress: string;
            period: string;
            approve: string;
            reject: string;
            close: string;
            approved: string;
            rejected: string;
        };
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
                argumentDateHint: string;
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
