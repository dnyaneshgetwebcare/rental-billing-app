import { useState } from 'react';
import { Input, Table, Typography } from 'antd';
import { useList } from '../../api/hooks';
import PageHeader from '../../components/PageHeader';
import { useAuth } from '../../auth/AuthContext';

interface AuditRow {
  id: number;
  created_at: string;
  user_name: string | null;
  action: string;
  entity: string;
  entity_id: number | null;
  data: string | null;
}

export default function AuditLogPage() {
  const { can } = useAuth();
  const [page, setPage] = useState(1);
  const [entity, setEntity] = useState('');
  const list = useList<AuditRow>('/audit-logs', { page, per_page: 50, entity }, can());
  if (!can()) return <div className="page">Only admins can see the audit log.</div>;
  return (
    <div className="page">
      <PageHeader title="Audit log" extra={<Input.Search allowClear placeholder="Entity, e.g. invoice" onSearch={(v) => { setEntity(v); setPage(1); }} />} />
      <Table
        rowKey="id"
        size="small"
        loading={list.isFetching}
        dataSource={list.data?.items ?? []}
        pagination={{ current: page, pageSize: 50, total: list.data?.total ?? 0, onChange: setPage, showSizeChanger: false }}
        scroll={{ x: 800 }}
        columns={[
          { title: 'When', dataIndex: 'created_at', width: 160 },
          { title: 'User', dataIndex: 'user_name', width: 140 },
          { title: 'Action', dataIndex: 'action', width: 110 },
          { title: 'Record', render: (_: unknown, r: AuditRow) => `${r.entity}${r.entity_id ? ' #' + r.entity_id : ''}`, width: 150 },
          {
            title: 'Details',
            dataIndex: 'data',
            render: (d: string | null) => <Typography.Text type="secondary" ellipsis={{ tooltip: d }} style={{ maxWidth: 380 }}>{d}</Typography.Text>,
          },
        ]}
      />
    </div>
  );
}
