<?php
/**
 * UniGo - authentication: sign in, sign out and self-registration.
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\ErrorHandler;
use App\Core\Flash;
use App\Core\Validator;
use App\Models\UserModel;

final class AuthController extends Controller
{
    public function showLogin(): void
    {
        $this->requireGuest();
        $this->view('auth/login', [
            'title' => 'Sign in - ' . app_name(),
        ], 'layouts/auth');
    }

    public function login(): void
    {
        $this->requireGuest();
        $this->verifyCsrf();

        $email    = strtolower(trim($this->request->str('email')));
        $password = (string) $this->request->input('password', '');
        $remember = $this->request->bool('remember');

        if ($email === '' || $password === '') {
            Flash::withInput(['email' => $email], ['email' => 'Enter your email and password.']);
            Flash::error('Please enter your email and password.');
            $this->redirect('/login');
        }

        $result = Auth::attempt($email, $password, $remember);

        if (!$result['ok']) {
            Flash::withInput(['email' => $email], ['email' => (string) $result['message']]);
            Flash::error((string) $result['message']);
            $this->redirect('/login');
        }

        Flash::success('Welcome back, ' . Auth::firstName() . '.');
        $this->returnToTrip();
    }

    public function showRegister(): void
    {
        $this->requireGuest();
        $this->view('auth/register', [
            'title' => 'Create account - ' . app_name(),
        ], 'layouts/auth');
    }

    public function register(): void
    {
        $this->requireGuest();
        $this->verifyCsrf();

        $input = [
            'first_name' => trim($this->request->str('first_name')),
            'last_name'  => trim($this->request->str('last_name')),
            'email'      => strtolower(trim($this->request->str('email'))),
            'phone'      => trim($this->request->str('phone')),
            'city'       => trim($this->request->str('city')),
            'password'   => (string) $this->request->input('password', ''),
            'password_confirmation' => (string) $this->request->input('password_confirmation', ''),
        ];

        $validator = Validator::make($input);
        $ok = $validator->validate([
            'first_name' => 'required|min:2|max:60',
            'last_name'  => 'required|min:2|max:60',
            'email'      => 'required|email|max:150|unique:users,email',
            'phone'      => 'required|phone',
            'city'       => 'max:80',
            'password'   => 'required|password|confirmed',
        ], [
            'first_name' => 'First name',
            'last_name'  => 'Last name',
            'password'   => 'Password',
        ]);

        if (!$ok) {
            $errors = $validator->errors();
            Flash::withInput($input, $errors);
            Flash::error($validator->firstError() ?? 'Please correct the highlighted fields.');
            $this->redirect('/register');
        }

        try {
            $userId = (new UserModel())->createUser(
                [
                    'email'         => $input['email'],
                    'password_hash' => Auth::hashPassword($input['password']),
                    'first_name'    => $input['first_name'],
                    'last_name'     => $input['last_name'],
                    'phone'         => $input['phone'],
                    'status'        => 'active',
                ],
                ['passenger'],
                [
                    'city'              => $input['city'],
                    'preferred_payment' => 'mobile_money',
                ],
                'passengers'
            );
        } catch (\Throwable $e) {
            ErrorHandler::log('error', 'Registration failed: ' . $e->getMessage());
            Flash::withInput($input, []);
            Flash::error('We could not create your account right now. Please try again.');
            $this->redirect('/register');
        }

        $user = (new UserModel())->findWithRoles($userId);
        if ($user !== null) {
            Auth::login($user);
        }

        Csrf::rotate();
        Flash::success('Your account is ready. Welcome to ' . app_name() . '!');
        $this->returnToTrip();
    }

    private function returnToTrip(): void
    {
        $intended = (string) ($_SESSION['_intended'] ?? '');
        unset($_SESSION['_intended']);
        if (str_starts_with($intended, '/') && !str_starts_with($intended, '//') && !str_contains($intended, '\\') && !preg_match('/[\r\n]/', $intended) && preg_match('#/trips/[0-9]+$#', $intended)) {
            \App\Core\Http::redirect($intended);
        }
        $this->redirect(Auth::homeRoute());
    }

    public function showForgot(): void
    {
        $this->requireGuest();
        $this->view('auth/forgot', [
            'title' => 'Reset password - ' . app_name(),
        ], 'layouts/auth');
    }

    public function sendReset(): void
    {
        $this->requireGuest();
        $this->verifyCsrf();

        // Password reset delivery is out of scope for the demonstration build.
        // We acknowledge the request without revealing whether the email exists.
        Flash::info('Email recovery is not configured. Please contact the system administrator for account recovery.');
        $this->redirect('/login');
    }

    public function showLogout(): void
    {
        if (!Auth::check()) {
            $this->redirect('/');
        }
        $this->view('auth/logout', ['title' => 'Sign out - ' . app_name()], 'layouts/auth');
    }

    public function logout(): void
    {
        $this->verifyCsrf();
        if (Auth::check()) {
            Auth::logout();
            Flash::success('You have been signed out.');
        }
        $this->redirect('/');
    }
}
