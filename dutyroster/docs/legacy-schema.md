# Legacy Database — Schema Reference

_Extracted from the legacy INFORMATION_SCHEMA dump: **123 tables**, 1448 columns._

- **Live/functional tables:** 61
- **Monthly archives + backups (not detailed here):** 62 (`Atten_MMYYYY` per-month partitions, `*BK*` backups)

The rebuilt app targets these table/column names directly (drop-in on the live DB).

## Allot_Shift  (6 cols)

| # | Column | Type | Null |
|---|--------|------|------|
| 1 | ID | int | NO |
| 2 | Empid | int | NO |
| 3 | CurrentMonth | datetime | YES |
| 4 | Deleted | bit | YES |
| 5 | Operatorid | int | YES |
| 6 | TotalHours | numeric | YES |

## Allot_ShiftDetails  (11 cols)

| # | Column | Type | Null |
|---|--------|------|------|
| 1 | AllotId | int | YES |
| 2 | Shiftid | int | YES |
| 3 | InTime | datetime | YES |
| 4 | OutTime | datetime | YES |
| 5 | ShiftDay | int | YES |
| 6 | Deleted | bit | YES |
| 7 | InTime1 | datetime | YES |
| 8 | OutTime1 | datetime | YES |
| 9 | RequestId | int | YES |
| 10 | Modify | int | YES |
| 11 | TotalHours | numeric | YES |

## AllotShift  (6 cols)

| # | Column | Type | Null |
|---|--------|------|------|
| 1 | ID | int | NO |
| 2 | Empid | int | NO |
| 3 | CurrentMonth | datetime | NO |
| 4 | Deleted | bit | NO |
| 5 | operatorid | int | NO |
| 6 | TotalHours | numeric | YES |

## AllotShiftDetail  (11 cols)

| # | Column | Type | Null |
|---|--------|------|------|
| 1 | AllotId | int | NO |
| 2 | Shiftid | int | NO |
| 3 | Intime | datetime | YES |
| 4 | Outtime | datetime | YES |
| 5 | ShiftDay | varchar(30) | NO |
| 6 | Deleted | bit | NO |
| 7 | InTime1 | datetime | YES |
| 8 | OutTime1 | datetime | YES |
| 9 | halfdayleavetype | int | NO |
| 10 | ShiftDate | datetime | YES |
| 11 | TotalHours | numeric | YES |

## AllotShiftDetailLeave  (11 cols)

| # | Column | Type | Null |
|---|--------|------|------|
| 1 | AllotId | int | NO |
| 2 | Shiftid | int | NO |
| 3 | Intime | datetime | YES |
| 4 | Outtime | datetime | YES |
| 5 | ShiftDay | varchar(30) | NO |
| 6 | Deleted | bit | NO |
| 7 | InTime1 | datetime | YES |
| 8 | OutTime1 | datetime | YES |
| 9 | halfdayleavetype | int | NO |
| 10 | ShiftDate | datetime | YES |
| 11 | TotalHours | numeric | YES |

## attendance  (7 cols)

| # | Column | Type | Null |
|---|--------|------|------|
| 1 | ID | int | NO |
| 2 | Empid | int | NO |
| 3 | CurrentMonth | datetime | NO |
| 4 | AttendedDays | numeric | YES |
| 5 | LeaveDays | numeric | YES |
| 6 | Deleted | bit | NO |
| 7 | Operatorid | int | YES |

## attendancehistory  (13 cols)

| # | Column | Type | Null |
|---|--------|------|------|
| 1 | EmpId | varchar(10) | YES |
| 2 | ToDate | datetime | YES |
| 3 | FirstIn | datetime | YES |
| 4 | FirstOut | datetime | YES |
| 5 | SecondIn | datetime | YES |
| 6 | SecondOut | datetime | YES |
| 7 | Status | char(1) | YES |
| 8 | ModifiedBy | varchar(10) | YES |
| 9 | ModifiedDateTime | datetime | YES |
| 10 | PrevFirstIn | datetime | YES |
| 11 | PrevFirstOut | datetime | YES |
| 12 | PrevSecondIn | datetime | YES |
| 13 | PrevSecondOut | datetime | YES |

## CHECKINOUT_24  (5 cols)

| # | Column | Type | Null |
|---|--------|------|------|
| 1 | Pin | nvarchar(20) | NO |
| 2 | CheckTime | datetime | NO |
| 3 | CheckType | tinyint | NO |
| 4 | IsLoaded | int | NO |
| 5 | ID | int | NO |

## CurrentDetails  (27 cols)

| # | Column | Type | Null |
|---|--------|------|------|
| 1 | Empid | int | NO |
| 2 | CurrentMonth | datetime | NO |
| 3 | BasicSalary | numeric | YES |
| 4 | HRA | numeric | YES |
| 5 | RiskAllow | numeric | YES |
| 6 | HealthPlan | numeric | YES |
| 7 | FamilyPlan | numeric | YES |
| 8 | Trsp | numeric | YES |
| 9 | OtherAllow1 | numeric | YES |
| 10 | OtherAllow2 | numeric | YES |
| 11 | GrossSalary | numeric | YES |
| 12 | OtherEarnings | numeric | YES |
| 13 | Incometax | numeric | YES |
| 14 | OtherAllowance | numeric | YES |
| 15 | Deleted | bit | YES |
| 16 | OperatorID | int | YES |
| 17 | PositionAllowance | numeric | YES |
| 18 | CommunicationAllownace | numeric | YES |
| 19 | DutyManagerAllowance | numeric | YES |
| 20 | SpecialAllowance | numeric | YES |
| 21 | NatureOfWorkAllownace | numeric | YES |
| 22 | BlockLeaderAllownace | numeric | YES |
| 23 | MealAllownace | numeric | YES |
| 24 | EducationalAllownace | numeric | YES |
| 25 | FixedIncentive | numeric | YES |
| 26 | Overtime | numeric | YES |
| 27 | SalaryAdjust | numeric | YES |

## CurrentMonth  (125 cols)

