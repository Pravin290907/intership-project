/**
 * Campus Recruitment Dashboard - UI Component Library
 */

// 1. SVG Sparkline Generator
function createSparklineSVG(values, color = "#2563EB", width = 80, height = 32) {
  if (!values || values.length === 0) return "";
  const min = Math.min(...values);
  const max = Math.max(...values);
  const range = max - min === 0 ? 1 : max - min;
  
  const points = values.map((val, index) => {
    const x = (index / (values.length - 1)) * width;
    const y = height - ((val - min) / range) * (height - 4) - 2;
    return { x, y };
  });

  let d = `M ${points[0].x} ${points[0].y}`;
  for (let i = 1; i < points.length; i++) {
    const p0 = points[i - 1];
    const p1 = points[i];
    // Smooth bezier curve control points
    const cpX1 = p0.x + (p1.x - p0.x) / 2;
    const cpY1 = p0.y;
    const cpX2 = p0.x + (p1.x - p0.x) / 2;
    const cpY2 = p1.y;
    d += ` C ${cpX1} ${cpY1}, ${cpX2} ${cpY2}, ${p1.x} ${p1.y}`;
  }

  // Create path for gradient fill under the line
  const fillD = `${d} L ${points[points.length - 1].x} ${height} L ${points[0].x} ${height} Z`;
  const gradId = `spark-grad-${Math.random().toString(36).substr(2, 9)}`;

  return `
    <svg width="${width}" height="${height}" viewBox="0 0 ${width} ${height}" xmlns="http://www.w3.org/2000/svg">
      <defs>
        <linearGradient id="${gradId}" x1="0" y1="0" x2="0" y2="1">
          <stop offset="0%" stop-color="${color}" stop-opacity="0.25" />
          <stop offset="100%" stop-color="${color}" stop-opacity="0.0" />
        </linearGradient>
      </defs>
      <path d="${fillD}" fill="url(#${gradId})" />
      <path d="${d}" fill="none" stroke="${color}" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
    </svg>
  `;
}

// 2. Data Table Controller Object
class ModernDataTable {
  constructor(containerId, originalData, columns, options = {}) {
    this.container = document.getElementById(containerId);
    this.originalData = originalData;
    this.data = [...originalData];
    this.columns = columns;
    this.options = Object.assign({
      itemsPerPage: 10,
      searchKey: "",
      filterKeys: {},
      sortKey: "",
      sortDesc: false,
      onRowClick: null,
      onEdit: null,
      onDelete: null,
      selectable: true
    }, options);

    this.currentPage = 1;
    this.selectedIds = new Set();
    this.init();
  }

  init() {
    this.applyFilters();
    this.render();
  }

  setData(newData) {
    this.originalData = newData;
    this.currentPage = 1;
    this.selectedIds.clear();
    this.applyFilters();
    this.render();
  }

  applyFilters() {
    let filtered = [...this.originalData];

    // 1. Search filter
    if (this.options.searchQuery && this.options.searchQuery.trim() !== "") {
      const q = this.options.searchQuery.toLowerCase();
      filtered = filtered.filter(item => {
        return Object.keys(item).some(key => {
          const val = item[key];
          return val && val.toString().toLowerCase().includes(q);
        });
      });
    }

    // 2. Column-based filters
    if (this.options.filterKeys) {
      Object.keys(this.options.filterKeys).forEach(key => {
        const targetVal = this.options.filterKeys[key];
        if (targetVal && targetVal !== "All") {
          filtered = filtered.filter(item => item[key] && item[key].toString() === targetVal);
        }
      });
    }

    // 3. Sorting
    if (this.options.sortKey) {
      const k = this.options.sortKey;
      const desc = this.options.sortDesc;
      filtered.sort((a, b) => {
        let valA = a[k];
        let valB = b[k];
        
        if (typeof valA === 'string') {
          return desc ? valB.localeCompare(valA) : valA.localeCompare(valB);
        } else {
          return desc ? valB - valA : valA - valB;
        }
      });
    }

    this.data = filtered;
    this.totalPages = Math.ceil(this.data.length / this.options.itemsPerPage) || 1;
    if (this.currentPage > this.totalPages) this.currentPage = this.totalPages;
  }

  setSearch(query) {
    this.options.searchQuery = query;
    this.currentPage = 1;
    this.applyFilters();
    this.render();
  }

