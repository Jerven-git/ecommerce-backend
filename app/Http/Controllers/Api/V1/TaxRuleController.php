<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\TaxRule;
use Illuminate\Http\Request;

class TaxRuleController extends Controller
{
    public function index()
    {
        $rules = TaxRule::orderBy('country')
            ->orderBy('state')
            ->orderByDesc('priority')
            ->get();

        return response()->json(['data' => $rules]);
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

        // Auto-set priority based on region type
        $validated['priority'] = match ($validated['region_type']) {
            'state' => 2,
            'country' => 1,
            'all' => 0,
        };

        // Clear state for non-state rules, clear country for all-region rules
        if ($validated['region_type'] === 'all') {
            $validated['country'] = null;
            $validated['state'] = null;
        } elseif ($validated['region_type'] === 'country') {
            $validated['state'] = null;
        }

        $rule = TaxRule::create($validated);

        return response()->json([
            'message' => 'Tax rule created successfully',
            'data' => $rule,
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $rule = TaxRule::findOrFail($id);

        $validated = $request->validate([
            'region_type' => 'sometimes|in:all,country,state',
            'country' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'tax_rate' => 'sometimes|numeric|min:0|max:100',
            'tax_name' => 'sometimes|string|max:50',
            'tax_display_mode' => 'sometimes|in:inclusive,exclusive',
            'enabled' => 'sometimes|boolean',
        ]);

        // Update priority if region type changed
        if (isset($validated['region_type'])) {
            $validated['priority'] = match ($validated['region_type']) {
                'state' => 2,
                'country' => 1,
                'all' => 0,
            };
        }

        $rule->update($validated);

        return response()->json([
            'message' => 'Tax rule updated successfully',
            'data' => $rule,
        ]);
    }

    public function destroy($id)
    {
        $rule = TaxRule::findOrFail($id);
        $rule->delete();

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
            'rules.*.id' => 'nullable|integer|exists:tax_rules,id',
            'rules.*.region_type' => 'required|in:all,country,state',
            'rules.*.country' => 'nullable|string|max:100',
            'rules.*.state' => 'nullable|string|max:100',
            'rules.*.tax_rate' => 'required|numeric|min:0|max:100',
            'rules.*.tax_name' => 'required|string|max:50',
            'rules.*.tax_display_mode' => 'required|in:inclusive,exclusive',
            'rules.*.enabled' => 'boolean',
        ]);

        $incomingIds = collect($validated['rules'])->pluck('id')->filter()->toArray();

        // Delete rules not in the incoming list
        TaxRule::whereNotIn('id', $incomingIds)->delete();

        $rules = [];
        foreach ($validated['rules'] as $ruleData) {
            $ruleData['priority'] = match ($ruleData['region_type']) {
                'state' => 2,
                'country' => 1,
                'all' => 0,
            };

            if ($ruleData['region_type'] === 'all') {
                $ruleData['country'] = null;
                $ruleData['state'] = null;
            } elseif ($ruleData['region_type'] === 'country') {
                $ruleData['state'] = null;
            }

            if (!empty($ruleData['id'])) {
                $rule = TaxRule::find($ruleData['id']);
                if ($rule) {
                    $rule->update($ruleData);
                    $rules[] = $rule;
                    continue;
                }
            }

            unset($ruleData['id']);
            $rules[] = TaxRule::create($ruleData);
        }

        return response()->json([
            'message' => 'Tax rules synced successfully',
            'data' => $rules,
        ]);
    }
}