| # | Column | Type | Null |
|---|--------|------|------|
| 1 | Empid | int | NO |
| 2 | Basicpay | numeric | YES |
| 3 | Basicsalary | numeric | YES |
| 4 | CurrentMonth | datetime | YES |
| 5 | NoofDaysattended | numeric | YES |
| 6 | LEAVE | numeric | YES |
| 7 | NHoliDays | numeric | YES |
| 8 | OverTime | numeric | YES |
| 9 | VDA | numeric | YES |
| 10 | HRA | numeric | YES |
| 11 | HousingDeduction | numeric | YES |
| 12 | TotalSalary | numeric | YES |
| 13 | FixedIncentive | numeric | YES |
| 14 | Trsp | numeric | YES |
| 15 | SpecialAmt | numeric | YES |
| 16 | OtherAllow1 | numeric | YES |
| 17 | OtherAllow2 | numeric | YES |
| 18 | MisAll | numeric | YES |
| 19 | LTA | numeric | YES |
| 20 | ExecutiveAllow | numeric | YES |
| 21 | FandB | numeric | YES |
| 22 | MedicalAllow | numeric | YES |
| 23 | MedicalPositiveAdjust | numeric | YES |
| 24 | FIT | numeric | YES |
| 25 | ResidentialAllow | numeric | YES |
| 26 | LoanAmount | numeric | YES |
| 27 | LeaveEncash | numeric | YES |
| 28 | ExtraDuty | numeric | YES |
| 29 | NHolidayAmt | numeric | YES |
| 30 | OtherEarnings | numeric | YES |
| 31 | LastMonthAmt | numeric | YES |
| 32 | PFAllow | numeric | YES |
| 33 | Arrear | numeric | YES |
| 34 | TotalEarnings | numeric | YES |
| 35 | PF | numeric | YES |
| 36 | ProfTax | numeric | YES |
| 37 | AFBFFund | numeric | YES |
| 38 | BusPas | numeric | YES |
| 39 | UnionAmt | numeric | YES |
| 40 | Advance | numeric | YES |
| 41 | MedBillamt | numeric | YES |
| 42 | AFBFLoan | numeric | YES |
| 43 | bankloan | numeric | YES |
| 44 | LICAmt | numeric | YES |
| 45 | IT | numeric | YES |
| 46 | ExtraPayment | numeric | YES |
| 47 | FestivalAmt | numeric | YES |
| 48 | OtherDed1 | numeric | YES |
| 49 | OtherDed2 | numeric | YES |
| 50 | Misded | numeric | YES |
| 51 | HostelStay | numeric | YES |
| 52 | ExtraDutyADJ | numeric | YES |
| 53 | SDebtor | numeric | YES |
| 54 | TotalDeduction | numeric | YES |
| 55 | NetPayment | numeric | YES |
| 56 | LossOfPay | numeric | YES |
| 57 | total | numeric | YES |
| 58 | Deleted | bit | NO |
| 59 | bankid | int | YES |
| 60 | Accno | varchar(50) | YES |
| 61 | AccTypeid | int | YES |
| 62 | Mode | int | YES |
| 63 | Operatorid | int | YES |
| 64 | Centerid | int | YES |
| 65 | Categoryid | int | YES |
| 66 | Designationid | int | YES |
| 67 | Departmentid | int | YES |
| 68 | BasicOverTime | numeric | YES |
| 69 | Allowance | numeric | YES |
| 70 | PositiveAdjust | numeric | YES |
| 71 | WaterCharges | numeric | YES |
| 72 | IQMA | numeric | YES |
| 73 | Penalty | numeric | YES |
| 74 | ElectricityBill | numeric | YES |
| 75 | GOSI | numeric | YES |
| 76 | FamilyPlan | numeric | YES |
| 77 | HealthPlan | numeric | YES |
| 78 | PhoneBills | numeric | YES |
| 79 | MealBills | numeric | YES |
| 80 | Sponsorship | numeric | YES |
| 81 | NegativeAdjust | numeric | YES |
| 82 | Adjustment | numeric | YES |
| 83 | RiskAllow | numeric | YES |
| 84 | SalaryAdjust | numeric | YES |
| 85 | FreeMeals | numeric | YES |
| 86 | Lates | numeric | YES |
| 87 | Absences | numeric | YES |
| 88 | Undertimes | numeric | YES |
| 89 | MedPosAdjust | numeric | YES |
| 90 | VacationLeave | numeric | YES |
| 91 | MarriageLeave | numeric | YES |
| 92 | MaternityLeave | numeric | YES |
| 93 | ChildLeave | numeric | YES |
| 94 | SickLeave | numeric | YES |
| 95 | LeaveVsOT | numeric | YES |
| 96 | TicketExps | numeric | YES |
| 97 | unpaidleave | numeric | YES |
| 98 | MedNegAdjust | numeric | YES |
| 99 | UnpaidSalary | numeric | YES |
| 100 | payabledays | numeric | YES |
| 101 | unpaidleavedays | numeric | YES |
| 102 | absentdays | numeric | YES |
| 103 | otherloan | money | YES |
| 104 | ExitReEntryAmt | money | YES |
| 105 | ReplacementAmt | money | YES |
| 106 | Mobile | money | YES |
| 107 | PositionAllowance | numeric | YES |
| 108 | CommunicationAllownace | numeric | YES |
| 109 | DutyManagerAllowance | numeric | YES |
| 110 | SpecialAllowance | numeric | YES |
| 111 | NatureOfWorkAllownace | numeric | YES |
| 112 | BlockLeaderAllownace | numeric | YES |
| 113 | MealAllownace | numeric | YES |
| 114 | EducationalAllownace | numeric | YES |
| 115 | WorkingDaysSalary | numeric | YES |
| 116 | VacationDays | smallint | YES |
| 117 | StateID | smallint | YES |
| 118 | CompensationOff | numeric | YES |
| 119 | CompensationOffDays | float | YES |
| 120 | BereavementLeave | numeric | YES |
| 121 | BereavementLeaveDays | float | YES |
| 122 | OffialLeave | numeric | YES |
| 123 | OffialLeaveDays | float | YES |
| 124 | OtherEarningsAdj | numeric | YES |
| 125 | Refund | numeric | YES |

## Department  (29 cols)

| # | Column | Type | Null |
|---|--------|------|------|
| 1 | Id | smallint | NO |
| 2 | DeptCode | varchar(10) | NO |
| 3 | Name | varchar(50) | NO |
| 4 | AccountCode | varchar(20) | YES |
| 5 | DeptClassID | varchar(5) | YES |
| 6 | RecordID | varchar(5) | YES |
| 7 | StartDateTime | datetime | NO |
| 8 | Deleted | bit | NO |
| 9 | EndDateTime | datetime | YES |
| 10 | OperatorID | int | YES |
| 11 | ModifiedBy | int | YES |
| 12 | ModifiedDateTime | datetime | YES |
| 13 | arabiccode | varchar(20) | YES |
| 14 | ArabicName | varchar(100) | YES |
| 15 | BSCName | varchar(50) | YES |
| 16 | Ora_Code | varchar(10) | YES |
| 17 | UPLOADED | tinyint | YES |
| 18 | UDATETIME | datetime | YES |
| 19 | ADName | nvarchar(100) | YES |
| 20 | NewSCM_SUBINV_ORA_ID | int | YES |
| 21 | NewSCM_SUBINV_ORA_CODE | varchar(50) | YES |
| 22 | NewSCM_ORG_ORA_ID | int | YES |
| 23 | NewSCM_ORG_ORA_CODE | varchar(50) | YES |
| 24 | NewSCM_ORG_ORA_NAME | varchar(250) | YES |
| 25 | NewSCM_LOC_ORA_ID | int | YES |
| 26 | NewSCM_LOC_ORA_CODE | varchar(250) | YES |
| 27 | NewSCM_SUBINV_ORA_NAME | varchar(250) | YES |
| 28 | SCMLocator | varchar(15) | YES |
| 29 | TypeID | varchar(15) | YES |

