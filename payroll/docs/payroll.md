# Payroll — design, mapping and open questions

This is a **standalone** payroll app. It has its own login (`Pay_Users`), its
own front controller and its own config, and it connects to the SQL Server
database that holds the HR masters and the legacy payroll register
(`Employee`, `Department`, `CurrentDetails`, `CurrentMonth`), where it creates
its own `Pay_*` tables. Nothing legacy is altered.

## The Duty Roster link

The one thing payroll cannot produce on its own is **attendance**. That
dependency is isolated behind a single switch, `roster_link.enabled`.

```
                                   roster_link.enabled?
                                      │        │
                            OFF (now) │        │ ON (later)
                                      ▼        ▼
  full attendance assumed  ◄──────────┘        └──────────►  roster (AllotShift/Detail)
  (pay full structure,                                       attendance (Atten_MMYYYY)
   prorated for join/leave)                                  approved OT (DR_OverTime)
                                      │                      leave, corrections
                                      ▼                              │
                                PayrollAttendance ◄──────────────────┘
                                      │
                                      ▼
   CurrentDetails ──► PayrollEngine ──► CurrentMonth (register) ──► payslip / bank file
   Pay_* (statutory, loans, holds, encashment, GOSI) ──┘
```

**While the link is OFF (the default)** payroll assumes full attendance: every
payable employee is paid their full structure, prorated only for a mid-month
join or leave. No absence, lates, early-outs or overtime are applied, and the
UI says so with a banner. Everything else — structures, GOSI, loans, holds,
encashment, increments, settlements, register, payslips, bank file — works now.

**Turning the link ON** (config: `roster_link.enabled = true`, once the roster
tables are reachable from this database) makes the calculation
attendance-driven. It is a config change, not a code change.

## Where things are written

| What | Table | New? |
|---|---|---|
| Salary structure per employee per month | `CurrentDetails` | legacy |
| Monthly payroll register | `CurrentMonth` | legacy |
| Ad-hoc monthly adjustments | `MonthlyAllowances` | legacy (read) |
| Run header, state, totals | `Pay_Run` | new |
| Who did what to a run | `Pay_RunAudit` | new |
| Bahraini/expat, GOSI no., CPR, IBAN | `Pay_EmployeeStatutory` | new |
| Effective-dated GOSI rates | `Pay_GosiRate` | new |
| Bank master for WPS | `Pay_Bank` | new |
| Loans and their installments | `Pay_Loan`, `Pay_LoanInstallment` | new |
| End-of-service settlements | `Pay_Settlement` | new |
| Bank files produced | `Pay_WpsExport` | new |

Nothing in the legacy schema is altered. Everything additive is prefixed `Pay_`
and created by [`database/payroll.sqlserver.sql`](../database/payroll.sqlserver.sql),
which is idempotent.

## The period model

Roster is prepared per calendar month; attendance and payroll run on the cutoff
cycle (`PCoff.PCoffDay + 1`, in practice the 16th). A payroll month therefore
covers **16th of the previous month to the 15th of this one**, and
`payroll.month_is_period_end` controls which month it is called. With the
default `true`, the cycle 16 Jun – 15 Jul is the **July** payroll month, and
that is the date written to `CurrentMonth.CurrentMonth` (always the 1st).

## The calculation

For each employee, in order:

1. **Employment factor** — the share of the cycle they were employed for, from
   the joining and leaving dates. A mid-month joiner is paid for their days.
2. **Earnings** — each structure component × the factor when it is marked
   `prorate` in config. Components are defined once in `payroll.components` and
   that one definition drives the structure form, the register columns, the
   payslip and the CSV export.
3. **Rates** — day rate = monthly wage ÷ divisor; the divisor is a fixed 30 by
   default (`payroll.day_rate_basis`). Hour rate = day rate ÷ contract hours.
4. **Absence and unpaid leave** — deducted at the day rate rather than netted
   out of earnings, so the payslip shows the gross the employee was entitled to
   and what was taken off.
