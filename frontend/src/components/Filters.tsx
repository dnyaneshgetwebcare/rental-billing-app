import { DatePicker } from 'antd';
import dayjs from 'dayjs';
import { toApiDate } from '../utils/date';

export interface Range {
  from?: string;
  to?: string;
}

export function DateRangeFilter({ value, onChange, allowClear = true }: { value: Range; onChange: (r: Range) => void; allowClear?: boolean }) {
  return (
    <DatePicker.RangePicker
      allowClear={allowClear}
      format="DD-MM-YYYY"
      value={value.from && value.to ? [dayjs(value.from), dayjs(value.to)] : null}
      onChange={(v) => onChange(v && v[0] && v[1] ? { from: toApiDate(v[0]), to: toApiDate(v[1]) } : {})}
      presets={[
        { label: 'This month', value: [dayjs().startOf('month'), dayjs()] },
        { label: 'Last month', value: [dayjs().subtract(1, 'month').startOf('month'), dayjs().subtract(1, 'month').endOf('month')] },
        { label: 'This FY', value: [fyStart(), dayjs()] },
        { label: 'Last FY', value: [fyStart().subtract(1, 'year'), fyStart().subtract(1, 'day')] },
      ]}
      style={{ maxWidth: 260 }}
    />
  );
}

export function fyStart(startMonth = 4) {
  const now = dayjs();
  const year = now.month() + 1 >= startMonth ? now.year() : now.year() - 1;
  return dayjs(`${year}-${String(startMonth).padStart(2, '0')}-01`);
}
