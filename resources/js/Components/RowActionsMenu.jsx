import { useRef, useState } from "react";
import { MoreHorizontal } from "lucide-react";
import { Button } from "@/Components/ui/button";
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from "@/Components/ui/dropdown-menu";

const ITEM_CLASS =
    "pl-2 pr-4 py-2 text-sm text-gray-800 hover:bg-gray-100 rounded-md cursor-pointer";

/**
 * A table row's "..." actions menu. Picking an action closes the menu; an
 * action with a `dialog` key then opens that dialog, which is rendered
 * OUTSIDE the menu (via `renderDialogs`) so closing the menu doesn't unmount
 * it. The menu is non-modal so it doesn't fight the dialog over body
 * pointer-events / focus.
 *
 * actions: [{ label, dialog?, onSelect?, className? }] (falsy entries skipped)
 * renderDialogs: ({ isOpen(key), onOpenChange }) => dialogs
 */
export default function RowActionsMenu({ actions, renderDialogs }) {
    const [activeDialog, setActiveDialog] = useState(null);
    const triggerRef = useRef(null);
    const openingDialog = useRef(false);

    const handleSelect = (action) => {
        if (action.dialog) {
            openingDialog.current = true;
            setActiveDialog(action.dialog);
        } else {
            action.onSelect?.();
        }
    };

    const onOpenChange = (open) => {
        if (open) return;
        setActiveDialog(null);
        // The menu item that opened the dialog is gone; return focus to
        // the row's trigger instead.
        requestAnimationFrame(() => triggerRef.current?.focus());
    };

    return (
        <>
            <DropdownMenu modal={false}>
                <DropdownMenuTrigger asChild>
                    <Button
                        ref={triggerRef}
                        variant="ghost"
                        className="h-8 w-8 p-0"
                    >
                        <span className="sr-only">Open actions</span>
                        <MoreHorizontal className="h-4 w-4" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    align="end"
                    onCloseAutoFocus={(e) => {
                        // Let the dialog take focus instead of the trigger.
                        if (openingDialog.current) {
                            e.preventDefault();
                            openingDialog.current = false;
                        }
                    }}
                >
                    <DropdownMenuLabel>Actions</DropdownMenuLabel>
                    {actions.filter(Boolean).map((action) => (
                        <DropdownMenuItem
                            key={action.label}
                            onSelect={() => handleSelect(action)}
                            className={action.className ?? ITEM_CLASS}
                        >
                            {action.label}
                        </DropdownMenuItem>
                    ))}
                </DropdownMenuContent>
            </DropdownMenu>

            {renderDialogs?.({
                isOpen: (key) => activeDialog === key,
                onOpenChange,
            })}
        </>
    );
}
