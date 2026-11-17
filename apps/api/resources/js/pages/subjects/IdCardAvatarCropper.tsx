import { useCallback, useEffect, useRef, useState, type JSX, type PointerEvent as ReactPointerEvent } from 'react';
import { ZoomIn, ZoomOut } from 'lucide-react';

import type { IdCardFaceBox } from '../../api/accountApi';
import { useTranslation } from '../../i18n';

/** The avatar is stored square; this is the side of the PNG the crop produces. */
const OUTPUT = 256;

/** The square source rect, in natural image pixels, that both the lens and the preview use. */
function sourceRect(
    natural: { w: number; h: number },
    center: { x: number; y: number },
    side: number,
): { left: number; top: number; size: number } {
    const size = side * Math.min(natural.w, natural.h);

    return {
        size,
        left: Math.min(Math.max(center.x * natural.w - size / 2, 0), natural.w - size),
        top: Math.min(Math.max(center.y * natural.h - size / 2, 0), natural.h - size),
    };
}

/**
 * Crops the cardholder's photograph out of the identity card, in the browser, from the box the model
 * returned — the card is never sent anywhere for this (ADR 0028). The box is a starting point, not a
 * verdict: drag the lens to move it, the slider to resize it, and a round live preview shows exactly
 * the avatar that will be stored. The cropped PNG is handed back on every change; the server re-scans
 * and re-encodes it like any avatar.
 *
 * The lens and the preview are driven from one source rect and one measured render — the image is
 * `object-fit: contain`, so its drawn size and letterbox offset are measured rather than assumed, and
 * the lens sits over exactly the pixels the preview shows.
 */
