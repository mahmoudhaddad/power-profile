# Scalability Notes — Power Profile

## Current State
- **Database**: SQLite — suitable for single-user, projects up to ~50 buildings.
- **Architecture**: 56 stateless API endpoints behind Laravel Sanctum token auth.

## Scaling the Database
Change `DATABASE_CONNECTION=pgsql` in `.env` — no code changes needed.
All queries use Eloquent ORM which is provider-agnostic.

## Caching
Add `CACHE_DRIVER=redis` for:
- **NASA POWER results**: already cached 30 days per coordinate + month.
- **Dispatch simulation output**: for projects with more than 20 buildings,
  cache the daily dispatch result keyed by `project_id + month + day + mode`.

## Query Strategy
All calculation endpoints (schedule, financial, cost signal, phase balance)
use eager loading to prevent N+1 problems. Example pattern used:

```php
$project->buildings()->with([
    'components.componentType',
    'floors.components.componentType',
    'floors.rooms.components.componentType',
])->get();
```

Without eager loading, a project with 10 buildings × 5 floors × 10 rooms
generates 500+ individual database queries per request.

## Horizontal Scaling
All API endpoints are stateless — horizontal scaling behind a load balancer
is straightforward. Session state lives in Sanctum tokens, not server memory.
