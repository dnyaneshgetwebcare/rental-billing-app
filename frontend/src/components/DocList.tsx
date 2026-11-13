import type { ReactNode } from 'react';
import { Card, Empty, Grid, List, Table } from 'antd';
import type { ColumnsType } from 'antd/es/table';
import { useList } from '../api/hooks';

type Query = Record<string, string | number | boolean | null | undefined>;

export interface MobileRow {
  title: ReactNode;
  subtitle?: ReactNode;
  right?: ReactNode;
  footer?: ReactNode;
}

/**
 * Paginated list: a table on desktop, tappable cards on phones.
 */
export default function DocList<T extends { id: number }>({
  path,
  query,
  page,
  onPage,
  perPage = 25,
  columns,
  mobile,
  onOpen,
  enabled = true,
}: {
  path: string;
  query?: Query;
  page: number;
  onPage: (p: number) => void;
  perPage?: number;
  columns: ColumnsType<T>;
  mobile: (row: T) => MobileRow;
  onOpen?: (row: T) => void;
  enabled?: boolean;
}) {
  const screens = Grid.useBreakpoint();
  const list = useList<T>(path, { ...query, page, per_page: perPage }, enabled);
  const rows = list.data?.items ?? [];
  const pagination = { current: page, pageSize: perPage, total: list.data?.total ?? 0, onChange: onPage, showSizeChanger: false };

  if (screens.md) {
    return (
      <Table<T>
        rowKey="id"
        loading={list.isFetching}
        dataSource={rows}
        columns={columns}
        pagination={pagination}
        scroll={{ x: 760 }}
        onRow={(r) => ({ onClick: () => onOpen?.(r), style: { cursor: onOpen ? 'pointer' : undefined } })}
      />
    );
  }
  return (
    <List
      loading={list.isFetching}
      dataSource={rows}
      locale={{ emptyText: <Empty description="Nothing here yet" /> }}
      pagination={rows.length ? { ...pagination, size: 'small', simple: true } : false}
      renderItem={(r) => {
        const m = mobile(r);
        return (
          <Card className="list-card" style={{ marginBottom: 8 }} onClick={() => onOpen?.(r)}>
            <div style={{ display: 'flex', justifyContent: 'space-between', gap: 8 }}>
              <div style={{ minWidth: 0 }}>
                <div style={{ fontWeight: 600, overflow: 'hidden', textOverflow: 'ellipsis' }}>{m.title}</div>
                {m.subtitle && <div style={{ color: '#888', fontSize: 12 }}>{m.subtitle}</div>}
              </div>
              {m.right && <div style={{ textAlign: 'right', flexShrink: 0 }}>{m.right}</div>}
            </div>
            {m.footer && <div style={{ marginTop: 6 }}>{m.footer}</div>}
          </Card>
        );
      }}
    />
  );
}
