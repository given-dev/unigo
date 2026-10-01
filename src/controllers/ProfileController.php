<?php
/**
 * UniGo - account profile.
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\ErrorHandler;
use App\Core\Flash;
use App\Models\UserModel;

final class ProfileController extends Controller
{
    /** /profile - edit the signed-in user's details. */
    public function edit(): void
    {
        $this->requireLogin();

        $user = $this->safe(static fn () => (new UserModel())->findWithRoles((int) Auth::id()), []);

        $this->view('profile/edit', [
            'title'     => 'My profile - ' . app_name(),
            'pageTitle' => 'My profile',
            'pageSub'   => 'Personal details and security',
            'user'      => $user,
            'roles'     => Auth::roles(),
        ], 'layouts/app');
    }

    /** POST /profile - update profile fields. */
    public function update(): void
    {
        $this->requireLogin();
        $this->verifyCsrf();

        $data = [
            'first_name'    => trim($this->request->str('first_name')),
            'last_name'     => trim($this->request->str('last_name')),
            'phone'         => trim($this->request->str('phone')),
            'national_id'   => trim($this->request->str('national_id')),
            'date_of_birth' => $this->request->str('date_of_birth') ?: null,
            'gender'        => $this->request->str('gender') ?: null,
        ];

        if ($data['first_name'] === '' || $data['last_name'] === '') {
            Flash::error('First and last name are required.');
            $this->redirect('/profile');
        }

        try {
            (new UserModel())->updateProfile((int) Auth::id(), array_filter($data, static fn ($v) => $v !== null));
            Flash::success('Profile updated.');
        } catch (\Throwable $e) {
            ErrorHandler::log('error', 'Profile update failed: ' . $e->getMessage());
            Flash::error('We could not save your profile. Please try again.');
        }

        $this->redirect('/profile');
    }

    /** POST /profile/password - change password. */
    public function password(): void
    {
        $this->requireLogin();
        $this->verifyCsrf();

        $current = $this->request->str('current_password');
        $new     = $this->request->str('new_password');
        $confirm = $this->request->str('new_password_confirmation');

        if ($new !== $confirm) {
            Flash::error('The new passwords do not match.');
            $this->redirect('/profile');
        }

        try {
            if (Auth::changePassword((int) Auth::id(), $current, $new)) {
                Flash::success('Password changed.');
            } else {
                Flash::error('Your current password is not correct.');
            }
        } catch (\Throwable $e) {
            ErrorHandler::log('error', 'Password change failed: ' . $e->getMessage());
            Flash::error('We could not change your password. Please try again.');
        }

        $this->redirect('/profile');
    }

    private function safe(callable $callback, $fallback)
    {
        try {
            return $callback();
        } catch (\Throwable $e) {
            ErrorHandler::log('warning', 'Profile data unavailable: ' . $e->getMessage());
            return $fallback;
        }
    }
}
