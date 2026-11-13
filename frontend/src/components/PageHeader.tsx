import type { ReactNode } from 'react';
import { useNavigate } from 'react-router-dom';
import { Button, Space } from 'antd';
import { ArrowLeftOutlined } from '@ant-design/icons';

export default function PageHeader({ title, back, extra }: { title: ReactNode; back?: boolean | string; extra?: ReactNode }) {
  const navigate = useNavigate();
  return (
    <div className="page-header">
      <Space>
        {back && (
          <Button
            type="text"
            icon={<ArrowLeftOutlined />}
            onClick={() => (typeof back === 'string' ? navigate(back) : navigate(-1))}
          />
        )}
        <h2>{title}</h2>
      </Space>
      {extra && <Space wrap className="no-print">{extra}</Space>}
    </div>
  );
}
