<?php

namespace App\ViewModels;

use App\Models\Employee;

class EmployeeViewModel
{

    public int $id;
    public string $employeeNumber;
    public string $name;
    public ?string $curp;
    public string $abbreviation;  // general direction abbreviation
    public string $generalDirection;
    public int $generalDirectionId;
    public string $direction;
    public int $directionId;
    public int $checa;
    public ?string $photo;
    public string $horario;
    public string $days;
    public bool $active = true;
    public ?string $subDirection = null;
    public ?int $subDirectionId = null;
    public ?string $department = null;
    public ?int $departmentId = null;
    
    public function __construct(int $id, string $employeeNumber, string $name) {
        $this->id = $id;
        $this->employeeNumber = $employeeNumber;
        $this->name = $name;
        $this->abbreviation = "";
        $this->generalDirection = "";
        $this->direction = "";
        $this->checa = 0;
        $this->horario = 'Sin horario asignado';
        $this->days = 'Días no asignados';
    }
    
    /**
     * create a employee view model from the local model
     *
     * @param  Employee $employee
     * @return EmployeeViewModel
     */
    public static function fromEmployeeModel( Employee $employee) : EmployeeViewModel {

        // create the view model
        $model = new EmployeeViewModel(
            $employee->id,
            substr($employee->plantilla_id, 1),
            $employee->name
        );
        $model->checa = $employee->status_id;
        $model->photo = $employee->photo;
        if(isset($employee->active)){
            if(is_bool($employee->active)){
                $model->active = $employee->active;
            }else{
                $model->active = $employee->active === 1;
            }
        }

        $employee->load(['generalDirection', 'direction', 'workingHours', 'workingDays', 'subdirectorate', 'department']);

        if( isset($employee->generalDirection) ){
            $model->abbreviation = $employee->generalDirection->abbreviation;
            $model->generalDirection = $employee->generalDirection->name;
            $model->generalDirectionId = $employee->generalDirection->id;
        }

        if( isset($employee->direction) ){
            $model->direction = $employee->direction->name;
            $model->directionId = $employee->direction->id;
        }

        if($employee->workingHours)  {
            if ($employee->workingHours->checkin) {
                $model->horario = $employee->workingHours->checkin . ' a ' . $employee->workingHours->checkout;
            }
        }

        if($employee->workingDays)  {
            if ($employee->workingHours->checkin) {
                $model->horario = $employee->workingHours->checkin . ' a ' . $employee->workingHours->checkout;
            }
        }
        
        if ($employee->workingDays) {
            $allWeek = 0;
            if ($employee->workingDays->week == 1) {
                $model->days = 'Lun - Vier ';
                $allWeek ++;
            }
            if ($employee->workingDays->weekend == 1) {
                $model->days = ' Sab y Dom';
                $allWeek ++;
            }
            if ($allWeek == 2) {
                $model->days = 'Lun - Dom';
            }
        }

        if( isset($employee->subdirectorate) ){
            $model->subDirection = $employee->subdirectorate->name;
            $model->subDirectionId = $employee->subdirectorate->id;
        }

        if( isset($employee->department) ){
            $model->subDirection = $employee->department->name;
            $model->subDirectionId = $employee->department->id;

        }

        // TODO: retrive the data from the RH database
        // $employeeRh = EmployeeRh::select('NUMEMP', 'NOMBRE', 'APELLIDO', 'RFC')->where('NUMEMP', $employee_number)->first();
        $employeeRh = \App\Services\EmployeeRHService::getEmployeeData( $model->employeeNumber );
        if($employeeRh){
            $model->name = $employeeRh->NOMBRE . ' ' . $employeeRh->APELLIDO;
            $model->curp = $employeeRh->CURP;
        }

        return $model;
    }

}