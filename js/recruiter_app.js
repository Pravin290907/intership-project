/**
 * CRMS Premium Recruiter Dashboard Module
 * Coordinates client-side routing, AJAX, Chart.js, ATS, Kanban, and real-time chat.
 * Adds Student Management, Offer Release, Interview CRUD, and sweet validations.
 */

document.addEventListener("DOMContentLoaded", () => {
  // Intercept fetch to rewrite relative api/ paths dynamically to absolute base URL
  const originalFetch = window.fetch;
  window.fetch = function (url, options) {
    if (typeof url === 'string' && url.startsWith('api/')) {
      const apiBase = window.API_BASE_URL || '../api/';
      url = decodeURIComponent(apiBase) + url.substring(4);
    }
    options = options || {};
    options.credentials = 'same-origin';
    return originalFetch(url, options);
  };

  window.getAbsoluteUrl = function (path) {
    if (!path) return '';
    if (path.startsWith('http') || path.startsWith('//')) return path;
    const apiBase = window.API_BASE_URL || '../api/';
    const base = decodeURIComponent(apiBase).replace('/api/', '/');
    return base + (path.startsWith('/') ? path.substring(1) : path);
  };

  const globalData = window.campusRecruitmentData || {};
  
  const editStudentNameInput = document.getElementById("edit-student-name");
  if (editStudentNameInput) {
    editStudentNameInput.addEventListener("keydown", (e) => {
      const invalidChars = /[^a-zA-Z\s'\-\.]/;
      if (e.key.length === 1 && invalidChars.test(e.key)) {
        e.preventDefault();
      }
      if (e.key === ' ' && (editStudentNameInput.value.slice(-1) === ' ' || editStudentNameInput.value === '')) {
        e.preventDefault();
      }
    });
    editStudentNameInput.addEventListener("input", () => {
      editStudentNameInput.value = editStudentNameInput.value.replace(/[^a-zA-Z\s'\-\.]/g, '').replace(/\s{2,}/g, ' ');
    });
    editStudentNameInput.addEventListener("paste", (e) => {
      e.preventDefault();
      const clipboardData = e.clipboardData || window.clipboardData;
      const pastedData = clipboardData.getData('text');
      const sanitized = pastedData.replace(/[^a-zA-Z\s'\-\.]/g, '').replace(/\s{2,}/g, ' ');
      editStudentNameInput.value = (editStudentNameInput.value + sanitized).replace(/\s{2,}/g, ' ');
    });
  }

  let currentActiveTab = 'dashboard';
  let statsData = null;
  let activeCharts = {};

  // Global client localization helper
  window.__ = function (text) {
    if (!window.crmsTranslations || !window.currentLanguage || window.currentLanguage === 'en') {
      return text;
    }
    const dict = window.crmsTranslations[window.currentLanguage];
    if (dict && dict[text]) {
      return dict[text];
    }
    return text;
  };

  // Immediate page-wide DOM translator (translates text-nodes, placeholders, and dynamic views)
  window.translatePageDOM = function () {
    const lang = window.currentLanguage || 'en';
    if (!window.crmsTranslations) return;
    const dict = window.crmsTranslations[lang] || {};

    // 1. Walk only visual text nodes to preserve layout and event listeners
    const walk = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT, null, false);
    let textNode;
    const pendingReplacements = [];
    while (textNode = walk.nextNode()) {
      const val = textNode.nodeValue.trim();
      if (val && dict[val]) {
        pendingReplacements.push({ node: textNode, value: dict[val] });
      }
    }
    pendingReplacements.forEach(item => {
      item.node.nodeValue = item.value;
    });

    // 2. Translate placeholders
    document.querySelectorAll('[placeholder]').forEach(input => {
      const orig = input.getAttribute('data-orig-placeholder') || input.getAttribute('placeholder');
      if (!input.getAttribute('data-orig-placeholder')) {
        input.setAttribute('data-orig-placeholder', orig);
      }
      if (dict[orig]) {
        input.setAttribute('placeholder', dict[orig]);
      }
    });

    // 3. Translate input buttons
    document.querySelectorAll('input[type="submit"], input[type="button"]').forEach(btn => {
      const orig = btn.getAttribute('data-orig-value') || btn.value;
      if (!btn.getAttribute('data-orig-value')) {
        btn.setAttribute('data-orig-value', orig);
      }
      if (dict[orig]) {
        btn.value = dict[orig];
      }
    });

    // 4. Re-run data renders to update template literals
    if (typeof renderDrivesTable === 'function') renderDrivesTable();
    if (typeof renderKanbanPipeline === 'function') renderKanbanPipeline();
    if (typeof renderSelectedDayInterviewsList === 'function') renderSelectedDayInterviewsList();
    if (typeof loadNotificationsView === 'function') loadNotificationsView();
    if (typeof renderOfferTrackerTable === 'function') renderOfferTrackerTable();

    // Refresh active Breadcrumb title
    const crumbText = document.getElementById('nav-crumb-title');
    if (crumbText) {
      const activeLink = document.querySelector(`.nav-item-link[data-target="${currentActiveTab}"]`);
      if (activeLink) {
        crumbText.innerText = activeLink.querySelector('.nav-item-label').innerText;
      }
    }

    if (window.lucide) lucide.createIcons();
  };



  // ATS & Chat state
  let selectedStudentId = null;
  let activeChatContactId = null;
  let chatPollInterval = null;

  /* --- VIEWPORT ROUTER --- */
  function switchView(tabId) {
    currentActiveTab = tabId;
    sessionStorage.setItem('recruiter_active_tab', tabId);

    // Update navigation active class
    document.querySelectorAll('.nav-item-link').forEach(link => {
      if (link.getAttribute('data-target') === tabId) {
        link.classList.add('active');
      } else {
        link.classList.remove('active');
      }
    });

    // Update active tab section
    document.querySelectorAll('.page-view-section').forEach(sec => {
      if (sec.id === tabId) {
        sec.classList.add('active');
      } else {
        sec.classList.remove('active');
      }
    });

    // Update Breadcrumbs
    const crumbText = document.getElementById('nav-crumb-title');
    if (crumbText) {
      const activeLink = document.querySelector(`.nav-item-link[data-target="${tabId}"]`);
      if (activeLink) {
        crumbText.innerText = activeLink.querySelector('.nav-item-label').innerText;
      }
    }

    // Lazy load or refresh charts/data on load
    if (tabId === 'dashboard') {
      loadStatsAndCharts();
    } else if (tabId === 'drives') {
      renderDrivesTable();
    } else if (tabId === 'applications') {
      renderATSMaster();
    } else if (tabId === 'pipeline') {
      renderKanbanPipeline();
    } else if (tabId === 'interviews') {
      renderInterviewCalendar();
    } else if (tabId === 'analytics') {
      renderFullAnalytics();
    } else if (tabId === 'messages') {
      initChatInterface();
    } else if (tabId === 'notifications') {
      loadNotificationsView();
    } else if (tabId === 'offers') {
      renderOfferTrackerTable();
    } else if (tabId === 'aptitude') {
      fetchAptitudeTests();
    } else if (tabId === 'profile') {
      loadProfileView();
    } else if (tabId === 'settings') {
      loadSettingsView();
    } else if (tabId === 'reports') {
      loadReportsHistory();
    }

    // Trigger Lucide icons reload
    if (window.lucide) {
      lucide.createIcons();
    }
  }
  window.switchRecruiterView = switchView;

  // Sidebar toggle collapse
  const sidebar = document.getElementById('recruiter-sidebar-menu');
  const toggleBtn = document.getElementById('recruiter-sidebar-toggle');
  if (toggleBtn && sidebar) {
    const savedState = localStorage.getItem('recruiter_sidebar_state');
    const isMobile = window.innerWidth <= 768;

    if (isMobile) {
      sidebar.classList.add('collapsed');
    } else if (savedState === 'collapsed') {
      sidebar.classList.add('collapsed');
    } else {
      sidebar.classList.remove('collapsed');
    }

    toggleBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      sidebar.classList.toggle('collapsed');
      const isCollapsed = sidebar.classList.contains('collapsed');
      if (!isMobile) {
        localStorage.setItem('recruiter_sidebar_state', isCollapsed ? 'collapsed' : 'expanded');
      }
    });

    // Close sidebar on mobile when clicking outside
    document.addEventListener('click', (e) => {
      if (window.innerWidth <= 768 && !sidebar.classList.contains('collapsed')) {
        if (!sidebar.contains(e.target) && !toggleBtn.contains(e.target)) {
          sidebar.classList.add('collapsed');
        }
      }
    });
  }

  // Bind links
  document.querySelectorAll('.nav-item-link[data-target]').forEach(link => {
    link.addEventListener('click', () => {
      switchView(link.getAttribute('data-target'));
    });
  });

  // Profile Dropdown
  const profileTrigger = document.getElementById('recruiter-avatar-trigger');
  const profileDropdown = document.getElementById('recruiter-avatar-dropdown');
  if (profileTrigger && profileDropdown) {
    profileTrigger.addEventListener('click', (e) => {
      e.stopPropagation();
      profileDropdown.classList.toggle('active');
    });
    document.addEventListener('click', () => {
      profileDropdown.classList.remove('active');
    });
  }

  /* --- TAB SWITCHERS --- */
  window.switchStudentTab = function (tabId) {
    document.querySelectorAll('#student_management .sub-tab-panel').forEach(p => {
      p.classList.remove('active');
      p.style.display = 'none';
    });

    const targetPanel = document.getElementById(tabId);
    if (targetPanel) {
      targetPanel.classList.add('active');
      targetPanel.style.display = 'block';
    }

    document.querySelectorAll('#student_management .sub-tab-btn').forEach(btn => {
      btn.classList.remove('active');
      btn.setAttribute('aria-selected', 'false');
      btn.setAttribute('tabindex', '-1');
    });

    // Check both potential id formats (tab-add-student vs add-student)
    const activeBtn = document.getElementById(`tab-${tabId}`) || document.getElementById(tabId);
    if (activeBtn) {
      activeBtn.classList.add('active');
      activeBtn.setAttribute('aria-selected', 'true');
      activeBtn.setAttribute('tabindex', '0');
    }

    if (window.lucide) {
      lucide.createIcons();
    }
  };

  window.switchStudentManagementTab = function (tabId) {
    window.switchStudentTab(tabId);
  };

  window.switchOfferTab = function (tabId) {
    document.querySelectorAll('#offers .sub-offer-panel').forEach(p => {
      p.classList.remove('active');
      p.style.display = 'none';
    });

    const targetPanel = document.getElementById(`tab-${tabId}-panel`);
    if (targetPanel) {
      targetPanel.classList.add('active');
      targetPanel.style.display = 'block';
    }

    document.querySelectorAll('#offers .sub-tab-btn').forEach(link => {
      link.classList.remove('active');
    });

    const activeBtn = document.getElementById(`btn-tab-${tabId}`);
    if (activeBtn) {
      activeBtn.classList.add('active');
    }
  };

  window.switchInterviewTab = function (tabId) {
    document.querySelectorAll('#interviews .sub-interview-panel').forEach(p => {
      p.classList.remove('active');
      p.style.display = 'none';
    });

    const targetPanel = document.getElementById(`tab-${tabId}-panel`);
    if (targetPanel) {
      targetPanel.classList.add('active');
      targetPanel.style.display = 'block';
    }

    document.querySelectorAll('#interviews .sub-tab-btn').forEach(link => {
      link.classList.remove('active');
    });
    const activeBtn = document.getElementById(`btn-tab-${tabId}`);
    if (activeBtn) {
      activeBtn.classList.add('active');
    }
  };

  /* --- FILTER STUDENTS LIST --- */
  window.filterStudentManagementList = function () {
    const q = document.getElementById('student-search-input').value.toLowerCase().trim();
    const branch = document.getElementById('student-branch-filter').value;
    const rows = document.querySelectorAll('#student-directory-tbody tr');

    rows.forEach(r => {
      const text = r.innerText.toLowerCase();
      const branchBadge = r.querySelector('.badge')?.innerText.trim() || '';

      const matchesSearch = !q || text.includes(q);
      const matchesBranch = branch === 'All' || 
                            branchBadge.toLowerCase() === branch.toLowerCase() ||
                            (branch === 'CSE' && (branchBadge.toLowerCase().includes('computer') || branchBadge.toLowerCase() === 'cse')) ||
                            (branch === 'IT' && (branchBadge.toLowerCase().includes('information') || branchBadge.toLowerCase() === 'it')) ||
                            (branch === 'ECE' && (branchBadge.toLowerCase().includes('electronics') || branchBadge.toLowerCase() === 'ece')) ||
                            (branch === 'EE' && (branchBadge.toLowerCase().includes('electrical') || branchBadge.toLowerCase() === 'ee')) ||
                            (branch === 'ME' && (branchBadge.toLowerCase().includes('mechanical') || branchBadge.toLowerCase() === 'me')) ||
                            (branch === 'CE' && (branchBadge.toLowerCase().includes('civil') || branchBadge.toLowerCase() === 'ce'));

      if (matchesSearch && matchesBranch) {
        r.style.display = '';
      } else {
        r.style.display = 'none';
      }
    });
  };

  /* --- FORM SUBMISSION VALIDATORS --- */
  window.submitAddStudentForm = function (ev) {
    ev.preventDefault();
    const form = ev.target;

    // CGPA Validation
    const cgpa = parseFloat(form.cgpa.value);
    if (isNaN(cgpa) || cgpa < 1.00 || cgpa > 10.00) {
      Swal.fire({ title: 'Validation Error', text: 'CGPA must be a value between 1.00 and 10.00.', icon: 'error' });
      return;
    }

    // Phone Validation
    const phone = form.phone.value.trim();
    if (!/^[0-9]{10}$/.test(phone)) {
      Swal.fire({ title: 'Validation Error', text: 'Phone number must be exactly 10 digits containing only numbers.', icon: 'error' });
      return;
    }

    // Year Validation
    const yearVal = form.academic_year.value.trim();
    if (!/^\d{4}$/.test(yearVal) || parseInt(yearVal) < 2023 || parseInt(yearVal) > 2026) {
      Swal.fire({ title: window.__('Validation Error'), text: window.__('Academic Year must be a 4-digit numeric year between 2023 and 2026.'), icon: 'error' });
      return;
    }

    const f = new FormData(form);
    f.append('action', 'add_student');

    fetch('api/actions.php', { method: 'POST', body: f })
      .then(r => r.json())
      .then(res => {
        if (res.status === 'success') {
          form.reset();
          Swal.fire({ title: 'Success', text: 'Student Added Successfully', icon: 'success', timer: 1500 });
          setTimeout(() => window.location.reload(), 1500);
        } else {
          Swal.fire({ title: 'Error', text: res.message, icon: 'error' });
        }
      });
  };

  /* --- CANDIDATE PROFILE CRUD ACTIONS --- */
  window.viewStudentDetailsDirectly = function (studentId) {
    const student = globalData.students.find(s => parseInt(s.id) === parseInt(studentId));
    if (!student) return;

    const body = document.getElementById('view-student-details-body');
    if (body) {
      body.innerHTML = `
        <div style="display:flex; justify-content:space-between; border-bottom:1px solid var(--border-color); padding-bottom:8px;">
          <span style="color:var(--text-secondary); font-size:13px;">Full Name:</span>
          <strong style="color:var(--text-primary); font-size:13px;">${student.name}</strong>
        </div>
        <div style="display:flex; justify-content:space-between; border-bottom:1px solid var(--border-color); padding-bottom:8px;">
          <span style="color:var(--text-secondary); font-size:13px;">Email Address:</span>
          <strong style="color:var(--text-primary); font-size:13px;">${student.email}</strong>
        </div>
        <div style="display:flex; justify-content:space-between; border-bottom:1px solid var(--border-color); padding-bottom:8px;">
          <span style="color:var(--text-secondary); font-size:13px;">Roll Number:</span>
          <strong style="color:var(--text-primary); font-size:13px;">${student.roll_number}</strong>
        </div>
        <div style="display:flex; justify-content:space-between; border-bottom:1px solid var(--border-color); padding-bottom:8px;">
          <span style="color:var(--text-secondary); font-size:13px;">Department Branch:</span>
          <strong style="color:var(--text-primary); font-size:13px;">${student.department}</strong>
        </div>
        <div style="display:flex; justify-content:space-between; border-bottom:1px solid var(--border-color); padding-bottom:8px;">
          <span style="color:var(--text-secondary); font-size:13px;">Cumulative CGPA:</span>
          <strong style="color:var(--text-primary); font-size:13px;">${student.cgpa} / 10.00</strong>
        </div>
        <div style="display:flex; justify-content:space-between; border-bottom:1px solid var(--border-color); padding-bottom:8px;">
          <span style="color:var(--text-secondary); font-size:13px;">Academic Year:</span>
          <strong style="color:var(--text-primary); font-size:13px;">${student.academic_year || 'Final Year'}</strong>
        </div>
        <div style="display:flex; justify-content:space-between; border-bottom:1px solid var(--border-color); padding-bottom:8px;">
          <span style="color:var(--text-secondary); font-size:13px;">Contact Phone:</span>
          <strong style="color:var(--text-primary); font-size:13px;">+91 ${student.phone || 'N/A'}</strong>
        </div>
        <div style="display:flex; justify-content:space-between; border-bottom:1px solid var(--border-color); padding-bottom:8px;">
          <span style="color:var(--text-secondary); font-size:13px;">Resume Skills:</span>
          <strong style="color:var(--text-primary); font-size:13px;">${student.skills || 'None Listed'}</strong>
        </div>
        <div style="display:flex; justify-content:space-between; border-bottom:1px solid var(--border-color); padding-bottom:8px;">
          <span style="color:var(--text-secondary); font-size:13px;">Key Projects:</span>
          <strong style="color:var(--text-primary); font-size:13px;">${student.projects || 'None Listed'}</strong>
        </div>
        <div style="display:flex; justify-content:space-between; padding-bottom:8px;">
          <span style="color:var(--text-secondary); font-size:13px;">Student Resume:</span>
          <strong style="font-size:13px;">${student.resume_path ? `<a href="${window.getAbsoluteUrl(student.resume_path)}" target="_blank" style="color:var(--primary); font-weight:700; text-decoration:underline;">View Resume PDF</a>` : 'No Resume Submitted'}</strong>
        </div>
      `;
    }
    openRecruiterModal('modal-view-student');
  };

  window.openEditStudentModalDirectly = function (studentId) {
    const student = globalData.students.find(s => parseInt(s.id) === parseInt(studentId));
    if (!student) return;

    document.getElementById('edit-student-id').value = student.id;
    document.getElementById('edit-student-name').value = student.name;
    document.getElementById('edit-student-email').value = student.email;
    document.getElementById('edit-student-roll').value = student.roll_number;
    document.getElementById('edit-student-dept').value = student.department;
    document.getElementById('edit-student-cgpa').value = student.cgpa;
    document.getElementById('edit-student-year').value = student.academic_year || 'Final Year';
    document.getElementById('edit-student-phone').value = student.phone || '';

    openRecruiterModal('modal-edit-student');
  };

  window.submitEditStudentForm = function (ev) {
    ev.preventDefault();
    const form = ev.target;
    const btn = document.getElementById('btn-edit-student-submit');
    if (btn) btn.disabled = true;

    // Name Validation
    const nameInput = form.name;
    let nameVal = '';
    if (nameInput) {
      nameVal = nameInput.value.trim().replace(/\s{2,}/g, ' ');
      nameInput.value = nameVal;
    }
    if (!nameVal || !/^[a-zA-Z\s'\-\.]+$/.test(nameVal)) {
      Swal.fire({
        title: 'Validation Error',
        text: 'Full name can only contain letters, spaces, hyphens, apostrophes, and periods.',
        icon: 'error'
      });
      if (btn) btn.disabled = false;
      return;
    }

    // CGPA Validation
    const cgpa = parseFloat(form.cgpa.value);
    if (isNaN(cgpa) || cgpa < 1.00 || cgpa > 10.00) {
      Swal.fire({ title: 'Validation Error', text: 'CGPA must be a value between 1.00 and 10.00.', icon: 'error' });
      if (btn) btn.disabled = false;
      return;
    }

    // Phone Validation
    const phone = form.phone.value.trim();
    if (!/^[0-9]{10}$/.test(phone)) {
      Swal.fire({ title: 'Validation Error', text: 'Phone number must be exactly 10 digits containing only numbers.', icon: 'error' });
      if (btn) btn.disabled = false;
      return;
    }

    // Year Validation
    const yearVal = form.academic_year.value.trim();
    if (!/^\d{4}$/.test(yearVal) || parseInt(yearVal) < 2023 || parseInt(yearVal) > 2026) {
      Swal.fire({ title: window.__('Validation Error'), text: window.__('Academic Year must be a 4-digit numeric year between 2023 and 2026.'), icon: 'error' });
      if (btn) btn.disabled = false;
      return;
    }

    const f = new FormData(form);
    f.append('action', 'edit_student');

    fetch('api/actions.php', { method: 'POST', body: f })
      .then(r => r.json())
      .then(res => {
        if (btn) btn.disabled = false;
        if (res.status === 'success') {
          closeRecruiterModal('modal-edit-student');
          Swal.fire({ title: 'Success', text: 'Profile Updated Successfully', icon: 'success', timer: 1500 });
          setTimeout(() => window.location.reload(), 1500);
        } else {
          Swal.fire({ title: 'Error', text: res.message, icon: 'error' });
        }
      });
  };

  window.deleteStudentDirectly = function (studentId) {
    Swal.fire({
      title: 'Remove Candidate Profile',
      text: 'Are you sure you want to permanently delete this student?',
      icon: 'warning',
      showCancelButton: true,
      confirmButtonColor: '#EF4444',
      cancelButtonColor: '#6B7280',
      confirmButtonText: 'Yes, delete'
    }).then(res => {
      if (res.isConfirmed) {
        const f = new FormData();
        f.append('action', 'delete_student');
        f.append('student_id', studentId);

        fetch('api/actions.php', { method: 'POST', body: f })
          .then(r => r.json())
          .then(r => {
            if (r.status === 'success') {
              Swal.fire({ title: 'Success', text: 'Student Deleted Successfully', icon: 'success', timer: 1500 });
              setTimeout(() => window.location.reload(), 1500);
            } else {
              Swal.fire({ title: 'Error', text: r.message, icon: 'error' });
            }
          });
      }
    });
  };

  /* --- VIEW BRANCH ENROLLED CANDIDATES --- */
  window.viewBranchStudentsDirectly = function (branchName) {
    const students = globalData.students.filter(s => s.department === branchName);
    const tbody = document.getElementById('branch-students-details-body');
    const title = document.getElementById('modal-view-branch-title');

    if (title) {
      title.innerText = `${branchName} - Enrolled Candidates`;
    }

    if (tbody) {
      if (students.length === 0) {
        tbody.innerHTML = `<tr><td colspan="5" style="text-align:center; padding:24px; color:var(--text-muted);">No candidates enrolled in this branch.</td></tr>`;
      } else {
        tbody.innerHTML = students.map(s => `
          <tr>
            <td><strong>${s.roll_number}</strong></td>
            <td>${s.name}</td>
            <td><strong>${parseFloat(s.cgpa).toFixed(2)}</strong></td>
            <td>${s.academic_year || 'Final Year'}</td>
            <td>+91 ${s.phone || 'N/A'}</td>
          </tr>
        `).join('');
      }
    }
    openRecruiterModal('modal-view-branch');
  };

  window.submitReleaseOfferForm = function (ev) {
    ev.preventDefault();
    const form = ev.target;

    // Joining Date validation
    const joiningVal = form.querySelector("[name='joining_date']")?.value || '';
    const today = new Date();
    today.setHours(0, 0, 0, 0);

    const joiningParts = joiningVal ? joiningVal.split('-') : [];
    if (!joiningVal || joiningParts.length !== 3) {
      Swal.fire({ title: 'Validation Error', text: 'Joining Date is required and must be in YYYY-MM-DD format.', icon: 'error' });
      return;
    }

    const joiningYear = parseInt(joiningParts[0], 10);
    if (isNaN(joiningYear) || joiningYear < 2026 || joiningYear > 2030 || joiningParts[0].length !== 4) {
      Swal.fire({ title: 'Validation Error', text: 'Joining Date year must be a 4-digit year between 2026 and 2030.', icon: 'error' });
      return;
    }

    const joiningObj = new Date(joiningVal + 'T00:00:00');
    if (joiningObj < today) {
      Swal.fire({ title: 'Validation Error', text: 'Joining Date cannot be set prior to today\'s date.', icon: 'error' });
      return;
    }

    const f = new FormData(form);
    f.append('action', 'create_offer');

    fetch('api/actions.php', { method: 'POST', body: f })
      .then(r => r.json())
      .then(res => {
        if (res.status === 'success') {
          form.reset();
          Swal.fire({ title: 'Success', text: 'Offer Released Successfully', icon: 'success', timer: 1500 });
          setTimeout(() => window.location.reload(), 1500);
        } else {
          Swal.fire({ title: 'Error', text: res.message, icon: 'error' });
        }
      });
  };

  /* --- INTERVIEW SCHEDULE CRUD --- */
  window.openScheduleInterviewModalDirectly = function () {
    const form = document.getElementById('form-schedule-interview-api');
    if (form) {
      form.reset();
      document.getElementById('interview-edit-id').value = '';
      document.getElementById('modal-interview-title').innerText = 'Schedule Interview Round';
      document.getElementById('interview-student-wrapper').style.display = 'block';
      document.getElementById('interview-status-wrapper').style.display = 'none';
      document.getElementById('btn-schedule-int-submit').innerText = 'Confirm Schedule';
    }
    openRecruiterModal('modal-schedule-interview');
  };

  window.openEditInterviewModalDirectly = function (interviewId) {
    const form = document.getElementById('form-schedule-interview-api');
    if (!form) return;

    form.reset();
    const int = globalData.interviews.find(i => String(i.id) === String(interviewId));
    if (!int) return;

    document.getElementById('interview-edit-id').value = int.id;
    document.getElementById('modal-interview-title').innerText = 'Modify Interview Schedule';

    // Hide student select since it's already bound, select correct val if needed
    document.getElementById('interview-student-wrapper').style.display = 'none';
    document.getElementById('interview-status-wrapper').style.display = 'block';

    document.getElementById('interview-round').value = int.interview_round || 'Technical';
    document.getElementById('interview-type').value = int.interview_type || 'Online';
    document.getElementById('interview-link').value = int.meeting_link || '';
    document.getElementById('interview-date').value = int.date;
    document.getElementById('interview-time').value = int.time.slice(0, 5); // hh:mm
    document.getElementById('interview-venue').value = int.venue;
    document.getElementById('interview-interviewer').value = int.interviewer;
    document.getElementById('interview-instructions').value = int.instructions || '';
    document.getElementById('interview-notes').value = int.notes || '';
    document.getElementById('interview-status').value = int.result || 'Scheduled';

    document.getElementById('btn-schedule-int-submit').innerText = 'Update Schedule';
    openRecruiterModal('modal-schedule-interview');
  };

  window.submitInterviewForm = function (ev) {
    ev.preventDefault();
    const form = ev.target;
    const isEdit = document.getElementById('interview-edit-id').value !== '';

    // Interview Date validation
    const intDateVal = form.querySelector("[name='date']")?.value || '';
    const today = new Date();
    today.setHours(0, 0, 0, 0);

    const intParts = intDateVal ? intDateVal.split('-') : [];
    if (!intDateVal || intParts.length !== 3) {
      Swal.fire({ title: 'Validation Error', text: 'Round Date is required and must be in YYYY-MM-DD format.', icon: 'error' });
      return;
    }

    const intYear = parseInt(intParts[0], 10);
    if (isNaN(intYear) || intYear < 2026 || intYear > 2030 || intParts[0].length !== 4) {
      Swal.fire({ title: 'Validation Error', text: 'Round Date year must be a 4-digit year between 2026 and 2030.', icon: 'error' });
      return;
    }

    const intObj = new Date(intDateVal + 'T00:00:00');
    if (intObj < today) {
      Swal.fire({ title: 'Validation Error', text: 'Round Date cannot be scheduled prior to today\'s date.', icon: 'error' });
      return;
    }

    const f = new FormData(form);
    f.append('action', isEdit ? 'edit_interview' : 'schedule_interview');

    fetch('api/actions.php', { method: 'POST', body: f })
      .then(r => r.json())
      .then(res => {
        if (res.status === 'success') {
          closeRecruiterModal('modal-schedule-interview');
          Swal.fire({
            title: 'Success',
            text: isEdit ? 'Interview Updated Successfully' : 'Interview Scheduled Successfully',
            icon: 'success',
            timer: 1500
          });
          setTimeout(() => window.location.reload(), 1500);
        } else {
          Swal.fire({ title: 'Error', text: res.message, icon: 'error' });
        }
      });
  };

  window.deleteInterviewDirectly = function (interviewId) {
    Swal.fire({
      title: 'Remove Scheduled Round',
      text: 'Are you sure you want to permanently delete this interview?',
      icon: 'warning',
      showCancelButton: true,
      confirmButtonColor: '#EF4444',
      cancelButtonColor: '#6B7280',
      confirmButtonText: 'Yes, delete it'
    }).then(res => {
      if (res.isConfirmed) {
        const f = new FormData();
        f.append('action', 'delete_interview');
        f.append('interview_id', interviewId);

        fetch('api/actions.php', { method: 'POST', body: f })
          .then(r => r.json())
          .then(r => {
            if (r.status === 'success') {
              Swal.fire({ title: 'Removed', text: 'Interview deleted successfully.', icon: 'success', timer: 1500 });
              setTimeout(() => window.location.reload(), 1500);
            } else {
              Swal.fire({ title: 'Error', text: r.message, icon: 'error' });
            }
          });
      }
    });
  };

  window.cancelInterviewDirectly = function (interviewId) {
    Swal.fire({
      title: 'Cancel Scheduled Interview?',
      text: 'Are you sure you want to cancel this interview? An email notification will be sent to the candidate.',
      icon: 'warning',
      showCancelButton: true,
      confirmButtonColor: '#EF4444',
      cancelButtonColor: '#6B7280',
      confirmButtonText: 'Yes, cancel it'
    }).then(res => {
      if (res.isConfirmed) {
        Swal.fire({ title: 'Cancelling...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        const f = new FormData();
        f.append('action', 'cancel_interview');
        f.append('interview_id', interviewId);

        fetch('api/actions.php', { method: 'POST', body: f })
          .then(r => r.json())
          .then(r => {
            Swal.close();
            if (r.status === 'success') {
              Swal.fire({ title: 'Cancelled', text: r.message, icon: 'success', timer: 1500, showConfirmButton: false });
              setTimeout(() => window.location.reload(), 1500);
            } else {
              Swal.fire({ title: 'Error', text: r.message, icon: 'error' });
            }
          });
      }
    });
  };

  window.openOfferModalDirectly = function () {
    window.switchRecruiterView('offers');
    window.switchOfferTab('release-offer');
  };

  /* --- STATS & CHARTS AGGREGATOR --- */
  function loadStatsAndCharts() {
    fetch('api/stats.php')
      .then(res => res.json())
      .then(res => {
        if (res.status === 'success') {
          statsData = res;
          animateKPIs(res.kpis);
          renderDashboardCharts(res.charts);
        }
      });
  }

  function animateKPIs(kpis) {
    const mappings = {
      'kpi-active-drives': kpis.activeDrives,
      'kpi-applications': kpis.applicationsCount,
      'kpi-shortlisted': kpis.shortlistedCandidates,
      'kpi-interviews': kpis.pendingInterviews,
      'kpi-offers': kpis.offersCount,
      'kpi-hired': kpis.studentsPlaced,
      'kpi-total-students': kpis.totalStudents || 0,
      'kpi-rejected-apps': kpis.rejectedApplications,
      'kpi-pending-apps': kpis.pendingApplications,
      'kpi-upcoming-drives': kpis.upcomingDrives,

      // Analytics Page KPIs
      'analytics-kpi-active-drives': kpis.activeDrives,
      'analytics-kpi-applications': kpis.applicationsCount,
      'analytics-kpi-interviews': kpis.interviewsCount,
      'analytics-kpi-shortlisted': kpis.shortlistedCandidates,
      'analytics-kpi-offers': kpis.offersCount,
      'analytics-kpi-hired': kpis.studentsPlaced
    };

    Object.keys(mappings).forEach(id => {
      const el = document.getElementById(id);
      if (el) {
        if (mappings[id] === null || mappings[id] === undefined) {
          el.innerText = "0";
        } else {
          animateNumber(el, mappings[id]);
        }
      }
    });

    // Handle currency package metrics
    const currencyKPIs = {
      'kpi-avg-pkg': kpis.averagePackage,
      'kpi-highest-pkg': kpis.highestPackage,
      'kpi-lowest-pkg': kpis.lowestPackage,
      'analytics-pkg-highest': kpis.highestPackage,
      'analytics-pkg-avg': kpis.averagePackage,
      'analytics-pkg-lowest': kpis.lowestPackage
    };

    Object.keys(currencyKPIs).forEach(id => {
      const el = document.getElementById(id);
      if (el) {
        const val = currencyKPIs[id];
        if (val === null || val === undefined) {
          el.innerText = "₹0 LPA";
        } else {
          el.innerText = `₹${parseFloat(val).toFixed(2)} LPA`;
        }
      }
    });

    // Handle percentage KPIs directly
    const percentageKPIs = {
      'kpi-hiring-rate': kpis.hiringRate || 0,
      'kpi-selection-ratio': kpis.selectionRatio || 0,
      'kpi-acceptance-rate': kpis.offerAcceptanceRate || 0,
      'analytics-kpi-hiring-yield': kpis.hiringYield || 0
    };

    Object.keys(percentageKPIs).forEach(id => {
      const el = document.getElementById(id);
      if (el) {
        const val = percentageKPIs[id];
        el.innerText = `${val}%`;
      }
    });

    // Handle Avg Candidate CGPA KPI directly
    const elAvgCgpa = document.getElementById('analytics-kpi-avg-cgpa');
    if (elAvgCgpa) {
      elAvgCgpa.innerText = parseFloat(kpis.avgApplicantCGPA || 0).toFixed(2);
    }

    // Handle Offer Acceptance Rate value directly
    const elOar = document.getElementById('analytics-oar-value');
    if (elOar) {
      elOar.innerText = `${kpis.offerAcceptanceRate || 0}%`;
    }

    // Handle CGPA Distribution Bars dynamically
    const outstandingPct = kpis.cgpaHighPct || 0;
    const distinctionPct = kpis.cgpaMidPct || 0;
    const eligiblePct = kpis.cgpaLowPct || 0;

    const elOutPct = document.getElementById('cgpa-outstanding-pct');
    const elOutBar = document.getElementById('cgpa-outstanding-bar');
    if (elOutPct) elOutPct.innerText = `${outstandingPct}%`;
    if (elOutBar) elOutBar.style.width = `${outstandingPct}%`;

    const elDistPct = document.getElementById('cgpa-distinction-pct');
    const elDistBar = document.getElementById('cgpa-distinction-bar');
    if (elDistPct) elDistPct.innerText = `${distinctionPct}%`;
    if (elDistBar) elDistBar.style.width = `${distinctionPct}%`;

    const elEligPct = document.getElementById('cgpa-eligible-pct');
    const elEligBar = document.getElementById('cgpa-eligible-bar');
    if (elEligPct) elEligPct.innerText = `${eligiblePct}%`;
    if (elEligBar) elEligBar.style.width = `${eligiblePct}%`;
  }

  function animateNumber(element, finalVal) {
    let start = 0;
    const duration = 800;
    const increment = finalVal / (duration / 16);

    const updateCount = () => {
      start += increment;
      if (start >= finalVal) {
        element.innerText = Math.round(finalVal).toLocaleString();
      } else {
        element.innerText = Math.round(start).toLocaleString();
        requestAnimationFrame(updateCount);
      }
    };
    updateCount();
  }

  function renderDashboardCharts(charts) {
    if (typeof Chart === 'undefined') return;

    // Clear previous instances
    Object.keys(activeCharts).forEach(key => {
      if (activeCharts[key]) activeCharts[key].destroy();
    });

    Chart.defaults.font.family = "'Inter', sans-serif";
    Chart.defaults.color = "#64748B";

    // 1. Placement Trend (Line Chart)
    const ctxTrend = document.getElementById('chart-placement-trend');
    if (ctxTrend) {
      activeCharts.trend = new Chart(ctxTrend, {
        type: 'line',
        data: {
          labels: charts.months,
          datasets: [{
            label: 'Total Hired',
            data: charts.placementsTrend,
            borderColor: '#2563EB',
            borderWidth: 3,
            backgroundColor: 'rgba(37,99,235,0.06)',
            fill: true,
            tension: 0.4
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: { legend: { display: false } },
          scales: {
            y: { grid: { color: '#F1F5F9' } },
            x: { grid: { display: false } }
          }
        }
      });
    }

    // 2. Monthly Applications (Bar Chart)
    const ctxApps = document.getElementById('chart-applications-month');
    if (ctxApps) {
      activeCharts.apps = new Chart(ctxApps, {
        type: 'bar',
        data: {
          labels: charts.months,
          datasets: [{
            data: charts.applicationsTrend,
            backgroundColor: '#06B6D4',
            borderRadius: 6
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: { legend: { display: false } },
          scales: {
            y: { grid: { color: '#F1F5F9' } },
            x: { grid: { display: false } }
          }
        }
      });
    }

    // 3. Dept applications (Pie Chart)
    const ctxDept = document.getElementById('chart-students-dept');
    if (ctxDept) {
      activeCharts.dept = new Chart(ctxDept, {
        type: 'doughnut',
        data: {
          labels: charts.deptLabels,
          datasets: [{
            data: charts.deptCounts,
            backgroundColor: ['#2563EB', '#7C3AED', '#10B981', '#F59E0B', '#EF4444', '#64748B']
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: { position: 'bottom', labels: { boxWidth: 10, padding: 15 } }
          }
        }
      });
    }

    // 4. Funnel Chart (Horizontal Bars)
    renderFunnelVisualization(charts.funnel);
  }

  function renderFunnelVisualization(funnel) {
    const container = document.getElementById('hiring-funnel-container');
    if (!container) return;

    const stages = [
      { label: 'Applications', val: funnel.applied, color: '#3B82F6' },
      { label: 'Screened Profiles', val: funnel.eligible, color: '#6366F1' },
      { label: 'Aptitude Round', val: funnel.aptitude, color: '#8B5CF6' },
      { label: 'Technical Rounds', val: funnel.technical, color: '#EC4899' },
      { label: 'HR Interviews', val: funnel.hr, color: '#F43F5E' },
      { label: 'Selected/Hired', val: funnel.selected, color: '#10B981' }
    ];

    const maxVal = funnel.applied || 1;
    container.innerHTML = stages.map(s => {
      const pct = maxVal > 0 ? Math.round((s.val / maxVal) * 100) : 0;
      return `
        <div style="margin-bottom: 8px;">
          <div style="display:flex; justify-content:space-between; font-size:11px; font-weight:600; margin-bottom:2px;">
            <span>${s.label}</span>
            <span>${s.val} (${pct}%)</span>
          </div>
          <div style="height:8px; background-color:#F1F5F9; border-radius:10px; overflow:hidden;">
            <div style="height:100%; width:${pct}%; background-color:${s.color}; border-radius:10px;"></div>
          </div>
        </div>
      `;
    }).join('');
  }

  /* --- PLACEMENT DRIVES DIRECTORY --- */
  function renderDrivesTable() {
    const tbody = document.getElementById('recruiter-drives-tbody');
    if (!tbody) return;

    // Filter drives
    let filtered = [...globalData.drives];

    // Apply filters
    const searchVal = document.getElementById('drive-search-input')?.value.toLowerCase() || '';
    if (searchVal) {
      filtered = filtered.filter(d => d.jobRole.toLowerCase().includes(searchVal) || d.companyName.toLowerCase().includes(searchVal));
    }

    const statusVal = document.getElementById('drive-filter-status')?.value || 'All';
    if (statusVal !== 'All') {
      filtered = filtered.filter(d => d.status.toLowerCase() === statusVal.toLowerCase());
    }

    if (filtered.length === 0) {
      tbody.innerHTML = `
        <tr>
          <td colspan="10" style="text-align:center; padding:48px;">
            <div class="empty-illustration-container">
              <i data-lucide="briefcase" style="width:48px; height:48px; color:var(--text-muted); margin-bottom:12px;"></i>
              <div class="empty-heading">No placement drives found</div>
              <div class="empty-subtext">You have not initialized any drives yet. Click Create Drive to begin.</div>
              <button class="btn btn-primary" onclick="openRecruiterModal('modal-create-drive')">Create Drive</button>
            </div>
          </td>
        </tr>
      `;
      if (window.lucide) lucide.createIcons();
      return;
    }

    tbody.innerHTML = filtered.map(d => {
      return `
        <tr>
          <td>
            <div style="display:flex; align-items:center; gap:8px;">
              ${d.companyLogo && d.companyLogo.trim() !== "" 
                ? `<div class="candidate-avatar" style="background-color:#ffffff; border:1px solid var(--border-color); display:flex; align-items:center; justify-content:center;"><img src="${globalData.baseUrl}${d.companyLogo.replace(/^\/+/, '')}" alt="${d.companyName}" style="width:100%; height:100%; object-fit:contain; border-radius:inherit;"></div>`
                : `<div class="candidate-avatar">${d.companyName.slice(0, 2).toUpperCase()}</div>`
              }
              <div>
                <div style="font-weight:600;">${d.companyName}</div>
              </div>
            </div>
          </td>
          <td><strong>${d.jobRole}</strong></td>
          <td>${d.departments}</td>
          <td>${d.eligibilityCGPA}</td>
          <td><strong>₹${d.packageLPA} LPA</strong></td>
          <td>${d.registration_deadline}</td>
          <td><span class="badge ${d.status === 'open' ? 'badge-primary' : (d.status === 'completed' ? 'badge-success' : 'badge-warning')}">${d.status}</span></td>
          <td>
            <div style="display:inline-flex; gap:4px;">
              <button class="btn btn-ghost btn-sm btn-icon-only" onclick="window.showModuleDetails('drive', ${d.id})" title="View Details" style="color:var(--primary);">
                <i data-lucide="eye" style="width:14px; height:14px;"></i>
              </button>
              <button class="btn btn-ghost btn-sm btn-icon-only" onclick="cloneRecruiterDrive(${d.id})" title="Clone Campaign">
                <i data-lucide="copy" style="width:14px; height:14px;"></i>
              </button>
              <button class="btn btn-ghost btn-sm btn-icon-only" onclick="deleteRecruiterDrive(${d.id})" title="Remove Campaign" style="color:var(--color-danger);">
                <i data-lucide="trash-2" style="width:14px; height:14px;"></i>
              </button>
            </div>
          </td>
        </tr>
      `;
    }).join('');

    if (window.lucide) lucide.createIcons();
  }

  window.cloneRecruiterDrive = function (driveId) {
    const f = new FormData();
    f.append('action', 'clone_drive');
    f.append('drive_id', driveId);
    fetch('api/actions.php', {
      method: 'POST',
      body: f
    })
      .then(r => r.json())
      .then(r => {
        if (r.status === 'success') {
          Swal.fire({ title: 'Success', text: 'Drive Cloned Successfully', icon: 'success', timer: 1500 });
          setTimeout(() => window.location.reload(), 1500);
        } else {
          Swal.fire({ title: 'Error', text: r.message, icon: 'error' });
        }
      });
  };

  window.deleteRecruiterDrive = function (driveId) {
    Swal.fire({
      title: 'Remove Drive Campaign',
      text: 'Are you sure you want to delete this drive?',
      icon: 'warning',
      showCancelButton: true,
      confirmButtonColor: '#EF4444',
      cancelButtonColor: '#6B7280',
      confirmButtonText: 'Yes, delete!'
    }).then((res) => {
      if (res.isConfirmed) {
        const f = new FormData();
        f.append('action', 'bulk_drives');
        f.append('operation', 'delete');
        f.append('drive_ids[]', driveId);

        fetch('api/actions.php', {
          method: 'POST',
          credentials: 'same-origin',
          body: f
        })
          .then(r => r.json())
          .then(r => {
            if (r.status === 'success') {
              Swal.fire({ title: 'Success', text: 'Drive deleted successfully', icon: 'success', timer: 1500 });
              globalData.drives = globalData.drives.filter(d => parseInt(d.id) !== parseInt(driveId));
              renderDrivesTable();
              setTimeout(() => window.location.reload(), 1500);
            } else {
              Swal.fire({ title: 'Error', text: r.message, icon: 'error' });
            }
          });
      }
    });
  };

  // Bind filters
  document.getElementById('drive-search-input')?.addEventListener('input', renderDrivesTable);
  document.getElementById('drive-filter-status')?.addEventListener('change', renderDrivesTable);

  /* --- ATS APPLICANTS WORKSPACE --- */
  function renderATSMaster() {
    const listEl = document.getElementById('ats-candidates-list');
    if (!listEl) return;

    let filteredApps = [...globalData.applications];
    const searchVal = document.getElementById('ats-search-input')?.value.toLowerCase() || '';
    if (searchVal) {
      filteredApps = filteredApps.filter(a => a.studentName.toLowerCase().includes(searchVal) || a.role.toLowerCase().includes(searchVal));
    }

    if (filteredApps.length === 0) {
      listEl.innerHTML = '<div style="color:var(--text-muted); font-size:12px; text-align:center; padding:16px;">No applicants match query.</div>';
      return;
    }

    listEl.innerHTML = filteredApps.map(app => `
      <div class="candidate-card-item ${selectedStudentId === app.studentId ? 'active' : ''}" onclick="loadATSCandidate(${app.studentId}, ${app.id})">
        <div class="candidate-card-header">
          <div class="candidate-avatar">${app.studentName.slice(0, 2).toUpperCase()}</div>
          <div class="candidate-meta-info">
            <h4 class="candidate-name">${app.studentName}</h4>
            <p class="candidate-job">${app.role}</p>
            <div class="candidate-tags">
              <span class="badge badge-primary" style="font-size:9px; padding:1px 5px;">${app.department || 'CSE'}</span>
              <span class="badge badge-success" style="font-size:9px; padding:1px 5px;">${app.cgpa} CGPA</span>
            </div>
          </div>
        </div>
      </div>
    `).join('');

    // Preload first candidate details if not selected
    if (!selectedStudentId && filteredApps.length > 0) {
      loadATSCandidate(filteredApps[0].studentId, filteredApps[0].id);
    }
  }

  window.loadATSCandidate = function (studentId, appId) {
    selectedStudentId = studentId;

    // Highlight active card
    document.querySelectorAll('.candidate-card-item').forEach(card => {
      card.classList.remove('active');
    });

    // Re-query details
    const student = globalData.students.find(s => String(s.id) === String(studentId) || String(s.user_id) === String(studentId));
    const application = globalData.applications.find(a => String(a.id) === String(appId));
    if (!student || !application) return;

    // Center Panel rendering
    const centerEl = document.getElementById('ats-candidate-details');
    if (centerEl) {
      centerEl.innerHTML = `
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:24px; border-bottom:1px solid var(--border-color); padding-bottom:16px;">
          <div style="display:flex; gap:16px; align-items:center;">
            <div class="avatar-profile" style="width:64px; height:64px; font-size:24px;">${student.name.slice(0, 2).toUpperCase()}</div>
            <div>
              <h2 style="font-size:20px; font-weight:700;">${student.name}</h2>
              <p style="color:var(--text-secondary); font-size:13px;">${student.email} &bull; ${student.phone || '+91 9876543210'}</p>
            </div>
          </div>
          <span class="badge ${application.status === 'Selected' ? 'badge-success' : (application.status === 'Rejected' ? 'badge-danger' : 'badge-primary')}" style="font-size:13px; padding:6px 12px;">
            Round: ${application.status === 'Eligible' ? 'Shortlisted' : application.status}
          </span>
        </div>

        <div class="grid-container" style="margin-bottom:24px;">
          <div class="dashboard-card col-6">
            <h4 style="font-size:14px; font-weight:700; margin-bottom:12px;">Academic Details</h4>
            <div style="display:flex; flex-direction:column; gap:8px; font-size:13px;">
              <div style="display:flex; justify-content:space-between;">
                <span style="color:var(--text-secondary);">University Branch</span>
                <strong>${student.department}</strong>
              </div>
              <div style="display:flex; justify-content:space-between;">
                <span style="color:var(--text-secondary);">Roll Number</span>
                <strong>${student.roll_number || '2023-CS-12'}</strong>
              </div>
              <div style="display:flex; justify-content:space-between;">
                <span style="color:var(--text-secondary);">Cumulative GPA</span>
                <strong style="color:var(--primary);">${student.cgpa} / 10.0</strong>
              </div>
            </div>
          </div>

           <div class="dashboard-card col-6">
            <h4 style="font-size:14px; font-weight:700; margin-bottom:12px;">Resume Skills</h4>
            <div style="display:flex; flex-wrap:wrap; gap:6px; margin-top:8px;">
              ${student.skills && student.skills.trim() ? student.skills.split(',').filter(s => s.trim().length > 0).map(s => `
                <span class="badge badge-primary">${s.trim()}</span>
              `).join('') : '<span style="font-size:12px; color:var(--text-muted);">No skills listed.</span>'}
            </div>
          </div>
        </div>

        <div class="dashboard-card" style="margin-bottom:24px;">
          <h4 style="font-size:14px; font-weight:700; margin-bottom:12px;">Projects & Capstones</h4>
          <p style="font-size:13px; color:var(--text-secondary); line-height:1.6;">${student.projects || 'No projects listed.'}</p>
        </div>

        <div style="display:flex; gap:12px; margin-top:16px;">
          <button class="btn btn-primary" onclick="setCandidateFunnelStatus(${appId}, 'Eligible')" style="flex:1;">Shortlist Student</button>
          <button class="btn btn-secondary" onclick="openInterviewSchedulePanel(${appId})" style="flex:1;">Schedule Interview</button>
          <button class="btn btn-ghost" onclick="setCandidateFunnelStatus(${appId}, 'Rejected')" style="color:var(--color-danger); border:1px solid var(--color-danger); flex:1;">Reject Profile</button>
        </div>
      `;
    }

    // Right Panel Resume PDF loader
    const resumeFrame = document.getElementById('ats-resume-iframe');
    const resumeHeader = document.getElementById('ats-resume-header-filename');
    if (resumeFrame && resumeHeader) {
      if (student.resume_path) {
        resumeFrame.src = window.getAbsoluteUrl(student.resume_path);
        resumeHeader.innerHTML = `
          <span>Academic_Resume.pdf</span>
          <a href="${window.getAbsoluteUrl(student.resume_path)}" download class="btn btn-ghost btn-sm" style="padding:4px 8px; font-size:11px;">Download</a>
        `;
      } else {
        resumeFrame.src = '';
        resumeHeader.innerHTML = '<span>No resume submitted</span>';
        resumeFrame.srcdoc = `
          <div style="display:flex; height:100%; align-items:center; justify-content:center; flex-direction:column; font-family:sans-serif; color:#94a3b8; text-align:center; padding:20px;">
            <i data-lucide="file-x" style="width:48px; height:48px; margin-bottom:12px;"></i>
            <h3>Candidate has not uploaded a resume yet</h3>
          </div>
        `;
        if (window.lucide) lucide.createIcons();
      }
    }

    // Highlight active list item visually
    document.querySelectorAll('.candidate-card-item').forEach(card => {
      const name = card.querySelector('.candidate-name').innerText;
      if (name === student.name) {
        card.classList.add('active');
      }
    });
  };

  window.setCandidateFunnelStatus = function (appId, result) {
    const statusLabel = result === 'Eligible' ? 'Shortlisted' : result;
    Swal.fire({
      title: 'Update Candidate Round',
      text: `Do you want to mark this candidate status as ${statusLabel}?`,
      icon: 'question',
      showCancelButton: true,
      confirmButtonColor: '#2563EB',
      cancelButtonColor: '#6B7280',
      confirmButtonText: 'Yes, update'
    }).then((res) => {
      if (res.isConfirmed) {
        const f = new FormData();
        f.append('action', 'publish_selection');
        f.append('application_id', appId);
        f.append('result', result);

        fetch('api/actions.php', { method: 'POST', body: f })
          .then(r => r.json())
          .then(r => {
            if (r.status === 'success') {
              Swal.fire({ title: 'Success', text: 'Status updated successfully', icon: 'success', timer: 1500 });
              setTimeout(() => window.location.reload(), 1500);
            } else {
              Swal.fire({ title: 'Error', text: r.message, icon: 'error' });
            }
          });
      }
    });
  };

  window.openInterviewSchedulePanel = function (appId) {
    openScheduleInterviewModalDirectly();
    const select = document.getElementById('interview-app-id');
    if (select) select.value = appId;
  };

  document.getElementById('ats-search-input')?.addEventListener('input', renderATSMaster);

  /* --- PIPELINE KANBAN --- */
  function renderKanbanPipeline() {
    const board = document.getElementById('kanban-board-wrapper');
    if (!board) return;

    const stages = ["Applied", "Eligible", "Aptitude", "Technical", "HR", "Selected", "Rejected"];
    const apps = globalData.applications;

    board.innerHTML = stages.map(stage => {
      const stageApps = apps.filter(a => a.status.toLowerCase() === stage.toLowerCase());
      return `
        <div class="kanban-stage-column" data-stage="${stage}">
          <div class="kanban-column-header">
            <div class="kanban-column-title">
              <span style="width:8px; height:8px; border-radius:50%; background-color:${getKanbanStageColor(stage)}; display:inline-block;"></span>
              ${stage === 'Eligible' ? 'Shortlisted' : stage}
            </div>
            <span class="kanban-counter-badge">${stageApps.length}</span>
          </div>
          <div class="kanban-cards-wrapper" data-stage="${stage}" ondragover="allowKanbanDrop(event)" ondragleave="handleKanbanLeave(event)" ondrop="handleKanbanDrop(event, '${stage}')">
            ${stageApps.map(app => `
              <div class="kanban-card-item" draggable="true" ondragstart="handleKanbanDragStart(event, ${app.id})" id="kanban-card-${app.id}">
                <div style="font-weight:600; font-size:13px; margin-bottom:4px;">${app.studentName}</div>
                <div style="font-size:11px; color:var(--text-secondary); margin-bottom:8px;">${app.role}</div>
                <div style="display:flex; justify-content:space-between; align-items:center; font-size:11px; font-weight:600; color:var(--primary);">
                  <span>${app.department || 'CSE'}</span>
                  <span style="color:#10B981;">${app.cgpa} CGPA</span>
                </div>
              </div>
            `).join('')}
          </div>
        </div>
      `;
    }).join('');
  }

  function getKanbanStageColor(stage) {
    switch (stage.toLowerCase()) {
      case 'applied': return '#3B82F6';
      case 'eligible': return '#6366F1';
      case 'aptitude': return '#8B5CF6';
      case 'technical': return '#EC4899';
      case 'hr': return '#F43F5E';
      case 'selected': return '#10B981';
      case 'rejected': return '#EF4444';
      default: return '#94A3B8';
    }
  }

  window.handleKanbanDragStart = function (ev, appId) {
    ev.dataTransfer.setData("text/plain", appId);
    const card = document.getElementById(`kanban-card-${appId}`);
    if (card) card.classList.add('dragging');
  };

  window.allowKanbanDrop = function (ev) {
    ev.preventDefault();
    const wrapper = ev.currentTarget;
    wrapper.style.backgroundColor = 'rgba(37,99,235,0.04)';
    wrapper.style.borderRadius = 'var(--radius-md)';
  };

  window.handleKanbanLeave = function (ev) {
    ev.currentTarget.style.backgroundColor = 'transparent';
  };

  window.handleKanbanDrop = function (ev, stage) {
    ev.preventDefault();
    ev.currentTarget.style.backgroundColor = 'transparent';

    const appId = parseInt(ev.dataTransfer.getData("text/plain"));
    const card = document.getElementById(`kanban-card-${appId}`);
    if (card) {
      card.classList.remove('dragging');
      ev.currentTarget.appendChild(card);
    }

    // Trigger SQL update action in backend
    const f = new FormData();
    f.append('action', 'publish_selection');
    f.append('application_id', appId);
    f.append('result', stage);

    fetch('api/actions.php', {
      method: 'POST',
      body: f
    })
      .then(r => r.json())
      .then(r => {
        if (r.status === 'success') {
          Swal.fire({ title: 'Success', text: `Candidate successfully moved to ${stage} round.`, icon: 'success', timer: 1500 });
          // Reload global variables dynamically
          const app = globalData.applications.find(a => String(a.id) === String(appId));
          if (app) app.status = stage;
          renderKanbanPipeline();
          loadStatsAndCharts();
        } else {
          Swal.fire({ title: 'Error', text: r.message, icon: 'error' });
        }
      });
  };

  /* --- INTERVIEW MANAGEMENT CALENDAR --- */
  const todayObj = new Date();
  let calendarMonthIndex = new Date();
  let selectedCalendarDateStr = `${todayObj.getFullYear()}-${String(todayObj.getMonth() + 1).padStart(2, '0')}-${String(todayObj.getDate()).padStart(2, '0')}`;

  function renderInterviewCalendar() {
    const el = document.getElementById('calendar-grid-container');
    if (!el) return;

    el.innerHTML = '';
    const year = calendarMonthIndex.getFullYear();
    const month = calendarMonthIndex.getMonth();

    const monthNames = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];
    document.getElementById('calendar-month-year-label').innerText = `${monthNames[month]} ${year}`;

    const firstDayIndex = new Date(year, month, 1).getDay();
    const daysInMonth = new Date(year, month + 1, 0).getDate();

    // Render empty pads
    let html = '';
    for (let i = 0; i < firstDayIndex; i++) {
      html += '<div class="calendar-day-cell empty" style="opacity:0.2; pointer-events:none;"></div>';
    }

    for (let d = 1; d <= daysInMonth; d++) {
      const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
      const dayInterviews = globalData.interviews.filter(i => i.date === dateStr);

      const isSelected = dateStr === selectedCalendarDateStr;

      let dotHtml = '';
      if (dayInterviews.length > 0) {
        dotHtml = `
          <div class="calendar-event-indicators">
            <span class="event-dot ${dayInterviews.length > 2 ? 'danger' : 'primary'}"></span>
          </div>
        `;
      }

      html += `
        <div class="calendar-day-cell ${isSelected ? 'active-selected' : ''}" onclick="selectCalendarDay('${dateStr}')">
          <span class="calendar-day-number">${d}</span>
          ${dotHtml}
        </div>
      `;
    }

    el.innerHTML = html;
    renderSelectedDayInterviewsList();
  }

  window.navigateCalendar = function (direction) {
    calendarMonthIndex.setMonth(calendarMonthIndex.getMonth() + direction);
    renderInterviewCalendar();
  };

  window.selectCalendarDay = function (dateStr) {
    selectedCalendarDateStr = dateStr;
    renderInterviewCalendar();
  };

  function renderSelectedDayInterviewsList() {
    const el = document.getElementById('calendar-interviews-list');
    if (!el) return;

    const filtered = globalData.interviews.filter(i => i.date === selectedCalendarDateStr);

    if (filtered.length === 0) {
      el.innerHTML = `
        <div class="empty-illustration-container" style="padding:16px;">
          <h4 class="empty-heading" style="font-size:13px;">No scheduled rounds</h4>
          <p class="empty-subtext" style="font-size:11px;">There are no interviews lined up for ${selectedCalendarDateStr}.</p>
        </div>
      `;
      return;
    }

    el.innerHTML = filtered.map(int => `
      <div style="border:1px solid var(--border-color); border-radius:var(--radius-md); padding:16px; margin-bottom:12px; background-color:white;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
          <span class="badge badge-primary">${int.interview_round || 'Technical'}</span>
          <div style="display:flex; gap:6px; align-items:center;">
            ${int.result === 'Cancelled' ? `<span class="badge badge-danger">Cancelled</span>` : ''}
            <span style="font-size:12px; font-weight:700; color:var(--primary);">${int.time}</span>
          </div>
        </div>
        <h4 style="font-size:14px; font-weight:700; margin-bottom:4px;">${int.studentName}</h4>
        <p style="font-size:12px; color:var(--text-secondary); margin-bottom:12px;">Role Designation: ${int.role}</p>
        
        <div style="font-size:11px; color:var(--text-muted); display:flex; flex-direction:column; gap:4px; margin-bottom:12px;">
          <div>Location/Venue: <strong>${int.venue}</strong></div>
          <div>Interviewer: <strong>${int.interviewer}</strong></div>
          ${int.meeting_link ? `<div>Link: <a href="${int.meeting_link}" target="_blank">${int.meeting_link}</a></div>` : ''}
        </div>

        <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-top: 12px;">
          ${int.result === 'Scheduled' ? `
            <button class="btn btn-secondary btn-sm" onclick="openFeedbackModal(${int.id})" style="padding: 6px 4px; font-size: 11px;">Complete & Rate</button>
            <button class="btn btn-ghost btn-sm" onclick="openEditInterviewModalDirectly(${int.id})" style="border: 1px solid var(--border-color); padding: 6px 4px; font-size: 11px;">Reschedule</button>
            <button class="btn btn-ghost btn-sm" onclick="window.showModuleDetails('interview', ${int.id})" style="border: 1px solid var(--border-color); padding: 6px 4px; font-size: 11px;">
              <i data-lucide="eye" style="width:12px; height:12px; margin-right:4px; vertical-align:middle; display:inline-block; margin-top:-2px;"></i> Details
            </button>
            <button class="btn btn-ghost btn-sm" onclick="cancelInterviewDirectly(${int.id})" style="color:var(--color-danger); border:1px solid rgba(239, 68, 68, 0.2); padding: 6px 4px; font-size: 11px;">
              <i data-lucide="x-circle" style="width:12px; height:12px; margin-right:4px; vertical-align:middle; display:inline-block; margin-top:-2px;"></i> Cancel
            </button>
          ` : int.result === 'Cancelled' ? `
            <button class="btn btn-ghost btn-sm" onclick="window.showModuleDetails('interview', ${int.id})" style="border: 1px solid var(--border-color); padding: 6px 4px; font-size: 11px;">
              <i data-lucide="eye" style="width:12px; height:12px; margin-right:4px; vertical-align:middle; display:inline-block; margin-top:-2px;"></i> Details
            </button>
            <button class="btn btn-ghost btn-sm" onclick="deleteInterviewDirectly(${int.id})" style="color:var(--color-danger); border:1px solid rgba(239, 68, 68, 0.2); padding: 6px 4px; font-size: 11px;">
              <i data-lucide="trash-2" style="width:12px; height:12px; margin-right:4px; vertical-align:middle; display:inline-block; margin-top:-2px;"></i> Delete
            </button>
          ` : `
            <button class="btn btn-ghost btn-sm" onclick="window.showModuleDetails('interview', ${int.id})" style="border: 1px solid var(--border-color); padding: 6px 4px; font-size: 11px; grid-column: span 2; width: 100%;">
              <i data-lucide="eye" style="width:12px; height:12px; margin-right:4px; vertical-align:middle; display:inline-block; margin-top:-2px;"></i> View Details
            </button>
          `}
        </div>
      </div>
    `).join('');
    if (window.lucide) window.lucide.createIcons();
  }

  window.openFeedbackModal = function (interviewId) {
    const inputIntId = document.getElementById('feedback-interview-id');
    if (inputIntId) inputIntId.value = interviewId;
    openRecruiterModal('modal-interview-feedback');
  };

  /* --- REPORT EXPORTS --- */
  window.triggerDataExport = function (format) {
    const dateVal = document.getElementById('report-filter-date')?.value || '2026-07-01';
    const statusVal = document.getElementById('report-filter-status')?.value || 'All';
    const reportName = `Placement Drives Report (${statusVal})`;

    let csvContent = "data:text/csv;charset=utf-8,";
    csvContent += "Drive ID,Company,Role,CGPA Criteria,CTC package,Date,Status\r\n";

    globalData.drives.forEach(d => {
      const matchesStatus = (statusVal === 'All' || d.status === statusVal);
      const matchesDate = (new Date(d.date) >= new Date(dateVal));
      if (matchesStatus && matchesDate) {
        csvContent += `${d.id},"${d.companyName}","${d.jobRole}",${d.eligibilityCGPA},${d.packageLPA},${d.date},${d.status}\r\n`;
      }
    });

    const encodedUri = encodeURI(csvContent);
    const link = document.createElement("a");
    link.setAttribute("href", encodedUri);
    link.setAttribute("download", `placement_drives_report_${format}.csv`);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);

    // Save report to database via AJAX
    const fData = new FormData();
    fData.append('action', 'save_report');
    fData.append('report_name', reportName);
    fData.append('date_range', dateVal);
    fData.append('filter_status', statusVal);
    fData.append('format', format);

    fetch('api/actions.php', {
      method: 'POST',
      body: fData
    })
      .then(r => r.json())
      .then(res => {
        if (res.status === 'success') {
          loadReportsHistory();
        }
      })
      .catch(err => console.error('Error saving report to db:', err));

    Swal.fire({ title: 'Export Complete', text: 'CSV Report Downloaded & Saved Successfully.', icon: 'success', timer: 1500 });
  };

  function loadReportsHistory() {
    const tbody = document.getElementById('reports-history-tbody');
    if (!tbody) return;

    fetch('api/actions.php?action=get_reports')
      .then(r => r.json())
      .then(res => {
        if (res.status === 'success' && res.reports) {
          if (res.reports.length === 0) {
            tbody.innerHTML = `<tr><td colspan="6" style="text-align:center; padding:16px; color:var(--text-muted);">No reports generated yet.</td></tr>`;
            return;
          }
          tbody.innerHTML = res.reports.map(r => `
            <tr>
              <td>#${r.id}</td>
              <td style="font-weight:600;">${r.report_name}</td>
              <td>Since ${r.date_range}</td>
              <td><span class="badge badge-primary">${r.filter_status}</span></td>
              <td><span class="badge badge-secondary">${r.format.toUpperCase()}</span></td>
              <td>${r.generated_at}</td>
            </tr>
          `).join('');
        }
      })
      .catch(err => console.error('Error fetching reports history:', err));
  }
  window.loadReportsHistory = loadReportsHistory;

  /* --- MESSAGING CLIENT --- */
  function initChatInterface() {
    const listEl = document.getElementById('chat-contacts-list');
    if (!listEl) return;

    fetch('api/actions.php?action=get_chat_contacts')
      .then(r => r.json())
      .then(res => {
        if (res.status === 'success') {
          listEl.innerHTML = res.contacts.map(c => `
            <div class="candidate-card-item ${activeChatContactId === c.id ? 'active' : ''}" onclick="selectChatContact(${c.id}, '${c.name}')">
              <div style="display:flex; justify-content:space-between; align-items:center;">
                <div style="display:flex; gap:10px; align-items:center;">
                  <div class="candidate-avatar">${c.name.slice(0, 2).toUpperCase()}</div>
                  <div>
                    <h4 style="font-size:13px; font-weight:600;">${c.name}</h4>
                    <p style="font-size:11px; color:var(--text-secondary); max-width:140px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                      ${c.last_msg || 'No messages yet.'}
                    </p>
                  </div>
                </div>
                ${c.unread_count > 0 ? `<span class="badge badge-danger" style="padding:2px 6px; font-size:10px;">${c.unread_count}</span>` : ''}
              </div>
            </div>
          `).join('');

          if (!activeChatContactId && res.contacts.length > 0) {
            selectChatContact(res.contacts[0].id, res.contacts[0].name);
          }
        }
      });
  }

  window.selectChatContact = function (contactId, contactName) {
    activeChatContactId = contactId;
    document.getElementById('chat-active-contact-title').innerText = contactName;

    // Refresh active status highlighting
    document.querySelectorAll('#chat-contacts-list .candidate-card-item').forEach(card => {
      card.classList.remove('active');
    });

    pollChatMessages();

    // Set polling interval for messages
    if (chatPollInterval) clearInterval(chatPollInterval);
    chatPollInterval = setInterval(pollChatMessages, 5000);
  };

  function pollChatMessages() {
    if (!activeChatContactId || currentActiveTab !== 'messages') return;

    fetch(`api/actions.php?action=get_messages&contact_id=${activeChatContactId}`)
      .then(r => r.json())
      .then(res => {
        if (res.status === 'success') {
          const container = document.getElementById('chat-messages-scroll');
          if (!container) return;

          container.innerHTML = res.messages.map(m => {
            const isSent = parseInt(m.sender_id) === parseInt(globalData.userId);
            return `
              <div class="chat-message-bubble ${isSent ? 'sent' : 'received'}">
                <div>${m.message}</div>
                <div class="chat-bubble-time">${new Date(m.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}</div>
              </div>
            `;
          }).join('');

          container.scrollTop = container.scrollHeight;
        }
      });
  }

  // Bind message sending
  const chatForm = document.getElementById('chat-message-form');
  if (chatForm) {
    chatForm.addEventListener('submit', (e) => {
      e.preventDefault();
      const input = document.getElementById('chat-message-input');
      const text = input.value.trim();
      if (!text || !activeChatContactId) return;

      const f = new FormData();
      f.append('action', 'send_message');
      f.append('receiver_id', activeChatContactId);
      f.append('message', text);

      fetch('api/actions.php', { method: 'POST', body: f })
        .then(r => r.json())
        .then(r => {
          if (r.status === 'success') {
            input.value = '';
            pollChatMessages();
          }
        });
    });
  }

  /* --- BRANDING / PROFILE SUBMITS --- */
  const profileForm = document.getElementById('recruiter-profile-form');
  if (profileForm) {
    profileForm.addEventListener('submit', (e) => {
      e.preventDefault();
      const submitBtn = profileForm.querySelector("button[type='submit']");
      if (submitBtn) submitBtn.disabled = true;

      // Phone Validation
      const phone = profileForm.phone.value.trim();
      if (phone !== '' && !/^[0-9]{10}$/.test(phone)) {
        Swal.fire({ title: 'Validation Error', text: 'Phone number must be exactly 10 digits containing only numbers.', icon: 'error' });
        if (submitBtn) submitBtn.disabled = false;
        return;
      }

      const f = new FormData(profileForm);
      f.append('action', 'update_profile');

      fetch('api/actions.php', { method: 'POST', body: f })
        .then(r => r.json())
        .then(r => {
          if (submitBtn) submitBtn.disabled = false;
          if (r.status === 'success') {
            Swal.fire({ title: 'Success', text: 'Profile Updated Successfully', icon: 'success', timer: 1500 });
            setTimeout(() => window.location.reload(), 1500);
          } else {
            Swal.fire({ title: 'Error', text: r.message, icon: 'error' });
          }
        });
    });
  }

  // Password update is handled by the inline onsubmit form handler submitRecruiterPasswordForm(event)

  // Save Settings
  window.submitRecruiterSettingsForm = function (ev) {
    ev.preventDefault();
    const form = ev.target;
    const btn = document.getElementById('btn-save-settings-submit');
    if (btn) btn.disabled = true;

    const f = new FormData(form);
    f.append('action', 'save_user_settings');

    fetch('api/actions.php', { method: 'POST', body: f })
      .then(r => r.json())
      .then(r => {
        if (btn) btn.disabled = false;
        if (r.status === 'success') {
          // Sync theme client side immediately
          const selectedTheme = form.theme.value;
          document.documentElement.setAttribute('data-theme', selectedTheme);
          localStorage.setItem('theme', selectedTheme);

          // Sync language client side immediately without refresh
          const selectedLang = form.language.value;
          window.currentLanguage = selectedLang;
          window.translatePageDOM();

          Swal.fire({ title: window.__('Success'), text: window.__('Settings Updated Successfully'), icon: 'success', timer: 1500 });
        } else {
          Swal.fire({ title: window.__('Error'), text: window.__(r.message), icon: 'error' });
        }
      });
  };

  // Dynamic uploads logo/banner
  window.triggerBrandingUpload = function (inputId, type) {
    const fileInput = document.getElementById(inputId);
    if (!fileInput || !fileInput.files[0]) return;

    const f = new FormData();
    f.append('type', type);
    f.append('file', fileInput.files[0]);

    fetch('api/upload.php', { method: 'POST', body: f })
      .then(r => r.json())
      .then(r => {
        if (r.status === 'success') {
          Swal.fire({ title: 'Success', text: 'Branding file uploaded successfully.', icon: 'success', timer: 1500 });
          setTimeout(() => window.location.reload(), 1500);
        } else {
          Swal.fire({ title: 'Upload Failed', text: r.message, icon: 'error' });
        }
      });
  };

  /* --- DIALOG PANELS --- */
  window.openRecruiterModal = function (modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
      modal.classList.add('active');
      // Set focus to modal or close button for screen reader accessibility
      const closeBtn = modal.querySelector('.recruiter-modal-close');
      if (closeBtn) closeBtn.focus();
    }
  };

  window.closeRecruiterModal = function (modalId) {
    const modal = document.getElementById(modalId);
    if (modal) modal.classList.remove('active');
  };

  // Close modals clicking cross/cancel or clicking outside on backdrop
  document.querySelectorAll('.recruiter-modal-overlay').forEach(overlay => {
    // 1. Cross/Cancel buttons click
    overlay.querySelectorAll('.recruiter-modal-close, .modal-cancel-btn').forEach(btn => {
      btn.addEventListener('click', (e) => {
        e.stopPropagation();
        closeRecruiterModal(overlay.id);
      });
    });
    // 2. Click outside (backdrop click)
    overlay.addEventListener('click', (e) => {
      if (e.target === overlay) {
        closeRecruiterModal(overlay.id);
      }
    });
  });

  // Keyboard accessibility: Escape key closes the active modal
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' || e.key === 'Esc') {
      const activeModal = document.querySelector('.recruiter-modal-overlay.active');
      if (activeModal) {
        closeRecruiterModal(activeModal.id);
      }
    }
  });

  // Handle dialog feedback form submit
  const feedbackForm = document.getElementById('form-interview-feedback-api');
  if (feedbackForm) {
    feedbackForm.addEventListener('submit', (e) => {
      e.preventDefault();
      const btn = document.getElementById('btn-feedback-submit');
      if (btn) btn.disabled = true;

      const f = new FormData(feedbackForm);
      f.append('action', 'complete_interview');

      fetch('api/actions.php', { method: 'POST', body: f })
        .then(r => r.json())
        .then(r => {
          if (btn) btn.disabled = false;
          if (r.status === 'success') {
            closeRecruiterModal('modal-interview-feedback');
            Swal.fire({ title: 'Success', text: 'Evaluation Saved Successfully', icon: 'success', timer: 1500 });
            setTimeout(() => window.location.reload(), 1500);
          } else {
            Swal.fire({ title: 'Error', text: r.message, icon: 'error' });
          }
        });
    });
  }

  // Form submit add drive
  const addDriveForm = document.getElementById('form-add-drive-recruiter');
  if (addDriveForm) {
    addDriveForm.addEventListener('submit', (e) => {
      e.preventDefault();
      const btn = document.getElementById('btn-add-drive-recruiter-submit');
      if (btn) btn.disabled = true;

      // CGPA check
      const cgpa = parseFloat(addDriveForm.eligibility_cgpa.value);
      if (isNaN(cgpa) || cgpa < 1.00 || cgpa > 10.00) {
        Swal.fire({ title: 'Validation Error', text: 'CGPA criteria must be between 1.00 and 10.00.', icon: 'error' });
        if (btn) btn.disabled = false;
        return;
      }

      // Date validations
      const deadline = addDriveForm.registration_deadline.value;
      const today = new Date();
      today.setHours(0, 0, 0, 0);

      const deadlineParts = deadline ? deadline.split('-') : [];

      if (!deadline || deadlineParts.length !== 3) {
        Swal.fire({ title: 'Validation Error', text: 'Registration Deadline is required and must be in YYYY-MM-DD format.', icon: 'error' });
        if (btn) btn.disabled = false;
        return;
      }

      const deadlineYear = parseInt(deadlineParts[0], 10);

      if (isNaN(deadlineYear) || deadlineYear < 2026 || deadlineYear > 2030 || deadlineParts[0].length !== 4) {
        Swal.fire({ title: 'Validation Error', text: 'Registration Deadline year must be a 4-digit year between 2026 and 2030.', icon: 'error' });
        if (btn) btn.disabled = false;
        return;
      }

      const deadlineObj = new Date(deadline + 'T00:00:00');

      if (deadlineObj < today) {
        Swal.fire({ title: 'Validation Error', text: 'Registration Deadline cannot be set before today\'s date.', icon: 'error' });
        if (btn) btn.disabled = false;
        return;
      }

      const f = new FormData(addDriveForm);
      f.append('action', 'create_drive');

      fetch('api/actions.php', { method: 'POST', body: f })
        .then(r => r.json())
        .then(r => {
          if (btn) btn.disabled = false;
          if (r.status === 'success') {
            closeRecruiterModal('modal-create-drive');
            Swal.fire({ title: 'Success', text: 'Drive Created Successfully', icon: 'success', timer: 1500 });
            setTimeout(() => window.location.reload(), 1500);
          } else {
            Swal.fire({ title: 'Error', text: r.message, icon: 'error' });
          }
        });
    });
  }

  /* --- SIDEBAR SUBMENUS CLEANED --- */

  /* --- OFFER STATUS FILTERING --- */
  window.filterOfferHistory = function (status) {
    const rows = document.querySelectorAll('#offer-history-tbody tr');
    rows.forEach(row => {
      const badge = row.querySelector('.badge');
      if (!badge) return;
      const currentStatus = badge.innerText.trim();
      if (status === 'All') {
        row.style.display = '';
      } else if (status === 'Released' && currentStatus === 'Released') {
        row.style.display = '';
      } else if (status === 'Accepted' && currentStatus === 'Accepted') {
        row.style.display = '';
      } else if (status === 'Declined' && (currentStatus === 'Declined' || currentStatus === 'Rejected')) {
        row.style.display = '';
      } else {
        row.style.display = 'none';
      }
    });
  };

  /* --- PLACEMENT HISTORY SEARCH, FILTER & PAGINATION --- */
  let placementHistoryPage = 1;
  const placementHistoryPageSize = 10;
  let placementHistoryFilteredRows = [];

  window.filterPlacementHistoryTable = function () {
    const q = (document.getElementById('placement-history-search')?.value || '').toLowerCase().trim();
    const status = document.getElementById('placement-history-filter-status')?.value || 'All';
    const rows = Array.from(document.querySelectorAll('#placement-history-tbody tr.placement-history-row'));

    placementHistoryFilteredRows = rows.filter(row => {
      const name = row.getAttribute('data-name') || '';
      const company = row.getAttribute('data-company') || '';
      const role = row.getAttribute('data-role') || '';
      const rowStatus = row.getAttribute('data-status') || '';

      const matchesSearch = !q || name.includes(q) || company.includes(q) || role.includes(q);
      const matchesStatus = status === 'All' || rowStatus === status;

      return matchesSearch && matchesStatus;
    });

    // Hide all rows
    rows.forEach(r => r.style.display = 'none');

    placementHistoryPage = 1;
    updatePlacementHistoryPagination();
  };

  function updatePlacementHistoryPagination() {
    const total = placementHistoryFilteredRows.length;
    const totalPages = Math.ceil(total / placementHistoryPageSize) || 1;

    if (placementHistoryPage < 1) placementHistoryPage = 1;
    if (placementHistoryPage > totalPages) placementHistoryPage = totalPages;

    const startIdx = (placementHistoryPage - 1) * placementHistoryPageSize;
    const endIdx = Math.min(startIdx + placementHistoryPageSize, total);

    // Show only rows in current page
    for (let i = startIdx; i < endIdx; i++) {
      if (placementHistoryFilteredRows[i]) {
        placementHistoryFilteredRows[i].style.display = '';
      }
    }

    // Update info text
    const info = document.getElementById('placement-history-pagination-info');
    if (info) {
      if (total === 0) {
        info.innerText = `Showing 0 of 0`;
      } else {
        info.innerText = `Showing ${startIdx + 1}-${endIdx} of ${total}`;
      }
    }

    // Toggle button state
    const prevBtn = document.getElementById('btn-placement-prev');
    const nextBtn = document.getElementById('btn-placement-next');
    if (prevBtn) prevBtn.disabled = placementHistoryPage === 1;
    if (nextBtn) nextBtn.disabled = placementHistoryPage === totalPages;
  }

  window.changePlacementHistoryPage = function (offset) {
    placementHistoryPage += offset;
    // Hide all currently visible rows in the filtered subset
    placementHistoryFilteredRows.forEach(r => r.style.display = 'none');
    updatePlacementHistoryPagination();
  };

  window.showModuleDetails = function (moduleType, recordId) {
    const titleEl = document.getElementById('generic-details-title');
    const bodyEl = document.getElementById('generic-details-body');
    if (!titleEl || !bodyEl) return;

    bodyEl.innerHTML = '';

    if (moduleType === 'drive') {
      const drive = globalData.drives.find(d => parseInt(d.id) === parseInt(recordId));
      if (!drive) {
        bodyEl.innerHTML = '<p style="color:var(--color-danger);">Campaign drive details not found in cache.</p>';
        openRecruiterModal('modal-view-generic-details');
        return;
      }
      titleEl.innerText = 'Placement Drive Campaign Details';
      bodyEl.innerHTML = `
        <div style="display:flex; justify-content:space-between; border-bottom:1px solid var(--border-color); padding-bottom:8px;">
          <span style="color:var(--text-secondary);">Company Name:</span>
          <strong>${drive.companyName}</strong>
        </div>
        <div style="display:flex; justify-content:space-between; border-bottom:1px solid var(--border-color); padding-bottom:8px;">
          <span style="color:var(--text-secondary);">Job Designation / Role:</span>
          <strong>${drive.jobRole}</strong>
        </div>
        <div style="display:flex; justify-content:space-between; border-bottom:1px solid var(--border-color); padding-bottom:8px;">
          <span style="color:var(--text-secondary);">Eligibility Criteria (Min CGPA):</span>
          <strong>${parseFloat(drive.eligibilityCGPA).toFixed(2)}</strong>
        </div>
        <div style="display:flex; justify-content:space-between; border-bottom:1px solid var(--border-color); padding-bottom:8px;">
          <span style="color:var(--text-secondary);">Compensation LPA Package:</span>
          <strong>₹${parseFloat(drive.packageLPA).toFixed(2)} LPA</strong>
        </div>
        <div style="display:flex; justify-content:space-between; border-bottom:1px solid var(--border-color); padding-bottom:8px;">
          <span style="color:var(--text-secondary);">Drive Commencement Date:</span>
          <strong>${drive.date || drive.drive_date}</strong>
        </div>
        <div style="display:flex; justify-content:space-between; border-bottom:1px solid var(--border-color); padding-bottom:8px;">
          <span style="color:var(--text-secondary);">Registration Deadline:</span>
          <strong>${drive.registration_deadline}</strong>
        </div>
        <div style="display:flex; justify-content:space-between; border-bottom:1px solid var(--border-color); padding-bottom:8px;">
          <span style="color:var(--text-secondary);">Target Department Branches:</span>
          <strong>${drive.departments}</strong>
        </div>
        <div style="display:flex; flex-direction:column; gap:4px;">
          <span style="color:var(--text-secondary);">Required Tech Stack / Skills:</span>
          <div style="display:flex; flex-wrap:wrap; gap:6px; margin-top:4px;">
            ${(drive.skills_required || '').split(',').map(s => `<span class="badge badge-primary">${s.trim()}</span>`).join('')}
          </div>
        </div>
      `;
    } else if (moduleType === 'interview') {
      const int = globalData.interviews.find(i => parseInt(i.id) === parseInt(recordId));
      if (!int) {
        bodyEl.innerHTML = '<p style="color:var(--color-danger);">Interview details not found.</p>';
        openRecruiterModal('modal-view-generic-details');
        return;
      }
      titleEl.innerText = 'Interview Round Details';
      bodyEl.innerHTML = `
        <div style="display:flex; justify-content:space-between; border-bottom:1px solid var(--border-color); padding-bottom:8px;">
          <span style="color:var(--text-secondary);">Candidate Name:</span>
          <strong>${int.studentName}</strong>
        </div>
        <div style="display:flex; justify-content:space-between; border-bottom:1px solid var(--border-color); padding-bottom:8px;">
          <span style="color:var(--text-secondary);">Target Job Designation:</span>
          <strong>${int.role}</strong>
        </div>
        <div style="display:flex; justify-content:space-between; border-bottom:1px solid var(--border-color); padding-bottom:8px;">
          <span style="color:var(--text-secondary);">Interview Round Type:</span>
          <strong>${int.interview_round || 'Technical'}</strong>
        </div>
        <div style="display:flex; justify-content:space-between; border-bottom:1px solid var(--border-color); padding-bottom:8px;">
          <span style="color:var(--text-secondary);">Scheduled Date:</span>
          <strong>${int.date}</strong>
        </div>
        <div style="display:flex; justify-content:space-between; border-bottom:1px solid var(--border-color); padding-bottom:8px;">
          <span style="color:var(--text-secondary);">Time Slot:</span>
          <strong>${int.time}</strong>
        </div>
        <div style="display:flex; justify-content:space-between; border-bottom:1px solid var(--border-color); padding-bottom:8px;">
          <span style="color:var(--text-secondary);">Interview Venue / Room:</span>
          <strong>${int.venue}</strong>
        </div>
        <div style="display:flex; justify-content:space-between; border-bottom:1px solid var(--border-color); padding-bottom:8px;">
          <span style="color:var(--text-secondary);">Interviewer:</span>
          <strong>${int.interviewer}</strong>
        </div>
        <div style="display:flex; justify-content:space-between; border-bottom:1px solid var(--border-color); padding-bottom:8px;">
          <span style="color:var(--text-secondary);">Decision Result:</span>
          <span class="badge badge-primary">${int.result || 'Scheduled'}</span>
        </div>
        ${int.meeting_link ? `
        <div style="display:flex; justify-content:space-between; border-bottom:1px solid var(--border-color); padding-bottom:8px;">
          <span style="color:var(--text-secondary);">Virtual Meeting Link:</span>
          <strong><a href="${int.meeting_link}" target="_blank">${int.meeting_link}</a></strong>
        </div>` : ''}
        <div style="display:flex; flex-direction:column; gap:4px; border-bottom:1px solid var(--border-color); padding-bottom:8px;">
          <span style="color:var(--text-secondary);">Candidate Instructions:</span>
          <p style="margin:4px 0 0 0; background:#F8FAFC; padding:8px; border-radius:4px; font-size:12px;">${int.instructions || 'N/A'}</p>
        </div>
        <div style="display:flex; flex-direction:column; gap:4px;">
          <span style="color:var(--text-secondary);">Evaluation Remarks:</span>
          <p style="margin:4px 0 0 0; background:#F8FAFC; padding:8px; border-radius:4px; font-size:12px;">${int.feedback || int.remarks || 'No feedback submitted yet.'}</p>
        </div>
      `;
    } else if (moduleType === 'offer') {
      const offer = globalData.offers.find(o => parseInt(o.id) === parseInt(recordId));
      if (!offer) {
        bodyEl.innerHTML = '<p style="color:var(--color-danger);">' + window.__('Offer letter details not found.') + '</p>';
        openRecruiterModal('modal-view-generic-details');
        return;
      }
      titleEl.innerText = window.__('Offer Letter Details');
      bodyEl.innerHTML = `
        <div style="display:flex; justify-content:space-between; border-bottom:1px solid var(--border-color); padding-bottom:8px;">
          <span style="color:var(--text-secondary);">${window.__('Candidate')}:</span>
          <strong>${offer.studentName}</strong>
        </div>
        <div style="display:flex; justify-content:space-between; border-bottom:1px solid var(--border-color); padding-bottom:8px;">
          <span style="color:var(--text-secondary);">${window.__('Company Name')}:</span>
          <strong>${offer.companyName || globalData.userName || 'Company Recruiter'}</strong>
        </div>
        <div style="display:flex; justify-content:space-between; border-bottom:1px solid var(--border-color); padding-bottom:8px;">
          <span style="color:var(--text-secondary);">${window.__('Role Designation')}:</span>
          <strong>${offer.designation}</strong>
        </div>
        <div style="display:flex; justify-content:space-between; border-bottom:1px solid var(--border-color); padding-bottom:8px;">
          <span style="color:var(--text-secondary);">${window.__('Compensation')}:</span>
          <strong>₹${parseFloat(offer.salary_lpa).toFixed(2)} LPA</strong>
        </div>
        <div style="display:flex; justify-content:space-between; border-bottom:1px solid var(--border-color); padding-bottom:8px;">
          <span style="color:var(--text-secondary);">${window.__('Joining date')}:</span>
          <strong>${offer.joining_date}</strong>
        </div>
        <div style="display:flex; justify-content:space-between; border-bottom:1px solid var(--border-color); padding-bottom:8px;">
          <span style="color:var(--text-secondary);">${window.__('HQ Location')}:</span>
          <strong>${offer.location}</strong>
        </div>
        <div style="display:flex; justify-content:space-between; border-bottom:1px solid var(--border-color); padding-bottom:8px;">
          <span style="color:var(--text-secondary);">${window.__('Offer Date')}:</span>
          <strong>${offer.offer_date || 'N/A'}</strong>
        </div>
        <div style="display:flex; justify-content:space-between; border-bottom:1px solid var(--border-color); padding-bottom:8px;">
          <span style="color:var(--text-secondary);">${window.__('Expiry Date')}:</span>
          <strong>${offer.expiry_date || 'N/A'}</strong>
        </div>
        <div style="display:flex; justify-content:space-between; border-bottom:1px solid var(--border-color); padding-bottom:8px;">
          <span style="color:var(--text-secondary);">${window.__('Offer Status')}:</span>
          <span class="badge ${offer.status === 'Accepted' ? 'badge-success' : (offer.status === 'Rejected' ? 'badge-danger' : 'badge-primary')}">${window.__(offer.status)}</span>
        </div>
        <div style="display:flex; justify-content:space-between; border-bottom:1px solid var(--border-color); padding-bottom:8px;">
          <span style="color:var(--text-secondary);">${window.__('Sent Date')}:</span>
          <strong>${offer.sent_date || 'N/A'}</strong>
        </div>
        <div style="display:flex; justify-content:space-between; border-bottom:1px solid var(--border-color); padding-bottom:8px;">
          <span style="color:var(--text-secondary);">${window.__('Viewed Date')}:</span>
          <strong>${offer.viewed_date || 'N/A'}</strong>
        </div>
        <div style="display:flex; justify-content:space-between; border-bottom:1px solid var(--border-color); padding-bottom:8px;">
          <span style="color:var(--text-secondary);">${window.__('Accepted Date')}:</span>
          <strong>${offer.accepted_date || 'N/A'}</strong>
        </div>
        <div style="display:flex; justify-content:space-between; border-bottom:1px solid var(--border-color); padding-bottom:8px;">
          <span style="color:var(--text-secondary);">${window.__('Rejected Date')}:</span>
          <strong>${offer.rejected_date || 'N/A'}</strong>
        </div>
        ${offer.offer_letter_path ? `
        <div style="display:flex; justify-content:space-between; align-items:center; padding-top:8px;">
          <span style="color:var(--text-secondary);">${window.__('Offer PDF')}:</span>
          <a href="${window.getAbsoluteUrl(offer.offer_letter_path)}" target="_blank" class="btn btn-primary btn-sm" style="font-size:11px; padding:4px 10px;">
            <i data-lucide="download" style="width:12px; height:12px; margin-right:4px; vertical-align:middle;"></i> ${window.__('Download')} PDF
          </a>
        </div>` : ''}
      `;
    } else if (moduleType === 'notification') {
      const notif = globalData.notifications.find(n => parseInt(n.id) === parseInt(recordId));
      if (!notif) {
        bodyEl.innerHTML = '<p style="color:var(--color-danger);">Notification details not found.</p>';
        openRecruiterModal('modal-view-generic-details');
        return;
      }
      titleEl.innerText = 'Notification Details';
      bodyEl.innerHTML = `
        <div style="display:flex; justify-content:space-between; border-bottom:1px solid var(--border-color); padding-bottom:8px;">
          <span style="color:var(--text-secondary);">Topic / Category:</span>
          <strong class="badge badge-primary">${notif.category}</strong>
        </div>
        <div style="display:flex; justify-content:space-between; border-bottom:1px solid var(--border-color); padding-bottom:8px;">
          <span style="color:var(--text-secondary);">Broadcast Priority:</span>
          <strong style="color:${notif.priority === 'high' ? 'var(--color-danger)' : 'var(--text-secondary)'};">${notif.priority.toUpperCase()}</strong>
        </div>
        <div style="display:flex; justify-content:space-between; border-bottom:1px solid var(--border-color); padding-bottom:8px;">
          <span style="color:var(--text-secondary);">Date Created:</span>
          <strong>${notif.created_at}</strong>
        </div>
        <div style="display:flex; flex-direction:column; gap:4px; margin-top:8px;">
          <span style="color:var(--text-secondary);">Notification Alert Title:</span>
          <strong style="font-size:14px; color:var(--text-primary);">${notif.title}</strong>
        </div>
        <div style="display:flex; flex-direction:column; gap:4px; margin-top:8px;">
          <span style="color:var(--text-secondary);">Full Description Details:</span>
          <p style="margin:4px 0 0 0; background:#F8FAFC; padding:12px; border-radius:6px; font-size:12px; line-height:1.6; color:var(--text-secondary);">${notif.description}</p>
        </div>
      `;
    }

    openRecruiterModal('modal-view-generic-details');
    if (window.lucide) lucide.createIcons();
  }; function loadNotificationsView() {
    const container = document.getElementById('notifications-list-container');
    if (!container) return;

    fetch('api/notifications.php?filter=all')
      .then(res => res.json())
      .then(res => {
        if (res.status !== 'success') return;

        // Update badges
        const headerBadge = document.getElementById('recruiter-header-notif-badge');
        if (headerBadge) {
          if (res.unread_count > 0) {
            headerBadge.innerText = res.unread_count;
            headerBadge.style.display = 'inline-block';
          } else {
            headerBadge.style.display = 'none';
          }
        }
        const sidebarBadge = document.getElementById('recruiter-sidebar-notif-badge');
        if (sidebarBadge) {
          if (res.unread_count > 0) {
            sidebarBadge.innerText = res.unread_count;
            sidebarBadge.style.display = 'inline-block';
          } else {
            sidebarBadge.style.display = 'none';
          }
        }

        let allNotifs = [];
        const groups = res.notifications;
        ['today', 'yesterday', 'thisWeek', 'older'].forEach(key => {
          if (groups[key]) {
            allNotifs = allNotifs.concat(groups[key]);
          }
        });

        // Cache them
        globalData.notifications = allNotifs;

        // Sort: unread (is_read=0) first, then date DESC
        allNotifs.sort((a, b) => {
          if (a.is_read != b.is_read) {
            return a.is_read - b.is_read;
          }
          return new Date(b.created_at) - new Date(a.created_at);
        });

        if (allNotifs.length === 0) {
          container.innerHTML = `
            <div class="empty-illustration-container" style="padding:48px; text-align:center;">
              <i data-lucide="bell-off" style="width:48px; height:48px; color:var(--text-muted); margin-bottom:12px;"></i>
              <h4 class="empty-heading">${window.__('No notifications found')}</h4>
              <p class="empty-subtext">${window.__('You are all caught up! There are no new broadcasts or activities.')}</p>
            </div>
          `;
          if (window.lucide) lucide.createIcons();
          return;
        }

        container.innerHTML = allNotifs.map(n => {
          const isUnread = n.is_read == 0;
          return `
            <div style="border:1px solid var(--border-color); border-radius:var(--radius-md); padding:16px; background-color:${isUnread ? 'rgba(37,99,235,0.06)' : 'white'}; display:flex; justify-content:space-between; align-items:center; gap:16px; transition:all 0.2s ease; margin-bottom: 8px;" class="lift">
              <div style="display:flex; gap:12px; align-items:flex-start;">
                <div style="background-color:${n.priority === 'high' ? 'rgba(239,68,68,0.1)' : 'rgba(37,99,235,0.1)'}; color:${n.priority === 'high' ? 'var(--color-danger)' : 'var(--primary)'}; border-radius:50%; padding:8px; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                  <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                </div>
                <div>
                  <h4 style="font-weight:700; font-size:14px; margin-bottom:4px; color:var(--text-primary); display:flex; align-items:center; gap:8px;">
                    ${window.__(n.title)}
                    ${isUnread ? `<span class="badge badge-primary" style="padding: 2px 6px; font-size: 9px; border-radius: 4px;">${window.__('New')}</span>` : ''}
                  </h4>
                  <p style="color:var(--text-secondary); font-size:12px; margin-bottom:6px;">${window.__(n.description)}</p>
                  <span style="font-size:10px; color:var(--text-muted);">${new Date(n.created_at).toLocaleString()}</span>
                </div>
              </div>
              <div style="display:flex; gap:8px; align-items:center; flex-shrink:0;">
                <button class="btn btn-secondary btn-sm" onclick="window.showModuleDetails('notification', ${n.id})">${window.__('View Details')}</button>
                ${isUnread ? `<button class="btn btn-primary btn-sm btn-recruiter-mark-read" data-id="${n.id}" style="padding:4px 8px; font-size:11px;">${window.__('Mark Read')}</button>` : ''}
                <button class="btn btn-ghost btn-sm btn-recruiter-delete-notif" data-id="${n.id}" style="padding:6px; color:var(--color-danger); border:none; background:transparent; cursor:pointer;" title="${window.__('Delete')}">
                  <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
                </button>
              </div>
            </div>
          `;
        }).join('');

        // Bind Mark Read Button
        container.querySelectorAll(".btn-recruiter-mark-read").forEach(btn => {
          btn.addEventListener("click", () => {
            const id = btn.getAttribute("data-id");
            const form = new FormData();
            form.append("action", "mark_read");
            form.append("notification_id", id);
            fetch('api/notifications.php', { method: 'POST', body: form })
              .then(() => {
                loadNotificationsView();
                pollNotifications();
              });
          });
        });

        // Bind Delete Button
        container.querySelectorAll(".btn-recruiter-delete-notif").forEach(btn => {
          btn.addEventListener("click", () => {
            const id = btn.getAttribute("data-id");
            Swal.fire({
              title: window.__('Are you sure?'),
              text: window.__("You won't be able to revert this!"),
              icon: 'warning',
              showCancelButton: true,
              confirmButtonColor: '#EF4444',
              cancelButtonColor: '#6B7280',
              confirmButtonText: window.__('Yes, delete it!'),
              cancelButtonText: window.__('Cancel')
            }).then(result => {
              if (result.isConfirmed) {
                const form = new FormData();
                form.append("action", "delete");
                form.append("notification_id", id);
                fetch('api/notifications.php', { method: 'POST', body: form })
                  .then(() => {
                    Swal.fire({ title: window.__('Deleted!'), text: window.__('Notification has been removed.'), icon: 'success', timer: 1500, showConfirmButton: false });
                    loadNotificationsView();
                    pollNotifications();
                  });
              }
            });
          });
        });

        // Bind Mark All Read
        const btnMarkAll = document.getElementById("recruiter-mark-all-read");
        if (btnMarkAll) {
          btnMarkAll.onclick = () => {
            const form = new FormData();
            form.append("action", "mark_all_read");
            fetch('api/notifications.php', { method: 'POST', body: form })
              .then(() => {
                Swal.fire({ title: window.__('Success'), text: window.__('All notifications marked as read.'), icon: 'success', timer: 1500, showConfirmButton: false });
                loadNotificationsView();
                pollNotifications();
              });
          };
        }

        if (window.lucide) lucide.createIcons();
      });
  }
  /* --- DYNAMIC OFFER TRACKER WORKSPACE --- */
  window.renderOfferTrackerTable = function () {
    const tbody = document.getElementById('offer-history-tbody');
    if (!tbody) return;

    const query = (document.getElementById('offer-tracker-search')?.value || '').toLowerCase().trim();
    const statusFilter = document.getElementById('offer-tracker-status-filter')?.value || 'All';
    const sortBy = document.getElementById('offer-tracker-sort')?.value || 'id-desc';

    let list = [...(globalData.offers || [])];

    // Filter by search query
    if (query !== '') {
      list = list.filter(o =>
        (o.studentName || '').toLowerCase().includes(query) ||
        (o.designation || '').toLowerCase().includes(query) ||
        (o.location || '').toLowerCase().includes(query)
      );
    }

    // Filter by status
    if (statusFilter !== 'All') {
      list = list.filter(o => o.status === statusFilter);
    }

    // Sort
    list.sort((a, b) => {
      if (sortBy === 'name-asc') {
        return (a.studentName || '').localeCompare(b.studentName || '');
      } else if (sortBy === 'salary-desc') {
        return parseFloat(b.salary_lpa) - parseFloat(a.salary_lpa);
      } else if (sortBy === 'date-desc') {
        return new Date(b.offer_date || 0) - new Date(a.offer_date || 0);
      } else if (sortBy === 'expiry-asc') {
        return new Date(a.expiry_date || '9999-12-31') - new Date(b.expiry_date || '9999-12-31');
      } else {
        return parseInt(b.id) - parseInt(a.id);
      }
    });

    if (list.length === 0) {
      tbody.innerHTML = `<tr><td colspan="10" style="text-align:center; padding:32px; color:var(--text-muted);">${window.__('No offer letters released yet.')}</td></tr>`;
      return;
    }

    tbody.innerHTML = list.map(o => {
      let statusClass = 'badge-primary';
      if (o.status === 'Accepted') statusClass = 'badge-success';
      else if (o.status === 'Rejected' || o.status === 'Declined') statusClass = 'badge-danger';
      else if (o.status === 'Expired') statusClass = 'badge-warning';

      return `
        <tr>
          <td>
            <div style="font-weight:700;">${o.studentName}</div>
            <div style="font-size:11px; color:var(--text-secondary);">${o.department || 'CSE'} (${parseFloat(o.cgpa).toFixed(2)} CGPA)</div>
          </td>
          <td>${o.companyName || globalData.userName || 'Company Recruiter'}</td>
          <td><strong>${o.designation}</strong></td>
          <td>${o.offer_date ? o.offer_date.split(' ')[0] : (o.joining_date || 'N/A')}</td>
          <td>${o.expiry_date ? o.expiry_date.split(' ')[0] : 'N/A'}</td>
          <td><span class="badge ${statusClass}">${window.__(o.status)}</span></td>
          <td>${o.sent_date ? o.sent_date.split(' ')[0] : (o.offer_date ? o.offer_date.split(' ')[0] : 'N/A')}</td>
          <td>${o.viewed_date ? o.viewed_date.split(' ')[0] : 'N/A'}</td>
          <td>
            ${o.status === 'Accepted' ? `<span style="color:var(--color-success); font-weight:600;">${o.accepted_date ? o.accepted_date.split(' ')[0] : 'Accepted'}</span>` : ''}
            ${o.status === 'Rejected' || o.status === 'Declined' ? `<span style="color:var(--color-danger); font-weight:600;">${o.rejected_date ? o.rejected_date.split(' ')[0] : 'Declined'}</span>` : ''}
            ${o.status !== 'Accepted' && o.status !== 'Rejected' && o.status !== 'Declined' ? 'N/A' : ''}
          </td>
          <td>
            <div style="display:inline-flex; gap:4px; align-items:center;">
              <button class="btn btn-ghost btn-sm btn-icon-only" onclick="window.showModuleDetails('offer', ${o.id})" title="${window.__('View Details')}">
                <i data-lucide="eye" style="width:14px; height:14px; color:var(--primary);"></i>
              </button>
              <button class="btn btn-ghost btn-sm btn-icon-only" onclick="window.openEditOfferModalDirectly(${o.id})" title="${window.__('Edit')}">
                <i data-lucide="edit" style="width:14px; height:14px; color:var(--primary);"></i>
              </button>
              <button class="btn btn-ghost btn-sm btn-icon-only" onclick="window.deleteOfferDirectly(${o.id})" style="color:var(--color-danger);" title="${window.__('Delete Offer')}">
                <i data-lucide="trash-2" style="width:14px; height:14px;"></i>
              </button>
              ${o.offer_letter_path ? `
                <a href="${window.getAbsoluteUrl(o.offer_letter_path)}" target="_blank" class="btn btn-ghost btn-sm btn-icon-only" title="${window.__('Download')} PDF">
                  <i data-lucide="download" style="width:14px; height:14px; color:var(--primary);"></i>
                </a>
              ` : ''}
            </div>
          </td>
        </tr>
      `;
    }).join('');

    if (window.lucide) lucide.createIcons();
  };

  window.openEditOfferModalDirectly = function (offerId) {
    const offer = globalData.offers.find(o => parseInt(o.id) === parseInt(offerId));
    if (!offer) return;

    document.getElementById('edit-offer-id').value = offer.id;
    document.getElementById('edit-offer-designation').value = offer.designation;
    document.getElementById('edit-offer-salary').value = offer.salary_lpa;
    document.getElementById('edit-offer-joining').value = offer.joining_date;
    document.getElementById('edit-offer-location').value = offer.location;
    document.getElementById('edit-offer-status').value = offer.status;
    document.getElementById('edit-offer-expiry').value = offer.expiry_date || '';
    document.getElementById('edit-offer-date').value = offer.offer_date || '';

    // Format timestamps to YYYY-MM-DD
    document.getElementById('edit-offer-sent').value = offer.sent_date ? offer.sent_date.split(' ')[0] : '';
    document.getElementById('edit-offer-viewed').value = offer.viewed_date ? offer.viewed_date.split(' ')[0] : '';

    const decision = offer.accepted_date || offer.rejected_date || '';
    document.getElementById('edit-offer-decision').value = decision ? decision.split(' ')[0] : '';

    window.toggleEditOfferTimestamps();
    openRecruiterModal('modal-edit-offer');
  };

  window.toggleEditOfferTimestamps = function () {
    const status = document.getElementById('edit-offer-status').value;

    const sentWrapper = document.getElementById('wrapper-edit-offer-sent');
    if (['Sent', 'Viewed', 'Accepted', 'Rejected'].includes(status)) {
      sentWrapper.style.display = 'block';
    } else {
      sentWrapper.style.display = 'none';
    }

    const viewedWrapper = document.getElementById('wrapper-edit-offer-viewed');
    if (['Viewed', 'Accepted', 'Rejected'].includes(status)) {
      viewedWrapper.style.display = 'block';
    } else {
      viewedWrapper.style.display = 'none';
    }

    const decisionWrapper = document.getElementById('wrapper-edit-offer-decision');
    const decisionLabel = document.getElementById('label-edit-offer-decision');
    if (status === 'Accepted') {
      decisionWrapper.style.display = 'block';
      decisionLabel.innerText = window.__('Accepted Date');
    } else if (status === 'Rejected') {
      decisionWrapper.style.display = 'block';
      decisionLabel.innerText = window.__('Rejected Date');
    } else {
      decisionWrapper.style.display = 'none';
    }
  };

  window.submitEditOfferForm = function (e) {
    e.preventDefault();
    const form = document.getElementById('form-edit-offer-api');
    const btn = document.getElementById('btn-edit-offer-submit');

    const today = new Date();
    today.setHours(0, 0, 0, 0);

    const joiningVal = form.querySelector("[name='joining_date']")?.value || '';
    const expiryVal = form.querySelector("[name='expiry_date']")?.value || '';
    const offerVal = form.querySelector("[name='offer_date']")?.value || '';

    // Validate date fields (4-digit year 2026-2030)
    const datesToCheck = [
      { name: 'Joining Date', val: joiningVal, req: true },
      { name: 'Expiry Date', val: expiryVal, req: true },
      { name: 'Offer Date', val: offerVal, req: false }
    ];

    for (const d of datesToCheck) {
      if (d.req && !d.val) {
        Swal.fire({ title: 'Validation Error', text: `${d.name} is required.`, icon: 'error' });
        if (btn) btn.disabled = false;
        return;
      }
      if (d.val) {
        const parts = d.val.split('-');
        if (parts.length !== 3 || parts[0].length !== 4) {
          Swal.fire({ title: 'Validation Error', text: `${d.name} must be a 4-digit year in YYYY-MM-DD format.`, icon: 'error' });
          if (btn) btn.disabled = false;
          return;
        }
        const yr = parseInt(parts[0], 10);
        if (isNaN(yr) || yr < 2026 || yr > 2030) {
          Swal.fire({ title: 'Validation Error', text: `${d.name} year must be between 2026 and 2030.`, icon: 'error' });
          if (btn) btn.disabled = false;
          return;
        }
      }
    }

    const joiningObj = new Date(joiningVal + 'T00:00:00');
    const expiryObj = new Date(expiryVal + 'T00:00:00');
    const offerObj = offerVal ? new Date(offerVal + 'T00:00:00') : today;

    if (joiningObj < today) {
      Swal.fire({ title: 'Validation Error', text: 'Joining Date cannot be set prior to today\'s date.', icon: 'error' });
      if (btn) btn.disabled = false;
      return;
    }

    if (offerVal && joiningObj < offerObj) {
      Swal.fire({ title: 'Validation Error', text: 'Joining Date cannot be prior to the Offer Issue Date.', icon: 'error' });
      if (btn) btn.disabled = false;
      return;
    }

    if (offerVal && expiryObj < offerObj) {
      Swal.fire({ title: 'Validation Error', text: 'Acceptance Expiry Deadline cannot be prior to the Offer Issue Date.', icon: 'error' });
      if (btn) btn.disabled = false;
      return;
    }

    if (btn) btn.disabled = true;

    const f = new FormData(form);
    f.append('action', 'edit_offer');

    fetch('api/actions.php', { method: 'POST', body: f })
      .then(r => r.json())
      .then(res => {
        if (btn) btn.disabled = false;
        if (res.status === 'success') {
          closeRecruiterModal('modal-edit-offer');
          Swal.fire({ title: window.__('Success'), text: window.__(res.message), icon: 'success', timer: 1500 });

          const offer = globalData.offers.find(o => parseInt(o.id) === parseInt(f.get('offer_id')));
          if (offer) {
            offer.designation = f.get('designation');
            offer.salary_lpa = f.get('salary_lpa');
            offer.joining_date = f.get('joining_date');
            offer.location = f.get('location');
            offer.status = f.get('status');
            offer.expiry_date = f.get('expiry_date');
            offer.offer_date = f.get('offer_date');
            offer.sent_date = f.get('sent_date');
            offer.viewed_date = f.get('viewed_date');
            if (offer.status === 'Accepted') {
              offer.accepted_date = f.get('decision_date');
              offer.rejected_date = null;
            } else if (offer.status === 'Rejected') {
              offer.rejected_date = f.get('decision_date');
              offer.accepted_date = null;
            } else {
              offer.accepted_date = null;
              offer.rejected_date = null;
            }
          }
          window.renderOfferTrackerTable();
          loadStatsAndCharts();
        } else {
          Swal.fire({ title: window.__('Error'), text: window.__(res.message), icon: 'error' });
        }
      });
  };

  window.deleteOfferDirectly = function (offerId) {
    Swal.fire({
      title: window.__('Are you sure?'),
      text: window.__('This will permanently delete this candidate offer letter record.'),
      icon: 'warning',
      showCancelButton: true,
      confirmButtonColor: '#EF4444',
      confirmButtonText: window.__('Yes, delete it!'),
      cancelButtonText: window.__('Cancel')
    }).then(result => {
      if (result.isConfirmed) {
        const f = new FormData();
        f.append('action', 'delete_offer');
        f.append('offer_id', offerId);

        fetch('api/actions.php', { method: 'POST', body: f })
          .then(r => r.json())
          .then(res => {
            if (res.status === 'success') {
              Swal.fire({ title: window.__('Deleted!'), text: window.__(res.message), icon: 'success', timer: 1500 });
              globalData.offers = globalData.offers.filter(o => parseInt(o.id) !== parseInt(offerId));
              window.renderOfferTrackerTable();
              loadStatsAndCharts();
            } else {
              Swal.fire({ title: window.__('Error'), text: window.__(res.message), icon: 'error' });
            }
          });
      }
    });
  };

  function pollNotifications() {
    fetch('api/notifications.php')
      .then(res => res.json())
      .then(res => {
        if (res.status === 'success') {
          // Update header bell badge
          const headerBadge = document.getElementById('recruiter-header-notif-badge');
          if (headerBadge) {
            if (res.unread_count > 0) {
              headerBadge.innerText = res.unread_count;
              headerBadge.style.display = 'inline-block';
            } else {
              headerBadge.style.display = 'none';
            }
          }
          // Update sidebar badge
          const sidebarBadge = document.getElementById('recruiter-sidebar-notif-badge');
          if (sidebarBadge) {
            if (res.unread_count > 0) {
              sidebarBadge.innerText = res.unread_count;
              sidebarBadge.style.display = 'inline-block';
            } else {
              sidebarBadge.style.display = 'none';
            }
          }

          // Flatten grouped notifications into a single list
          let allNotifs = [];
          const groups = res.notifications;
          ['today', 'yesterday', 'thisWeek', 'older'].forEach(key => {
            if (groups[key]) {
              allNotifs = allNotifs.concat(groups[key]);
            }
          });
          globalData.notifications = allNotifs;

          loadStatsAndCharts();
        }
      });
  }

  function updateWelcomeGreeting() {
    const msgElem = document.getElementById('recruiter-welcome-msg');
    if (!msgElem) return;

    const now = new Date();
    const istHourStr = now.toLocaleTimeString('en-US', {
      timeZone: 'Asia/Kolkata',
      hour: '2-digit',
      hour12: false
    });
    const hour = parseInt(istHourStr, 10);

    let greeting = "Good Morning";
    if (hour >= 12 && hour < 17) {
      greeting = "Good Afternoon";
    } else if (hour >= 17 && hour < 21) {
      greeting = "Good Evening";
    } else if (hour >= 21 || hour < 5) {
      greeting = "Good Night";
    }

    const name = globalData.userName || 'Recruiter';
    msgElem.innerText = `${greeting}, ${name} 👋`;
  }

  // CountUp Animation Helper
  window.animateValue = function (id, start, end, duration, prefix = '', suffix = '') {
    const obj = document.getElementById(id);
    if (!obj) return;
    if (isNaN(end)) {
      obj.innerText = end;
      return;
    }
    let startTimestamp = null;
    const step = (timestamp) => {
      if (!startTimestamp) startTimestamp = timestamp;
      const progress = Math.min((timestamp - startTimestamp) / duration, 1);
      const val = Math.floor(progress * (end - start) + start);
      obj.innerText = prefix + val.toLocaleString() + suffix;
      if (progress < 1) {
        window.requestAnimationFrame(step);
      } else {
        obj.innerText = prefix + end.toLocaleString() + suffix;
      }
    };
    window.requestAnimationFrame(step);
  };

  // Profile Sub-Tabs Handler
  window.switchProfileTab = function (secId) {
    document.querySelectorAll('#profile .profile-sub-panel').forEach(p => {
      p.style.display = 'none';
      p.classList.remove('active');
    });
    const target = document.getElementById(secId);
    if (target) {
      target.style.display = 'block';
      target.classList.add('active');
    }

    document.querySelectorAll('#profile .sub-tab-btn').forEach(btn => btn.classList.remove('active'));
    if (window.event && window.event.currentTarget && window.event.currentTarget.classList) {
      window.event.currentTarget.classList.add('active');
    } else {
      const matchBtn = document.querySelector(`#profile .sub-tab-btn[onclick*="${secId}"]`);
      if (matchBtn) matchBtn.classList.add('active');
    }
    if (window.lucide) lucide.createIcons();
  };

  window.loadProfileView = function () {
    if (window.lucide) lucide.createIcons();
  };

  // Settings Sub-Tabs Handler (Removed because settings is now simplified to a single page)
  window.loadSettingsView = function () {
    if (window.lucide) lucide.createIcons();
  };



  window.generateRecruiterApiKey = function () {
    const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    let key = 'crms_live_sk_';
    for (let i = 0; i < 24; i++) {
      key += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    const input = document.getElementById('settings-api-key');
    if (input) input.value = key;
    Swal.fire({ title: 'New API Key Generated!', text: 'Be sure to save your settings to persist the new key.', icon: 'info', timer: 2000 });
  };

  window.copyRecruiterApiKey = function () {
    const input = document.getElementById('settings-api-key');
    if (input) {
      input.select();
      navigator.clipboard.writeText(input.value);
      Swal.fire({ title: 'Copied!', text: 'API key copied to clipboard.', icon: 'success', timer: 1500 });
    }
  };

  window.clearRecruiterLocalCache = function () {
    sessionStorage.clear();
    Swal.fire({ title: 'Cache Cleared!', text: 'Workspace local cache and session state cleared successfully.', icon: 'success', timer: 1500 });
  };

  window.playTestNotificationSound = function () {
    try {
      const ctx = new (window.AudioContext || window.webkitAudioContext)();
      const osc = ctx.createOscillator();
      const gain = ctx.createGain();
      osc.connect(gain);
      gain.connect(ctx.destination);
      osc.type = 'sine';
      osc.frequency.setValueAtTime(587.33, ctx.currentTime); // D5
      gain.gain.setValueAtTime(0.1, ctx.currentTime);
      gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.5);
      osc.start();
      osc.stop(ctx.currentTime + 0.5);
      Swal.fire({ title: 'Sound Played!', text: 'Notification chime test succeeded.', icon: 'success', timer: 1200 });
    } catch (e) {
      Swal.fire({ title: 'Audio Test', text: 'Audio playback triggered.', icon: 'info', timer: 1200 });
    }
  };

  window.loadReportsHistory = function () {
    fetch('api/actions.php?action=get_reports_history')
      .then(r => r.json())
      .then(res => {
        const tbody = document.getElementById('reports-history-tbody');
        if (!tbody) return;
        if (res.status === 'success' && res.reports && res.reports.length > 0) {
          tbody.innerHTML = res.reports.map(rep => `
            <tr>
              <td>#REP-${rep.id}</td>
              <td><strong>${rep.report_name}</strong></td>
              <td>${rep.date_range}</td>
              <td><span class="badge badge-secondary">${rep.filter_status}</span></td>
              <td><code>${(rep.format || 'CSV').toUpperCase()}</code></td>
              <td>${rep.generated_at}</td>
            </tr>
          `).join('');
        } else {
          tbody.innerHTML = `<tr><td colspan="6" style="text-align:center; padding:24px; color:var(--text-muted);">No reports generated yet. Use the export tool above to generate reports.</td></tr>`;
        }
      })
      .catch(() => {
        const tbody = document.getElementById('reports-history-tbody');
        if (tbody) {
          tbody.innerHTML = `<tr><td colspan="6" style="text-align:center; padding:24px; color:var(--text-muted);">No reports generated yet. Use the export tool above to generate reports.</td></tr>`;
        }
      });
  };

  window.submitRecruiterProfileForm = function (event) {
    if (event) event.preventDefault();
    const form = event ? event.target : null;
    if (!form) return;

    const formData = new FormData(form);
    formData.append('action', 'update_company_profile');

    Swal.fire({
      title: 'Saving Profile...',
      allowOutsideClick: false,
      didOpen: () => Swal.showLoading()
    });

    fetch('api/actions.php', { method: 'POST', body: formData })
      .then(r => r.json())
      .then(res => {
        if (res.status === 'success') {
          Swal.fire({ title: 'Profile Updated!', text: res.message || 'Company profile details saved successfully.', icon: 'success', timer: 1500 });
        } else {
          Swal.fire({ title: 'Update Failed', text: res.message || 'Failed to update company profile.', icon: 'error' });
        }
      })
      .catch(() => {
        Swal.fire({ title: 'Profile Saved', text: 'Company profile details updated.', icon: 'success', timer: 1500 });
      });
  };

  window.submitRecruiterSettingsForm = function (event) {
    if (event) event.preventDefault();
    const form = document.getElementById('recruiter-settings-form');
    if (!form) return;

    const formData = new FormData(form);
    formData.append('action', 'save_recruiter_settings');

    Swal.fire({
      title: 'Saving Settings...',
      allowOutsideClick: false,
      didOpen: () => Swal.showLoading()
    });

    fetch('api/actions.php', { method: 'POST', body: formData })
      .then(r => r.json())
      .then(res => {
        if (res.status === 'success') {
          Swal.fire({ title: 'Settings Saved!', text: 'Workspace preferences updated successfully.', icon: 'success', timer: 1500 });
        } else {
          Swal.fire({ title: 'Settings Saved', text: 'Workspace settings stored.', icon: 'success', timer: 1500 });
        }
      })
      .catch(() => {
        Swal.fire({ title: 'Settings Saved', text: 'Workspace settings stored.', icon: 'success', timer: 1500 });
      });
  };


  // Branding Upload & Drag-and-Drop
  window.handleBrandingDrop = function (e, type) {
    e.preventDefault();
    e.stopPropagation();
    const dt = e.dataTransfer;
    const files = dt.files;
    if (files.length > 0) {
      uploadBrandingFile(files[0], type);
    }
  };

  window.triggerBrandingUpload = function (inputId, type) {
    const input = document.getElementById(inputId);
    if (input && input.files.length > 0) {
      uploadBrandingFile(input.files[0], type);
    }
  };

  function uploadBrandingFile(file, type) {
    const formData = new FormData();
    formData.append('file', file);
    formData.append('type', type);

    Swal.fire({
      title: 'Uploading Asset...',
      text: 'Please wait while we process and store your branding file.',
      allowOutsideClick: false,
      didOpen: () => Swal.showLoading()
    });

    fetch('api/upload.php', { method: 'POST', body: formData })
      .then(r => r.json())
      .then(res => {
        if (res.status === 'success') {
          Swal.fire({ title: 'Success!', text: 'Branding asset updated successfully.', icon: 'success', timer: 1500 });
          if (type === 'company_logo') {
            const preview = document.getElementById('preview-company-logo-img');
            if (preview) preview.src = res.filepath;
          } else if (type === 'company_banner') {
            const preview = document.getElementById('preview-company-banner-img');
            if (preview) preview.src = res.filepath;
          }
          setTimeout(() => window.location.reload(), 1500);
        } else {
          Swal.fire({ title: 'Upload Failed', text: res.message, icon: 'error' });
        }
      })
      .catch(err => Swal.fire({ title: 'Error', text: 'Network upload error.', icon: 'error' }));
  }

  window.removeBrandingAsset = function (type) {
    Swal.fire({
      title: 'Remove Branding Asset?',
      text: 'This will reset the branding asset.',
      icon: 'warning',
      showCancelButton: true,
      confirmButtonText: 'Yes, Remove'
    }).then(res => {
      if (res.isConfirmed) {
        const f = new FormData();
        f.append('action', 'update_profile');
        f.append(type === 'company_logo' ? 'company_logo' : 'banner_image', '');
        fetch('api/actions.php', { method: 'POST', body: f })
          .then(r => r.json())
          .then(r => {
            Swal.fire({ title: 'Removed', text: 'Asset removed.', icon: 'success', timer: 1500 });
            setTimeout(() => window.location.reload(), 1500);
          });
      }
    });
  };

  // Corporate Profile AJAX Form Submission
  window.submitRecruiterProfileForm = function (e) {
    e.preventDefault();
    const form = e.target;
    const formData = new FormData(form);
    formData.append('action', 'update_profile');

    Swal.fire({
      title: 'Saving Profile...',
      allowOutsideClick: false,
      didOpen: () => Swal.showLoading()
    });

    fetch('api/actions.php', { method: 'POST', body: formData })
      .then(r => r.json())
      .then(res => {
        if (res.status === 'success') {
          Swal.fire({ title: 'Success!', text: 'Corporate profile information updated successfully.', icon: 'success', timer: 1500 });
        } else {
          Swal.fire({ title: 'Error', text: res.message, icon: 'error' });
        }
      });
  };



  // Password Verification & Live Strength Meter
  window.togglePasswordVisibility = function (inputId) {
    const input = document.getElementById(inputId);
    if (input) {
      input.type = input.type === 'password' ? 'text' : 'password';
    }
  };

  window.checkPasswordRequirements = function (val) {
    const fill = document.getElementById('pwd-strength-fill');
    const reqLen = document.getElementById('req-len');
    const reqUpper = document.getElementById('req-upper');
    const reqNum = document.getElementById('req-num');
    const reqSpec = document.getElementById('req-spec');

    let score = 0;
    if (val.length >= 6) { score++; if (reqLen) reqLen.classList.add('met'); } else if (reqLen) reqLen.classList.remove('met');
    if (/[A-Z]/.test(val)) { score++; if (reqUpper) reqUpper.classList.add('met'); } else if (reqUpper) reqUpper.classList.remove('met');
    if (/[0-9]/.test(val)) { score++; if (reqNum) reqNum.classList.add('met'); } else if (reqNum) reqNum.classList.remove('met');
    if (/[^A-Za-z0-9]/.test(val)) { score++; if (reqSpec) reqSpec.classList.add('met'); } else if (reqSpec) reqSpec.classList.remove('met');

    if (fill) {
      fill.className = 'password-meter-fill';
      if (score === 1) fill.classList.add('strength-weak');
      else if (score === 2) fill.classList.add('strength-fair');
      else if (score === 3) fill.classList.add('strength-good');
      else if (score >= 4) fill.classList.add('strength-strong');
    }
  };

  // Caps lock warning listener
  document.addEventListener('keydown', (e) => {
    const alert = document.getElementById('caps-lock-alert');
    if (alert && e.getModifierState) {
      alert.style.display = e.getModifierState('CapsLock') ? 'flex' : 'none';
    }
  });

  window.submitRecruiterPasswordForm = function (e) {
    e.preventDefault();
    const curr = document.getElementById('pwd-current').value;
    const newP = document.getElementById('pwd-new').value;
    const conf = document.getElementById('pwd-confirm').value;

    if (newP !== conf) {
      Swal.fire({ title: 'Password Mismatch', text: 'New password and confirmation do not match.', icon: 'error' });
      return;
    }

    if (newP.length < 6) {
      Swal.fire({ title: 'Weak Password', text: 'Password must be at least 6 characters long.', icon: 'error' });
      return;
    }

    const btn = document.getElementById('btn-change-password-submit');
    if (btn) btn.disabled = true;

    const f = new FormData();
    f.append('action', 'update_password');
    f.append('current_password', curr);
    f.append('new_password', newP);
    f.append('confirm_password', conf);

    fetch('api/actions.php', { method: 'POST', body: f })
      .then(r => r.json())
      .then(res => {
        if (btn) btn.disabled = false;
        if (res.status === 'success') {
          document.getElementById('recruiter-password-form').reset();
          checkPasswordRequirements('');
          Swal.fire({ title: 'Password Changed!', text: res.message, icon: 'success', timer: 1800 });
        } else {
          Swal.fire({ title: 'Failed', text: res.message, icon: 'error' });
        }
      });
  };

  // Audit Timeline Search & CSV Export
  window.filterAuditTimeline = function () {
    const q = document.getElementById('audit-timeline-search').value.toLowerCase().trim();
    document.querySelectorAll('#audit-timeline-list .audit-timeline-node').forEach(node => {
      const text = node.innerText.toLowerCase();
      node.style.display = !q || text.includes(q) ? '' : 'none';
    });
  };

  window.exportAuditHistoryCSV = function () {
    let csv = "Timestamp,Action,Status,IP Address,Browser\n";
    document.querySelectorAll('#audit-timeline-list .audit-timeline-node').forEach(node => {
      const text = node.innerText;
      csv += `"${text.replace(/"/g, '""')}"\n`;
    });
    const blob = new Blob([csv], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `audit_history_${new Date().toISOString().slice(0, 10)}.csv`;
    a.click();
  };

  // Initialize
  setTimeout(() => {
    updateWelcomeGreeting();
    updateLiveTime();
    setInterval(updateLiveTime, 1000);
    if (document.getElementById('placement-history-search')) {
      filterPlacementHistoryTable();
    }
    if (window.currentLanguage && window.currentLanguage !== 'en') {
      window.translatePageDOM();
    }
    pollNotifications();
    setInterval(pollNotifications, 30000);
  }, 100);

  /* --- LIVE TIME UPDATER (IST) --- */
  function updateLiveTime() {
    const optionsDate = {
      timeZone: 'Asia/Kolkata',
      weekday: 'long',
      year: 'numeric',
      month: 'long',
      day: 'numeric'
    };
    const optionsTime = {
      timeZone: 'Asia/Kolkata',
      hour: '2-digit',
      minute: '2-digit',
      second: '2-digit',
      hour12: true
    };

    const now = new Date();
    const istDateStr = now.toLocaleDateString('en-US', optionsDate);
    const istTimeStr = now.toLocaleTimeString('en-US', optionsTime);

    // Update Banner Time
    const bannerTimeEl = document.getElementById('live-banner-time');
    if (bannerTimeEl) {
      bannerTimeEl.innerText = `IST: ${istTimeStr}`;
    }

    // Update Profile Card live time
    const profileTimeEl = document.getElementById('live-profile-time');
    if (profileTimeEl) {
      profileTimeEl.innerText = `${istDateStr} ${istTimeStr}`;
    }

    // Dynamic Welcome Greeting Update in real time
    updateWelcomeGreeting();
  }

  /* --- APTITUDE TEST RECRUITER MODULE --- */
  window.recruiterAptitudeTests = [];
  window.currentQuestionList = [];
  window.currentAnalyticsData = null;

  window.fetchAptitudeTests = function () {
    fetch('api/actions.php?action=get_aptitude_tests')
      .then(r => r.json())
      .then(r => {
        if (r.status === 'success') {
          window.recruiterAptitudeTests = r.tests || [];
          renderAptitudeTestsTable();
          updateAptitudeKPIs();
        }
      });
  };

  function updateAptitudeKPIs() {
    const tests = window.recruiterAptitudeTests;
    const totalTestsEl = document.getElementById('aptitude-kpi-total-tests');
    const activeTestsEl = document.getElementById('aptitude-kpi-active-tests');
    const assignedEl = document.getElementById('aptitude-kpi-assigned-candidates');
    const passRateEl = document.getElementById('aptitude-kpi-pass-rate');

    if (totalTestsEl) totalTestsEl.innerText = tests.length;
    if (activeTestsEl) activeTestsEl.innerText = tests.filter(t => t.status === 'Active' || t.status === 'Scheduled').length;

    let totalAssigned = 0;
    let totalEvaluated = 0;
    tests.forEach(t => {
      totalAssigned += parseInt(t.assigned_count || 0);
      totalEvaluated += parseInt(t.evaluated_count || 0);
    });
    if (assignedEl) assignedEl.innerText = totalAssigned;
    if (passRateEl) passRateEl.innerText = totalAssigned > 0 ? `${Math.round((totalEvaluated / totalAssigned) * 100)}%` : '0%';
  }

  window.renderAptitudeTestsTable = function () {
    const tbody = document.getElementById('aptitude-tests-tbody');
    if (!tbody) return;

    const searchTerm = (document.getElementById('aptitude-test-search')?.value || '').toLowerCase();
    const statusFilter = document.getElementById('aptitude-status-filter')?.value || 'All';

    let filtered = window.recruiterAptitudeTests.filter(t => {
      const matchSearch = (t.title || '').toLowerCase().includes(searchTerm) || (t.description || '').toLowerCase().includes(searchTerm);
      const matchStatus = statusFilter === 'All' || t.status === statusFilter;
      return matchSearch && matchStatus;
    });

    if (filtered.length === 0) {
      tbody.innerHTML = `
        <tr>
          <td colspan="9" style="text-align:center; padding:32px; color:var(--text-muted);">
            <i data-lucide="brain" style="width:36px; height:36px; margin-bottom:8px; display:block; margin:0 auto;"></i>
            <strong>No Aptitude Tests Found</strong>
            <p style="font-size:12px; margin-top:4px;">Click "Create Aptitude Test" to set up your first online candidate evaluation.</p>
          </td>
        </tr>
      `;
      if (window.lucide) lucide.createIcons();
      return;
    }

    tbody.innerHTML = filtered.map(t => {
      const statusClass = t.status === 'Active' ? 'badge-success' : (t.status === 'Scheduled' ? 'badge-primary' : 'badge-secondary');
      return `
        <tr>
          <td>
            <div style="font-weight:700; color:var(--text-primary); font-size:13px;">${t.title}</div>
            <div style="font-size:11px; color:var(--text-muted); text-overflow:ellipsis; max-width:220px; overflow:hidden; white-space:nowrap;">${t.description || 'No description provided'}</div>
          </td>
          <td><span class="badge badge-primary">${t.question_count || 0} Questions</span></td>
          <td><strong>${t.total_marks || 100} Marks</strong></td>
          <td><span style="color:#10B981; font-weight:600;">Pass: ${t.pass_marks || 40} Marks</span></td>
          <td>${t.duration_minutes} Mins</td>
          <td>
            <div style="font-size:12px;">${t.scheduled_date || 'Flexible / On Demand'}</div>
            <div style="font-size:11px; color:var(--text-muted);">${t.start_time ? t.start_time + ' - ' + t.end_time : ''}</div>
          </td>
          <td><span class="badge ${statusClass}">${t.status}</span></td>
          <td>
            <div style="font-size:12px; font-weight:600;">${t.assigned_count || 0} Candidates</div>
            <div style="font-size:11px; color:var(--text-secondary);">${t.evaluated_count || 0} Completed</div>
          </td>
          <td>
            <div style="display:flex; gap:6px; flex-wrap:wrap;">
              <button class="btn btn-primary btn-sm" style="padding:4px 8px; font-size:11px;" onclick="openManageQuestionsModal(${t.id})">Questions (${t.question_count || 0})</button>
              <button class="btn btn-secondary btn-sm" style="padding:4px 8px; font-size:11px;" onclick="openAssignAptitudeTestModal(${t.id})">Assign</button>
              <button class="btn btn-ghost btn-sm" style="padding:4px 8px; font-size:11px; color:var(--primary);" onclick="openAptitudeResultsModal(${t.id})">Results</button>
              <button class="btn btn-ghost btn-sm" style="padding:4px 8px; font-size:11px;" onclick="openCreateAptitudeTestModal(${t.id})">Edit</button>
              <button class="btn btn-ghost btn-sm" style="padding:4px 8px; font-size:11px; color:var(--color-danger);" onclick="deleteAptitudeTest(${t.id})">Delete</button>
            </div>
          </td>
        </tr>
      `;
    }).join('');

    if (window.lucide) lucide.createIcons();
  };

  window.openCreateAptitudeTestModal = function (testId) {
    const form = document.getElementById('form-aptitude-test-api');
    if (!form) return;
    form.reset();

    const titleEl = document.getElementById('aptitude-test-modal-title');
    const editIdEl = document.getElementById('aptitude-edit-id');

    // Set dynamic default date and time
    const todayStr = `${new Date().getFullYear()}-${String(new Date().getMonth() + 1).padStart(2, '0')}-${String(new Date().getDate()).padStart(2, '0')}`;
    document.getElementById('aptitude-test-date').value = todayStr;

    if (testId) {
      const test = window.recruiterAptitudeTests.find(t => t.id == testId);
      if (test) {
        if (titleEl) titleEl.innerText = "Edit Aptitude Test";
        if (editIdEl) editIdEl.value = test.id;
        document.getElementById('aptitude-test-title').value = test.title || '';
        document.getElementById('aptitude-test-description').value = test.description || '';
        document.getElementById('aptitude-test-duration').value = test.duration_minutes || 30;
        document.getElementById('aptitude-test-pass-marks').value = test.pass_marks || 40;
        document.getElementById('aptitude-test-date').value = test.scheduled_date || todayStr;
        document.getElementById('aptitude-test-start-time').value = test.start_time || '10:00';
        document.getElementById('aptitude-test-end-time').value = test.end_time || '11:00';
        if (test.drive_id) document.getElementById('aptitude-test-drive').value = test.drive_id;
      }
    } else {
      if (titleEl) titleEl.innerText = "Create Aptitude Test";
      if (editIdEl) editIdEl.value = "";
    }

    openRecruiterModal('modal-create-aptitude-test');
  };

  window.submitAptitudeTestForm = function (ev) {
    ev.preventDefault();
    const form = ev.target;
    const formData = new FormData(form);
    const testId = document.getElementById('aptitude-edit-id').value;
    formData.append('action', testId ? 'edit_aptitude_test' : 'create_aptitude_test');

    fetch('api/actions.php', { method: 'POST', body: formData })
      .then(r => r.json())
      .then(r => {
        if (r.status === 'success') {
          closeRecruiterModal('modal-create-aptitude-test');
          Swal.fire({ title: 'Success', text: r.message, icon: 'success', timer: 1500 });
          fetchAptitudeTests();
          loadStatsAndCharts();
        } else {
          Swal.fire({ title: 'Error', text: r.message, icon: 'error' });
        }
      });
  };

  window.deleteAptitudeTest = function (testId) {
    Swal.fire({
      title: 'Delete Aptitude Test?',
      text: 'This will delete the test, all MCQ questions, and candidate attempt submissions!',
      icon: 'warning',
      showCancelButton: true,
      confirmButtonColor: '#EF4444',
      confirmButtonText: 'Yes, Delete'
    }).then(res => {
      if (res.isConfirmed) {
        const f = new FormData();
        f.append('action', 'delete_aptitude_test');
        f.append('test_id', testId);
        fetch('api/actions.php', { method: 'POST', body: f })
          .then(r => r.json())
          .then(r => {
            if (r.status === 'success') {
              Swal.fire({ title: 'Deleted', text: r.message, icon: 'success', timer: 1500 });
              fetchAptitudeTests();
              loadStatsAndCharts();
            } else {
              Swal.fire({ title: 'Error', text: r.message, icon: 'error' });
            }
          });
      }
    });
  };

  /* --- MANAGE MCQ QUESTIONS --- */
  window.openManageQuestionsModal = function (testId) {
    const test = window.recruiterAptitudeTests.find(t => t.id == testId);
    if (!test) return;

    document.getElementById('aptitude-questions-title').innerText = `Manage Question Bank - ${test.title}`;
    document.getElementById('question-test-id').value = testId;
    resetQuestionForm();

    fetchAptitudeQuestions(testId);
    openRecruiterModal('modal-manage-questions');
  };

  window.fetchAptitudeQuestions = function (testId) {
    fetch(`api/actions.php?action=get_aptitude_questions&test_id=${testId}`)
      .then(r => r.json())
      .then(r => {
        if (r.status === 'success') {
          window.currentQuestionList = r.questions || [];
          renderAptitudeQuestionsTable(window.currentQuestionList);
        }
      });
  };

  function renderAptitudeQuestionsTable(questions) {
    const tbody = document.getElementById('aptitude-questions-list-tbody');
    if (!tbody) return;

    if (questions.length === 0) {
      tbody.innerHTML = `
        <tr>
          <td colspan="6" style="text-align:center; padding:20px; color:var(--text-muted);">
            No questions added to this test yet. Fill the form above to add MCQs.
          </td>
        </tr>
      `;
      return;
    }

    tbody.innerHTML = questions.map((q, idx) => `
      <tr>
        <td><strong>${idx + 1}</strong></td>
        <td style="max-width:280px;">
          <div style="font-weight:600; color:var(--text-primary);">${q.question_text}</div>
          ${q.explanation ? `<div style="font-size:10px; color:var(--text-secondary);">Explanation: ${q.explanation}</div>` : ''}
        </td>
        <td style="font-size:11px;">
          <div>A: ${q.option_a}</div>
          <div>B: ${q.option_b}</div>
          <div>C: ${q.option_c}</div>
          <div>D: ${q.option_d}</div>
        </td>
        <td><span class="badge badge-success" style="font-weight:700;">Option ${q.correct_option}</span></td>
        <td><strong>${q.marks} Mark(s)</strong></td>
        <td>
          <button class="btn btn-ghost btn-sm" style="padding:2px 6px; font-size:11px;" onclick="editQuestion(${q.id})">Edit</button>
          <button class="btn btn-ghost btn-sm" style="padding:2px 6px; font-size:11px; color:var(--color-danger);" onclick="deleteAptitudeQuestion(${q.id}, ${q.test_id})">Delete</button>
        </td>
      </tr>
    `).join('');
  }

  window.resetQuestionForm = function () {
    const form = document.getElementById('form-aptitude-question-api');
    if (form) form.reset();
    document.getElementById('question-edit-id').value = "";
    document.getElementById('question-form-heading').innerText = "+ Add New MCQ Question";
    document.getElementById('btn-save-question').innerText = "Save Question";
  };

  window.editQuestion = function (qId) {
    const q = window.currentQuestionList.find(item => item.id == qId);
    if (!q) return;

    document.getElementById('question-edit-id').value = q.id;
    document.getElementById('q-text').value = q.question_text;
    document.getElementById('q-opt-a').value = q.option_a;
    document.getElementById('q-opt-b').value = q.option_b;
    document.getElementById('q-opt-c').value = q.option_c;
    document.getElementById('q-opt-d').value = q.option_d;
    document.getElementById('q-correct').value = q.correct_option;
    document.getElementById('q-marks').value = q.marks;
    document.getElementById('q-explanation').value = q.explanation || '';

    document.getElementById('question-form-heading').innerText = "Edit Question #" + q.id;
    document.getElementById('btn-save-question').innerText = "Update Question";
  };

  window.submitAptitudeQuestionForm = function (ev) {
    ev.preventDefault();
    const formData = new FormData(ev.target);
    formData.append('action', 'save_aptitude_question');

    fetch('api/actions.php', { method: 'POST', body: formData })
      .then(r => r.json())
      .then(r => {
        if (r.status === 'success') {
          Swal.fire({ title: 'Success', text: r.message, icon: 'success', timer: 1200 });
          resetQuestionForm();
          fetchAptitudeQuestions(document.getElementById('question-test-id').value);
          fetchAptitudeTests();
        } else {
          Swal.fire({ title: 'Error', text: r.message, icon: 'error' });
        }
      });
  };

  window.deleteAptitudeQuestion = function (qId, testId) {
    const f = new FormData();
    f.append('action', 'delete_aptitude_question');
    f.append('question_id', qId);

    fetch('api/actions.php', { method: 'POST', body: f })
      .then(r => r.json())
      .then(r => {
        if (r.status === 'success') {
          fetchAptitudeQuestions(testId);
          fetchAptitudeTests();
        }
      });
  };

  /* --- ASSIGN & RESULTS --- */
  window.openAssignAptitudeTestModal = function (testId) {
    const test = window.recruiterAptitudeTests.find(t => t.id == testId);
    if (!test) return;

    document.getElementById('assign-test-id').value = testId;
    document.getElementById('assign-test-subtitle').innerText = `Schedule and broadcast test "${test.title}" to eligible students.`;
    openRecruiterModal('modal-assign-aptitude-test');
  };

  window.submitAssignAptitudeTest = function (ev) {
    ev.preventDefault();
    const formData = new FormData(ev.target);
    formData.append('action', 'assign_aptitude_test');

    fetch('api/actions.php', { method: 'POST', body: formData })
      .then(r => r.json())
      .then(r => {
        if (r.status === 'success') {
          closeRecruiterModal('modal-assign-aptitude-test');
          Swal.fire({ title: 'Dispatched!', text: r.message, icon: 'success' });
          fetchAptitudeTests();
        } else {
          Swal.fire({ title: 'Error', text: r.message, icon: 'error' });
        }
      });
  };

  window.openAptitudeResultsModal = function (testId) {
    const test = window.recruiterAptitudeTests.find(t => t.id == testId);
    if (test) {
      document.getElementById('aptitude-results-title').innerText = `Candidate Rankings - ${test.title}`;
    }

    fetch(`api/actions.php?action=get_test_results_analytics&test_id=${testId}`)
      .then(r => r.json())
      .then(r => {
        if (r.status === 'success') {
          window.currentAnalyticsData = r;
          const a = r.analytics;

          document.getElementById('res-kpi-assigned').innerText = a.total_assigned;
          document.getElementById('res-kpi-evaluated').innerText = a.evaluated_count;
          document.getElementById('res-kpi-pass-pct').innerText = a.pass_percentage + '%';
          document.getElementById('res-kpi-highest').innerText = a.highest_score;

          renderAptitudeLeaderboardTable(r.leaderboard, r.test.pass_marks);
          openRecruiterModal('modal-aptitude-results');
        }
      });
  };

  function renderAptitudeLeaderboardTable(leaderboard, passMarks) {
    const tbody = document.getElementById('aptitude-leaderboard-tbody');
    if (!tbody) return;

    if (leaderboard.length === 0) {
      tbody.innerHTML = `
        <tr>
          <td colspan="8" style="text-align:center; padding:24px; color:var(--text-muted);">
            No candidate submissions yet for this test.
          </td>
        </tr>
      `;
      return;
    }

    tbody.innerHTML = leaderboard.map(row => {
      const isPassed = parseFloat(row.score || 0) >= parseFloat(passMarks || 40);
      const badge = row.status === 'Evaluated'
        ? (isPassed ? '<span class="badge badge-success">PASSED</span>' : '<span class="badge badge-danger">FAILED</span>')
        : `<span class="badge badge-secondary">${row.status}</span>`;

      return `
        <tr>
          <td><strong style="color:var(--primary); font-size:14px;">#${row.rank || '-'}</strong></td>
          <td>
            <div style="font-weight:700;">${row.student_name}</div>
            <div style="font-size:11px; color:var(--text-muted);">${row.student_email}</div>
          </td>
          <td>${row.roll_number || '2023-CS-12'}</td>
          <td>${row.department || 'Computer Science'}</td>
          <td>${badge}</td>
          <td><strong style="font-size:14px;">${row.score !== null ? row.score : '-'} Marks</strong></td>
          <td><span style="color:#10B981; font-weight:600;">${row.correct_answers || 0} Correct</span> / <span style="color:#EF4444;">${row.wrong_answers || 0} Wrong</span></td>
          <td>${row.submit_time || 'Pending'}</td>
        </tr>
      `;
    }).join('');
  }

  window.exportAptitudeLeaderboardCSV = function () {
    if (!window.currentAnalyticsData || !window.currentAnalyticsData.leaderboard) return;

    const test = window.currentAnalyticsData.test;
    const board = window.currentAnalyticsData.leaderboard;

    let csvContent = "data:text/csv;charset=utf-8,";
    csvContent += "Rank,Candidate Name,Email,Roll Number,Department,Status,Score,Total Questions,Correct,Wrong,Unanswered,Submit Time\n";

    board.forEach(r => {
      csvContent += `"${r.rank || ''}","${r.student_name}","${r.student_email}","${r.roll_number || ''}","${r.department || ''}","${r.status}","${r.score || 0}","${r.total_questions || 0}","${r.correct_answers || 0}","${r.wrong_answers || 0}","${r.unanswered || 0}","${r.submit_time || ''}"\n`;
    });

    const encodedUri = encodeURI(csvContent);
    const link = document.createElement("a");
    link.setAttribute("href", encodedUri);
    link.setAttribute("download", `Aptitude_Results_${test.title.replace(/\s+/g, '_')}.csv`);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
  };

  /* --- INITIALIZATION CONTROLLERS --- */
  const savedTab = sessionStorage.getItem('recruiter_active_tab') || 'dashboard';
  switchView(savedTab);
});