  setFilter(key, val) {
    this.options.filterKeys[key] = val;
    this.currentPage = 1;
    this.applyFilters();
    this.render();
  }

  setSort(key) {
    if (this.options.sortKey === key) {
      this.options.sortDesc = !this.options.sortDesc;
    } else {
      this.options.sortKey = key;
      this.options.sortDesc = false;
    }
    this.applyFilters();
    this.render();
  }

  toggleSelectAll(checked) {
    const pageStart = (this.currentPage - 1) * this.options.itemsPerPage;
    const pageEnd = Math.min(pageStart + this.options.itemsPerPage, this.data.length);
    for (let i = pageStart; i < pageEnd; i++) {
      const item = this.data[i];
      if (checked) {
        this.selectedIds.add(item.id);
      } else {
        this.selectedIds.delete(item.id);
      }
    }
    this.render();
    this.updateBulkActionsBar();
  }

  toggleSelectItem(id, checked) {
    const parsedId = isNaN(id) ? id : parseInt(id, 10);
    if (checked) {
      this.selectedIds.add(parsedId);
    } else {
      this.selectedIds.delete(parsedId);
    }
    this.render();
    this.updateBulkActionsBar();
  }

  updateBulkActionsBar() {
    const bar = document.getElementById("table-bulk-actions");
    const countEl = document.getElementById("bulk-selected-count");
    if (!bar) return;

    if (this.selectedIds.size > 0) {
      bar.classList.add("active");
      countEl.innerText = `${this.selectedIds.size} row(s) selected`;
    } else {
      bar.classList.remove("active");
    }
  }

  getPaginatedData() {
    const start = (this.currentPage - 1) * this.options.itemsPerPage;
    const end = start + this.options.itemsPerPage;
    return this.data.slice(start, end);
  }

  render() {
    if (!this.container) return;

    const paginated = this.getPaginatedData();
    const pageStart = (this.currentPage - 1) * this.options.itemsPerPage;
    const pageEnd = Math.min(pageStart + this.options.itemsPerPage, this.data.length);

    // Header building
    let selectAllChecked = true;
    if (paginated.length === 0) selectAllChecked = false;
    paginated.forEach(item => {
      if (!this.selectedIds.has(item.id)) selectAllChecked = false;
    });

    let html = `
      <div class="data-table-wrapper">
        <table class="data-table">
          <thead>
            <tr>
              ${this.options.selectable ? `
                <th width="40">
                  <label class="checkbox-label" style="padding: 0;">
                    <input type="checkbox" class="checkbox-custom select-all-rows" ${selectAllChecked ? 'checked' : ''}>
                    <div class="checkbox-box"></div>
                  </label>
                </th>
              ` : ''}
              ${this.columns.map(col => `
                <th class="sortable-th" data-key="${col.key}" style="cursor: pointer;">
                  <div style="display: flex; align-items: center; gap: 4px;">
                    ${col.label}
                    ${this.options.sortKey === col.key ? (this.options.sortDesc ? '↓' : '↑') : ''}
                  </div>
                </th>
              `).join('')}
              <th width="120" style="text-align: right;">Actions</th>
            </tr>
          </thead>
          <tbody>
            ${paginated.length === 0 ? `
              <tr>
                <td colspan="${this.columns.length + (this.options.selectable ? 2 : 1)}" style="text-align: center; padding: 40px;">
                  <div class="empty-state">
                    <svg class="empty-state-illust" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="var(--text-muted)" stroke-width="1.5">
                      <circle cx="12" cy="12" r="10" />
                      <line x1="8" y1="12" x2="16" y2="12" />
                    </svg>
                    <div class="empty-state-title">No matching records</div>
                    <div class="empty-state-desc">Try modifying your filters or search keywords.</div>
                  </div>
                </td>
              </tr>
            ` : paginated.map(row => {
              const isSelected = this.selectedIds.has(row.id);
              return `
                <tr class="table-row-item" data-id="${row.id}" style="cursor: pointer;">
                  ${this.options.selectable ? `
                    <td class="checkbox-cell" style="cursor: default;">
                      <label class="checkbox-label" style="padding: 0;">
                        <input type="checkbox" class="checkbox-custom select-row-item" data-id="${row.id}" ${isSelected ? 'checked' : ''}>
                        <div class="checkbox-box"></div>
                      </label>
                    </td>
                  ` : ''}
                  ${this.columns.map(col => `
                    <td>${col.render ? col.render(row[col.key], row) : (row[col.key] || '')}</td>
                  `).join('')}
                  <td style="text-align: right;" class="actions-cell">
                    <div style="display: inline-flex; gap: 4px; justify-content: flex-end;">
                      ${this.options.onRowClick ? `
                      <button class="btn btn-ghost btn-sm btn-icon-only row-action-view" title="View details" data-id="${row.id}">
                        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                      </button>
                      ` : ''}
                      ${this.options.onEdit ? `
                      <button class="btn btn-ghost btn-sm btn-icon-only row-action-edit" title="Edit" data-id="${row.id}">
                        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                      </button>
                      ` : ''}
                      ${this.options.onDelete ? `
                      <button class="btn btn-ghost btn-sm btn-icon-only row-action-delete" title="Delete" data-id="${row.id}" style="color: var(--color-danger);">
                        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
                      </button>
                      ` : ''}
                    </div>
                  </td>
                </tr>
              `;
            }).join('')}
          </tbody>
        </table>
        
        <div class="pagination">
          <div style="font-size: 13px; color: var(--text-secondary);">
            Showing <span style="font-weight: 600; color: var(--text-primary);">${this.data.length === 0 ? 0 : pageStart + 1}</span> to 
            <span style="font-weight: 600; color: var(--text-primary);">${pageEnd}</span> of 
            <span style="font-weight: 600; color: var(--text-primary);">${this.data.length}</span> entries
          </div>
          <div class="page-links">
            <button class="page-link prev-page-btn ${this.currentPage === 1 ? 'disabled' : ''}">Prev</button>
            ${this.renderPageNumbers()}
            <button class="page-link next-page-btn ${this.currentPage === this.totalPages ? 'disabled' : ''}">Next</button>
          </div>
        </div>
      </div>
    `;

    this.container.innerHTML = html;
    this.bindEvents();
    this.updateBulkActionsBar();
  }

