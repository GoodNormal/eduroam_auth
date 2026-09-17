<?php

namespace AuthEduroam;

use Illuminate\Http\Request;
use App\Models\User;
use Auth;
use Cache;
use Illuminate\Contracts\Events\Dispatcher;
use App\Events;
use Carbon\Carbon;
use Illuminate\Routing\Controller;
use Blessing\Filter;
use Vectorface\Whip\Whip;
use App\Rules\Captcha;
use Session;

class LoginController extends Controller
{
    public function showLoginForm(Filter $filter)
    {
        $whip = new Whip();
        $ip = $whip->getValidIpAddress();
        $ip = $filter->apply('client_ip', $ip);
        $rows = [
            'AuthEduroam::rows.login.notice',
            'AuthEduroam::rows.login.form',
            'AuthEduroam::rows.login.extra'
        ];

        return view('AuthEduroam::login', [
            'rows' => $rows,
            'extra' => [
                'tooManyFails' => cache(sha1('login_fails_'.$ip)) > 3,
                'recaptcha' => option('recaptcha_sitekey'),
                'invisible' => (bool) option('recaptcha_invisible'),
            ],
        ]);
    }
    public function handleLogin(
        Request $request,
        Captcha $captcha,
        Dispatcher $dispatcher,
        Filter $filter
    ) {
        $data = $request->validate([
            'identification' => 'required|string',
            'password' => 'required|string',
        ]);
        $identification = $data['identification'];
        $password = $data['password'];

        $can = $filter->apply('can_login', null, [$identification, $password]);
        if ($can instanceof Rejection) {
            return json($can->getReason(), 1);
        }
        $authType = 'eduroam';
        $dispatcher->dispatch('auth.login.attempt', [$identification, $password, $authType]);
        event(new Events\UserTryToLogin($identification, $authType));
        
        $whip = new Whip();
        $ip = $whip->getValidIpAddress();
        $ip = $filter->apply('client_ip', $ip);
        $loginFailsCacheKey = sha1('login_fails_'.$ip);
        $loginFails = (int) Cache::get($loginFailsCacheKey, 0);

        if ($loginFails > 3) {
            $request->validate(['captcha' => ['required', $captcha]]);
        }

        if (null !== env('EDUROAM_HOST')) {
            $emailForAuthentication = $identification . '@' . env('EDUROAM_HOST');
            $emailForDatabase = $identification . '@' . (null !== env('EDUROAM_STORE_HOST') ? env('EDUROAM_HOST') : env('EDUROAM_HOST'));
        } else {
            $emailForAuthentication = $identification;
            $emailForDatabase = $identification;
        }

        $result = (new Authenticator())->authenticate($emailForAuthentication, $password);

        // 根据远程服务的响应处理登录逻辑
        if ($result === 'success') {
            Session::forget('login_fails');
            Cache::forget($loginFailsCacheKey);

            // 认证成功，检查用户是否存在
            $user = User::where('email', $emailForDatabase)->first();
            if (!$user) {
                // 用户不存在，创建新用户
                $user = new User();
                $user->email = $emailForDatabase;
                $user->nickname = $identification;
                $user->score = option('user_initial_score');
                $user->avatar = 0;
                $user->password = '';
                $user->ip = $ip;
                $user->permission = User::NORMAL;
                $user->register_at = Carbon::now();
                $user->last_sign_at = Carbon::now();
                $user->verified = true;
                $user->save();
                Auth::login($user, true);
                $dispatcher->dispatch('auth.registration.completed', [$user]);
            }
            $dispatcher->dispatch('auth.login.ready', [$user]);
            Auth::login($user, $request->input('keep'));
            $dispatcher->dispatch('auth.login.succeeded', [$user]);
            event(new Events\UserLoggedIn($user));
            return json(trans('auth.login.success'), 0, [
                'redirectTo' => $request->session()->pull('last_requested_path', url('/user')),
            ]);
        } else {
            $loginFails++;
            Cache::put($loginFailsCacheKey, $loginFails, 3600);
            $error_reason = trans('AuthEduroam::auth.eduroam.error.failure');

            if ($result === 'credential') {
                // 认证失败：用户名或密码错误
                $error_reason = trans('AuthEduroam::auth.eduroam.error.credential');
                $user = User::where('email', $emailForDatabase)->first();
                if(isset($user)){
                    $dispatcher->dispatch('auth.login.failed', [$user, $loginFails]);
                }
            } elseif (config('app.debug')) {
                $error_reason = trans('AuthEduroam::auth.eduroam.error.'.$result);
            }

            return json($error_reason, 1, [
                'login_fails' => $loginFails,
            ]);
        }
    }
}
