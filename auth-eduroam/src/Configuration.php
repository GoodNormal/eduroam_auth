<?php

namespace AuthEduroam;

use App\Models\User;
use Illuminate\Support\Str;

class Configuration
{
    public function render()
    {
        $info = null;
        $hosts = [
            'host' => env('EDUROAM_HOST'),
            'store_host' => env('EDUROAM_STORE_HOST'),
            'username' => trans('AuthEduroam::auth.eduroam.username'),
        ];
        if (env('EDUROAM_HOST')) {
            if (env('EDUROAM_STORE_HOST')) {
                $info = trans('AuthEduroam::config.host.set_with_store_host', $hosts);
            } else {
                $info = trans('AuthEduroam::config.host.set', $hosts);
            }
        } else {
            $info = trans('AuthEduroam::config.host.unset');
            if(env('EDUROAM_STORE_HOST')){
                $info = $info . trans('AuthEduroam::config.only_store_host');
            }
        }
        return view('AuthEduroam::config', [
            'info' => $info,
            'host' => (null !== env('EDUROAM_HOST')) ? env('EDUROAM_HOST') : trans('AuthEduroam::config.unset'),
            'store_host' => (null !== env('EDUROAM_STORE_HOST')) ? env('EDUROAM_STORE_HOST') : trans('AuthEduroam::config.unset'),
        ]);
    }
}