export function IdCardAvatarCropper(props: {
    imageUrl: string;
    initialBox: IdCardFaceBox | null;
    onCrop: (blob: Blob) => void;
}): JSX.Element {
    const { t } = useTranslation();
    const words = t((d) => d.pages.settingsSubjects.idCard);
    const imgRef = useRef<HTMLImageElement | null>(null);
    const previewRef = useRef<HTMLCanvasElement | null>(null);
    const dragRef = useRef<{ x: number; y: number } | null>(null);

    const [natural, setNatural] = useState<{ w: number; h: number } | null>(null);
    // The image element's measured box — tracked live, because the preview appearing beside it reflows
    // its width, and a size captured once at load would leave the lens scaled wrong.
    const [box, setBox] = useState<{ w: number; h: number }>({ w: 0, h: 0 });
    // Centre as a fraction of width/height; side as a fraction of the shorter edge (so it stays square).
    const [center, setCenter] = useState<{ x: number; y: number }>({ x: 0.5, y: 0.5 });
    const [side, setSide] = useState(0.4);

    const clampCenter = useCallback(
        (
            next: { x: number; y: number },
            sideFraction: number,
            size: { w: number; h: number },
        ): { x: number; y: number } => {
            const halfX = (sideFraction * Math.min(size.w, size.h)) / 2 / size.w;
            const halfY = (sideFraction * Math.min(size.w, size.h)) / 2 / size.h;

            return {
                x: Math.min(Math.max(next.x, halfX), 1 - halfX),
                y: Math.min(Math.max(next.y, halfY), 1 - halfY),
            };
        },
        [],
    );

    // Keep the measured element box current as the layout reflows.
    useEffect(() => {
        const img = imgRef.current;

        if (img === null) {
            return;
        }

        const measure = (): void => {
            setBox({ w: img.clientWidth, h: img.clientHeight });
        };

        const observer = new ResizeObserver(measure);
        observer.observe(img);
        measure();

        return () => {
            observer.disconnect();
        };
    }, [natural]);

    const onImageLoad = (): void => {
        const img = imgRef.current;

        if (img === null) {
            return;
        }

        const size = { w: img.naturalWidth, h: img.naturalHeight };
        setNatural(size);

        const initial = props.initialBox;

        if (initial === null) {
            setSide(0.4);
            setCenter(clampCenter({ x: 0.5, y: 0.5 }, 0.4, size));

            return;
        }

        // A touch of room around the face, squared off the longer side.
        const sidePx = Math.max(initial.w * size.w, initial.h * size.h) * 1.15;
        const sideFraction = Math.min(Math.max(sidePx / Math.min(size.w, size.h), 0.1), 1);
        setSide(sideFraction);
        setCenter(clampCenter({ x: initial.x + initial.w / 2, y: initial.y + initial.h / 2 }, sideFraction, size));
    };

    // Draw the selection into the round preview canvas and hand the same pixels back as the avatar.
    useEffect(() => {
        const img = imgRef.current;
        const canvas = previewRef.current;

        if (img === null || canvas === null || natural === null) {
            return;
        }

        const rect = sourceRect(natural, center, side);
        const context = canvas.getContext('2d');

        if (context === null) {
            return;
        }

        context.clearRect(0, 0, OUTPUT, OUTPUT);
        context.drawImage(img, rect.left, rect.top, rect.size, rect.size, 0, 0, OUTPUT, OUTPUT);
        canvas.toBlob((blob) => {
            if (blob !== null) {
                props.onCrop(blob);
            }
        }, 'image/png');
    }, [center, side, natural, props]);

    // How the natural image is actually drawn inside its (object-fit: contain) element box.
    const render =
        natural === null || box.w === 0 || box.h === 0
            ? null
            : (() => {
                  const drawScale = Math.min(box.w / natural.w, box.h / natural.h);

                  return {
                      drawScale,
                      offsetX: (box.w - natural.w * drawScale) / 2,
                      offsetY: (box.h - natural.h * drawScale) / 2,
                  };
              })();

    const onPointerDown = (event: ReactPointerEvent<HTMLButtonElement>): void => {
        event.currentTarget.setPointerCapture(event.pointerId);
        dragRef.current = { x: event.clientX, y: event.clientY };
    };

    const onPointerMove = (event: ReactPointerEvent<HTMLButtonElement>): void => {
        const start = dragRef.current;

        if (start === null || natural === null || render === null) {
            return;
        }

        const dx = (event.clientX - start.x) / render.drawScale / natural.w;
        const dy = (event.clientY - start.y) / render.drawScale / natural.h;
        dragRef.current = { x: event.clientX, y: event.clientY };
        setCenter((current) => clampCenter({ x: current.x + dx, y: current.y + dy }, side, natural));
    };

    const onPointerUp = (event: ReactPointerEvent<HTMLButtonElement>): void => {
        event.currentTarget.releasePointerCapture(event.pointerId);
        dragRef.current = null;
    };

    // The lens, in element-box pixels, over exactly the rect the preview crops.
    const lens =
        natural === null || render === null
            ? null
            : (() => {
                  const rect = sourceRect(natural, center, side);

                  return {
                      left: render.offsetX + rect.left * render.drawScale,
                      top: render.offsetY + rect.top * render.drawScale,
                      size: rect.size * render.drawScale,
                  };
              })();

    return (
        <div className="idcard-crop">
            <div className="idcard-crop-stage">
                <img
                    ref={imgRef}
                    src={props.imageUrl}
                    alt=""
                    onLoad={onImageLoad}
                    className="idcard-crop-img"
                    draggable={false}
                />
                {lens !== null && (
                    <button
                        type="button"
                        aria-label={words.adjust}
                        onPointerDown={onPointerDown}
                        onPointerMove={onPointerMove}
                        onPointerUp={onPointerUp}
                        className="idcard-crop-lens"
                        style={{
                            left: `${String(lens.left)}px`,
                            top: `${String(lens.top)}px`,
                            width: `${String(lens.size)}px`,
                            height: `${String(lens.size)}px`,
                        }}
                    />
                )}
            </div>

            <div className="idcard-crop-side">
                <canvas ref={previewRef} width={OUTPUT} height={OUTPUT} className="idcard-crop-preview" />
                <div className="idcard-crop-controls">
                    <span className="idcard-crop-hint">{words.adjust}</span>
                    <label className="idcard-crop-zoom">
                        <ZoomOut className="size-3.5" aria-hidden="true" />
                        <input
                            type="range"
                            min={0.1}
                            max={1}
                            step={0.01}
                            value={side}
                            aria-label={words.size}
                            disabled={natural === null}
                            onChange={(event) => {
                                const next = Number(event.target.value);
                                setSide(next);

                                if (natural !== null) {
                                    setCenter((current) => clampCenter(current, next, natural));
                                }
                            }}
                        />
                        <ZoomIn className="size-3.5" aria-hidden="true" />
                    </label>
                </div>
            </div>
        </div>
    );
}