## DepartmentNew  (29 cols)

| # | Column | Type | Null |
|---|--------|------|------|
| 1 | ID | int | NO |
| 2 | DeptCode | varchar(10) | NO |
| 3 | Name | varchar(50) | NO |
| 4 | AccountCode | varchar(20) | YES |
| 5 | DeptClassID | varchar(5) | YES |
| 6 | RecordID | varchar(5) | YES |
| 7 | StartDateTime | smalldatetime | NO |
| 8 | Deleted | bit | NO |
| 9 | EndDateTime | smalldatetime | YES |
| 10 | OperatorID | int | YES |
| 11 | ModifiedBy | int | YES |
| 12 | ModifiedDateTime | smalldatetime | YES |
| 13 | DivisionId | int | YES |
| 14 | ArabicName | nvarchar(100) | YES |
| 15 | ArabicCode | nvarchar(100) | YES |
| 16 | OLDID | int | YES |
| 17 | NonSGHDept | tinyint | YES |
| 18 | Ora_Code | varchar(50) | YES |
| 19 | UPLOADED | bit | YES |
| 20 | UDATETIME | smalldatetime | YES |
| 21 | BSCName | varchar(50) | YES |
| 22 | DDBArabicName | varchar(50) | YES |
| 23 | NewSCM_SUBINV_ORA_CODE | varchar(50) | YES |
| 24 | NewSCM_ORG_ORA_ID | int | YES |
| 25 | NewSCM_ORG_ORA_CODE | varchar(15) | YES |
| 26 | NewSCM_ORG_ORA_NAME | varchar(1000) | YES |
| 27 | NewSCM_LOC_ORA_ID | int | YES |
| 28 | NewSCM_LOC_ORA_CODE | varchar(250) | YES |
| 29 | NewSCM_SUBINV_ORA_NAME | varchar(250) | YES |

## departmentorder  (14 cols)

| # | Column | Type | Null |
|---|--------|------|------|
| 1 | id | int | NO |
| 2 | datetime | datetime | YES |
| 3 | stationid | int | NO |
| 4 | destinationid | int | NO |
| 5 | operatorid | int | NO |
| 6 | referenceno | varchar(20) | YES |
| 7 | status | tinyint | YES |
| 8 | stationslno | int | NO |
| 9 | categoryid | int | NO |
| 10 | deptid | int | YES |
| 11 | prRaised | bit | YES |
| 12 | uploaded | int | YES |
| 13 | udatetime | datetime | YES |
| 14 | StationSeq | int | YES |

## departmentorderdetail  (6 cols)

| # | Column | Type | Null |
|---|--------|------|------|
| 1 | orderid | int | NO |
| 2 | itemid | int | NO |
| 3 | quantity | int | YES |
| 4 | unitid | int | NO |
| 5 | slno | int | NO |
| 6 | remarks | varchar(40) | YES |

## DepartmentwiseAdjustment  (3 cols)

| # | Column | Type | Null |
|---|--------|------|------|
| 1 | Deptcode | varchar(10) | YES |
| 2 | IPCashamount | numeric | YES |
| 3 | IPChargeAmount | numeric | YES |

## desgmaster  (7 cols)

| # | Column | Type | Null |
|---|--------|------|------|
| 1 | ID | int | NO |
| 2 | Name | varchar(30) | NO |
| 3 | OrderPlaced | int | YES |
| 4 | Startdatetime | datetime | YES |
| 5 | Deleted | bit | NO |
| 6 | Enddatetime | datetime | YES |
| 7 | Operatorid | int | YES |

## desgmasterdetail  (2 cols)

| # | Column | Type | Null |
|---|--------|------|------|
| 1 | DesgMasterid | int | YES |
| 2 | Designationid | int | YES |

## Designation  (11 cols)

| # | Column | Type | Null |
|---|--------|------|------|
| 1 | ID | int | NO |
| 2 | Code | varchar(30) | YES |
| 3 | Name | varchar(100) | YES |
| 4 | Startdatetime | smalldatetime | YES |
| 5 | Deleted | bit | NO |
| 6 | Enddatetime | smalldatetime | YES |
| 7 | Category | int | YES |
| 8 | operatorid | int | YES |
| 9 | modifiedby | int | YES |
| 10 | Modifieddatetime | datetime | YES |
| 11 | ArabicName | varchar(100) | YES |

## DesignationContract  (8 cols)

| # | Column | Type | Null |
|---|--------|------|------|
| 1 | ID | int | NO |
| 2 | Code | varchar(30) | YES |
| 3 | Name | varchar(100) | YES |
| 4 | Startdatetime | smalldatetime | YES |
| 5 | Enddatetime | smalldatetime | YES |
| 6 | Deleted | bit | NO |
| 7 | operatorid | int | YES |
| 8 | Category | int | YES |

## DR_Audit  (6 cols)

| # | Column | Type | Null |
|---|--------|------|------|
| 1 | DocName | varchar(50) | NO |
| 2 | DocID | int | NO |
| 3 | ActionID | int | NO |
| 4 | UserID | varchar(10) | NO |
| 5 | ActionDate | datetime | NO |
| 6 | Reason | varchar(200) | YES |

## DR_ChangeSchedule  (11 cols)

| # | Column | Type | Null |
|---|--------|------|------|
| 1 | RequestID | int | NO |
| 2 | RequestDate | datetime | NO |
| 3 | EmployeeID | int | NO |
| 4 | ScheduleMonth | datetime | NO |
| 5 | ScheduleDay | int | NO |
| 6 | ShiftID | int | NO |
| 7 | AgainstFor | tinyint | NO |
| 8 | ClaimTime | int | NO |
| 9 | ChangeShiftID | int | NO |
| 10 | RejectReason | varchar(200) | YES |
| 11 | StateID | int | NO |

## DR_OverTime  (15 cols)

| # | Column | Type | Null |
|---|--------|------|------|
| 1 | RequestID | int | NO |
| 2 | EmployeeID | int | NO |
| 3 | CategoryID | int | YES |
| 4 | Designation | varchar(50) | NO |
| 5 | RequestDate | datetime | NO |
| 6 | OverTimeDate | datetime | NO |
| 7 | StartOverTime | datetime | NO |
| 8 | EndOverTime | datetime | NO |
| 9 | TotalOverTime | numeric | NO |
| 10 | ReasonID | int | NO |
| 11 | Remarks | varchar(200) | NO |
| 12 | StateID | tinyint | NO |
| 13 | ClaimTime | int | NO |
| 14 | RejectReason | varchar(1000) | NO |
| 15 | IsExpired | tinyint | YES |

## DR_OvertimeReason  (8 cols)

