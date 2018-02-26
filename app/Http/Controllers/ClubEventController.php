<?php

namespace Lara\Http\Controllers;

use Auth;
use Cache;
use Config;
use DateInterval;
use DateTime;
use DateTimeZone;
use Illuminate\Http\Request;
use Input;
use Lara\Club;
use Lara\ClubEvent;
use Lara\Console\Commands\SyncBDclub;
use Lara\Http\Middleware\RejectGuests;
use Lara\Logging;
use Lara\ShiftType;
use Lara\Shift;
use Lara\Person;
use Lara\Section;
use Lara\Schedule;
use Lara\Template;
use Lara\Utilities;
use Log;
use Redirect;
use Session;
use View;

class ClubEventController extends Controller
{
    public function __construct()
    {
        $this->middleware('rejectGuests', ['only' => 'create']);
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        //
    }

    /**
     * Show the form for creating a new resource.
     *
     * @param  int $year
     * @param  int $month
     * @param  int $day
     *
     * @return view createClubEventView
     * @return Section[] sections
     * @return Schedule[] templates
     * @return shiftTypes[] shiftTypes
     * @return string $date
     * @return \Illuminate\Http\Response
     */
    public function create($year = null, $month = null, $day = null, $templateId = null)
    {
        // Filling missing date and template number in case none are provided
        if ( is_null($year) ) {
            $year = date("Y");
        }

        if ( is_null($month) ) {
            $month = date("m");
        }

        if ( is_null($day) ) {
            $day = date("d");
        }

        if ( is_null($templateId) ) {
            $templateId = 0;    // 0 = no template
        }


        // validate date, see
        // https://stackoverflow.com/questions/19271381/correctly-determine-if-date-string-is-a-valid-date-in-that-format
        $dateString = $year . "-" . $month . "-" . $day;
        $d = DateTime::createFromFormat('Y-m-d', $dateString);
        $isDateFormatValid = $d && $d->format('Y-m-d') === $dateString;

        if ( !$isDateFormatValid) {
            Session::put('message', trans("messages.invalidDate", compact('day', 'month', 'year')));
            Session::put('msgType', 'danger');
            return Redirect::to('/');
        }

        // prepare correct date format to be used in the forms
        $date = strftime("%d-%m-%Y", strtotime($year.$month.$day));

        // get a list of possible clubs to create an event at, but without the id=0 (default value)
        $sections = Section::where("id", '>', 0)
                       ->orderBy('title', 'ASC')
                       ->get();

        // get a list of available templates to choose from
        $userClub = Auth::user()->section->title;
        if(Utilities::requirePermission("admin")) {
            $templates = Template::all()->sortBy('title');
        } else {
            $templates = Template::whereHas('section', function ($query) use ($userClub) {
                $query->where('title', '=', $userClub);
            })->get()->sortBy('title');
        }
        // get a list of available job types
        $shiftTypes = ShiftType::where('is_archived', '=', '0')
                           ->orderBy('title', 'ASC')
                           ->get();

        // if a template id was provided, load the schedule needed and extract job types
        if ( $templateId != 0 ) {
            /** @var Template $template */
            $template = Template::where('id', '=', $templateId)
                                ->first();

            // put name of the active template for further use
            $activeTemplate = $template->title;

            // get template data
            $shifts     = $template->shifts()
                ->with('type')
                ->orderByRaw('position IS NULL, position ASC, id ASC')
                ->get()
                ->map(function(Shift $shift) {
                    // copy all except person_id and schedule_id and comment
                    return $shift->replicate(['person_id', 'schedule_id', 'comment']);
                });
            $title          = $template->title;
            $subtitle       = $template->subtitle;
            $type           = $template->type;
            $section        = $template->section;
            $filter         = $template->showToSectionNames();
            $dv             = $template->time_preparation_start;
            $timeStart      = $template->time_start;
            $timeEnd        = $template->time_end;
            $info           = $template->public_info;
            $details        = $template->private_details;
            $private        = $template->is_private;
            $facebookNeeded = $template->facebook_needed;
        } else {
            // fill variables with no data if no template was chosen
            $activeTemplate = "";
            $shifts         = collect([]);
            $title          = null;
            $type           = null;
            $subtitle       = null;
            $section        = Section::current();
            $filter         = null;
            $dv             = $section->preparationTime;
            $timeStart      = $section->startTime;
            $timeEnd        = $section->endTime;
            $info           = null;
            $details        = null;
            $private        = null;
            $facebookNeeded = false;
        }
        $createClubEvent = true;

        return View::make('clubevent.createClubEventView', compact('sections', 'shiftTypes', 'templates',
                                                         'shifts', 'title', 'subtitle', 'type',
                                                         'section', 'filter', 'timeStart', 'timeEnd',
                                                         'info', 'details', 'private', 'dv',
                                                         'activeTemplate',
                                                         'date', 'templateId','facebookNeeded','createClubEvent'));
    }


