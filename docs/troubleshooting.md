# Troubleshooting

## Directus 503 /server/health
Причина: нет прав на /directus/uploads

Фикс:
```bash
chown -R 1000:1000 docker/volumes/directus_uploads
chmod -R 775 docker/volumes/directus_uploads
docker restart vse_directus