  renderPageNumbers() {
    let pages = [];
    const maxVisible = 5;
    
    let startPage = Math.max(1, this.currentPage - Math.floor(maxVisible / 2));
    let endPage = Math.min(this.totalPages, startPage + maxVisible - 1);
    
    if (endPage - startPage + 1 < maxVisible) {
      startPage = Math.max(1, endPage - maxVisible + 1);
    }

    for (let i = startPage; i <= endPage; i++) {
      pages.push(`
        <button class="page-link number-page-btn ${i === this.currentPage ? 'active' : ''}" data-page="${i}">${i}</button>
      `);
    }

    return pages.join('');
  }

  bindEvents() {
    // 1. Sort click events
    this.container.querySelectorAll(".sortable-th").forEach(th => {
      th.addEventListener("click", () => {
        const key = th.getAttribute("data-key");
        this.setSort(key);
      });
    });

    // 2. Select All
    const selectAllBtn = this.container.querySelector(".select-all-rows");
    if (selectAllBtn) {
      selectAllBtn.addEventListener("change", (e) => {
        this.toggleSelectAll(e.target.checked);
      });
    }

    // 3. Select Item
    this.container.querySelectorAll(".select-row-item").forEach(cb => {
      cb.addEventListener("change", (e) => {
        e.stopPropagation();
        const id = cb.getAttribute("data-id");
        this.toggleSelectItem(id, e.target.checked);
      });
    });

    // 4. Page changes
    const prevBtn = this.container.querySelector(".prev-page-btn");
    if (prevBtn) {
      prevBtn.addEventListener("click", () => {
        if (this.currentPage > 1) {
          this.currentPage--;
          this.render();
        }
      });
    }

    const nextBtn = this.container.querySelector(".next-page-btn");
    if (nextBtn) {
      nextBtn.addEventListener("click", () => {
        if (this.currentPage < this.totalPages) {
          this.currentPage++;
          this.render();
        }
      });
    }

    this.container.querySelectorAll(".number-page-btn").forEach(btn => {
      btn.addEventListener("click", () => {
        const p = parseInt(btn.getAttribute("data-page"), 10);
        this.currentPage = p;
        this.render();
      });
    });

    // 5. Actions / Row Click
    this.container.querySelectorAll(".table-row-item").forEach(row => {
      row.addEventListener("click", (e) => {
        const id = row.getAttribute("data-id");
        
        // Exclude cell interactions
        if (e.target.closest(".checkbox-cell") || e.target.closest(".actions-cell")) {
          return;
        }

        if (this.options.onRowClick) {
          this.options.onRowClick(id, this.data.find(x => x.id == id));
        }
      });
    });

    this.container.querySelectorAll(".row-action-view").forEach(btn => {
      btn.addEventListener("click", (e) => {
        e.stopPropagation();
        const id = btn.getAttribute("data-id");
        if (this.options.onRowClick) {
          this.options.onRowClick(id, this.data.find(x => x.id == id));
        }
      });
    });

    this.container.querySelectorAll(".row-action-edit").forEach(btn => {
      btn.addEventListener("click", (e) => {
        e.stopPropagation();
        const id = btn.getAttribute("data-id");
        if (this.options.onEdit) {
          this.options.onEdit(id, this.data.find(x => x.id == id));
        }
      });
    });

    this.container.querySelectorAll(".row-action-delete").forEach(btn => {
      btn.addEventListener("click", (e) => {
        e.stopPropagation();
        const id = btn.getAttribute("data-id");
        if (this.options.onDelete) {
          this.options.onDelete(id);
        }
      });
    });
  }
}

