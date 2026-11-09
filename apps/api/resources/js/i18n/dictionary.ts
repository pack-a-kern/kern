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
        wallet: string;
        walletAccounts: string;
        walletTransactions: string;
        documents: string;
        walletCounterparties: string;
        walletImports: string;
        walletReports: string;
        rentals: string;
        analysis: string;
        taxes: string;
        legislation: string;
        spv: string;
        accountGroup: string;
        settingsSubjects: string;
        adminGroup: string;
        adminSettings: string;
        taxRules: string;
        adminUsers: string;
        adminOperations: string;
        adminJobs: string;
        adminAudit: string;
        /** The bands the sidebar groups its destinations into. */
        sections: { records: string; law: string; connections: string; system: string };
    };
    shell: {
        appTagline: string;
        collapseSidebar: string;
        expandSidebar: string;
        changeTheme: string;
        impersonating: string;
        offline: string;
        offlineHint: string;
        backOnline: string;
        loading: string;
        refreshing: string;
        systemDownOne: string;
        systemDownMany: string;
        viewSystemStatus: string;
        impersonationStop: string;
        impersonationStopFailed: string;
        notifications: string;
        markAllRead: string;
        notificationsEmpty: string;
        notificationsEmptyHint: string;
        openNotice: string;
        notificationsFailed: string;
        /** Keyed by `resources/js/lib/palette.ts`'s registry — add a palette, `tsc` asks for these. */
        paletteNames: Record<PaletteName, string>;
        paletteDescriptions: Record<PaletteName, string>;
        languageRo: string;
        languageEn: string;
        account: string;
        signOut: string;
        noActiveSubject: string;
        manageSubjects: string;
        taxSubject: string;
        fiscalSnapshot: string;
        /** The declaration for `{year}`'s income. */
        declarationFor: string;
        /** `{date}` it is due. */
        dueOn: string;
        /** `{days}` until it is due. */
        daysLeft: string;
        dueToday: string;
        searchSubjects: string;
        chooseSubject: string;
        chooseSubjectHint: string;
        noSubjectsFound: string;
    };
    common: {
        /** Where a file is dropped or chosen (`components/FileDrop`). `{formats}`: the extensions taken. */
        fileDrop: { or: string; formats: string; formatsMany: string };
        loading: string;
        save: string;
        /** Dismisses a dialog that has nothing to submit — a viewer, not a form. */
        cancel: string;
        close: string;
        open: string;
        /** The destructive action's own label — never "OK", which says nothing about what happens. */
        delete: string;
        edit: string;
        deleteFailed: string;
        search: string;
        noResults: string;
        actions: string;
        /** "{count} selected" — the bulk-action trigger, shared by every table that has one. */
        /** The one wording for the one action, on every screen that offers it. */
        deleteSelected: string;
        selectedCount: string;
        /** The header checkbox's label — select-all acts on this page only. */
        selectPage: string;
    };
    /**
     * A cron expression said in words. `{minutes}`, `{hours}`, `{minute}`, `{time}`, `{day}` are
     * substituted; `days` is the seven day names, Sunday first, separated by `|` — cron counts from
     * Sunday, so the order is cron's rather than the week's as it is read locally.
     */
    cron: {
        everyMinutes: string;
        everyHours: string;
        daily: string;
        weekdays: string;
        weekends: string;
        weekly: string;
        monthly: string;
        days: string;
    };
    pagination: {
        /** `{page}` and `{total}` are substituted. Shown where numbers do not fit. */
        pageOf: string;
        nav: string;
        next: string;
        previous: string;
        morePages: string;
        /** `{first}`, `{last}`, `{total}` and `{unit}` are substituted. */
        range: string;
        rows: string;
    };
    home: {
        signIn: string;
        eyebrow: string;
        title: string;
        lead: string;
        /** Marks the cards beside the headline as made up, so no figure in them reads as anyone's. */
        example: string;
        previewDeadline: string;
        previewDeadlineDue: string;
        previewDaysLeft: string;
        previewRate: string;
        previewRateSource: string;
        previewDeposit: string;
        previewDepositInterest: string;
        featuresTitle: string;
        featuresLead: string;
        features: Record<
            'wallet' | 'statements' | 'spv' | 'taxes' | 'law' | 'approval',
            { title: string; body: string }
        >;
        securityTitle: string;
    };
    auth: {
        signInTitle: string;
        registerTitle: string;
        /** The same answer for every address, so the form never says whether one has an account. */
        registrationAnswered: string;
        mfaCodeLabel: string;
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
        passkeyCancelled: string;
        passkeyNotRecognisedTitle: string;
        passkeyNotRecognised: string;
        rateLimited: string;
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
        queueEmptyHint: string;
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
    /**
     * A derivative kind's name, keyed by `DocumentObjectKind`'s value.
     *
     * Named here rather than shown as stored: `converted_csv` is a storage word, and the eight keys
     * are a closed set, so every one has a name whether or not anything writes it yet.
     */
    derivativeKind: Record<string, string>;
    documentsReview: {
        /** The panel listing what was made from a document, and the control on each row. */
        derivatives: string;
        derivativeOpen: string;
        /** Shown in place of a `proposed_fields` value that cannot be serialised for display. */
        unrenderableValue: string;
        title: string;
        description: string;
        receivedLabel: string;
        formatLabel: string;
        documentIdLabel: string;
        typeLabel: string;
        statusLabel: string;
        /** Alt text for the image preview — copy, so it belongs here rather than in the markup. */
        previewAlt: string;
        page: string;
        previewPdfTitle: string;
        back: string;
        source: string;
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
    /**
     * A picture and the two controls that change it, for anything that can wear one — an account, a
     * taxpayer. At the root rather than under a screen because two screens share the component that
     * reads these, and none of the words was ever about users.
     */
    avatar: {
        choose: string;
        replace: string;
        remove: string;
        /** With the noun, for a menu where "Remove" alone would be ambiguous. */
        removePicture: string;
        uploading: string;
        /** Keyed by the `reason` a 422 carries — see `UploadRejectedException`. */
        reasons: Record<
            | 'infected'
            | 'scanner_unavailable'
            | 'too_large'
            | 'not_an_image'
            | 'dimensions_too_large'
            | 'undecodable'
            | 'unencodable',
            string
        >;
    };
    subjectType: {
        PF: string;
        PFA: string;
        II: string;
        IF: string;
        SRL: string;
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
    /** The dialog that asks for an authenticator code before a sensitive act. */
    stepUp: {
        title: string;
        hint: string;
        /** Shown instead of `hint` when the account has no authenticator app and confirms by password. */
        passwordHint: string;
        /** Shown under the field when the code or password did not match. */
        incorrect: string;
        code: string;
        password: string;
        confirm: string;
        cancel: string;
    };
    /**
     * What a rule's `explanationKey` renders as.
     *
     * Top level rather than under a page, because the keys belong to the rules: a figure and its reasoning
     * travel together wherever they are shown, and `/taxes/explain` will want the same strings.
     */
    taxExplanations: Record<string, string | undefined>;
    pages: {
        wallet: {
            /** Shared by all three wallet screens. */
            noSubject: string;
            loadFailed: string;
            /** The system's own name for the source every taxpayer has. Never a stored value. */
            generalSource: string;
            unknownBalance: string;
            kinds: Record<string, string | undefined>;
            frequencies: Record<string, string | undefined>;
            rateTypes: Record<string, string | undefined>;
            interestMethods: Record<string, string | undefined>;
        };
        walletOverview: {
            /** Under the total when an account's records start after money was already in it; `{date}`. */
            recordsShortFrom: string;
            /** Everything the subject holds, in one currency. An indication, never a fiscal figure. */
            netWorth: string;
            netWorthRate: string;
            netWorthDirect: string;
            /** The hero's left half: what the figure covers, and whether it is the whole of it. */
            netWorthScope: string;
            netWorthRecorded: string;
            netWorthIncomplete: string;
            /** Its right half: the allocation, and the caption above the list. */
            whereItSits: string;
            allocationOf: string;
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
            /** The count in each card's head, and the two columns a maturity is read as. */
            activeCount: string;
            upcomingCount: string;
            principalLabel: string;
            grossInterestLabel: string;
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
            /** What would be here, and — for maturities — that empty is good news rather than missing data. */
            /** The four things somebody opens the dashboard to start. */
            quickTitle: string;
            quickHint: string;
            quickUpload: string;
            quickTransaction: string;
            quickAssess: string;
            quickSpv: string;
            /** The published BNR line: its controls, its footer reading, and what it says with no data. */
            fxTitle: string;
            fxHint: string;
            fxWindow: string;
            fxLatest: string;
            fxEmpty: string;
            fxEmptyHint: string;
            noDepositsHint: string;
            nothingMaturing: string;
            nothingMaturingHint: string;
            noDeposits: string;
        };
        walletCounterparties: {
            manageTags: string;
            editHint: string;
            forget: string;
            forgetTitle: string;
            /** Every payee, from the card's head rather than a selection. */
            forgetAll: string;
            forgetAllTitle: string;
            forgetHint: string;
            forgotten: string;
            willReplace: string;
            title: string;
            description: string;
            search: string;
            party: string;
            rows: string;
            moneyIn: string;
            moneyOut: string;
            undecided: string;
            merge: string;
            mergeTitle: string;
            mergeHint: string;
            survivingName: string;
            survivingHelp: string;
            merged: string;
            category: string;
            tags: string;
            ruleSaved: string;
            saveFailed: string;
            empty: string;
            emptyHint: string;
            loadFailed: string;
            unit: string;
        };
        walletReports: {
            date: string;
            amount: string;
            tabMonths: string;
            tabLargest: string;
            colTxs: string;
            colTop: string;
            exportReport: string;
            exportMonths: string;
            exportBreakdown: string;
            exportMovement: string;
            exportLargest: string;
            versusPrevious: string;
            retained: string;
            needAttention: string;
            transactions: string;
            flowBoth: string;
            flowNet: string;
            spend: string;
            currencies: string;
            mainCurrency: string;
            ofActivity: string;
            dataQuality: string;
            cleanCount: string;
            reviewCount: string;
            clean: string;
            yearByMonth: string;
            yearByMonthHint: string;
            month: string;
            monthlySpend: string;
            breakdown: string;
            breakdownHint: string;
            tabCategory: string;
            tabTag: string;
            tabMerchant: string;
            totalExpenses: string;
            movement: string;
            movementHint: string;
            tabBank: string;
            tabCurrency: string;
            tabAccount: string;
            source: string;
            currency: string;
            completeness: string;
            duplicatesNote: string;
            cashFlow: string;
            cashFlowHint: string;
            composition: string;
            compositionHint: string;
            attention: string;
            attentionHint: string;
            needsTreatment: string;
            possibleDuplicates: string;
            allClear: string;
            largest: string;
            largestHint: string;
            net: string;
            topCategory: string;
            lastTwelve: string;
            noCounterparty: string;
            title: string;
            description: string;
            noBank: string;
            untagged: string;
            moneyIn: string;
            moneyOut: string;
            empty: string;
            loadFailed: string;
        };
        walletImports: {
            title: string;
            description: string;
            start: string;
            /** Removing an import that never became transactions. One that did is undone instead. */
            delete: string;
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
            /** What the pager counts, in lower case — see every other listing. */
            unit: string;
            historyTitle: string;
            historyHint: string;
            empty: string;
            steps: { progress: string; file: string; columns: string; review: string; done: string };
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
                drop: string;
                tipsTitle: string;
                tips: Record<'export' | 'asIs' | 'review', { title: string; body: string }>;
                chosenTitle: string;
                oneFile: string;
                files: string;
                none: string;
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
                /** The three headings the read-from summary groups its fields under. */
                groupTransaction: string;
                groupCounterparty: string;
                groupText: string;
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
                currency: string;
                currencyHint: string;
                bank: string;
                bankHint: string;
                /** Beside the bank when it was guessed, by where the guess came from. */
                bankFromIban: string;
                bankFromAi: string;
                description: string;
                counterparty: string;
                /** Asked per direction: a statement names the two sides of a payment differently. */
                counterpartyOut: string;
                counterpartyIn: string;
                reference: string;
                referenceOut: string;
                referenceIn: string;
                chainHint: string;
                /** The picker at the end of a chip row, and what a chip's remove button is called. */
                addPlace: string;
                /** Shown once the ceiling is reached, with `:max` replaced by the number. */
                placesFull: string;
                removePlace: string;
                /** Shown in a chip row with nothing in it yet. */
                noPlace: string;
                /** Why description takes several places, and that they are joined rather than tried in turn. */
                descriptionHint: string;
                referenceHint: string;
                thenSeparator: string;
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
                none: string;
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
            };
            review: {
                /** Asks again only about rows still without a category; edits are kept. */
                askAgain: string;
                title: string;
                hint: string;
                statTransactions: string;
                statDuplicates: string;
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
                /** Said where a round would be reported, when classification is switched off. */
                aiOff: string;
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
                /** The two figures on the finished panel, and the line under them. */
                imported: string;
                skipped: string;
                skippedHint: string;
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
            columns: {
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
            /** Adding many deposits at once, from a CSV, inside the add-deposit dialog. */
            csv: {
                modeOne: string;
                modeCsv: string;
                drop: string;
                choose: string;
                fileHint: string;
                separator: string;
                separatorComma: string;
                separatorSemicolon: string;
                columnsTitle: string;
                columnsHint: string;
                /** A column left unnamed: the field below applies to every deposit. */
                sameForAll: string;
                termMonths: string;
                line: string;
                ready: string;
                /** `{cell}` is substituted: the file's own text that could not be read. */
                unreadable: string;
                missing: string;
                reading: string;
                readFailed: string;
                pickColumns: string;
                /** `{count}` is substituted. */
                submit: string;
                /** `{count}` is substituted. */
                added: string;
                refused: string;
            };
            form: {
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
                /** The length a deposit is bought as; the maturity date is derived from it. */
                term: string;
                termMonths: string;
                termOneMonth: string;
                termSearch: string;
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
            /** The bulk menu's payee and category changes; `{count}` the rows, `{done}` those changed. */
            bulk: {
                changePayee: string;
                changeCategory: string;
                payeeTitle: string;
                categoryTitle: string;
                payeeHint: string;
                categoryHint: string;
                payeeEmpty: string;
                apply: string;
                applying: string;
                done: string;
                stopped: string;
            };
            classify: {
                category: string;
                country: string;
                /** The filter that turns the list into a worklist. */
                unclassified: string;
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
                /** The second view, beside `toolbar.ledger`. It was an option in the status filter,
                 *  which hid that it replaces the table and ignores every filter. */
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
                labelDates: string;
                remove: string;
                allBanks: string;
                allTags: string;
                /** What each control in the filter card is labelled. */
                labelAccount: string;
                labelBank: string;
                labelTag: string;
                labelDirection: string;
                labelStatus: string;
                counterparty: string;
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
                mergeConfirm: string;
                /** `{count}` tags are being folded into one. */
                mergeManyHint: string;
                keepName: string;
                empty: string;
                renamed: string;
                merged: string;
                deleted: string;
                failed: string;
                deleteTitle: string;
                deleteHint: string;
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
                /** The account, with the bank beneath it: both answer "where". */
                account: string;
                description: string;
                /** Who the money went to or came from — its own column since the description cell
                 *  held four stacked things and no header said which was which. */
                counterparty: string;
                amount: string;
                status: string;
            };
            /** The two rows of the toolbar, each named by what its controls do. */
            toolbar: {
                filter: string;
                /** The first view: the ledger itself, beside `duplicates.filter`. */
                ledger: string;
            };
            add: string;
            unit: string;
            deleteSelectedHint: string;
            emptyTitle: string;
            emptyHint: string;
            addHint: string;
            added: string;
            saved: string;
            saveFailed: string;
            addFailed: string;
            form: {
                account: string;
                amount: string;
                amountHint: string;
                currency: string;
                bookedAt: string;
                description: string;
                counterparty: string;
                /** Announced to a screen reader when matches appear. `{count}` is substituted. */
                counterpartyMatches: string;
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
            /** The row's own verbs — short, because the dialog each one opens says the rest. */
            actions: { rent: string; end: string };
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
        taxes: {
            thresholdReached: string;
            /** The one card holding every tax, and the count in its head. */
            /** The two files a year can be downloaded as. */
            /** The icon-only trigger's label, since its name is a picture. */
            actionsMenu: string;
            exportFigures: string;
            exportEvidence: string;
            /** The one act the assessment's state allows, and why there is none when there is none. */
            markFinal: string;
            unlock: string;
            finalHint: string;
            yearStillOpen: string;
            breakdownTitle: string;
            breakdownHint: string;
            entryCount: string;
            quotaDeducted: string;
            title: string;
            description: string;
            assess: string;
            computedAt: string;
            loadingSaved: string;
            /** The screen with nothing on it yet: what is absent, and what pressing the button will give. */
            emptyTitle: string;
            emptyHint: string;
            draftHint: string;
            /** How many rule outputs the year produced, and the heading over the ring. */
            rulesApplied: string;
            composition: string;
            totalOwedLabel: string;
            incomeAssessed: string;
            assessmentStatus: string;
            statusDraft: string;
            statusFinal: string;
            /** A component's own income and base, inside a tax group — "Your income", "Taxed on". */
            componentIncome: string;
            componentBase: string;
            nothingOwed: string;
            /** A tax's name in the reader's language; the code is the fallback, never the label. */
            taxCasTitle: string;
            taxCassTitle: string;
            taxIncomeTaxTitle: string;
            /** What a figure here does not yet account for, each of which changes what is owed. */
            beforeFinalising: string;
            /** A check's own heading; the caveat below it is the reason it matters. */
            checkExpensesTitle: string;
            checkCategoriesTitle: string;
            checkFilingTitle: string;
            caveatExpenses: string;
            caveatCategorisation: string;
            caveatNotFiling: string;
        };
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
            /** Opens the law a hit is in, at the date searched. */
            openInLaw: string;
            laws: {
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
                /** Something else in the app was read from the law as a whole: a bond from the order that issued it. */
                removeReadFrom: string;
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
                /** The consolidation in force now: the latest read. */
                currentVersion: string;
            };
            /** Searches of the portal kept and run again, each new act read as a law. */
            watches: {
                title: string;
                hint: string;
                empty: string;
                /** `{package}` is the package that declared it. */
                declaredBy: string;
                yours: string;
                someone: string;
                /** `{type}` the act type, `{year}` the first year searched. */
                scope: string;
                everyDay: string;
                /** `{days}` */
                everyDays: string;
                /** `{hours}` */
                everyHours: string;
                notRun: string;
                /** `{date}`, `{result}` */
                lastRun: string;
                active: string;
                interval: string;
                remove: string;
                removeConfirm: string;
                removed: string;
                failed: string;
                /** In "Add a law": keeping the current search. */
                watch: string;
                watchHint: string;
                watchNeedsType: string;
                watched: string;
                cancel: string;
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
                /** The site's search page answered with its error page: nothing matched, or it failed. */
                searchRefused: string;
                browserBusy: string;
                browserUnavailable: string;
                /** `{pages}` read so far, `{count}` acts found so far. */
                searching: string;
                stop: string;
                stopped: string;
                /** `{page}` the page that would not answer, `{reason}` why. */
                incomplete: string;
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
        spv: {
            revoke: string;
            delete: string;
            deleteTitle: string;
            deleteHint: string;
            deleteRuns: string;
            signedInUntil: string;
            revokeTitle: string;
            revokeHint: string;
            statusColumn: string;
            title: string;
            description: string;
            connections: string;
            connectionsHint: string;
            connect: string;
            connectTitle: string;
            connectHint: string;
            method: string;
            /** Keyed by `SpvAuthMethod`. */
            methods: Record<string, string>;
            username: string;
            noConnections: string;
            /** Keyed by `SpvConnectionStatus`. */
            statuses: Record<string, string>;
            lastSuccess: string;
            never: string;
            sync: string;
            signIn: string;
            signInTitle: string;
            credentialsStep: string;
            password: string;
            challengeStep: string;
            code: string;
            submit: string;
            cancel: string;
            /** The portal answered, and what it said was no — not a transport failure. */
            signInFailed: string;
            signedIn: string;
            /** `{count}` is substituted. */
            syncDone: string;
            syncFailed: string;
            certificateHint: string;
        };
        account: {
            title: string;
            description: string;
            notifications: {
                title: string;
                description: string;
                thisDevice: string;
                on: string;
                off: string;
                blocked: string;
                turnOn: string;
                turnOff: string;
                onHint: string;
                offHint: string;
                blockedHint: string;
                iosTitle: string;
                iosHint: string;
                unsupported: string;
                notConfigured: string;
                kinds: string;
                devices: string;
                lastUsed: string;
                never: string;
                remove: string;
                removed: string;
                sendTest: string;
                testSent: string;
                turnedOn: string;
                turnedOff: string;
                failed: string;
                loadFailed: string;
            };
            appearance: {
                title: string;
                description: string;
                theme: string;
                themeHint: string;
                light: string;
                dark: string;
                system: string;
                palette: string;
                paletteHint: string;
                language: string;
                languageHint: string;
            };
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
            /** `{count}` is substituted — the other sessions a password change signed out. */
            otherSessionsEnded: string;
            passwordFailed: string;
            passkeyOnly: string;
        };
        settingsSecurity: {
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
            /** `{browser}` and `{platform}` are substituted. The server sends the two parts; this joins them. */
            deviceOn: string;
            revoke: string;
            revokeOthers: string;
            sessionRevoked: string;
            othersRevoked: string;
            revokeFailed: string;
            columns: { ip: string };
            stats: {
                mfa: string;
                mfaSub: string;
                on: string;
                off: string;
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
            managePasskeys: string;
            passkeysDialogTitle: string;
            passkeysDialogHint: string;
            passkeyUnnamed: string;
            passkeySynced: string;
            passkeyAddedOn: string;
            passkeyLastUsed: string;
            passkeyNeverUsed: string;
            removePasskey: string;
            removePasskeyConfirm: string;
            passkeyRemoved: string;
            passkeyRemoveFailed: string;
            passkeysLoadFailed: string;
            close: string;
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
            types: Record<string, string | undefined>;
            roles: Record<string, string | undefined>;
            statuses: Record<string, string | undefined>;
            columns: { subject: string; type: string; role: string; status: string; currency: string; ai: string };
            stats: {
                total: string;
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
            source: { database: string; inherited: string };
            save: string;
            saving: string;
            saved: string;
            reset: string;
            unsaved: string;
            testConnection: string;
            testing: string;
            testOk: string;
            testFailed: string;
            secretUnset: string;
            secretReplace: string;
            currencyAdd: string;
            currencyRemove: string;
            currencyNone: string;
            forbidden: string;
            loadFailed: string;
            retry: string;
            saveFailed: string;
            ai: {
                choose: string;
                docs: string;
                loadModels: string;
                loadingModels: string;
                modelsLoaded: string;
                modelsFailed: string;
                keyOptional: string;
                /** Under the key in the connection dialog, for a provider that needs one. */
                keyHint: string;
                testHint: string;
                /** The toast description for a failed test: the reason is in the log, not here. */
                failedHint: string;
                replaceKey: string;
                /** The button on the address field that opens the connection dialog. */
                changeConnection: string;
                connectionTitle: string;
                connectionHint: string;
                connectionSaved: string;
                /**
                 * Keyed by the `reason` codes `ModelListResult` returns — only the ones about this
                 * installation; everything that depends on what answered is `failed`. Indexed so an
                 * unrecognised code falls back rather than crashing the page.
                 */
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
                browser: string;
                legislation_portal: string;
                fx: string;
                spv: string;
                security: string;
                banks: string;
            };
            legend: { title: string; database: string; inherited: string; locked: string };
            lockedBadge: string;
            /** The Official sources card's folded section of addresses, which are almost never changed. */
            sourceAddresses: { title: string; hint: string };
            settingLabels: Record<string, string>;
            /** A `choice` setting's values, by setting key then stored value. */
            settingChoices: Record<string, Record<string, string> | undefined>;
            /** The Banks card: where logos are read from, and which banks still have none. */
            banks: {
                /** `{path}` is the logo folder. */
                naming: string;
                noFolder: string;
                refresh: string;
                refreshed: string;
                /** `{count}` is how many banks have no logo. */
                viewMissing: string;
                missingTitle: string;
                /** `{path}` is the logo folder. */
                missingHint: string;
                close: string;
                noFile: string;
                loadFailed: string;
            };
            groupHints: {
                ai: string;
                documents: string;
                sources: string;
                browser: string;
                legislation_portal: string;
                fx: string;
                spv: string;
                security: string;
                banks: string;
            };
            scheduler: {
                title: string;
                alive: string;
                stalled: string;
                never: string;
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
            /** What the law says holds, or does not — an exemption. */
            yes: string;
            no: string;
            /** A period of `{count}` days. */
            days: string;
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
            parameter: string;
            /** Beside a value that has a warning to read. */
            look: string;
            empty: string;
            forbidden: string;
            failed: string;
            newYear: string;
            newYearHint: string;
            /** Every value read, decided or not, dropped for reading again. */
            dropAll: string;
            dropAllTitle: string;
            dropAllHint: string;
            dropped: string;
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
            forbidden: string;
            loadFailed: string;
            retry: string;
            reset: string;
            /** Why the list is empty when the accounts are not, and the way back. */
            emptyHint: string;
            clearFilters: string;
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
            edit: string;
            editTitle: string;
            editNotice: string;
            cancel: string;
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
            };
        };
        adminOperations: {
            /** The screen's own heading and the line under it. */
            title: string;
            description: string;
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
        };
        /**
         * The queue, read from Horizon — a different population from `adminOperations`, which is
         * the six maintenance tasks. Neither ever contains the other.
         */
        adminJobs: {
            /** The screen's own heading and the line under it. */
            title: string;
            description: string;
            forbidden: string;
            loadFailed: string;
            /** What an empty list means here, and the difference between empty and unanswered. */
            emptyHint: string;
            unavailableTitle: string;
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
        adminAudit: {
            /** The screen's own heading and the line under it. */
            title: string;
            description: string;
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
            /** Shown to a reader whose role reaches the account and system lines only. */
            taxpayerLinesHidden: string;
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
