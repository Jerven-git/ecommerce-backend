<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Currency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CurrencyController extends Controller
{
    /**
     * Public list: only enabled currencies, ordered for the storefront picker.
     */
    public function index(): JsonResponse
    {
        $currencies = Currency::query()
            ->where('is_enabled', true)
            ->orderBy('sort_order')
            ->orderBy('code')
            ->get();

        return response()->json([
            'data' => $currencies,
            'base' => Currency::base()?->code,
        ]);
    }

    /**
     * Admin list: every currency, enabled or not.
     */
    public function adminIndex(): JsonResponse
    {
        $currencies = Currency::query()
            ->orderBy('sort_order')
            ->orderBy('code')
            ->get();

        return response()->json(['data' => $currencies]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->rules(creating: true));

        $currency = DB::transaction(function () use ($validated) {
            if (! empty($validated['is_base'])) {
                Currency::query()->where('is_base', true)->update(['is_base' => false]);
            }

            return Currency::create($validated);
        });

        return response()->json(['data' => $currency], 201);
    }

    public function update(Request $request, Currency $currency): JsonResponse
    {
        $validated = $request->validate($this->rules(creating: false, currencyId: $currency->id));

        DB::transaction(function () use ($validated, $currency): void {
            if (! empty($validated['is_base'])) {
                Currency::query()
                    ->where('is_base', true)
                    ->where('id', '!=', $currency->id)
                    ->update(['is_base' => false]);

                $validated['rate'] = 1;
            }

            $currency->update($validated);
        });

        return response()->json(['data' => $currency->fresh()]);
    }

    public function destroy(Currency $currency): JsonResponse
    {
        if ($currency->is_base) {
            return response()->json(['message' => 'Cannot delete the base currency.'], 422);
        }

        $currency->delete();

        return response()->json(['message' => 'Currency deleted.']);
    }

    /**
     * @return array<string, array<int, mixed>|string>
     */
    private function rules(bool $creating, ?int $currencyId = null): array
    {
        return [
            'code' => [
                $creating ? 'required' : 'sometimes',
                'string',
                'size:3',
                Rule::unique('currencies', 'code')->ignore($currencyId),
            ],
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'symbol' => [$creating ? 'required' : 'sometimes', 'string', 'max:8'],
            'symbol_position' => ['sometimes', Rule::in(['before', 'after'])],
            'decimal_places' => ['sometimes', 'integer', 'between:0,4'],
            'rate' => ['sometimes', 'numeric', 'gt:0'],
            'is_base' => ['sometimes', 'boolean'],
            'is_enabled' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
