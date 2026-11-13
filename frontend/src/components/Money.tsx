import { Typography } from 'antd';
import { formatMoney } from '../utils/money';

export default function Money({ value, strong, type }: { value: string | number | null | undefined; strong?: boolean; type?: 'danger' | 'success' | 'secondary' }) {
  return (
    <Typography.Text className="money" strong={strong} type={type}>
      {formatMoney(value)}
    </Typography.Text>
  );
}

const STATUS_COLORS: Record<string, string> = {
  paid: 'green',
  partial: 'orange',
  unpaid: 'red',
  cancelled: 'default',
  active: 'blue',
  ended: 'default',
};

export function statusColor(status: string): string {
  return STATUS_COLORS[status] ?? 'default';
}
