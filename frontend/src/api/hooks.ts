import { useMutation, useQuery, useQueryClient, keepPreviousData } from '@tanstack/react-query';
import type { FormInstance } from 'antd';
import { message } from 'antd';
import { api, ApiError, type Paged } from './client';

type Query = Record<string, string | number | boolean | null | undefined>;

export function useList<T>(path: string, query?: Query, enabled = true) {
  return useQuery({
    queryKey: [path, query ?? {}],
    queryFn: () => api.get<Paged<T>>(path, query),
    placeholderData: keepPreviousData,
    enabled,
  });
}

export function useGet<T>(path: string | null, query?: Query) {
  return useQuery({
    queryKey: [path, query ?? {}],
    queryFn: () => api.get<T>(path as string, query),
    enabled: path !== null,
  });
}

/** Options for selects: all active rows of a master (up to 200). */
export function useOptions<T extends { id: number }>(path: string, query?: Query) {
  const q = useList<T>(path, { per_page: 200, active: 1, ...query });
  return { ...q, rows: q.data?.items ?? [] };
}

/**
 * Mutation that invalidates the given query prefixes and shows API validation errors on the form.
 */
export function useSave<TBody, TResult>(
  fn: (body: TBody) => Promise<TResult>,
  opts: { invalidate?: string[]; form?: FormInstance; success?: string; onSuccess?: (r: TResult) => void } = {},
) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: fn,
    onSuccess: (result) => {
      for (const key of opts.invalidate ?? []) {
        qc.invalidateQueries({ predicate: (q) => String(q.queryKey[0]).startsWith(key) });
      }
      qc.invalidateQueries({ queryKey: ['/dashboard'] });
      if (opts.success) message.success(opts.success);
      opts.onSuccess?.(result);
    },
    onError: (err) => showError(err, opts.form),
  });
}

export function showError(err: unknown, form?: FormInstance) {
  if (err instanceof ApiError) {
    if (form && Object.keys(err.fields).length > 0) {
      form.setFields(
        Object.entries(err.fields).map(([name, errors]) => ({
          name: name.split('.').map((p) => (/^\d+$/.test(p) ? Number(p) : p)),
          errors,
        })),
      );
      const first = Object.values(err.fields)[0]?.[0];
      message.error(first ? `${err.message} ${first}` : err.message);
      return;
    }
    message.error(err.message);
    return;
  }
  message.error(err instanceof Error ? err.message : 'Something went wrong');
}