    /**
     * Store a newly created resource in storage.
     * Create a new event with schedule and write it to the database.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        //validate passwords
        if (Input::get('password') != Input::get('passwordDouble')) {
            Session::put('message', Config::get('messages_de.password-mismatch') );
            Session::put('msgType', 'danger');
            return Redirect::back()->withInput();
            }

        $newEvent = $this->editClubEvent(null);
        $newEvent->save();

        $newSchedule = (new ScheduleController)->update(null);
        $newSchedule->evnt_id = $newEvent->id;

        // log revision
        Logging::scheduleCreated($newSchedule);

        $newSchedule->save();

        ScheduleController::createShifts($newSchedule);

        // log the action
        $user = Auth::user();
        Log::info('Event created: ' . $user->name . ' (' . $user->person->prsn_ldap_id . ', '
                 . $user->group . ') created event "' . $newEvent->evnt_title . '" (eventID: ' . $newEvent->id . ') on ' . $newEvent->evnt_date_start . '.');
        Utilities::clearIcalCache();
        if (Input::get('saveAsTemplate')){
            $template = $newSchedule->toTemplate();
            $newEvent->template_id = $template->id;
            $newEvent->save();
        }

        // show new event
        return Redirect::action('ClubEventController@show', array('id' => $newEvent->id));
    }

    /**
     * Generates the view for a specific event, including the schedule.
     *
     * @param  int $id
     * @return view ClubEventView
     * @return ClubEvent $clubEvent
     * @return Shifts[] $shifts
     * @return RedirectResponse
     */
    public function show($id)
    {
        /* @var $clubEvent ClubEvent */
        $clubEvent = ClubEvent::with('section')
                              ->findOrFail($id);

        $user = Auth::user();
        if (!$user && $clubEvent->evnt_is_private == 1)
        {
            Session::put('message', Config::get('messages_de.access-denied'));
            Session::put('msgType', 'danger');
            return Redirect::action('MonthController@showMonth', array('year' => date('Y'),
                                                                   'month' => date('m')));
        }

        //ignore html tags in the info boxes
        $clubEvent->evnt_public_info = htmlspecialchars($clubEvent->evnt_public_info, ENT_NOQUOTES);
        $clubEvent->evnt_private_details = htmlspecialchars($clubEvent->evnt_private_details, ENT_NOQUOTES);

        //if URL is in one of the info boxes, convert it to clickable hyperlink (<a> tag)
        $clubEvent->evnt_public_info = Utilities::surroundLinksWithTags($clubEvent->evnt_public_info);
        $clubEvent->evnt_private_details = Utilities::surroundLinksWithTags($clubEvent->evnt_private_details);

        $schedule = Schedule::findOrFail($clubEvent->getSchedule->id);

        $shifts = Shift::where('schedule_id', '=', $schedule->id)
                                ->with('type',
                                       'getPerson',
                                       'getPerson.getClub')
                                ->orderByRaw('position IS NULL, position ASC, id ASC')
                                ->get();

        $clubs = Club::orderBy('clb_title')->pluck('clb_title', 'id');

        $persons = Cache::remember('personsForDropDown', 10 , function()
        {
            $timeSpan = new DateTime("now");
            $timeSpan = $timeSpan->sub(DateInterval::createFromDateString('3 months'));
            return Person::whereRaw("prsn_ldap_id IS NOT NULL AND (prsn_status IN ('aktiv', 'kandidat') OR updated_at>='".$timeSpan->format('Y-m-d H:i:s')."')")
                            ->orderBy('clb_id')
                            ->orderBy('prsn_name')
                            ->get();
        });

        $revisions = json_decode($clubEvent->getSchedule->entry_revisions, true);

        if (!is_null($revisions)) {
            // reverse order to show latest revision first
            $revisions = array_reverse($revisions);

            // deleting ip adresses from output for privacy reasons
            foreach ($revisions as $entry) {
                unset($entry["from ip"]);
            }
            // add LDAP-ID of the event creator - only this user + Marketing + CL will be able to edit
            $created_by = $revisions[0]["user id"];
            $creator_name = $revisions[0]["user name"];
        }
        else {
            // workaround for empty revision in development
            $revisions = [];
            $created_by = "";
            $creator_name = "";
        }

        // Filter - Workaround for older events: populate filter with event club
        if ($clubEvent->showToSection->isEmpty() ) {
            $clubEvent->showToSection()->sync([$clubEvent->section->id]);
            $clubEvent->save();
        }

        return View::make('clubevent.clubEventView', compact('clubEvent', 'shifts', 'clubs', 'persons', 'revisions', 'created_by', 'creator_name'));
    }


    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        // find event
        /* @var $event ClubEvent */
        $event = ClubEvent::findOrFail($id);

