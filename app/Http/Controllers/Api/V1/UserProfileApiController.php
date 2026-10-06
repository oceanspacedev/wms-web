<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RequestWhatsAppOtpRequest;
use App\Http\Requests\Api\V1\UpdateUserProfilePhotoRequest;
use App\Http\Requests\Api\V1\UpdateUserProfileRequest;
use App\Http\Requests\Api\V1\VerifyWhatsAppOtpRequest;
use App\Http\Resources\Api\V1\AuthUserResource;
use App\Models\User;
use App\Models\WhatsappOtp;
use App\Services\WhatsAppOtpService;
use App\Support\WhatsAppNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class UserProfileApiController extends Controller
{
    /**
     * Get current authenticated user profile.
     */
    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->load('roles');

        return response()->json([
            'success' => true,
            'message' => 'Profil berhasil dimuat.',
            'data' => new AuthUserResource($user),
        ]);
    }

    /**
     * Update user profile (name, username, password).
     */
    public function update(UpdateUserProfileRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $validated = $request->validated();

        if (filled($validated['nama_lengkap'] ?? null)) {
            $user->name = trim((string) $validated['nama_lengkap']);
        } elseif (filled($validated['name'] ?? null)) {
            $user->name = trim((string) $validated['name']);
        }

        if (array_key_exists('username', $validated)) {
            $username = is_string($validated['username']) ? Str::lower(trim($validated['username'])) : null;
            $user->username = $username === '' ? null : $username;
        }

        if (filled($validated['password'] ?? null)) {
            $user->password = Hash::make((string) $validated['password']);
        }

        $user->save();
        $user->load('roles');

        return response()->json([
            'success' => true,
            'message' => 'Profil berhasil diperbarui.',
            'data' => new AuthUserResource($user),
        ]);
    }

    /**
     * Update user profile photo.
     */
    public function updatePhoto(UpdateUserProfilePhotoRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $file = $request->file('profile_photo') ?? $request->file('photo') ?? $request->file('avatar');

        if (! $file) {
            return response()->json([
                'success' => false,
                'message' => 'File foto profil tidak ditemukan.',
                'data' => null,
                'errors' => [
                    'profile_photo' => ['File foto profil tidak ditemukan.'],
                ],
            ], 422);
        }

        $path = $file->store('avatars', 'public');

        if ($user->avatar_url && ! str_starts_with($user->avatar_url, 'http://') && ! str_starts_with($user->avatar_url, 'https://')) {
            Storage::disk('public')->delete($user->avatar_url);
        }

        $user->avatar_url = $path;
        $user->save();
        $user->load('roles');

        return response()->json([
            'success' => true,
            'message' => 'Foto profil berhasil diperbarui.',
            'data' => new AuthUserResource($user),
        ]);
    }

    /**
     * Request OTP to update WhatsApp number.
     */
    public function requestWhatsAppOtp(RequestWhatsAppOtpRequest $request, WhatsAppOtpService $otpService): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $number = WhatsAppNumber::normalize((string) $request->input('whatsapp_number'));

        if (! $number) {
            return response()->json([
                'success' => false,
                'message' => 'Nomor WhatsApp tidak valid.',
                'data' => null,
                'errors' => [
                    'whatsapp_number' => ['Nomor WhatsApp tidak valid.'],
                ],
            ], 422);
        }

        $alreadyUsed = User::query()
            ->where('whatsapp_number', $number)
            ->where('id', '!=', $user->id)
            ->exists();

        if ($alreadyUsed) {
            return response()->json([
                'success' => false,
                'message' => 'Nomor WhatsApp sudah digunakan oleh akun lain.',
                'data' => null,
                'errors' => [
                    'whatsapp_number' => ['Nomor WhatsApp sudah digunakan oleh akun lain.'],
                ],
            ], 422);
        }

        $rateKey = 'api-profile-whatsapp-otp:'.$user->id;

        if (RateLimiter::tooManyAttempts($rateKey, 3)) {
            return response()->json([
                'success' => false,
                'message' => 'Terlalu banyak permintaan OTP. Coba lagi sebentar lagi.',
                'data' => null,
            ], 429);
        }

        RateLimiter::hit($rateKey, 300);

        try {
            $result = $otpService->issue($user, $number, WhatsappOtp::PURPOSE_UPDATE_WHATSAPP);
        } catch (\Throwable) {
            return response()->json([
                'success' => false,
                'message' => 'OTP belum bisa dikirim ke WhatsApp. Coba lagi sebentar lagi.',
                'data' => null,
            ], 502);
        }

        return response()->json([
            'success' => true,
            'message' => 'OTP berhasil dikirim ke nomor WhatsApp.',
            'data' => [
                'expires_in' => $result['expires_in'],
                'whatsapp_number' => WhatsAppNumber::mask($number),
            ],
        ]);
    }

    /**
     * Verify OTP and update WhatsApp number.
     */
    public function verifyWhatsAppOtp(VerifyWhatsAppOtpRequest $request, WhatsAppOtpService $otpService): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $number = WhatsAppNumber::normalize((string) $request->input('whatsapp_number'));

        if (! $number) {
            return response()->json([
                'success' => false,
                'message' => 'Nomor WhatsApp tidak valid.',
                'data' => null,
                'errors' => [
                    'whatsapp_number' => ['Nomor WhatsApp tidak valid.'],
                ],
            ], 422);
        }

        $alreadyUsed = User::query()
            ->where('whatsapp_number', $number)
            ->where('id', '!=', $user->id)
            ->exists();

        if ($alreadyUsed) {
            return response()->json([
                'success' => false,
                'message' => 'Nomor WhatsApp sudah digunakan oleh akun lain.',
                'data' => null,
                'errors' => [
                    'whatsapp_number' => ['Nomor WhatsApp sudah digunakan oleh akun lain.'],
                ],
            ], 422);
        }

        $otpRecord = $otpService->verify(
            $user,
            $number,
            WhatsappOtp::PURPOSE_UPDATE_WHATSAPP,
            $request->string('otp')->toString(),
        );

        if (! $otpRecord) {
            return response()->json([
                'success' => false,
                'message' => 'OTP tidak valid atau sudah kedaluwarsa.',
                'data' => null,
                'errors' => [
                    'otp' => ['OTP tidak valid atau sudah kedaluwarsa.'],
                ],
            ], 422);
        }

        $user->whatsapp_number = $number;
        $user->whatsapp_verified_at = now();
        $user->save();
        $user->load('roles');

        return response()->json([
            'success' => true,
            'message' => 'Nomor WhatsApp berhasil diperbarui.',
            'data' => new AuthUserResource($user),
        ]);
    }

    /**
     * Delete user account.
     */
    public function destroy(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        // Revoke all tokens
        $user->tokens()->delete();

        // Delete photo if stored on local disk
        if ($user->avatar_url && ! str_starts_with($user->avatar_url, 'http://') && ! str_starts_with($user->avatar_url, 'https://')) {
            Storage::disk('public')->delete($user->avatar_url);
        }

        $user->delete();

        return response()->json([
            'success' => true,
            'message' => 'Akun berhasil dihapus.',
            'data' => null,
        ]);
    }
}
