<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdatePasswordRequest;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class PasswordController extends Controller
{
    public function edit(): View
    {
        return view('auth.password');
    }

    public function update(UpdatePasswordRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        DB::transaction(function () use ($request, $user): void {
            $user->password = $request->string('password')->toString();
            $user->setRememberToken(Str::random(60));
            $user->save();

            AuditLog::query()->create([
                'actor_id' => $user->id,
                'event' => 'account.password_changed',
                'auditable_type' => User::class,
                'auditable_id' => $user->id,
                'metadata' => ['session_others_invalidated' => true],
                'ip_address' => $request->ip(),
            ]);
        });

        $request->session()->regenerate();

        return to_route('password.edit')->with('status', 'Password berhasil diperbarui. Session pada perangkat lain telah dinonaktifkan.');
    }
}