        // find schedule
        $schedule = $event->getSchedule;

        // get a list of possible clubs to create an event at
        $sections = Section::where("id", '>', 0)
                        ->orderBy('title', 'ASC')
                       ->get();


        // get a list of available job types
        $shiftTypes = ShiftType::where('is_archived', '=', '0')
                           ->orderBy('title', 'ASC')
                           ->get();

        // put template data into shifts
        $shifts = $schedule->shifts()
                            ->with('type')
                            ->orderByRaw('position IS NULL, position ASC, id ASC')
                            ->get();

        // Filter - Workaround for older events: populate filter with event club
        if ($event->showToSection->isEmpty() ) {
            $event->showToSection()->sync([$event->section->id]);
            $event->save();
        }

        // add LDAP-ID of the event creator - only this user + Marketing + CL will be able to edit
        $revisions = json_decode($event->getSchedule->entry_revisions, true);
        if (is_null($revisions)) {
            $created_by = "";
            $creator_name = "";
        }
        else {
            $created_by = $revisions[0]["user id"];
            $creator_name = $revisions[0]["user name"];
        }

        $title          = $event->evnt_title;
        $type           = $event->evnt_type;
        $subtitle       = $event->evnt_subtitle;
        $section        = $event->section;
        $filter         = $event->showToSectionNames();
        $dv             = $schedule->schdl_time_preparation_start;
        $timeStart      = $event->evnt_time_start;
        $timeEnd        = $event->evnt_time_end;
        $info           = $event->evnt_public_info;
        $details        = $event->evnt_private_details;
        $private        = $event->evnt_is_private;
        $facebookNeeded = $event->facebook_done;
        $date = $event->evnt_date_start;
        if(!is_null($event->template_id)) {
            $baseTemplate = $event->template;
        } else {
            $baseTemplate = null;
        }

        $createClubEvent = false;

        
        $userId = Auth::user()->person->prsn_ldap_id;

        if(Utilities::requirePermission(["marketing","clubleitung","admin"]) || $userId == $created_by) {
            return View::make('clubevent.createClubEventView', compact('sections', 'shiftTypes', 'templates',
                'shifts', 'title', 'subtitle', 'type',
                'section', 'filter', 'timeStart', 'timeEnd',
                'info', 'details', 'private', 'dv',
                'activeTemplate',
                'date', 'templateId', 'facebookNeeded', 'createClubEvent',
                'event','baseTemplate'));
        } else {
            return response()->view('clubevent.notAllowedToEdit',compact('created_by','creator_name'),403);
        }
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        //validate passwords
        if (Input::get('password') != Input::get('passwordDouble')) {
            Session::put('message', Config::get('messages_de.password-mismatch') );
            Session::put('msgType', 'danger');
            return Redirect::back()->withInput();
        }

        // first we fill objects with data
        // if there is an error, we have not saved yet, so we need no rollback
        $event = $this->editClubEvent($id);

