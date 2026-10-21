<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\CommerceSettings\Application\TaxRuleManager;
use App\Support\Tenancy\CurrentStore;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TaxRuleController extends Controller
{
    public function __construct(private readonly TaxRuleManager $rules) {}

    public function index()
    {
        return response()->json(['data' => $this->rules->all()]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'region_type' => 'required|in:all,country,state',
            'country' => 'nullable|required_if:region_type,country|required_if:region_type,state|string|max:100',
            'state' => 'nullable|required_if:region_type,state|string|max:100',
            'tax_rate' => 'required|numeric|min:0|max:100',
            'tax_name' => 'required|string|max:50',
            'tax_display_mode' => 'required|in:inclusive,exclusive',
            'enabled' => 'boolean',
        ]);

        $rule = $this->rules->create($validated);

        return response()->json([
            'message' => 'Tax rule created successfully',
            'data' => $rule,
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'region_type' => 'sometimes|in:all,country,state',
            'country' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'tax_rate' => 'sometimes|numeric|min:0|max:100',
            'tax_name' => 'sometimes|string|max:50',
            'tax_display_mode' => 'sometimes|in:inclusive,exclusive',
            'enabled' => 'sometimes|boolean',
        ]);

        $rule = $this->rules->update((int) $id, $validated);

        return response()->json([
            'message' => 'Tax rule updated successfully',
            'data' => $rule,
        ]);
    }

    public function destroy($id)
    {
        $this->rules->delete((int) $id);

        return response()->json([
            'message' => 'Tax rule deleted successfully',
        ]);
    }

    /**
     * Bulk update/sync all tax rules at once (used by admin settings page).
     */
    public function sync(Request $request)
    {
        $validated = $request->validate([
            'rules' => 'present|array',
            'rules.*.id' => [
                'nullable',
                'integer',
                Rule::exists('tax_rules', 'id')->where(
                    'store_id',
                    app(CurrentStore::class)->id(),
                ),
            ],
            'rules.*.region_type' => 'required|in:all,country,state',
            'rules.*.country' => 'nullable|string|max:100',
            'rules.*.state' => 'nullable|string|max:100',
            'rules.*.tax_rate' => 'required|numeric|min:0|max:100',
            'rules.*.tax_name' => 'required|string|max:50',
            'rules.*.tax_display_mode' => 'required|in:inclusive,exclusive',
            'rules.*.enabled' => 'boolean',
        ]);

        $rules = $this->rules->sync($validated['rules']);

        return response()->json([
            'message' => 'Tax rules synced successfully',
            'data' => $rules,
        ]);
    }
}
