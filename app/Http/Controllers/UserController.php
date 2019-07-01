<?php

namespace Lara\Http\Controllers;

use Auth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Input;
use Lara\Http\Middleware\ClOnly;
use Lara\Role;
use Lara\Section;
use Lara\Status;
use Lara\SurveyAnswer;
use Lara\User;
use Lara\UserSectionsRoleView;
use Lara\Utilities;
use Lara\utilities\RoleUtility;
use Log;
use Redirect;
use Session;
use View;

class UserController extends Controller
{
    const DELETED_PERSON = 'gelöschte Person';
    
    public function __construct()
    {
        $this->middleware(ClOnly::class, ['except' => ['agreePrivacy']]);
    }
    
    /**
     * Get a validator for an incoming registration request.
     *
     * @param array $data
     * @return \Illuminate\Contracts\Validation\Validator
     */
    protected function validator(User $user, array $data)
    {
        return \Validator::make($data, [
            'name'      => 'required|max:255',
            'givenname' => 'required|max:255',
            'lastname'  => 'required|max:255',
            'email'     => 'required|email|max:255|unique:users,id,'.$user->id,
            'section'   => [
                'required',
                Rule::in(
                    Section::all()->map(
                        function (Section $section) {
                            return $section->id;
                        }
                    )->toArray()
                ),
            ],
            'status'    => ['required', Rule::in(Status::ACTIVE)],
            'on_leave' => 'nullable|date'
        ]);
    }
    
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Contracts\View\View
     */
    public function index()
    {
        $sections = Section::query()->orderBy('title')->get();
        
        $userSectionsRoleViews = UserSectionsRoleView::with('section')->with('user')->with('user.section')->with('user.roles')->with('user.roles.section')
            ->get()
            ->sortBy(function (UserSectionsRoleView $userSectionsRoleView) {
                $user = $userSectionsRoleView->user;
                
                return sprintf('%-12s%s%s%s', Auth::user()->section->id != $user->section->id, $user->section->title,
                    $user->status, $user->name);
            });
        
        return View::make('user.index', compact('userSectionsRoleViews', 'sections'));
    }
    
    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        //
    }
    
    /**
     * Store a newly created resource in storage.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        //
    }
    
    /**
     * Display the specified resource.
     *
     * @param int $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        //
    }
    
    /**
     * Show the form for editing the specified resource.
     *
     * @param int $id
     * @return \Illuminate\Contracts\View\View
     */
    public function edit($id)
    {
        /** @var User $editUser */
        $user = User::findOrFail($id);
        
        $permissionsPersection = [];
        $sectionQuery = Section::query();
        if (!Auth::user()->isAn(RoleUtility::PRIVILEGE_ADMINISTRATOR)) {
            $sectionQuery->whereIn('id', Auth::user()->getSectionsIdForRoles(RoleUtility::PRIVILEGE_CL));
        }
        
        foreach ($sectionQuery->get() as $section) {
            $roles = Role::query()->where('section_id', '=', $section->id)->get();
            $permissionsPersection[$section->id] = $roles;
        }
        
        return View::make('user.edituser', compact('user', 'permissionsPersection'));
    }
    
    /**
     * Update the specified resource in storage.
     *
     * @param \Illuminate\Http\Request $request
     * @param int $id
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(Request $request, User $user)
    {
        //$this->authorize('update', $user);
        
        if (!Auth::user()->can('update', $user)) {
            Utilities::error(trans('mainLang.accessDenied'));
            
            return Redirect::back();
        }
        
        $user->fill($request->all())->save();
        
        $person = $user->person;
        $person->prsn_status = $user->status;
        $person->save();
        
        Utilities::success(trans('mainLang.update'));
        
        return Redirect::back();
    }
    
    /**
     * Remove the specified resource from storage.
     *
     * @param int $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        //
        /** @var User $user */
        $user = User::query()->findOrFail($id);
        if ($user->id == \Auth::user()->id) {
            Utilities::error(trans('mainLang.accessDenied'));
            
            return Redirect::back();
        }
        $person = $user->person;
        $person->prsn_ldap_id = null;
        $person->prsn_name = self::DELETED_PERSON;
        DB::transaction(function () use ($user, $person) {
            $surveyAnswers = SurveyAnswer::query()->where('creator_id','=',$person->id)->get();
            $surveyAnswers->each(function (SurveyAnswer $answer){
                $answer->name = self::DELETED_PERSON;
                $answer->save();
            });
            $user->delete();
            $person->save();
        });
        Log::info('User: '.\Auth::user()->name.' deleted '.$user->name);
        Utilities::success(trans('mainLang.changesSaved'));
        
        return Redirect::action('UserController@index');
    }
    
    public function agreePrivacy()
    {
        $user = Auth::user();
        $user->privacy_accepted = new \DateTime();
        if ($user->save()) {
            Log::info('User: '.$user->name.' ('.$user->person->prsn_ldap_id.') accepted the privacy policy.');
            Session::put('message', trans('mainLang.privacyAccepted'));
            Session::put('msgType', 'success');
            
            return redirect('/');
        }
        Session::put('message', 'mainLang.fatalErrorUponSaving');
        Session::put('msgType', 'danger');
        redirect();
    }
    
    /**
     * Update the specified resource in storage.
     *
     * @param \Illuminate\Http\Request $request
     * @param int $id
     * @return \Illuminate\Http\RedirectResponse
     */
    public function updateData(Request $request, User $user)
    {
        if (!Auth::user()->can('update', $user)) {
            Utilities::error(trans('mainLang.accessDenied'));
            
            return Redirect::back();
        }
        $data = [];
        if (Auth::user()->isAn(RoleUtility::PRIVILEGE_ADMINISTRATOR) || Auth::user()->hasPermissionsInSection($user->section,
                RoleUtility::PRIVILEGE_CL)) {
            $validator = $this->validator($user, Input::all());
            if ($validator->fails()) {
                Utilities::error(trans('mainLang.changesWereReset'));
                
                return Redirect::back()->withErrors($validator)->withInput(Input::all());
            }
            $data['givenname'] = Input::get('givenname');
            $data['lastname'] = Input::get('lastname');
            $data['name'] = Input::get('name');
            $data['email'] = Input::get('email');
            $data['section_id'] = Input::get('section');
            $data['status'] = Input::get('status');
            $data['on_leave'] = Input::get('on_leave');
            
        }
        if (Auth::user()->isAn(RoleUtility::PRIVILEGE_ADMINISTRATOR)) {
            $sectionIds = Section::all()->map(function (Section $section) {
                return $section->id;
            });
        } else {
            $sectionIds = Auth::user()->getSectionsIdForRoles(RoleUtility::PRIVILEGE_CL);
        }
        $assignedRoleIds = [];
        $unassignedRoleIds = [];
        foreach ($sectionIds as $sectionId) {
            $lAssignedRoleIds = explode(',', \Input::get('role-assigned-section-'.$sectionId));
            $assignedRoleIds = array_merge($lAssignedRoleIds, $assignedRoleIds);
            
            $lUnassignedRoleIds = explode(',', \Input::get('role-unassigned-section-'.$sectionId));
            $unassignedRoleIds = array_merge($lUnassignedRoleIds, $unassignedRoleIds);
        }
        
        
        $previousRoles = $user->roles;
        
        $assignedRoles = Role::query()->whereIn('id', $assignedRoleIds)->get();
        $unassignedRoles = Role::query()->whereIn('id', $unassignedRoleIds)->get();
        
        $user->fill($data);
        
        $person = $user->person;
        $person->prsn_status = $user->status;
        $person->save();
        
        $changedSection = $user->isDirty('section_id');
        $user->save();
        
        $assignedRoles->each(function (Role $role) use ($user) {
            if (!Auth::user()->can('assign', $role)) {
                Log::warning(trans('mainLang.accessDenied').' '.$role->name);
            } else {
                $user->roles()->syncWithoutDetaching($role);
            }
        });
        
        $unassignedRoles->each(function (Role $role) use ($user) {
            if (!Auth::user()->can('remove', $role)) {
                Log::warning(trans('mainLang.accessDenied').' '.$role->name.' '.$user->name);
            } else {
                $user->roles()->detach($role);
            }
        });
        
        if ($changedSection) {
            Utilities::success(trans('mainLang.changesSaved').trans('mainLang.sectionChanged'));
        } else {
            Utilities::success(trans('mainLang.changesSaved'));
        }
        
        $newRoles = $user->roles()->distinct()->get();
        $rolesChanged = $newRoles->diff($previousRoles)->count() != 0 || $previousRoles->diff($newRoles)->count() != 0;
        
        if ($rolesChanged) {
            
            $previousRolesString = $previousRoles->map(function (Role $role) {
                return $role->section->title.": ".$role->name;
            })->implode(', ');
            
            $currentRolesString = $newRoles->map(function (Role $role) {
                return $role->section->title.": ".$role->name;
            })->implode(', ');
            
            Log::info('Roles for user '.$user->givenname." ".$user->lastname.'('.$user->id.').  Changes made by '.Auth::user()->firstname.' '.Auth::user()->lastname.'('.Auth::user()->id.')'."\nPrevious roles: ".$previousRolesString."\nNew roles: ".$currentRolesString);
        }
        
        
        return Redirect::back();
    }
}