| # | Column | Type | Null |
|---|--------|------|------|
| 1 | ReasonID | tinyint | NO |
| 2 | Reason | varchar(50) | NO |
| 3 | IsOvertime | tinyint | NO |
| 4 | MaximumLimitAdmin | int | NO |
| 5 | MaximumLimitDoctor | int | NO |
| 6 | MaximumLimitNursing | int | NO |
| 7 | Status | tinyint | NO |
| 8 | OvertimeExpiry | smallint | YES |

## DR_VacationPlan  (9 cols)

| # | Column | Type | Null |
|---|--------|------|------|
| 1 | EntryID | int | NO |
| 2 | EntryDate | datetime | NO |
| 3 | DepartmentID | int | NO |
| 4 | DepartmentName | varchar(60) | NO |
| 5 | EmployeeID | varchar(10) | NO |
| 6 | EmployeeName | varchar(60) | NO |
| 7 | FromDate | datetime | NO |
| 8 | ToDate | datetime | NO |
| 9 | TotalDays | int | NO |

## DRMainDashBoard  (11 cols)

| # | Column | Type | Null |
|---|--------|------|------|
| 1 | EmployeeID | varchar(10) | YES |
| 2 | AttendanceDate | datetime | YES |
| 3 | EmployeeName | varchar(152) | YES |
| 4 | Designation | varchar(100) | YES |
| 5 | FirstIn | varchar(15) | YES |
| 6 | FirstOut | varchar(15) | YES |
| 7 | SecondIn | varchar(15) | YES |
| 8 | SecondOut | varchar(15) | YES |
| 9 | OddPunch | int | NO |
| 10 | Absent1 | int | NO |
| 11 | UserID | varchar(10) | NO |

## Employee  (76 cols)

| # | Column | Type | Null |
|---|--------|------|------|
| 1 | ID | int | NO |
| 2 | EmployeeId | varchar(20) | YES |
| 3 | EmpCode | varchar(10) | NO |
| 4 | FirstName | varchar(50) | NO |
| 5 | Middlename | varchar(50) | NO |
| 6 | Lastname | varchar(50) | YES |
| 7 | Sex | int | YES |
| 8 | DesignationId | int | NO |
| 9 | Dob | smalldatetime | YES |
| 10 | Age | varchar(6) | YES |
| 11 | Hadd1 | varchar(250) | YES |
| 12 | HCity | varchar(50) | YES |
| 13 | HState | varchar(50) | YES |
| 14 | HCountry | varchar(50) | YES |
| 15 | HPinCode | varchar(14) | YES |
| 16 | Wadd1 | varchar(250) | YES |
| 17 | WCity | varchar(50) | YES |
| 18 | WState | varchar(50) | YES |
| 19 | WCountry | varchar(50) | YES |
| 20 | WPinCode | varchar(14) | YES |
| 21 | HPhoneNo | varchar(50) | YES |
| 22 | WPhoneNo | varchar(50) | YES |
| 23 | FaxNo | varchar(50) | YES |
| 24 | PagerNo | varchar(40) | YES |
| 25 | CellNo | varchar(40) | YES |
| 26 | EMail | varchar(100) | YES |
| 27 | Qualification | varchar(150) | YES |
| 28 | EPhoneNo | varchar(50) | YES |
| 29 | PlaceOfContact | varchar(100) | YES |
| 30 | ContactTime | varchar(40) | YES |
| 31 | Timings | varchar(60) | YES |
| 32 | Remarks | varchar(250) | YES |
| 33 | StartDateTime | smalldatetime | NO |
| 34 | Deleted | tinyint | NO |
| 35 | EndDateTime | smalldatetime | YES |
| 36 | LastUpdated | smalldatetime | YES |
| 37 | DepartmentId | smallint | NO |
| 38 | Title | tinyint | YES |
| 39 | CategoryID | tinyint | YES |
| 40 | Medical | tinyint | YES |
| 41 | Password | varchar(20) | YES |
| 42 | EAdd1 | varchar(250) | YES |
| 43 | ECity | varchar(50) | YES |
| 44 | EState | varchar(50) | YES |
| 45 | ECountry | varchar(50) | YES |
| 46 | EPincode | varchar(50) | YES |
| 47 | EmployeeType | tinyint | YES |
| 48 | Supervisor | tinyint | YES |
| 49 | EContactPerson | varchar(50) | YES |
| 50 | Name | varchar(152) | YES |
| 51 | NationId | smallint | YES |
| 52 | SubCategoryId | smallint | YES |
| 53 | Regno | int | YES |
| 54 | OPMarkUpPercent | int | YES |
| 55 | ArabicName | varchar(200) | YES |
| 56 | Indent | tinyint | YES |
| 57 | IAcode | varchar(6) | YES |
| 58 | IsUploaded | tinyint | YES |
| 59 | Insert_Update | varchar(7) | YES |
| 60 | OPERATORIDs | int | YES |
| 61 | ARABICCODE | varchar(20) | YES |
| 62 | ContractDesignationID | int | YES |
| 63 | SaudiID | varchar(20) | YES |
| 64 | AFirstName | varchar(50) | YES |
| 65 | AMiddleName | varchar(50) | YES |
| 66 | ALastName | varchar(50) | YES |
| 67 | SectionID | int | YES |
| 68 | IsExcludedCAP | tinyint | YES |
| 69 | UnifiedCode | varchar(10) | YES |
| 70 | IsHead | tinyint | YES |
| 71 | IsBlocked | tinyint | YES |
| 72 | SEQ_No | int | YES |
| 73 | SCHS_LICENSE_NUMBER | varchar(30) | YES |
| 74 | ProbationDate | datetime | YES |
| 75 | IsEligibleToRehire | smallint | YES |
| 76 | OvertimeBalance | float | YES |

## Employee_DutyRoster  (14 cols)

| # | Column | Type | Null |
|---|--------|------|------|
| 1 | ID | int | NO |
| 2 | EmpID | int | YES |
| 3 | EmployeeCode | varchar(50) | YES |
| 4 | EmpName | varchar(50) | YES |
| 5 | Designation | varchar(50) | YES |
| 6 | DesID | int | YES |
| 7 | Department | varchar(50) | YES |
| 8 | DeptID | int | YES |
| 9 | MapStation | varchar(50) | YES |
| 10 | MapStationID | int | YES |
| 11 | Supervisor | int | YES |
| 12 | Password | varchar(15) | YES |
| 13 | Deleted | bit | YES |
| 14 | OvertimeRole | tinyint | NO |

## employeechangedetails  (19 cols)

