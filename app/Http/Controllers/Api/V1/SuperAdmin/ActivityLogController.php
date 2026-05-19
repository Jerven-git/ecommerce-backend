<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Support\Tenancy\CurrentStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
            'causer_id' => ['nullable', 'integer'],
            'log_name' => ['nullable', 'string', 'max:100'],
            'event' => ['nullable', 'string', 'max:50'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $user = $request->user();
        $isSuperAdmin = $user?->isSuperAdmin() ?? false;

        $query = Activity::query()->with(['causer', 'subject', 'store']);

        // Super admin can filter freely; store admin is locked to their own store.
        if ($isSuperAdmin) {
            if ($request->filled('store_id')) {
                $query->forStore((int) $request->input('store_id'));
            }
        } else {
            $query->forStore(app(CurrentStore::class)->id());
        }

        $query
            ->when($request->filled('causer_id'), fn ($q) => $q->where('causer_id', $request->input('causer_id')))
            ->when($request->filled('log_name'), fn ($q) => $q->where('log_name', $request->input('log_name')))
            ->when($request->filled('event'), fn ($q) => $q->where('event', $request->input('event')))
            ->when($request->filled('from'), fn ($q) => $q->where('created_at', '>=', $request->input('from')))
            ->when($request->filled('to'), fn ($q) => $q->where('created_at', '<=', $request->input('to')))
            ->latest();

        $perPage = (int) $request->input('per_page', 25);
        $paginated = $query->paginate($perPage);

        $paginated->getCollection()->transform(fn (Activity $a) => [
            'id' => $a->id,
            'log_name' => $a->log_name,
            'description' => $a->description,
            'event' => $a->event,
            'subject_type' => $a->subject_type,
            'subject_id' => $a->subject_id,
            'causer' => $a->causer ? [
                'id' => $a->causer->id,
                'name' => $a->causer->name ?? null,
                'email' => $a->causer->email ?? null,
            ] : null,
            'store' => $a->store ? [
                'id' => $a->store->id,
                'name' => $a->store->name,
            ] : null,
            'properties' => $a->properties,
            'created_at' => $a->created_at,
        ]);

        return response()->json($paginated);
    }
}
