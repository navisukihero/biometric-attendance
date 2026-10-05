-- UCC HR flexible part-time schedule periods (additive migration)
-- Select the existing UCC HR database before importing this file.
-- This migration does not delete or rewrite existing schedules/attendance.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS work_schedule_periods (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    work_schedule_id INT UNSIGNED NOT NULL,
    period_order TINYINT UNSIGNED NOT NULL,
    period_start TIME NOT NULL,
    period_end TIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_schedule_period_order (work_schedule_id, period_order),
    KEY idx_schedule_period_time (work_schedule_id, period_start, period_end),
    CONSTRAINT fk_schedule_period_parent
        FOREIGN KEY (work_schedule_id) REFERENCES work_schedules(id) ON DELETE CASCADE
) ENGINE=InnoDB;

ALTER TABLE attendance
    ADD COLUMN IF NOT EXISTS schedule_periods_snapshot LONGTEXT NULL AFTER `schedule_source`;

-- Preserve the net hours of an existing Part-Time single shift. Its former
-- break is represented as a centered gap between two periods. Rows with no
-- break become one period. HR can then edit the exact periods in the website.
INSERT INTO work_schedule_periods
    (work_schedule_id, period_order, period_start, period_end)
SELECT ws.id,
       1,
       ws.shift_start,
       CASE
           WHEN ws.break_minutes > 0 THEN SEC_TO_TIME(
               TIME_TO_SEC(ws.shift_start)
               + FLOOR(((TIME_TO_SEC(ws.shift_end)-TIME_TO_SEC(ws.shift_start))/60-ws.break_minutes)/2)*60
           )
           ELSE ws.shift_end
       END
FROM work_schedules ws
JOIN employees e ON e.id=ws.employee_id
WHERE e.employment_type='Part-Time'
  AND ws.schedule_type='Work'
  AND ws.shift_start IS NOT NULL AND ws.shift_end IS NOT NULL
  AND ws.shift_end>ws.shift_start
  AND (TIME_TO_SEC(ws.shift_end)-TIME_TO_SEC(ws.shift_start))/60>ws.break_minutes
  AND NOT EXISTS (
      SELECT 1 FROM work_schedule_periods wsp WHERE wsp.work_schedule_id=ws.id
  );

INSERT INTO work_schedule_periods
    (work_schedule_id, period_order, period_start, period_end)
SELECT ws.id,
       2,
       SEC_TO_TIME(
           TIME_TO_SEC(ws.shift_start)
           + FLOOR(((TIME_TO_SEC(ws.shift_end)-TIME_TO_SEC(ws.shift_start))/60-ws.break_minutes)/2)*60
           + ws.break_minutes*60
       ),
       ws.shift_end
FROM work_schedules ws
JOIN employees e ON e.id=ws.employee_id
WHERE e.employment_type='Part-Time'
  AND ws.schedule_type='Work'
  AND ws.break_minutes>0
  AND ws.shift_start IS NOT NULL AND ws.shift_end IS NOT NULL
  AND ws.shift_end>ws.shift_start
  AND (TIME_TO_SEC(ws.shift_end)-TIME_TO_SEC(ws.shift_start))/60>ws.break_minutes
  AND EXISTS (
      SELECT 1 FROM work_schedule_periods wsp
      WHERE wsp.work_schedule_id=ws.id AND wsp.period_order=1
  )
  AND NOT EXISTS (
      SELECT 1 FROM work_schedule_periods wsp
      WHERE wsp.work_schedule_id=ws.id AND wsp.period_order=2
  );

CREATE TABLE IF NOT EXISTS schema_migrations (
    migration_key VARCHAR(120) PRIMARY KEY,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    description VARCHAR(255) NOT NULL
) ENGINE=InnoDB;

INSERT INTO schema_migrations (migration_key, description)
VALUES (
    '2026-09-06-flexible-part-time-periods-v1',
    'Add multiple daily schedule periods and immutable attendance period snapshots'
)
ON DUPLICATE KEY UPDATE description=VALUES(description);

SELECT
    (SELECT COUNT(*) FROM work_schedules) AS weekly_schedule_days_preserved,
    (SELECT COUNT(*) FROM work_schedule_periods) AS flexible_periods_available,
    (SELECT COUNT(*) FROM attendance) AS attendance_rows_preserved;