// 3. Kanban Drag-and-Drop Controller
class KanbanPipeline {
  constructor(containerId, initialApps, options = {}) {
    this.container = document.getElementById(containerId);
    this.apps = initialApps;
    this.options = Object.assign({
      onCardMove: null
    }, options);

    this.stages = ["Registration", "Applied", "Eligible", "Aptitude", "Technical", "HR", "Selected", "Rejected"];
    this.init();
  }

  init() {
    this.render();
  }

  setData(newApps) {
    this.apps = newApps;
    this.render();
  }

  render() {
    if (!this.container) return;

    let html = "";
    this.stages.forEach(stage => {
      const stageApps = this.apps.filter(a => a.status.toLowerCase() === stage.toLowerCase());
      
      html += `
        <div class="kanban-column" data-stage="${stage}">
          <div class="kanban-column-header">
            <div class="kanban-column-title">
              <span class="kanban-stage-dot" style="width: 8px; height: 8px; border-radius: 50%; background-color: ${this.getStageColor(stage)};"></span>
              ${stage}
            </div>
            <span class="kanban-column-badge">${stageApps.length}</span>
          </div>
          
          <div class="kanban-cards-container" data-stage="${stage}">
            ${stageApps.map(app => `
              <div class="kanban-card" draggable="true" data-id="${app.id}">
                <div class="kanban-card-title">${app.studentName}</div>
                <div class="kanban-card-desc">
                  <div style="font-weight: 500; margin-bottom: 2px;">${app.companyName}</div>
                  <div style="color: var(--text-muted); font-size: 11px;">${app.role}</div>
                </div>
                <div class="kanban-card-meta">
                  <span class="badge ${app.cgpa >= 8.5 ? 'badge-success' : 'badge-primary'}" style="font-size: 10px;">
                    CGPA: ${app.cgpa}
                  </span>
                  <span style="font-size: 11px; color: var(--text-secondary); font-weight: 600;">
                    ${app.deptCode}
                  </span>
                </div>
              </div>
            `).join('')}
          </div>
        </div>
      `;
    });

    this.container.innerHTML = html;
    this.bindEvents();
  }

  getStageColor(stage) {
    switch(stage.toLowerCase()) {
      case "registration": return "var(--text-muted)";
      case "applied": return "var(--primary)";
      case "eligible": return "var(--color-info)";
      case "aptitude": return "var(--color-warning)";
      case "technical": return "var(--primary)";
      case "hr": return "var(--color-info)";
      case "selected": return "var(--color-success)";
      case "rejected": return "var(--color-danger)";
      default: return "var(--primary)";
    }
  }

