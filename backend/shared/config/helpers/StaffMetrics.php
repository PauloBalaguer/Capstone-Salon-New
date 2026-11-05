<?php

/**
 * Staff performance rules, shared by the staff, admin and cashier portals so
 * a stylist's numbers match everywhere they're shown.
 */
final class StaffMetrics
{
    /** A customer's review moves a booking from Completed to Reviewed; both are finished work. */
    public const FINISHED_STATUSES_SQL = "('Completed', 'Reviewed')";

    /**
     * SQL condition: the employee is on this appointment, as its main stylist
     * or for one of its services. $employee is a column name or a "?"
     * placeholder (a placeholder must be bound twice).
     */
    public static function worksOnSql(string $appointmentAlias, string $employee): string
    {
        return "({$appointmentAlias}.employee_id = {$employee} OR EXISTS ("
            . "SELECT 1 FROM appointment_services worked_on "
            . "WHERE worked_on.appointment_id = {$appointmentAlias}.id AND worked_on.employee_id = {$employee}))";
    }

    /** SQL subquery: days this employee clocked in during the last 30 days. */
    public static function attendedDaysSql(string $employeeAlias): string
    {
        return "(SELECT COUNT(DISTINCT DATE(attended.clock_in_time)) FROM attendance attended
            WHERE attended.employee_id = {$employeeAlias}.id
            AND attended.clock_in_time >= (NOW() - INTERVAL 30 DAY))";
    }

    /**
     * SQL subquery: days in the last 30 that the employee's branch operated
     * (any of its staff clocked in), counted from their hire date. There is
     * no staff work schedule, so this is the closest record of the days they
     * could have worked: closed days and days before they joined don't count
     * against them, but a personal day off still does.
     */
    public static function branchOpenDaysSql(string $employeeAlias): string
    {
        return "(SELECT COUNT(DISTINCT DATE(branch_day.clock_in_time)) FROM attendance branch_day
            JOIN employees colleague ON colleague.id = branch_day.employee_id
            WHERE colleague.branch_id <=> {$employeeAlias}.branch_id
            AND branch_day.clock_in_time >= (NOW() - INTERVAL 30 DAY)
            AND ({$employeeAlias}.hire_date IS NULL OR DATE(branch_day.clock_in_time) >= {$employeeAlias}.hire_date))";
    }

    public static function attendanceRate(int $attendedDays, int $branchOpenDays): int
    {
        if ($attendedDays <= 0 || $branchOpenDays <= 0) {
            return 0;
        }
        return min(100, (int) round(($attendedDays / $branchOpenDays) * 100));
    }
}
