<?php

namespace Lara\Http\Controllers\Auth;

use Lara\User;
use Lara\Person;
use Lara\Section;

use Lara\Http\Controllers\Controller;
use Illuminate\Support\Facades\Validator;
use Illuminate\Foundation\Auth\RegistersUsers;

class RegisterController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Register Controller
    |--------------------------------------------------------------------------
    |
    | This controller handles the registration of new users as well as their
    | validation and creation. By default this controller uses a trait to
    | provide this functionality without requiring any additional code.
    |
    */

    use RegistersUsers;

    /**
     * Where to redirect users after registration.
     *
     * @var string
     */
    protected $redirectTo = '/';

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('auth');
    }

    /**
     * Get a validator for an incoming registration request.
     *
     * @param  array  $data
     * @return \Illuminate\Contracts\Validation\Validator
     */
    protected function validator(array $data)
    {
        return Validator::make($data, [
            'name' => 'required|max:255',
            'email' => 'required|email|max:255|unique:users',
            'password' => 'required|min:6|confirmed',
            'section' => 'required'
        ]);
    }

    /**
     * Create a new user instance after a valid registration.
     *
     * @param  array  $data
     * @return User
     */
    protected function create(array $data)
    {
        // workaround, since most of the legacy depends on an LDAP id being present
        $newLDAPId = Person::max('prsn_ldap_id') + 1;
        $person = new Person([
            'prsn_name' => $data['name'],
            'prsn_ldap_id' => $newLDAPId,
            'prsn_status' => 'member',
            'clb_id' => Section::find($data['section'])->club()->id,
            'prsn_uid' => hash("sha512", uniqid())
        ]);
        $person->save();

        return User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => bcrypt($data['password']),
            // default to member, can change permissions later
            'status' => 'member',
            'section_id' => $data['section'],
            'group' => Section::find($data['section'])->title,
            'person_id' => $person->id
        ]);
    }
}