5. **Lates and early-outs** — priced per minute after the monthly grace, and
   **waived for any day with an approved `DR_CorrectionRequest`**.
6. **Overtime** — approved `DR_OverTime` only, priced by day type: a normal day,
   a rostered day off (rest day) or a public holiday each get their own
   multiplier.
7. **GOSI** — employee share on the components flagged `gosi`, using the rate in
   force on the payroll month. The employer share is calculated and shown but
   not deducted.
8. **Loans** — the installment due this month, trimmed to the outstanding
   balance so rounding cannot over-recover.

## Run states

```
Draft ──calculate──► Calculated ──approve──► Approved ──lock──► Locked
  ▲                                              │
  └──────────────── reopen ──────────────────────┘
```

- A draft can be recalculated as often as you like; each recalculation replaces
  the month's register rows.
- Approving makes the register read-only. Payslips an employee has seen cannot
  then change underneath them.
- **Locking is where loan installments are posted.** That is deliberate: a draft
  recalculated ten times must not move a loan balance ten times.
- A locked run cannot be reopened. Correct it with an adjustment in the next
  month (`MonthlyAllowances`, or the `arrear` / `pos_adjust` components).

## Assumptions to confirm before the first live run

These are the places where the rebuild had to infer something. Each is a config
or data change, not a code change.

| # | Assumption | Where to change it | How to confirm |
|---|---|---|---|
| 1 | **GOSI rates.** Seeded provisionally (Bahraini 8% employee / 13% employer rising 1pp a year; expat 1% / 4%; ceiling BD 4,000). | `Pay_GosiRate` rows | The current SIO circular / your SIO portal statement |
| 2 | **Indemnity basis.** 15 days per year for the first 3 years, 30 days thereafter, on gross. | `payroll.indemnity` | Bahrain Labour Law art. 111 **and** how the SIO end-of-service scheme applies to your expat staff — the calculator gives the gross entitlement and does not net off anything already remitted |
| 3 | **`DR_OverTime.StateID` values that mean approved.** Defaulted to 5, 6, 14 from the provisional state map in `legacy.dr_states`. | `payroll.ot_paid_states` | The `CASE` in the legacy overtime approval procedure |
| 4 | **`TotalOverTime` unit** — assumed hours. Only used when the start/end datetimes are missing. | `payroll.ot_source_unit` | Compare a known OT row's start/end against its total |
| 5 | **Which leave types are unpaid.** Empty list = everything is paid leave. | `payroll.unpaid_leave_ids` | The `leave` master |
| 6 | **`CurrentMonth.LEAVE`** is written as *paid leave days*, not an amount. | `PayrollEngine::toRegisterRow` | Compare against a historic legacy register row |
| 7 | **Day-rate divisor** — fixed 30. | `payroll.day_rate_basis` | Finance policy |
| 8 | **Lates and early-outs are money.** Both are deducted by default. | `payroll.deduct_lates`, `deduct_undertime` | HR policy — some sites report these without deducting |
| 9 | **WPS file layout.** Follows the common LMRA/CBB field set, not bank-certified. | `WpsExporter::renderSif()` | The paying bank's current specification |
| 10 | **Public holidays** are a config list, not a table. | `payroll.public_holidays` | HR calendar |

Columns in `CurrentMonth` whose meaning could not be established — `total`,
`WorkingDaysSalary`, `LossOfPay`, the `Med*Adjust` family — are deliberately
**not written**. They are left as the legacy system left them rather than being
filled with a guess.

## Database topology (confirmed on the live server)

| Role | Database | Key tables |
|---|---|---|
| Core HRMS / roster | **ASSH** | Employee, Department, Shift, CurrentMonth, CurrentDetails, MonthlyAllowances, AllotShift(Detail), `Atten_MMYYYY`, LeaveApplication, leavebalance, Schedule_Request |
| Companion | **DB_ASSH** | DR_CorrectionRequest, **DR_OverTime**, EmployeeWorkingHours |
| Biometric punches | **zkteco_biotime** | checkinout |
| Test copy | **TestASSH** | all of the above, consolidated into one DB |