  bindEvents() {
    const cards = this.container.querySelectorAll(".kanban-card");
    const cols = this.container.querySelectorAll(".kanban-cards-container");

    cards.forEach(card => {
      card.addEventListener("dragstart", (e) => {
        card.classList.add("dragging");
        e.dataTransfer.setData("text/plain", card.getAttribute("data-id"));
      });

      card.addEventListener("dragend", () => {
        card.classList.remove("dragging");
      });
    });

    cols.forEach(col => {
      col.addEventListener("dragover", (e) => {
        e.preventDefault();
        col.style.backgroundColor = "rgba(37, 99, 235, 0.05)";
        col.style.borderRadius = "8px";
      });

      col.addEventListener("dragleave", () => {
        col.style.backgroundColor = "transparent";
      });

      col.addEventListener("drop", (e) => {
        e.preventDefault();
        col.style.backgroundColor = "transparent";
        const appId = e.dataTransfer.getData("text/plain");
        const stage = col.getAttribute("data-stage");
        
        const card = this.container.querySelector(`.kanban-card[data-id="${appId}"]`);
        if (card) {
          col.appendChild(card);
          
          // Trigger updates
          const app = this.apps.find(a => a.id === appId);
          if (app) {
            app.status = stage;
            
            // Re-render columns counts
            this.updateColumnBadges();

            if (this.options.onCardMove) {
              this.options.onCardMove(appId, stage);
            }
          }
        }
      });
    });
  }

  updateColumnBadges() {
    this.container.querySelectorAll(".kanban-column").forEach(col => {
      const stage = col.getAttribute("data-stage");
      const count = col.querySelectorAll(".kanban-card").length;
      col.querySelector(".kanban-column-badge").innerText = count;
    });
  }
}

const translate = (text) => (typeof window.__ === 'function' ? window.__(text) : text);

// 4. Upcoming Interviews Calendar
class InterviewCalendar {
  constructor(calendarContainerId, listContainerId, interviews) {
    this.calContainer = document.getElementById(calendarContainerId);
    this.listContainer = document.getElementById(listContainerId);
    this.interviews = interviews;
    
    // Set viewDate to July 2026 initially for matching existing DB mock interviews
    this.viewDate = new Date(2026, 6, 1); // July is index 6
    
    // Set active today to 2026-07-16 to match seeded records
    this.today = new Date(2026, 6, 16);
    this.selectedDateStr = "2026-07-16";
    this.timers = [];

    this.init();
  }

  init() {
    this.renderCalendar();
    this.renderList();
    this.startTimers();
  }

  renderCalendar() {
    if (!this.calContainer) return;
    
    // Clear existing
    this.calContainer.innerHTML = "";
    
    const year = this.viewDate.getFullYear();
    const month = this.viewDate.getMonth();
    
    // First day of month (0 = Sun, 1 = Mon, etc.)
    const firstDay = new Date(year, month, 1).getDay();
    // Days in month
    const daysInMonth = new Date(year, month + 1, 0).getDate();
    
    // Month name
    const monthNames = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];
    const monthLabel = `${monthNames[month]} ${year}`;
    
    let html = `
      <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--space-2);">
        <div style="font-weight: var(--font-bold); font-size: 16px;">${monthLabel}</div>
        <div style="display: flex; gap: 4px;">
          <button class="btn btn-secondary btn-sm" id="cal-prev-btn">&lt;</button>
          <button class="btn btn-secondary btn-sm" id="cal-next-btn">&gt;</button>
        </div>
      </div>
      <div class="calendar-grid-header">
        <div>Sun</div><div>Mon</div><div>Tue</div><div>Wed</div><div>Thu</div><div>Fri</div><div>Sat</div>
      </div>
      <div class="calendar-grid">
    `;
    
    // Fill empty slots before start date
    for (let i = 0; i < firstDay; i++) {
      html += `<div class="calendar-day empty"></div>`;
    }
    
    // Today's exact date string for comparison (format: YYYY-MM-DD)
    const todayStr = `${this.today.getFullYear()}-${String(this.today.getMonth() + 1).padStart(2, '0')}-${String(this.today.getDate()).padStart(2, '0')}`;
    
    // Fill days
    for (let d = 1; d <= daysInMonth; d++) {
      const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
      const dayInterviews = this.interviews.filter(i => i.date === dateStr);
      
      const isToday = dateStr === todayStr;
      const isSelected = dateStr === this.selectedDateStr;
      
      let dotsHtml = "";
      if (dayInterviews.length > 0) {
        dotsHtml = `
          <div class="calendar-events">
            <span class="calendar-event-dot ${dayInterviews.length > 2 ? 'danger' : 'primary'}"></span>
          </div>
        `;
      }

      html += `
        <div class="calendar-day ${isToday ? 'today' : ''} ${isSelected ? 'selected' : ''}" 
             data-date="${dateStr}" 
             style="${isSelected ? 'border-color: var(--primary); background-color: var(--primary-light);' : ''}">
          <div class="calendar-day-num">${d}</div>
          ${dotsHtml}
        </div>
      `;
    }
    
