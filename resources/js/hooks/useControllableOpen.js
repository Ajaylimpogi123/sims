import { useState } from "react";

/**
 * Open state for a dialog that can be either self-managed (it renders its
 * own trigger) or controlled by a parent (e.g. a row's "..." actions menu
 * that opens the dialog after closing itself). Pass `open` to control it.
 */
export default function useControllableOpen(open, onOpenChange) {
    const [internalOpen, setInternalOpen] = useState(false);
    const isControlled = open !== undefined;

    return [
        isControlled ? open : internalOpen,
        isControlled ? (value) => onOpenChange?.(value) : setInternalOpen,
    ];
}
