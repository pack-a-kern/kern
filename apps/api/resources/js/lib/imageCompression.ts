/**
 * Client-side image resize/compression before upload (`BLUEPRINT.md` §1.4: "compresie/resize
 * client-side pentru imagini înainte de upload, fără degradarea OCR").
 *
 * The long edge is capped at {@link MAX_DIMENSION_PX} (2200px) — well above the ~150–300 DPI a
 * receipt/invoice needs for native-text or OCR extraction to read individual characters reliably,
 * while still cutting a typical 12MP phone photo (4000×3000) down substantially. Quality is fixed
 * at {@link JPEG_QUALITY} (0.86): high enough that JPEG block artefacts don't blur small glyphs,
 * low enough to meaningfully shrink the upload on a mobile connection. Both are named constants,
 * not tuned per-call — `docs/CODE-QUALITY.md` §4 (no magic numbers) applies to this app's own
 * constants the same way it applies to backend fiscal code.
 *
 * A file that is not a browser-decodable raster image (PDF, or any `image/*` MIME the platform's
 * decoder rejects) is returned unchanged — this module never touches documents it cannot safely
 * re-encode. A file already at or below the target dimensions is also returned unchanged, since
 * re-encoding it could only lose quality, never gain anything.
 */

export const MAX_DIMENSION_PX = 2200;
export const JPEG_QUALITY = 0.86;

/**
 * Pure sizing math, factored out so it is unit-testable without a real `<canvas>`/image decoder
 * (not available in this project's Vitest + jsdom environment — see
 * `docs/handoff/030-pwa-frontend.md` "How to verify" for why the encode/decode half of this module
 * is verified manually in a real browser instead).
 */
export function computeTargetDimensions(
    width: number,
    height: number,
    maxDimension: number,
): { width: number; height: number } {
    const longestEdge = Math.max(width, height);

    if (longestEdge <= maxDimension || longestEdge === 0) {
        return { width, height };
    }

    const scale = maxDimension / longestEdge;

    return {
        width: Math.max(1, Math.round(width * scale)),
        height: Math.max(1, Math.round(height * scale)),
    };
}

function isRasterImage(file: File): boolean {
    return file.type.startsWith('image/') && file.type !== 'image/svg+xml';
}

/**
 * Resizes/re-encodes `file` as a JPEG when it is a raster image larger than {@link MAX_DIMENSION_PX}
 * on its long edge; otherwise returns `file` untouched. Never throws for a decode failure — a file
 * this browser cannot decode as an image is returned as-is and lets the server's own validation
 * (`Kern\Documents\FileSecurity\FileSecurityValidator`) be the source of truth on whether it
 * is acceptable at all.
 */
export async function compressImageIfNeeded(file: File): Promise<File> {
    if (!isRasterImage(file)) {
        return file;
    }

    let bitmap: ImageBitmap;

    try {
        bitmap = await createImageBitmap(file);
    } catch {
        return file;
    }

    try {
        const target = computeTargetDimensions(bitmap.width, bitmap.height, MAX_DIMENSION_PX);

        if (target.width === bitmap.width && target.height === bitmap.height) {
            return file;
        }

        const canvas = document.createElement('canvas');
        canvas.width = target.width;
        canvas.height = target.height;

        const context = canvas.getContext('2d');

        if (!context) {
            return file;
        }

        context.drawImage(bitmap, 0, 0, target.width, target.height);

        const blob = await new Promise<Blob | null>((resolve) => {
            canvas.toBlob(resolve, 'image/jpeg', JPEG_QUALITY);
        });

        if (!blob) {
            return file;
        }

        const newName = file.name.replace(/\.\w+$/, '') + '.jpg';

        return new File([blob], newName, { type: 'image/jpeg', lastModified: Date.now() });
    } finally {
        bitmap.close();
    }
}
