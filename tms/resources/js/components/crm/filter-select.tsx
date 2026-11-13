import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { type Option } from '@/lib/crm';

const ALL = 'all';

export function FilterSelect({
    label,
    value,
    options,
    onChange,
}: {
    label: string;
    value: string | null;
    options: Option[];
    onChange: (value: string | null) => void;
}) {
    return (
        <div className="grid gap-1">
            <Label className="text-xs">{label}</Label>
            <Select value={value ?? ALL} onValueChange={(v) => onChange(v === ALL ? null : v)}>
                <SelectTrigger aria-label={label}>
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value={ALL}>Tous</SelectItem>
                    {options.map((o) => (
                        <SelectItem key={o.value} value={o.value}>
                            {o.label}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
        </div>
    );
}
