<?php

return [
    // Central login portal talks only to login endpoints on each CNPJ's instance.
    'paths'=>['login','login/bootstrap'],
    'allowed_methods'=>['GET','POST','OPTIONS'],
    'allowed_origins'=>['https://'.env('LUMERON_LOGIN_PORTAL_HOST','login.lumeron.com.br')],
    'allowed_origins_patterns'=>[],
    'allowed_headers'=>['Content-Type','Accept','X-CSRF-TOKEN','X-Requested-With'],
    'exposed_headers'=>[],
    'max_age'=>0,
    'supports_credentials'=>true,
];