    html += `</div>`;
    this.calContainer.innerHTML = html;
    
    // Bind click
    this.calContainer.querySelectorAll(".calendar-day:not(.empty)").forEach(cell => {
      cell.addEventListener("click", () => {
        this.selectedDateStr = cell.getAttribute("data-date");
        this.renderCalendar();
        this.renderList();
      });
    });

    // Bind navigation buttons
    const prevBtn = this.calContainer.querySelector("#cal-prev-btn");
    const nextBtn = this.calContainer.querySelector("#cal-next-btn");
    
    if (prevBtn) {
      prevBtn.addEventListener("click", () => {
        this.viewDate.setMonth(this.viewDate.getMonth() - 1);
        this.renderCalendar();
      });
    }
    if (nextBtn) {
      nextBtn.addEventListener("click", () => {
        this.viewDate.setMonth(this.viewDate.getMonth() + 1);
        this.renderCalendar();
      });
    }
  }

  renderList() {
    if (!this.listContainer) return;
    
    // Filter interviews by selected date
    const filtered = this.interviews.filter(i => i.date === this.selectedDateStr);
    
    // Stop previous timers
    this.timers.forEach(t => clearInterval(t));
    this.timers = [];

    if (filtered.length === 0) {
      this.listContainer.innerHTML = `
        <div class="empty-state" style="padding: 24px;">
          <div class="empty-state-title" style="font-size: 14px;">${translate('No interviews scheduled')}</div>
          <div class="empty-state-desc">${translate('There are no placement drives running on')} ${this.selectedDateStr}.</div>
        </div>
      `;
      return;
    }

    let html = "";
    filtered.forEach((int, index) => {
      // Mock Countdown (simulate interview hours)
      const mockTimeStr = `${this.selectedDateStr}T${int.time}:00`;
      const elementId = `countdown-timer-${index}`;

      html += `
        <div class="interview-item-card">
          <div class="interview-card-top">
            <div>
              <span class="badge badge-primary">${int.deptCode}</span>
            </div>
            <div class="interview-countdown" id="${elementId}">
              Live Soon
            </div>
          </div>
          
          <div class="interview-card-details">
            <h4 style="font-weight: 700; font-size: 14px; margin-bottom: 2px;">${int.studentName}</h4>
            <div style="font-weight: 500; font-size: 12px; color: var(--text-primary);">${int.companyName} &bull; ${int.role}</div>
          </div>
          
          <div class="interview-card-meta">
            <span style="display: inline-flex; align-items: center; gap: 4px;">
              <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
              ${int.time}
            </span>
            <span style="display: inline-flex; align-items: center; gap: 4px;">
              <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
              ${int.venue}
            </span>
            <span style="display: inline-flex; align-items: center; gap: 4px;">
              <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
              ${int.interviewer}
            </span>
          </div>
          
          <div style="margin-top: 10px; display: flex; gap: var(--space-1);">
            <a href="#" class="btn btn-secondary btn-sm start-meeting-btn" data-id="${int.id}" style="flex-grow: 1;">
              ${translate('Join Meeting Link')}
            </a>
          </div>
        </div>
      `;

      // Set countdown trigger
      setTimeout(() => {
        this.bindCountdown(elementId, mockTimeStr);
      }, 50);
    });

    this.listContainer.innerHTML = html;

    this.listContainer.querySelectorAll(".start-meeting-btn").forEach(btn => {
      btn.addEventListener("click", (e) => {
        e.preventDefault();
        showToast("Accessing Video Conferencing", "Initializing secure connection to virtual room.", "info");
      });
    });
  }

  bindCountdown(elementId, targetTimeStr) {
    const el = document.getElementById(elementId);
    if (!el) return;

    const target = new Date(targetTimeStr).getTime();
    
    const update = () => {
      // Since it's simulated, we mock the remaining hours dynamically relative to a fixed 11:00 AM July 16 start time.
      const now = new Date("2026-07-16T11:08:32").getTime();
      const diff = target - now;

      if (diff < 0) {
        el.innerText = "Ongoing / Completed";
        el.style.color = "var(--color-success)";
        return;
      }

      const hrs = Math.floor(diff / (1000 * 60 * 60));
      const mins = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
      
      el.innerText = `Starts in ${hrs}h ${mins}m`;
    };

    update();
    const interval = setInterval(update, 60000);
    this.timers.push(interval);
  }

  startTimers() {
    // Initialized timers
  }
}
