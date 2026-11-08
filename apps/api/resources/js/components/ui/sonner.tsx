import { Toaster as Sonner, type ToasterProps } from 'sonner';
import { CircleCheckIcon, InfoIcon, TriangleAlertIcon, OctagonXIcon, Loader2Icon } from 'lucide-react';
import { useTheme } from '@/hooks/useTheme';
import { useMediaQuery } from '@/hooks/useMediaQuery';

const Toaster = ({ ...props }: ToasterProps) => {
    const { resolved } = useTheme();
    // The sidebar's breakpoint: below it the screen is a phone's, with the tab bar along the bottom.
    const desktop = useMediaQuery('(min-width: 64rem)');

    return (
        <Sonner
            theme={resolved}
            // On a desktop, inverted against the page in the bottom-right: a dark pill on a light
            // ground reads as a transient notice rather than as another card. On a phone, from the
            // top, where the phone's own notifications arrive, clear of the tab bar along the bottom
            // — and in the page's own surface: Safari tints the status bar from what is fixed at the
            // top of the screen, and a dark toast there turned the whole bar black.
            position={desktop ? 'bottom-right' : 'top-center'}
            // Sonner's own distances from the edge (32px, 8px from the top on a phone), plus what iOS
            // keeps for its notch, rounded corners and home bar — 0 everywhere else. See `.safe-area`
            // in app.css.
            offset={{
                bottom: 'calc(32px + env(safe-area-inset-bottom))',
                right: 'calc(32px + env(safe-area-inset-right))',
            }}
            mobileOffset={{
                top: 'calc(8px + env(safe-area-inset-top))',
                left: 'calc(12px + env(safe-area-inset-left))',
                right: 'calc(12px + env(safe-area-inset-right))',
            }}
            className="toaster group"
            icons={{
                success: <CircleCheckIcon className="size-4" />,
                info: <InfoIcon className="size-4" />,
                warning: <TriangleAlertIcon className="size-4" />,
                error: <OctagonXIcon className="size-4" />,
                loading: <Loader2Icon className="size-4 animate-spin" />,
            }}
            style={
                {
                    '--normal-bg': desktop ? 'var(--foreground)' : 'var(--popover)',
                    '--normal-text': desktop ? 'var(--background)' : 'var(--popover-foreground)',
                    '--normal-border': desktop ? 'transparent' : 'var(--border)',
                    // Rounder on a phone, the shape its own notifications have.
                    '--border-radius': desktop ? '9px' : '14px',
                    // Sonner styles the description with its own muted token, which lands
                    // near-invisible on the inverted toast; tie it to the toast's own text colour.
                    '--normal-description': 'color-mix(in oklab, var(--normal-text) 78%, transparent)',
                } as React.CSSProperties
            }
            toastOptions={{
                classNames: {
                    toast: 'cn-toast !gap-2 !p-[10px_12px] !text-xs !shadow-[0_12px_28px_rgba(16,24,40,0.18)]',
                    description: '!text-xs !text-[var(--normal-description)] !opacity-100',
                },
            }}
            {...props}
        />
    );
};

export { Toaster };