The DR_* tables (overtime, corrections) and working-hours targets live in
**DB_ASSH**, not ASSH — on the live server `ASSH.DR_OverTime` holds ~5 rows while
`DB_ASSH.DR_OverTime` holds ~3,963. `PayrollAttendance` reads both through
`legacy.companion_db`, so set it to `DB_ASSH` in production and `''` for the
consolidated TestASSH.

**TestASSH note:** the initial copy double-loaded several transactional tables
(CurrentMonth, CurrentDetails, AllotShiftDetail, LeaveApplication, leavebalance
came in at ~2×) and skipped MonthlyAllowances. Run
[`database/rebuild_testassh.sql`](../database/rebuild_testassh.sql) once to
reload those cleanly from ASSH + DB_ASSH before the dry run, or payroll will
double-count.

## Setting it up

1. Copy `config/config.example.php` to `config/config.php` and set the `db`
   block to the SQL Server database that holds `Employee` / `CurrentMonth`
   (use `TestASSH` first). Leave `roster_link.enabled => false` for now.

2. Create this app's tables — `Pay_Users` (login) plus the `Pay_*` payroll
   tables — and seed the admin user, GOSI rates and banks:

   ```bash
   php ingest/install.php --admin-pass=YOUR_PASSWORD
   ```

   (idempotent; run it against the test database first). Or run
   `database/schema.sqlserver.sql` then `database/seed.sqlserver.sql` by hand.

3. Start it and sign in as `admin`:

   ```bash
   php -S 127.0.0.1:8080 -t public
   ```

4. In config set at least `payroll.roles.*` (who may view / process / approve),
   `payroll.wps.employer_id` (from LMRA) and, for when the roster link is on,
   `payroll.unpaid_leave_ids`.
5. Correct the seeded rows in `Pay_GosiRate`; add any missing banks to
   `Pay_Bank`.
6. Fill `Pay_EmployeeStatutory` — Bahraini flag, GOSI number, IBAN. The run
   screen lists everyone still missing these before you calculate.
7. Enter or import salary structures (`Structures`).
8. When the Duty Roster tables are reachable from this database, set
   `roster_link.enabled => true` to switch from assumed-full-attendance to real
   attendance-driven payroll.

## Parallel run

Before payroll goes live, run it alongside the current process for at least one
full month:

1. Open the month and calculate.
2. Export the register to CSV and reconcile it line by line against the current
   payroll output — start with gross, then GOSI, then net.
3. Every difference is either a data gap (missing structure, missing statutory
   record) or one of the ten assumptions above. Fix it in config or data.
4. Only when a month reconciles to the fils should the run be approved and
   locked.

## Screens

| Screen | Route | Role |
|---|---|---|
| Payroll months | `/payroll` | view |
| One run: calculate, approve, lock | `/payroll/run?id=` | process / approve |
| Monthly register (+ CSV) | `/payroll/register` | view |
| Salary structures | `/payroll/structures`, `/payroll/structure` | view / process |
| Payslip | `/payroll/payslip` | own slip: anyone |
| Printable payslip | `/payroll/payslip/print` | own slip: anyone |
| Salary increment | `/payroll/increment` | process |
| Loans and advances | `/payroll/loans` | view / process |
| Salary hold & release | `/payroll/holds` | view / process |
| Leave encashment | `/payroll/encashment` | view / process / approve |
| Indemnity provision | `/payroll/indemnity` | view / process |
| Leave provision | `/payroll/leave-provision` | view / process |
| End-of-service settlement | `/payroll/settlement` | view / process |
| Employee master (add / edit) | `/payroll/employees` | process |
| HR desk — leave / requests / CME | `/hr/leave`, `/hr/requests`, `/hr/cme` | process |
| Bank file | `/payroll/wps?id=` | approve |

### Employee self-service (any signed-in employee)

| Screen | Route |
|---|---|
| Self-service home | `/me` |
| My payslips (view + print) | `/me/payslips` |
| Submit / track leave | `/me/leave` |
| Requests to HR | `/me/hr` |
| Training (CME) hours | `/me/cme` |

