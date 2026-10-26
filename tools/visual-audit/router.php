<?php
/** Developer-only GET router for an isolated synthetic SQLite visual audit. */
if (getenv('LUMERON_VISUAL_AUDIT') !== '1' || getenv('APP_ENV') !== 'testing'
    || getenv('DB_CONNECTION') !== 'sqlite' || !str_starts_with((string)getenv('DB_DATABASE'), '/evidence/visual-standardization/')) {
    http_response_code(404); exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') { http_response_code(405); exit('Somente consulta.'); }
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
$asset=realpath('/app/public'.$path);
if ($path!=='/' && $asset && is_file($asset) && str_starts_with($asset,'/app/public/')) return false;
require '/app/vendor/autoload.php';
$app=require '/app/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.visual-audit-absent');
$app->useStoragePath('/evidence/visual-standardization/storage');
$request=Illuminate\Http\Request::capture();$app->instance('request',$request);
$kernel=$app->make(Illuminate\Contracts\Http\Kernel::class);$kernel->bootstrap();
$auditViews=[];
Illuminate\Support\Facades\View::composer('*',function($view)use(&$auditViews){$auditViews[$view->name()]=true;});
if (!in_array($path,['/login','/forgot-password'],true) && !str_starts_with($path,'/reset-password/')) {
    $user=App\Models\User::query()->where('email','visual@example.test')->firstOrFail();
    Illuminate\Support\Facades\Auth::setUser($user);
    $request->setUserResolver(fn()=>$user);
}
// Preview standalone error templates without changing application routes or licensing.
if ($path==='/__visual-audit/license-blocked' || $path==='/__visual-audit/session-expired') {
    $html=$path==='/__visual-audit/license-blocked' ? view('errors.license-blocked')->render()
        : view('errors.message',['status'=>419,'message'=>'Sua sessão expirou. Entre novamente para continuar.'])->render();
    header('X-Visual-Audit-Views: '.implode(',',array_keys($auditViews)));echo $html;exit;
}
$response=$kernel->handle($request);
$response->headers->set('X-Visual-Audit-Views',implode(',',array_keys($auditViews)));
$response->send();$kernel->terminate($request,$response);