| # | Column | Type | Null |
|---|--------|------|------|
| 1 | Id | int | NO |
| 2 | Empid | int | YES |
| 3 | OldEmpNo | varchar(20) | YES |
| 4 | NewEmpNo | varchar(20) | YES |
| 5 | OldDeptId | int | YES |
| 6 | NewDeptId | int | YES |
| 7 | OldDesgId | int | YES |
| 8 | NewDesgId | int | YES |
| 9 | OldCatId | int | YES |
| 10 | NewCatId | int | YES |
| 11 | OldCentId | int | YES |
| 12 | NewCentId | int | YES |
| 13 | OldEmpTypeId | int | YES |
| 14 | NewEmpTypeId | int | YES |
| 15 | StartDateTime | datetime | YES |
| 16 | Deleted | bit | YES |
| 17 | OperatorId | int | YES |
| 18 | NewSectionID | int | YES |
| 19 | OldSectionID | int | YES |

## EmployeeFeatures  (3 cols)

| # | Column | Type | Null |
|---|--------|------|------|
| 1 | ModuleId | int | YES |
| 2 | EmployeeId | int | YES |
| 3 | MenuId | int | YES |

## employeeimmunisation  (10 cols)

| # | Column | Type | Null |
|---|--------|------|------|
| 1 | id | int | NO |
| 2 | employeeid | int | YES |
| 3 | Immunisationid | int | YES |
| 4 | frequncey | tinyint | YES |
| 5 | DoseDatetime | datetime | YES |
| 6 | Datetime | datetime | YES |
| 7 | operatorid | int | YES |
| 8 | Stationid | int | YES |
| 9 | ImmuneBY | int | YES |
| 10 | remarks | varchar(100) | YES |

## employeeinvpermission  (2 cols)

| # | Column | Type | Null |
|---|--------|------|------|
| 1 | EmployeeID | int | YES |
| 2 | Type | tinyint | YES |

## EmployeeNew  (95 cols)

| # | Column | Type | Null |
|---|--------|------|------|
| 1 | ID | int | NO |
| 2 | EmployeeID | varchar(50) | YES |
| 3 | EmpCode | varchar(10) | NO |
| 4 | Title | int | YES |
| 5 | FirstName | varchar(50) | NO |
| 6 | MiddleName | varchar(50) | YES |
| 7 | LastName | varchar(50) | YES |
| 8 | Sex | int | YES |
| 9 | DOB | smalldatetime | YES |
| 10 | Age | smallint | YES |
| 11 | HAdd1 | varchar(250) | YES |
| 12 | HCity | varchar(50) | YES |
| 13 | HState | varchar(50) | YES |
| 14 | HCountry | varchar(50) | YES |
| 15 | HPINCode | varchar(50) | YES |
| 16 | HPhoneNo | varchar(50) | YES |
| 17 | WAdd1 | varchar(250) | YES |
| 18 | WCity | varchar(50) | YES |
| 19 | WState | varchar(50) | YES |
| 20 | WCountry | varchar(50) | YES |
| 21 | WPINCode | varchar(50) | YES |
| 22 | WPhoneNo | varchar(50) | YES |
| 23 | FaxNo | varchar(50) | YES |
| 24 | PagerNo | varchar(50) | YES |
| 25 | CellNo | varchar(50) | YES |
| 26 | EMail | varchar(50) | YES |
| 27 | ECity | varchar(50) | YES |
| 28 | EAdd1 | varchar(250) | YES |
| 29 | EState | varchar(50) | YES |
| 30 | ECountry | varchar(50) | YES |
| 31 | EPINcode | varchar(50) | YES |
| 32 | EPhoneNo | varchar(50) | YES |
| 33 | Qualification | varchar(75) | YES |
| 34 | PlaceOfContact | varchar(50) | YES |
| 35 | EContactPerson | varchar(50) | YES |
| 36 | ContactTime | varchar(50) | YES |
| 37 | Timings | varchar(50) | YES |
| 38 | Remarks | varchar(250) | YES |
| 39 | EmployeeType | tinyint | YES |
| 40 | VisitingProf | tinyint | YES |
| 41 | IsPractisingDoctor | tinyint | YES |
| 42 | DivisionID | int | YES |
| 43 | DepartmentID | int | YES |
| 44 | DesignationID | int | YES |
| 45 | CategoryID | int | YES |
| 46 | SubCategoryID | int | YES |
| 47 | Medical | int | YES |
| 48 | Supervisor | tinyint | YES |
| 49 | Name | varchar(122) | YES |
| 50 | ArabicName | nvarchar(100) | YES |
| 51 | NationID | int | YES |
| 52 | IACode | varchar(6) | YES |
| 53 | RegNo | int | YES |
| 54 | OPMarkUpPercent | int | YES |
| 55 | Password | varchar(100) | YES |
| 56 | GLCode | varchar(20) | YES |
| 57 | OperatorID | int | YES |
| 58 | LastUpdated | smalldatetime | YES |
| 59 | StartDateTime | smalldatetime | NO |
| 60 | EndDateTime | smalldatetime | YES |
| 61 | Deleted | int | NO |
| 62 | Indent | bit | YES |
| 63 | SystemName | varchar(50) | YES |
| 64 | LoggedYN | bit | YES |
| 65 | LoggedIPAddress | varchar(50) | YES |
| 66 | Locked_YN | char(1) | YES |
| 67 | PW_SET_DATE | smalldatetime | YES |
| 68 | PWD_SET_DATE | smalldatetime | YES |
| 69 | PWD_EXPIRED_YN | char(1) | YES |
| 70 | USER_START_TIME | smalldatetime | YES |
| 71 | USER_END_TIME | smalldatetime | YES |
| 72 | Insert_Update | char(1) | YES |
| 73 | IsUploaded | bit | YES |
| 74 | TempRegNo | varchar(30) | YES |
| 75 | Arabiccode | nvarchar(50) | YES |
| 76 | OldEmpCode | varchar(15) | YES |
| 77 | ADUserName | varchar(50) | YES |
| 78 | InsuranceNumber | varchar(20) | YES |
| 79 | WorkHours | int | YES |
| 80 | BranchCode | varchar(5) | YES |
| 81 | IsHalfDayDuty | int | YES |
| 82 | TaxId | int | YES |
| 83 | SocialRegisterID | int | YES |
| 84 | SocialNumber | int | YES |
| 85 | SocialId | int | YES |
| 86 | WorkHours_SCS | int | YES |
| 87 | Section_ID | int | YES |
| 88 | IsExpat | int | YES |
| 89 | ArrivalDate | smalldatetime | YES |
| 90 | WorkingDate | smalldatetime | YES |
| 91 | ContractSalary | money | YES |
| 92 | StaffSalary | money | YES |
| 93 | IRCRemarks | varchar(255) | YES |
| 94 | SubContractorId | int | YES |
| 95 | temppw | varchar(100) | YES |

## EmployeePhoto  (3 cols)

| # | Column | Type | Null |
|---|--------|------|------|
| 1 | empid | int | NO |
| 2 | Empphoto | image(2147483647) | YES |
| 3 | Signature | image(2147483647) | YES |

## employeepresent  (57 cols)

