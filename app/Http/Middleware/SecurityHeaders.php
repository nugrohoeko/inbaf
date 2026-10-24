<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next)
    {
        if ($user = $request->user()) {
            $current = User::withTrashed()->find($user->id);
            if (! $current || ! $current->active || $current->activation_pending || $current->trashed()) {
                $this->invalidateSession($request);
                if ($request->is('api/*') || $request->expectsJson()) {
                    return $this->secure($request, response()->json(['message' => 'Akun ini tidak aktif. Silakan hubungi administrator.', 'code' => 'ACCOUNT_INACTIVE', 'csrf_token' => csrf_token()], 403));
                }
                abort(403, 'Akun ini tidak aktif.');
            }
            $version = $request->session()->get('user_session_version');
            // Sessions created before this feature can adopt the baseline once.
            // Any administrative change increments it, so an older session
            // without a snapshot must log in again after such a change.
            if (($version === null && (int) $current->session_version !== 1)
                || ($version !== null && (int) $version !== (int) $current->session_version)) {
                $this->invalidateSession($request);
                if ($request->is('api/*') || $request->expectsJson()) {
                    return $this->secure($request, response()->json(['message' => 'Sesi telah berakhir karena perubahan keamanan akun. Silakan masuk kembali.', 'code' => 'SESSION_INVALIDATED', 'csrf_token' => csrf_token()], 401));
                }
                return $this->secure($request, redirect('/login'));
            }
            if ($version === null) $request->session()->put('user_session_version', (int) $current->session_version);
            if ($current->must_change_password && ! $this->passwordChangeAllowed($request)) {
                $target = '/profile?tab=password&required=1';
                if ($request->is('api/*') || $request->expectsJson()) {
                    return $this->secure($request, response()->json(['message' => 'Ganti kata sandi awal sebelum melanjutkan.', 'code' => 'PASSWORD_CHANGE_REQUIRED', 'redirect' => $target], 403));
                }
                return $this->secure($request, redirect($target));
            }
        }
        return $this->secure($request, $next($request));
    }

    private function secure(Request $request, $response)
    {
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', $request->is('set-password') ? 'no-referrer' : 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(self), geolocation=()');
        // The SPA shell can contain a restored account view. Do not reuse it or
        // authenticated JSON after logout, including through browser history.
        $response->headers->set('Cache-Control', 'no-store, private');
        if (! app()->isLocal()) $response->headers->set('Content-Security-Policy', "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data: blob:; media-src 'self' blob:; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
        if ($request->isSecure()) $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        return $response;
    }

    private function invalidateSession(Request $request): void
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    private function passwordChangeAllowed(Request $request): bool
    {
        if ($request->isMethod('GET')) {
            return $request->is('api/bootstrap', 'api/account', 'api/account/photo', 'profile', 'admin/change-password', 'set-password');
        }
        if ($request->isMethod('PUT') && $request->is('api/account/password', 'api/preferences')) return true;
        // The original participant profile form can also change a password.
        if ($request->isMethod('PUT') && $request->is('api/profile') && $request->filled('password')) return true;

        return $request->isMethod('POST') && $request->is('api/logout', 'api/set-password', 'api/set-password/inspect');
    }
}
