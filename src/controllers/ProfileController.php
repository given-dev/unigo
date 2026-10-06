<?php
/**
 * UniGo - account profile.
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\ErrorHandler;
use App\Core\Flash;
use App\Core\Validator;
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
            'gender'        => $this->request->str('gender') ?: 'undisclosed',
        ];

        $validator = Validator::make($data);
        if (!$validator->validate([
            'first_name' => 'required|min:2|max:60',
            'last_name' => 'required|min:2|max:60',
            'phone' => 'required|phone',
            'national_id' => 'max:40',
        ]) || !in_array($data['gender'], ['male', 'female', 'other', 'undisclosed'], true)) {
            Flash::error($validator->firstError() ?? 'Choose a valid gender.');
            $this->redirect('/profile');
        }
        $dob = $data['date_of_birth'];
        $date = $dob ? \DateTimeImmutable::createFromFormat('!Y-m-d', $dob) : null;
        if ($dob && (!$date || $date->format('Y-m-d') !== $dob || $dob > date('Y-m-d'))) {
            Flash::error('Enter a valid date of birth that is not in the future.');
            $this->redirect('/profile');
        }

        try {
            (new UserModel())->updateProfile((int) Auth::id(), $data);
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

        $current = (string) $this->request->input('current_password', '');
        $new     = (string) $this->request->input('new_password', '');
        $confirm = (string) $this->request->input('new_password_confirmation', '');

        if (!Validator::isStrongPassword($new)) {
            Flash::error(Validator::passwordHint());
            $this->redirect('/profile');
        }

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
