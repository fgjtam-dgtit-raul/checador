<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Exception;
use App\Services\{
    EmployeeService,
    JustificationService
};
use App\Models\{
    Department,
    Employee,
    GeneralDirection,
    Direction,
    Subdirectorate,
    WorkingHours,
    Record,
    Incident,
    Justify
};
use App\ViewModels\{
    CalendarEvent
};
use App\Http\Requests\{
    UpdateEmployeeRequest
};
use App\Helpers\EmployeeKardexRecords;
use App\Helpers\EmployeeKardexExcel;


class EmployeeController extends Controller
{

    protected EmployeeService $employeeService;
    protected JustificationService $justificationService;

    function __construct( EmployeeService $employeeService, JustificationService $justificationService )
    {
        $this->employeeService = $employeeService;
        $this->justificationService = $justificationService;
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $currentPage = $request->query('p', 1);
        $elementsToTake = 50;
        $generalDirectionId = null;
        $directionId = null;
        $subdirectionId = null;
        if(Auth::user()->level_id > 1){
            $generalDirectionId = Auth::user()->general_direction_id;

            if( Auth::user()->level_id > 2){
                $directionId = Auth::user()->direction_id;
            }else{
                $directionId = $request->filled('d') ?$request->query("d") :null;
            }

        }else{
            if( $request->filled('gd')){
                $generalDirectionId = $request->query("gd");
            }
            if( $request->filled('d')){
                $directionId = $request->query("d");
            }
            if( $request->filled('sb')){
                $subdirectionId = $request->query("sb");
            }
        }


        // * get catalogs
        $generalDirections = GeneralDirection::select('id', 'name')->get();
        $directions = Direction::select('id', 'name', 'general_direction_id')->get();
        $subdirectorate = Subdirectorate::select('id', 'name', 'direction_id')->get();


        // * prepare the filters
        $filters = array();
        if( $generalDirectionId != null){
            $filters[ EmployeeFiltersEnum::GD ] = $generalDirectionId;
            $directions = $directions->where('general_direction_id', $generalDirectionId);
        }
        if( $directionId != null){
            $filters[ EmployeeFiltersEnum::D ] = $directionId;
            $subdirectorate = $directions->where('direction_id', $directionId);
        }
        if( $subdirectionId != null){
            $filters[ EmployeeFiltersEnum::SD ] = $subdirectionId;
        }
        if( $request->filled("se")){
            $filters['search'] = $request->query("se");
        }


        // * get employees
        $totalEmployees = 0;
        $data = $this->employeeService->getEmployees(
            take: $elementsToTake,
            skip: ($elementsToTake * ($currentPage - 1)),
            filters:$filters,
            total:$totalEmployees
        );


        // * verify if display paginator
        $showPaginator = $elementsToTake < $totalEmployees;


        // * make paginator
        $paginator = [
            "from" => $elementsToTake * ($currentPage - 1),
            "to" =>  $elementsToTake * $currentPage,
            "total" => $totalEmployees,
            "pages" =>  range(1, ceil( $totalEmployees / $elementsToTake))
        ];


        // * return the viewe
        return Inertia::render('Employees/Index', [
            "employees" => $data,
            "general_direction" => $generalDirections,
            "directions" => array_values( $directions->toArray() ),
            "subdirectorate" => array_values( $subdirectorate->toArray() ),
            "showPaginator" => $showPaginator,
            "filters" => [
                "gd" => $generalDirectionId,
                "d" => $directionId,
                "sd" => $subdirectionId,
                "page" => $currentPage,
                "search" => $request->filled("se") ?$request->input("se") :null
            ],
            "paginator" => $paginator
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, string $employee_number)
    {

        // * attempt to get the employee
        try {
            $employee = $this->employeeService->getEmployee($employee_number);
        } catch (ModelNotFoundException $nf) {
            Log::warning("Employee with employee number '$employee_number' not found");

            // * redirect back
            return redirect()->back()->withErrors([
                "employee_number" => "Empleado no encontrado",
                "message" => "Empleado no encontrado"
            ])->withInput();

        } catch (\Throwable $th) {
            Log::error("Unhandle exception at attempting to get the employee at EmployeeController.show: {message}", [
                "employee_number" => $employee_number,
                "message" => $th->getMessage(),
            ]);

            //TODO: Redirect to error page
            throw new Exception("Not implemented");
        }

        // calculate status
        $status = array(
            'name' => 'BAJA',
            'class' => 'border border-red-400 text-red-600'
        );
        if ($employee->active) {
            $status = array(
                'name' => 'ACTIVO',
                'class' => 'border border-green-400 text-green-600'
            );
        }

        // calculate status checa
        $checa = array(
            'name' => 'REGISTRA ASISTENCIA',
            'class' => 'border border-green-400 text-green-600'
        );
        if ($employee->checa != 1) {
            $checa = array(
                'name' => 'NO REGISTRA ASISTENCIA',
                'class' => 'border border-red-400 text-red-600'
            );
        }

        // * get working hours
        $hours = array();
        $workingHours = WorkingHours::where("employee_id", $employee->id)->first();
        if( $workingHours != null){
            if( $workingHours->toeat == null){
                array_push($hours, $workingHours->checkin . "-" . $workingHours->checkout);
            }else {
                array_push($hours, $workingHours->checkin . "-" . $workingHours->toeat);
                array_push($hours, $workingHours->toarrive . "-" . $workingHours->checkout);
            }
        }


        // * calculate the breadcrumns based on where the request come from
        $breadcrumbs = array(
            ["name"=> "Inicio", "href"=> "/dashboard"],
            ["name"=> "Vista Empleados", "href"=> route('employees.index') ],
            ["name"=> "Empleado: $employee->employeeNumber", "href"=> route('employees.show', $employee->employeeNumber)],
        );
        if( parse_url( $request->headers->get('referer'), PHP_URL_PATH ) == '/inactive' ){
            $breadcrumbs[1] = [
                "name"=> "Inactivos", "href"=> $request->headers->get('referer'),
            ];
        }

        // * return the view
        return Inertia::render('Employees/Show', [
            "employeeNumber" => $employee_number,
            "employee" => isset($employee) ?$employee :null,
            "status" => (object) $status,
            "checa" => (object) $checa,
            "workingHours" => $hours,
            "breadcrumbs" => $breadcrumbs
        ]);
    }

    /**
     * show the form for editing the employee
     *
     * @param  string $employee_number
     * @return void
     */
    public function edit(string $employee_number)
    {
        // TODO: retrive the query params to filter the catalogs

        // * retrive the employee
        $employee = $this->findEmployee($employee_number);
        if($employee instanceof \Illuminate\Http\RedirectResponse){
            return $employee;
        }

        // * retrive the catalogs
        $generalDirections = GeneralDirection::select('id','name')->get()->toArray();
        $directions = Direction::select('id','name', 'general_direction_id')->get()->toArray();
        $subdirectorates = Subdirectorate::select('id', 'name', 'direction_id')->get()->toArray();
        $deparments = Department::select('id', 'name', 'subdirectorate_id')->get()->toArray();


        // * return the view
        return Inertia::render('Employees/Edit', [
            "employeeNumber" => $employee->employeeNumber,
            "employee" => $employee,
            "generalDirections" => $generalDirections,
            "directions" => $directions,
            "subdirectorates" => $subdirectorates,
            "deparments" => $deparments,
            "defaultValues" =>  (object) array(),
        ]);
    }

    /**
     * Update the employee in storage.
     *
     * @param  UpdateEmployeeRequest $request
     * @param  string $employee_number
     * @return void
     */
    public function update(UpdateEmployeeRequest $request, string $employee_number)
    {
        // * retrive the employee
        $employee = $this->findEmployee($employee_number);
        if($employee instanceof \Illuminate\Http\RedirectResponse){
            return $employee;
        }

        // * update the employee data
        try {
            $this->employeeService->updateEmployee( $employee->employeeNumber, $request->request->all());
        }catch (\Throwable $th) {
            return redirect()->back()->withErrors([
                "message" => $th->getMessage()
            ])->withInput();
        }

        // * redirect to show view
        return redirect()->route('employees.show', ['employee_number' => $employee->employeeNumber ]);

    }


    public function eventsJson(Request $request, string $employee_number): JsonResponse{

        // * get the range day from the querys
        $from = Carbon::now()->startOfMonth();
        $to = Carbon::now()->endOfMonth();
        if($request->has("from") && $request->has("to")){
            $from = Carbon::parse($request->query("from"));
            $to = Carbon::parse($request->query("to"));
        }

        // * retrive the employee
        $employee = $this->employeeService->getEmployee($employee_number);

        // * get the records

        $records = Record::where('employee_id', $employee->id)
            ->whereDate('check', '>=', $from->format('Y-m-d'))
            ->whereDate('check', '<=', $to->format('Y-m-d'))
            ->get();

        // * get the incidents
        $incidents = Incident::with(['type', 'state'])
            ->where('employee_id', $employee->id)
            ->whereDate('date', '>=', $from->format('Y-m-d'))
            ->whereDate('date', '<=', $to->format('Y-m-d'))
            ->get();

        // * get the justifications
        $justifications = $this->justificationService->getJustificationsEmployee( $employee, $from->format('Y-m-d'), $to->format('Y-m-d') );

        // * parse events
        $events = array();

        foreach($records as $record) {
            $event = new CalendarEvent("Registro", $record->check, $record->check);
            $event->color = "#27ae60";
            $event->type = "RECORD";
            array_push( $events, $event);
        }

        foreach($incidents as $incident){
            $title = $incident->type->name;
            $event = new CalendarEvent($title, $incident->date, $incident->date);
            $event->color = "#dc7633";
            $event->type = "INCIDENT";
            array_push( $events, $event);
        }

        foreach($justifications as $justify) {
            $justify_title = $justify->type->name;
            if( $justify->date_finish != null ){
                $_from = Carbon::parse($justify->date_start);
                $_to = Carbon::parse($justify->date_finish);
                // Loop through each day from start to end date
                for ($date = $_from; $date->lte($_to); $date->addDay()) {
                    $event = new CalendarEvent($justify_title, $date->format('Y-m-d'), $date->format('Y-m-d'));
                    $event->color = "#5499c7";
                    $event->type = "JUSTIFY";
                    array_push( $events, $event);
                }
            }
            else{
                $event = new CalendarEvent($justify_title, $justify->date_start->format('Y-m-d'), $justify->date_start->format('Y-m-d'));
                $event->color = "#5499c7";
                $event->type = "JUSTIFY";
                array_push( $events, $event);
            }

        }

        return response()->json($events, 200);
    }

    public function kardexEmployee(Request $request, string $employee_number){
        // * retrive the employee
        $employee = null;
        try {
            $employeeVM = $this->employeeService->getEmployee($employee_number);
            $employee = Employee::with(['workingHours', 'workingDays', 'generalDirection'])->findOrFail($employeeVM->id);
        } catch (ModelNotFoundException $nf) {
            Log::warning("Employee with employee number '$employee_number' not found");

            // * redirect back
            return redirect()->back()->withErrors([
                "employee_number" => "Empleado no encontrado",
                "message" => "Empleado no encontrado"
            ])->withInput();
        }

        $workingHours = $employee->workingHours;
        $year = $request->input('year');
        $today = new \DateTime();


        // * attempt to get the cache kardex record from the mongodb
        $recordMongo = \App\Models\KardexRecord::where('employee_id', $employee->id)
            ->where('report_date', '=', $today->format('Y-m-d'))
            ->where('year', '=', $year)
            ->first();

        if ($recordMongo) {
            $dataUser = $recordMongo->data;
        } else {
            if ($workingHours) {
                if (!$workingHours->checkin || $workingHours->checkin == '') {
                    throw new \Exception("The employee has no working schedule assigned.");
                }
            }

            // * make the records of the employee kardex
            $employeeKardexRecords = new EmployeeKardexRecords($employee);
            $dataUser = $employeeKardexRecords->makeRecords($year);
        }

        // * make the excel file
        $employeeKardexExcel = new EmployeeKardexExcel($dataUser, $employee->generalDirection->name);
        $documentContent = $employeeKardexExcel->create();
        if( $documentContent === false){
            // TODO: Log fail
            throw new \Exception("Fail to make the report document");
        }

        // * store the file
        $fileName = sprintf("%s.xlsx", (string) Str::uuid() );
        $filePath = sprintf("tmp/kardex/$fileName");
        if( Storage::disk('local')->put( $filePath, $documentContent ) ){
            Log::info('User ' . Auth::user()->name . ' generate daily report for year ' . $request->input('year'));
        }else {
            Log::warning('Fail at stored the report of the employee kardex by User ' . Auth::user()->name);
        }

        // * download the file
        $name = "kardex-empleado.xlsx";
        return Storage::disk('local')->download($filePath, $name);
    }

    #region Incidents
    public function incidentCreate(Request $request, string $employee_number) {

        // * retrive the employee
        $employee = $this->findEmployee($employee_number);
        if($employee instanceof \Illuminate\Http\RedirectResponse){
            return $employee;
        }

        // * return the view
        return Inertia::render('Employees/Incidents/Create', [
            "employeeNumber" => $employee->employeeNumber,
            "employee" => $employee,
            "date" => $request->filled('date') ?$request->query('date') :null
        ]);

    }
    #endregion

    #region private methods
    /**
     * find Employee
     *
     * @param  string $employee_number
     * @return \App\ViewModels\EmployeeViewModel|\Illuminate\Http\RedirectResponse
     */
    private function findEmployee(string $employee_number){

        // * attempt to get the employee
        try {
            return $this->employeeService->getEmployee($employee_number);
        } catch (ModelNotFoundException $nf) {

            Log::warning("Employee with employee number '$employee_number' not found");

            //TODO: redirect to not found page

            // * redirect back
            return redirect()->back()->withErrors([
                "employee_number" => "Empleado no encontrado",
                "message" => "Empleado no encontrado"
            ])->withInput();
        }
    }
    #endregion

}

class EmployeeFiltersEnum {
    const GD = 'general_direction_id';
    const D = 'direction_id';
    const SD = 'subdirectorate_id';
}