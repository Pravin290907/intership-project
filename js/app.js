/**
 * Campus Recruitment Dashboard - Core App Logic
 * Coordinates client-side routing, real-time alerts, Chart.js datasets, and table actions.
 */

document.addEventListener("DOMContentLoaded", () => {
  const data = window.campusRecruitmentData;
  const langData = data.translations || {};
  window.__ = function(text) {
    return langData[text] || text;
  };

  let studentTableInstance = null;
  let companyTableInstance = null;
  let driveTableInstance = null;
  let applicationTableInstance = null;
  let kanbanInstance = null;
  let calendarInstance = null;
  let charts = {};

  // Initialization calls moved to the bottom of the file to prevent temporal dead zone ReferenceErrors with const variables



  /* --- ROUTER & SIDEBAR CONTROLLER --- */
  function initSidebar() {
    const sidebar = document.getElementById("app-sidebar");
    const toggleBtn = document.getElementById("sidebar-toggle");
    
    toggleBtn.addEventListener("click", () => {
      sidebar.classList.toggle("collapsed");
    });

    const navItems = document.querySelectorAll("[data-target]");
    navItems.forEach(item => {
      item.addEventListener("click", () => {
        const targetId = item.getAttribute("data-target");
        navItems.forEach(n => n.classList.remove("active"));
        item.classList.add("active");
        switchView(targetId);
      });
    });
  }

  function switchView(viewId) {
    const pages = document.querySelectorAll(".page-view");
    const targetPage = document.getElementById(viewId);

    if (targetPage) {
      pages.forEach(p => p.classList.remove("active"));
      targetPage.classList.add("active");
      
      updateBreadcrumbs(viewId);
      document.querySelector(".content-area").scrollTop = 0;

      // Persist active view
      sessionStorage.setItem('activeView', viewId);

      // Synchronize sidebar navigation active state
      const navItems = document.querySelectorAll("[data-target]");
      navItems.forEach(n => {
        if (n.getAttribute("data-target") === viewId) {
          n.classList.add("active");
        } else {
          n.classList.remove("active");
        }
      });

      // Lazy render components
      if (viewId === "students") {
        renderStudentsTable();
      } else if (viewId === "companies") {
        renderCompaniesTable();
      } else if (viewId === "drives") {
        renderDrivesTable();
      } else if (viewId === "applications") {
        renderApplicationsTable();
      } else if (viewId === "pipeline") {
        renderKanban();
      } else if (viewId === "interviews") {
        renderCalendar();
      } else if (viewId === "dashboard") {
        refreshCharts();
      } else if (viewId === "notifications") {
        loadNotificationsPage();
      }
    }
  }
  window.switchView = switchView;

  function updateBreadcrumbs(viewId) {
    const activeItem = document.querySelector(`.nav-item[data-target="${viewId}"]`);
    const label = activeItem ? activeItem.querySelector(".nav-label").innerText : "Home";
    const crumbText = document.getElementById("crumb-current");
    if (crumbText) crumbText.innerText = label;
  }

  function handleResponsiveLayout() {
    const sidebar = document.getElementById("app-sidebar");
    if (window.innerWidth <= 1024) {
      sidebar.classList.add("collapsed");
    } else {
      sidebar.classList.remove("collapsed");
    }
  }

  /* --- DROPDOWNS --- */
  function initDropdowns() {
    const trigger = document.getElementById("avatar-menu-trigger");
    const menu = document.getElementById("avatar-menu");
    
    if (trigger && menu) {
      trigger.addEventListener("click", (e) => {
        e.stopPropagation();
        menu.classList.toggle("active");
      });
      document.addEventListener("click", () => {
        menu.classList.remove("active");
      });
    }
  }

  /* --- NOTIFICATIONS BELL DRAWER --- */
  function initNotificationDrawer() {
    const trigger = document.getElementById("notify-drawer-trigger");
    const drawer = document.getElementById("notify-drawer");
    const closeBtn = document.getElementById("drawer-close-btn");

    if (trigger && drawer && closeBtn) {
      trigger.addEventListener("click", (e) => {
        e.stopPropagation();
        drawer.classList.add("active");
      });
      closeBtn.addEventListener("click", () => {
        drawer.classList.remove("active");
      });
      document.addEventListener("click", (e) => {
        if (drawer.classList.contains("active") && !e.target.closest("#notify-drawer")) {
          drawer.classList.remove("active");
        }
      });
    }

    pollNotifications();
    setInterval(pollNotifications, 30000); // Poll every 30s
  }

  function pollNotifications() {
    fetch('api/notifications.php')
      .then(res => res.json())
      .then(res => {
        if (res.status === 'success') {
          updateUnreadBadge(res.unread_count);
          updateSidebarNotifBadge(res.unread_count);
          renderNotificationDrawerList(res.notifications);
        }
      });
  }

  function updateUnreadBadge(count) {
    const badge = document.getElementById("header-unread-pulse");
    if (badge) {
      if (count > 0) {
        badge.style.display = "block";
      } else {
        badge.style.display = "none";
      }
    }
  }

  function updateSidebarNotifBadge(count) {
    const badge = document.getElementById("sidebar-notif-badge");
    if (badge) {
      if (count > 0) {
        badge.innerText = count;
        badge.style.display = "inline-block";
      } else {
        badge.style.display = "none";
      }
    }
  }

  function renderNotificationDrawerList(groups) {
    const list = document.getElementById("notification-drawer-list");
    if (!list) return;

    let html = "";
    const sections = [
      { key: 'today', title: 'Today' },
      { key: 'yesterday', title: 'Yesterday' },
      { key: 'thisWeek', title: 'This Week' },
      { key: 'older', title: 'Older' }
    ];

    let hasNotifications = false;

    sections.forEach(sec => {
      const items = groups[sec.key];
      if (items && items.length > 0) {
        hasNotifications = true;
        html += `<div style="font-size: 11px; font-weight: 700; color: var(--text-secondary); text-transform: uppercase; margin: 12px 6px 4px 6px;">${sec.title}</div>`;
        items.forEach(item => {
          html += `
            <div class="drawer-item ${item.is_read == 0 ? 'unread' : ''}" data-url="${item.url || ''}" data-id="${item.id}">
              <div class="drawer-item-icon ${item.priority === 'high' ? 'danger' : 'primary'}">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9M13.73 21a2 2 0 0 1-3.46 0"/></svg>
              </div>
              <div class="drawer-item-body">
                <div class="drawer-item-title">${item.title}</div>
                <div class="drawer-item-desc">${item.description}</div>
                <div class="drawer-item-time">${formatTimeStr(item.created_at)}</div>
              </div>
            </div>
          `;
        });
      }
    });

    if (!hasNotifications) {
      html = `
        <div class="empty-state" style="padding: 40px;">
          <svg class="empty-state-illust" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="var(--text-muted)" stroke-width="1.5"><circle cx="12" cy="12" r="10"/><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/></svg>
          <div class="empty-state-title" style="font-size:13px;">No notifications yet</div>
        </div>
      `;
    }

    list.innerHTML = html;

    // Bind item click
    list.querySelectorAll(".drawer-item").forEach(el => {
      el.addEventListener("click", () => {
        const id = el.getAttribute("data-id");
        const url = el.getAttribute("data-url");

        // Mark read
        const form = new FormData();
        form.append("action", "mark_read");
        form.append("notification_id", id);
        
        fetch('api/notifications.php', {
          method: 'POST',
          body: form
        }).then(() => {
          pollNotifications();
          if (url) {
            switchView(url);
          } else {
            switchView('notifications');
          }
        });
      });
    });
  }

  function formatTimeStr(timestamp) {
    const diff = Math.floor((new Date() - new Date(timestamp)) / 1000);
    if (diff < 60) return "Just now";
    if (diff < 3600) return Math.floor(diff / 60) + " mins ago";
    if (diff < 86400) return Math.floor(diff / 3600) + " hours ago";
    return dateStr = new Date(timestamp).toLocaleDateString();
  }

  /* --- FLOATING ACTION BUTTON (FAB) --- */
  function initFAB() {
    const fab = document.getElementById("fab-element");
    const trigger = document.getElementById("fab-trigger-btn");

    if (fab && trigger) {
      trigger.addEventListener("click", (e) => {
        e.stopPropagation();
        fab.classList.toggle("active");
      });
      document.addEventListener("click", () => {
        fab.classList.remove("active");
      });
    }
  }

  /* --- GLOBAL SEARCH MANAGER --- */
  function initGlobalSearch() {
    const searchInput = document.getElementById("global-search-input");
    if (!searchInput) return;

    searchInput.addEventListener("keydown", (e) => {
      if (e.key === "Enter") {
        e.preventDefault();
        const val = searchInput.value.trim();
        if (val) {
          window.location.href = "search_results.php?query=" + encodeURIComponent(val);
        }
      }
    });
  }

  /* --- PORTAL TRANSLATION ENGINE --- */
  const translations = {
    en: {
      dashboard: "Dashboard",
      students: "Students",
      companies: "Companies",
      drives: "Placement Drives",
      applications: "Applications",
      pipeline: "Pipeline (Kanban)",
      interviews: "Interviews",
      settings: "Settings",
      my_profile: "My Profile",
      activity_history: "Activity History",
      search_placeholder: "Search profiles, drives, metrics...",
      morning: "Good Morning",
      afternoon: "Good Afternoon",
      evening: "Good Evening",
      welcome_suffix: "👋"
    },
    hi: {
      dashboard: "डैशबोर्ड",
      students: "छात्र",
      companies: "कंपनियां",
      drives: "प्लेसमेंट ड्राइव",
      applications: "आवेदन",
      pipeline: "पाइपलाइन (कानबन)",
      interviews: "साक्षात्कार",
      settings: "सेटिंग्स",
      my_profile: "मेरी प्रोफ़ाइल",
      activity_history: "गतिविधि इतिहास",
      search_placeholder: "प्रोफाइल, ड्राइव, मेट्रिक्स खोजें...",
      morning: "शुभ प्रभात",
      afternoon: "शुभ दोपहर",
      evening: "शुभ संध्या",
      welcome_suffix: "👋"
    }
  };

  function initLanguageSelector() {
    const langSelect = document.getElementById("settings-lang-select");
    if (langSelect) {
      const currentLang = langSelect.value;
      localStorage.setItem("lang", currentLang);

      langSelect.addEventListener("change", () => {
        const nextLang = langSelect.value;
        localStorage.setItem("lang", nextLang);
        saveSettingsAPI();
        setTimeout(() => window.location.reload(), 500);
      });
    }
  }

  function saveSettingsAPI() {
    const langSelect = document.getElementById("settings-lang-select");
    if (!langSelect) return;

    const lang = langSelect.value;

    const f = new FormData();
    f.append("action", "update_settings");
    f.append("language", lang);

    fetch('api/actions.php', {
      method: 'POST',
      body: f
    })
    .then(res => res.json())
    .then(res => {
      if (res.status !== 'success') {
        showToast("Error saving preferences", res.message, "danger");
      }
    });
  }

  function getInitials(name) {
    name = name.trim();
    if (!name) return 'U';
    
    // Clean up multiple spaces
    name = name.replace(/\s+/g, ' ');
    
    // Strip prefixes
    name = name.replace(/^(mr|ms|mrs|dr|prof|prof\.)\s+/i, '');
    name = name.trim();
    
    if (!name) return 'U';
    
    const words = name.split(' ');
    if (words.length === 1) {
      return words[0].charAt(0).toUpperCase();
    }
    
    const firstInitial = words[0].charAt(0).toUpperCase();
    const lastInitial = words[words.length - 1].charAt(0).toUpperCase();
    return firstInitial + lastInitial;
  }

  function initProfileForm() {
    const form = document.getElementById("form-update-profile");
    if (!form) return;

    const phoneInput = form.querySelector("[name='phone']");
    if (phoneInput) {
      phoneInput.addEventListener("keydown", (e) => {
        if ([46, 8, 9, 27, 13].indexOf(e.keyCode) !== -1 ||
            (e.ctrlKey === true || e.metaKey === true) ||
            (e.keyCode >= 35 && e.keyCode <= 40)) {
                 return;
        }
        if ((e.shiftKey || (e.keyCode < 48 || e.keyCode > 57)) && (e.keyCode < 96 || e.keyCode > 105)) {
            e.preventDefault();
        }
      });
      phoneInput.addEventListener("input", () => {
        let val = phoneInput.value.replace(/\D/g, '');
        if (val.length > 10) val = val.substring(0, 10);
        phoneInput.value = val;
      });
      phoneInput.addEventListener("paste", (e) => {
        e.preventDefault();
        const clipboardData = e.clipboardData || window.clipboardData;
        const pastedData = clipboardData.getData('text');
        let val = pastedData.replace(/\D/g, '');
        if (val.length > 10) val = val.substring(0, 10);
        phoneInput.value = val;
      });
    }

    form.addEventListener("submit", (e) => {
      e.preventDefault();
      
      const submitBtn = form.querySelector("button[type='submit']");
      const phoneInputVal = form.querySelector("[name='phone']");
      if (phoneInputVal) {
        const phone = phoneInputVal.value;
        if (!/^[0-9]{10}$/.test(phone)) {
          Swal.fire({
            title: 'Validation Error',
            text: 'Please enter a valid mobile number in the format +91 XXXXXXXXXX.',
            icon: 'error'
          });
          return;
        }
      }

      if (submitBtn) submitBtn.disabled = true;

      Swal.fire({
        title: 'Saving Profile...',
        text: 'Please wait while we update your details.',
        allowOutsideClick: false,
        didOpen: () => {
          Swal.showLoading();
        }
      });

      const f = new FormData(form);
      f.append("action", "update_profile");

      fetch('api/actions.php', {
        method: 'POST',
        body: f
      })
      .then(res => res.json())
      .then(res => {
        if (submitBtn) submitBtn.disabled = false;
        Swal.close();

        if (res.status === 'success') {
          Swal.fire({
            title: 'Profile Saved!',
            text: res.message,
            icon: 'success',
            timer: 1500,
            showConfirmButton: false
          });

          // Dynamic Navbar/Greeting/Avatar Updates
          const newName = res.user_name;
          
          const dropdownName = document.querySelector(".dropdown-user-name");
          if (dropdownName) dropdownName.innerText = newName;
          
          const initials = getInitials(newName);
          const avatarEl = document.querySelector(".profile-avatar-trigger .avatar");
          if (avatarEl) avatarEl.innerText = initials;

          initWelcomeGreeting();
        } else {
          Swal.fire({
            title: 'Error',
            text: res.message,
            icon: 'error'
          });
        }
      })
      .catch(err => {
        if (submitBtn) submitBtn.disabled = false;
        Swal.close();
        Swal.fire({
          title: 'Connection Error',
          text: 'Failed to communicate with the server.',
          icon: 'error'
        });
      });
    });
  }

  function initDriveForm() {
    const form = document.getElementById("form-add-drive-api");
    if (!form) return;

    form.addEventListener("submit", (e) => {
      e.preventDefault();

      const btn = document.getElementById("btn-add-drive-submit");
      if (btn) btn.disabled = true;

      // Front-end validations
      const jobRole = form.querySelector("[name='job_role']").value.trim();
      const cgpa = form.querySelector("[name='eligibility_cgpa']").value;
      const packageLpa = form.querySelector("[name='package_lpa']").value;
      const driveDate = form.querySelector("[name='drive_date']").value;
      const deadline = form.querySelector("[name='registration_deadline']").value;
      const departments = form.querySelector("[name='departments']").value.trim();

      if (!jobRole) {
        Swal.fire({ title: 'Validation Error', text: 'Drive Title is required.', icon: 'error' });
        if (btn) btn.disabled = false;
        return;
      }
      if (!cgpa || isNaN(cgpa) || cgpa < 0 || cgpa > 10) {
        Swal.fire({ title: 'Validation Error', text: 'Minimum CGPA Criteria must be a number between 0 and 10.', icon: 'error' });
        if (btn) btn.disabled = false;
        return;
      }
      if (!packageLpa || isNaN(packageLpa) || packageLpa <= 0) {
        Swal.fire({ title: 'Validation Error', text: 'Compensation LPA must be a positive number.', icon: 'error' });
        if (btn) btn.disabled = false;
        return;
      }
      if (!driveDate) {
        Swal.fire({ title: 'Validation Error', text: 'Invalid interview date.', icon: 'error' });
        if (btn) btn.disabled = false;
        return;
      }
      if (!deadline) {
        Swal.fire({ title: 'Validation Error', text: 'Registration deadline is required.', icon: 'error' });
        if (btn) btn.disabled = false;
        return;
      }
      if (!departments) {
        Swal.fire({ title: 'Validation Error', text: 'Target Branches are required.', icon: 'error' });
        if (btn) btn.disabled = false;
        return;
      }

      const fd = new FormData(form);
      fd.append("action", "create_drive");

      fetch('api/actions.php', {
        method: 'POST',
        body: fd
      })
      .then(res => res.json())
      .then(res => {
        if (btn) btn.disabled = false;

        if (res.status === 'success') {
          closeModal("modal-add-drive");
          form.reset();

          Swal.fire({
            title: 'Success',
            text: 'Placement Drive Created Successfully.',
            icon: 'success',
            timer: 1500,
            showConfirmButton: false
          });

          setTimeout(() => window.location.reload(), 1500);
        } else {
          Swal.fire({
            title: 'Error',
            text: res.message,
            icon: 'error'
          });
        }
      })
      .catch(err => {
        if (btn) btn.disabled = false;
        Swal.fire({
          title: 'Error',
          text: 'Database connection failed or network error.',
          icon: 'error'
        });
      });
    });
  }

  function applyTranslations(lang) {
    const t = translations[lang] || translations.en;
    
    document.querySelectorAll("[data-target]").forEach(item => {
      const target = item.getAttribute("data-target");
      const labelEl = item.querySelector(".nav-label");
      if (labelEl) {
        if (target === "dashboard") labelEl.innerText = t.dashboard;
        else if (target === "students") labelEl.innerText = t.students;
        else if (target === "companies") labelEl.innerText = t.companies;
        else if (target === "drives") labelEl.innerText = t.drives;
        else if (target === "applications") labelEl.innerText = t.applications;
        else if (target === "pipeline") labelEl.innerText = t.pipeline;
        else if (target === "interviews") labelEl.innerText = t.interviews;
        else if (target === "settings") labelEl.innerText = t.settings;
        else if (target === "profile-tab") labelEl.innerText = t.my_profile;
        else if (target === "activitylogs") labelEl.innerText = t.activity_history;
      }
    });

    const searchInput = document.getElementById("global-search-input");
    if (searchInput) {
      searchInput.placeholder = t.search_placeholder;
    }

    initWelcomeGreeting();
  }

  /* --- WELCOME GREETING --- */
  function initWelcomeGreeting() {
    const greetingText = document.getElementById("admin-greeting-text");
    if (greetingText) {
      const hrs = new Date().getHours();
      const lang = localStorage.getItem("lang") || "en";
      const t = translations[lang] || translations.en;
      let greet = t.morning;
      if (hrs >= 12 && hrs < 17) greet = t.afternoon;
      else if (hrs >= 17) greet = t.evening;
      
      const userName = document.querySelector(".dropdown-user-name") ? document.querySelector(".dropdown-user-name").innerText : "Administrator";
      greetingText.innerText = `${greet}, ${userName} ${t.welcome_suffix}`;
    }
  }

  /* --- TOAST SYSTEM --- */
  window.showToast = function(title, desc, type = "success") {
    const container = document.getElementById("toast-holder");
    if (!container) return;

    const toast = document.createElement("div");
    toast.className = `toast-message`;
    
    let iconSvg = "";
    if (type === "success") {
      iconSvg = `<svg class="toast-icon" style="color: var(--color-success);" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>`;
    } else if (type === "warning") {
      iconSvg = `<svg class="toast-icon" style="color: var(--color-warning);" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>`;
    } else if (type === "danger") {
      iconSvg = `<svg class="toast-icon" style="color: var(--color-danger);" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="7.86 2 16.14 2 22 7.86 22 16.14 16.14 22 7.86 22 2 16.14 2 7.86 7.86 2"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>`;
    } else {
      iconSvg = `<svg class="toast-icon" style="color: var(--color-info);" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>`;
    }

    toast.innerHTML = `
      ${iconSvg}
      <div class="toast-content">
        <div class="toast-title">${title}</div>
        <div class="toast-desc">${desc}</div>
      </div>
      <svg class="toast-close" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
    `;

    container.appendChild(toast);

    toast.querySelector(".toast-close").addEventListener("click", () => {
      toast.classList.add("closing");
      setTimeout(() => toast.remove(), 250);
    });

    setTimeout(() => {
      if (toast.parentNode) {
        toast.classList.add("closing");
        setTimeout(() => toast.remove(), 250);
      }
    }, 4000);
  };

  /* --- LOAD DYNAMIC STATS & GRAPH DATA --- */
  function loadLiveDashboardData() {
    fetch('api/stats.php')
      .then(res => res.json())
      .then(res => {
        if (res.status === 'success') {
          renderDashboardKPIs(res.kpis);
          initChartJSVisuals(res.charts);
        }
      });
  }

  function renderDashboardKPIs(k) {
    const grid = document.getElementById("dashboard-kpis-grid");
    if (!grid) return;

    // Define cards structure dynamically based on user role permissions
    let cards = [];
    
    if (data.role === 'admin' || data.role === 'tpo') {
      cards = [
        { label: 'Total Students', val: k.totalStudents, spark: [120, 140, 150], color: '#2563EB', icon: 'users', view: 'students' },
        { label: 'Verified Students', val: k.verifiedStudents, spark: [100, 130, 145], color: '#10B981', icon: 'user-check', view: 'students' },
        { label: 'Pending Students', val: k.pendingStudents, spark: [20, 15, 12], color: '#F59E0B', icon: 'clock', view: 'students' },
        { label: 'Companies', val: k.companiesRegistered, spark: [20, 24, 25], color: '#06B6D4', icon: 'briefcase', view: 'companies' },
        { label: 'Placement Drives', val: k.activeDrives, spark: [3, 5, 8], color: '#EF4444', icon: 'calendar', view: 'drives' },
        { label: 'Applications', val: k.applicationsCount, spark: [50, 80, 110], color: '#3B82F6', icon: 'file-text', view: 'applications' },
        { label: 'Shortlisted', val: k.shortlistedStudents, spark: [10, 22, 35], color: '#10B981', icon: 'award' },
        { label: 'Offers Released', val: k.offersCount, spark: [5, 10, 15], color: '#10B981', icon: 'check-circle' },
        { label: 'Placed Students', val: k.studentsPlaced, spark: [5, 12, 18], color: '#10B981', icon: 'smile' },
        { label: 'Placement Rate', val: k.placementRate + '%', spark: [75, 82, 88], color: '#10B981', icon: 'percent' },
        { label: 'Highest Package', val: '₹' + k.highestPackage + ' LPA', spark: [22, 32, 48], color: '#EF4444', icon: 'dollar-sign' },
        { label: 'Average Package', val: '₹' + k.averagePackage + ' LPA', spark: [6.8, 7.5, 8.7], color: '#06B6D4', icon: 'dollar-sign' },
        { label: 'Rejected Apps', val: k.rejectedApplications, spark: [5, 12, 18], color: '#EF4444', icon: 'x-circle' },
        { label: 'Pending Interviews', val: k.pendingInterviews, spark: [2, 5, 1], color: '#F59E0B', icon: 'users' }
      ];
    } else if (data.role === 'student') {
      cards = [
        { label: 'Job Openings Available', val: k.activeDrives, spark: [3, 5, 8], color: '#2563EB', icon: 'briefcase', view: 'drives' },
        { label: 'My Applications', val: data.applications.length, spark: [1, 2, 3], color: '#06B6D4', icon: 'file-text', view: 'applications' },
        { label: 'Interviews Scheduled', val: data.interviews.length, spark: [0, 1, 1], color: '#F59E0B', icon: 'clock', view: 'interviews' },
        { label: 'Offers Received', val: data.offers.length, spark: [0, 1, 1], color: '#10B981', icon: 'check-circle' }
      ];
    } else if (data.role === 'company') {
      cards = [
        { label: 'Active Recruitment Drives', val: data.drives.length, spark: [1, 2, 2], color: '#2563EB', icon: 'briefcase', view: 'drives' },
        { label: 'Registered Candidates Applied', val: data.applications.length, spark: [5, 10, 18], color: '#3B82F6', icon: 'users', view: 'applications' },
        { label: 'Interviews Scheduled', val: data.interviews.length, spark: [2, 4, 8], color: '#F59E0B', icon: 'clock', view: 'interviews' },
        { label: 'Total Hired Candidates', val: k.studentsPlaced, spark: [1, 4, 9], color: '#10B981', icon: 'check-circle' }
      ];
    }

    grid.innerHTML = cards.map(c => `
      <div class="card kpi-card card-lift" ${c.view ? `onclick="window.switchView('${c.view}')"` : ''}>
        <div class="kpi-card-header">
          <span>${c.label}</span>
          <div class="kpi-card-icon" style="background-color: var(--primary-light); color: var(--primary);">
            <i data-lucide="${c.icon}" style="width:16px; height:16px;"></i>
          </div>
        </div>
        <div class="kpi-card-body">
          <div class="kpi-value">${c.val}</div>
          <div class="kpi-sparkline">${createSparklineSVG(c.spark, c.color)}</div>
        </div>
        <div class="kpi-card-footer">
          <span class="kpi-trend-pct up">&uarr; Live</span>
          <span>database sync</span>
        </div>
      </div>
    `).join('');

    lucide.createIcons();
  }

  /* --- CHART.JS CONFIG --- */
  function initChartJSVisuals(ch) {
    if (typeof Chart === 'undefined') return;

    Chart.defaults.font.family = "'Inter', sans-serif";
    Chart.defaults.color = "var(--text-secondary)";

    // 1. Placements Trend
    const ctxTrend = document.getElementById("chart-placement-trend");
    if (ctxTrend) {
      charts.trend = new Chart(ctxTrend, {
        type: 'line',
        data: {
          labels: ch.months,
          datasets: [{
            label: 'Selections (Acc.)',
            data: ch.placementsTrend,
            borderColor: '#2563EB',
            borderWidth: 3,
            backgroundColor: 'rgba(37, 99, 235, 0.08)',
            fill: true,
            tension: 0.4
          }]
        },
        options: getChartOptions()
      });
    }

    // 2. Applications Trend
    const ctxApps = document.getElementById("chart-applications-month");
    if (ctxApps) {
      charts.apps = new Chart(ctxApps, {
        type: 'bar',
        data: {
          labels: ch.months,
          datasets: [{
            label: 'Applications',
            data: ch.applicationsTrend,
            backgroundColor: '#06B6D4',
            borderRadius: 6
          }]
        },
        options: getChartOptions()
      });
    }

    // 3. Dept Breakdown
    const ctxDept = document.getElementById("chart-students-dept");
    if (ctxDept) {
      charts.dept = new Chart(ctxDept, {
        type: 'doughnut',
        data: {
          labels: ch.deptLabels,
          datasets: [{
            data: ch.deptCounts,
            backgroundColor: ['#2563EB', '#06B6D4', '#10B981', '#F59E0B', '#EF4444', '#6B7280'],
            borderWidth: 2,
            borderColor: 'var(--bg-card)'
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: {
              position: 'right',
              labels: { boxWidth: 12 }
            }
          }
        }
      });
    }

    renderSelectionFunnel(ch.funnel);
    renderTopCompaniesList();
    renderActivitiesTimeline();
    lucide.createIcons();
  }

  function renderSelectionFunnel(f) {
    const container = document.getElementById("chart-selection-funnel");
    if (!container) return;

    const stages = [
      { name: "Applications", val: f.applied, pct: 100 },
      { name: "Screened Profile", val: f.eligible, pct: f.applied > 0 ? Math.round((f.eligible/f.applied)*100) : 0 },
      { name: "Aptitude round", val: f.aptitude, pct: f.applied > 0 ? Math.round((f.aptitude/f.applied)*100) : 0 },
      { name: "Technical Interview", val: f.technical, pct: f.applied > 0 ? Math.round((f.technical/f.applied)*100) : 0 },
      { name: "HR Round", val: f.hr, pct: f.applied > 0 ? Math.round((f.hr/f.applied)*100) : 0 },
      { name: "Job Placed", val: f.selected, pct: f.applied > 0 ? Math.round((f.selected/f.applied)*100) : 0 }
    ];

    container.innerHTML = stages.map(s => `
      <div class="funnel-stage">
        <div class="funnel-label">${s.name}</div>
        <div class="funnel-bar-wrapper">
          <div class="funnel-bar" style="width: ${s.pct}%;"></div>
          <span class="funnel-value">${s.val.toLocaleString()} (${s.pct}%)</span>
        </div>
      </div>
    `).join('');
  }

  function renderTopCompaniesList() {
    const list = document.getElementById("dashboard-companies-list");
    if (!list) return;
    const sorted = [...data.companies].sort((a,b) => b.studentsHired - a.studentsHired).slice(0, 5);
    list.innerHTML = sorted.map(c => `
      <div class="recruiting-company-card">
        <div class="recruiting-company-left">
          <div class="avatar" style="background-color: var(--primary-light); color: var(--primary);">
            ${c.name.slice(0,2).toUpperCase()}
          </div>
          <div class="recruiting-company-info">
            <h4 style="font-size: 14px;">${c.name}</h4>
            <p>${c.industry}</p>
          </div>
        </div>
        <div class="recruiting-company-stats">
          <div>
            <div class="recruiting-stat-val">₹${c.highestPackage} LPA</div>
            <div class="recruiting-stat-lbl">Max Package</div>
          </div>
          <div>
            <div class="recruiting-stat-val">${c.studentsHired}</div>
            <div class="recruiting-stat-lbl">Hired</div>
          </div>
        </div>
      </div>
    `).join('');
  }

  function renderActivitiesTimeline() {
    const list = document.getElementById("dashboard-timeline-list");
    if (!list) return;

    // Map applications list dynamically to timeline
    const subset = data.applications.slice(0, 5);
    
    list.innerHTML = subset.map(item => `
      <div class="timeline-item ${item.status === 'Selected' ? 'offer' : (item.status === 'Rejected' ? 'drive' : 'student')}">
        <div class="timeline-marker"></div>
        <div class="timeline-content">
          <div class="timeline-header">
            <span class="timeline-title">${item.studentName}</span>
            <span class="timeline-time">${item.applied_date}</span>
          </div>
          <div class="timeline-desc">
            Applied to ${item.companyName} &bull; ${item.role} 
            <span class="badge ${item.status === 'Selected' ? 'badge-success' : (item.status === 'Rejected' ? 'badge-danger' : 'badge-primary')}" style="padding: 2px 6px; font-size: 10px;">
              ${item.status}
            </span>
          </div>
        </div>
      </div>
    `).join('');
  }

  function getChartOptions() {
    return {
      responsive: true,
      maintainAspectRatio: false,
      scales: {
        x: { grid: { color: 'var(--border-color)' } },
        y: { grid: { color: 'var(--border-color)' } }
      },
      plugins: { legend: { display: false } }
    };
  }

  function refreshCharts() {
    Object.keys(charts).forEach(key => {
      const c = charts[key];
      if (c) {
        c.options.scales.x.grid.color = getComputedStyle(document.documentElement).getPropertyValue('--border-color').trim();
        c.options.scales.y.grid.color = getComputedStyle(document.documentElement).getPropertyValue('--border-color').trim();
        c.update();
      }
    });
  }

  /* --- DATA TABLES LAZY RENDERERS --- */
  window.renderStudentsTable = function() {
    if (studentTableInstance) return;
    const cols = [
      { key: "id", label: "Student ID" },
      { key: "name", label: "Name", render: (val, row) => `
        <div style="display: flex; align-items: center; gap: 8px;">
          <div class="avatar avatar-sm">${val.slice(0,2).toUpperCase()}</div>
          <div>
            <div style="font-weight: 600;">${val}</div>
            <div style="font-size: 11px; color: var(--text-secondary);">${row.email}</div>
          </div>
        </div>
      `},
      { key: "deptCode", label: "Dept" },
      { key: "cgpa", label: "CGPA", render: val => `<span style="font-weight:600;">${val}</span>` },
      { key: "placedStatus", label: "Placement", render: val => `<span class="badge ${val === 'Placed' ? 'badge-success' : 'badge-warning'}">${val}</span>` },
      { key: "verifiedStatus", label: "Portal Status", render: (val, row) => {
        const style = val === 'approved' ? 'badge-success' : (val === 'pending' ? 'badge-warning' : 'badge-danger');
        return `
          <div style="display:flex; align-items:center; gap:6px;">
            <span class="badge ${style}">${val}</span>
            ${(data.role === 'admin' || data.role === 'tpo') && val === 'pending' ? `
              <button class="btn btn-success btn-sm quick-verify-student" data-id="${row.id}" style="padding:2px 6px; font-size:10px;">Verify</button>
            ` : ''}
          </div>
        `;
      }}
    ];

    studentTableInstance = new ModernDataTable("students-table-container", data.students, cols, {
      itemsPerPage: 10,
      onDelete: (id) => {
        Swal.fire({
          title: 'Are you sure?',
          text: "Do you want to suspend this student account?",
          icon: 'warning',
          showCancelButton: true,
          confirmButtonColor: '#EF4444',
          cancelButtonColor: '#6B7280',
          confirmButtonText: 'Yes, suspend it!'
        }).then((result) => {
          if (result.isConfirmed) {
            updateStatusAPI(id, 'suspended');
          }
        });
      },
      onRowClick: (id, row) => showStudentDetailsModal(row)
    });

    // Bind filters
    const deptFilter = document.getElementById("filter-student-dept");
    if (deptFilter) {
      deptFilter.addEventListener("change", (e) => {
        studentTableInstance.setFilter('deptCode', e.target.value);
      });
    }

    const placedFilter = document.getElementById("filter-student-placed");
    if (placedFilter) {
      placedFilter.addEventListener("change", (e) => {
        studentTableInstance.setFilter('placedStatus', e.target.value);
      });
    }

    bindVerifyButtons();
  };

  function bindVerifyButtons() {
    document.getElementById("students-table-container").addEventListener("click", (e) => {
      if (e.target.classList.contains("quick-verify-student")) {
        const id = e.target.getAttribute("data-id");
        updateStatusAPI(id, 'approved');
      }
    });
  }

  function updateStatusAPI(userId, status) {
    const f = new FormData();
    f.append("action", "update_user_status");
    f.append("target_user_id", userId);
    f.append("status", status);

    fetch('api/actions.php', {
      method: 'POST',
      body: f
    })
    .then(res => res.json())
    .then(res => {
      if (res.status === 'success') {
        Swal.fire({
          title: 'Success!',
          text: res.message,
          icon: 'success',
          timer: 1500,
          showConfirmButton: false
        });
        setTimeout(() => window.location.reload(), 1500);
      } else {
        Swal.fire({
          title: 'Action Failed',
          text: res.message,
          icon: 'error'
        });
      }
    });
  }

  function showStudentDetailsModal(student) {
    const bodyHtml = `
      <div style="display: flex; flex-direction: column; align-items: center; gap: var(--space-2); text-align: center;">
        <div class="avatar avatar-lg">${student.name.slice(0,2).toUpperCase()}</div>
        <div>
          <h3 style="font-weight: 700; font-size: 18px;">${student.name}</h3>
          <p style="color: var(--text-secondary); font-size: 13px;">${student.email}</p>
        </div>
      </div>
      <div style="margin-top: var(--space-3); border-top: 1px solid var(--border-color); padding-top: var(--space-2); display: flex; flex-direction: column; gap: var(--space-15);">
        <div style="display: flex; justify-content: space-between;">
          <span style="color: var(--text-secondary);">Department</span>
          <span style="font-weight: 600;">${student.department}</span>
        </div>
        <div style="display: flex; justify-content: space-between;">
          <span style="color: var(--text-secondary);">CGPA</span>
          <span style="font-weight: 600;">${student.cgpa} / 10.0</span>
        </div>
        <div style="display: flex; justify-content: space-between;">
          <span style="color: var(--text-secondary);">Resume</span>
          <span>${student.resume_path ? `<a href="${student.resume_path}" target="_blank" style="color:var(--primary); font-weight:600;">Open PDF</a>` : 'Not uploaded'}</span>
        </div>
        <div style="display: flex; justify-content: space-between;">
          <span style="color: var(--text-secondary);">Certificates</span>
          <span>${student.certificate_path ? `<a href="${student.certificate_path}" target="_blank" style="color:var(--primary); font-weight:600;">Open PDF</a>` : 'Not uploaded'}</span>
        </div>
      </div>
    `;

    const modal = document.getElementById("modal-view-details");
    modal.querySelector(".modal-title").innerText = "Student Profile";
    modal.querySelector(".modal-body").innerHTML = bodyHtml;
    openModal("modal-view-details");
  }

  window.renderCompaniesTable = function() {
    if (companyTableInstance) return;
    const cols = [
      { key: "id", label: "ID" },
      { key: "company_name", label: "Company" },
      { key: "industry", label: "Industry" },
      { key: "avgPackage", label: "Avg Package", render: val => `₹${val} LPA` },
      { key: "highestPackage", label: "Highest Package", render: val => `₹${val} LPA` },
      { key: "openPositions", label: "Openings" },
      { key: "status", label: "Portal Status", render: (val, row) => {
        const style = val === 'approved' ? 'badge-success' : (val === 'pending' ? 'badge-warning' : 'badge-danger');
        return `
          <div style="display:flex; align-items:center; gap:6px;">
            <span class="badge ${style}">${val}</span>
            ${(data.role === 'admin' || data.role === 'tpo') && val === 'pending' ? `
              <button class="btn btn-success btn-sm quick-verify-company" data-id="${row.id}" style="padding:2px 6px; font-size:10px;">Approve</button>
            ` : ''}
          </div>
        `;
      }}
    ];

    companyTableInstance = new ModernDataTable("companies-table-container", data.companies, cols, {
      itemsPerPage: 10,
      onDelete: (id) => updateStatusAPI(id, 'suspended')
    });

    document.getElementById("companies-table-container").addEventListener("click", (e) => {
      if (e.target.classList.contains("quick-verify-company")) {
        const id = e.target.getAttribute("data-id");
        updateStatusAPI(id, 'approved');
      }
    });
  };

  window.renderDrivesTable = function() {
    if (driveTableInstance) return;
    const cols = [
      { key: "id", label: "Drive ID" },
      { key: "companyName", label: "Company" },
      { key: "jobRole", label: "Role" },
      { key: "eligibilityCGPA", label: "Min CGPA" },
      { key: "packageLPA", label: "CTC Package", render: val => `₹${val} LPA` },
      { key: "date", label: "Commence Date" },
      { key: "status", label: "Drive Status", render: val => {
        const style = val === 'completed' ? 'badge-success' : (val === 'open' ? 'badge-primary' : 'badge-info');
        return `<span class="badge ${style}">${val}</span>`;
      }}
    ];

    // Show Clone / Delete buttons inside actions
    driveTableInstance = new ModernDataTable("drives-table-container", data.drives, cols, {
      itemsPerPage: 10,
      onDelete: (id) => {
        Swal.fire({
          title: 'Delete Campaign?',
          text: 'Are you sure you want to delete this drive campaign?',
          icon: 'warning',
          showCancelButton: true,
          confirmButtonColor: '#EF4444',
          cancelButtonColor: '#6B7280',
          confirmButtonText: 'Yes, delete'
        }).then((result) => {
          if (result.isConfirmed) {
            showToast("Drives", "Deleting campaign is simulation only", "info");
          }
        });
      },
      onEdit: (id) => {
        // Clone Drive Action
        Swal.fire({
          title: 'Clone Drive?',
          text: 'Do you want to clone this recruitment drive campaign?',
          icon: 'question',
          showCancelButton: true,
          confirmButtonColor: '#2563EB',
          cancelButtonColor: '#6B7280',
          confirmButtonText: 'Yes, clone it'
        }).then((result) => {
          if (result.isConfirmed) {
            const f = new FormData();
            f.append("action", "clone_drive");
            f.append("drive_id", id);
            
            fetch('api/actions.php', {
              method: 'POST',
              body: f
            })
            .then(res => res.json())
            .then(res => {
              if (res.status === 'success') {
                showToast("Drive Cloned", res.message, "success");
                setTimeout(() => window.location.reload(), 1500);
              } else {
                showToast("Clone Failed", res.message, "danger");
              }
            });
          }
        });
      }
    });
  };

  window.renderApplicationsTable = function() {
    if (applicationTableInstance) return;
    const cols = [
      { key: "id", label: "App ID" },
      { key: "studentName", label: "Candidate" },
      { key: "deptCode", label: "Dept" },
      { key: "companyName", label: "Recruiter" },
      { key: "role", label: "Job Role" },
      { key: "status", label: "Funnel Status", render: val => {
        const style = val === 'Selected' ? 'badge-success' : (val === 'Rejected' ? 'badge-danger' : 'badge-primary');
        return `<span class="badge ${style}">${val}</span>`;
      }}
    ];

    applicationTableInstance = new ModernDataTable("applications-table-container", data.applications, cols, {
      itemsPerPage: 12
    });
  };

  window.renderKanban = function() {
    if (kanbanInstance) return;
    kanbanInstance = new KanbanPipeline("kanban-pipeline-container", data.applications, {
      onCardMove: (appId, newStage) => {
        // Update database round status
        const f = new FormData();
        f.append("action", "publish_selection");
        f.append("application_id", appId);
        f.append("result", newStage === "Selected" ? "Selected" : (newStage === "Rejected" ? "Rejected" : "Eligible"));

        fetch('api/actions.php', {
          method: 'POST',
          body: f
        });
      }
    });
  };

  window.renderCalendar = function() {
    if (calendarInstance) return;
    calendarInstance = new InterviewCalendar("calendar-widget-grid", "upcoming-interviews-list", data.interviews);
  }

  // Bind custom modals actions
  window.openModal = function(id) {
    const m = document.getElementById(id);
    if (m) m.classList.add("active");
  };
  
  window.closeModal = function(id) {
    const m = document.getElementById(id);
    if (m) m.classList.remove("active");
  };

  // Close overlays
  document.querySelectorAll(".modal-overlay").forEach(overlay => {
    overlay.querySelectorAll(".modal-close, .modal-cancel-btn").forEach(btn => {
      btn.addEventListener("click", () => closeModal(overlay.id));
    });
  });

  function loadNotificationsPage() {
    const listContainer = document.getElementById("notifications-list");
    if (!listContainer) return;

    fetch('api/notifications.php?filter=all')
      .then(res => res.json())
      .then(res => {
        if (res.status !== 'success') return;
        
        // Update sidebar and drawer counter/badges
        updateUnreadBadge(res.unread_count);
        updateSidebarNotifBadge(res.unread_count);

        // Combine groups into a single list
        let allNotifs = [];
        const groups = res.notifications;
        ['today', 'yesterday', 'thisWeek', 'older'].forEach(key => {
          if (groups[key]) {
            allNotifs = allNotifs.concat(groups[key]);
          }
        });

        // Sort: unread (is_read=0) first, then read (is_read=1)
        // Secondary sort: created_at DESC
        allNotifs.sort((a, b) => {
          if (a.is_read != b.is_read) {
            return a.is_read - b.is_read; // 0 (unread) first, then 1 (read)
          }
          return new Date(b.created_at) - new Date(a.created_at);
        });

        if (allNotifs.length === 0) {
          listContainer.innerHTML = `
            <div class="empty-state" style="padding: 40px; text-align: center;">
              <svg class="empty-state-illust" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="var(--text-muted)" stroke-width="1.5" style="margin: 0 auto 16px auto; display: block;"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
              <div class="empty-state-title" style="font-size: 14px; font-weight: 600;">No notifications</div>
              <p style="color: var(--text-secondary); font-size: 13px; margin-top: 4px;">You are all caught up!</p>
            </div>
          `;
          return;
        }

        listContainer.innerHTML = allNotifs.map(n => {
          const isUnread = n.is_read == 0;
          return `
            <div class="notification-item ${isUnread ? 'unread' : 'read'}" style="display: flex; justify-content: space-between; align-items: flex-start; padding: var(--space-2); border-radius: var(--radius-md); border: 1px solid var(--border-color); background: ${isUnread ? 'rgba(37,99,235,0.06)' : 'rgba(255,255,255,0.02)'}; transition: all var(--transition-normal); margin-bottom: 8px;">
              <div style="display: flex; gap: var(--space-2); align-items: flex-start;">
                <div style="margin-top: 4px; color: ${isUnread ? 'var(--primary)' : 'var(--text-secondary)'};">
                  <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                </div>
                <div>
                  <h4 style="font-weight: 600; font-size: 14px; margin-bottom: 4px; color: var(--text-primary); display: flex; align-items: center; gap: 8px;">
                    ${n.title}
                    ${isUnread ? '<span class="badge badge-primary" style="padding: 2px 6px; font-size: 9px; border-radius: 4px;">New</span>' : ''}
                  </h4>
                  <p style="font-size: 13px; color: var(--text-secondary); margin-bottom: 6px;">${n.description}</p>
                  <span style="font-size: 11px; color: var(--text-muted);">${new Date(n.created_at).toLocaleString()}</span>
                </div>
              </div>
              <div style="display: flex; gap: 8px; align-items: center;">
                ${isUnread ? `<button class="btn btn-secondary btn-sm btn-page-mark-read" data-id="${n.id}" style="padding: 4px 8px; font-size: 11px;">Mark Read</button>` : ''}
                <button class="btn btn-ghost btn-sm btn-page-delete" data-id="${n.id}" style="padding: 6px; color: var(--color-danger); border: none; background: transparent; cursor: pointer;">
                  <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
                </button>
              </div>
            </div>
          `;
        }).join('');

        // Bind Mark Read
        listContainer.querySelectorAll(".btn-page-mark-read").forEach(btn => {
          btn.addEventListener("click", () => {
            const id = btn.getAttribute("data-id");
            const form = new FormData();
            form.append("action", "mark_read");
            form.append("notification_id", id);
            fetch('api/notifications.php', {
              method: 'POST',
              body: form
            }).then(() => {
              loadNotificationsPage();
              pollNotifications();
            });
          });
        });

        // Bind Delete
        listContainer.querySelectorAll(".btn-page-delete").forEach(btn => {
          btn.addEventListener("click", () => {
            const id = btn.getAttribute("data-id");
            Swal.fire({
              title: 'Are you sure?',
              text: "You won't be able to revert this!",
              icon: 'warning',
              showCancelButton: true,
              confirmButtonColor: '#EF4444',
              cancelButtonColor: '#6B7280',
              confirmButtonText: 'Yes, delete it!'
            }).then((result) => {
              if (result.isConfirmed) {
                const form = new FormData();
                form.append("action", "delete");
                form.append("notification_id", id);
                fetch('api/notifications.php', {
                  method: 'POST',
                  body: form
                }).then(() => {
                  Swal.fire({
                    title: 'Deleted!',
                    text: 'Notification has been removed.',
                    icon: 'success',
                    timer: 1500,
                    showConfirmButton: false
                  });
                  loadNotificationsPage();
                  pollNotifications();
                });
              }
            });
          });
        });
      });
  }

  function initNotificationsPageActions() {
    const btnMarkAll = document.getElementById("btn-mark-all-read");
    if (btnMarkAll) {
      btnMarkAll.addEventListener("click", () => {
        const form = new FormData();
        form.append("action", "mark_all_read");
        fetch('api/notifications.php', {
          method: 'POST',
          body: form
        }).then(() => {
          showToast("Success", "All notifications marked as read.", "success");
          loadNotificationsPage();
          pollNotifications();
        });
      });
    }
  }

  // Start polling
  initSidebar();
  initDropdowns();
  initNotificationDrawer();
  initFAB();
  initGlobalSearch();
  initLanguageSelector();
  initProfileForm();
  initDriveForm();
  initWelcomeGreeting();
  initNotificationsPageActions();
  loadLiveDashboardData(); // Load real DB statistics and render charts

  // Load and restore active view tab
  const savedView = sessionStorage.getItem('activeView') || 'dashboard';
  switchView(savedView);

  // Watch for size changes
  window.addEventListener("resize", handleResponsiveLayout);
  handleResponsiveLayout();
});
