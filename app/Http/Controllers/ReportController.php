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
    Employee,
    Record,
    WorkingDays,
    WorkingHours,
    MonthlyRecord
};
use App\Helpers\DailyReportFactory;
use App\Helpers\DailyReportPdfFactory;
use Inertia\Inertia;
use Date;
use Illuminate\Http\JsonResponse;

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
        if( $AUTH_USER->level_id == 1 && $request->has('gd') )/*Admin*/{
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

    public function createMonthlyReport(Request $request) 
    {
        // * validate request
        if( !$request->has('gd') || !$request->has('y') || !$request->has('m') ){
            return redirect()->back()->withErrors([
                "message" => "General Direction, Year and Month parameters are required."
            ])->withInput();
        }

        // * prepared variables
        $generalDirection = null;
        $year = $request->query('y', 0);
        $month = $request->query('m', 0);
        $dateReport = Date::createFromFormat('Y-m-d', $year.'-'.$month.'-01');
        $includeAllEmployees = $request->query('a', 0) == 1;
        $AUTH_USER = Auth::user();

        // * generate breadcumbs for the view
        $breadcrumbs = array(
            ["name"=> "Inicio", "href"=> "/dashboard"],
            ["name"=> "Generar reportes", "href"=> route('reports.index') ],
            ["name"=> "Reporte mensual de " . $dateReport->format('M Y'), "href" => ""],
        );

        // * retrive the general_direction based on user level
        if( $AUTH_USER->level_id == 1 && $request->has('gd') )/*Admin*/{
            $generalDirection = GeneralDirection::where('id', $request->query('gd') )->first();
        }
        else {
            $generalDirection = GeneralDirection::where('id', $AUTH_USER->general_direction_id)->first();
        }

        // * make the report
        $reportData = $this->makeMonthlyReport($AUTH_USER, $dateReport, $generalDirection, $includeAllEmployees);

        // * retornar vista
        return Inertia::render("Reports/Monthly", [
            "title" => "Generando reporte mensual de " . $dateReport->format("M Y"),
            "breadcrumbs" => $breadcrumbs,
            "reportId" => $reportData->id
        ]);
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

    public function downloadMonthlyReporte(Request $request, $report_name){

        $filePath = sprintf("tmp/monthlyreports/$report_name");

        // * validate if the report exist
        if( !Storage::disk('local')->exists( $filePath)){
            return response()->json([
                "message" => "El reporte que está tratando de acceder no se encuentra disponible."
            ], 404);
        };

        // * download the file
        $name = "reporte-mensual.xlsx";
        return Storage::disk('local')->download($filePath, $name);

    }

    public function verifyMonthlyReporte(Request $request, string $reportId): JsonResponse{

        // * attempt to get the report
        $reportData = MonthlyRecord::find($reportId);
        if($reportData == null){
            return response()->json( [
                "message" => "El reporte seleccionado no esta disponible."
            ], 409);
        }

        // * attempt to process info attached to the report record
        $reportProcess = $reportData->process;
        if($reportProcess == null){
            return response()->json( [
                "message" => "El process reporte seleccionado no esta disponible."
            ], 409);
        }


        // * verify process status
        if( $reportProcess->status == "success"){

            // * return the data related to the report created
            $filePath = $reportData->filePath;

            $size = Storage::disk('local')->size($filePath);
            $sizeInKB = number_format($size / 1024, 2);

            return response()->json( [
                "status" => $reportProcess->status,
                "message" => $reportProcess->output,
                "reportData" => [
                    "fileName" => basename($filePath),
                    "date" => Carbon::now()->format("Y-m-d H:i:s"),
                    "userName" => Auth::user()->name,
                    "size" => $sizeInKB . " KB"
                ],
                "finish" => Carbon::parse($reportProcess->updated_at)->format("H:i:s"),
            ], 200);
        }

        return response()->json( [
            "status" => $reportProcess->status,
            "message" => $reportProcess->output,
            "finish" => Carbon::parse($reportProcess->updated_at)->format("H:i:s"),
        ], 200);

    }


    #region private functions
    /**
     * makeDailyReport
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

    /**
     * makeMonthlyReportV2
     *
     * @param  mixed $AUTH_USER
     * @param  mixed $dateReport
     * @param  GeneralDirection $generalDirection
     * @param  bool $includeAllEmployees
     * @return MonthlyRecord
     */
    private function makeMonthlyReport($AUTH_USER, $dateReport, $generalDirection, $includeAllEmployees ){

        // * create a mongo monthy report record
        $reportData = new MonthlyRecord([
            'general_direction_id'=> $generalDirection->id,
            'year' => $dateReport->year,
            'month' => $dateReport->month,
            'all_employees' => $includeAllEmployees,
            'filePath' => null
        ]);
        if($AUTH_USER->level_id > 2){
            $reportData->general_direction_id = $AUTH_USER->direction_id;
        }
        if($AUTH_USER->level_id > 3){
            $reportData->subdirectorate_id = $AUTH_USER->subdirectorate_id;
        }
        if($AUTH_USER->level_id > 4){
            $reportData->department_id = $AUTH_USER->department_id;
        }
        $reportData->save();


        // * get the employees for the report
        $employees = $this->getEmployees( $generalDirection->id, [
            'directionId' => ($AUTH_USER->level_id > 2) ?$AUTH_USER->direction_id :null,
            'subdirectorateId' => ($AUTH_USER->level_id > 3) ?$AUTH_USER->subdirectorate_id :null,
            'departmentId' => ($AUTH_USER->level_id > 4) ?$AUTH_USER->department_id :null,
        ]);

        Log::debug("Dispatching the report queue");
        \App\Jobs\MakeMonthlyReport::dispatch($reportData, $employees );

        return $reportData;
    }

    /**
     * @param  mixed $generalDirectionId
     * @param  mixed $options
     * @return array
     */
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

        return $employeesQuery->orderBy('name', 'ASC')->get()->all();
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

    #endregion

}