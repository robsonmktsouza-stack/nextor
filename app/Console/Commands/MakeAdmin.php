<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

final class MakeAdmin extends Command
{
    protected $signature='erp:make-admin {name?} {email?} {username?}';
    protected $description='Criar administrador na própria instalação Lumeron';

    public function handle(): int
    {
        $name=$this->argument('name') ?: $this->ask('Nome');
        $email=$this->argument('email') ?: $this->ask('E-mail');
        $username=strtolower(trim((string)($this->argument('username') ?: $this->ask('Usuário de acesso'))));
        $password=$this->secret('Senha (mínimo de 8 caracteres)');

        $validator=Validator::make(compact('name','email','username','password'),[
            'name'=>['required','string','max:190'],
            'email'=>['required','email','unique:users,email'],
            'username'=>['required','string','min:3','max:50','regex:/^[a-z][a-z0-9._-]*$/','unique:users,username'],
            'password'=>['required','string','min:8'],
        ]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) $this->error($error);
            return self::FAILURE;
        }
        User::query()->create([
            'name'=>$name,
            'email'=>strtolower(trim($email)),
            'username'=>$username,
            'password'=>Hash::make($password),
        ]);
        $this->info('Administrador criado. Faça login usando subdomínio, usuário e senha.');
        return self::SUCCESS;
    }
}
