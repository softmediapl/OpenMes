/**
 * ActionMenu — Geist White system (design ref: OpenMES Components.dc.html §07).
 *
 * Trigger node + 184px menu card (radius 12, menu shadow, 6px padding); items
 * 13px ink radius-8 rows with chip hover, destructive items in blocked,
 * hairline dividers via `{ divider: true }`. Closes on outside click/Escape.
 * API is identical to the native twin (index.native.tsx).
 * Web extras: `portal` escapes clipped table containers; `touch` gives items
 * a 48px minimum height. The trigger must forward button props and ref.
 */
import { cloneElement, useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { useAnchoredPopover } from '../lib/anchorPopover.web.js';

export function ActionMenu({ trigger, items, portal = false, touch = false, className = '', ...props }) {
    const [open, setOpen] = useState(false);
    const rootRef = useRef(null);
    const { anchorRef, popRef, style } = useAnchoredPopover(open && portal, { estHeight: items.length * (touch ? 48 : 36) + 12, estWidth: 184 });
    const close = () => {
        setOpen(false);
        anchorRef.current?.focus();
    };
    const positioned = !portal || Boolean(style);
    useEffect(() => {
        if (open && positioned) popRef.current?.querySelector('button:not(:disabled)')?.focus();
    }, [open, positioned, popRef]);

    useEffect(() => {
        if (!open) return;
        const onDown = (e) => {
            if (!rootRef.current?.contains(e.target) && !popRef.current?.contains(e.target)) setOpen(false);
        };
        const onKey = (e) => {
            if (e.key === 'Escape') {
                e.preventDefault();
                close();
            }
        };
        document.addEventListener('mousedown', onDown);
        document.addEventListener('keydown', onKey);
        return () => {
            document.removeEventListener('mousedown', onDown);
            document.removeEventListener('keydown', onKey);
        };
    }, [open]);

    const select = (item) => {
        if (item.disabled) return;
        close();
        item.onSelect?.();
    };

    const itemColor = (item) =>
        item.disabled
            ? 'cursor-not-allowed text-om-faint'
            : item.destructive
              ? 'text-om-blocked hover:bg-om-chip'
              : 'text-om-ink hover:bg-om-chip';

    const menu = <div
        ref={popRef}
        role="menu"
        style={portal ? style : undefined}
        onKeyDown={(event) => {
            const buttons = [...event.currentTarget.querySelectorAll('button:not(:disabled)')];
            const index = buttons.indexOf(document.activeElement);
            const next = event.key === 'ArrowDown' ? (index + 1) % buttons.length
                : event.key === 'ArrowUp' ? (index - 1 + buttons.length) % buttons.length
                : event.key === 'Home' ? 0 : event.key === 'End' ? buttons.length - 1 : null;
            if (next !== null) {
                event.preventDefault();
                buttons[next]?.focus();
            }
            if (event.key === 'Tab') setOpen(false);
        }}
        className={`${portal ? '' : 'absolute left-0 top-full z-50 mt-[6px]'} w-[184px] rounded-om border border-om-line bg-om-card p-[6px] shadow-[0_18px_44px_-18px_rgba(0,0,0,.3)]`}
    >
        {items.map((item, i) => item.divider ? (
            <div key={item.key ?? `divider-${i}`} aria-hidden className="my-[5px] h-px bg-om-line2" />
        ) : (
            <button
                key={item.key ?? `item-${i}`}
                type="button"
                role="menuitem"
                disabled={item.disabled}
                autoFocus={i === items.findIndex((entry) => !entry.divider && !entry.disabled)}
                onClick={() => select(item)}
                className={`block w-full cursor-pointer rounded-om-sm px-[11px] py-[9px] text-left ${touch ? 'min-h-12 text-base' : 'text-[13px]'} ${itemColor(item)}`}
            >{item.label}</button>
        ))}
    </div>;

    return (
        <div ref={rootRef} className={`relative inline-block ${className}`} {...props}>
            {cloneElement(trigger, {
                ref: anchorRef,
                'aria-haspopup': 'menu',
                'aria-expanded': open,
                onClick: () => setOpen((value) => !value),
                onKeyDown: (event) => {
                    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                        event.preventDefault();
                        setOpen(true);
                    }
                },
            })}
            {open && (portal ? style && createPortal(menu, document.body) : menu)}
        </div>
    );
}
