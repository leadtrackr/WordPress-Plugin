import * as React from "react";
import { cn } from "../../lib/utils";
import { Switch } from "./switch";

/**
 * A titled block of settings. Groups related fields so the page reads as a few
 * decisions instead of one long column of inputs.
 */
export function Section({
  title,
  description,
  children,
  className,
}: {
  title: string;
  description?: React.ReactNode;
  children: React.ReactNode;
  className?: string;
}) {
  return (
    <section className={cn("rounded-xl border bg-card p-5 shadow-sm mb-3", className)}>
      <h2 className="text-base font-semibold leading-none">{title}</h2>
      {description && <p className="text-sm text-muted-foreground mt-1.5">{description}</p>}
      <div className="mt-4">{children}</div>
    </section>
  );
}

/**
 * A labelled field with room for the one sentence that explains what the value
 * is for. Keeps every field on the page built the same way.
 */
export function Field({
  label,
  htmlFor,
  hint,
  badge,
  children,
}: {
  label: string;
  htmlFor: string;
  hint?: React.ReactNode;
  badge?: string;
  children: React.ReactNode;
}) {
  return (
    <div className="max-w-md">
      <div className="flex items-center gap-2 mb-1.5">
        <label
          htmlFor={htmlFor}
          className="text-sm font-medium leading-none"
        >
          {label}
        </label>
        {badge && (
          <span className="rounded border border-border px-1.5 py-0.5 text-[11px] font-medium text-muted-foreground">
            {badge}
          </span>
        )}
      </div>
      {children}
      {hint && <p className="text-sm text-muted-foreground mt-1.5">{hint}</p>}
    </div>
  );
}

/**
 * Switch plus label plus explanation, built once so every toggle on the page
 * looks and behaves the same.
 */
export function ToggleField({
  id,
  label,
  hint,
  checked,
  onChange,
  disabled,
}: {
  id: string;
  label: string;
  hint?: React.ReactNode;
  checked: boolean;
  onChange: (checked: boolean) => void;
  disabled?: boolean;
}) {
  return (
    <div className={cn("flex items-start gap-3", disabled && "opacity-50")}>
      <Switch id={id} checked={checked} onCheckedChange={onChange} disabled={disabled} />
      <div className="min-w-0">
        <label
          htmlFor={id}
          onClick={() => !disabled && onChange(!checked)}
          className="text-sm font-medium leading-none cursor-pointer"
        >
          {label}
        </label>
        {hint && <p className="text-sm text-muted-foreground mt-1">{hint}</p>}
      </div>
    </div>
  );
}

/** External link with a consistent look and the arrow that marks "leaves WordPress". */
export function ExternalLink({
  href,
  children,
  className,
}: {
  href: string;
  children: React.ReactNode;
  className?: string;
}) {
  return (
    <a
      href={href}
      target="_blank"
      rel="noreferrer noopener"
      className={cn(
        "inline-flex items-center gap-1 font-medium text-primary_branding hover:underline",
        className
      )}
    >
      {children}
      <span aria-hidden="true">↗</span>
    </a>
  );
}

/** Shown where a form builder is expected but not present on the site. */
export function EmptyState({
  title,
  children,
}: {
  title: string;
  children?: React.ReactNode;
}) {
  return (
    <div className="rounded-xl border border-dashed bg-card py-10 px-6 text-center shadow-sm">
      <p className="text-sm font-medium">{title}</p>
      {children && <p className="text-sm text-muted-foreground mt-1.5">{children}</p>}
    </div>
  );
}
