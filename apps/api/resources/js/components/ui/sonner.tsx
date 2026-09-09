import { Toaster as Sonner, type ToasterProps } from 'sonner';
import { CircleCheckIcon, InfoIcon, TriangleAlertIcon, OctagonXIcon, Loader2Icon } from 'lucide-react';
import { useTheme } from '@/hooks/useTheme';

const Toaster = ({ ...props }: ToasterProps) => {
    const { resolved } = useTheme();

    return (
        <Sonner
            theme={resolved}
            // Bottom-right, and inverted against the page: a dark pill on a light ground reads as
            // transient notice rather than as another card competing with the content.
            position="bottom-right"
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
                    '--normal-bg': 'var(--foreground)',
                    '--normal-text': 'var(--background)',
                    '--normal-border': 'transparent',
                    '--border-radius': '9px',
                    // Sonner styles the description with its own muted token, which is meant for a
                    // light surface. On this inverted toast it lands near-invisible; tie it to the
                    // toast's own text colour instead.
                    '--normal-description': 'color-mix(in oklab, var(--background) 78%, transparent)',
                } as React.CSSProperties
            }
            toastOptions={{
                classNames: {
                    toast: 'cn-toast !gap-2 !p-[10px_12px] !text-xs !shadow-[0_12px_28px_rgba(16,24,40,0.18)]',
                    description: '!text-[11.5px] !text-[var(--normal-description)] !opacity-100',
                },
            }}
            {...props}
        />
    );
};

export { Toaster };
