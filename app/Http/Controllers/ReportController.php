<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Carbon\Carbon;
use App\Models\{
    GeneralDirection,
    DailyRecord,
    Employee
};
use App\Helpers\DailyReportFactory;
use App\Helpers\DailyReportPdfFactory;
use Inertia\Inertia;

class ReportController extends Controller
{


    /**
     * returned the main view for generating reports
     *
     * @return void
     */
    public function index(){

        // * get catalogs
        $generalDirections = GeneralDirection::select('id','name')->get()->all();

        $breadcrumbs = array(
            ["name"=> "Inicio", "href"=> "/dashboard"],
            ["name"=> "Generar reportes", "href"=>""],
        );

        return Inertia::render("Reports/Index", [
            "generalDirections" => $generalDirections,
            "breadcrumbs" => $breadcrumbs
        ]);
    }

    public function createDailyReport(Request $request) {

        // * validate request
        if( !$request->has('gd') || !$request->has('d') ){
            return redirect()->back()->withErrors([
                "message" => "General Direction Id and Date required"
            ])->withInput();
        }


        // * prepared variables
        $generalDirection=null;
        $dateReport = Carbon::parse( $request->query('d') )->format("Y-m-d");
        $includeAllEmployees = $request->query('a', false);


        // * generate breadcumbs for the view
        $breadcrumbs = array(
            ["name"=> "Inicio", "href"=> "/dashboard"],
            ["name"=> "Generar reportes", "href"=> route('reports.index') ],
            ["name"=> "Reporte diario de $dateReport", "href"=>""],
        );


        // * get current user
        $AUTH_USER = Auth::user();


        // * retrive the general_direction based on user level
        if( $AUTH_USER->level_id == 1 &&  $request->has('gd') )/*Admin*/{
            $generalDirection = GeneralDirection::where('id', $request->query('gd') )->first();
        }
        else {
            $generalDirection = GeneralDirection::where('id', $AUTH_USER->general_direction_id)->first();
        }


        // * retornar vista
        return Inertia::render("Reports/Daily", [
            "title" => "Generando reporte diario del " . Carbon::parse($request->query('date'))->format("d M Y"),
            "breadcrumbs" => $breadcrumbs,
            "report" => Inertia::lazy( fn() => $this->makeDailyReport($AUTH_USER, $dateReport, $generalDirection, $includeAllEmployees) ),
        ]);

    }

