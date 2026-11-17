import { FileText, Upload, X } from 'lucide-react';
import { useState, type JSX } from 'react';

import { useTranslation } from '../i18n';
import { formatBytes } from '../lib/format';
import { HelpMark } from './panel';

/** The files among those given whose name ends in one of the accepted extensions. */
function accepted(files: FileList | null, accept: string[]): File[] {
    return [...(files ?? [])].filter((file) =>
        accept.some((extension) => file.name.toLocaleLowerCase().endsWith(extension)),
    );
}

/**
 * Where a file is dropped or chosen: a dashed area with the one button it answers to, and the
 * formats it takes underneath — the statement import and the deposits CSV ask for a file the same way.
 *
 * The label is the control, wrapping the real picker, so a click anywhere on it opens the picker and a
 * keyboard reaches it as the picker it is. A file of another kind is left out rather than refused
 * later: the formats line already said what is taken.
 */
export function FileDrop(props: {
    id: string;
    /** Extensions with their dot — `.csv` — as the picker's `accept` and the formats line both read them. */
    accept: string[];
    multiple?: boolean;
    title: string;
    choose: string;
    /** What the file should be, behind a mark beside the title — see `HelpMark`. */
    help?: string;
    /** A shorter area, for a drop that shares a dialog with other fields rather than owning a page. */
    compact?: boolean;
    onFiles: (files: File[]) => void;
}): JSX.Element {
    const { t } = useTranslation();
    const [dragging, setDragging] = useState(false);
    const formats = props.accept.map((extension) => extension.slice(1).toUpperCase()).join(', ');

    return (
        <label
            htmlFor={props.id}
            className={props.compact === true ? 'file-drop file-drop--compact' : 'file-drop'}
            data-dragging={dragging ? '' : undefined}
            onDragOver={(event) => {
                event.preventDefault();
                setDragging(true);
            }}
            onDragLeave={() => {
                setDragging(false);
            }}
            onDrop={(event) => {
                event.preventDefault();
                setDragging(false);
                props.onFiles(accepted(event.dataTransfer.files, props.accept));
            }}
        >
            <span className="file-drop-mark" aria-hidden="true">
                <Upload className="size-6" />
            </span>
            <span className="flex items-center gap-1.5">
                <strong className="file-drop-title">{props.title}</strong>
                {props.help === undefined ? null : <HelpMark text={props.help} />}
            </span>
            <span className="note">{t((d) => d.common.fileDrop.or)}</span>
            {/* The label is the control: this only looks like the button it answers to. */}
            <span className="file-drop-button">{props.choose}</span>
            <span className="file-drop-formats">
                {(props.multiple === true
                    ? t((d) => d.common.fileDrop.formatsMany)
                    : t((d) => d.common.fileDrop.formats)
                ).replace('{formats}', formats)}
            </span>
            <input
                id={props.id}
                type="file"
                multiple={props.multiple === true}
                className="sr-only"
                accept={props.accept.join(',')}
                onChange={(event) => {
                    props.onFiles(accepted(event.target.files, props.accept));
                    // So the same file chosen again, after being taken off, is a change the picker reports.
                    event.target.value = '';
                }}
            />
        </label>
    );
}

/** A file chosen and not yet sent: what it is, how big, and a way to take it off. */
export function ChosenFile(props: { file: File; onRemove: () => void }): JSX.Element {
    const { t, locale } = useTranslation();

    return (
        <div className="chosen-file">
            <span className="chosen-file-mark" aria-hidden="true">
                <FileText className="size-4" />
            </span>
            <span className="min-w-0 flex-1">
                <span className="block truncate text-xs font-semibold" title={props.file.name}>
                    {props.file.name}
                </span>
                <span className="note tabular-nums">
                    {formatBytes(props.file.size, locale)} · {props.file.name.split('.').pop()?.toUpperCase()}
                </span>
            </span>
            <button
                type="button"
                className="chosen-file-remove"
                aria-label={t((d) => d.common.delete)}
                onClick={props.onRemove}
            >
                <X className="size-4" aria-hidden="true" />
            </button>
        </div>
    );
}
