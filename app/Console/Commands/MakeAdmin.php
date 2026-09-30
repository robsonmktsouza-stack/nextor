<?php
namespace App\Console\Commands;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Facades\Validator;
class MakeAdmin extends Command {
    protected $signature='erp:make-admin {name?} {email?}';
    protected $description='Criar usuário administrador local, sem HUB ou cadastro público';
    public function handle(): int {
        $name=$this->argument('name') ?: $this->ask('Nome');
        $email=$this->argument('email') ?: $this->ask('E-mail');
        $password=$this->secret('Senha (mínimo de 8 caracteres)');
        $validation=Validator::make(compact('name','email','password'),[
            'name'=>['required','string','max:190'], 'email'=>['required','email','unique:users,email'],
            'password'=>['required','min:8'],
        ]);
        if ($validation->fails()) { foreach($validation->errors()->all() as $e) $this->error($e); return self::FAILURE; }
        User::create(['name'=>$name,'email'=>$email,'password'=>Hash::make($password)]);
        $this->info('Usuário criado. Faça login em /login.');
        return self::SUCCESS;
    }
}
