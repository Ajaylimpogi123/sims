import * as React from "react"
import { cn } from "@/lib/utils"
import { Label } from "@/Components/ui/label"
import { Separator } from "@/Components/ui/separator"

const FieldGroup = React.forwardRef(({ className, ...props }, ref) => (
  <div
    ref={ref}
    className={cn("flex flex-col gap-6", className)}
    {...props}
  />
))
FieldGroup.displayName = "FieldGroup"

const Field = React.forwardRef(({ className, ...props }, ref) => (
  <div
    ref={ref}
    className={cn("flex flex-col gap-2", className)}
    {...props}
  />
))
Field.displayName = "Field"

const FieldLabel = React.forwardRef(({ className, htmlFor, ...props }, ref) => (
  <Label
    ref={ref}
    htmlFor={htmlFor}
    className={cn("", className)}
    {...props}
  />
))
FieldLabel.displayName = "FieldLabel"

const FieldDescription = React.forwardRef(({ className, ...props }, ref) => (
  <p
    ref={ref}
    className={cn("text-sm text-muted-foreground", className)}
    {...props}
  />
))
FieldDescription.displayName = "FieldDescription"

const FieldSeparator = React.forwardRef(({ className, children, ...props }, ref) => (
  <div
    ref={ref}
    className={cn("relative flex items-center gap-4", className)}
    {...props}
  >
    <Separator />
    {children && (
      <span className="text-xs text-muted-foreground bg-card px-2">
        {children}
      </span>
    )}
    <Separator />
  </div>
))
FieldSeparator.displayName = "FieldSeparator"

export { Field, FieldDescription, FieldGroup, FieldLabel, FieldSeparator }