## Employee master

Add and edit staff at `/payroll/employees`. Records are written to the shared HR
master (`Employee`), so an employee added here is the same record the Duty
Roster sees — there is no separate payroll employee list to reconcile. Only the
fields payroll needs are captured; salary and bank details are set on the
structure screen (the new-employee flow goes straight there).

## Leave provision

Accrued untaken annual leave, valued on the **latest basic** salary, for every
active employee: entitlement (pro-rated to service), used, balance, and the
portion above the carry-over cap that is **forfeited** (and so not a liability).
Balance and used come from the HR `leavebalance` / `LeaveApplication` tables
when reachable; otherwise they derive from the configured entitlement, so the
screen works before the roster link is live. Snapshots to `Pay_LeaveProvision`,
same as the indemnity provision. Configured under `payroll.leave_provision`.

## Self-service and the HR desk

Employees get their own scoped area (`/me/*`): payslips, leave submission, HR
requests, and CME training hours logged against a required target. HR / Finance
act on these at the HR desk (`/hr/*`) — approve or reject leave, respond to and
close HR requests, set per-employee CME requirements and verify logged
activities. Leave requests are held in `Pay_LeaveRequest` on the payroll side
for now; when the roster link is live they can be pushed into the HR
`LeaveApplication` table. CME requirements/activities live in `Pay_CmeRequirement`
and `Pay_CmeActivity`; the yearly default is `payroll.cme.required_hours_per_year`.

### CME requirement master (by category)

Required CME hours are defined **by staff category** at `/hr/cme/categories`
(e.g. Doctor 50, Nursing 35, Admin 15), stored in `Pay_CmeCategoryRequirement`.
An individual employee's target resolves in this order:

1. a per-employee override (set on the compliance screen), else
2. their staff category's requirement (`Employee.CategoryID`), else
3. the global default (`payroll.cme.required_hours_per_year`).

Categories are taken from the `payroll.staff_categories` (id → name) config map;
if that is empty, the master lists whatever `CategoryID` values appear on
employees so you can label them and set hours. The compliance screen shows each
person's target with its source — (cat) / (emp) / (def).

## Indemnity provision

The provision screen accrues the end-of-service liability for **every active
employee** as of a reporting date — the balance-sheet number, not a leaver's
payout. It uses the same `IndemnityCalculator` as the settlement, so an
employee's provision always equals what they would be paid if they left that
day.

- **As-of date + department filter**, total provision and accrued days.
- **Snapshot** a reporting date to keep the month-end balance; then **compare
  to** a saved snapshot to get the period charge (movement) per employee and in
  total — that difference is the accrual to post.
- **CSV export** for the accounts.
- Employees with no joining date or no salary structure are listed as
  exceptions (they accrue nothing until fixed).

By default the provision accrues from day one (`provision_min_service_months =>
0`) even though the settlement pays nothing below 3 months; set it to 3 to make
them match. Snapshots are stored in `Pay_IndemnityProvision`.

## Salary hold, release, encashment and increment

These mirror the legacy HRMS `Salary Details` menu (see the screenshots in
`HRMS/`).

**Salary hold / release** — holding a payroll month leaves the employee's salary
on the register (auditable) but excludes them from that month's bank file. The
withheld net is stamped when the held month is **locked**, so it is final before
it can be released. Releasing pays the accumulated held net in a chosen later
month as an **arrear** earning. Held employees show on the run screen and are
listed as exclusions on the bank-file screen.

**Leave encashment** (standalone) — pays out unused annual leave into a chosen
month, priced from the salary structure. A request is Pending → Approved (by an
approver) → Paid (automatically when the target month locks). Approved requests
are added to that month's `LeaveEncash` earning. End-of-service encashment is
separate and lives in the settlement.

**Salary increment** — a front-end over the effective-dated structure: raise
ticked components by a percentage, add a flat amount to Basic, or type new
figures. Applying inserts a new structure row from the effective month; earlier
months keep their figures, so historic payroll still reconciles.