        $schedule = (new ScheduleController)->update($event->getSchedule->id);

        ScheduleController::editShifts($schedule);

        // log the action
        $user = Auth::user();
        Log::info('Event edited: ' . $user->name . ' (' . $user->person->prsn_ldap_id . ', '
                 . $user->group . ') edited event "' . $event->evnt_title . '" (eventID: ' . $event->id . ') on ' . $event->evnt_date_start . '.');


        // save all data in the database
        $event->save();
        $schedule->save();

        Utilities::clearIcalCache();
        if (Input::get('saveAsTemplate') == true){
            $template = $schedule->toTemplate();
            $event->template_id = $template->id;
            $event->save();
        }

        // show event
        return Redirect::action('ClubEventController@show', array('id' => $id));
    }

    /**
     * Delete an event specified by parameter $id with schedule and shifts
     *
     * @param  int  $id
     * @return RedirectResponse
     */
    public function destroy($id)
    {
        // Get all the data
        $event = ClubEvent::find($id);

        $date = new DateTime($event->evnt_date_start);


        // Check if event exists
        if ( is_null($event) ) {
            Session::put('message', Config::get('messages_de.event-doesnt-exist') );
            Session::put('msgType', 'danger');
            return Redirect::back();
        }

        // Check credentials: you can only delete, if you have rights for marketing or management.
        $revisions = json_decode($event->getSchedule->entry_revisions, true);
        $created_by = $revisions[0]["user id"];

        $user = Auth::user();
        if (!$user || $user->is(['marketing', 'clubleitung', 'admin']))
        {
            Session::put('message', 'Du darfst diese Veranstaltung/Aufgabe nicht einfach löschen! Frage die Clubleitung oder Markleting ;)');
            Session::put('msgType', 'danger');
            return Redirect::action('MonthController@showMonth', array('year' => date('Y'),
                                                                   'month' => date('m')));
        }

        // Log the action while we still have the data
        Log::info('Event deleted: ' . $user->name . ' (' . $user->person->prsn_ldap_id . ', '
                 . $user->group . ') deleted event "' . $event->evnt_title . '" (eventID: ' . $event->id . ') on ' . $event->evnt_date_start . '.');
        Utilities::clearIcalCache();

        // Delete schedule with shifts
        $result = (new ScheduleController)->destroy($event->getSchedule()->first()->id);

        // Now delete the event itself
        ClubEvent::destroy($id);

        // show current month afterwards
        Session::put('message', Config::get('messages_de.event-delete-ok'));
        Session::put('msgType', 'success');
        return Redirect::action( 'MonthController@showMonth', ['year' => $date->format('Y'),
                                                               'month' => $date->format('m')] );
    }





