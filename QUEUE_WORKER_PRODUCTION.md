# Production lead-import worker

Lead uploads are placed on the `lead-import` database queue and return `202` immediately. A persistent worker must consume that queue.

Before starting the worker, verify that the production database contains the Laravel `cache`, `cache_locks`, `jobs`, and `failed_jobs` tables. The worker checks the cache store before it reads jobs. If the cache table is missing, jobs remain queued and the Leads page will stay in the preparing state.

```bash
cd /var/www/leox24-backend
php artisan migrate --force
php artisan config:cache
```

Install Supervisor if necessary, then install the supplied configuration:

```bash
sudo apt-get install -y supervisor
sudo cp deploy/supervisor/leox24-queue-worker.conf /etc/supervisor/conf.d/leox24-queue-worker.conf
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl restart leox24-queue-worker
sudo supervisorctl status leox24-queue-worker
```

The configuration assumes the application is at `/var/www/leox24-backend`, PHP is `/usr/bin/php`, and the application files are readable by `www-data`. Adjust those values if the server uses different paths or ownership.

Useful diagnostics:

```bash
php artisan queue:failed
tail -f storage/logs/laravel.log /var/log/supervisor/leox24-queue-worker.log
```

Do not run seeders or any command that resets the database. This worker setup does not modify user credentials.
