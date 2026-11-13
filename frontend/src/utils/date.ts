import dayjs, { type Dayjs } from 'dayjs';

export const API_DATE = 'YYYY-MM-DD';

export function today(): string {
  return dayjs().format(API_DATE);
}

export function toApiDate(d: Dayjs | string | null | undefined): string | undefined {
  if (!d) return undefined;
  return typeof d === 'string' ? d : d.format(API_DATE);
}

export function fromApiDate(s: string | null | undefined): Dayjs | undefined {
  return s ? dayjs(s) : undefined;
}

/** "2026-11-13" -> "13 Nov 2026" */
export function fmtDate(s: string | null | undefined): string {
  return s ? dayjs(s).format('DD MMM YYYY') : '';
}

/** "2026-11" -> "Nov 2026" */
export function fmtPeriod(p: string | null | undefined): string {
  return p ? dayjs(`${p}-01`).format('MMM YYYY') : '';
}