| # | Column | Type | Null |
|---|--------|------|------|
| 1 | ID | int | NO |
| 2 | EmployeeId | varchar(20) | YES |
| 3 | EmpCode | varchar(10) | NO |
| 4 | FirstName | varchar(50) | NO |
| 5 | Middlename | varchar(50) | NO |
| 6 | Lastname | varchar(50) | YES |
| 7 | Sex | int | YES |
| 8 | DesignationId | int | YES |
| 9 | Dob | smalldatetime | YES |
| 10 | Age | varchar(6) | YES |
| 11 | Hadd1 | varchar(250) | YES |
| 12 | HCity | varchar(50) | YES |
| 13 | HState | varchar(50) | YES |
| 14 | HCountry | varchar(50) | YES |
| 15 | HPinCode | varchar(14) | YES |
| 16 | Wadd1 | varchar(250) | YES |
| 17 | WCity | varchar(50) | YES |
| 18 | WState | varchar(50) | YES |
| 19 | WCountry | varchar(50) | YES |
| 20 | WPinCode | varchar(14) | YES |
| 21 | HPhoneNo | varchar(50) | YES |
| 22 | WPhoneNo | varchar(50) | YES |
| 23 | FaxNo | varchar(50) | YES |
| 24 | PagerNo | varchar(40) | YES |
| 25 | CellNo | varchar(40) | YES |
| 26 | EMail | varchar(100) | YES |
| 27 | Qualification | varchar(150) | YES |
| 28 | EPhoneNo | varchar(50) | YES |
| 29 | PlaceOfContact | varchar(100) | YES |
| 30 | ContactTime | varchar(40) | YES |
| 31 | Timings | varchar(60) | YES |
| 32 | Remarks | varchar(250) | YES |
| 33 | StartDateTime | smalldatetime | NO |
| 34 | Deleted | bit | NO |
| 35 | EndDateTime | smalldatetime | YES |
| 36 | LastUpdated | smalldatetime | YES |
| 37 | DepartmentId | smallint | YES |
| 38 | Title | tinyint | YES |
| 39 | CategoryID | tinyint | YES |
| 40 | Medical | tinyint | YES |
| 41 | Password | varchar(20) | YES |
| 42 | EAdd1 | varchar(250) | YES |
| 43 | ECity | varchar(50) | YES |
| 44 | EState | varchar(50) | YES |
| 45 | ECountry | varchar(50) | YES |
| 46 | EPincode | varchar(50) | YES |
| 47 | EmployeeType | tinyint | YES |
| 48 | Supervisor | tinyint | YES |
| 49 | EContactPerson | varchar(50) | YES |
| 50 | Name | varchar(152) | YES |
| 51 | NationId | tinyint | YES |
| 52 | SubCategoryId | smallint | YES |
| 53 | Regno | int | YES |
| 54 | OPMarkUpPercent | int | YES |
| 55 | ArabicName | varchar(100) | YES |
| 56 | Indent | bit | YES |
| 57 | IAcode | varchar(6) | YES |

## employeestation  (8 cols)

| # | Column | Type | Null |
|---|--------|------|------|
| 1 | EmployeeID | int | NO |
| 2 | StationID | int | NO |
| 3 | Category | int | YES |
| 4 | ModuleId | tinyint | YES |
| 5 | Permission | tinyint | YES |
| 6 | DoctorHours | int | YES |
| 7 | PrescriptionDays | int | YES |
| 8 | ShowPendingPatients | tinyint | YES |

## empPunchingDetails  (4 cols)

| # | Column | Type | Null |
|---|--------|------|------|
| 1 | empid | varchar(10) | NO |
| 2 | todate | datetime | YES |
| 3 | intime | datetime | YES |
| 4 | outtime | datetime | YES |

## empPunchingDetailsIF  (6 cols)

| # | Column | Type | Null |
|---|--------|------|------|
| 1 | ID | int | NO |
| 2 | empID | varchar(10) | NO |
| 3 | ToDate | datetime | YES |
| 4 | InTime | datetime | YES |
| 5 | OutTime | datetime | YES |
| 6 | IsLoaded | tinyint | YES |

## empPunchingDetailsIFDT  (6 cols)

| # | Column | Type | Null |
|---|--------|------|------|
| 1 | ID | int | NO |
| 2 | empID | varchar(10) | NO |
| 3 | ToDate | datetime | YES |
| 4 | InTime | datetime | YES |
| 5 | OutTime | datetime | YES |
| 6 | IsLoaded | tinyint | YES |

## leave  (10 cols)

| # | Column | Type | Null |
|---|--------|------|------|
| 1 | Name | varchar(30) | NO |
| 2 | StartDateTime | datetime | YES |
| 3 | EndDateTime | datetime | YES |
| 4 | Deleted | bit | NO |
| 5 | OperatorID | int | YES |
| 6 | ID | int | NO |
| 7 | CommonForAll | bit | YES |
| 8 | ShiftID | smallint | YES |
| 9 | IsCalenderDaysSplit | tinyint | YES |
| 10 | IsPHDaysApplied | tinyint | YES |

## LeaveApplication  (33 cols)

| # | Column | Type | Null |
|---|--------|------|------|
| 1 | ID | int | NO |
| 2 | EmpID | int | YES |
| 3 | LeaveID | int | YES |
| 4 | FromDate | datetime | YES |
| 5 | ToDate | datetime | YES |
| 6 | ActualDate | datetime | YES |
| 7 | Reason | varchar(200) | YES |
| 8 | Address | varchar(150) | YES |
| 9 | Deleted | bit | YES |
| 10 | OperatorID | int | NO |
| 11 | NoOfLeaveDays | numeric | YES |
| 12 | PhoneNumber | varchar(20) | YES |
| 13 | EmployeeTicket | bit | YES |
| 14 | SpouseTicket | bit | YES |
| 15 | Child1Ticket | bit | YES |
| 16 | Child2Ticket | bit | YES |
| 17 | Employeevisa | bit | YES |
| 18 | Spousevisa | bit | YES |
| 19 | Child1visa | bit | YES |
| 20 | Child2visa | bit | YES |
| 21 | EarlyDays | int | YES |
| 22 | ActualLeaveDays | numeric | YES |
| 23 | BalanceLeaves | int | YES |
| 24 | EmpContractID | int | YES |
| 25 | Approved | bit | YES |
| 26 | Halfdaydate | datetime | YES |
| 27 | halftype | int | YES |
| 28 | VacationFlag | bit | YES |
| 29 | familyonly | bit | YES |
| 30 | replacementempid | int | YES |
| 31 | VAC_ID | int | YES |
| 32 | PublicHolidays | tinyint | YES |
| 33 | EntryDate | datetime | YES |

## LeaveApplicationCOff  (5 cols)

