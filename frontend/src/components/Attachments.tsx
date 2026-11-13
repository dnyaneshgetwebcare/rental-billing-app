import { useRef } from 'react';
import { Button, Card, Image, Popconfirm, Space, Typography } from 'antd';
import { CameraOutlined, DeleteOutlined, FilePdfOutlined } from '@ant-design/icons';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '../api/client';
import { useSave } from '../api/hooks';
import { useAuth } from '../auth/AuthContext';
import { shrinkImage } from '../utils/files';

interface Attachment {
  id: number;
  original_name: string;
  mime: string;
  url: string;
  thumb_url: string | null;
  medium_url: string | null;
}

/** Photos / PDFs of the paper bill or agreement. On phones the camera opens directly. */
export default function Attachments({ ownerType, ownerId }: { ownerType: string; ownerId: number }) {
  const { can } = useAuth();
  const qc = useQueryClient();
  const input = useRef<HTMLInputElement>(null);
  const key = ['/attachments', ownerType, ownerId];
  const list = useQuery({ queryKey: key, queryFn: () => api.get<Attachment[]>('/attachments', { owner_type: ownerType, owner_id: ownerId }) });

  const upload = useSave(
    async (files: File[]) => {
      for (const f of files) {
        const body = new FormData();
        body.append('owner_type', ownerType);
        body.append('owner_id', String(ownerId));
        body.append('file', await shrinkImage(f));
        await api.post('/attachments', body);
      }
    },
    { success: 'Uploaded', onSuccess: () => qc.invalidateQueries({ queryKey: key }) },
  );
  const remove = useSave((id: number) => api.del(`/attachments/${id}`), { success: 'Deleted', onSuccess: () => qc.invalidateQueries({ queryKey: key }) });

  const rows = list.data ?? [];
  const images = rows.filter((a) => a.thumb_url);
  const pdfs = rows.filter((a) => !a.thumb_url);

  return (
    <Card
      size="small"
      title="Attachments"
      className="no-print"
      extra={
        can('accountant', 'staff') && (
          <>
            <input
              ref={input}
              type="file"
              accept="image/*,application/pdf"
              multiple
              hidden
              onChange={(e) => {
                const files = Array.from(e.target.files ?? []);
                e.target.value = '';
                if (files.length) upload.mutate(files);
              }}
            />
            <Button size="small" icon={<CameraOutlined />} loading={upload.isPending} onClick={() => input.current?.click()}>
              Add photo / PDF
            </Button>
          </>
        )
      }
    >
      {rows.length === 0 && <Typography.Text type="secondary">No attachments.</Typography.Text>}
      <Image.PreviewGroup>
        <div className="thumb-grid">
          {images.map((a) => (
            <div key={a.id} style={{ position: 'relative' }}>
              <Image src={a.thumb_url!} preview={{ src: a.medium_url ?? a.url }} width={96} height={96} style={{ objectFit: 'cover', borderRadius: 6 }} loading="lazy" alt={a.original_name} />
              {can('accountant') && (
                <Popconfirm title="Delete this attachment?" onConfirm={() => remove.mutate(a.id)}>
                  <Button size="small" danger type="primary" shape="circle" icon={<DeleteOutlined />} style={{ position: 'absolute', top: 2, right: 2 }} />
                </Popconfirm>
              )}
            </div>
          ))}
        </div>
      </Image.PreviewGroup>
      {pdfs.length > 0 && (
        <Space direction="vertical" style={{ marginTop: images.length ? 8 : 0 }}>
          {pdfs.map((a) => (
            <Space key={a.id}>
              <a href={a.url} target="_blank" rel="noreferrer"><FilePdfOutlined /> {a.original_name}</a>
              {can('accountant') && (
                <Popconfirm title="Delete this attachment?" onConfirm={() => remove.mutate(a.id)}>
                  <Button size="small" type="text" danger icon={<DeleteOutlined />} />
                </Popconfirm>
              )}
            </Space>
          ))}
        </Space>
      )}
    </Card>
  );
}