    public function createMonthlyReport(Request $request) {
        // * validate the request
        $validate = $request->validate([
            'general_direction_id' => 'required',
            'year' => 'required',
            'month' => 'required',
        ]);

        $AUTH_USER = Auth::user();
        $users = array();
        $generalDirection = '';
        $year = $request->input('year');
        $month = $request->input('month');
        $allEmployees = $request->input('all') ?? false;
        if ($allEmployees == 1) {
            $allEmployees = true;
        }

        if ($AUTH_USER->level_id == 1) {
            $generalDirectionId = $request->input('general_direction_id');
            $queryGeneralDirection = GeneralDirection::select('name')->where('id', $generalDirectionId)->first();
            $generalDirection = $queryGeneralDirection->name;
            $dataMongo = \App\Models\MonthlyRecord::where('general_direction_id', $generalDirectionId);
        } else {
            if ($AUTH_USER->generalDirection) {
                $generalDirection = $AUTH_USER->generalDirection->name;
            }
            $generalDirectionId = $AUTH_USER->general_direction_id;

            $dataMongo = \App\Models\MonthlyRecord::where('general_direction_id', $generalDirectionId)
                ->where('direction_id', Auth::user()->direction_id)
                ->where('subdirectorate_id', Auth::user()->subdirectorate_id)
                ->where('department_id', Auth::user()->department_id);
        }
        // Re create actual month report
        $today = new \DateTime();
        if ($today->format('Y') == $year && $today->format('m') == $month) {
            $dataMongo = false;
        } else {
            $dataMongo = $dataMongo->where('year', $year)
                ->where('month', $month)
                ->where('all_employees', $allEmployees)
                ->first();
        }

        // * make mongo record if is nothing alredy stored
        if (!$dataMongo)
        {
            $employees = Employee::select('id', 'general_direction_id', 'direction_id', 'subdirectorate_id', 'department_id', 'plantilla_id', 'name')
                ->where('status_id', 1)
                ->where('active', 1)
                ->where('general_direction_id', $generalDirectionId);


            // * filter employee by current user level
            if ($AUTH_USER->level_id > 2) { // Director
                $employees = $employees->where('direction_id', $AUTH_USER->direction_id);
            }
            // else {
            //     if ($allEmployees == NULL) {
            //         $employees = $employees->where('direction_id', 1);
            //     }
            // }

            if ($AUTH_USER->level_id > 3) { // Subdirectorate
                $employees = $employees->where('subdirectorate_id', $AUTH_USER->subdirectorate_id);
            }
            // else {
            //     if ($allEmployees == NULL) {
            //         $employees = $employees->where('subdirectorate_id', 1);
            //     }
            // }

            if ($AUTH_USER->level_id > 4) { // department
                $employees = $employees->where('department_id', $AUTH_USER->subdirectorate_id);
            }
            // else {
            //     if ($allEmployees == NULL) {
            //         $employees = $employees->where('department_id', 1);
            //     }
            // }

            $employees = $employees->orderBy('name', 'ASC')->get();

            $data = $this->makeMonthlyRecords(
                $employees,
                $generalDirectionId,
                $year,
                $month,
                $allEmployees
            );
        } else {
            $data = $dataMongo->data;
        }


        $excel = new ExcelController($data, $generalDirection);
        $fileName = 'reporte_mensual_' .Str::slug($generalDirection, '_') . '_' . $month . $year.'.xlsx';
        Log::info('User '.Auth::user()->name.' generated monthly report '. $month . '_' . $year . ' '. $generalDirection);
        $excel->create($fileName);
    }

    public function downloadDailyReporte(Request $request, $report_name){

        $filePath = sprintf("tmp/dailyreports/$report_name");

        // * validate if the report exist
        if( !Storage::disk('local')->exists( $filePath)){
            return response()->json([
                "message" => "El reporte que está tratando de acceder no se encuentra disponible."
            ], 404);
        };

        // * download the file
        $name = "reporte-diario.pdf";
        return Storage::disk('local')->download($filePath, $name);

    }


    #region private functions

