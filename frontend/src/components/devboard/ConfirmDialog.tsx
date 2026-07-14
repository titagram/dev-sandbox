import React, { useState } from "react";
import {
  AlertDialog, AlertDialogAction, AlertDialogCancel, AlertDialogContent,
  AlertDialogDescription, AlertDialogFooter, AlertDialogHeader, AlertDialogTitle,
} from "@/components/ui/alert-dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { TriangleAlert } from "lucide-react";

export function ConfirmDialog({
  open, onOpenChange, title, description, confirmLabel = "Confirm", destructive,
  requireText, requireApproval, onConfirm, testId,
}: {
  open: boolean;
  onOpenChange: (v: boolean) => void;
  title: string;
  description: React.ReactNode;
  confirmLabel?: string;
  destructive?: boolean;
  /** require typing this exact string to enable confirm. */
  requireText?: string;
  /** require ticking an explicit human-approval acknowledgement. */
  requireApproval?: string;
  onConfirm: () => void | Promise<void>;
  testId?: string;
}) {
  const [text, setText] = useState("");
  const [approved, setApproved] = useState(false);
  const [busy, setBusy] = useState(false);

  const ready =
    (!requireText || text === requireText) && (!requireApproval || approved);

  const handle = async () => {
    setBusy(true);
    try { await onConfirm(); onOpenChange(false); }
    finally { setBusy(false); setText(""); setApproved(false); }
  };

  return (
    <AlertDialog open={open} onOpenChange={(v) => { onOpenChange(v); if (!v) { setText(""); setApproved(false); } }}>
      <AlertDialogContent data-testid={testId}>
        <AlertDialogHeader>
          <AlertDialogTitle className="flex items-center gap-2">
            {destructive && <TriangleAlert className="h-4 w-4 text-red-500" />}
            {title}
          </AlertDialogTitle>
          <AlertDialogDescription asChild>
            <div className="text-sm text-muted-foreground">{description}</div>
          </AlertDialogDescription>
        </AlertDialogHeader>

        {requireText && (
          <div className="space-y-1.5">
            <Label className="text-xs">Type <span className="font-mono text-foreground">{requireText}</span> to confirm</Label>
            <Input value={text} onChange={(e) => setText(e.target.value)} className="font-mono" data-testid="confirm-text-input" autoFocus />
          </div>
        )}

        {requireApproval && (
          <label className="flex items-start gap-2 rounded-md border border-amber-500/30 bg-amber-500/5 p-3 text-xs">
            <input type="checkbox" checked={approved} onChange={(e) => setApproved(e.target.checked)} className="mt-0.5" data-testid="confirm-approval-checkbox" />
            <span>{requireApproval}</span>
          </label>
        )}

        <AlertDialogFooter>
          <AlertDialogCancel data-testid="confirm-cancel">Cancel</AlertDialogCancel>
          <AlertDialogAction
            disabled={!ready || busy}
            onClick={(e) => { e.preventDefault(); handle(); }}
            data-testid="confirm-action"
            className={destructive ? "bg-red-600 text-white hover:bg-red-700" : ""}
          >
            {busy ? "Working…" : confirmLabel}
          </AlertDialogAction>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
  );
}