| # | Column | Type | Null |
|---|--------|------|------|
| 1 | ID | int | YES |
| 2 | FromDate | datetime | YES |
| 3 | ToDate | datetime | YES |
| 4 | TotalDays | float | YES |
| 5 | PublicHolidays | tinyint | YES |

## leavebalance  (11 cols)

| # | Column | Type | Null |
|---|--------|------|------|
| 1 | EmpID | int | YES |
| 2 | Balance | numeric | YES |
| 3 | leaveid | int | YES |
| 4 | todate | datetime | YES |
| 5 | operatorid | int | YES |
| 6 | deleted | bit | YES |
| 7 | remarks | varchar(50) | YES |
| 8 | TicketProvision | numeric | YES |
| 9 | GratuityProvision | numeric | YES |
| 10 | LeaveProvision | numeric | YES |
| 11 | AvailedLeaves | numeric | YES |

## ModulePermission  (6 cols)

| # | Column | Type | Null |
|---|--------|------|------|
| 1 | ModuleId | int | YES |
| 2 | SystemName | varchar(20) | NO |
| 3 | StationId | int | NO |
| 4 | OperatorID | int | YES |
| 5 | ModifiedBy | int | YES |
| 6 | ModifiedDateTime | datetime | YES |

## MonthlyAllowances  (16 cols)

| # | Column | Type | Null |
|---|--------|------|------|
| 1 | empid | int | YES |
| 2 | currentmonth | datetime | YES |
| 3 | PositiveAdjust | numeric | YES |
| 4 | NegativeAdjust | numeric | YES |
| 5 | PhoneBills | numeric | YES |
| 6 | ElecBills | numeric | YES |
| 7 | OtherDed | numeric | YES |
| 8 | deleted | bit | YES |
| 9 | operatorid | int | YES |
| 10 | RefundDays | numeric | YES |
| 11 | RefundMonth | datetime | YES |
| 12 | LatesRefund | numeric | YES |
| 13 | UndertimesRefund | numeric | YES |
| 14 | AbsencesRefund | numeric | YES |
| 15 | Reason | varchar(250) | YES |
| 16 | TicketExpenses | numeric | YES |

## overtime  (11 cols)

| # | Column | Type | Null |
|---|--------|------|------|
| 1 | ID | int | NO |
| 2 | EmpID | int | NO |
| 3 | MonthFor | datetime | YES |
| 4 | TotalOffDayHours | float | YES |
| 5 | TotalOffDayOvertime | numeric | YES |
| 6 | TotalNormalDayHours | float | YES |
| 7 | TotalNormalDayOvertime | numeric | YES |
| 8 | TotalOvertime | numeric | YES |
| 9 | Status | tinyint | YES |
| 10 | Operatorid | int | YES |
| 11 | OvertimeMonth | datetime | YES |

## RA_SystemUsers  (10 cols)

| # | Column | Type | Null |
|---|--------|------|------|
| 1 | EmployeeID | varchar(10) | NO |
| 2 | DischargeNote | tinyint | NO |
| 3 | BillingAuditor | tinyint | NO |
| 4 | RoomClerk | tinyint | NO |
| 5 | Maintenance | tinyint | NO |
| 6 | RoomCleaning | tinyint | NO |
| 7 | SystemUser | tinyint | NO |
| 8 | StateID | tinyint | NO |
| 9 | StationID | tinyint | YES |
| 10 | AllStations | tinyint | YES |

## Schedule_Request  (10 cols)

| # | Column | Type | Null |
|---|--------|------|------|
| 1 | ID | int | NO |
| 2 | DateTime | datetime | YES |
| 3 | DepartmentId | int | YES |
| 4 | ScheduleMonth | datetime | YES |
| 5 | OperatorId | int | YES |
| 6 | Approved | int | YES |
| 7 | Comments | varchar(300) | YES |
| 8 | Uploaded | bit | YES |
| 9 | Modify | bit | YES |
| 10 | Reason | varchar(300) | YES |

## Schedule_RequestActions  (5 cols)

| # | Column | Type | Null |
|---|--------|------|------|
| 1 | RequestID | int | NO |
| 2 | ActionDate | datetime | NO |
| 3 | Comments | varchar(500) | NO |
| 4 | UserID | varchar(20) | NO |
| 5 | ActionID | int | NO |

## ScheduleDay  (9 cols)

| # | Column | Type | Null |
|---|--------|------|------|
| 1 | id | int | NO |
| 2 | DoctorId | int | NO |
| 3 | Day | int | YES |
| 4 | FromTime | datetime | NO |
| 5 | ToTime | datetime | NO |
| 6 | FromDate | datetime | YES |
| 7 | ToDate | datetime | YES |
| 8 | Deleted | bit | NO |
| 9 | Status | smallint | YES |

## ScheduleDayComments  (3 cols)

| # | Column | Type | Null |
|---|--------|------|------|
| 1 | DoctorID | int | NO |
| 2 | MonthFor | datetime | NO |
| 3 | Comments | varchar(500) | NO |

## ScheduleDaySlots  (11 cols)

| # | Column | Type | Null |
|---|--------|------|------|
| 1 | ID | int | NO |
| 2 | DoctorId | int | NO |
| 3 | Day | int | YES |
| 4 | FromTime | datetime | NO |
| 5 | ToTime | datetime | NO |
| 6 | FromDate | datetime | YES |
| 7 | ToDate | datetime | YES |
| 8 | Deleted | bit | NO |
| 9 | Status | smallint | YES |
| 10 | MainScheduleId | int | NO |
| 11 | ScheduleId | int | NO |

## ScheduleDoctor  (10 cols)

| # | Column | Type | Null |
|---|--------|------|------|
| 1 | ID | int | NO |
| 2 | DoctorId | int | NO |
| 3 | Day | int | YES |
| 4 | FromTime | datetime | NO |
| 5 | ToTime | datetime | NO |
| 6 | FromDate | datetime | YES |
| 7 | ToDate | datetime | YES |
| 8 | Deleted | bit | NO |
| 9 | Status | smallint | YES |
| 10 | OperatorID | int | YES |

## scheduler  (4 cols)

| # | Column | Type | Null |
|---|--------|------|------|
| 1 | id | int | YES |
| 2 | name | varchar(50) | YES |
| 3 | TableName | varchar(20) | YES |
| 4 | Deleted | bit | YES |

## ScheduleStatus  (13 cols)

| # | Column | Type | Null |
|---|--------|------|------|
| 1 | Id | int | NO |
| 2 | Name | varchar(30) | YES |
| 3 | StatusColor | varchar(20) | YES |
| 4 | Available | bit | YES |
| 5 | Startdatetime | datetime | YES |
| 6 | Enddatetime | datetime | YES |
| 7 | Deleted | bit | YES |
| 8 | Prompt | varchar(100) | YES |
| 9 | ArabicName | varchar(50) | YES |
| 10 | EnglishName | varchar(50) | YES |
| 11 | IsAllowed | tinyint | YES |
| 12 | DisplayName | varchar(15) | YES |
| 13 | AName | nvarchar(50) | YES |

