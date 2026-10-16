import { ExternalLink, FileImage, FileSpreadsheet, FileText, Paperclip } from "lucide-react";

import { cn } from "@/lib/utils";

const ICONS = {
    pdf: FileText,
    doc: FileText,
    docx: FileText,
    txt: FileText,
    jpg: FileImage,
    jpeg: FileImage,
    png: FileImage,
    gif: FileImage,
    webp: FileImage,
    xls: FileSpreadsheet,
    xlsx: FileSpreadsheet,
    csv: FileSpreadsheet,
};

/**
 * A report attachment in a table cell: a file chip (type icon, name,
 * extension) that opens the file in a new tab.
 */
export default function AttachmentLink({ href, name, className }) {
    const fileName = name || "Attachment";
    const dot = fileName.lastIndexOf(".");
    const ext = dot > 0 ? fileName.slice(dot + 1).toLowerCase() : "";
    const base = dot > 0 ? fileName.slice(0, dot) : fileName;
    const Icon = ICONS[ext] ?? Paperclip;

    return (
        <a
            href={href}
            target="_blank"
            rel="noreferrer"
            title={`Open ${fileName}`}
            className={cn(
                "group inline-flex max-w-[14rem] items-center gap-2 rounded-lg border border-border bg-background py-1 pl-1 pr-2 text-sm shadow-sm transition-colors hover:border-primary/40 hover:bg-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring",
                className,
            )}
        >
            <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-md bg-primary/10 text-primary">
                <Icon className="h-4 w-4" />
            </span>
            <span className="min-w-0 truncate font-medium text-foreground group-hover:text-accent-foreground">
                {base}
            </span>
            {ext && (
                <span className="shrink-0 rounded bg-muted px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">
                    {ext}
                </span>
            )}
            <ExternalLink className="h-3.5 w-3.5 shrink-0 text-muted-foreground transition-colors group-hover:text-primary" />
        </a>
    );
}
