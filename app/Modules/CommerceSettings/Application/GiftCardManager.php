<?php

namespace App\Modules\CommerceSettings\Application;

use App\Models\GiftCard;
use App\Models\GiftCardDenomination;
use App\Models\SiteConfig;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class GiftCardManager
{
    public function moduleEnabled(): bool
    {
        return (bool) ((SiteConfig::first()?->modules_enabled ?? [])['gift_cards'] ?? false);
    }

    public function enabledDenominations(): Collection
    {
        return GiftCardDenomination::enabled()->get();
    }

    public function denominations(): Collection
    {
        return GiftCardDenomination::orderBy('sort_order')->orderBy('amount')->get();
    }

    public function createDenomination(array $attributes): GiftCardDenomination
    {
        return GiftCardDenomination::create($attributes);
    }

    public function updateDenomination(GiftCardDenomination $denomination, array $attributes): GiftCardDenomination
    {
        $denomination->update($attributes);

        return $denomination->fresh();
    }

    public function deleteDenomination(GiftCardDenomination $denomination): void
    {
        $denomination->delete();
    }

    public function findUsable(string $code): ?GiftCard
    {
        $card = GiftCard::where('code', strtoupper(trim($code)))->first();

        return $card?->isUsable() ? $card : null;
    }

    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = GiftCard::with('order')->latest();

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['search'])) {
            $term = $filters['search'];
            $query->where(fn ($query) => $query
                ->where('code', 'like', "%{$term}%")
                ->orWhere('recipient_email', 'like', "%{$term}%")
                ->orWhere('purchaser_email', 'like', "%{$term}%"));
        }

        return $query->paginate(min((int) ($filters['per_page'] ?? 20), 100));
    }

    public function issue(array $attributes): GiftCard
    {
        return GiftCard::create([
            'code' => GiftCard::generateCode(),
            'original_amount' => $attributes['amount'],
            'balance' => $attributes['amount'],
            'currency' => strtoupper($attributes['currency'] ?? 'USD'),
            'purchaser_name' => $attributes['purchaser_name'],
            'purchaser_email' => $attributes['purchaser_email'],
            'recipient_name' => $attributes['recipient_name'] ?? null,
            'recipient_email' => $attributes['recipient_email'],
            'message' => $attributes['message'] ?? null,
            'status' => GiftCard::STATUS_ACTIVE,
            'activated_at' => now(),
        ]);
    }

    public function update(GiftCard $giftCard, array $attributes): GiftCard
    {
        $giftCard->update($attributes);

        return $giftCard->fresh();
    }
}
