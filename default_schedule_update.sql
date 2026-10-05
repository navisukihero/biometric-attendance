-- Normalized default schedules, employee Work/Off overrides, and immutable
-- attendance schedule snapshots. Run against the database selected by the
-- MySQL client; this script intentionally does not hard-code a schema name.

CREATE TABLE IF NOT EXISTS default_work_schedules (
    day_of_week ENUM('Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday') PRIMARY KEY,
    schedule_type ENUM('Work','Off') NOT NULL DEFAULT 'Work',
    shift_start TIME NULL,
    shift_end TIME NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT chk_default_schedule_times CHECK (
        (schedule_type='Off' AND shift_start IS NULL AND shift_end IS NULL)
        OR
        (schedule_type='Work' AND shift_start IS NOT NULL AND shift_end IS NOT NULL AND shift_start<>shift_end)
    )
) ENGINE=InnoDB;

-- Initial defaults are intentionally inserted without replacing an
-- administrator's existing configuration when this migration is rerun.
INSERT INTO default_work_schedules (day_of_week, schedule_type, shift_start, shift_end) VALUES
('Monday',    'Work', '08:00:00', '17:00:00'),
('Tuesday',   'Work', '08:00:00', '17:00:00'),
('Wednesday', 'Work', '08:00:00', '17:00:00'),
('Thursday',  'Work', '08:00:00', '17:00:00'),
('Friday',    'Work', '08:00:00', '17:00:00'),
('Saturday',  'Work', '08:00:00', '17:00:00'),
('Sunday',    'Off',  NULL,       NULL)
ON DUPLICATE KEY UPDATE day_of_week=VALUES(day_of_week);

ALTER TABLE work_schedules
    ADD COLUMN IF NOT EXISTS schedule_type ENUM('Work','Off') NOT NULL DEFAULT 'Work' AFTER day_of_week,
    MODIFY COLUMN shift_start TIME NULL,
    MODIFY COLUMN shift_end TIME NULL;

UPDATE work_schedules
SET schedule_type='Work'
WHERE schedule_type IS NULL OR schedule_type NOT IN ('Work','Off');

ALTER TABLE attendance
    ADD COLUMN IF NOT EXISTS schedule_type ENUM('Work','Off','Unscheduled') NULL AFTER expected_time_out,
    ADD COLUMN IF NOT EXISTS schedule_source VARCHAR(32) NULL AFTER schedule_type;

-- Rows that already contain either expected-time value are historical
-- snapshots. Classify them without replacing either stored value.
UPDATE attendance
SET schedule_type='Work',
    schedule_source=COALESCE(NULLIF(schedule_source, ''), 'Existing Snapshot')
WHERE schedule_type IS NULL
  AND (expected_time_in IS NOT NULL OR expected_time_out IS NOT NULL);

-- Resolve only rows that genuinely have no snapshot. The selected values are
-- frozen here; later default/override edits do not rewrite completed history.
UPDATE attendance a
LEFT JOIN work_schedules ws
  ON ws.employee_id=a.employee_id
 AND ws.day_of_week=DAYNAME(a.scan_date)
LEFT JOIN default_work_schedules dws
  ON dws.day_of_week=DAYNAME(a.scan_date)
SET a.schedule_type=CASE
        WHEN ws.id IS NOT NULL THEN ws.schedule_type
        WHEN dws.day_of_week IS NOT NULL THEN dws.schedule_type
        ELSE 'Unscheduled'
    END,
    a.schedule_source=CASE
        WHEN ws.id IS NOT NULL THEN 'Backfill Employee'
        WHEN dws.day_of_week IS NOT NULL THEN 'Backfill Default'
        ELSE 'Unscheduled'
    END,
    a.expected_time_in=CASE
        WHEN ws.id IS NOT NULL AND ws.schedule_type='Work' THEN ws.shift_start
        WHEN ws.id IS NULL AND dws.schedule_type='Work' THEN dws.shift_start
        ELSE NULL
    END,
    a.expected_time_out=CASE
        WHEN ws.id IS NOT NULL AND ws.schedule_type='Work' THEN ws.shift_end
        WHEN ws.id IS NULL AND dws.schedule_type='Work' THEN dws.shift_end
        ELSE NULL
    END
WHERE a.schedule_type IS NULL
  AND a.expected_time_in IS NULL
  AND a.expected_time_out IS NULL;

-- Recalculate stored metrics from each row's frozen snapshot. An end time
-- before its start is next-day. Overtime is only actual work overlapping the
-- period after scheduled end, so it can never exceed actual worked minutes.
SET @ucchr_grace_minutes := COALESCE(
    (SELECT CAST(`value` AS UNSIGNED) FROM settings WHERE `key`='grace_minutes' LIMIT 1),
    15
);

UPDATE attendance a
SET a.worked_minutes=CASE
        WHEN a.time_in IS NULL OR a.time_out IS NULL THEN 0
        ELSE GREATEST(0, TIMESTAMPDIFF(
            MINUTE,
            TIMESTAMP(a.scan_date, a.time_in),
            CASE
                WHEN a.time_out < a.time_in
                    THEN TIMESTAMP(DATE_ADD(a.scan_date, INTERVAL 1 DAY), a.time_out)
                ELSE TIMESTAMP(a.scan_date, a.time_out)
            END
        ))
    END,
    a.late_minutes=CASE
        WHEN a.schedule_type<>'Work' OR a.time_in IS NULL OR a.expected_time_in IS NULL THEN 0
        ELSE GREATEST(0, TIMESTAMPDIFF(
            MINUTE,
            TIMESTAMP(a.scan_date, a.expected_time_in),
            TIMESTAMP(a.scan_date, a.time_in)
        ) - @ucchr_grace_minutes)
    END,
    a.overtime_minutes=CASE
        WHEN a.schedule_type<>'Work'
          OR a.time_in IS NULL OR a.time_out IS NULL
          OR a.expected_time_in IS NULL OR a.expected_time_out IS NULL THEN 0
        ELSE GREATEST(0, TIMESTAMPDIFF(
            MINUTE,
            GREATEST(
                TIMESTAMP(a.scan_date, a.time_in),
                CASE
                    WHEN a.expected_time_out<=a.expected_time_in
                        THEN TIMESTAMP(DATE_ADD(a.scan_date, INTERVAL 1 DAY), a.expected_time_out)
                    ELSE TIMESTAMP(a.scan_date, a.expected_time_out)
                END
            ),
            CASE
                WHEN a.time_out<a.time_in
                    THEN TIMESTAMP(DATE_ADD(a.scan_date, INTERVAL 1 DAY), a.time_out)
                ELSE TIMESTAMP(a.scan_date, a.time_out)
            END
        ))
    END,
    a.status=CASE
        WHEN a.time_in IS NULL OR a.status NOT IN ('Present','Late') THEN a.status
        WHEN a.schedule_type='Work' AND a.expected_time_in IS NOT NULL
         AND TIMESTAMPDIFF(
                MINUTE,
                TIMESTAMP(a.scan_date, a.expected_time_in),
                TIMESTAMP(a.scan_date, a.time_in)
             ) > @ucchr_grace_minutes
            THEN 'Late'
        ELSE 'Present'
    END;

SET @ucchr_grace_minutes := NULL;
