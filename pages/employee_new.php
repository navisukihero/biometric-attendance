<?php $departments = $pdo->query('SELECT * FROM departments ORDER BY name')->fetchAll(); ?>
<article class="panel form-panel">
    <h2><span class="panel-icon"><?= ui_icon('employee-add') ?></span> Add New Employee</h2>
    <div class="form-tabs"><span class="active">Personal info</span><span>Employment</span><span>Salary</span></div>
    <div class="note-box onboarding-note"><strong>Two-step setup:</strong> first save the employee details. On the employee record, click <strong>Sync/Enroll Fingerprint</strong>, then press <strong>BTN1</strong> on the ESP32 terminal. Follow the OLED prompts for the employee’s thumb: <strong>Center → Left → Right → Upper → Lower</strong>. Attendance and payroll eligibility begin only after all five positions are captured and enrollment succeeds.</div>
    <form method="post" class="employee-form" data-employment-pay-form>
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save_employee">
        <div class="form-grid">
            <label>First Name<input name="first_name" placeholder="Enter first name..." required></label>
            <label>Last Name<input name="last_name" placeholder="Enter last name..." required></label>
            <label>Middle Name<input name="middle_name" placeholder="Enter middle name..."></label>
            <label>Date of Birth<input type="date" name="date_of_birth"></label>
            <label>Gender<select name="gender">
                    <option>Male</option>
                    <option>Female</option>
                    <option>Not specified</option>
                </select></label>
            <label>Civil Status<select name="civil_status">
                    <option>Single</option>
                    <option>Married</option>
                    <option>Widowed</option>
                    <option>Separated</option>
                </select></label>
            <label>Department<select name="department_id" required>
                    <option value="">Select department</option><?php foreach ($departments as $department): ?><option value="<?= (int) $department['id'] ?>"><?= e($department['name']) ?></option><?php endforeach; ?>
                </select></label>
            <label>Position<input name="position" placeholder="Enter position..." required></label>
            <label>Employment Type<select name="employment_type" data-employment-type required>
                    <option value="">Select employment type</option>
                    <option value="Full-Time">Full-Time</option>
                    <option value="Part-Time">Part-Time</option>
                </select></label>
            <label>Pay Type<select name="pay_type" data-pay-type required>
                    <option value="Daily">Daily</option>
                    <option value="Hourly">Hourly</option>
                    <option value="Monthly">Monthly</option>
                </select><small data-pay-type-help>Daily pay uses scheduled workdays in the payroll period.</small></label>
            <label>Approved Rate (₱)<input type="number" min="0.01" max="999999999.99" step="0.01" name="basic_rate" placeholder="Enter the HR-approved rate" required><small data-approved-rate-help>Enter the approved amount per day.</small></label>
            <section class="expected-monthly-salary span-2" data-expected-monthly-salary-card data-scheduled-days="0" data-scheduled-minutes="0" aria-label="Expected monthly salary">
                <div class="expected-monthly-salary-copy">
                    <span>Expected Monthly Salary</span>
                    <strong data-expected-monthly-salary>₱0.00</strong>
                    <small data-expected-monthly-formula>Enter an Approved Rate</small>
                </div>
                <div class="expected-monthly-salary-meta"><span>Daily × scheduled days · Hourly × scheduled hours · Monthly fixed rate</span><span>Assign the Work Schedule after saving</span></div>
            </section>
            <label>Rate Effective Date<input type="date" name="compensation_effective_from" max="<?= e(date('Y-m-d')) ?>" value="<?= e(date('Y-m-d')) ?>" required></label>
            <label class="span-2">Rate Note<input name="compensation_change_reason" maxlength="500" value="Initial approved compensation" placeholder="Approval/reference note"></label>
            <label>Employment Status<input value="Active — automatically available in Work Schedule" disabled><input type="hidden" name="status" value="Active"></label>
            <label>Contact Number<input name="contact_number" placeholder="09XX XXX XXXX"></label>
            <label>Email<input type="email" name="email" placeholder="name@ucchr.edu.ph"></label>
        </div>
        <div class="note-box"><strong>Salary control:</strong> Daily and Hourly estimates use the assigned Work Schedule; Monthly uses the approved monthly rate. Actual payroll is calculated and frozen by the server.</div>
        <div class="form-actions"><a class="btn btn-outline" href="app.php?page=employees"><?= ui_icon('x', 'button-icon') ?>Cancel</a><button class="btn btn-primary" type="submit"><?= ui_icon('save', 'button-icon') ?>Save employee</button></div>
    </form>
</article>
