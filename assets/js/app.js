(() => {
  const sidebar = document.getElementById('sidebar');
  const toggle = document.querySelector('.menu-toggle');
  if (toggle && sidebar) {
    const mobileMenu = window.matchMedia('(max-width: 820px)');
    const mainShell = document.querySelector('.app-main');
    const backdrop = document.createElement('div');
    backdrop.className = 'mobile-nav-backdrop';
    backdrop.setAttribute('aria-hidden', 'true');
    document.body.appendChild(backdrop);
    const closeButton = document.createElement('button');
    closeButton.type = 'button';
    closeButton.className = 'mobile-nav-close';
    closeButton.setAttribute('aria-label', 'Close menu');
    closeButton.textContent = '×';
    sidebar.prepend(closeButton);
    const setMenuOpen = (open, restoreFocus = true) => {
      open = mobileMenu.matches && open;
      sidebar.classList.toggle('open', open);
      document.body.classList.toggle('mobile-nav-open', open);
      toggle.setAttribute('aria-expanded', String(open));
      sidebar.inert = mobileMenu.matches && !open;
      if (mainShell) mainShell.inert = open;
      if (open) closeButton.focus();
      else if (restoreFocus) toggle.focus();
    };
    toggle.addEventListener('click', () => {
      setMenuOpen(!sidebar.classList.contains('open'));
    });
    closeButton.addEventListener('click', () => setMenuOpen(false));
    backdrop.addEventListener('click', () => setMenuOpen(false));
    document.addEventListener('keydown', (event) => {
      if (!mobileMenu.matches || !sidebar.classList.contains('open')) return;
      if (event.key === 'Escape') { setMenuOpen(false); return; }
      if (event.key === 'Tab') {
        const items = Array.from(sidebar.querySelectorAll('a[href], button:not([disabled]), input:not([disabled])'))
          .filter((item) => item.getClientRects().length > 0);
        const first = items[0], last = items[items.length - 1];
        if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
        else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
      }
    });
    mobileMenu.addEventListener('change', () => setMenuOpen(false, false));
    setMenuOpen(false, false);
  }
  // Simple record tables become labelled cards on phones. Complex tables and
  // printable documents retain their existing table/scroll presentation.
  document.querySelectorAll('.content-wrap table.data-table').forEach((table) => {
    const weeklySchedule = table.classList.contains('weekly-schedule-table');
    if (!table.tHead || table.tHead.rows.length !== 1 || (table.tFoot && !weeklySchedule)) return;
    const headers = Array.from(table.tHead.rows[0].cells);
    if (headers.some((cell) => cell.colSpan !== 1 || cell.rowSpan !== 1)) return;
    const rows = Array.from(table.tBodies).flatMap((body) => Array.from(body.rows));
    if (rows.some((row) => !(row.cells.length === 1 && row.cells[0].colSpan === headers.length)
      && (row.cells.length !== headers.length || Array.from(row.cells).some((cell) => cell.colSpan !== 1 || cell.rowSpan !== 1)))) return;
    table.classList.add('mobile-record-table');
    table.setAttribute('role', 'table');
    table.tHead.setAttribute('role', 'rowgroup');
    table.tHead.rows[0].setAttribute('role', 'row');
    headers.forEach((header) => { header.setAttribute('scope', 'col'); header.setAttribute('role', 'columnheader'); });
    Array.from(table.tBodies).forEach((body) => body.setAttribute('role', 'rowgroup'));
    if (weeklySchedule && table.tFoot) {
      table.tFoot.setAttribute('role', 'rowgroup');
      Array.from(table.tFoot.rows).forEach((row) => {
        row.setAttribute('role', 'row');
        Array.from(row.cells).forEach((cell) => cell.setAttribute('role', 'cell'));
      });
    }
    rows.forEach((row) => {
      row.setAttribute('role', 'row');
      Array.from(row.cells).forEach((cell, index) => {
        cell.setAttribute('role', 'cell');
        if (cell.colSpan > 1) return;
        const label = document.createElement('span');
        label.className = 'mobile-cell-label';
        label.setAttribute('aria-hidden', 'true');
        label.textContent = headers[index].textContent.trim();
        cell.prepend(label);
      });
    });
  });
  if (sidebar) {
    const navigation = sidebar.querySelector('.navigation');
    const scrollKey = sidebar.classList.contains('employee-sidebar')
      ? 'ucchr.employee.navigation.scroll'
      : 'ucchr.admin.navigation.scroll';
    const saveNavigationScroll = () => {
      if (!navigation) return;
      try {
        window.sessionStorage.setItem(scrollKey, String(navigation.scrollTop));
      } catch (_) {
        // Navigation still works when browser storage is unavailable.
      }
    };

    if (navigation) {
      try {
        const savedPosition = Number.parseInt(window.sessionStorage.getItem(scrollKey) || '', 10);
        if (Number.isFinite(savedPosition) && savedPosition >= 0) {
          navigation.scrollTop = savedPosition;
        }
      } catch (_) {
        // Fall back to positioning the active item below.
      }

      const activeLink = navigation.querySelector('.nav-link.active');
      if (activeLink) {
        const linkTop = activeLink.offsetTop - navigation.offsetTop;
        const linkBottom = linkTop + activeLink.offsetHeight;
        if (linkTop < navigation.scrollTop) {
          navigation.scrollTop = Math.max(0, linkTop - 12);
        } else if (linkBottom > navigation.scrollTop + navigation.clientHeight) {
          navigation.scrollTop = Math.max(0, linkBottom - navigation.clientHeight + 12);
        }
      }

      navigation.addEventListener('scroll', saveNavigationScroll, { passive: true });
      navigation.querySelectorAll('.nav-link').forEach((link) => {
        link.addEventListener('click', saveNavigationScroll);
      });
      window.addEventListener('pagehide', saveNavigationScroll);
    }
  }
  document.querySelectorAll('[data-dismiss]').forEach((button) => {
    button.addEventListener('click', () => button.parentElement.remove());
  });

  document.querySelectorAll('[data-copy-target]').forEach((button) => {
    button.addEventListener('click', async () => {
      const input = document.getElementById(button.dataset.copyTarget || '');
      if (!input) return;
      let copied = false;
      try {
        await navigator.clipboard.writeText(input.value);
        copied = true;
      } catch (_) {
        input.focus();
        input.select();
        try { copied = document.execCommand('copy'); } catch (_) { /* Keep the selected link available for manual copying. */ }
      }
      const label = button.querySelector('span');
      if (label) {
        label.textContent = copied ? 'Copied' : 'Select and copy';
        window.setTimeout(() => { label.textContent = 'Copy link'; }, 1800);
      }
    });
  });

  document.querySelectorAll('[data-schedule-form]').forEach((form) => {
    const typeSelect = form.querySelector('[data-schedule-type]');
    const timeInputs = form.querySelectorAll('[data-work-time]');
    if (!typeSelect || !timeInputs.length) return;

    const syncScheduleFields = () => {
      const isWork = typeSelect.value === 'Work';
      timeInputs.forEach((input) => {
        input.disabled = !isWork;
        input.required = isWork;
        input.closest('label')?.classList.toggle('is-disabled', !isWork);
      });
    };
    typeSelect.addEventListener('change', syncScheduleFields);
    syncScheduleFields();
  });

  document.querySelectorAll('.weekday-option input[type="checkbox"]').forEach((checkbox) => {
    const status = checkbox.nextElementSibling?.querySelector('small');
    const syncDayLabel = () => {
      if (status) status.textContent = checkbox.checked ? 'Work' : 'Off';
    };
    checkbox.addEventListener('change', syncDayLabel);
    syncDayLabel();
  });

  document.querySelectorAll('[data-schedule-employee-picker]').forEach((form) => {
    const select = form.querySelector('select[name="employee_id"]');
    select?.addEventListener('change', () => form.requestSubmit());
  });

  document.querySelectorAll('[data-employment-pay-form]').forEach((form) => {
    const basicRate = form.querySelector('input[name="basic_rate"]');
    const payType = form.querySelector('[data-pay-type]');
    const payTypeHelp = form.querySelector('[data-pay-type-help]');
    const rateHelp = form.querySelector('[data-approved-rate-help]');
    const salaryCard = form.querySelector('[data-expected-monthly-salary-card]');
    const money = new Intl.NumberFormat('en-PH', {
      style: 'currency',
      currency: 'PHP',
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    });
    const syncExpectedSalary = () => {
      if (!salaryCard || !basicRate) return;
      const output = salaryCard.querySelector('[data-expected-monthly-salary]');
      const formula = salaryCard.querySelector('[data-expected-monthly-formula]');
      const rate = Number.parseFloat(basicRate.value || '0');
      const scheduledDays = Number.parseInt(salaryCard.dataset.scheduledDays || '0', 10) || 0;
      const scheduledMinutes = Number.parseInt(salaryCard.dataset.scheduledMinutes || '0', 10) || 0;
      const selectedType = payType?.value || '';
      let amount = 0;
      let explanation = 'Enter an Approved Rate';
      if (Number.isFinite(rate) && rate > 0) {
        if (selectedType === 'Hourly') {
          amount = rate * (scheduledMinutes / 60);
          explanation = scheduledMinutes > 0
            ? `${(scheduledMinutes / 60).toFixed(2)} scheduled hours × ${money.format(rate)}/hour`
            : 'Assign a Work Schedule to calculate the hourly monthly estimate';
        } else if (selectedType === 'Daily') {
          amount = rate * scheduledDays;
          explanation = scheduledDays > 0
            ? `${scheduledDays} scheduled workday${scheduledDays === 1 ? '' : 's'} × ${money.format(rate)}/day`
            : 'Assign a Work Schedule to calculate the daily monthly estimate';
        } else if (selectedType === 'Monthly') {
          amount = rate;
          explanation = `${money.format(rate)} approved monthly rate`;
        }
      }
      const unit = selectedType === 'Hourly' ? 'hour' : (selectedType === 'Monthly' ? 'month' : 'day');
      if (payTypeHelp) {
        payTypeHelp.textContent = selectedType === 'Hourly'
          ? 'Expected salary uses scheduled hours; actual payroll uses eligible worked hours.'
          : (selectedType === 'Daily'
            ? 'Expected salary uses the employee’s scheduled workdays.'
            : (selectedType === 'Monthly'
              ? 'Monthly salary is prorated by payroll period; attendance controls deductions.'
              : 'Choose Daily, Hourly, or Monthly pay.'));
      }
      if (rateHelp) rateHelp.textContent = selectedType
        ? `Enter the approved amount per ${unit}.`
        : 'Choose Daily, Hourly, or Monthly before confirming the Approved Rate.';
      if (output) output.textContent = money.format(amount);
      if (formula) formula.textContent = explanation;
    };
    basicRate?.addEventListener('input', syncExpectedSalary);
    payType?.addEventListener('change', syncExpectedSalary);
    syncExpectedSalary();
  });

  document.querySelectorAll('[data-weekly-schedule-form]').forEach((form) => {
    const rows = [...form.querySelectorAll('[data-schedule-day-row]')];
    const weeklyOutput = form.querySelector('[data-weekly-hours]');
    const employmentType = form.querySelector('[data-employment-type]');
    const payType = form.querySelector('[data-pay-type]');
    const payTypeHelp = form.querySelector('[data-pay-type-help]');
    const rateHelp = form.querySelector('[data-approved-rate-help]');
    const previewStart = form.querySelector('[data-period-preview-start]');
    const previewEnd = form.querySelector('[data-period-preview-end]');
    const previewOutput = form.querySelector('[data-period-preview-total]');
    const dayNames = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    let dailyMinutes = {};

    const toMinutes = (value) => {
      if (!/^\d{2}:\d{2}$/.test(value)) return null;
      const [hours, minutes] = value.split(':').map(Number);
      return (hours * 60) + minutes;
    };
    const formatDuration = (minutes) => {
      const hours = Math.floor(minutes / 60);
      const remainder = minutes % 60;
      return remainder ? `${hours}h ${remainder}m` : `${hours}h`;
    };

    const syncPayTypeHelp = () => {
      const selectedType = payType?.value || '';
      const unit = selectedType === 'Hourly' ? 'hour' : (selectedType === 'Monthly' ? 'month' : 'day');
      if (payTypeHelp) {
        payTypeHelp.textContent = selectedType === 'Hourly'
          ? 'Expected salary uses this schedule; actual payroll uses eligible regular hours.'
          : (selectedType === 'Daily'
            ? 'Expected salary uses scheduled workdays × Approved Rate.'
            : (selectedType === 'Monthly'
              ? 'Expected monthly salary uses the approved monthly rate.'
              : 'Choose Daily, Hourly, or Monthly pay.'));
      }
      if (rateHelp) rateHelp.textContent = selectedType
        ? `Enter the approved amount per ${unit}.`
        : 'Choose Daily, Hourly, or Monthly before confirming the Approved Rate.';
    };

    const renumberPeriods = (row) => {
      const periodRows = [...row.querySelectorAll('[data-period-row]')];
      periodRows.forEach((periodRow, index) => {
        const label = periodRow.querySelector('span');
        const remove = periodRow.querySelector('[data-remove-period]');
        if (label) label.textContent = `Period ${index + 1}`;
        if (remove) {
          remove.disabled = periodRows.length === 1;
          remove.setAttribute('aria-label', `Remove ${row.dataset.day || ''} period ${index + 1}`);
        }
      });
    };

    const addPeriod = (row) => {
      const list = row.querySelector('[data-period-list]');
      const limit = Number.parseInt(row.querySelector('[data-part-time-periods]')?.dataset.periodLimit || '6', 10);
      if (!list || list.querySelectorAll('[data-period-row]').length >= limit) return;
      const day = row.dataset.day || '';
      const removeIcon = row.querySelector('[data-remove-period] .ui-icon')?.outerHTML || '';
      const period = document.createElement('div');
      period.className = 'part-time-period-row';
      period.dataset.periodRow = '';
      period.innerHTML = `<span>Period</span>
        <label>From<input type="time" name="period_start[${day}][]" data-period-start></label>
        <label>To<input type="time" name="period_end[${day}][]" data-period-end></label>
        <button class="btn btn-mini btn-outline" type="button" data-remove-period>${removeIcon}Remove</button>`;
      list.append(period);
      renumberPeriods(row);
      syncForm();
      period.querySelector('[data-period-start]')?.focus();
    };

    const syncPreview = () => {
      if (!previewStart || !previewEnd || !previewOutput) return;
      previewStart.setCustomValidity('');
      previewEnd.setCustomValidity('');
      const start = /^\d{4}-\d{2}-\d{2}$/.test(previewStart.value)
        ? new Date(`${previewStart.value}T00:00:00`)
        : null;
      const end = /^\d{4}-\d{2}-\d{2}$/.test(previewEnd.value)
        ? new Date(`${previewEnd.value}T00:00:00`)
        : null;
      if (!start || !end || end < start) {
        previewOutput.textContent = 'Check dates';
        previewEnd.setCustomValidity('The preview end must be on or after its start.');
        return;
      }
      const maximum = new Date(start);
      maximum.setDate(maximum.getDate() + 366);
      if (end > maximum) {
        previewOutput.textContent = 'Range too long';
        previewEnd.setCustomValidity('Preview a maximum of 367 days.');
        return;
      }
      let total = 0;
      for (const cursor = new Date(start); cursor <= end; cursor.setDate(cursor.getDate() + 1)) {
        total += dailyMinutes[dayNames[cursor.getDay()]] || 0;
      }
      previewOutput.textContent = formatDuration(total);
    };

    const syncForm = () => {
      let weeklyMinutes = 0;
      dailyMinutes = {};
      const isPartTime = employmentType?.value === 'Part-Time';
      form.classList.toggle('is-part-time-schedule', isPartTime);
      const workdayToggles = rows.map((row) => row.querySelector('[data-workday-toggle]')).filter(Boolean);
      const selectedWorkdays = workdayToggles.filter((toggle) => toggle.checked);
      workdayToggles.forEach((toggle) => toggle.setCustomValidity(''));
      if (!selectedWorkdays.length && workdayToggles[0]) {
        workdayToggles[0].setCustomValidity('Select at least one workday.');
      }
      rows.forEach((row) => {
        const toggle = row.querySelector('[data-workday-toggle]');
        const start = row.querySelector('[data-schedule-start]');
        const end = row.querySelector('[data-schedule-end]');
        const breakInput = row.querySelector('[data-schedule-break]');
        const type = row.querySelector('[data-day-type]');
        const output = row.querySelector('[data-day-hours]');
        const countOutput = row.querySelector('[data-period-count]');
        const periodMessage = row.querySelector('[data-period-message]');
        const periodRows = [...row.querySelectorAll('[data-period-row]')];
        if (!toggle || !start || !end || !breakInput || !type || !output) return;

        const isWork = toggle.checked;
        start.disabled = !isWork || isPartTime;
        end.disabled = !isWork || isPartTime;
        breakInput.disabled = !isWork || isPartTime;
        start.required = isWork && !isPartTime;
        end.required = isWork && !isPartTime;
        breakInput.required = isWork && !isPartTime;
        type.textContent = isWork ? 'Work Day' : 'Rest Day';
        row.classList.toggle('is-rest-day', !isWork);
        end.setCustomValidity('');
        breakInput.setCustomValidity('');
        periodRows.forEach((periodRow) => {
          const periodStart = periodRow.querySelector('[data-period-start]');
          const periodEnd = periodRow.querySelector('[data-period-end]');
          if (!periodStart || !periodEnd) return;
          periodStart.disabled = !isWork || !isPartTime;
          periodEnd.disabled = !isWork || !isPartTime;
          periodStart.required = isWork && isPartTime;
          periodEnd.required = isWork && isPartTime;
          periodStart.setCustomValidity('');
          periodEnd.setCustomValidity('');
        });

        if (!isWork) {
          output.textContent = 'Rest';
          if (countOutput) countOutput.textContent = '';
          dailyMinutes[row.dataset.day || ''] = 0;
          return;
        }

        if (isPartTime) {
          const normalized = [];
          let invalid = false;
          periodRows.forEach((periodRow) => {
            const periodStart = periodRow.querySelector('[data-period-start]');
            const periodEnd = periodRow.querySelector('[data-period-end]');
            const startMinutes = toMinutes(periodStart?.value || '');
            const endMinutes = toMinutes(periodEnd?.value || '');
            if (startMinutes === null || endMinutes === null || endMinutes <= startMinutes) {
              invalid = true;
              periodEnd?.setCustomValidity('Each Part-Time period must end after it starts on the same day.');
              return;
            }
            normalized.push({ start: startMinutes, end: endMinutes, input: periodStart });
          });
          normalized.sort((left, right) => left.start - right.start);
          for (let index = 1; index < normalized.length; index += 1) {
            if (normalized[index].start < normalized[index - 1].end) {
              invalid = true;
              normalized[index].input?.setCustomValidity('Part-Time periods cannot overlap.');
            }
          }
          if (invalid || !normalized.length) {
            output.textContent = 'Check periods';
            if (countOutput) countOutput.textContent = '';
            if (periodMessage) periodMessage.textContent = 'Enter 1–6 valid, non-overlapping periods.';
            dailyMinutes[row.dataset.day || ''] = 0;
            return;
          }
          const total = normalized.reduce((minutes, period) => minutes + period.end - period.start, 0);
          weeklyMinutes += total;
          dailyMinutes[row.dataset.day || ''] = total;
          output.textContent = formatDuration(total);
          if (countOutput) countOutput.textContent = `${normalized.length} period${normalized.length === 1 ? '' : 's'}`;
          if (periodMessage) periodMessage.textContent = 'Only listed periods count as regular hours.';
          return;
        }

        const startMinutes = toMinutes(start.value);
        let endMinutes = toMinutes(end.value);
        if (startMinutes === null || endMinutes === null) {
          output.textContent = 'Enter both times';
          return;
        }
        if (endMinutes === startMinutes) {
          output.textContent = 'Times must differ';
          end.setCustomValidity('Expected Time Out must be different from Expected Time In.');
          return;
        }
        if (endMinutes < startMinutes) endMinutes += 24 * 60;
        const duration = endMinutes - startMinutes;
        const breakMinutes = Math.max(0, Number.parseInt(breakInput.value || '0', 10) || 0);
        if (breakMinutes >= duration) {
          output.textContent = 'Break too long';
          breakInput.setCustomValidity('Break must be shorter than the scheduled shift.');
          return;
        }
        const netDuration = duration - breakMinutes;
        weeklyMinutes += netDuration;
        dailyMinutes[row.dataset.day || ''] = netDuration;
        output.textContent = formatDuration(netDuration) + (endMinutes >= 24 * 60 ? ' overnight' : '');
        if (countOutput) countOutput.textContent = 'single shift';
      });
      if (weeklyOutput) weeklyOutput.textContent = formatDuration(weeklyMinutes);
      syncPreview();
    };

    rows.forEach((row) => {
      row.addEventListener('change', syncForm);
      row.addEventListener('input', syncForm);
      row.addEventListener('click', (event) => {
        const target = event.target;
        if (!(target instanceof Element)) return;
        if (target.closest('[data-add-period]')) {
          addPeriod(row);
        } else if (target.closest('[data-remove-period]')) {
          const periodRows = row.querySelectorAll('[data-period-row]');
          if (periodRows.length > 1) {
            target.closest('[data-period-row]')?.remove();
            renumberPeriods(row);
            syncForm();
          }
        }
      });
      renumberPeriods(row);
    });
    employmentType?.addEventListener('change', () => {
      syncForm();
    });
    payType?.addEventListener('change', syncPayTypeHelp);
    previewStart?.addEventListener('change', syncPreview);
    previewEnd?.addEventListener('change', syncPreview);
    employmentType?.dispatchEvent(new Event('change'));
    syncPayTypeHelp();
    syncForm();
  });

  document.querySelectorAll('[data-payroll-preview-form]').forEach((form) => {
    const employee = form.querySelector('[data-payroll-employee]');
    employee?.addEventListener('change', () => form.requestSubmit());
  });

  document.querySelectorAll('[data-payroll-generate-form]').forEach((form) => {
    form.addEventListener('submit', (event) => {
      const status = form.querySelector('[data-payment-status]')?.value;
      if (status === 'Paid' && !window.confirm('Record this validated payroll as already Paid? This creates a final, read-only record and publishes the official payslip(s).')) {
        event.preventDefault();
      }
    });
  });

  document.querySelectorAll('[data-submit-lock]').forEach((form) => {
    form.addEventListener('submit', (event) => {
      if (event.defaultPrevented || !form.checkValidity()) return;
      const button = form.querySelector('button[type="submit"]');
      if (!button) return;
      const icon = button.querySelector('.ui-icon')?.cloneNode(true);
      button.disabled = true;
      button.replaceChildren();
      if (icon) button.append(icon);
      button.append(document.createTextNode(button.dataset.submitLabel || 'Please wait…'));
    });
  });
})();
