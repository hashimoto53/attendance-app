<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Http\Requests\LoginRequest;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\LoginResponse;
use Laravel\Fortify\Contracts\RegisterResponse;
use Laravel\Fortify\Contracts\VerifyEmailResponse;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(
            \Laravel\Fortify\Http\Requests\LoginRequest::class,
            LoginRequest::class
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Fortify::createUsersUsing(CreateNewUser::class);

        // 会員登録画面の指定
        Fortify::registerView(function () {
            return view('user.register');
        });

        // ログイン画面の指定
        Fortify::loginView(function () {
            return view('user.user-login');
        });

        // メール認証画面の指定（必要な場合）
        Fortify::verifyEmailView(function () {
            return view('auth.verify-email');
        });

        // ログイン試行制限（レートリミット）
        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        // 会員登録後はメール認証誘導画面へ
        $this->app->instance(RegisterResponse::class, new class implements RegisterResponse {
            public function toResponse($request)
            {
                return redirect()->route('verification.notice');
            }
        });

        // 認証リンクの完了後は打刻画面へ
        $this->app->instance(VerifyEmailResponse::class, new class implements VerifyEmailResponse {
            public function toResponse($request)
            {
                return redirect()->intended('/attendance');
            }
        });

        // ログイン後のリダイレクト先振り分け
        $this->app->instance(LoginResponse::class, new class implements LoginResponse {
            public function toResponse($request)
            {
                $user = auth()->user();

                // 管理者（role = 2）の場合は管理者画面へ
                if ($user->role === 2) {
                    return redirect('/admin/attendance/list');
                }

                // 未認証の一般ユーザーはメール認証誘導画面へ
                if ($user instanceof MustVerifyEmail && ! $user->hasVerifiedEmail()) {
                    return redirect()->route('verification.notice');
                }

                return redirect('/attendance');
            }
        });
    }
}