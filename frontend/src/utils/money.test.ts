import { describe, expect, it } from 'vitest';
import { formatMoney, fromPaise, gstTotals, percentOf, toPaise } from './money';

describe('toPaise / fromPaise', () => {
  it('parses decimal strings with half-up rounding', () => {
    expect(toPaise('100')).toBe(10000);
    expect(toPaise('100.5')).toBe(10050);
    expect(toPaise('100.005')).toBe(10001);
    expect(toPaise('100.004')).toBe(10000);
    expect(toPaise('-12.345')).toBe(-1235);
    expect(toPaise('')).toBe(0);
    expect(toPaise('abc')).toBe(0);
  });
  it('formats back', () => {
    expect(fromPaise(10050)).toBe('100.50');
    expect(fromPaise(-5)).toBe('-0.05');
  });
});

describe('percentOf', () => {
  it('rounds half up', () => {
    expect(percentOf(10000, 18)).toBe(1800);
    expect(percentOf(3333, 9)).toBe(300); // 299.97 -> 300
    expect(percentOf(5, 50)).toBe(3); // 2.5 -> 3
  });
});

describe('gstTotals', () => {
  it('splits CGST/SGST within the state and rounds the total to the rupee', () => {
    const t = gstTotals([{ qty: 1, rate: '25000.50', gst_rate: '18' }], false);
    expect(t.taxable).toBe(2500050);
    expect(t.cgst).toBe(225005); // 2250.045 -> 2250.05
    expect(t.sgst).toBe(225005);
    expect(t.igst).toBe(0);
    expect(t.total).toBe(2950100); // 29500.60 -> 29501.00
    expect(t.roundOff).toBe(40);
  });
  it('charges IGST across states', () => {
    const t = gstTotals([{ qty: 2, rate: '1000', gst_rate: '18' }, { rate: '500', gst_rate: '0' }], true);
    expect(t.taxable).toBe(250000);
    expect(t.igst).toBe(36000);
    expect(t.total).toBe(286000);
    expect(t.roundOff).toBe(0);
  });
});

describe('formatMoney', () => {
  it('uses Indian digit grouping', () => {
    expect(formatMoney('1234567.5')).toBe('12,34,567.50');
  });
});
