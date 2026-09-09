'use client';

import * as React from 'react';
import { cn } from 'cn';
import { Switch as SwitchPrimitive } from 'radix-ui';

/**
 * An on/off control. Used where a value is genuinely binary — a text box containing `0` asks the
 * reader to know that zero means "off", which is a needless thing to know.
 */
function Switch({ className, ...props }: React.ComponentProps<typeof SwitchPrimitive.Root>) {
    return (
        <SwitchPrimitive.Root
            data-slot="switch"
            className={cn(
                'peer inline-flex h-4.5 w-8 shrink-0 cursor-pointer items-center rounded-full border border-transparent transition-colors outline-none',
                ' disabled:cursor-not-allowed disabled:opacity-50',
                'data-[state=unchecked]:bg-input data-[state=checked]:bg-primary dark:data-[state=unchecked]:bg-input/60',
                className,
            )}
            {...props}
        >
            <SwitchPrimitive.Thumb
                data-slot="switch-thumb"
                className={cn(
                    'pointer-events-none block size-3.5 rounded-full bg-background shadow-sm ring-0 transition-transform',
                    'data-[state=unchecked]:translate-x-0.5 data-[state=checked]:translate-x-3.5',
                )}
            />
        </SwitchPrimitive.Root>
    );
}

export { Switch };
