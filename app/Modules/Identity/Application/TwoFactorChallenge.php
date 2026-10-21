<?php

namespace App\Modules\Identity\Application;

use App\Mail\TwoFactorCodeMail;
use App\Models\TwoFactorCode;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Throwable;

class TwoFactorChallenge
{
    public function send(User $user): void
    {
        TwoFactorCode::where('user_id', $user->id)->whereNull('used_at')->delete();
        $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $challenge = TwoFactorCode::create([
            'user_id' => $user->id,
            'code' => Hash::make($code),
            'expires_at' => now()->addMinutes(10),
        ]);

        try {
            Mail::to($user->email)->send(new TwoFactorCodeMail($code, $user->name));
        } catch (Throwable $exception) {
            $challenge->delete();
            throw $exception;
        }
    }

    public function consume(int $userId, string $code): bool
    {
        $challenge = TwoFactorCode::where('user_id', $userId)
            ->whereNull('used_at')->where('expires_at', '>', now())->latest()->first();

        if (! $challenge || ! Hash::check($code, $challenge->code)) {
            return false;
        }

        $challenge->update(['used_at' => now()]);

        return true;
    }
}