//--------- PRIVATE FUNCTIONS ------------


    /**
    * Edit or create a clubevent with its entered information.
    * If $id is null create a new clubEvent, otherwise the clubEvent specified by $id will be edit.
    *
    * @param int $id
    * @return ClubEvent clubEvent
    */
    private function editClubEvent($id)
    {
        $event = new ClubEvent;
        $event->creator_id = Auth::user()->id;

        if(!is_null($id)) {
            $event = ClubEvent::findOrFail($id);
        }

        // format: strings; no validation needed
        $event->evnt_title             = Input::get('title');
        $event->evnt_subtitle          = Input::get('subtitle');
        $event->evnt_public_info       = Input::get('publicInfo');
        $event->evnt_private_details   = Input::get('privateDetails');
        $event->evnt_type              = Input::get('evnt_type');
        $event->facebook_done          = $this->getFacebookDoneValue();
        $event->event_url              = Input::get('eventUrl',"");
        $event->price_tickets_normal   = $this->getOrNullNumber('priceTicketsNormal');
        $event->price_tickets_external = $this->getOrNullNumber('priceTicketsExternal');
        $event->price_normal           = $this->getOrNullNumber('priceNormal');
        $event->price_external         = $this->getOrNullNumber('priceExternal');
        $event->template_id            = $this->getTemplateId();

        // Check if event URL is properly formatted: if the protocol is missing, we have to add it.
        if( $event->event_url !== "" && parse_url($event->event_url, PHP_URL_SCHEME) === null) {
            $event->event_url = 'https://' . $event->event_url;
        }

        // create new section
        if (!Section::where('title', '=', Input::get('section'))->first()) {
            $section = new Section;
            $section->title = Input::get('section');
            $section->save();

            $event->plc_id = $section->id;
        }
        // use existing section
        else {
            $event->plc_id = Section::where('title', '=', Input::get('section'))->first()->id;
        }

        // format: date; validate on filled value
        if(!empty(Input::get('beginDate')))
        {
            $newBeginDate = new DateTime(Input::get('beginDate'), new DateTimeZone(Config::get('app.timezone')));
            $event->evnt_date_start = $newBeginDate->format('Y-m-d');
        }
        else
        {
            $event->evnt_date_start = date('Y-m-d', mktime(0, 0, 0, 0, 0, 0));;
        }

        if(!empty(Input::get('endDate')))
        {
            $newEndDate = new DateTime(Input::get('endDate'), new DateTimeZone(Config::get('app.timezone')));
            $event->evnt_date_end = $newEndDate->format('Y-m-d');
        }
        else
        {
            $event->evnt_date_end = date('Y-m-d', mktime(0, 0, 0, 0, 0, 0));;
        }

        // format: time; validate on filled value
        $event->evnt_time_start = !empty(Input::get('beginTime')) ? Input::get('beginTime') : mktime(0, 0, 0);
        $event->evnt_time_end = !empty(Input::get('endTime')) ? Input::get('endTime') : mktime(0, 0, 0);

        // format: tinyInt; validate on filled value
        // reversed this: input=1 means "event is public", input=0 means "event is private"
        $event->evnt_is_private = (Input::get('isPrivate') == '1') ? 0 : 1;
        $eventIsPublished = Input::get('evntIsPublished');

        $userGroup = Auth::user()->group;
        if (!is_null($eventIsPublished)) {
            //event is pubished
            $event->evnt_is_published = (int)$eventIsPublished;
        } elseif (in_array($userGroup, ['marketing', 'clubleitung', 'admin'])){
            // event was unpublished
            $event->evnt_is_published = 0;
        }

        // Filter
        $filter = collect(Input::get("filter"))->values()->toArray();

        $event->save();
        $event->showToSection()->sync($filter);

        // Logging

        if ($event->exists) {
            //only log changes if the event already exists in the DB
            if ($event->isDirty('evnt_time_start')) {
                Logging::eventStartChanged($event);
            }

            if ($event->isDirty('evnt_title')) {
                Logging::eventTitleChanged($event);
            }

            if ($event->isDirty('evnt_subtitle')) {
                Logging::eventSubtitleChanged($event);
            }

            if ($event->isDirty('evnt_time_end')) {
                Logging::eventEndChanged($event);
            }

            if ($event->isDirty('evnt_public_info')) {
                Logging::logEventRevision($event, "revisions.eventPublicInfoChanged");
            }

            if ($event->isDirty('evnt_private_details')) {
                Logging::logEventRevision($event, "revisions.eventPrivateDetailsChanged");
            }

        }
        return $event;
    }

    private function getOrNullNumber($query){
        $num = Input::get($query);
        if(doubleval($num)>0){
            return $num;
        } else {
            return null;
        }
    }

    private function getFacebookDoneValue() {
        $inputVal = Input::get('facebookDone') ;
        switch ($inputVal){
            case "1": return true;
            case "0": return false;
            case "-1" :
            default:null;
        }
    }

    private function getTemplateId() {
        $templateValue = Input::get('template',-1);
        if($templateValue == -1){
            return null;
        }
        /**
         * from input event we get the link to create a template
         * e.g. <code>/event/2018/02/16/81/create</code>
         * in this case we need the 81
         * php can only search from beginning
         * so we reverse the string, use offset 7 to look after create
         * we get the position of the /
         * now we get the substring -> 81/create
         * after this we remove the /create -> 81
         */
        $reverse = strrev($templateValue);
        $pos = strpos($reverse,"/",7);
        $result = substr($templateValue, strlen($templateValue)-$pos);
        return str_replace("/create",'',$result);
    }
}



