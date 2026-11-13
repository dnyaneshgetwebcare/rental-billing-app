// Money helpers for display and live form totals. The server recalculates everything;
// these use integer paise so the preview matches the server's half-up rounding.

export function toPaise(value: string | number | null | undefined): number {
  if (value === null || value === undefined || value === '') return 0;
  const s = typeof value === 'number' ? value.toFixed(6) : String(value).trim();
  if (!/^-?\d+(\.\d+)?$/.test(s)) return 0;
  const negative = s.startsWith('-');
  const [int, frac = ''] = s.replace('-', '').split('.');
  const padded = (frac + '000').slice(0, 3);
  let paise = Number(int) * 100 + Number(padded.slice(0, 2));
  if (Number(padded[2]) >= 5) paise += 1;
  return negative ? -paise : paise;
}

export function fromPaise(paise: number): string {
  const negative = paise < 0;
  const abs = Math.abs(paise);
  const s = `${Math.floor(abs / 100)}.${String(abs % 100).padStart(2, '0')}`;
  return negative ? `-${s}` : s;
}

/** round(paise * rate / 100), half up away from zero; rate like "18.00" or "9". */
export function percentOf(paise: number, rate: string | number): number {
  const r = Math.round(Number(rate) * 10000); // rate with 4 decimals
  const exact = paise * r; // paise * rate * 10000
  const divisor = 100 * 10000;
  const q = Math.trunc(exact / divisor);
  const rem = Math.abs(exact % divisor);
  const up = rem * 2 >= divisor ? 1 : 0;
  return exact < 0 ? q - up : q + up;
}

const inr = new Intl.NumberFormat('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

export function formatMoney(value: string | number | null | undefined): string {
  return inr.format(toPaise(value) / 100);
}

export function rupees(value: string | number | null | undefined): string {
  return `₹ ${formatMoney(value)}`;
}

export interface GstLineInput {
  qty?: string | number | null;
  rate?: string | number | null;
  gst_rate?: string | number | null;
}

export interface GstTotals {
  taxable: number;
  cgst: number;
  sgst: number;
  igst: number;
  roundOff: number;
  total: number;
  lines: { taxable: number; tax: number; total: number }[];
}

/** Mirrors backend GstCalculator: per-line rounding, CGST+SGST within the state, IGST otherwise. */
export function gstTotals(lines: GstLineInput[], interState: boolean): GstTotals {
  let taxable = 0, cgst = 0, sgst = 0, igst = 0;
  const out: GstTotals['lines'] = [];
  for (const l of lines) {
    const qtyMilli = Math.round(Number(l.qty ?? 1) * 1000) || 0;
    const ratePaise = toPaise(l.rate);
    const lineTaxable = roundDiv(ratePaise * qtyMilli, 1000);
    const gstRate = Number(l.gst_rate ?? 0) || 0;
    let tax = 0;
    if (interState) {
      const i = percentOf(lineTaxable, gstRate);
      igst += i;
      tax = i;
    } else {
      const half = percentOf(lineTaxable, gstRate / 2);
      cgst += half;
      sgst += half;
      tax = half * 2;
    }
    taxable += lineTaxable;
    out.push({ taxable: lineTaxable, tax, total: lineTaxable + tax });
  }
  const exact = taxable + cgst + sgst + igst;
  const total = roundDiv(exact, 100) * 100;
  return { taxable, cgst, sgst, igst, roundOff: total - exact, total, lines: out };
}

function roundDiv(a: number, b: number): number {
  const q = Math.trunc(a / b);
  const rem = Math.abs(a % b);
  const up = rem * 2 >= b ? 1 : 0;
  return a < 0 ? q - up : q + up;
}

export const STATES: Record<string, string> = {
  '01': 'Jammu and Kashmir', '02': 'Himachal Pradesh', '03': 'Punjab', '04': 'Chandigarh', '05': 'Uttarakhand',
  '06': 'Haryana', '07': 'Delhi', '08': 'Rajasthan', '09': 'Uttar Pradesh', '10': 'Bihar', '11': 'Sikkim',
  '12': 'Arunachal Pradesh', '13': 'Nagaland', '14': 'Manipur', '15': 'Mizoram', '16': 'Tripura', '17': 'Meghalaya',
  '18': 'Assam', '19': 'West Bengal', '20': 'Jharkhand', '21': 'Odisha', '22': 'Chhattisgarh', '23': 'Madhya Pradesh',
  '24': 'Gujarat', '26': 'Dadra & Nagar Haveli and Daman & Diu', '27': 'Maharashtra', '29': 'Karnataka', '30': 'Goa',
  '31': 'Lakshadweep', '32': 'Kerala', '33': 'Tamil Nadu', '34': 'Puducherry', '35': 'Andaman & Nicobar',
  '36': 'Telangana', '37': 'Andhra Pradesh', '38': 'Ladakh', '97': 'Other Territory',
};

export const stateOptions = Object.entries(STATES).map(([value, label]) => ({ value, label: `${value} - ${label}` }));

export function stateFromGstin(gstin?: string | null): string | null {
  return gstin && /^\d{2}/.test(gstin) ? gstin.slice(0, 2) : null;
}
