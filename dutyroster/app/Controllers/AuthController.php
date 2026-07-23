<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Auth;

class AuthController extends Controller
{
    public function showLogin(): void
    {
        if (Auth::check()) {
            $this->redirect('dashboard');
        }
        $this->view('auth/login', [], 'blank');
    }

    public function login(): void
    {
        $this->verifyCsrf();
        $user = $this->input('username', '');
        $pass = $_POST['password'] ?? '';
        if (Auth::attempt($user, $pass)) {
            $this->redirect('dashboard');
        }
        $this->flash('error', 'Invalid username or password.');
        $this->redirect('login');
    }

    public function logout(): void
    {
        Auth::logout();
        $this->redirect('login');
    }
}
