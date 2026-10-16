export default function Checkbox({ className = '', ...props }) {
    return (
        <input
            {...props}
            type="checkbox"
            className={
                'rounded border-input text-primary shadow-sm focus:ring-primary/30 ' +
                className
            }
        />
    );
}
