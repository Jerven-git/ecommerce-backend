<?php

namespace App\Modules\ControlPlane\Application;

use App\Models\Activity;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ActivityQuery
{
    public function paginate(array $filters, ?int $storeId): LengthAwarePaginator
    {
        $query = Activity::with(['causer', 'subject', 'store'])
            ->when($storeId !== null, fn ($query) => $query->forStore($storeId))
            ->when(filled($filters['causer_id'] ?? null), fn ($query) => $query->where('causer_id', $filters['causer_id']))
            ->when(filled($filters['log_name'] ?? null), fn ($query) => $query->where('log_name', $filters['log_name']))
            ->when(filled($filters['event'] ?? null), fn ($query) => $query->where('event', $filters['event']))
            ->when(filled($filters['from'] ?? null), fn ($query) => $query->where('created_at', '>=', $filters['from']))
            ->when(filled($filters['to'] ?? null), fn ($query) => $query->where('created_at', '<=', $filters['to']))
            ->latest();
        $page = $query->paginate((int) ($filters['per_page'] ?? 25));
        $page->getCollection()->transform(fn (Activity $activity) => [
            'id' => $activity->id, 'log_name' => $activity->log_name,
            'description' => $activity->description, 'event' => $activity->event,
            'subject_type' => $activity->subject_type, 'subject_id' => $activity->subject_id,
            'causer' => $activity->causer ? [
                'id' => $activity->causer->id, 'name' => $activity->causer->name ?? null,
                'email' => $activity->causer->email ?? null,
            ] : null,
            'store' => $activity->store ? ['id' => $activity->store->id, 'name' => $activity->store->name] : null,
            'properties' => $activity->properties, 'created_at' => $activity->created_at,
        ]);

        return $page;
    }
}
