# Realtime Module

Real-time broadcasting module using Laravel Reverb (WebSocket server). Pushes model changes and deployment notifications to connected frontend clients.

## Architecture

```
Model saves (Product, Category, etc.)
  -> BroadcastsChanges trait fires
  -> RealtimeService dispatches ModelChanged event (queued)
  -> Reverb broadcasts to "ssu.updates" channel
  -> Frontend receives: { model, id, action, data, timestamp }

Deployment (artisan command)
  -> RealtimeService writes version file + dispatches DeploymentNotification (immediate)
  -> Reverb broadcasts to "ssu.updates" channel
  -> Frontend receives: { version, timestamp }
```

## Directory Structure

```
Realtime/
├── Config/realtime.php              # Module config (enabled flag, channels, broadcast models)
├── Console/NotifyDeploymentCommand  # artisan realtime:notify-deployment
├── Events/
│   ├── BaseRealtimeEvent.php        # Abstract base (ShouldBroadcast)
│   ├── DeploymentNotification.php   # Deployment event (ShouldBroadcastNow)
│   └── ModelChanged.php             # Model CRUD event (queued)
├── Http/Controllers/
│   └── VersionController.php        # GET /api/version (polling fallback)
├── Providers/RealtimeServiceProvider.php
├── Routes/
│   ├── api.php                      # Version endpoint
│   └── channels.php                 # Channel auth definitions
├── Services/RealtimeService.php     # Core service (singleton)
└── Traits/BroadcastsChanges.php     # Attach to any Eloquent model
```

## Setup

### 1. Generate Reverb Credentials

Run this command **twice** in your terminal to generate a key and secret:

```bash
openssl rand -hex 16
```

Use the first output for `REVERB_APP_KEY` and the second for `REVERB_APP_SECRET`.
For `REVERB_APP_ID`, use any unique identifier (e.g. `openssl rand -hex 6`).

### 2. Configure Environment

Add these to your `.env`:

```env
BROADCAST_CONNECTION=reverb
QUEUE_CONNECTION=redis

# Reverb WebSocket Server
REVERB_APP_ID=<your-generated-id>
REVERB_APP_KEY=<your-generated-key>
REVERB_APP_SECRET=<your-generated-secret>
REVERB_HOST=0.0.0.0
REVERB_PORT=8080
REVERB_SCHEME=http

REALTIME_ENABLED=true

# Frontend needs these to connect
VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="${REVERB_HOST}"
VITE_REVERB_PORT="${REVERB_PORT}"
VITE_REVERB_SCHEME="${REVERB_SCHEME}"
```

### 3. Start the Services

```bash
# Start the Reverb WebSocket server
php artisan reverb:start

# Start the queue worker (required for ModelChanged events)
php artisan queue:work
```

## Usage

### Broadcasting Model Changes

Add the `BroadcastsChanges` trait to any Eloquent model:

```php
use App\Modules\Realtime\Traits\BroadcastsChanges;

class Product extends Model
{
    use BroadcastsChanges;
}
```

The model is now automatically broadcast on `created`, `updated`, and `deleted` events.

To register the model in config, add it to `Config/realtime.php`:

```php
'broadcast_models' => [
    \App\Models\Product::class,
    \App\Models\SiteConfig::class,
    // add more models here
],
```

### Deployment Notifications

Notify connected clients of a new deployment:

```bash
# Auto-generates version from timestamp
php artisan realtime:notify-deployment

# Or specify a version
php artisan realtime:notify-deployment 1.2.3
```

### Version Polling (HTTP Fallback)

For clients that can't maintain a WebSocket connection:

```
GET /api/version
```

Returns:

```json
{
    "version": "1.2.3",
    "timestamp": "2026-03-27T12:00:00.000000Z"
}
```

## Channels

| Channel | Type | Auth | Purpose |
|---------|------|------|---------|
| `ssu.updates` | Public | None | Model changes + deployment notifications |
| `ssu.admin` | Private | `is_admin === true` | Admin-only events |

## Frontend Integration (Laravel Echo)

```js
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

const echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST,
    wsPort: import.meta.env.VITE_REVERB_PORT,
    wssPort: import.meta.env.VITE_REVERB_PORT,
    forceTLS: import.meta.env.VITE_REVERB_SCHEME === 'https',
    enabledTransports: ['ws', 'wss'],
});

// Listen for model changes
echo.channel('ssu.updates')
    .listen('.model.changed', (e) => {
        console.log(e.model, e.action, e.data);
        // e.g. { model: "Product", id: 5, action: "updated", data: {...}, timestamp: "..." }
    })
    .listen('.deployment.new', (e) => {
        console.log('New deployment:', e.version);
        // Prompt user to refresh
    });
```

## Production Deployment

### Required

- **Generate unique credentials** per environment (local/staging/production)
- **TLS**: Either set `REVERB_SCHEME=https` with certs, or terminate TLS at your reverse proxy (nginx/Caddy)
- **Redis queue**: Ensure `QUEUE_CONNECTION=redis` (not `database`)
- **Rate limiting**: Enabled by default in `config/reverb.php` (30 requests per 60s, terminates on limit)
- **Max connections**: Defaults to 10,000, adjust via `REVERB_APP_MAX_CONNECTIONS`

### Multi-Instance Scaling

If running multiple Reverb server instances behind a load balancer:

```env
REVERB_SCALING_ENABLED=true
```

This uses Redis pub/sub to sync events across instances.

### Nginx Reverse Proxy Example

```nginx
location /app {
    proxy_pass http://127.0.0.1:8080;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_read_timeout 60s;
    proxy_send_timeout 60s;
}
```

### Health Monitoring

Monitor the Reverb process with your process manager (Supervisor, systemd).

Example Supervisor config:

```ini
[program:reverb]
command=php /path/to/artisan reverb:start
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/log/reverb.log
```

## Error Handling

- **Broadcast failures** are caught silently — a failed broadcast will never break a model save. Failures are logged as warnings.
- **Version file errors** are caught and logged. Falls back to `config('app.version')` if the file is unreadable.
- **Reverb downtime** does not affect your application. Model operations continue normally; events are simply lost until Reverb recovers.

## Disabling

Set `REALTIME_ENABLED=false` in your `.env`. This disables all model broadcasting and skips event dispatch entirely. The version endpoint (`/api/version`) remains available.