    /**
     * makeReport
     *
     * @return array|null
     */
    private function makeDailyReport($AUTH_USER, $dateReport, $generalDirection, $includeAllEmployees ){

        $now = new \DateTime();
        $reportData = array();

        // * attempt to make the daily report
        try {

            // * attempt to get the report data from the MongoDB
            $mongoReportRecord = $this->getDailyReportStored(
                date: $dateReport,
                generalDirectionId: $generalDirection->id,
                options: [
                    'directionId' => ($AUTH_USER->level_id > 2) ?$AUTH_USER->direction_id :null,
                    'subdirectorateId' => ($AUTH_USER->level_id > 3) ?$AUTH_USER->subdirectorate_id :null,
                    'departmentId' => ($AUTH_USER->level_id > 4) ?$AUTH_USER->department_id :null,
                ],
                allEmployees: $includeAllEmployees
            );


            // * use the reportData stored if reportData is not off today
            if ($mongoReportRecord && $now->format('Y-m-d') != $dateReport) {
                $reportData = $mongoReportRecord->data;
            }
            else { // Not today and not store data from the selected day

                // * get the employees associated to the user department
                $employees = $this->getEmployees( $generalDirection->id, [
                    'directionId' => ($AUTH_USER->level_id > 2) ?$AUTH_USER->direction_id :null,
                    'subdirectorateId' => ($AUTH_USER->level_id > 3) ?$AUTH_USER->subdirectorate_id :null,
                    'departmentId' => ($AUTH_USER->level_id > 4) ?$AUTH_USER->department_id :null,
                ]);


                // * make dailyReport data
                $dailyReportFactory = new DailyReportFactory( $employees, $dateReport );
                $reportData = $dailyReportFactory->makeReportData();


                // * attempt to store in mongoDB only if selected day is not today
                if( Carbon::today()->format('Y-m-d') != $dateReport ) {
                    try {
                        $recordMongo = new \App\Models\DailyRecord();
                        $recordMongo->general_direction_id = $generalDirection->id;
                        if( $AUTH_USER->level_id != 1) {
                            $recordMongo->direction_id = $AUTH_USER->direction_id;
                            $recordMongo->subdirectorate_id = $AUTH_USER->subdirectorate_id;
                            $recordMongo->department_id = $AUTH_USER->department_id;
                        }
                        $recordMongo->report_date = $dateReport;
                        $recordMongo->all_employees = $includeAllEmployees;
                        $recordMongo->data = $reportData;
                        $recordMongo->save();
                    } catch (\Throwable $th) {
                        Log::error($th->getMessage());
                    }
                }

            }


            // * make pdf and stored
            $dailyReportFactory = new DailyReportPdfFactory( $reportData, $dateReport, $generalDirection->name );
            $dailyReportFactory->makePdf();
            $pdfStringContent = $dailyReportFactory->Output('S');


            // * store pdf
            $fileName = sprintf("%s.pdf", (string) Str::uuid() );
            $filePath = sprintf("tmp/dailyreports/$fileName");
            if( Storage::disk('local')->put( $filePath, $pdfStringContent ) ){
                Log::info('User ' . $AUTH_USER->name . ' generate daily report for date ' . $dateReport);
            }else {
                Log::warning('Fail at stored the pdf of the daily report by User ' . $AUTH_USER->name . ' for date ' . $dateReport);
            }

            $size = Storage::disk('local')->size($filePath);
            $sizeInKB = number_format($size / 1024, 2);

            // * return the data related to the pdf created
            return [
                "fileName" => $fileName,
                "date" => Carbon::now()->format("Y-m-d H:i:s"),
                "userName" => $AUTH_USER->name,
                "size" => $sizeInKB . " KB"
            ];

        } catch (\Throwable $th) {
            Log::error("Fail to generate the daily report of day {date}: {message}", [
                "date" => $dateReport,
                "message" => $th->getMessage()
            ]);

            return [
                "error" => "Error al generar el reporte diario del día '$dateReport' intente de nuevo o comuníquese con el administrador."
            ];
        }

    }

    private function getEmployees( $generalDirectionId, $options ){

        // * get employees of the current general-direction
        $employeesQuery = Employee::with(['workingHours'])
            ->select('id', 'general_direction_id', 'direction_id', 'subdirectorate_id', 'department_id', 'plantilla_id', 'name')
            ->where('status_id', 1)
            ->where('active', 1)
            ->where('general_direction_id', $generalDirectionId);

        if(isset($options['directionId']) ){
            $employeesQuery = $employeesQuery->where('direction_id', $options['directionId']);
        }

        if(isset($options['subdirectorateId']) ){
            $employeesQuery = $employeesQuery->where('subdirectorate_id', $options['directionId']);
        }

        if(isset($options['departmentId']) ){
            $employeesQuery = $employeesQuery->where('department_id', $options['departmentId']);
        }

        return $employeesQuery->orderBy('name', 'ASC')->get();
    }

    /**
     * getDailyReportStored
     *
     * @param  string $date format Y-m-d
     * @param  int $generalDirectionId
     * @param  array<string,int> $options ['directionId', 'subdirectorateId', 'departmentId']
     * @param  bool $allEmployees
     * @return DailyRecord|null
     */
    private function getDailyReportStored( $date, $generalDirectionId, $options, $allEmployees = false ){

        // * prepare the query
        $mongoRecordQuery = DailyRecord::where('general_direction_id', $generalDirectionId)
            ->where('report_date', $date)
            ->where('all_employees', $allEmployees);

        if( isset($options['directionId']) ){
            $mongoRecordQuery = $mongoRecordQuery->where('direction_id', $options['directionId']);
        }

        if( isset($options['subdirectorateId']) ){
            $mongoRecordQuery = $mongoRecordQuery->where('subdirectorate_id', $options['subdirectorateId']);
        }

        if( isset($options['departmentId']) ){
            $mongoRecordQuery = $mongoRecordQuery->where('department_id', $options['departmentId']);
        }

        // retrive the data
        return $mongoRecordQuery->first();
    }
    
