<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Currency;
use App\Modules\CommerceSettings\Application\CurrencyManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CurrencyController extends Controller
{
    public function __construct(private readonly CurrencyManager $currencies) {}

    /**
     * Public list: only enabled currencies, ordered for the storefront picker.
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => $this->currencies->enabled(),
            'base' => $this->currencies->baseCode(),
        ]);
    }

    /**
     * Admin list: every currency, enabled or not.
     */
    public function adminIndex(): JsonResponse
    {
        return response()->json(['data' => $this->currencies->all()]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->rules(creating: true));

        $currency = $this->currencies->create($validated);

        return response()->json(['data' => $currency], 201);
    }

    public function update(Request $request, Currency $currency): JsonResponse
    {
        $validated = $request->validate($this->rules(creating: false, currencyId: $currency->id));

        return response()->json(['data' => $this->currencies->update($currency, $validated)]);
    }

    public function destroy(Currency $currency): JsonResponse
    {
        if (! $this->currencies->delete($currency)) {
            return response()->json(['message' => 'Cannot delete the base currency.'], 422);
        }

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