## Shift  (16 cols)

| # | Column | Type | Null |
|---|--------|------|------|
| 1 | Name | varchar(50) | YES |
| 2 | FromTime | varchar(20) | YES |
| 3 | ToTime | varchar(20) | YES |
| 4 | StartDateTime | datetime | NO |
| 5 | Deleted | bit | NO |
| 6 | EndDateTime | datetime | YES |
| 7 | operatorid | int | YES |
| 8 | ID | int | NO |
| 9 | FromTime1 | varchar(20) | YES |
| 10 | ToTime1 | varchar(20) | YES |
| 11 | IsBlocked | tinyint | YES |
| 12 | split | tinyint | NO |
| 13 | TotalHours | numeric | YES |
| 14 | ColorCode | varchar(50) | YES |
| 15 | IsTwoShifts | tinyint | YES |
| 16 | wColorCode | varchar(10) | YES |

## shift_deleted  (1 cols)

| # | Column | Type | Null |
|---|--------|------|------|
| 1 | ID | int | NO |

## ShiftIDs  (1 cols)

| # | Column | Type | Null |
|---|--------|------|------|
| 1 | ShiftID | int | NO |

## shiftschedulehistory  (15 cols)

| # | Column | Type | Null |
|---|--------|------|------|
| 1 | EmpID | int | YES |
| 2 | Todate | datetime | YES |
| 3 | FirstIn | datetime | YES |
| 4 | FirstOut | datetime | YES |
| 5 | SecondIn | datetime | YES |
| 6 | SecondOut | datetime | YES |
| 7 | PrevFirstIn | datetime | YES |
| 8 | PrevFirstOut | datetime | YES |
| 9 | PrevSecondIn | datetime | YES |
| 10 | PrevSecondOut | datetime | YES |
| 11 | Status | char(1) | YES |
| 12 | ModifiedBy | int | YES |
| 13 | Modifieddatetime | datetime | YES |
| 14 | PrevShiftID | int | YES |
| 15 | PresentShiftID | int | YES |

## shiftvenkat  (10 cols)

| # | Column | Type | Null |
|---|--------|------|------|
| 1 | Name | varchar(50) | YES |
| 2 | FromTime | varchar(20) | YES |
| 3 | ToTime | varchar(20) | YES |
| 4 | StartDateTime | datetime | NO |
| 5 | Deleted | bit | NO |
| 6 | EndDateTime | datetime | YES |
| 7 | operatorid | int | YES |
| 8 | ID | int | NO |
| 9 | FromTime1 | varchar(20) | YES |
| 10 | ToTime1 | varchar(20) | YES |

## SickLeaves  (14 cols)

| # | Column | Type | Null |
|---|--------|------|------|
| 1 | RegistrationNo | int | YES |
| 2 | DoctorID | int | YES |
| 3 | FromDate | datetime | YES |
| 4 | ToDate | datetime | YES |
| 5 | LeaveIssueDate | datetime | YES |
| 6 | IssueAuthority | varchar(6) | YES |
| 7 | OperatorID | int | YES |
| 8 | Diagnosis | varchar(2000) | YES |
| 9 | Justification | varchar(2000) | YES |
| 10 | VisitID | int | YES |
| 11 | StateID | tinyint | YES |
| 12 | SavedDiagnosis | varchar(500) | YES |
| 13 | HoldReason | varchar(100) | YES |
| 14 | RejectReason | varchar(100) | YES |

## Station  (32 cols)

| # | Column | Type | Null |
|---|--------|------|------|
| 1 | ID | int | NO |
| 2 | Name | varchar(50) | NO |
| 3 | Deleted | bit | NO |
| 4 | StartDateTime | datetime | NO |
| 5 | EndDateTime | datetime | YES |
| 6 | StationTypeID | int | NO |
| 7 | Location | varchar(30) | YES |
| 8 | DepartmentId | int | YES |
| 9 | Prefix | varchar(10) | YES |
| 10 | Stores | tinyint | YES |
| 11 | operatorid | int | YES |
| 12 | modifiedby | int | YES |
| 13 | Modifieddatetime | datetime | YES |
| 14 | appid | int | YES |
| 15 | Ora_Code | varchar(15) | YES |
| 16 | ArabicName | varchar(100) | YES |
| 17 | UPLOADED | tinyint | YES |
| 18 | UnifiedCode | varchar(10) | YES |
| 19 | UnifiedName | varchar(100) | YES |
| 20 | OldPrefix | varchar(10) | YES |
| 21 | LabSectionID | varchar(2) | YES |
| 22 | PointOfCareCode | varchar(15) | YES |
| 23 | NewSCM_SUBINV_ORA_ID | int | YES |
| 24 | NewSCM_SUBINV_ORA_CODE | varchar(50) | YES |
| 25 | NewSCM_ORG_ORA_ID | int | YES |
| 26 | NewSCM_ORG_ORA_CODE | varchar(50) | YES |
| 27 | NewSCM_ORG_ORA_NAME | varchar(250) | YES |
| 28 | NewSCM_LOC_ORA_ID | int | YES |
| 29 | NewSCM_LOC_ORA_CODE | varchar(250) | YES |
| 30 | NewSCM_SUBINV_ORA_NAME | varchar(250) | YES |
| 31 | SCMLocator | varchar(15) | YES |
| 32 | SupervisorID | int | YES |

## Archived / backup tables (names only)

`AllotShiftBKMar2024`, `AllotShiftDetailBKMar2024`, `AllotShiftDetailBKP`, `Atten_012022`, `Atten_012023`, `Atten_012024`, `Atten_012025`, `Atten_012026`, `Atten_022022`, `Atten_022023`, `Atten_022024`, `Atten_022025`, `Atten_022026`, `Atten_032022`, `Atten_032023`, `Atten_032024`, `Atten_032025`, `Atten_032026`, `Atten_042022`, `Atten_042023`, `Atten_042024`, `Atten_042025`, `Atten_042026`, `Atten_052022`, `Atten_052023`, `Atten_052024`, `Atten_052025`, `Atten_052026`, `Atten_062022`, `Atten_062023`, `Atten_062024`, `Atten_062025`, `Atten_062026`, `Atten_072021`, `Atten_072022`, `Atten_072023`, `Atten_072024`, `Atten_072025`, `Atten_072026`, `Atten_082022`, `Atten_082023`, `Atten_082024`, `Atten_082025`, `Atten_092022`, `Atten_092023`, `Atten_092024`, `Atten_092025`, `Atten_102022`, `Atten_102023`, `Atten_102024`, `Atten_102025`, `Atten_112022`, `Atten_112023`, `Atten_112024`, `Atten_112025`, `Atten_122022`, `Atten_122023`, `Atten_122024`, `Atten_122025`, `Atten_122026`, `empPunchingDetails_Upto_2010`, `Shift_BK`