    private function makeMonthlyRecords($employees, $generalDirectionId, $year, $month, $all_employees)
    {
        foreach ($employees as $employee) {
            $checaComida = false;
            // Get working hours
            $workingHours = $employee->workingHours;

            if ($workingHours)
            {
                if ($workingHours->toeat && $workingHours->toarrive) {
                    $checaComida = true;
                }
            }

            $checadas = array();
            $date = new \DateTime("$year-$month-01");

            for ($i=1; $i < 32; $i++) {
                if ($i == $date->format('d')) {
                    $checkin = '';
                    $checkout = '';
                    $eat = '';
                    $arrive = '';
                    // Get checkin
                    $records = Record::select('check')
                    ->where('employee_id', $employee->id)
                    ->whereDate('check', $date->format('Y-m-d'))
                    ->get();

                    if ($workingHours)
                    {
                        // Horario corrido
                        $hour1 = strtotime($workingHours->checkin);
                        $hour2 = strtotime($workingHours->checkout);
                        // horario quebrado
                        $hour3 = strtotime($workingHours->toeat);
                        $hour4 = strtotime($workingHours->toarrive);

                        $recordsArray = [];
                        foreach ($records as $record) {
                            $dateRecord = new \DateTime($record->check);
                            $timeRecord = $dateRecord->format('H:i');

                            if (!in_array($timeRecord, $recordsArray))
                            {
                                $recordsArray[] = $timeRecord;
                            }
                        } // End foreach

                        foreach ($recordsArray as $timeRecord) {
                            $diffCheckin = round(abs(strtotime($timeRecord) - $hour1) / 3600, 2);
                            $diffCheckout = round(abs(strtotime($timeRecord) - $hour2) / 3600, 2);

                            $diffToEat = round(abs(strtotime($timeRecord) - $hour3) / 3600, 2);
                            $diffToArrive = round(abs(strtotime($timeRecord) - $hour4) / 3600, 2);

                            if (!$checaComida) {
                                if ($diffCheckin < $diffCheckout && !$checkin) {
                                    $checkin = $timeRecord;
                                } else {
                                    $checkout = $timeRecord;
                                }
                            } else {
                                if ($diffCheckin < $diffToEat && $checkin == '') {
                                    $checkin = $timeRecord;
                                } elseif ($diffToEat < $diffToArrive) {
                                    $eat = $timeRecord;
                                } elseif ($diffToArrive < $diffCheckout) {
                                    $arrive = $timeRecord;
                                } else {
                                    $checkout = $timeRecord;
                                }
                            }
                        }
                    } else {
                        $checkin = 'S/H';
                        $checkout = 'S/H';
                    }
                    // Array
                    $checadas[] = array(
                        'diaNombre' => $this->translateDayName($date->format('D')),
                        'dia' => $date->format('d'),
                        'entrada' => $checkin,
                        'comidaS' => $eat,
                        'comidaE' => $arrive,
                        'salida' => $checkout,
                    );
                    $date->modify('+1 day');
                }
            }

            $users[] = array(
                'name' => $employee->name,
                'checadas' => $checadas
            );
        }

        $data = array(
            'year' => $year,
            'month' => $this->months($month),
            'users' => $users
        );

        // Store in mongoDB
        try {
            $today = new \DateTime();
            //if ($today->format('Yn') != $year.$month) {
                $recordMongo = new \App\Models\MonthlyRecord();
                $recordMongo->general_direction_id = $generalDirectionId;
                if (Auth::user()->level_id != 1) {
                    $recordMongo->direction_id = Auth::user()->direction_id;
                    $recordMongo->subdirectorate_id = Auth::user()->subdirectorate_id;
                    $recordMongo->department_id = Auth::user()->department_id;
                }
                $recordMongo->year = $year;
                $recordMongo->month = $month;
                $recordMongo->all_employees = $all_employees;
                $recordMongo->data = $data;
                $recordMongo->save();
            //}
        } catch (\Throwable $th) {
            Log::error($th->getMessage());
        }

        return $data;
    }
    #endregion

}