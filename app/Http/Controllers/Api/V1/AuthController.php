<?php

namespace App\Http\Controllers\Api\V1;

use App\Filament\Auth\Concerns\InteractsWithWhatsAppLogin;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PasswordLoginRequest;
use App\Http\Requests\Api\V1\VerifyWhatsAppLoginRequest;
use App\Http\Requests\Api\V1\WhatsAppLoginRequest;
use App\Http\Resources\Api\V1\AuthUserResource;
use App\Models\User;
use App\Models\WhatsappOtp;
use App\Services\WhatsAppOtpService;
use App\Support\WhatsAppNumber;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Filament\Facades\Filament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

#[Group('Auth', 'Login, sesi, dan data profil pengguna yang sedang masuk.', 1)]
class AuthController extends Controller
{
    use InteractsWithWhatsAppLogin;

    /**
     * @unauthenticated
     */
    #[Endpoint(
        title: 'Login dengan username atau email',
        description: 'Menerima `login` (username atau email) dan `password`. Mengembalikan `access_token` Bearer serta data user sesuai kolom tabel: id, username, name, email, avatar_url, whatsapp_number, plus role dan roles.',
    )]
    public function login(PasswordLoginRequest $request): JsonResponse
    {
        $login = trim($request->string('login')->toString());
        $field = filter_var($login, FILTER_VALIDATE_EMAIL) ? 'email' : 'username';
        $value = $field === 'username' ? Str::lower($login) : $login;

        $user = User::query()->where($field, $value)->first();
        $panel = Filament::getPanel('admin');

        if (
            ! $user
            || ! Hash::check($request->string('password')->toString(), $user->password)
            || ! $user->canAccessPanel($panel)
        ) {
            return $this->failure('Username, email, atau password salah.', 401);
        }

        return $this->tokenResponse($user, 'Login berhasil.');
    }

    /**
     * @unauthenticated
     */
    #[Endpoint(
        title: 'Minta OTP login WhatsApp',
        description: 'Mengirim OTP ke nomor WhatsApp yang sudah terdaftar. Response `data` berisi `expires_in` (detik) dan `whatsapp_number` yang sudah di-mask.',
    )]
    public function requestWhatsAppOtp(WhatsAppLoginRequest $request, WhatsAppOtpService $otpService): JsonResponse
    {
        $number = $this->normalizeWhatsAppNumber($request->input('whatsapp_number'));

        if (! $number) {
            return $this->failure('Nomor WhatsApp tidak valid.', 422, [
                'whatsapp_number' => ['Nomor WhatsApp tidak valid.'],
            ]);
        }

        $rateKey = 'api-whatsapp-otp:'.hash('sha256', $number);

        if (RateLimiter::tooManyAttempts($rateKey, 3)) {
            return $this->failure('Terlalu banyak permintaan OTP. Coba lagi sebentar lagi.', 429);
        }

        RateLimiter::hit($rateKey, 300);

        $user = $this->findEligibleWebUserByWhatsApp($number);

        if (! $user) {
            return $this->failure($this->unavailableWhatsAppMessage(), 422, [
                'whatsapp_number' => [$this->unavailableWhatsAppMessage()],
            ]);
        }

        try {
            $result = $otpService->issue($user, $number, WhatsappOtp::PURPOSE_LOGIN);
        } catch (\Throwable) {
            return $this->failure('OTP belum bisa dikirim ke WhatsApp. Coba lagi sebentar lagi.', 502);
        }

        return response()->json([
            'success' => true,
            'message' => 'OTP berhasil dikirim.',
            'data' => [
                'expires_in' => $result['expires_in'],
                'whatsapp_number' => WhatsAppNumber::mask($number),
            ],
        ]);
    }

    /**
     * @unauthenticated
     */
    #[Endpoint(
        title: 'Verifikasi OTP login WhatsApp',
        description: 'Menukar nomor WhatsApp dan OTP menjadi Bearer token plus data user yang sama seperti login password.',
    )]
    public function verifyWhatsAppOtp(VerifyWhatsAppLoginRequest $request, WhatsAppOtpService $otpService): JsonResponse
    {
        $number = $this->normalizeWhatsAppNumber($request->input('whatsapp_number'));
        $user = $number ? $this->findEligibleWebUserByWhatsApp($number) : null;

        if (! $user || ! $number) {
            return $this->failure($this->unavailableWhatsAppMessage(), 422, [
                'whatsapp_number' => [$this->unavailableWhatsAppMessage()],
            ]);
        }

        $otpRecord = $otpService->verify(
            $user,
            $number,
            WhatsappOtp::PURPOSE_LOGIN,
            $request->string('otp')->toString(),
        );

        if (! $otpRecord) {
            return $this->failure('OTP tidak valid atau sudah kedaluwarsa.', 422, [
                'otp' => ['OTP tidak valid atau sudah kedaluwarsa.'],
            ]);
        }

        return $this->tokenResponse($user, 'Login berhasil.');
    }

    #[Endpoint(
        title: 'Ambil profil pengguna yang sedang masuk',
        description: 'Membaca data akun dari token Bearer. Field `data` mengikuti kolom users: id, username, name, email, avatar_url, whatsapp_number, plus role (id + name) dan roles.',
    )]
    public function me(Request $request): JsonResponse
    {
        $request->user()->load('roles');

        return response()->json([
            'success' => true,
            'message' => 'Profil berhasil dimuat.',
            'data' => new AuthUserResource($request->user()),
        ]);
    }

    #[Endpoint(
        title: 'Logout dan cabut token',
        description: 'Menghapus token Sanctum yang sedang dipakai. Tidak mengembalikan data akun.',
    )]
    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()?->currentAccessToken();

        if ($token !== null && method_exists($token, 'delete')) {
            $token->delete();
        }

        return response()->json([
            'success' => true,
            'message' => 'Logout berhasil.',
            'data' => null,
        ]);
    }

    private function tokenResponse(User $user, string $message): JsonResponse
    {
        $user->load('roles');

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => [
                'access_token' => $user->createToken('tracking')->plainTextToken,
                'token_type' => 'Bearer',
                'user' => new AuthUserResource($user),
            ],
        ]);
    }

    /**
     * @param  array<string, array<int, string>>|null  $errors
     */
    private function failure(string $message, int $status, ?array $errors = null): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'data' => null,
            'errors' => $errors,
        ], $status);
    }
}
